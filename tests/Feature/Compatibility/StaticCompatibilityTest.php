<?php

declare(strict_types=1);

use App\Domain\Artifacts\Artifact;
use App\Domain\Artifacts\ArtifactIntegrityFailed;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Artifacts\PackageSource;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Compatibility\CompatSettings;
use App\Domain\Compatibility\PackageCompatibilityAnalyzer;
use App\Domain\Compatibility\StaticAnalysisFailed;
use App\Domain\Compatibility\StaticCompatibility;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Inventory\PendingUpdate;
use App\Domain\Planning\PlanAction;
use App\Domain\Planning\PlannedChange;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\ComponentType;
use App\Enums\SkipReason;
use App\Models\CompatScan;
use App\Models\Run;

// PHPCompatibility 9.3: the mysql_* extension is an error (removed in PHP 7.0), create_function() only a warning (deprecated in 7.2).
const COMPAT_BAD_CODE = "<?php\nmysql_connect();\n";
const COMPAT_WARN_CODE = "<?php\n\$f = create_function('\$a', 'return \$a;');\n";

beforeEach(function (): void {
    $base = sys_get_temp_dir().'/wpup-compat-'.bin2hex(random_bytes(6));
    config(['wpup.artifacts.dir' => "{$base}/artifacts", 'wpup.static_analysis.work_dir' => "{$base}/work"]);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg(compatBase()));
});

function compatBase(): string
{
    $dir = config('wpup.artifacts.dir');

    return is_string($dir) ? dirname($dir) : throw new LogicException('wpup.artifacts.dir is not set.');
}

/**
 * @param  array<string, string>  $files  name => content
 */
function compatZip(array $files): string
{
    $path = sys_get_temp_dir().'/wpup-compat-zip-'.bin2hex(random_bytes(6)).'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $bytes = (string) file_get_contents($path);
    unlink($path);

    return $bytes;
}

/**
 * @param  array<string, string>  $files
 */
function compatArtifact(array $files, ?Run $run = null, string $slug = 'bad-plugin', string $version = '2.0.0'): Artifact
{
    $run ??= Run::factory()->create();
    $store = app(ArtifactStore::class);
    $bytes = compatZip($files);
    $temporary = $store->temporaryFile($run->id);
    file_put_contents($temporary, $bytes);
    $source = PackageSource::create(ComponentType::Plugin, $slug, $version, 'https://downloads.wordpress.org/plugin/x.zip', app(PackageUrlGuard::class));

    return $store->commit($run->id, $source, $temporary, hash('sha256', $bytes), strlen($bytes));
}

function compatWorkDirEntries(): int
{
    $dir = compatBase().'/work';

    return is_dir($dir) ? count(scandir($dir) ?: []) - 2 : 0;
}

/** A stand-in for phpcs: a PHP script run with the PHP binary. */
function compatFakePhpcs(string $code): void
{
    $path = compatBase().'/fake-phpcs.php';
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, "<?php\n{$code}\n");
    config(['wpup.static_analysis.phpcs_binary' => $path]);
}

it('reports errors before warnings with file and line, for the site PHP', function (): void {
    $artifact = compatArtifact(['bad-plugin/bad.php' => COMPAT_BAD_CODE, 'bad-plugin/warn.php' => COMPAT_WARN_CODE, 'bad-plugin/readme.txt' => 'x']);

    $report = app(StaticCompatibility::class)->analyse($artifact, '7.4');

    expect($report->errors)->toBe(1)->and($report->warnings)->toBe(1)->and($report->cached)->toBeFalse()
        ->and($report->findings[0]->isError)->toBeTrue()
        ->and($report->findings[0]->location())->toBe('bad-plugin/bad.php:2')
        ->and($report->findings[0]->message)->toContain('mysql_')
        ->and($report->findings[1]->location())->toBe('bad-plugin/warn.php:2')
        ->and($report->findings[1]->message)->toContain('create_function');
    expect(compatWorkDirEntries())->toBe(0);
});

