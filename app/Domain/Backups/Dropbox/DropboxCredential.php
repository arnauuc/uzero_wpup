<?php

declare(strict_types=1);

namespace App\Domain\Backups\Dropbox;

use App\Domain\Secrets\Secret;

/**
 * The credential of a Dropbox destination, as stored in the SecretStore:
 * {"app_key": ..., "app_secret": ..., "refresh_token": ...} of a Dropbox app
 * with "App folder" access. Errors never quote the values.
 */
final readonly class DropboxCredential
{
    private const array FIELDS = ['app_key', 'app_secret', 'refresh_token'];

    public function __construct(
        public string $appKey,
        public Secret $appSecret,
        public Secret $refreshToken,
    ) {}

    /**
     * @throws DropboxException
     */
    public static function fromSecret(Secret $secret): self
    {
        $data = json_decode($secret->reveal(), true);
        if (! is_array($data)) {
            throw new DropboxException('La credencial de Dropbox no és un objecte JSON vàlid.');
        }

        $values = [];
        foreach (self::FIELDS as $field) {
            $value = $data[$field] ?? null;
            if (! is_string($value) || trim($value) === '') {
                throw new DropboxException("A la credencial de Dropbox hi falta el camp «{$field}».");
            }
            $values[$field] = $value;
        }

        return new self($values['app_key'], new Secret($values['app_secret']), new Secret($values['refresh_token']));
    }
}
