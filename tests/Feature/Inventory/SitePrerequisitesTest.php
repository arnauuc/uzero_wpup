<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Drivers\Exceptions\ConnectionFailed;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\PrerequisiteCheck;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Inventory\SitePrerequisites;
use App\Domain\Inventory\WpCliReader;
use App\Domain\Notifications\LogSiteBlockNotifier;
use App\Domain\Notifications\SiteBlockNotifier;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\StepContext;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Sites\BlockOutcome;
use App\Domain\Sites\SiteBlocking;
use App\Enums\PrerequisiteName;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\SiteBlockSource;
use App\Models\AuditLogEntry;
use App\Models\Run;
use App\Models\Site;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeDriver;

function prerequisites(string $minPhp = '7.4', float $factor = 1.5): SitePrerequisites
{
    return new SitePrerequisites(new WpCliReader(app(SecretRedactor::class)), $minPhp, $factor, 10);
}

/**
 * @param  list<PrerequisiteCheck>  $checks
 */
function failed(array $checks, PrerequisiteName $name): ?PrerequisiteCheck
{
    foreach ($checks as $check) {
        if ($check->name === $name && ! $check->passed) {
            return $check;
        }
    }

    return null;
}

/** Records what the notifier is told. */
final class RecordingBlockNotifier implements SiteBlockNotifier
{
    /** @var list<string> */
    public array $calls = [];

    public function siteBlocked(Site $site, string $reason): void
    {
        $this->calls[] = "blocked:{$reason}";
    }

    public function siteUnblocked(Site $site): void
    {
        $this->calls[] = 'unblocked';
    }
}

function blockingSite(?SiteBlockSource $by = null, ?string $reason = null): Site
{
    return Site::factory()->create(['blocked_by' => $by, 'blocked_reason' => $reason, 'blocked_at' => $reason === null ? null : now()]);
}

/**
 * @return array{0: SiteBlocking, 1: RecordingBlockNotifier}
 */
function blocking(): array
{
    $notifier = new RecordingBlockNotifier;

    return [new SiteBlocking(app(AuditLogger::class), $notifier), $notifier];
}

it('passes every prerequisite on a healthy site', function (): void {
    $driver = new FakeDriver;

    $checks = prerequisites()->check($driver, '8.3.14', ['blocked_reason' => null], null)->checks;

    expect($checks)->toHaveCount(5)
        ->and(array_filter($checks, static fn (PrerequisiteCheck $c): bool => ! $c->passed))->toBe([]);
});

it('fails php_minimum below 7.4 with the exact reason and passes at 7.4', function (): void {
    $driver = new FakeDriver;

    $old = failed(prerequisites()->check($driver, '7.3.33', [], null)->checks, PrerequisiteName::PhpMinimum);
    $edge = failed(prerequisites()->check($driver, '7.4.0', [], null)->checks, PrerequisiteName::PhpMinimum);

    expect($old?->reason)->toBe('PHP < 7.4: no suportat')
        ->and($old?->value)->toBe('7.3.33')
        ->and($edge)->toBeNull();
});

it('uses the configured minimum in the reason', function (): void {
    $check = failed(prerequisites('8.1')->check(new FakeDriver, '8.0.30', [], null)->checks, PrerequisiteName::PhpMinimum);

    expect($check?->reason)->toBe('PHP < 8.1: no suportat');
});

it('refuses a minimum PHP below 7.4 or malformed (ADR-0007)', function (string|int|null $value): void {
    expect(fn () => SitePrerequisites::assertMinimumPhp($value))->toThrow(InvalidArgumentException::class, 'wpup.min_site_php');
})->with(['7.3', '5.6', 'latest', '', 8, null]);

it('refuses to resolve the service when the configuration is below 7.4', function (): void {
    config(['wpup.min_site_php' => '7.2']);

    expect(fn () => app(SitePrerequisites::class))->toThrow(InvalidArgumentException::class);
});

it('accepts 7.4 and above as a minimum', function (): void {
    SitePrerequisites::assertMinimumPhp('7.4');
    SitePrerequisites::assertMinimumPhp('8.1');

    expect(app(SitePrerequisites::class))->toBeInstanceOf(SitePrerequisites::class);
});