it('finds nothing in clean code', function (): void {
    $report = app(StaticCompatibility::class)->analyse(compatArtifact(['ok/ok.php' => "<?php\n\$a = array(1, 2);\n"]), '8.3');

    expect($report->errors)->toBe(0)->and($report->warnings)->toBe(0)->and($report->findings)->toBe([]);
});

it('excludes vendor/ and known polyfills, and the exclusions are configurable', function (): void {
    $files = [
        'p/p.php' => "<?php\n\$a = 1;\n",
        'p/vendor/lib/x.php' => COMPAT_BAD_CODE,
        'p/inc/symfony/polyfill-php80/y.php' => COMPAT_BAD_CODE,
        'p/inc/random_compat/z.php' => COMPAT_BAD_CODE,
    ];
    expect(app(StaticCompatibility::class)->analyse(compatArtifact($files), '8.2')->errors)->toBe(0);

    config(['wpup.static_analysis.ignore' => ['*/random_compat/*']]);
    $report = app(StaticCompatibility::class)->analyse(compatArtifact($files, slug: 'other'), '8.2');
    expect($report->errors)->toBe(2)
        ->and(array_map(fn ($f) => $f->file, $report->findings))->toBe(['p/inc/symfony/polyfill-php80/y.php', 'p/vendor/lib/x.php']);
});

it('caches by checksum and PHP minor, and the cache is not used for another PHP or other rules', function (): void {
    $artifact = compatArtifact(['p/p.php' => COMPAT_BAD_CODE]);
    $service = app(StaticCompatibility::class);

    expect($service->analyse($artifact, '8.2')->cached)->toBeFalse();
    expect(CompatScan::query()->count())->toBe(1);

    // The package is gone: only the cache can answer, without even verifying the file.
    unlink($artifact->path);
    $again = $service->analyse($artifact, '8.2');
    expect($again->cached)->toBeTrue()->and($again->errors)->toBe(1)->and($again->findings[0]->location())->toBe('p/p.php:2');

    expect(fn () => $service->analyse($artifact, '8.3'))->toThrow(ArtifactIntegrityFailed::class);

    config(['wpup.static_analysis.ignore' => ['*/vendor/*']]);
    expect(fn () => app(StaticCompatibility::class)->analyse($artifact, '8.2'))->toThrow(ArtifactIntegrityFailed::class);
});

it('does not analyse bytes that no longer match their checksum', function (): void {
    $artifact = compatArtifact(['p/p.php' => COMPAT_BAD_CODE]);
    file_put_contents($artifact->path, 'tampered');

    expect(fn () => app(StaticCompatibility::class)->analyse($artifact, '8.2'))->toThrow(ArtifactIntegrityFailed::class);
    expect(CompatScan::query()->count())->toBe(0)->and(compatWorkDirEntries())->toBe(0);
});

it('refuses a ZIP that tries to write outside the work directory', function (string $name): void {
    $artifact = compatArtifact([$name => "<?php\n"]);

    expect(fn () => app(StaticCompatibility::class)->analyse($artifact, '8.2'))->toThrow(StaticAnalysisFailed::class, 'ruta no permesa');
    expect(file_exists(compatBase().'/evil.php'))->toBeFalse()->and(compatWorkDirEntries())->toBe(0);
})->with(['parent' => '../../evil.php', 'nested parent' => 'p/../../../evil.php', 'backslash' => 'p\\..\\evil.php']);

it('does not cache nor leave anything behind when phpcs crashes, times out or prints too much', function (string $code, string $message, array $config): void {
    compatFakePhpcs($code);
    foreach ($config as $key => $value) {
        config([(string) $key => $value]);
    }
    $artifact = compatArtifact(['p/p.php' => "<?php\n"]);

    expect(fn () => app(StaticCompatibility::class)->analyse($artifact, '8.2'))->toThrow(StaticAnalysisFailed::class, $message);
    expect(CompatScan::query()->count())->toBe(0)->and(compatWorkDirEntries())->toBe(0);
})->with([
    'crash' => ['exit(3);', 'codi de sortida 3', []],
    'not JSON' => ['echo "oops";', 'JSON vàlid', []],
    'timeout' => ['sleep(10);', 'temps màxim', ['wpup.static_analysis.timeout_seconds' => 1]],
    'output cap' => ['echo str_repeat("x", 5000);', 'supera el límit', ['wpup.static_analysis.max_output_bytes' => 1000]],
]);

