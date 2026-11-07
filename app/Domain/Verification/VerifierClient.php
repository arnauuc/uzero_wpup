<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Domain\Secrets\SecretRedactor;
use App\Support\PrivateDirectory;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Runs the verifier (docs/verificador.md): a child process with an argument
 * array, a hard timeout, the input on stdin and a clean environment, so the
 * verifier never sees the database, the keys or any credential of the
 * worker. It writes result.json into the private output directory (0700).
 */
final readonly class VerifierClient
{
    public const string RESULT_FILE = 'result.json';

    /** stdout carries at most one JSON error line; more is cut. */
    private const int MAX_STDOUT_BYTES = 65536;

    private const int EXIT_INVALID_INPUT = 2;

    public function __construct(private VerifierSettings $settings, private SecretRedactor $redactor) {}

    /**
     * @throws VerifierFailed
     */
    public function verify(VerificationRequest $request, string $outputDir): VerificationResult
    {
        try {
            PrivateDirectory::ensure($outputDir);
        } catch (RuntimeException) {
            throw VerifierFailed::unreadable("no s'ha pogut preparar el directori de sortida");
        }
        $result = $outputDir.'/'.self::RESULT_FILE;
        // Idempotent: a result left by an interrupted attempt is never read as this one's.
        @unlink($result);

        $input = json_encode($request->toProtocol($outputDir), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $process = new Process($this->settings->command, $outputDir, $this->environment($outputDir), $input, $this->settings->timeoutSeconds);
        $stdout = '';
        try {
            $process->run(static function (string $type, string $buffer) use (&$stdout): void {
                // stderr may hold a stack trace or page content: it is never read.
                if ($type === Process::OUT && strlen($stdout) < self::MAX_STDOUT_BYTES) {
                    $stdout .= substr($buffer, 0, self::MAX_STDOUT_BYTES - strlen($stdout));
                }
            });
        } catch (ProcessTimedOutException) {
            throw VerifierFailed::timeout($this->settings->timeoutSeconds);
        }

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

    /** The verifier's own error line: code, message and the failing fields, redacted. */
    private function errorDetail(string $stdout): string
    {
        $line = json_decode(trim(strtok($stdout, "\n") ?: ''), true);
        $error = is_array($line) && is_array($line['error'] ?? null) ? $line['error'] : null;
        if ($error === null || ! is_string($error['message'] ?? null)) {
            return '';
        }
        $issues = is_array($error['issues'] ?? null) ? array_slice(array_filter($error['issues'], is_string(...)), 0, 5) : [];

        return $this->redactor->redact(mb_substr($error['message'].($issues === [] ? '' : ' ('.implode(', ', $issues).')'), 0, 500));
    }
}
