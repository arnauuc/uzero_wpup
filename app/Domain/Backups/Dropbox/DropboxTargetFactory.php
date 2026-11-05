<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupKey;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use App\Models\BackupTarget as BackupTargetRecord;
use Closure;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use League\Flysystem\Filesystem;
use Psr\Http\Message\RequestInterface;

/**
 * Builds the BackupTarget of a backup_targets row of type dropbox:
 * config.root is the folder inside the App folder, credential_ref the
 * SecretStore id of the DropboxCredential JSON.
 */
final readonly class DropboxTargetFactory
{
    /**
     * @param  (Closure(RequestInterface, array<mixed>): PromiseInterface)|null  $handler  Guzzle handler; tests pass a fake API
     */
    public function __construct(
        private SecretStore $secrets,
        private SecretRedactor $redactor,
        private DropboxSettings $settings,
        private int $hashChunkBytes,
        private ?Closure $handler = null,
    ) {}

    /**
     * @throws BackupTargetException
     */
    public function make(BackupTargetRecord $record, AuditActor $actor): FlysystemTarget
    {
        $root = $record->config['root'] ?? null;
        // The same rules as the keys under it, in lowercase (DropboxAdapter).
        if (! is_string($root) || ! BackupKey::isValid($root) || strtolower($root) !== $root) {
            throw new BackupTargetException("La destinació de Dropbox «{$record->name}» necessita a config.root una carpeta relativa en minúscules dins de la carpeta de l'app.");
        }
        if ($record->credential_ref === null || $record->credential_ref === '') {
            throw new BackupTargetException("La destinació de Dropbox «{$record->name}» no té cap credencial (credential_ref).");
        }

        try {
            $secret = $this->secrets->reveal($record->credential_ref, $actor, "Destinació de còpia Dropbox «{$record->name}»");
            try {
                $credential = DropboxCredential::fromSecret($secret);
            } finally {
                $secret->wipe();
            }
        } catch (DropboxException|SecretStoreException $e) {
            throw new BackupTargetException($this->redactor->redact("La credencial de la destinació de Dropbox «{$record->name}» no és utilitzable: {$e->getMessage()}"));
        }

        // Timeouts are set per request (DropboxSettings::timeouts()).
        $http = new Client($this->handler === null ? [] : ['handler' => HandlerStack::create($this->handler)]);
        $client = new DropboxClient($http, new DropboxTokenProvider($http, $credential, $this->redactor, $this->settings), $this->redactor, $this->settings);

        return new FlysystemTarget(new Filesystem(new DropboxAdapter($client, $root)), $this->redactor, $this->hashChunkBytes);
    }
}
