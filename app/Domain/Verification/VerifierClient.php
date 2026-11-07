<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Domain\Secrets\SecretRedactor;
use App\Support\PrivateDirectory;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs the verifier (docs/verificador.md): `setsid node bin.js`, an argument
 * array, with the input on stdin and a clean environment, so the verifier
 * never sees the database, the keys or any credential of the worker. It writes
 * result.json into the private output directory (0700).
 *
 * The verifier leads its own process group: on the hard timeout (or too much
 * output) the whole group gets SIGTERM, then SIGKILL after a grace period,
 * so no browser is left behind. Output is drained and cleared on every poll:
 * stdout is kept up to its cap, stderr is only counted (never stored).
 */
final readonly class VerifierClient
{
    public const string RESULT_FILE = 'result.json';

    private const int EXIT_INVALID_INPUT = 2;

    private const int POLL_MICROSECONDS = 100_000;

    public function __construct(private VerifierSettings $settings, private SecretRedactor $redactor) {}

    /**
     * @throws VerifierFailed
     */
    public function verify(VerificationRequest $request, string $outputDir): VerificationResult
    {
        $this->assertInstalled();
        try {
            PrivateDirectory::ensure($outputDir);
        } catch (RuntimeException $e) {
            $reason = $this->redactor->redact($e->getMessage());
            Log::error('Verifier output directory unavailable.', ['reason' => $reason]);

            throw VerifierFailed::workDir($reason, $e);
        }
        $result = $outputDir.'/'.self::RESULT_FILE;
        // Idempotent: a result left by an interrupted attempt is never read as this one's.
        @unlink($result);

        $input = json_encode($request->toProtocol($outputDir), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $command = [$this->settings->setsidBinary, $this->settings->nodeBinary, $this->settings->script];
        $process = new Process($command, $outputDir, $this->environment($outputDir), $input, null);
        $stdout = $this->supervise($process);

        $exit = $process->getExitCode();
        if ($exit !== 0) {
            $detail = $this->errorDetail($stdout);
            throw $exit === self::EXIT_INVALID_INPUT ? VerifierFailed::rejected($detail) : VerifierFailed::crashed($exit, $detail);
        }
        if (! is_file($result) || is_link($result)) {
            throw VerifierFailed::unreadable('no ha escrit '.self::RESULT_FILE);
        }
        $size = filesize($result);
        if ($size === false || $size > $this->settings->maxResultBytes) {
            throw VerifierFailed::unreadable('supera '.$this->settings->maxResultBytes.' bytes');
        }

        return VerificationResult::fromProtocol(json_decode((string) file_get_contents($result), true), $request->runId, $outputDir);
    }

    /**
     * Only what Node and Playwright need. Every inherited variable is removed
     * explicitly: Symfony Process would otherwise pass the worker's whole
     * environment (getenv() and $_ENV, i.e. .env: DB_*, APP_KEY, WPUP_DEV_KEK…).
     *
     * @return array<string, string|false>
     */
    public function environment(string $home): array
    {
        $inherited = array_filter(array_keys(getenv() + $_ENV + $_SERVER), is_string(...));
        $allowed = array_filter([
            'PATH' => $this->settings->path,
            'HOME' => $home,
            'LANG' => 'C.UTF-8',
            'PLAYWRIGHT_BROWSERS_PATH' => $this->settings->browsersPath,
        ], is_string(...));

        return $allowed + array_fill_keys($inherited, false);
    }

    /**
     * Runs the process to its end, or stops it: hard timeout, or a stream over its cap.
     *
     * @return string stdout, up to its cap
     *
     * @throws VerifierFailed
     */
    private function supervise(Process $process): string
    {
        $process->start();
        $deadline = microtime(true) + $this->settings->timeoutSeconds;
        $stdout = '';
        $stderrBytes = 0;
        do {
            $running = $process->isRunning();
            $chunk = $process->getIncrementalOutput();
            $stderrBytes += strlen($process->getIncrementalErrorOutput());
            $process->clearOutput()->clearErrorOutput();
            if (strlen($stdout) + strlen($chunk) > $this->settings->maxStdoutBytes) {
                $this->stop($process);
                throw VerifierFailed::outputTooLarge('stdout', $this->settings->maxStdoutBytes);
            }
            $stdout .= $chunk;
            if ($stderrBytes > $this->settings->maxStderrBytes) {
                $this->stop($process);
                throw VerifierFailed::outputTooLarge('stderr', $this->settings->maxStderrBytes);
            }
            if ($running && microtime(true) >= $deadline) {
                $this->stop($process);
                throw VerifierFailed::timeout($this->settings->timeoutSeconds);
            }
            if ($running) {
                usleep(self::POLL_MICROSECONDS);
            }
        } while ($running);

        return $stdout;
    }

    /** SIGTERM to the whole group, a grace period, then SIGKILL to the group. */
    private function stop(Process $process): void
    {
        $group = $process->getPid();
        if ($group !== null) {
            @posix_kill(-$group, SIGTERM);
            $until = microtime(true) + $this->settings->stopGraceSeconds;
            while ($process->isRunning() && microtime(true) < $until) {
                $process->clearOutput()->clearErrorOutput();
                usleep(self::POLL_MICROSECONDS);
            }
            // Also the children that outlived the leader (the browser).
            @posix_kill(-$group, SIGKILL);
        }
        $process->stop(0);
    }

    /**
     * @throws VerifierFailed
     */
    private function assertInstalled(): void
    {
        if (! is_executable($this->settings->setsidBinary)) {
            throw VerifierFailed::notInstalled("no es troba «{$this->settings->setsidBinary}»");
        }
        $node = $this->settings->nodeBinary;
        $found = str_contains($node, '/') ? is_executable($node)
            : array_filter(explode(':', $this->settings->path), static fn (string $dir): bool => is_executable("{$dir}/{$node}")) !== [];
        if (! $found) {
            throw VerifierFailed::notInstalled("no es troba Node («{$node}»)");
        }
        if (! is_file($this->settings->script)) {
            throw VerifierFailed::notInstalled('falta '.basename(dirname($this->settings->script)).'/'.basename($this->settings->script).' (cal `npm ci && npm run build` a verifier/)');
        }
    }

    /** The verifier's own error line: code, message and the failing fields; redacted first, then cut. */
    private function errorDetail(string $stdout): string
    {
        $line = json_decode(trim(strtok($stdout, "\n") ?: ''), true);
        $error = is_array($line) && is_array($line['error'] ?? null) ? $line['error'] : null;
        if ($error === null || ! is_string($error['message'] ?? null)) {
            return '';
        }
        $issues = is_array($error['issues'] ?? null) ? array_slice(array_filter($error['issues'], is_string(...)), 0, 5) : [];

        return mb_substr($this->redactor->redact($error['message'].($issues === [] ? '' : ' ('.implode(', ', $issues).')')), 0, 500);
    }
}
