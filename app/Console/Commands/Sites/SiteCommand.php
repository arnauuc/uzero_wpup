<?php

declare(strict_types=1);

namespace App\Console\Commands\Sites;

use App\Console\ChangesState;
use App\Console\WpupCommand;
use App\Domain\Identity\Ability;
use App\Domain\Sites\SiteAdministration;
use App\Domain\Sites\SiteInput;
use App\Domain\Sites\SiteValidationException;
use App\Enums\UpdateStrategy;
use DomainException;

/**
 * Shared option parsing of site:add and site:edit. Only non-secret fields: no
 * option here accepts a credential.
 */
abstract class SiteCommand extends WpupCommand implements ChangesState
{
    protected const SITE_OPTIONS = '
        {--url= : URL pública del lloc (http o https)}
        {--wp-path= : Camí absolut de WordPress al servidor}
        {--php-binary= : Camí absolut del PHP a fer servir per a WP-CLI}
        {--tag=* : Etiqueta (repetible; en editar, substitueix la llista)}
        {--strategy= : in_place o staging_first}
        {--window-days= : Dies de la finestra, 1 (dilluns) a 7: «1-5,7»}
        {--window-start= : Inici de la finestra (HH:MM)}
        {--window-end= : Final de la finestra (HH:MM)}
        {--auto-update : Actualitza automàticament dins de la finestra}
        {--no-auto-update : No actualitzis automàticament}
        {--verify-url=* : Camí o URL a verificar (repetible)}
        {--mask=* : Selector CSS a emmascarar a les captures (repetible)}
        {--diff-threshold= : Llindar de diferència visual, 0–100 (%)}
        {--checkout-product= : Producte de prova del checkout}
        {--freeze=* : Component congelat (repetible)}
        {--exclude=* : Component exclòs (repetible)}
        {--notify=* : Canal d\'avís: email, telegram, slack (repetible)}
        {--include-uploads : Inclou uploads a la còpia}
        {--no-include-uploads : Exclou uploads de la còpia}
        {--external-backup= : Còpia externa: plesk, dropbox, plugin, other, none}
        {--clear=* : Camp a esborrar: tag, php-binary, window, verify-url, mask, checkout-product, freeze, exclude, notify, external-backup}';

    /** CLI names accepted by --clear mapped to the canonical field names. */
    private const CLEAR_MAP = [
        'tag' => 'tags',
        'php-binary' => 'php_binary',
        'window' => 'maintenance_window',
        'verify-url' => 'verify_urls',
        'mask' => 'mask_selectors',
        'checkout-product' => 'checkout_product',
        'freeze' => 'frozen_components',
        'exclude' => 'excluded_components',
        'notify' => 'notification_channels',
        'external-backup' => 'external_backup',
    ];

    protected function ability(): Ability
    {
        return Ability::ManageSites;
    }

    protected function administration(): SiteAdministration
    {
        return app(SiteAdministration::class);
    }

    /**
     * @throws DomainException
     */
    protected function siteInput(?string $url = null, ?string $wpPath = null, ?UpdateStrategy $strategy = null): SiteInput
    {
        $strategyOption = $this->stringOption('strategy');
        $strategy ??= $strategyOption === null ? null : (UpdateStrategy::tryFrom($strategyOption)
            ?? throw new DomainException('L\'estratègia ha de ser in_place o staging_first.'));

        return new SiteInput(
            url: $url ?? $this->stringOption('url'),
            wpPath: $wpPath ?? $this->stringOption('wp-path'),
            phpBinary: $this->stringOption('php-binary'),
            tags: $this->listOption('tag'),
            strategy: $strategy,
            windowDays: $this->parseDays($this->stringOption('window-days')),
            windowStart: $this->stringOption('window-start'),
            windowEnd: $this->stringOption('window-end'),
            autoUpdate: $this->toggle('auto-update', 'no-auto-update'),
            active: $this->hasOption('activate') ? $this->toggle('activate', 'deactivate') : null,
            verifyUrls: $this->listOption('verify-url'),
            maskSelectors: $this->listOption('mask'),
            diffThreshold: $this->stringOption('diff-threshold'),
            checkoutProduct: $this->stringOption('checkout-product'),
            frozen: $this->listOption('freeze'),
            excluded: $this->listOption('exclude'),
            notify: $this->listOption('notify'),
            includeUploads: $this->toggle('include-uploads', 'no-include-uploads'),
            externalBackup: $this->stringOption('external-backup'),
        );
    }

    /**
     * @return list<string>
     *
     * @throws DomainException
     */
    protected function clearFields(): array
    {
        $fields = [];
        foreach ($this->listOption('clear') ?? [] as $name) {
            $fields[] = self::CLEAR_MAP[$name]
                ?? throw new DomainException("No es pot esborrar «{$name}» (opcions: ".implode(', ', array_keys(self::CLEAR_MAP)).').');
        }

        return $fields;
    }

    /**
     * @return list<string>|null null = option not given
     */
    protected function listOption(string $name): ?array
    {
        $value = $this->option($name);
        if (! is_array($value) || $value === []) {
            return null;
        }

        return array_values(array_map(static fn (mixed $item): string => is_string($item) ? $item : '', $value));
    }

    protected function toggle(string $on, string $off): ?bool
    {
        $enable = $this->option($on) === true;
        $disable = $this->option($off) === true;
        if ($enable && $disable) {
            throw new DomainException("--{$on} i --{$off} són incompatibles.");
        }

        return $enable ? true : ($disable ? false : null);
    }

    /**
     * @return list<int>|null
     *
     * @throws DomainException
     */
    private function parseDays(?string $spec): ?array
    {
        if ($spec === null) {
            return null;
        }
        $days = [];
        foreach (explode(',', $spec) as $part) {
            if (preg_match('/^([1-7])(?:-([1-7]))?$/', trim($part), $m) !== 1) {
                throw new DomainException('Els dies de la finestra tenen el format «1-5,7» (1 = dilluns, 7 = diumenge).');
            }
            array_push($days, ...range((int) $m[1], (int) ($m[2] ?? $m[1])));
        }

        return array_values(array_unique($days));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return list<list<string>>
     */
    protected function rows(array $values): array
    {
        $rows = [];
        foreach ($values as $field => $value) {
            $rows[] = [$field, $this->display($value)];
        }

        return $rows;
    }

    protected function display(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'sí' : 'no',
            is_array($value) && array_is_list($value) => $value === [] ? '—' : implode(', ', array_map($this->display(...), $value)),
            is_array($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    protected function reportValidation(DomainException $e): int
    {
        foreach ($e instanceof SiteValidationException ? $e->errors() : [$e->getMessage()] as $error) {
            $this->error($error);
        }

        return self::FAILURE;
    }
}