it('fails php_verified with the reason of the PHP cross-check', function (): void {
    $check = failed(prerequisites()->check(new FakeDriver, '8.3.14', ['blocked_reason' => 'No se sap quin PHP serveix el web'], null)->checks, PrerequisiteName::PhpVerified);

    expect($check?->reason)->toBe('No se sap quin PHP serveix el web');
});

it('fails backup_space when database plus files times the factor exceed the free disk', function (): void {
    $driver = new FakeDriver(freeDisk: 100_000_000);
    $driver->filesBytes = 40_000_000;
    $driver->dbTables = ['wp_posts' => 20_000_000, 'wp_options' => 10_000_000];

    $check = failed(prerequisites()->check($driver, '8.3.14', [], null)->checks, PrerequisiteName::BackupSpace);

    // (40M + 30M) x 1.5 = 105M > 100M
    expect($check?->value)->toBe(105_000_000)
        ->and($check?->reason)->toContain('espai insuficient per a la còpia');
});

it('passes backup_space with enough room, using the free disk of the state when given', function (): void {
    $driver = new FakeDriver(freeDisk: 1);
    $driver->filesBytes = 40_000_000;
    $driver->dbTables = ['wp_posts' => 20_000_000, 'wp_options' => 10_000_000];

    $withStateDisk = prerequisites()->check($driver, '8.3.14', [], 105_000_000)->checks;
    $withDriverDisk = prerequisites()->check($driver, '8.3.14', [], null)->checks;

    expect(failed($withStateDisk, PrerequisiteName::BackupSpace))->toBeNull()
        ->and(failed($withDriverDisk, PrerequisiteName::BackupSpace))->not->toBeNull();
});

it('fails closed when the sizes cannot be estimated', function (string $break): void {
    $driver = new FakeDriver;
    match ($break) {
        'db command' => $driver->failingCommands = ['db size'],
        'files' => $driver->diskUsageFails = true,
        'free disk' => $driver->diskFails = true,
        'no tables' => $driver->dbTables = [],
        default => throw new LogicException($break),
    };
    $check = failed(prerequisites()->check($driver, '8.3.14', [], null)->checks, PrerequisiteName::BackupSpace);

    expect($check?->reason)->toBe("no s'ha pogut estimar l'espai per a la còpia");
})->with(['db command', 'files', 'free disk', 'no tables']);

it('lets a lost connection through instead of calling it a failed prerequisite', function (): void {
    $driver = new FakeDriver;
    $driver->wpFailure = new ConnectionFailed('sense xarxa');

    expect(fn () => prerequisites()->check($driver, '8.3.14', [], null)->checks)->toThrow(ConnectionFailed::class);
});

it('fails writable naming the paths the account cannot write', function (): void {
    $driver = new FakeDriver;
    $driver->unwritable = ['/var/www/html/wp-content/plugins', '/var/www/html/wp-content/themes'];

    $check = failed(prerequisites()->check($driver, '8.3.14', [], null)->checks, PrerequisiteName::Writable);

    expect($check?->reason)->toBe('el compte no pot escriure a: /var/www/html/wp-content/plugins, /var/www/html/wp-content/themes');
});

it('records the checks in environment.prerequisites', function (): void {
    $driver = new FakeDriver('6.8.3', '7.3.33');

    $snapshot = (new SiteInventory(null, null, null, prerequisites()))->take(Site::factory()->create(), $driver);
    $stored = is_array($snapshot->environment['prerequisites'] ?? null) ? $snapshot->environment['prerequisites'] : [];

    expect($stored)->toHaveCount(5)
        ->and($stored[0])->toMatchArray(['name' => 'php_minimum', 'passed' => false, 'reason' => 'PHP < 7.4: no suportat', 'value' => '7.3.33'])
        ->and(PrerequisiteCheck::fromArray($stored[0])?->passed)->toBeFalse();
});

it('leaves environment.prerequisites out when none is injected', function (): void {
    expect((new SiteInventory)->take(Site::factory()->create(), new FakeDriver)->environment)->not->toHaveKey('prerequisites');
});

