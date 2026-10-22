<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\CliCommandGate;
use App\Domain\Identity\OperatorResolver;
use App\Domain\Identity\ProcessIdentity;
use App\Domain\Locks\SiteLockManager;
use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\SystemdCredentialKekProvider;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use App\Support\Logging\RedactSecretsTap;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Foundation\Application;
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
        $this->app->singleton(KekProvider::class, static function (Application $app): KekProvider {
            $provider = config('wpup.secrets.kek_provider');

            return match ($provider) {
                'systemd' => new SystemdCredentialKekProvider(
                    self::stringOrNull(getenv('CREDENTIALS_DIRECTORY')),
                    self::configString('wpup.secrets.credential_prefix'),
                    self::configInt('wpup.secrets.kek_version'),
                ),
                'env' => new EnvKekProvider(
                    self::stringOrNull(config('wpup.secrets.dev_kek')),
                    $app->environment(),
                ),
                default => throw SecretStoreException::unknownProvider(is_string($provider) ? $provider : get_debug_type($provider)),
            };
        });

        $this->app->singleton(SecretStore::class, EnvelopeSecretStore::class);
        $this->app->singleton(SecretRedactor::class);

        $this->app->bind(ProcessIdentity::class, static fn (): ProcessIdentity => ProcessIdentity::current());
        $this->app->bind(OperatorResolver::class, static fn (Application $app): OperatorResolver => new OperatorResolver(
            self::configString('wpup.identity.mode'),
            self::configString('wpup.identity.service_user'),
            $app->environment(),
        ));
        $this->app->singleton(SiteLockManager::class, static function (): SiteLockManager {
            $connection = Redis::connection(self::configString('wpup.locks.connection'));

            if (! $connection instanceof PhpRedisConnection) {
                throw new \UnexpectedValueException('Locks need the phpredis client (REDIS_CLIENT=phpredis).');
            }

            return new SiteLockManager($connection, self::configInt('wpup.locks.ttl_seconds'));
        });
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
