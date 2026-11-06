<?php

declare(strict_types=1);

namespace App\Domain\Compatibility;

use App\Domain\Artifacts\Artifact;
use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Secrets\SecretRedactor;
use App\Models\CompatScan;
use App\Support\PrivateDirectory;
use Composer\InstalledVersions;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Compatibility, layer 2 (UZEROWPUP-50, AGENTS.md): analyses a package with
 * PHP_CodeSniffer + PHPCompatibility, testVersion = the site's PHP minor and
 * up. The artifact is verified (SHA-256) right before it is extracted; the
 * extraction goes into a private directory (0700) that is always removed.
 * phpcs runs as an argument array (never a shell string) with a clean
 * environment, a timeout, a memory limit and a cap on its output. The result
 * is cached by package checksum, PHP minor and rules version, so the same
 * bytes are analysed once.
 */
final readonly class StaticCompatibility
{
    private const string PHP_MINOR = '/^\d{1,2}\.\d{1,2}$/D';

    /** phpcs exit codes up to this one mean "ran; violations found" (3 is a processing error). */
    private const int MAX_VIOLATIONS_EXIT = 2;

    public function __construct(
        private CompatSettings $settings,
        private ArtifactStore $store,
        private PhpFileExtractor $extractor,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @param  string  $phpMinor  the site's PHP, "major.minor" (e.g. "7.4")
     *
     * @throws StaticAnalysisFailed
     * @throws ArtifactException the artifact was purged, is missing or does not match its checksum
     */
    public function analyse(Artifact $artifact, string $phpMinor): CompatReport
    {
        if (preg_match(self::PHP_MINOR, $phpMinor) !== 1) {
            throw new StaticAnalysisFailed("la versió de PHP «{$phpMinor}» no es pot llegir.");
        }
        $rules = $this->rulesVersion();

        $cached = CompatScan::query()->where(['sha256' => $artifact->sha256, 'php_minor' => $phpMinor, 'rules_version' => $rules])->first();
        if ($cached !== null) {
            return new CompatReport($cached->error_count, $cached->warning_count, self::findingsOf($cached->summary), cached: true);
        }

        // Right before use, and again if the package sat in the queue for a while.
        $artifact = $this->store->verify($artifact);

        $report = $this->scan($artifact, $phpMinor);
        CompatScan::query()->insertOrIgnore([
            'sha256' => $artifact->sha256,
            'php_minor' => $phpMinor,
            'rules_version' => $rules,
            'error_count' => $report->errors,
            'warning_count' => $report->warnings,
            'summary' => json_encode(array_map(static fn (CompatFinding $f): array => $f->toArray(), $report->findings), JSON_THROW_ON_ERROR),
        ]);

        return $report;
    }

    /**
     * Part of the cache key: the result changes with the rules and the exclusions.
     */
    public function rulesVersion(): string
    {
        try {
            $compat = InstalledVersions::getPrettyVersion('phpcompatibility/php-compatibility');
            $phpcs = InstalledVersions::getPrettyVersion('squizlabs/php_codesniffer');
        } catch (\OutOfBoundsException) {
            $compat = $phpcs = null;
        }
        if ($compat === null || $phpcs === null) {
            throw new StaticAnalysisFailed('PHPCompatibility o PHP_CodeSniffer no estan instal·lats.');
        }

        return "compat-{$compat}|phpcs-{$phpcs}|".substr(sha1(implode("\n", $this->settings->ignore)), 0, 8);
    }

    /**
     * @throws StaticAnalysisFailed
     */
    private function scan(Artifact $artifact, string $phpMinor): CompatReport
    {
        if (! is_file($this->settings->phpcsBinary) || ! is_dir($this->settings->standardPath)) {
            throw new StaticAnalysisFailed('PHP_CodeSniffer o PHPCompatibility no estan disponibles.');
        }

        $root = $this->workDirectory();
        try {
            $files = $this->extractor->extract($artifact->path, $root, $this->settings->maxFiles, $this->settings->maxUnpackedBytes);
            if ($files === 0) {
                return new CompatReport(0, 0, []);
            }

            return $this->parse($this->runPhpcs($root, $phpMinor), $root);
        } finally {
            File::deleteDirectory($root);
        }
    }

    /**
     * @throws StaticAnalysisFailed
     */
    private function workDirectory(): string
    {
        try {
            $base = PrivateDirectory::ensure($this->settings->workDir);

            return PrivateDirectory::ensure($base.'/scan-'.bin2hex(random_bytes(8)));
        } catch (RuntimeException $e) {
            throw StaticAnalysisFailed::workDir($e->getMessage());
        }
    }

    /**
     * @throws StaticAnalysisFailed
     */
    private function runPhpcs(string $root, string $phpMinor): string
    {
        $command = [
            PHP_BINARY,
            '-d', 'memory_limit='.$this->settings->memoryLimit,
            $this->settings->phpcsBinary,
            '-q', '--no-colors', '--no-cache', '--parallel=1',
            '--standard=PHPCompatibility',
            '--runtime-set', 'installed_paths', $this->settings->standardPath,
            '--runtime-set', 'testVersion', "{$phpMinor}-",
            '--extensions=php',
            '--report=json',
            ...($this->settings->ignore === [] ? [] : ['--ignore='.implode(',', $this->settings->ignore)]),
            '.',
        ];
        // The analysed code is never run, but nothing of the tool's environment
        // (database, keys) is needed either: start from an empty one.
        $env = ['PATH' => '/usr/bin:/bin', 'HOME' => $root] + array_fill_keys(array_keys(getenv()), false);
        $process = new Process($command, $root, $env, null, $this->settings->timeoutSeconds);

        $output = '';
        $tooLarge = false;
        try {
            $process->run(function (string $type, string $buffer) use (&$output, &$tooLarge, $process): void {
                if ($type !== Process::OUT) {
                    return;
                }
                $output .= $buffer;
                if (strlen($output) > $this->settings->maxOutputBytes) {
                    $tooLarge = true;
                    $process->stop(0);
                }
            });
        } catch (ProcessTimedOutException) {
            throw StaticAnalysisFailed::timeout($this->settings->timeoutSeconds);
        }
        if ($tooLarge) {
            throw StaticAnalysisFailed::outputTooLarge();
        }
        $exit = $process->getExitCode();
        if ($exit === null || $exit > self::MAX_VIOLATIONS_EXIT) {
            throw StaticAnalysisFailed::crashed($exit ?? -1);
        }

        return $output;
    }

    /**
     * @throws StaticAnalysisFailed
     */
    private function parse(string $json, string $root): CompatReport
    {
        $data = json_decode($json, true);
        if (! is_array($data) || ! is_array($data['totals'] ?? null) || ! is_int($data['totals']['errors'] ?? null) || ! is_int($data['totals']['warnings'] ?? null)) {
            throw StaticAnalysisFailed::unreadable();
        }

        $errors = [];
        $warnings = [];
        foreach (is_array($data['files'] ?? null) ? $data['files'] : [] as $path => $file) {
            if (! is_array($file) || ! is_array($file['messages'] ?? null)) {
                continue;
            }
            $relative = $this->relative((string) $path, $root);
            foreach ($file['messages'] as $message) {
                if (! is_array($message) || ! is_string($message['message'] ?? null)) {
                    continue;
                }
                $isError = ($message['type'] ?? null) === 'ERROR';
                $finding = new CompatFinding($relative, is_int($message['line'] ?? null) ? $message['line'] : 0, $this->clean($message['message'], $this->settings->summaryMaxMessageLength), $isError);
                if ($isError) {
                    $errors[] = $finding;
                } else {
                    $warnings[] = $finding;
                }
            }
        }
        // Errors first: they are what blocks. Order by place so the summary is stable.
        $order = static fn (CompatFinding $a, CompatFinding $b): int => [$a->file, $a->line] <=> [$b->file, $b->line];
        usort($errors, $order);
        usort($warnings, $order);

        return new CompatReport(
            $data['totals']['errors'],
            $data['totals']['warnings'],
            array_slice([...$errors, ...$warnings], 0, $this->settings->summaryMaxFindings),
        );
    }

    private function relative(string $path, string $root): string
    {
        $prefix = rtrim($root, '/').'/';
        $path = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : basename($path);

        return $this->clean($path, $this->settings->summaryMaxPathLength);
    }

    /**
     * Text from the package: no control characters, valid UTF-8, redacted, capped.
     */
    private function clean(string $text, int $max): string
    {
        $text = (string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', mb_scrub($text, 'UTF-8'));

        return mb_strcut($this->redactor->redact(trim($text)), 0, $max, 'UTF-8');
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<CompatFinding>
     */
    private static function findingsOf(array $rows): array
    {
        $findings = [];
        foreach ($rows as $row) {
            $finding = CompatFinding::fromArray($row);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }
}