it('ignores malformed stored checks', function (): void {
    expect(PrerequisiteCheck::fromArray('x'))->toBeNull()
        ->and(PrerequisiteCheck::fromArray(['name' => 'nope', 'passed' => true]))->toBeNull()
        ->and(PrerequisiteCheck::fromArray(['name' => 'wp_cli', 'passed' => 'yes']))->toBeNull();
});

/**
 * A complete set of checks where only the given ones fail.
 *
 * @param  array<string, string>  $failing  check name => reason
 * @return list<PrerequisiteCheck>
 */
function allChecks(array $failing = []): array
{
    return array_map(
        static fn (PrerequisiteName $name): PrerequisiteCheck => new PrerequisiteCheck($name, ! isset($failing[$name->value]), $failing[$name->value] ?? null),
        PrerequisiteName::cases(),
    );
}

it('blocks a site with the joined reasons, audits and notifies', function (): void {
    [$blocking, $notifier] = blocking();
    $site = blockingSite();
    $checks = allChecks(['php_minimum' => 'PHP < 7.4: no suportat', 'writable' => 'el compte no pot escriure a: /x']);

    $result = $blocking->apply($site, $checks, AuditActor::system('tests'), false);

    $site->refresh();
    expect($result->outcome)->toBe(BlockOutcome::Blocked)
        ->and($site->blocked_reason)->toBe('PHP < 7.4: no suportat; el compte no pot escriure a: /x')
        ->and($site->blocked_by)->toBe(SiteBlockSource::Prerequisites)
        ->and($site->blocked_at)->not->toBeNull()
        ->and($notifier->calls)->toBe(['blocked:PHP < 7.4: no suportat; el compte no pot escriure a: /x'])
        ->and(AuditLogEntry::query()->where('action', 'site.blocked')->where('object_id', (string) $site->id)->count())->toBe(1);
});

it('treats an incomplete list of checks as a failure', function (array $checks): void {
    /** @var list<PrerequisiteCheck> $checks */
    [$blocking] = blocking();
    $site = blockingSite();

    $result = $blocking->apply($site, $checks, AuditActor::system('tests'), false);

    expect($result->outcome)->toBe(BlockOutcome::Blocked)
        ->and($site->refresh()->blocked_reason)->toBe('prerequisits incomplets');
})->with([
    'empty' => [[]],
    'missing one' => [array_slice(allChecks(), 1)],
    'duplicated' => [[...allChecks(), new PrerequisiteCheck(PrerequisiteName::WpCli, true)]],
]);

