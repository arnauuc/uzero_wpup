<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Secrets\EnvelopeSecretStore;
use App\Domain\Secrets\Kek\EnvKekProvider;
use App\Domain\Secrets\Kek\KekProvider;
use App\Domain\Secrets\Kek\SystemdCredentialKekProvider;
use App\Domain\Secrets\SecretRedactor;
use App\Domain\Secrets\SecretStore;
use App\Domain\Secrets\SecretStoreException;
use App\Support\Logging\RedactSecretsTap;
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
        $this->app->singleton(SecretRedactor::class);

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
        //
    }
}
