<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Domain\Secrets\SecretRedactor;
use Illuminate\Log\Logger;
use Monolog\Logger as Monolog;

/**
 * Channel "tap" that installs RedactSecretsProcessor. AppServiceProvider adds it to
 * every configured log channel, so no channel can bypass the redaction.
 */
final readonly class RedactSecretsTap
{
    public function __construct(private SecretRedactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();

        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(new RedactSecretsProcessor($this->redactor));
        }
    }
}
