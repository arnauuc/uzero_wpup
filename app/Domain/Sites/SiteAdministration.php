<?php

declare(strict_types=1);

namespace App\Domain\Sites;

use App\Domain\Audit\AuditActor;
use App\Domain\Audit\AuditLogger;
use App\Enums\AuditResult;
use App\Enums\HostingPanel;
use App\Enums\SiteDriverType;
use App\Enums\UpdateStrategy;
use App\Models\HostingAccount;
use App\Models\HostingServer;
use App\Models\Site;
use App\Models\SiteProfile;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits sites and their profiles (UZEROWPUP-25). Shared by the CLI
 * and, later, the web panel. It never receives or stores credentials: keys and
 * passwords are managed through the SecretStore commands, and the pinned host
 * key is set by `site:test`.
 *
 * Plans use database column names so audit entries and summaries line up with
 * the schema. Site columns and profile columns are kept apart.
 */
final readonly class SiteAdministration
{
    /** Canonical names accepted by $clear. */
    public const CLEARABLE = [
        'tags', 'php_binary', 'maintenance_window', 'checkout_product', 'verify_urls', 'mask_selectors',
        'frozen_components', 'excluded_components', 'notification_channels', 'external_backup',
    ];

    private const SITE_NAME = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/';

    private const TAG = '/^[a-z0-9][a-z0-9_-]{0,31}$/';

    private const ABSOLUTE_PATH = '#^/[A-Za-z0-9._/-]*$#';

    private const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$/';

    private const COMPONENT = '/^[A-Za-z0-9][A-Za-z0-9_.:\/-]{0,127}$/';

    private const HOSTNAME = '/^[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/';

    private const SYSTEM_USER = '/^[a-z_][a-z0-9_.-]{0,31}$/';

    public function __construct(private AuditLogger $audit) {}

    /**
     * @return array<string, mixed> Flat summary of what is (or, in dry-run, would be) stored.
     *
     * @throws SiteValidationException
     */
    public function add(AuditActor $actor, string $name, SiteInput $input, HostingPlacement $placement, bool $dryRun = false): array
    {
        $errors = [];
        if (preg_match(self::SITE_NAME, $name) !== 1) {
            $errors[] = "El nom del lloc només pot tenir minúscules, xifres, punts, guions i guions baixos («{$name}»).";
        } elseif (Site::query()->where('name', $name)->exists()) {
            $errors[] = "Ja existeix un lloc anomenat «{$name}».";
        }
        if ($input->url === null) {
            $errors[] = 'Cal la URL del lloc (--url).';
        }
        if ($input->wpPath === null) {
            $errors[] = 'Cal el camí de WordPress al servidor (--wp-path).';
        }

        $server = HostingServer::query()->where('name', $placement->server)->first();
        $account = $server === null ? null : HostingAccount::query()
            ->where('hosting_server_id', $server->id)->where('system_user', $placement->account)->first();
        array_push($errors, ...$this->placementErrors($placement, $server, $account));

        $plan = $this->plan($input, [], null, $input->url, $errors);
        if ($errors !== []) {
            throw new SiteValidationException($errors);
        }

        $summary = ['name' => $name, 'server' => $placement->server, 'account' => $placement->account]
            + $plan['site'] + $plan['profile'];
        $summary['driver'] = ($account !== null ? $account->driver : ($placement->driver ?? SiteDriverType::Ssh))->value;
        $summary = array_map($this->comparable(...), $summary);
        if ($dryRun) {
            return $this->withDefaults($summary);
        }

        $site = DB::transaction(function () use ($placement, $server, $account, $name, $plan): Site {
            $server ??= HostingServer::query()->create([
                'name' => $placement->server,
                'hostname' => (string) $placement->hostname,
                'ssh_port' => $placement->sshPort ?? 22,
                'panel' => $placement->panel ?? HostingPanel::Plesk,
            ]);
            $account ??= HostingAccount::query()->create([
                'hosting_server_id' => $server->id,
                'system_user' => $placement->account,
                'driver' => $placement->driver ?? SiteDriverType::Ssh,
            ]);
            $site = Site::query()->create($plan['site'] + ['name' => $name, 'hosting_account_id' => $account->id]);
            SiteProfile::query()->create($plan['profile'] + ['site_id' => $site->id]);

            return $site;
        });

        $summary = $this->withDefaults($summary);
        $this->audit->record($actor, 'site.add', AuditResult::Success, 'site', $site->id, $summary);

        return ['id' => $site->id] + $summary;
    }

    /**
     * @param  list<string>  $clear  Canonical field names from self::CLEARABLE to reset.
     * @return array<string, array{from: mixed, to: mixed}> Fields that actually change.
     *
     * @throws SiteValidationException
     */
    public function edit(AuditActor $actor, Site $site, SiteInput $input, array $clear = [], bool $dryRun = false): array
    {
        $errors = [];
        $plan = $this->plan($input, $clear, $site, $input->url ?? $site->url, $errors);
        if ($errors !== []) {
            throw new SiteValidationException($errors);
        }

        $profile = $site->profile ?? new SiteProfile($this->profileDefaults());
        $changes = [];
        foreach ([[$plan['site'], $site], [$plan['profile'], $profile]] as [$fields, $model]) {
            foreach ($fields as $column => $to) {
                $from = $this->comparable($model->getAttribute($column));
                if ($from !== $this->comparable($to)) {
                    $changes[$column] = ['from' => $from, 'to' => $this->comparable($to)];
                }
            }
        }

        if ($dryRun || $changes === []) {
            return $changes;
        }

        DB::transaction(function () use ($site, $plan, $changes): void {
            $site->update(array_intersect_key($plan['site'], $changes));
            $profile = $site->profile ?? SiteProfile::query()->create($this->profileDefaults() + ['site_id' => $site->id]);
            $profile->update(array_intersect_key($plan['profile'], $changes));
        });
        $this->audit->record($actor, 'site.edit', AuditResult::Success, 'site', $site->id, [
            'name' => $site->name,
            'changes' => $changes,
        ]);

        return $changes;
    }

    /**
     * Validates the input and returns the columns to write.
     *
     * @param  list<string>  $clear
     * @param  list<string>  $errors  Appended to.
     * @return array{site: array<string, mixed>, profile: array<string, mixed>}
     */
    private function plan(SiteInput $input, array $clear, ?Site $current, ?string $siteUrl, array &$errors): array
    {
        $site = [];
        $profile = [];

        foreach ($clear as $field) {
            if (! in_array($field, self::CLEARABLE, true)) {
                $errors[] = "No es pot esborrar «{$field}».";
            }
        }
        $clears = static fn (string $field): bool => in_array($field, $clear, true);

        $host = $siteUrl === null ? null : parse_url($siteUrl, PHP_URL_HOST);
        if ($input->url !== null) {
            $parts = parse_url($input->url);
            if ($parts === false || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true) || ! isset($parts['host'])
                || isset($parts['user']) || isset($parts['pass'])) {
                $errors[] = 'La URL ha de ser http o https, amb domini i sense usuari ni contrasenya.';
            } else {
                $site['url'] = rtrim($input->url, '/');
            }
        }
        if ($input->wpPath !== null) {
            if ($this->absolutePath($input->wpPath, '--wp-path', $errors)) {
                $site['wp_path'] = rtrim($input->wpPath, '/') ?: '/';
            }
        }
        if ($input->phpBinary !== null) {
            if ($this->absolutePath($input->phpBinary, '--php-binary', $errors)) {
                $site['php_binary'] = $input->phpBinary;
            }
        }
        if ($clears('php_binary')) {
            $this->conflict($input->phpBinary, 'php_binary', $errors);
            $site['php_binary'] = null;
        }
        if ($input->strategy !== null) {
            $site['strategy'] = $input->strategy;
        }
        if ($input->autoUpdate !== null) {
            $site['auto_update'] = $input->autoUpdate;
        }
        if ($input->active !== null) {
            $site['active'] = $input->active;
        }

        $tags = $this->list($input->tags, 'tags', $clears, $errors);
        if ($tags !== null) {
            foreach ($tags as $tag) {
                if (preg_match(self::TAG, $tag) !== 1) {
                    $errors[] = "L'etiqueta «{$tag}» no és vàlida (minúscules, xifres, guions).";
                }
            }
            $site['tags'] = $tags;
        }

        $window = $this->window($input, $clears('maintenance_window'), $current, $errors);
        if ($window !== false) {
            $site['maintenance_window'] = $window;
        }

        $urls = $this->list($input->verifyUrls, 'verify_urls', $clears, $errors);
        if ($urls !== null) {
            foreach ($urls as $url) {
                if (! $this->validVerifyUrl($url, is_string($host) ? $host : null)) {
                    $errors[] = "La URL a verificar «{$url}» ha de ser un camí relatiu (/…) o del mateix domini que el lloc.";
                }
            }
            $profile['verify_urls'] = $urls;
        }
        $masks = $this->list($input->maskSelectors, 'mask_selectors', $clears, $errors);
        if ($masks !== null) {
            $profile['mask_selectors'] = $masks;
        }
        foreach (['frozen_components' => $input->frozen, 'excluded_components' => $input->excluded] as $column => $values) {
            $components = $this->list($values, $column, $clears, $errors);
            if ($components !== null) {
                foreach ($components as $component) {
                    if (preg_match(self::COMPONENT, $component) !== 1) {
                        $errors[] = "El component «{$component}» no és vàlid.";
                    }
                }
                $profile[$column] = $components;
            }
        }
        $channels = $this->list($input->notify, 'notification_channels', $clears, $errors);
        if ($channels !== null) {
            $allowed = $this->configList('notification_channels');
            foreach (array_diff($channels, $allowed) as $channel) {
                $errors[] = "Canal d'avís desconegut «{$channel}» (opcions: ".implode(', ', $allowed).').';
            }
            $profile['notification_channels'] = $channels;
        }

        if ($input->diffThreshold !== null) {
            if (! is_numeric($input->diffThreshold) || (float) $input->diffThreshold < 0 || (float) $input->diffThreshold > 100) {
                $errors[] = 'El llindar de diferència ha de ser un nombre entre 0 i 100.';
            } else {
                $profile['diff_threshold'] = number_format((float) $input->diffThreshold, 2, '.', '');
            }
        }
        if ($input->checkoutProduct !== null) {
            if (trim($input->checkoutProduct) !== '' && strlen($input->checkoutProduct) <= 200) {
                $profile['checkout_product'] = ['product' => trim($input->checkoutProduct)];
            } else {
                $errors[] = 'El producte de prova no és vàlid.';
            }
        }
        if ($clears('checkout_product')) {
            $this->conflict($input->checkoutProduct, 'checkout_product', $errors);
            $profile['checkout_product'] = null;
        }
        if ($input->includeUploads !== null) {
            $profile['include_uploads'] = $input->includeUploads;
        }
        if ($input->externalBackup !== null) {
            $types = $this->configList('external_backup_types');
            if (in_array($input->externalBackup, $types, true)) {
                $profile['external_backup'] = ['type' => $input->externalBackup];
            } else {
                $errors[] = 'Còpia externa desconeguda (opcions: '.implode(', ', $types).').';
            }
        }
        if ($clears('external_backup')) {
            $this->conflict($input->externalBackup, 'external_backup', $errors);
            $profile['external_backup'] = null;
        }

        return ['site' => $site, 'profile' => $profile];
    }

    /**
     * @param  list<string>|null  $values
     * @param  callable(string): bool  $clears
     * @param  list<string>  $errors
     * @return list<string>|null null = leave untouched
     */
    private function list(?array $values, string $column, callable $clears, array &$errors): ?array
    {
        if ($clears($column)) {
            $this->conflict($values, $column, $errors);

            return [];
        }

        return $values === null ? null : array_values(array_unique(array_map('trim', $values)));
    }

    /**
     * @param  list<string>  $errors
     */
    private function conflict(mixed $given, string $column, array &$errors): void
    {
        if ($given !== null) {
            $errors[] = "No es pot indicar un valor i esborrar «{$column}» alhora.";
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private function absolutePath(string $path, string $option, array &$errors): bool
    {
        if (preg_match(self::ABSOLUTE_PATH, $path) !== 1 || str_contains($path, '..')) {
            $errors[] = "{$option} ha de ser un camí absolut sense espais ni «..».";

            return false;
        }

        return true;
    }

    private function validVerifyUrl(string $url, ?string $host): bool
    {
        if (str_starts_with($url, '/')) {
            return ! str_starts_with($url, '//') && ! preg_match('/\s/', $url);
        }
        $parts = parse_url($url);

        return $parts !== false && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            && isset($parts['host']) && ! isset($parts['user']) && ! isset($parts['pass'])
            && $host !== null && strtolower($parts['host']) === strtolower($host);
    }

    /**
     * @param  list<string>  $errors
     * @return array{days: list<int>, start: string, end: string}|null|false false = untouched
     */
    private function window(SiteInput $input, bool $clear, ?Site $current, array &$errors): array|null|false
    {
        $given = $input->windowDays !== null || $input->windowStart !== null || $input->windowEnd !== null;
        if ($clear) {
            $given && $errors[] = 'No es pot indicar una finestra i esborrar-la alhora.';

            return null;
        }
        if (! $given) {
            return false;
        }

        /** @var array{days: list<int>, start: string, end: string} $base */
        $base = $current->maintenance_window ?? config('wpup.sites.default_window');
        $days = $input->windowDays ?? $base['days'];
        $start = $input->windowStart ?? $base['start'];
        $end = $input->windowEnd ?? $base['end'];

        $days = array_values(array_unique($days));
        sort($days);
        if ($days === [] || array_filter($days, static fn (int $d): bool => $d < 1 || $d > 7) !== []) {
            $errors[] = 'Els dies de la finestra han de ser entre 1 (dilluns) i 7 (diumenge).';
        }
        if (preg_match(self::TIME, $start) !== 1 || preg_match(self::TIME, $end) !== 1) {
            $errors[] = "L'inici i el final de la finestra han de tenir el format HH:MM.";
        } elseif ($start === $end) {
            $errors[] = "L'inici i el final de la finestra no poden coincidir.";
        }

        return ['days' => $days, 'start' => $start, 'end' => $end];
    }

    /**
     * @return list<string>
     */
    private function placementErrors(HostingPlacement $placement, ?HostingServer $server, ?HostingAccount $account): array
    {
        $errors = [];
        if ($server === null) {
            if ($placement->hostname === null) {
                $errors[] = "El servidor «{$placement->server}» no existeix: indica --hostname per crear-lo.";
            } elseif (preg_match(self::HOSTNAME, $placement->hostname) !== 1) {
                $errors[] = "El nom de host «{$placement->hostname}» no és vàlid.";
            }
            if ($placement->sshPort !== null && ($placement->sshPort < 1 || $placement->sshPort > 65535)) {
                $errors[] = 'El port SSH ha de ser entre 1 i 65535.';
            }
        } elseif ($placement->hostname !== null || $placement->sshPort !== null || $placement->panel !== null) {
            $errors[] = "El servidor «{$placement->server}» ja existeix: no s'hi poden canviar el host, el port ni el tauler des d'aquí.";
        }
        if (preg_match(self::SYSTEM_USER, $placement->account) !== 1) {
            $errors[] = "L'usuari de sistema «{$placement->account}» no és vàlid.";
        }
        if ($account !== null && $placement->driver !== null && $account->driver !== $placement->driver) {
            $errors[] = "El compte «{$placement->account}» ja existeix amb el driver {$account->driver->value}.";
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    private function withDefaults(array $summary): array
    {
        return $summary + [
            'tags' => [],
            'strategy' => UpdateStrategy::InPlace->value,
            'maintenance_window' => null,
            'auto_update' => false,
            'active' => true,
            'php_binary' => null,
        ] + $this->profileDefaults();
    }

    /**
     * @return array<string, mixed>
     */
    private function profileDefaults(): array
    {
        return [
            'verify_urls' => [], 'mask_selectors' => [], 'diff_threshold' => '0.50', 'checkout_product' => null,
            'frozen_components' => [], 'excluded_components' => [], 'notification_channels' => [],
            'include_uploads' => false, 'external_backup' => null,
        ];
    }

    /**
     * @return list<string>
     */
    private function configList(string $key): array
    {
        /** @var list<string> $values */
        $values = config("wpup.sites.{$key}");

        return $values;
    }

    private function comparable(mixed $value): mixed
    {
        if ($value instanceof UpdateStrategy) {
            return $value->value;
        }
        if (is_array($value) && ! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
