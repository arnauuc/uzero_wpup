<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Artifacts\ArtifactException;
use App\Domain\Artifacts\ArtifactFetcher;
use App\Domain\Artifacts\ArtifactSettings;
use App\Domain\Artifacts\ArtifactStore;
use App\Domain\Artifacts\DnsHostResolver;
use App\Domain\Artifacts\HostResolver;
use App\Domain\Artifacts\PackageArtifacts;
use App\Domain\Artifacts\PackageUrlGuard;
use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupStep;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetException;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\Dropbox\DropboxCredentialVerifier;
use App\Domain\Backups\Dropbox\DropboxSettings;
use App\Domain\Backups\Dropbox\DropboxTargetFactory;
use App\Domain\Backups\FilesBackup;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\PreRestoreBackupStep;
use App\Domain\Backups\RestoreCheckStep;
use App\Domain\Backups\RestoreMaintenanceStep;
use App\Domain\Backups\RestorePointGate;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\RestoreSelection;
use App\Domain\Backups\RestoreStep;
use App\Domain\Backups\ShopWritesReader;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Backups\SiteRestore;
use App\Domain\Compatibility\CompatSettings;
use App\Domain\Compatibility\MemoizedDriver;
use App\Domain\Compatibility\PackageCompatibilityAnalyzer;
use App\Domain\Compatibility\StaticCompatibility;
use App\Domain\Credentials\KeyRotationStep;
use App\Domain\Credentials\SecretsRekey;
use App\Domain\Credentials\SecretsRekeyStep;
use App\Domain\Credentials\SshKeyManager;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\Exceptions\DriverException;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\Ssh\SshSettings;
use App\Domain\Identity\Ability;
use App\Domain\Identity\CliCommandGate;
use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Domain\Inventory\ExternalBackups;
use App\Domain\Inventory\ExternalBackupStatus;
use App\Domain\Inventory\HttpProbe;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\PendingUpdates;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Inventory\SitePrerequisites;
use App\Domain\Inventory\SiteState;
use App\Domain\Inventory\WpCliReader;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Notifications\LogSiteBlockNotifier;
use App\Domain\Notifications\SiteBlockNotifier;
use App\Domain\Planning\ChangeAnalyzer;
use App\Domain\Planning\PackageVersions;
use App\Domain\Planning\PlanStep;
use App\Domain\Planning\UpdatePlanner;
use App\Domain\Rollback\DatabaseRollbackStep;
use App\Domain\Runs\Concurrency\ConcurrencyGate;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunAuthorization;
use App\Domain\Runs\RunClaim;
use App\Domain\Runs\RunEngineSettings;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\ConfigPublicKeyRing;
use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\PublicKeyRing;
use App\Domain\Secrets\Kek\SystemdCredentialKekProvider;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use App\Domain\Sites\SiteBlocking;
use App\Domain\Sites\SiteTestStep;
use App\Domain\ToolBackup\ToolArchiveBuilder;
use App\Domain\ToolBackup\ToolBackupCheckStep;
use App\Domain\ToolBackup\ToolBackupContents;
use App\Domain\ToolBackup\ToolBackupService;
use App\Domain\ToolBackup\ToolBackupSettings;
use App\Domain\ToolBackup\ToolBackupStep;
use App\Domain\ToolBackup\ToolDatabase;
use App\Domain\Vulnerabilities\NoVulnerabilitySource;
use App\Domain\Vulnerabilities\VulnerabilitySource;
use App\Enums\BackupTargetType;
use App\Enums\RunType;
use App\Models\BackupTarget as BackupTargetRecord;
use App\Models\Run;
use App\Models\Site;
use App\Support\Logging\RedactSecretsTap;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Psr\Http\Message\RequestInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ADR-0011: the public half seals, the private half (workers only) opens.
        $this->app->singleton(EnvKekProvider::class, static fn (Application $app): EnvKekProvider => new EnvKekProvider(
            self::stringOrNull(config('wpup.secrets.dev_kek')),
            $app->environment(),
        ));
        $this->app->singleton(PublicKeyRing::class, static function (Application $app): PublicKeyRing {
            return match (self::kekProvider()) {
                'systemd' => new ConfigPublicKeyRing(self::publicKeys(), self::configInt('wpup.secrets.kek_version')),
                'env' => $app->make(EnvKekProvider::class),
            };
        });
        $this->app->singleton(KekProvider::class, static function (Application $app): KekProvider {
            return match (self::kekProvider()) {
                'systemd' => new SystemdCredentialKekProvider(
                    self::stringOrNull(getenv('CREDENTIALS_DIRECTORY')),
                    self::configString('wpup.secrets.credential_prefix'),
                ),
                'env' => $app->make(EnvKekProvider::class),
            };
        });

        $this->app->singleton(EnvelopeSecretStore::class);
        $this->app->singleton(SecretStore::class, EnvelopeSecretStore::class);
        $this->app->singleton(SecretRedactor::class);

        $this->app->bind(ProcessIdentity::class, static fn (): ProcessIdentity => ProcessIdentity::current());
        $this->app->bind(OperatorResolver::class, static fn (Application $app): OperatorResolver => new OperatorResolver(
            self::configString('wpup.identity.mode'),
            self::configString('wpup.identity.service_user'),
            $app->environment(),
        ));
        $this->app->singleton(SiteLockManager::class, static fn (): SiteLockManager => new SiteLockManager(
            self::locksConnection(),
            self::configInt('wpup.locks.ttl_seconds'),
        ));
        $this->app->singleton(RunClaim::class, static fn (): RunClaim => new RunClaim(
            self::locksConnection(),
            self::configInt('wpup.runs.claim_ttl_seconds'),
        ));
        $this->app->singleton(ConcurrencyGate::class, static fn (): ConcurrencyGate => new ConcurrencyGate(
            self::locksConnection(),
            [
                'global' => self::configInt('wpup.concurrency.global'),
                'per_hosting_server' => self::configInt('wpup.concurrency.per_hosting_server'),
                'staging' => self::configInt('wpup.concurrency.staging'),
            ],
            self::configInt('wpup.concurrency.lease_seconds'),
        ));
        $this->app->singleton(PipelineResolver::class);
        $this->app->bind(SiteLocalSettings::class, static fn (): SiteLocalSettings => new SiteLocalSettings(
            self::configString('wpup.backups.site_local.root'),
            self::configInt('wpup.backups.site_local.copy_chunk_bytes'),
            self::configString('wpup.backups.staging_dir'),
            self::configInt('wpup.backups.site_local.canary_timeout_seconds'),
            config('wpup.inventory.http_probe.verify_tls') !== false,
            self::configInt('wpup.backups.site_local.stale_after_seconds'),
        ));
        // Backup destinations register their factory here (UZEROWPUP-37).
        $this->app->singleton(BackupTargetRegistry::class, static function (Application $app): BackupTargetRegistry {
            $registry = new BackupTargetRegistry;
            // The site's own server, through its driver (UZEROWPUP-40).
            $registry->register(BackupTargetType::SiteLocal, static function (BackupTargetRecord $record, Site $site, AuditActor $actor) use ($app): BackupTarget {
                try {
                    $driver = $app->make(DriverFactory::class)->forSite($site, $actor);
                } catch (DriverException $e) {
                    throw new BackupTargetException($app->make(SecretRedactor::class)->redact($e->getMessage()));
                }

                return new SiteLocalTarget($driver, $app->make(SecretRedactor::class), $app->make(HttpFactory::class), $app->make(SiteLocalSettings::class), $site->url);
            });

            // Dropbox, an App folder (UZEROWPUP-107).
            $registry->register(BackupTargetType::Dropbox, static fn (BackupTargetRecord $record, Site $site, AuditActor $actor): BackupTarget => $app->make(DropboxTargetFactory::class)->make($record, $actor));
            // ...also for the tool's own backup (UZEROWPUP-47), which has no site.
            $registry->registerForTool(BackupTargetType::Dropbox, static fn (BackupTargetRecord $record, AuditActor $actor): BackupTarget => $app->make(DropboxTargetFactory::class)->make($record, $actor));

            return $registry;
        });
        $this->app->bind(DropboxSettings::class, static fn (): DropboxSettings => new DropboxSettings(
            self::configString('wpup.backups.dropbox.api_url'),
            self::configString('wpup.backups.dropbox.content_url'),
            self::configString('wpup.backups.dropbox.token_url'),
            self::configInt('wpup.backups.dropbox.chunk_bytes'),
            self::configInt('wpup.backups.dropbox.max_retries'),
            self::configInt('wpup.backups.dropbox.max_retry_after_seconds'),
            self::configInt('wpup.backups.dropbox.read_chunk_bytes'),
            self::configInt('wpup.backups.dropbox.connect_timeout_seconds'),
            self::configInt('wpup.backups.dropbox.timeout_seconds'),
            self::configInt('wpup.backups.dropbox.max_error_bytes'),
            self::configInt('wpup.backups.dropbox.error_summary_chars'),
            self::configInt('wpup.backups.dropbox.max_token_response_bytes'),
            self::configInt('wpup.backups.dropbox.read_timeout_seconds'),
            self::configString('wpup.backups.staging_dir'),
            self::configInt('wpup.backups.dropbox.max_idle_reads'),
        ));
        // Tests bind 'wpup.dropbox.handler' to a fake Dropbox API (a Guzzle handler).
        $this->app->bind(DropboxCredentialVerifier::class, static fn (Application $app): DropboxCredentialVerifier => new DropboxCredentialVerifier(
            $app->make(SecretRedactor::class),
            $app->make(DropboxSettings::class),
            $app->bound('wpup.dropbox.handler') ? self::dropboxHandler($app->make('wpup.dropbox.handler')) : null,
        ));
        $this->app->bind(DropboxTargetFactory::class, static fn (Application $app): DropboxTargetFactory => new DropboxTargetFactory(
            $app->make(SecretStore::class),
            $app->make(SecretRedactor::class),
            $app->make(DropboxSettings::class),
            self::configInt('wpup.backups.hash_chunk_bytes'),
        ));
        // Package artifacts of a run (UZEROWPUP-49).
        $this->app->bind(ArtifactSettings::class, static fn (): ArtifactSettings => new ArtifactSettings(
            self::configString('wpup.artifacts.dir'),
            self::configInt('wpup.artifacts.connect_timeout_seconds'),
            self::configInt('wpup.artifacts.timeout_seconds'),
            self::configInt('wpup.artifacts.read_timeout_seconds'),
            self::configInt('wpup.artifacts.max_bytes'),
            self::configInt('wpup.artifacts.max_unpacked_bytes'),
            self::configInt('wpup.artifacts.max_redirects'),
            self::configInt('wpup.artifacts.chunk_bytes'),
            self::configInt('wpup.artifacts.command_timeout_seconds'),
            self::configString('wpup.artifacts.wordpress_org.plugin_url'),
            self::configString('wpup.artifacts.wordpress_org.theme_url'),
            self::configInt('wpup.artifacts.min_retention_days'),
            array_values(array_filter((array) config('wpup.artifacts.allowed_ports'), is_int(...))),
            array_map(strtolower(...), self::configStrings('wpup.artifacts.url_secrets.query_keys')),
            self::configInt('wpup.artifacts.url_secrets.min_value_length'),
            self::configInt('wpup.artifacts.url_secrets.min_segment_length'),
            self::configString('wpup.artifacts.wordpress_org.core_url'),
            self::configString('wpup.artifacts.wordpress_org.localized_core_url'),
        ));
        $this->app->bind(CompatSettings::class, static fn (): CompatSettings => new CompatSettings(
            self::configString('wpup.static_analysis.work_dir'),
            self::configString('wpup.static_analysis.phpcs_binary'),
            self::configString('wpup.static_analysis.standard_path'),
            self::configInt('wpup.static_analysis.timeout_seconds'),
            self::configString('wpup.static_analysis.memory_limit'),
            self::configInt('wpup.static_analysis.max_output_bytes'),
            self::configInt('wpup.static_analysis.max_files'),
            self::configStrings('wpup.static_analysis.ignore'),
            self::configInt('wpup.static_analysis.summary_max_findings'),
            self::configInt('wpup.static_analysis.summary_max_path_length'),
            self::configInt('wpup.static_analysis.summary_max_message_length'),
            self::configInt('wpup.static_analysis.detail_max_findings'),
            self::configInt('wpup.static_analysis.warnings_max'),
            self::configInt('wpup.artifacts.max_unpacked_bytes'),
            self::configInt('wpup.static_analysis.step_budget_seconds'),
            self::configInt('wpup.static_analysis.min_remaining_seconds'),
        ));
        $this->app->bind(HostResolver::class, DnsHostResolver::class);
        $this->app->bind(ArtifactFetcher::class, static fn (Application $app): ArtifactFetcher => new ArtifactFetcher(
            new GuzzleClient,
            $app->make(ArtifactStore::class),
            $app->make(PackageUrlGuard::class),
            $app->make(ArtifactSettings::class),
        ));
        // The tool's own backup (UZEROWPUP-47, ADR-0009 §3).
        $this->app->bind(ToolBackupSettings::class, static fn (): ToolBackupSettings => new ToolBackupSettings(
            self::configStrings('wpup.tool_backup.age_recipients'),
            self::configStrings('wpup.tool_backup.config_paths'),
            self::configStrings('wpup.tool_backup.report_paths'),
            self::configString('wpup.backups.staging_dir'),
            self::configInt('wpup.tool_backup.dump_timeout_seconds'),
            self::configInt('wpup.tool_backup.archive_timeout_seconds'),
            self::configInt('wpup.tool_backup.encrypt_timeout_seconds'),
            self::configInt('wpup.tool_backup.retention.daily'),
            self::configInt('wpup.tool_backup.retention.monthly'),
            self::configInt('wpup.tool_backup.stale_after_seconds'),
        ));
        $this->app->bind(ToolBackupService::class, static fn (Application $app): ToolBackupService => new ToolBackupService(
            $app->make(ToolArchiveBuilder::class),
            $app->make(ToolBackupContents::class),
            $app->make(ToolBackupSettings::class),
            $app->make(BackupTargetRegistry::class),
            $app->make(AuditLogger::class),
            self::configString('wpup.tool_backup.target'),
        ));
        $this->app->bind(ToolDatabase::class, static fn (Application $app): ToolDatabase => ToolDatabase::fromConnection($app->make(DatabaseManager::class)->connection()));
        $this->app->bind(HttpProbe::class, static fn (Application $app): HttpProbe => new HttpProbe(
            $app->make(HttpFactory::class),
            $app->make(SecretRedactor::class),
            self::configInt('wpup.inventory.http_probe.timeout_seconds'),
            self::configInt('wpup.inventory.http_probe.ttl_seconds'),
            config('wpup.inventory.http_probe.verify_tls') !== false,
            self::configInt('wpup.inventory.http_probe.max_response_bytes'),
            self::configInt('wpup.inventory.http_probe.max_extensions'),
        ));
        $this->app->bind(PackageVersions::class, static fn (): PackageVersions => new PackageVersions(self::configInt('wpup.planning.package_versions_retention_days')));
        // Until UZEROWPUP-128 picks a source, no vulnerability is known (ADR-0005).
        $this->app->bind(VulnerabilitySource::class, NoVulnerabilitySource::class);
        $this->app->bind(UpdatePlanner::class, static fn (Application $app): UpdatePlanner => new UpdatePlanner(
            $app->make(PackageVersions::class),
            $app->make(VulnerabilitySource::class),
            self::configInt('wpup.planning.quarantine_days'),
        ));
        $this->app->bind(PendingUpdates::class, static fn (Application $app): PendingUpdates => new PendingUpdates(
            $app->make(HttpFactory::class),
            $app->make(CacheFactory::class)->store(),
            $app->make(SecretRedactor::class),
            $app->make(WpCliReader::class),
            self::configString('wpup.inventory.wordpress_org.plugins_info_url'),
            self::configString('wpup.inventory.wordpress_org.themes_info_url'),
            self::configInt('wpup.inventory.wordpress_org.timeout_seconds'),
            self::configInt('wpup.inventory.wordpress_org.command_timeout_seconds'),
            self::configInt('wpup.inventory.wordpress_org.max_lookups'),
            self::configInt('wpup.inventory.wordpress_org.stale_after_seconds'),
            self::configInt('wpup.inventory.wordpress_org.cache_ttl_seconds'),
        ));
        $this->app->bind(ShopWritesReader::class, static fn (Application $app): ShopWritesReader => new ShopWritesReader(
            $app->make(WpCliReader::class),
            self::configInt('wpup.backups.watermark.timeout_seconds'),
            array_values(array_filter(config()->array('wpup.backups.watermark.order_types'), is_string(...))),
            self::configInt('wpup.backups.watermark.scan_cap'),
            self::configInt('wpup.backups.watermark.max_listed'),
            self::configInt('wpup.backups.watermark.max_roles'),
            self::configInt('wpup.backups.watermark.margin_seconds'),
            self::configInt('wpup.backups.watermark.error_chars'),
        ));
        $this->app->bind(SiteState::class, static fn (Application $app): SiteState => new SiteState(
            $app->make(WpCliReader::class),
            self::configInt('wpup.inventory.state.command_timeout_seconds'),
            self::configInt('wpup.inventory.state.cron_overdue_after_seconds'),
            self::configInt('wpup.inventory.state.maintenance_ttl_seconds'),
        ));
        $this->app->bind(SitePrerequisites::class, static fn (Application $app): SitePrerequisites => new SitePrerequisites(
            $app->make(WpCliReader::class),
            self::configString('wpup.min_site_php'),
            self::configFloat('wpup.inventory.prerequisites.backup_space_factor'),
            self::configInt('wpup.inventory.prerequisites.command_timeout_seconds'),
            self::configInt('wpup.inventory.prerequisites.du_timeout_seconds'),
        ));
        $this->app->bind(ExternalBackups::class, static function (Application $app): ExternalBackups {
            /** @var array<string, array{name: string, last_backup: array{option: string, time_key: string, success_key: string}|null}> $known */
            $known = config('wpup.inventory.external_backups.plugins');

            return new ExternalBackups(
                $app->make(WpCliReader::class),
                $known,
                self::configInt('wpup.inventory.external_backups.command_timeout_seconds'),
                self::configInt('wpup.inventory.external_backups.max_future_seconds'),
                self::configInt('wpup.inventory.external_backups.max_output_bytes'),
            );
        });
        $this->app->bind(ExternalBackupStatus::class, static fn (): ExternalBackupStatus => new ExternalBackupStatus(self::configInt('wpup.inventory.external_backups.max_age_days')));
        // Until the notification channels exist (F5), blocks only reach the log.
        $this->app->bind(SiteBlockNotifier::class, LogSiteBlockNotifier::class);
        $this->app->bind(SiteInventory::class, static fn (Application $app): SiteInventory => new SiteInventory($app->make(HttpProbe::class), $app->make(PendingUpdates::class), $app->make(SiteState::class), $app->make(SitePrerequisites::class), $app->make(ExternalBackups::class), $app->make(PackageVersions::class)));
        // Credential operations queued by the CLI for the workers (ADR-0011), and the inventory.
        $this->app->afterResolving(PipelineResolver::class, static function (PipelineResolver $resolver, Application $app): void {
            $resolver->register(RunType::SiteTest, static fn (): Pipeline => new Pipeline([
                new SiteTestStep(
                    $app->make(DriverFactory::class),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.credential_operations.site_test_timeout_seconds'),
                ),
            ]));
            $resolver->register(RunType::KeyRotation, static fn (): Pipeline => new Pipeline([
                new KeyRotationStep(
                    $app->make(SshKeyManager::class),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.credential_operations.key_rotation_timeout_seconds'),
                ),
            ]));
            $resolver->register(RunType::SecretsRekey, static fn (): Pipeline => new Pipeline([
                new SecretsRekeyStep(
                    $app->make(SecretsRekey::class),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.credential_operations.secrets_rekey_timeout_seconds'),
                ),
            ]));
            $resolver->register(RunType::Inventory, static fn (): Pipeline => new Pipeline([
                new InventoryStep(
                    $app->make(SiteInventory::class),
                    static fn (Site $site, AuditActor $actor): SiteDriver => $app->make(DriverFactory::class)->forSite($site, $actor),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.inventory.step_timeout_seconds'),
                    $app->make(SiteBlocking::class),
                ),
            ]));
            // A restore point (UZEROWPUP-43) from a fresh inventory: the same first step as every run.
            $resolver->register(RunType::Backup, static fn (): Pipeline => new Pipeline([
                new InventoryStep(
                    $app->make(SiteInventory::class),
                    static fn (Site $site, AuditActor $actor): SiteDriver => $app->make(DriverFactory::class)->forSite($site, $actor),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.inventory.step_timeout_seconds'),
                    $app->make(SiteBlocking::class),
                ),
                new BackupStep(
                    $app->make(RestorePointService::class),
                    static fn (Site $site, AuditActor $actor): SiteDriver => $app->make(DriverFactory::class)->forSite($site, $actor),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.backups.step_timeout_seconds'),
                ),
            ]));
            // `backup:restore` (UZEROWPUP-46): inventory that never blocks nor stops the run (a
            // broken site must be restorable), the copies checked before writing anything,
            // maintenance mode, a verified point of the current state, then the restore.
            $resolver->register(RunType::Restore, static function () use ($app): Pipeline {
                $driverFor = static fn (Site $site, AuditActor $actor): SiteDriver => $app->make(DriverFactory::class)->forSite($site, $actor);
                $maintenance = new RestoreMaintenanceStep($driverFor, $app->make(RunAuthorization::class), $app->make(SecretRedactor::class), self::configInt('wpup.backups.restore.maintenance_timeout_seconds'));

                return new Pipeline([
                    new InventoryStep($app->make(SiteInventory::class), $driverFor, $app->make(RunAuthorization::class), self::configInt('wpup.inventory.step_timeout_seconds'), readOnlySite: true, advisory: true),
                    new RestoreCheckStep($app->make(RestoreSelection::class), $app->make(SiteRestore::class), $app->make(FilesBackup::class), $app->make(NewWritesDetector::class), $driverFor, $app->make(RunAuthorization::class), self::configInt('wpup.backups.restore.check_timeout_seconds')),
                    $maintenance,
                    new PreRestoreBackupStep($app->make(RestorePointService::class), $maintenance, $driverFor, $app->make(RunAuthorization::class), self::configInt('wpup.backups.step_timeout_seconds')),
                    new RestoreStep($app->make(RestoreSelection::class), $app->make(SiteRestore::class), $app->make(RestorePointGate::class), $maintenance, $driverFor,
                        $app->make(RunAuthorization::class), $app->make(AuditLogger::class), $app->make(SecretRedactor::class), self::configInt('wpup.backups.restore.step_timeout_seconds')),
                ]);
            });
            // The tool's own backup (UZEROWPUP-47): a read-only check (also under --dry-run), then the copy.
            $resolver->register(RunType::ToolBackup, static fn (): Pipeline => new Pipeline([
                new ToolBackupCheckStep($app->make(ToolBackupService::class), $app->make(RunAuthorization::class), self::configInt('wpup.tool_backup.check_timeout_seconds')),
                new ToolBackupStep($app->make(ToolBackupService::class), $app->make(RunAuthorization::class), self::configInt('wpup.tool_backup.step_timeout_seconds')),
            ]));
            // The plan reads a fresh inventory without touching the site (no probe, no block change).
            $resolver->register(RunType::Plan, static fn (): Pipeline => new Pipeline([
                new InventoryStep(
                    $app->make(SiteInventory::class),
                    static fn (Site $site, AuditActor $actor): SiteDriver => $app->make(DriverFactory::class)->forSite($site, $actor),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.inventory.step_timeout_seconds'),
                    readOnlySite: true,
                ),
                new PlanStep(
                    $app->make(UpdatePlanner::class),
                    $app->make(RunAuthorization::class),
                    self::configInt('wpup.planning.step_timeout_seconds'),
                    self::configInt('wpup.planning.verified_php_max_age_seconds'),
                    // Static analysis of the new packages (UZEROWPUP-50). The site is only reached,
                    // as whoever launched the run, to read the package URL of premium updates.
                    static function (Run $run, ?string $webPhp) use ($app): ChangeAnalyzer {
                        return new PackageCompatibilityAnalyzer(
                            $run->id,
                            $webPhp,
                            // One driver for the whole plan (see MemoizedDriver).
                            (new MemoizedDriver(static function () use ($app, $run): SiteDriver {
                                $actor = $app->make(RunAuthorization::class)->actorFor($run, Ability::RunOperations)
                                    ?? throw new ArtifactException(RunAuthorization::deniedMessage($run));
                                $site = $run->site ?? throw new ArtifactException("L'execució no té cap lloc.");

                                return $app->make(DriverFactory::class)->forSite($site, $actor);
                            }))(...),
                            $app->make(PackageArtifacts::class),
                            $app->make(StaticCompatibility::class),
                            $app->make(CompatSettings::class),
                            $app->make(SecretRedactor::class),
                        );
                    },
                ),
            ]));
        });
        $this->app->bind(RunEngineSettings::class, static fn (): RunEngineSettings => new RunEngineSettings(
            runTimeoutSeconds: self::configInt('wpup.runs.timeout_seconds'),
            heartbeatSeconds: self::configInt('wpup.runs.heartbeat_seconds'),
            maxStepAttempts: self::configInt('wpup.runs.max_step_attempts'),
            summaryMaxChars: self::configInt('wpup.runs.summary_max_chars'),
            outputMaxBytes: self::configInt('wpup.runs.output_max_bytes'),
        ));
        // The database rollback of an update (UZEROWPUP-59), after the files rollback (UZEROWPUP-57).
        $this->app->bind(DatabaseRollbackStep::class, static fn (Application $app): DatabaseRollbackStep => new DatabaseRollbackStep(
            $app->make(RestoreSelection::class),
            $app->make(SiteRestore::class),
            $app->make(NewWritesDetector::class),
            static fn (Site $site, AuditActor $actor): SiteDriver => $app->make(DriverFactory::class)->forSite($site, $actor),
            $app->make(RunAuthorization::class),
            $app->make(AuditLogger::class),
            $app->make(SecretRedactor::class),
            self::configInt('wpup.backups.restore.rollback_database_timeout_seconds'),
        ));
        $this->app->bind(SshSettings::class, static fn (): SshSettings => new SshSettings(
            connectTimeoutSeconds: self::configInt('wpup.ssh.connect_timeout_seconds'),
            commandTimeoutSeconds: self::configInt('wpup.ssh.command_timeout_seconds'),
            transferTimeoutSeconds: self::configInt('wpup.ssh.transfer_timeout_seconds'),
            workDir: self::configString('wpup.ssh.work_dir'),
            wpCliPhar: self::configString('wpup.ssh.wp_cli.phar'),
            wpCliSha256: self::configString('wpup.ssh.wp_cli.sha256'),
            stagingDir: self::configString('wpup.backups.staging_dir'),
        ));
        $this->app->bind(SshKeyManager::class, static fn (Application $app): SshKeyManager => new SshKeyManager(
            $app->make(SecretStore::class),
            $app->make(AuditLogger::class),
            $app->make(SshSettings::class),
            self::stringOrNull(config('wpup.ssh.egress_ip')),
        ));
        $this->app->singleton(CliCommandGate::class, static function (): CliCommandGate {
            $allowed = config('wpup.cli.allowed_commands');

            return new CliCommandGate(is_array($allowed) ? array_values(array_filter($allowed, is_string(...))) : []);
        });

        $this->redactAllLogChannels();
    }

    /**
     * @return Closure(RequestInterface, array<mixed>): PromiseInterface
     */
    private static function dropboxHandler(mixed $handler): Closure
    {
        if (! is_callable($handler)) {
            throw new LogicException('wpup.dropbox.handler must be a Guzzle handler.');
        }

        return static function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $promise = $handler($request, $options);

            return $promise instanceof PromiseInterface ? $promise : throw new LogicException('wpup.dropbox.handler must return a promise.');
        };
    }

    /**
     * Adds the secret-redaction tap to every configured log channel, so none can
     * bypass it (AGENTS.md rule 2).
     */
    private function redactAllLogChannels(): void
    {
        $channels = config('logging.channels');

        if (! is_array($channels)) {
            return;
        }

        foreach ($channels as $name => $channel) {
            if (! is_array($channel)) {
                continue;
            }

            $taps = isset($channel['tap']) && is_array($channel['tap']) ? $channel['tap'] : [];

            if (! in_array(RedactSecretsTap::class, $taps, true)) {
                $taps[] = RedactSecretsTap::class;
            }

            config(["logging.channels.{$name}.tap" => $taps]);
        }
    }

    private static function locksConnection(): PhpRedisConnection
    {
        $connection = Redis::connection(self::configString('wpup.locks.connection'));

        return $connection instanceof PhpRedisConnection
            ? $connection
            : throw new \UnexpectedValueException('Locks need the phpredis client (REDIS_CLIENT=phpredis).');
    }

    /**
     * @return 'systemd'|'env'
     */
    private static function kekProvider(): string
    {
        $provider = config('wpup.secrets.kek_provider');

        return match ($provider) {
            'systemd', 'env' => $provider,
            default => throw SecretStoreException::unknownProvider(is_string($provider) ? $provider : get_debug_type($provider)),
        };
    }

    /**
     * @return array<int, string> version => base64 public key
     */
    private static function publicKeys(): array
    {
        $keys = [];
        $configured = config('wpup.secrets.public_keys');

        foreach (is_array($configured) ? $configured : [] as $version => $key) {
            if (is_int($version) && is_string($key)) {
                $keys[$version] = $key;
            }
        }

        return $keys;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function configString(string $key): string
    {
        $value = config($key);

        return is_string($value) ? $value : throw new \UnexpectedValueException($key.' must be a string.');
    }

    private static function configFloat(string $key): float
    {
        $value = config($key);

        return is_int($value) || is_float($value) ? (float) $value : throw new \UnexpectedValueException($key.' must be a number.');
    }

    /**
     * @return list<string>
     */
    private static function configStrings(string $key): array
    {
        $value = config($key);

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : throw new \UnexpectedValueException($key.' must be a list of strings.');
    }

    private static function configInt(string $key): int
    {
        $value = config($key);

        return is_int($value) ? $value : throw new \UnexpectedValueException($key.' must be an integer.');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ADR-0007: the minimum PHP of the fleet cannot be configured below 7.4.
        SitePrerequisites::assertMinimumPhp(config('wpup.min_site_php'));

        // Second line of defence behind the `wpup` bash wrapper: when invoked through
        // it (WPUP_CLI=1), only Uzero wpup commands may run (ADR-0002).
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (getenv('WPUP_CLI') !== '1') {
                return;
            }

            if (! $this->app->make(CliCommandGate::class)->allows($event->command)) {
                throw new \RuntimeException(sprintf('wpup: comanda no permesa: %s', (string) $event->command));
            }
        });
    }
}
