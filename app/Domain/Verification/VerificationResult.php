<?php

declare(strict_types=1);

namespace App\Domain\Verification;

/**
 * The output document of protocol v1, validated: anything else is a
 * VerifierFailed, so later steps never see an unchecked shape.
 */
final readonly class VerificationResult
{
    /** A screenshot is "<viewport>/<n>.png" inside the output directory, nothing else. */
    private const string SCREENSHOT = '#^[a-z][a-z0-9_-]{0,29}/\d{1,3}\.png$#D';

    /**
     * @param  list<PageResult>  $pages
     * @param  list<string>  $verifierErrors
     * @param  bool  $partial  the verifier ran out of time before visiting every url × viewport
     */
    public function __construct(public string $outputDir, public array $pages, public array $verifierErrors, public bool $partial) {}

    /**
     * @throws VerifierFailed
     */
    public static function fromProtocol(mixed $data, int $runId, string $outputDir): self
    {
        if (! is_array($data) || ($data['version'] ?? null) !== VerificationRequest::PROTOCOL_VERSION) {
            throw VerifierFailed::unreadable('no és un document de la versió '.VerificationRequest::PROTOCOL_VERSION);
        }
        if (($data['run_id'] ?? null) !== $runId) {
            throw VerifierFailed::unreadable("no correspon a l'execució {$runId}");
        }
        if (! is_array($data['results'] ?? null) || ! array_is_list($data['results']) || ! is_bool($data['partial'] ?? null)) {
            throw VerifierFailed::unreadable('falten els resultats o la marca de resultat parcial');
        }

        return new self($outputDir, array_map(self::page(...), $data['results']), self::strings($data['verifier_errors'] ?? null, 'verifier_errors'), $data['partial']);
    }

    /** Pages answering 4xx/5xx or not loading at all. */
    public function httpErrors(): int
    {
        return count(array_filter($this->pages, static fn (PageResult $p): bool => $p->httpStatus === null || $p->httpStatus >= 400));
    }

    /**
     * Anything worth a warning: partial, verifier errors, or a page with an HTTP
     * error, console or resource errors, or that did not load. Decides how long
     * the screenshots are kept (ADR-0009).
     */
    public function hasWarnings(): bool
    {
        return $this->partial || $this->verifierErrors !== [] || $this->httpErrors() > 0 || array_filter($this->pages,
            static fn (PageResult $p): bool => $p->error !== null || $p->consoleErrors !== [] || $p->resourceErrors !== []) !== [];
    }

    public function consoleErrors(): int
    {
        return array_sum(array_map(static fn (PageResult $p): int => count($p->consoleErrors), $this->pages));
    }

    private static function page(mixed $page): PageResult
    {
        if (! is_array($page) || ! is_string($page['path'] ?? null) || ! is_string($page['profile'] ?? null) || ! is_string($page['viewport'] ?? null)
            || ! is_array($page['timings'] ?? null) || ! is_int($page['timings']['navigation_ms'] ?? null)) {
            throw VerifierFailed::unreadable('un resultat de pàgina no té la forma esperada');
        }
        $status = $page['http_status'] ?? null;
        $screenshot = $page['screenshot'] ?? null;
        $error = $page['error'] ?? null;
        if (($status !== null && ! is_int($status)) || ($error !== null && ! is_string($error))
            || ($screenshot !== null && (! is_string($screenshot) || preg_match(self::SCREENSHOT, $screenshot) !== 1))) {
            throw VerifierFailed::unreadable("el resultat de «{$page['path']}» no té la forma esperada");
        }
        if (! is_array($page['resource_errors'] ?? null) || ! array_is_list($page['resource_errors'])) {
            throw VerifierFailed::unreadable('falten els errors de recursos');
        }
        $resources = array_map(static function (mixed $r): ResourceError {
            if (! is_array($r) || ! is_string($r['url'] ?? null) || ! (is_int($r['status'] ?? null) || ($r['status'] ?? null) === null)
                || ! (is_string($r['failure'] ?? null) || ($r['failure'] ?? null) === null)) {
                throw VerifierFailed::unreadable('un error de recurs no té la forma esperada');
            }

            return new ResourceError($r['url'], $r['status'] ?? null, $r['failure'] ?? null);
        }, $page['resource_errors']);

        return new PageResult($page['path'], $page['profile'], $page['viewport'], $status, $screenshot,
            self::strings($page['console_errors'] ?? null, 'console_errors'), $resources,
            self::strings($page['blocked_requests'] ?? null, 'blocked_requests'), $page['timings']['navigation_ms'], $error);
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value, string $field): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, is_string(...)) !== $value) {
            throw VerifierFailed::unreadable("«{$field}» no és una llista de textos");
        }

        return $value;
    }
}
