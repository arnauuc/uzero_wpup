<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Audit\AuditActor;
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
    /** Relative segments of letters, digits, ".", "_" and "-", none starting with ".". */
    private const string ROOT_PATTERN = '#^[A-Za-z0-9_-][A-Za-z0-9._-]*(/[A-Za-z0-9_-][A-Za-z0-9._-]*)*$#';

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
        if (! is_string($root) || preg_match(self::ROOT_PATTERN, $root) !== 1) {
            throw new BackupTargetException("La destinació de Dropbox «{$record->name}» necessita a config.root una carpeta relativa dins de la carpeta de l'app.");
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

        $options = ['connect_timeout' => $this->settings->connectTimeoutSeconds, 'timeout' => $this->settings->timeoutSeconds];
        if ($this->handler !== null) {
            $options['handler'] = HandlerStack::create($this->handler);
        }
        $http = new Client($options);
        $client = new DropboxClient($http, new DropboxTokenProvider($http, $credential, $this->redactor, $this->settings->tokenUrl), $this->redactor, $this->settings);

        return new FlysystemTarget(new Filesystem(new DropboxAdapter($client, $root)), $this->redactor, $this->hashChunkBytes);
    }
}
