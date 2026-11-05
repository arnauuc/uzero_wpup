<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\FlysystemTarget;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Backup;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * Restore points for the retention tests (UZEROWPUP-44): the site-local store
 * and one external destination (type sftp) are Flysystem directories of the
 * tool server, created by setUp() and removed by tearDown().
 */
final class RetentionFixture
{
    public const string EXTERNAL = 'extern-retencio';

    private static string $base = '';

    public static function setUp(): void
    {
        self::$base = sys_get_temp_dir().'/wpup-retention-'.bin2hex(random_bytes(6));
        mkdir(self::dir(false), 0700, true);
        mkdir(self::dir(true), 0700, true);
        app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => self::flysystem(self::dir(false)));
        app(BackupTargetRegistry::class)->register(BackupTargetType::Sftp, static fn (): BackupTarget => self::flysystem(self::dir(true)));
    }

    public static function tearDown(): void
    {
        File::deleteDirectory(self::$base);
    }

    public static function dir(bool $external): string
    {
        return self::$base.($external ? '/external' : '/local');
    }

    public static function site(bool $external = false, ?string $name = null): Site
    {
        $site = Site::factory()->create($name === null ? [] : ['name' => $name]);
        app(SiteLocalDestination::class)->attach($site);
        if ($external) {
            $record = BackupTargetRecord::query()->firstOrCreate(['name' => self::EXTERNAL], ['type' => BackupTargetType::Sftp, 'config' => [], 'retention' => [], 'active' => true]);
            $site->backupTargets()->attach($record->id);
        }

        return $site;
    }

    /**
     * An update run of $site, succeeded unless said otherwise.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function run(Site $site, RunStatus $status = RunStatus::Succeeded, array $attributes = []): Run
    {
        return Run::factory()->create(['site_id' => $site->id, 'type' => RunType::Update, 'status' => $status] + $attributes);
    }

    /**
     * A verified restore point of $run made $daysAgo days ago (expiring after
     * the retention), with its files at the site-local store and at every
     * external destination of the site.
     *
     * @return string its key directory
     */
    public static function point(Site $site, ?Run $run, int $daysAgo, bool $protected = false): string
    {
        $created = CarbonImmutable::now()->subDays($daysAgo)->subMinutes(1);
        $directory = "site-{$site->id}/".($run === null ? 'manual-'.$created->format('YmdHis').'-'.bin2hex(random_bytes(3)) : "run{$run->id}");
        $records = [app(SiteLocalDestination::class)->record(), ...$site->externalBackupTargets()->get()->all()];

        foreach ($records as $record) {
            $dir = self::dir($record->type !== BackupTargetType::SiteLocal);
            foreach (BackupKind::cases() as $kind) {
                $key = "{$directory}/{$kind->value}.gz";
                File::ensureDirectoryExists(dirname("{$dir}/{$key}"));
                file_put_contents("{$dir}/{$key}", "{$key} bytes");
                Backup::factory()->create([
                    'site_id' => $site->id,
                    'run_id' => $run?->id,
                    'backup_target_id' => $record->id,
                    'kind' => $kind,
                    'path' => $key,
                    'sha256' => hash('sha256', "{$key} bytes"),
                    'verified_at' => $created,
                    'expires_at' => $created->addDays(config()->integer('wpup.backups.retention_days')),
                    'protected' => $protected,
                    'created_at' => $created,
                ]);
            }
        }

        return $directory;
    }

    /**
     * @return list<string> key directories of the site with live rows, oldest first
     */
    public static function live(Site $site): array
    {
        return array_values(array_unique(Backup::query()->where('site_id', $site->id)->whereNull('deleted_at')->orderBy('created_at')->orderBy('id')->pluck('path')
            ->map(static fn (mixed $path): string => dirname(is_string($path) ? $path : ''))->all()));
    }

    /** Whether the destination still holds files of the point. */
    public static function stored(string $directory, bool $external = false): bool
    {
        $files = glob(self::dir($external)."/{$directory}/*");

        return is_array($files) && $files !== [];
    }

    private static function flysystem(string $dir): FlysystemTarget
    {
        return new FlysystemTarget(new Filesystem(new LocalFilesystemAdapter($dir)), app(SecretRedactor::class), 1_048_576);
    }
}
