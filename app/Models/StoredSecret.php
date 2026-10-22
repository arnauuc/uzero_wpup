<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Encrypted row of the secret store. Only App\Domain\Secrets reads or writes it;
 * the cryptographic columns are hidden from serialisation.
 *
 * @property string $id
 * @property string $purpose
 * @property string $ciphertext
 * @property string $nonce
 * @property string $wrapped_dek
 * @property string $dek_nonce
 * @property int $kek_version
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
class StoredSecret extends Model
{
    use HasUuids;

    protected $table = 'secrets';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['ciphertext', 'nonce', 'wrapped_dek', 'dek_nonce'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kek_version' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
