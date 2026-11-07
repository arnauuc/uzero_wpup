<?php

declare(strict_types=1);

namespace App\Domain\Verification;

use App\Domain\Runs\Step;
use App\Domain\Runs\StepContext;
use App\Domain\Runs\StepOutcome;

/**
 * Runs the verifier against the run's site and records what each page did
 * (UZEROWPUP-64). Not wired into any pipeline yet (UZEROWPUP-72): the same
 * step serves the baseline and the check after the update, each under its own
 * name and output directory. It does not judge the site, comparing against
 * the baseline comes later: it fails only when the verifier itself fails.
 *
 * Repeatable: a second attempt writes a fresh result over the same directory.
 */
final readonly class VerifyStep implements Step
{
    public function __construct(
        private string $name,
        private VerifierClient $client,
        private VerifierSettings $settings,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function timeoutSeconds(): int
    {
        return $this->settings->stepTimeoutSeconds;
    }

    public function describe(StepContext $context): string
    {
        return 'Visitarà '.count($this->settings->urls).' URL(s) del lloc en '.count($this->settings->viewports).' viewport(s) amb el verificador i en desarà les captures i els errors.';
    }

    public function run(StepContext $context): StepOutcome
    {
        $site = $context->run->site;
        if ($site === null) {
            return StepOutcome::failed("L'execució no té cap lloc.");
        }
        $outputDir = $this->outputDir($context->run->id);
        $request = VerificationRequest::withDefaults($context->run->id, $site->name, $site->url, $this->settings);

        try {
            $result = $this->client->verify($request, $outputDir);
        } catch (VerifierFailed $e) {
            return StepOutcome::failed($e->getMessage());
        }

        $output = implode("\n", array_map(static fn (PageResult $p): string => "{$p->viewport} {$p->path}: HTTP ".($p->httpStatus ?? '—')
            .', '.count($p->consoleErrors).' error(s) de consola, '.count($p->resourceErrors).' recurs(os) amb error'.($p->error === null ? '' : " ({$p->error})"),
            $result->pages));
        $warned = $result->verifierErrors === [] ? '' : ' AVÍS: '.implode('; ', $result->verifierErrors).'.';

        return StepOutcome::succeeded(
            count($result->pages)." pàgina(es) verificada(es): {$result->httpErrors()} amb error HTTP, {$result->consoleErrors()} error(s) de consola.".$warned,
            $output,
            ['output_dir' => $outputDir, 'pages' => count($result->pages), 'http_errors' => $result->httpErrors(), 'console_errors' => $result->consoleErrors()],
        );
    }

    private function outputDir(int $runId): string
    {
        return $this->settings->workDir."/run-{$runId}/".preg_replace('/[^a-z0-9_-]+/', '-', strtolower($this->name));
    }
}
