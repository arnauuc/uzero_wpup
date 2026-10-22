<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\SystemdCredentialKekProvider;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use Illuminate\Contracts\Foundation\Application;
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
        //
    }
}
