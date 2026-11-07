<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditChannel;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Enums\UpdateStrategy;
use Carbon\CarbonImmutable;
use Database\Factories\RunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int|null $site_id
 * @property int|null $parent_run_id
 * @property int|null $user_id
 * @property string $actor
 * @property int|null $hosting_account_id
 * @property AuditChannel $channel
 * @property RunType $type
 * @property UpdateStrategy|null $strategy
 * @property RunStatus $status
 * @property array<string, mixed> $flags
 * @property array<string, mixed>|null $result_summary
 * @property CarbonImmutable $queued_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $deadline_at
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $reviewed_by
 * @property CarbonImmutable|null $reverted_at
 */
class Run extends Model
{
    /** @use HasFactory<RunFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'channel' => AuditChannel::class,
            'type' => RunType::class,
            'strategy' => UpdateStrategy::class,
            'status' => RunStatus::class,
            'flags' => 'array',
            'result_summary' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'deadline_at' => 'immutable_datetime',
            'reviewed_at' => 'immutable_datetime',
            'reverted_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Site, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * @return BelongsTo<HostingAccount, $this>
     */
    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(HostingAccount::class);
    }

    /**
     * Why the run did not succeed: the reason it recorded, or a generic text; null if it succeeded.
     */
    public function failureReason(): ?string
    {
        if ($this->status === RunStatus::Succeeded) {
            return null;
        }
        $reason = $this->result_summary['reason'] ?? null;

        return is_string($reason) ? $reason : "L'execució ha acabat com a {$this->status->value}.";
    }

    /**
     * What the run targets, for listings: its site, its hosting account (key
     * rotation) or the whole fleet.
     */
    public function targetLabel(): string
    {
        if ($this->site !== null) {
            return $this->site->name;
        }

        $account = $this->hostingAccount;

        return $account !== null
            ? "compte {$account->system_user}@".($account->hostingServer->name ?? "#{$account->hosting_server_id}")
            : 'flota';
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'parent_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<RunStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(RunStep::class);
    }

    /**
     * @return HasMany<RunChange, $this>
     */
    public function changes(): HasMany
    {
        return $this->hasMany(RunChange::class);
    }

    /**
     * @return HasMany<Verification, $this>
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(Verification::class);
    }

    /**
     * @return HasOne<Report, $this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(Report::class);
    }

    /**
     * @return HasMany<Backup, $this>
     */
    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }
}