it('audits and notifies only when the block changes', function (): void {
    [$blocking, $notifier] = blocking();
    $site = blockingSite();
    $bad = allChecks(['php_minimum' => 'PHP < 7.4: no suportat']);
    $actor = AuditActor::system('tests');

    $blocking->apply($site, $bad, $actor, false);
    $blockedAt = $site->refresh()->blocked_at;
    $again = $blocking->apply($site, $bad, $actor, false);

    expect($again->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($again->reason)->toBe('PHP < 7.4: no suportat')
        ->and($notifier->calls)->toHaveCount(1)
        ->and(AuditLogEntry::query()->where('action', 'site.blocked')->count())->toBe(1);

    // A different reason is a change, but the block keeps its start time.
    $blocking->apply($site, allChecks(['writable' => 'sense permisos']), $actor, false);
    expect($site->refresh()->blocked_reason)->toBe('sense permisos')
        ->and($site->blocked_at?->equalTo($blockedAt ?? now()))->toBeTrue()
        ->and($notifier->calls)->toHaveCount(2);
});

it('lifts its own block once everything passes, and only then audits and notifies', function (): void {
    [$blocking, $notifier] = blocking();
    $site = blockingSite(SiteBlockSource::Prerequisites, 'PHP < 7.4: no suportat');
    $actor = AuditActor::system('tests');

    $first = $blocking->apply($site, allChecks(), $actor, false);
    $second = $blocking->apply($site, allChecks(), $actor, false);

    $site->refresh();
    expect($first->outcome)->toBe(BlockOutcome::Unblocked)
        ->and($second->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($second->reason)->toBeNull()
        ->and($site->blocked_reason)->toBeNull()
        ->and($site->blocked_at)->toBeNull()
        ->and($site->blocked_by)->toBeNull()
        ->and($notifier->calls)->toBe(['unblocked'])
        ->and(AuditLogEntry::query()->where('action', 'site.unblocked')->count())->toBe(1);
});

it('never lifts nor overwrites a block set by an operator', function (?SiteBlockSource $by): void {
    [$blocking, $notifier] = blocking();
    $site = blockingSite($by, 'Migració en curs');
    $actor = AuditActor::system('tests');

    $good = $blocking->apply($site, allChecks(), $actor, false);
    $bad = $blocking->apply($site, allChecks(['php_minimum' => 'PHP < 7.4: no suportat']), $actor, false);

    $site->refresh();
    expect($good->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($bad->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($bad->reason)->toBe('Migració en curs')
        ->and($site->blocked_reason)->toBe('Migració en curs')
        ->and($notifier->calls)->toBe([])
        ->and(AuditLogEntry::query()->whereIn('action', ['site.blocked', 'site.unblocked'])->count())->toBe(0);
})->with([[SiteBlockSource::Operator], 'legacy block without source' => [null]]);

it('does not trample an operator block made while the inventory ran', function (): void {
    [$blocking, $notifier] = blocking();
    $stale = blockingSite();
    Site::query()->whereKey($stale->id)->update(['blocked_by' => 'operator', 'blocked_reason' => 'Migració en curs', 'blocked_at' => now()]);

    $result = $blocking->apply($stale, allChecks(['php_minimum' => 'PHP < 7.4: no suportat']), AuditActor::system('tests'), false);

    expect($result->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($result->reason)->toBe('Migració en curs')
        ->and($stale->blocked_reason)->toBe('Migració en curs')
        ->and(Site::query()->findOrFail($stale->id)->blocked_by)->toBe(SiteBlockSource::Operator)
        ->and($notifier->calls)->toBe([]);
});

it('leaves the site unblocked when the audit fails, and audits on the retry', function (): void {
    [$blocking, $notifier] = blocking();
    $site = blockingSite();
    $bad = allChecks(['php_minimum' => 'PHP < 7.4: no suportat']);
    $failOnce = true;
    Event::listen('eloquent.creating: '.AuditLogEntry::class, static function () use (&$failOnce): void {
        if ($failOnce) {
            $failOnce = false;
            throw new RuntimeException('audit down');
        }
    });

    expect(fn () => $blocking->apply($site, $bad, AuditActor::system('tests'), false))->toThrow(RuntimeException::class);
    expect($site->refresh()->blocked_reason)->toBeNull()
        ->and($notifier->calls)->toBe([]);

    $blocking->apply($site, $bad, AuditActor::system('tests'), false);

    expect($site->refresh()->blocked_reason)->toBe('PHP < 7.4: no suportat')
        ->and(AuditLogEntry::query()->where('action', 'site.blocked')->count())->toBe(1)
        ->and($notifier->calls)->toHaveCount(1);
});

it('does nothing to a healthy, unblocked site', function (): void {
    [$blocking, $notifier] = blocking();
    $site = blockingSite();

    $result = $blocking->apply($site, allChecks(), AuditActor::system('tests'), false);

    expect($result->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($result->reason)->toBeNull()
        ->and($notifier->calls)->toBe([]);
});

it('does not change the site in a dry run, only says what would happen', function (): void {
    [$blocking, $notifier] = blocking();
    $actor = AuditActor::system('tests');
    $site = blockingSite();
    $blocked = blockingSite(SiteBlockSource::Prerequisites, 'el compte no pot escriure a: /x');

    $would = $blocking->apply($site, allChecks(['writable' => 'el compte no pot escriure a: /x']), $actor, true);
    $wouldLift = $blocking->apply($blocked, allChecks(), $actor, true);

    expect($would->outcome)->toBe(BlockOutcome::WouldBlock)
        ->and($site->refresh()->blocked_reason)->toBeNull()
        ->and($wouldLift->outcome)->toBe(BlockOutcome::WouldUnblock)
        ->and($blocked->refresh()->blocked_reason)->toBe('el compte no pot escriure a: /x')
        ->and($notifier->calls)->toBe([])
        ->and(AuditLogEntry::query()->whereIn('action', ['site.blocked', 'site.unblocked'])->count())->toBe(0);
});

it('lets the PHP checks decide nothing in a dry run and never promises to lift a PHP block', function (): void {
    [$blocking] = blocking();
    $actor = AuditActor::system('tests');
    $phpBlocked = blockingSite(SiteBlockSource::Prerequisites, 'PHP < 7.4: no suportat');
    $healthy = blockingSite();

    // CLI PHP looks old, but without the probe that proves nothing.
    $noBlock = $blocking->apply($healthy, allChecks(['php_minimum' => 'PHP < 7.4: no suportat']), $actor, true);
    // CLI PHP looks fine, but the stored block came from the web's PHP.
    $kept = $blocking->apply($phpBlocked, allChecks(), $actor, true);

    expect($noBlock->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($noBlock->reason)->toBeNull()
        ->and($kept->outcome)->toBe(BlockOutcome::Unchanged)
        ->and($kept->reason)->toBe('PHP < 7.4: no suportat');
});

it('logs through the default notifier', function (): void {
    Log::shouldReceive('warning')->once();
    Log::shouldReceive('info')->once();
    $site = Site::factory()->create(['name' => 'botiga']);
    $notifier = app(SiteBlockNotifier::class);

    $notifier->siteBlocked($site, 'PHP < 7.4: no suportat');
    $notifier->siteUnblocked($site);

    expect($notifier)->toBeInstanceOf(LogSiteBlockNotifier::class);
});

/**
 * @return array{0: InventoryStep, 1: RecordingBlockNotifier}
 */
function stepWithBlocking(FakeDriver $driver): array
{
    [$blocking, $notifier] = blocking();

    return [new InventoryStep(new SiteInventory(null, null, null, prerequisites()), static fn () => $driver, app(RunAuthorization::class), 60, $blocking), $notifier];
}

it('blocks the site from the inventory step and puts the reason in the summary', function (): void {
    $driver = new FakeDriver('6.8.3', '7.3.33');
    [$step, $notifier] = stepWithBlocking($driver);
    $run = Run::factory()->create(['type' => RunType::Inventory]);

    $outcome = $step->run(new StepContext($run, false));

    expect($outcome->summary)->toContain('; bloquejat: PHP < 7.4: no suportat')
        ->and($outcome->data['block'])->toBe(['outcome' => 'blocked', 'reason' => 'PHP < 7.4: no suportat'])
        ->and($run->site?->refresh()->blocked_reason)->toBe('PHP < 7.4: no suportat')
        ->and($notifier->calls)->toHaveCount(1);

    // The same problem on the next inventory: still reported, not notified again.
    $again = $step->run(new StepContext(Run::factory()->create(['type' => RunType::Inventory, 'site_id' => $run->site_id]), false));
    expect($again->summary)->toContain('; bloquejat: PHP < 7.4: no suportat')
        ->and($notifier->calls)->toHaveCount(1);

    // The PHP is upgraded: the tool lifts its own block.
    $driver->phpVersion = '8.3.14';
    $fixed = $step->run(new StepContext(Run::factory()->create(['type' => RunType::Inventory, 'site_id' => $run->site_id]), false));
    expect($fixed->summary)->toContain('; desbloquejat')
        ->and($run->site?->refresh()->blocked_reason)->toBeNull();
});

it('only reports "es bloquejaria" in a dry run inventory step, without judging the PHP', function (): void {
    $driver = new FakeDriver('6.8.3', '7.3.33');
    $driver->unwritable = ['/var/www/html'];
    [$step, $notifier] = stepWithBlocking($driver);
    $run = Run::factory()->create(['type' => RunType::Inventory]);

    $outcome = $step->run(new StepContext($run, true));

    expect($outcome->summary)->toContain('; es bloquejaria: el compte no pot escriure a: /var/www/html')
        ->and($outcome->summary)->not->toContain('PHP < 7.4')
        ->and($outcome->data['block'])->toBe(['outcome' => 'would_block', 'reason' => 'el compte no pot escriure a: /var/www/html'])
        ->and($outcome->summary)->toContain('en dry-run no es verifica el PHP del web')
        ->and($run->site?->refresh()->blocked_reason)->toBeNull()
        ->and($notifier->calls)->toBe([]);
});

it('blocks the site when WP-CLI does not work, but not on a lost connection', function (): void {
    $driver = new FakeDriver;
    [$step, $notifier] = stepWithBlocking($driver);
    $run = Run::factory()->create(['type' => RunType::Inventory]);

    $driver->installedFailure = new ConnectionFailed('sense xarxa');
    $lost = $step->run(new StepContext($run, false));
    $afterLost = $run->site?->refresh()->blocked_reason;

    $driver->installedFailure = new RemoteCommandFailed('wp ha petat');
    $dry = $step->run(new StepContext($run, true));
    $afterDry = $run->site?->refresh()->blocked_reason;
    $broken = $step->run(new StepContext($run, false));

    expect($lost->status)->toBe(RunStepStatus::Failed)
        ->and($afterLost)->toBeNull()
        ->and($afterDry)->toBeNull()
        ->and($broken->status)->toBe(RunStepStatus::Failed)
        ->and($run->site?->refresh()->blocked_reason)->toBe('WP-CLI no funciona al lloc')
        ->and($notifier->calls)->toBe(['blocked:WP-CLI no funciona al lloc']);

    // WP-CLI works again: the tool lifts its own block.
    $driver->installedFailure = null;
    $fixed = $step->run(new StepContext($run, false));
    expect($fixed->summary)->toContain('; desbloquejat');
});

it('does not audit nor notify again when only live figures change between inventories', function (): void {
    $driver = new FakeDriver(freeDisk: 1_000);
    [$step, $notifier] = stepWithBlocking($driver);
    $run = Run::factory()->create(['type' => RunType::Inventory]);

    $step->run(new StepContext($run, false));
    $driver->freeDisk = 2_000;
    $driver->filesBytes = 300_000_000;
    $second = $step->run(new StepContext(Run::factory()->create(['type' => RunType::Inventory, 'site_id' => $run->site_id]), false));

    expect($run->site?->refresh()->blocked_reason)->toBe('espai insuficient per a la còpia')
        ->and($second->summary)->toContain('; bloquejat: espai insuficient per a la còpia')
        ->and($notifier->calls)->toHaveCount(1)
        ->and(AuditLogEntry::query()->where('action', 'site.blocked')->count())->toBe(1);
});

it('passes the configured du timeout and fails closed when it times out', function (): void {
    $driver = new FakeDriver;
    $service = new SitePrerequisites(new WpCliReader(app(SecretRedactor::class)), '7.4', 1.5, 10, 77);

    $service->check($driver, '8.3.14', [], null);
    $driver->diskUsageFails = true;
    $result = $service->check($driver, '8.3.14', [], null);

    expect($driver->duTimeouts)->toBe([77, 77])
        ->and(failed($result->checks, PrerequisiteName::BackupSpace)?->reason)->toBe("no s'ha pogut estimar l'espai per a la còpia")
        ->and($result->warnings)->toHaveCount(1)
        ->and(config('wpup.inventory.prerequisites.du_timeout_seconds'))->toBeInt();
});

it('fails writable closed, with a stable reason and a warning, when the check itself fails', function (): void {
    $driver = new FakeDriver;
    $driver->unwritableFails = true;
    Log::shouldReceive('warning')->once();

    $result = prerequisites()->check($driver, '8.3.14', [], null);

    expect(failed($result->checks, PrerequisiteName::Writable)?->reason)->toBe("no s'han pogut comprovar els permisos d'escriptura")
        ->and($result->warnings)->toHaveCount(1);
});

it('stores the evaluation warnings in the snapshot', function (): void {
    $driver = new FakeDriver;
    $driver->diskUsageFails = true;

    $snapshot = (new SiteInventory(null, null, null, prerequisites()))->take(Site::factory()->create(), $driver);

    expect($snapshot->environment['prerequisites_warnings'] ?? null)->toHaveCount(1);
});

it('says nothing about blocking for a healthy site', function (): void {
    [$step] = stepWithBlocking(new FakeDriver);

    $outcome = $step->run(new StepContext(Run::factory()->create(['type' => RunType::Inventory]), false));

    expect($outcome->summary)->not->toContain('bloquej');
});
