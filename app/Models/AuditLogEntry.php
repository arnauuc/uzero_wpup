<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditChannel;
use App\Enums\AuditResult;
use App\Exceptions\ImmutableRecordException;
use Carbon\CarbonImmutable;
use Database\Factories\AuditLogEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insert-only audit trail (AGENTS.md rule 10). DB-level enforcement is UZEROWPUP-17.
 *
 * @property int $id
 * @property CarbonImmutable $occurred_at
 * @property int|null $user_id
 * @property string $actor
 * @property AuditChannel $channel
 * @property string $action
 * @property string|null $object_type
 * @property string|null $object_id
 * @property string|null $ip
 * @property AuditResult $result
 * @property array<string, mixed> $context
 */
class AuditLogEntry extends Model
{
    /** @use HasFactory<AuditLogEntryFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $table = 'audit_log';

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'channel' => AuditChannel::class,
            'result' => AuditResult::class,
            'context' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @param  array{touch?: bool|null}  $options
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw ImmutableRecordException::forModel(self::class, 'update');
        }

        return parent::save($options);
    }

    public function delete(): never
    {
        throw ImmutableRecordException::forModel(self::class, 'delete');
    }
}
