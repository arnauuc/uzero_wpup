<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\AuditResult;
use App\Enums\BackupTargetType;
use App\Models\Backup;
use App\Models\BackupTarget;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Creates or updates a Dropbox destination together with its credential:
 * validates, checks the credential against Dropbox, and then writes the secret,
 * the row and the audit record in one transaction.
 */
final readonly class DropboxTargetSetup
{
    public const string SECRET_PURPOSE = 'dropbox-credential';

    private const string NAME_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    public function __construct(
        private SecretStore $secrets,
        private AuditLogger $audit,
        private DropboxCredentialVerifier $verifier,
    ) {}

    /**
     * Checks everything that does not need the credential. Returns the existing
     * destination, if any.
     *
     * @throws DropboxSetupException
     */
    public function check(string $name, string $root): ?BackupTarget
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new DropboxSetupException('El nom ha de tenir lletres, xifres, «.», «_» o «-» (màxim 128 caràcters).');
        }
        if (! DropboxTargetFactory::isValidRoot($root)) {
            throw new DropboxSetupException('Cal --root amb una carpeta relativa en minúscules (lletres, xifres, «.», «_», «-» separades per «/»).');
        }

        $existing = BackupTarget::query()->where('name', $name)->first();
        if ($existing !== null) {
            $this->checkExisting($existing, $root);
        }

        return $existing;
    }

    /**
     * @param  array{app_key: string, app_secret: string, refresh_token: string}  $values
     *
     * @throws DropboxSetupException
     */
    public function apply(string $name, string $root, array $values, AuditActor $actor): DropboxSetupResult
    {
        $this->check($name, $root);

        try {
            $raw = new Secret(json_encode($values, JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            throw new DropboxSetupException('Les credencials contenen caràcters que no són UTF-8 vàlid. No s\'ha desat res.');
        }

        try {
            try {
                $credential = DropboxCredential::fromSecret($raw);
            } catch (DropboxException $e) {
                throw new DropboxSetupException($e->getMessage().' No s\'ha desat res.');
            }

            // The normalized values (trimmed), which are what the destination will read.
            $secret = new Secret(json_encode([
                'app_key' => $credential->appKey,
                'app_secret' => $credential->appSecret->reveal(),
                'refresh_token' => $credential->refreshToken->reveal(),
            ], JSON_THROW_ON_ERROR));

            try {
                $this->verifier->verify($credential);
            } catch (DropboxException $e) {
                $secret->wipe();

                throw new DropboxSetupException("Dropbox no accepta les credencials: {$e->getMessage()} No s'ha desat res.");
            } finally {
                $credential->appSecret->wipe();
                $credential->refreshToken->wipe();
            }

            try {
                return $this->store($name, $root, $secret, $actor);
            } finally {
                $secret->wipe();
            }
        } finally {
            $raw->wipe();
        }
    }

    private function store(string $name, string $root, Secret $secret, AuditActor $actor): DropboxSetupResult
    {
        try {
            return DB::transaction(function () use ($name, $root, $secret, $actor): DropboxSetupResult {
                $existing = BackupTarget::query()->where('name', $name)->lockForUpdate()->first();
                if ($existing !== null) {
                    $this->checkExisting($existing, $root);
                }

                $previousRoot = $existing === null ? null : $this->rootOf($existing);
                $recreated = false;

                if ($existing === null) {
                    $target = BackupTarget::query()->create([
                        'name' => $name,
                        'type' => BackupTargetType::Dropbox,
                        'config' => ['root' => $root],
                        'credential_ref' => $this->secrets->put(self::SECRET_PURPOSE, $secret, $actor),
                        'retention' => [],
                        'active' => true,
                    ]);
                } else {
                    $target = $existing;
                    $ref = $existing->credential_ref;
                    if ($ref === null || $ref === '' || $this->secrets->purpose($ref) === null) {
                        $ref = $this->secrets->put(self::SECRET_PURPOSE, $secret, $actor);
                        $recreated = true;
                    } else {
                        $this->secrets->replace($ref, $secret, $actor);
                    }
                    $existing->forceFill(['config' => [...$existing->config, 'root' => $root], 'credential_ref' => $ref])->save();
                }

                $this->audit->record($actor, 'backup_target.dropbox.set', AuditResult::Success, 'backup_target', (string) $target->id, [
                    'name' => $name,
                    'root' => $root,
                    'previous_root' => $previousRoot,
                    'created' => $existing === null,
                    'secret_recreated' => $recreated,
                ]);

                return new DropboxSetupResult($target->id, $existing === null, $previousRoot);
            });
        } catch (UniqueConstraintViolationException) {
            throw new DropboxSetupException("Algú altre ha creat una destinació «{$name}» al mateix temps. Torna-ho a provar.");
        }
    }

    /**
     * @throws DropboxSetupException
     */
    private function checkExisting(BackupTarget $existing, string $root): void
    {
        if ($existing->type !== BackupTargetType::Dropbox) {
            throw new DropboxSetupException("Ja hi ha una destinació «{$existing->name}» d'un altre tipus; tria un altre nom.");
        }

        $ref = $existing->credential_ref;
        if ($ref !== null && $ref !== '') {
            $purpose = $this->secrets->purpose($ref);
            if ($purpose !== null && $purpose !== self::SECRET_PURPOSE) {
                throw new DropboxSetupException("La credencial de «{$existing->name}» no és d'una destinació de Dropbox; no es toca.");
            }
        }

        if ($this->rootOf($existing) !== $root
            && Backup::query()->where('backup_target_id', $existing->id)->whereNull('deleted_at')->exists()) {
            throw new DropboxSetupException("«{$existing->name}» ja conté còpies: canviar-ne la carpeta les deixaria orfes. Crea una destinació amb un altre nom.");
        }
    }

    private function rootOf(BackupTarget $target): ?string
    {
        $root = $target->config['root'] ?? null;

        return is_string($root) ? $root : null;
    }
}
