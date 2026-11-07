<?php

declare(strict_types=1);

namespace App\Domain\Verification;

/**
 * What to verify. It holds only public data: the site's public URL and the
 * pages to visit, never a credential (AGENTS.md, verifier).
 */
final readonly class VerificationRequest
{
    public const int PROTOCOL_VERSION = 1;

    /**
     * @param  list<PageTarget>  $urls
     * @param  list<Viewport>  $viewports
     * @param  list<string>  $masks
     */
    public function __construct(
        public int $runId,
        public string $siteName,
        public string $baseUrl,
        public array $urls,
        public array $viewports,
        public array $masks,
        public float $maxDiffRatio,
        public int $navigationTimeoutMs,
        public int $screenshotTimeoutMs,
        public int $totalMs,
    ) {}

    public static function withDefaults(int $runId, string $siteName, string $baseUrl, VerifierSettings $settings): self
    {
        return new self($runId, $siteName, $baseUrl, $settings->urls, $settings->viewports, $settings->masks,
            $settings->maxDiffRatio, $settings->navigationTimeoutMs, $settings->screenshotTimeoutMs, $settings->totalMs);
    }

    /**
     * The input document of protocol v1 (docs/verificador.md).
     *
     * @return array<string, mixed>
     */
    public function toProtocol(string $outputDir): array
    {
        return [
            'version' => self::PROTOCOL_VERSION,
            'run_id' => $this->runId,
            'site' => ['name' => $this->siteName, 'base_url' => $this->baseUrl],
            'urls' => array_map(static fn (PageTarget $u): array => ['path' => $u->path, 'profile' => $u->profile], $this->urls),
            'viewports' => array_map(static fn (Viewport $v): array => ['name' => $v->name, 'width' => $v->width], $this->viewports),
            'masks' => $this->masks,
            'thresholds' => ['max_diff_ratio' => $this->maxDiffRatio],
            'output_dir' => $outputDir,
            'timeouts' => ['navigation_ms' => $this->navigationTimeoutMs, 'screenshot_ms' => $this->screenshotTimeoutMs, 'total_ms' => $this->totalMs],
        ];
    }
}