it('runs phpcs without the tool environment', function (): void {
    putenv('WPUP_SECRET_PROBE=leaked');
    compatFakePhpcs('echo json_encode(["totals" => ["errors" => 0, "warnings" => getenv("WPUP_SECRET_PROBE") === false ? 0 : 1], "files" => new stdClass]);');

    try {
        $report = app(StaticCompatibility::class)->analyse(compatArtifact(['p/p.php' => "<?php\n"]), '8.2');
    } finally {
        putenv('WPUP_SECRET_PROBE');
    }

    expect($report->warnings)->toBe(0);
});

it('rejects an unreadable PHP minor', function (): void {
    expect(fn () => app(StaticCompatibility::class)->analyse(compatArtifact(['p/p.php' => "<?php\n"]), '8.2; rm -rf /'))->toThrow(StaticAnalysisFailed::class);
});

it('rejects a configuration with a comma in an ignore pattern', function (): void {
    config(['wpup.static_analysis.ignore' => ['a,b']]);

    expect(fn () => app(CompatSettings::class))->toThrow(InvalidArgumentException::class);
});

/**
 * @param  array<string, string>  $files
 * @return array{PackageCompatibilityAnalyzer, Run}
 */
function compatAnalyzer(array $files, ?string $php = '8.2.20', ?Closure $driverFor = null): array
{
    $run = Run::factory()->create();
    compatArtifact($files, $run);
    $analyzer = new PackageCompatibilityAnalyzer(
        $run->id,
        $php,
        $driverFor ?? fn (): SiteDriver => throw new LogicException('The site must not be reached: the artifact is already stored.'),
        app(PackageArtifacts::class),
        app(StaticCompatibility::class),
        app(CompatSettings::class),
        app(SecretRedactor::class),
    );

    return [$analyzer, $run];
}

function compatPending(string $slug = 'bad-plugin'): PendingUpdate
{
    return PendingUpdate::fromArray(['type' => 'plugin', 'slug' => $slug, 'wporg_slug' => $slug, 'current_version' => '1.0.0', 'new_version' => '2.0.0', 'source' => 'wordpress.org', 'licensed' => true]) ?? throw new LogicException('bad fixture');
}

function compatChange(ComponentType $type = ComponentType::Plugin): PlannedChange
{
    return new PlannedChange($type, 'bad-plugin', '1.0.0', '2.0.0', PlanAction::Update);
}

it('turns errors into a skip that names the places, and warnings into report lines', function (): void {
    [$analyzer] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_BAD_CODE]);

    $analysis = $analyzer->analyze(compatChange(), compatPending());
    expect($analysis?->skip)->toBe(SkipReason::StaticAnalysis)
        ->and($analysis?->detail)->toBe('PHPCompatibility: 1 errors per a PHP 8.2 (bad-plugin/bad.php:2).');

    [$old] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_WARN_CODE], '7.4.33');
    $warned = $old->analyze(compatChange(), compatPending());
    expect($warned?->skip)->toBeNull()->and($warned?->warnings)->toHaveCount(1)->and($warned?->warnings[0])->toContain('bad-plugin/bad.php:2');
});

it('does not analyse the core nor translations', function (): void {
    [$analyzer] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_BAD_CODE]);

    expect($analyzer->analyze(compatChange(ComponentType::Core), compatPending()))->toBeNull()
        ->and($analyzer->analyze(compatChange(ComponentType::Translation), compatPending()))->toBeNull();
});

it('skips conservatively when the analysis cannot run', function (string $php, string $code, string $reason): void {
    compatFakePhpcs($code);
    [$analyzer] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_BAD_CODE], $php);

    $analysis = $analyzer->analyze(compatChange(), compatPending());
    expect($analysis?->skip)->toBe(SkipReason::StaticAnalysis)->and($analysis?->detail)->toContain("s'omet per precaució")->toContain($reason);
})->with([
    'phpcs crashes' => ['8.2.20', 'exit(3);', 'codi de sortida 3'],
    'unknown PHP' => ['desconegut', 'exit(0);', 'PHP del lloc'],
]);

