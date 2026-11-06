<?php

declare(strict_types=1);

namespace App\Domain\Updates;

use App\Domain\Planning\VersionChange;
use App\Enums\ChangeResult;
use App\Enums\ComponentType;
use App\Models\Run;
use Illuminate\Support\Facades\DB;

/**
 * Writes what an update step did to run_changes (UZEROWPUP-54): one row per
 * component, with the version before and after. A retried step replaces only
 * its own row for that component, so it never duplicates one. Values from the
 * site are cut to the column size and the detail is already redacted.
 */
final class UpdateChanges
{
    /** run_changes.step of the component updates (the plan's rows are step = plan). */
    public const string STEP = 'update';

    /** Size of the string columns of run_changes. */
    private const int COLUMN_LENGTH = 255;

    public function record(Run $run, string $step, ComponentType $type, string $slug, ?string $before, ?string $after, ChangeResult $result, ?string $detail = null): void
    {
        $slug = self::column($slug);
        DB::transaction(static function () use ($run, $step, $type, $slug, $before, $after, $result, $detail): void {
            $run->changes()->where(['step' => $step, 'component_type' => $type->value, 'slug' => $slug])->delete();
            $run->changes()->create([
                'step' => $step,
                'component_type' => $type,
                'slug' => $slug,
                'version_before' => $before === null ? null : self::column($before),
                'version_after' => $after === null ? null : self::column($after),
                'result' => $result,
                'reason_detail' => $detail,
                'is_major' => $before !== null && $after !== null && VersionChange::isMajor($type, $before, $after),
            ]);
        });
    }

    /** Fits a site-provided value in a varchar(255) column (valid UTF-8, at most 255 bytes). */
    private static function column(string $value): string
    {
        return mb_strcut(mb_scrub($value, 'UTF-8'), 0, self::COLUMN_LENGTH, 'UTF-8');
    }
}
