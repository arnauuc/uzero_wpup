<?php

declare(strict_types=1);

namespace App\Domain\ToolBackup;

use App\Domain\Secrets\SecretRedactor;
use App\Support\PrivateDirectory;
use DateTimeInterface;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Builds the tool's backup (ADR-0009 §3) in a private work directory of the
 * staging directory (0700, never /tmp):
 *
 *   manifest.json   what is inside, for the restore checks
 *   db.dump         pg_dump --format=custom of the tool database
 *   files/<path>    the non-secret configuration and reports (ToolBackupContents)
 *
 * tarred and encrypted with age to the configured recipients. Every command is
 * an argument array with a timeout; the plaintext (dump, copies, tar) is
 * removed in `finally`, so only the ciphertext ever stays on disk.
 */
final readonly class ToolArchiveBuilder
{
    public const int MANIFEST_VERSION = 1;

    private const string AGE_HEADER = "age-encryption.org/v1\n";

    private const int ERROR_CHARS = 500;

    public function __construct(
        private ToolBackupSettings $settings,
        private ToolBackupContents $contents,
        private ToolDatabase $database,
        private SecretRedactor $redactor,
    ) {}

    /**
     * @throws ToolBackupException
     */
    public function build(DateTimeInterface $at): ToolArchive
    {
        $recipients = $this->settings->validRecipients();
        [$files, $warnings] = $this->contents->collect([...$this->settings->configPaths, ...$this->settings->reportPaths]);
        if ($this->database->password !== null) {
            $this->redactor->register($this->database->password);
        }

        try {
            $staging = PrivateDirectory::ensure($this->settings->stagingDir);
            $work = PrivateDirectory::ensure($staging.'/tool-backup-'.bin2hex(random_bytes(8)));
        } catch (RuntimeException $e) {
            throw new ToolBackupException($e->getMessage());
        }
        $out = $work.'.tar.age';

        try {
            $this->run(['pg_dump', '--format=custom', '--no-password', '--file='.$work.'/db.dump', ...$this->database->arguments()],
                $this->settings->dumpTimeoutSeconds, 'El pg_dump de la BD de l\'eina', $this->database->environment());
            $dumpBytes = (int) filesize($work.'/db.dump');

            foreach ($files as $file) {
                $this->copy($file, $work.'/'.$file->archivePath);
            }
            $this->writeManifest($work.'/manifest.json', $at, $dumpBytes, $files, $warnings);

            $members = ['manifest.json', 'db.dump', ...($files === [] ? [] : ['files'])];
            $this->run(['tar', '--create', '--format=posix', '--numeric-owner', '--file='.$work.'/archive.tar', '--directory='.$work, '--', ...$members],
                $this->settings->archiveTimeoutSeconds, "L'arxiu de la còpia de l'eina");

            $encrypt = ['age', '--encrypt'];
            foreach ($recipients as $recipient) {
                array_push($encrypt, '--recipient', $recipient);
            }
            $this->run([...$encrypt, '--output', $out, $work.'/archive.tar'], $this->settings->encryptTimeoutSeconds, "El xifratge amb age de la còpia de l'eina");
            chmod($out, 0600);

            if (file_get_contents($out, false, null, 0, strlen(self::AGE_HEADER)) !== self::AGE_HEADER) {
                throw new ToolBackupException("La sortida d'age no és un fitxer xifrat d'age: no es puja.");
            }
            $sha256 = hash_file('sha256', $out);
            if ($sha256 === false) {
                throw new ToolBackupException("No s'ha pogut calcular el SHA-256 de la còpia de l'eina.");
            }

            return new ToolArchive($out, $sha256, (int) filesize($out), $dumpBytes, $files, $warnings);
        } catch (Throwable $e) {
            @unlink($out);

            throw $e;
        } finally {
            self::remove($work);
        }
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $env
     *
     * @throws ToolBackupException
     */
    private function run(array $command, int $timeoutSeconds, string $what, array $env = []): void
    {
        $process = new Process($command, null, $env, null, $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new ToolBackupException("{$what} ha superat el temps màxim ({$timeoutSeconds} s).");
        }
        if (! $process->isSuccessful()) {
            $reason = trim($process->getErrorOutput()) ?: trim($process->getOutput());
            $reason = $this->redactor->redact(mb_substr($reason, 0, self::ERROR_CHARS));

            throw new ToolBackupException("{$what} ha fallat (codi {$process->getExitCode()}): {$reason}");
        }
    }

    /**
     * @throws ToolBackupException
     */
    private function copy(ToolBackupFile $file, string $to): void
    {
        try {
            PrivateDirectory::ensure(dirname($to));
        } catch (RuntimeException $e) {
            throw new ToolBackupException($e->getMessage());
        }
        if (! @copy($file->source, $to) || ! chmod($to, 0600)) {
            throw new ToolBackupException("No s'ha pogut copiar «{$file->source}» a la còpia de l'eina.");
        }
    }

    /**
     * @param  list<ToolBackupFile>  $files
     * @param  list<string>  $warnings
     */
    private function writeManifest(string $path, DateTimeInterface $at, int $dumpBytes, array $files, array $warnings): void
    {
        $manifest = [
            'version' => self::MANIFEST_VERSION,
            'created_at' => $at->format(DATE_ATOM),
            'database' => ['name' => $this->database->database, 'file' => 'db.dump', 'format' => 'pg_dump custom', 'size_bytes' => $dumpBytes],
            'files' => array_map(static fn (ToolBackupFile $f): array => ['path' => $f->source, 'archive_path' => $f->archivePath, 'size_bytes' => $f->sizeBytes], $files),
            'skipped' => $warnings,
        ];
        if (file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new ToolBackupException("No s'ha pogut escriure el manifest de la còpia de l'eina.");
        }
        chmod($path, 0600);
    }

    /**
     * Removes the work directory (only what this class created: no links followed).
     */
    private static function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            if ($item instanceof SplFileInfo) {
                $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
