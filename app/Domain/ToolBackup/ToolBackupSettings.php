<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

/**
 * config('wpup.tool_backup') and the staging directory, validated once.
 */
final readonly class ToolBackupSettings
{
    /** An X25519 age recipient: "age1" and 58 bech32 characters. */
    private const string RECIPIENT = '/^age1[02-9ac-hj-np-z]{58}$/';

    /**
     * @param  list<string>  $recipients
     * @param  list<string>  $configPaths
     * @param  list<string>  $reportPaths
     */
    public function __construct(
        public array $recipients,
        public array $configPaths,
        public array $reportPaths,
        public string $stagingDir,
        public int $dumpTimeoutSeconds,
        public int $archiveTimeoutSeconds,
        public int $encryptTimeoutSeconds,
        public int $dailyCopies,
        public int $monthlyCopies,
    ) {}

    /**
     * The recipients, refusing to go on without at least one or with any that
     * is not a valid age public key (a typo must not leave a copy nobody can
     * open, nor one encrypted to a key other than intended).
     *
     * @return list<string>
     *
     * @throws ToolBackupException
     */
    public function validRecipients(): array
    {
        if ($this->recipients === []) {
            throw new ToolBackupException("No hi ha cap destinatari d'age (WPUP_TOOL_BACKUP_AGE_RECIPIENTS): la còpia de l'eina no es fa sense xifrar.");
        }
        foreach ($this->recipients as $recipient) {
            if (preg_match(self::RECIPIENT, $recipient) !== 1) {
                throw new ToolBackupException('El destinatari d\'age «'.mb_substr($recipient, 0, 12).'…» no és una clau pública vàlida (age1…).');
            }
        }

        return $this->recipients;
    }
}