it('skips conservatively when the update is not in the inventory', function (): void {
    [$analyzer] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_BAD_CODE]);

    $analysis = $analyzer->analyze(new PlannedChange(ComponentType::Plugin, 'other', '1.0.0', '2.0.0', PlanAction::Update), null);

    expect($analysis?->skip)->toBe(SkipReason::StaticAnalysis)->and($analysis?->detail)->toContain('inventari');
});

it('analyses against exactly the site minor, not an open range', function (): void {
    compatFakePhpcs('$i = array_search("--runtime-set", $argv, true); $a = array_keys($argv, "testVersion", true); echo json_encode(["totals" => ["errors" => 0, "warnings" => 1], "files" => ["x" => ["messages" => [["message" => $argv[$a[0] + 1], "type" => "WARNING", "line" => 1]]]]]);');

    $report = app(StaticCompatibility::class)->analyse(compatArtifact(['p/p.php' => "<?php\n"]), '7.4');

    expect($report->findings[0]->message)->toBe('7.4-7.4');
});

it('reports a paths-free message when the work directory cannot be made', function (): void {
    $blocker = compatBase().'/blocker';
    @mkdir(dirname($blocker), 0700, true);
    file_put_contents($blocker, 'x');
    config(['wpup.static_analysis.work_dir' => $blocker.'/work']);

    $message = null;
    try {
        app(StaticCompatibility::class)->analyse(compatArtifact(['p/p.php' => "<?php\n"]), '8.2');
    } catch (StaticAnalysisFailed $e) {
        $message = $e->getMessage();
    }

    expect($message)->toContain('directori de treball');
    expect(str_contains((string) $message, compatBase()))->toBeFalse();
});

it('does not analyse against a PHP that was not verified on the web', function (): void {
    [$analyzer] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_BAD_CODE], null);

    $analysis = $analyzer->analyze(compatChange(), compatPending());

    expect($analysis?->skip)->toBe(SkipReason::StaticAnalysis)->and($analysis?->detail)->toContain('no')->and($analysis?->detail)->toContain('verificar');
    expect(CompatScan::query()->count())->toBe(0);
});

it('skips the packages left once the step budget cannot cover another one', function (): void {
    config(['wpup.static_analysis.step_budget_seconds' => 100, 'wpup.static_analysis.min_remaining_seconds' => 200]);
    [$analyzer] = compatAnalyzer(['bad-plugin/bad.php' => COMPAT_BAD_CODE]);

    $analysis = $analyzer->analyze(compatChange(), compatPending());

    expect($analysis?->skip)->toBe(SkipReason::StaticAnalysis)->and($analysis?->detail)->toContain("temps d'anàlisi esgotat")->toContain("s'omet per precaució");
    expect(CompatScan::query()->count())->toBe(0);
});

it('redacts secrets from the reason of a skip', function (): void {
    $secret = 'sup3r-s3cret-licence-token';
    app(SecretRedactor::class)->register($secret);
    $run = Run::factory()->create();
    $analyzer = new PackageCompatibilityAnalyzer(
        $run->id,
        '8.2.20',
        fn (): SiteDriver => throw new DriverException("connexió fallida amb {$secret}"),
        app(PackageArtifacts::class),
        app(StaticCompatibility::class),
        app(CompatSettings::class),
        app(SecretRedactor::class),
    );
    $update = PendingUpdate::fromArray(['type' => 'plugin', 'slug' => 'premium', 'wporg_slug' => null, 'current_version' => '1.0.0', 'new_version' => '2.0.0', 'source' => 'transient', 'licensed' => true]);

    $analysis = $analyzer->analyze(new PlannedChange(ComponentType::Plugin, 'premium', '1.0.0', '2.0.0', PlanAction::Update), $update);

    expect($analysis?->skip)->toBe(SkipReason::StaticAnalysis)->and($analysis?->detail)->not->toContain($secret)->and($analysis?->detail)->toContain('connexió fallida');
});
