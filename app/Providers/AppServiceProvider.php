<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Domain\Credentials\KeyRotationStep;
use App\Domain\Credentials\SecretsRekey;
use App\Domain\Credentials\SecretsRekeyStep;
use App\Domain\Credentials\SshKeyManager;
use App\Domain\Drivers\DriverFactory;
use App\Domain\Drivers\SiteDriver;
use App\Domain\Drivers\Ssh\SshSettings;
use App\Domain\Identity\CliCommandGate;
use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Domain\Inventory\HttpProbe;
use App\Domain\Inventory\InventoryStep;
use App\Domain\Inventory\SiteInventory;
use App\Domain\Locks\SiteLockManager;
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
use App\Domain\Sites\SiteTestStep;
use App\Enums\RunType;
use App\Models\Site;
use App\Support\Logging\RedactSecretsTap;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

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
        $this->app->bind(HttpProbe::class, static fn (Application $app): HttpProbe => new HttpProbe(
            $app->make(HttpFactory::class),
            $app->make(SecretRedactor::class),
            self::configInt('wpup.inventory.http_probe.timeout_seconds'),
            self::configInt('wpup.inventory.http_probe.ttl_seconds'),
            config('wpup.inventory.http_probe.verify_tls') !== false,
            self::configInt('wpup.inventory.http_probe.max_response_bytes'),
            self::configInt('wpup.inventory.http_probe.max_extensions'),
        ));
        $this->app->bind(SiteInventory::class, static fn (Application $app): SiteInventory => new SiteInventory($app->make(HttpProbe::class)));
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
        $this->app->bind(SshSettings::class, static fn (): SshSettings => new SshSettings(
            connectTimeoutSeconds: self::configInt('wpup.ssh.connect_timeout_seconds'),
            commandTimeoutSeconds: self::configInt('wpup.ssh.command_timeout_seconds'),
            transferTimeoutSeconds: self::configInt('wpup.ssh.transfer_timeout_seconds'),
            workDir: self::configString('wpup.ssh.work_dir'),
            wpCliPhar: self::configString('wpup.ssh.wp_cli.phar'),
            wpCliSha256: self::configString('wpup.ssh.wp_cli.sha256'),
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
