<?php

declare(strict_types=1);

namespace App\Support\Logging;

use App\Domain\Secrets\SecretRedactor;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Monolog processor that passes every log record through the SecretRedactor.
 * Exceptions in the context are replaced by a redacted text rendering, because
 * their message and trace may carry secrets and cannot be modified in place.
 */
final readonly class RedactSecretsProcessor implements ProcessorInterface
{
    public function __construct(private SecretRedactor $redactor) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->redactor->redact($record->message),
            context: $this->redactor->redactArray($this->renderThrowables($record->context)),
            extra: $this->redactor->redactArray($record->extra),
        );
    }

    /**
     * @param  array<mixed>  $context
     * @return array<mixed>
     */
    private function renderThrowables(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($value instanceof Throwable) {
                $context[$key] = sprintf(
                    '%s: %s in %s:%d%s%s',
                    $value::class,
                    $value->getMessage(),
                    $value->getFile(),
                    $value->getLine(),
                    PHP_EOL,
                    $value->getTraceAsString(),
                );
            }
        }

        return $context;
    }
}
