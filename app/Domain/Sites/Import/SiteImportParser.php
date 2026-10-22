<?php

declare(strict_types=1);

namespace App\Domain\Sites\Import;

use App\Domain\Sites\SiteInput;
use App\Domain\Sites\WindowDays;
use App\Enums\HostingPanel;
use App\Enums\SiteDriverType;
use App\Enums\UpdateStrategy;
use BackedEnum;
use DomainException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads a CSV or YAML import file into typed entries (UZEROWPUP-26). Both
 * formats are flattened to the same column names, so one reader serves both.
 * It only checks the shape of the file; the field rules live in
 * SiteAdministration. Credentials are never accepted: any key that looks like
 * a secret is an error, and so is any unknown key.
 *
 * A field that is absent stays untouched; a clearable field that is present
 * but empty is cleared; an empty value in any other field is ignored.
 */
final class SiteImportParser
{
    /** Flat column names, also the CSV header. */
    public const COLUMNS = [
        'name', 'url', 'wp_path', 'php_binary', 'tags', 'strategy', 'window_days', 'window_start', 'window_end',
        'auto_update', 'verify_urls', 'mask', 'diff_threshold', 'checkout_product', 'freeze', 'exclude', 'notify',
        'include_uploads', 'external_backup', 'server', 'hostname', 'ssh_port', 'panel', 'account', 'driver',
    ];

    /** Nested YAML keys mapped to flat columns. */
    private const NESTED = [
        'window' => ['days' => 'window_days', 'start' => 'window_start', 'end' => 'window_end'],
        'server' => ['name' => 'server', 'hostname' => 'hostname', 'ssh_port' => 'ssh_port', 'panel' => 'panel'],
        'account' => ['system_user' => 'account', 'driver' => 'driver'],
    ];

    /** Clearable columns mapped to the canonical column of SiteAdministration. */
    private const CLEARABLE = [
        'tags' => 'tags', 'verify_urls' => 'verify_urls', 'mask' => 'mask_selectors', 'freeze' => 'frozen_components',
        'exclude' => 'excluded_components', 'notify' => 'notification_channels', 'php_binary' => 'php_binary',
        'checkout_product' => 'checkout_product', 'external_backup' => 'external_backup',
    ];

    private const SECRET_KEY = '/pass|pwd|secret|token|key|private|credential|auth/i';

    public function parse(string $content, ImportFormat $format): ParsedImport
    {
        $errors = [];
        $records = $format === ImportFormat::Csv ? $this->csvRecords($content, $errors) : $this->yamlRecords($content, $errors);

        $entries = [];
        foreach ($records as $index => $record) {
            $entry = $this->entry($index + 1, $record, $errors);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }
        if ($records === [] && $errors === []) {
            $errors[] = 'El fitxer no conté cap lloc.';
        }

        return new ParsedImport($entries, $errors);
    }

    /**
     * @param  list<string>  $errors
     * @return list<array<string, mixed>>
     */
    private function csvRecords(string $content, array &$errors): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        $firstLine = strtok($content, "\n");
        $delimiter = substr_count((string) $firstLine, ';') > substr_count((string) $firstLine, ',') ? ';' : ',';

        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            throw new DomainException('No es pot obrir la memòria temporal.');
        }
        fwrite($stream, $content);
        rewind($stream);

        $header = fgetcsv($stream, null, $delimiter, '"', '');
        if ($header === false || $header === [null]) {
            fclose($stream);
            $errors[] = 'El CSV és buit: falta la capçalera.';

            return [];
        }
        $header = array_map(static fn (?string $name): string => strtolower(trim((string) $name)), $header);
        if (count(array_unique($header)) !== count($header)) {
            $errors[] = 'La capçalera del CSV té columnes repetides.';
        }

        $records = [];
        while (($row = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $position = count($records) + 1;
            if (count($row) !== count($header)) {
                $errors[] = "Lloc {$position}: té ".count($row).' columnes i la capçalera en té '.count($header).'.';
                $records[] = [];

                continue;
            }
            $records[] = array_combine($header, array_map(static fn (?string $cell): string => (string) $cell, $row));
        }
        fclose($stream);

        return $records;
    }

    /**
     * @param  list<string>  $errors
     * @return list<array<string, mixed>>
     */
    private function yamlRecords(string $content, array &$errors): array
    {
        try {
            $data = Yaml::parse($content);
        } catch (ParseException $e) {
            $errors[] = 'El YAML no és vàlid: '.$e->getMessage();

            return [];
        }
        if (is_array($data) && ! array_is_list($data) && array_keys($data) === ['sites']) {
            $data = $data['sites'];
        }
        if (! is_array($data) || ! array_is_list($data)) {
            $errors[] = 'El YAML ha de ser una llista de llocs (o una clau «sites» amb la llista).';

            return [];
        }

        $records = [];
        foreach ($data as $index => $item) {
            if (! is_array($item) || array_is_list($item)) {
                $errors[] = 'Lloc '.($index + 1).': ha de ser un objecte amb camps.';
                $records[] = [];

                continue;
            }
            $records[] = $this->flatten($item, $errors, $index + 1);
        }

        return $records;
    }

    /**
     * @param  array<mixed>  $item
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    private function flatten(array $item, array &$errors, int $position): array
    {
        $flat = [];
        foreach ($item as $key => $value) {
            $key = (string) $key;
            if (isset(self::NESTED[$key]) && ($value === null || is_array($value) && ! array_is_list($value))) {
                foreach (is_array($value) ? $value : ['days' => null] as $sub => $subValue) {
                    $column = self::NESTED[$key][(string) $sub] ?? null;
                    if ($column === null) {
                        $errors[] = "Lloc {$position}: ".$this->unknownKey("{$key}.{$sub}");
                    } else {
                        $flat[$column] = $subValue;
                    }
                }
            } else {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    private function unknownKey(string $key): string
    {
        return preg_match(self::SECRET_KEY, $key) === 1
            ? "«{$key}» sembla una credencial: els fitxers d'importació no poden contenir credencials (se'n gestionen a part, amb ssh-key:* i secrets:*)."
            : "la clau «{$key}» no és coneguda.";
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $errors
     */
    private function entry(int $position, array $record, array &$errors): ?SiteImportEntry
    {
        $local = [];
        foreach (array_keys($record) as $key) {
            if (! in_array($key, self::COLUMNS, true)) {
                $local[] = $this->unknownKey($key);
            }
        }

        $name = $this->text($record, 'name', $local);
        $label = $name === null ? "Lloc {$position}" : "Lloc {$position} («{$name}»)";
        if ($name === null && $record !== []) {
            $local[] = 'falta el nom del lloc (name).';
        }

        $strategy = $this->enum($record, 'strategy', UpdateStrategy::class, $local);
        $panel = $this->enum($record, 'panel', HostingPanel::class, $local);
        $driver = $this->enum($record, 'driver', SiteDriverType::class, $local);

        $port = $this->text($record, 'ssh_port', $local);
        if ($port !== null && ! ctype_digit($port)) {
            $local[] = 'ssh_port ha de ser un nombre.';
            $port = null;
        }

        $days = $record['window_days'] ?? null;
        $days = is_array($days) ? implode(',', array_map(static fn (mixed $d): string => is_scalar($d) ? (string) $d : '', $days)) : $days;
        $windowDays = null;
        if (is_int($days) || is_string($days) && trim($days) !== '') {
            try {
                $windowDays = WindowDays::parse((string) $days);
            } catch (DomainException $e) {
                $local[] = $e->getMessage();
            }
        }
        $windowGiven = array_values(array_filter(['window_days', 'window_start', 'window_end'], static fn (string $k): bool => array_key_exists($k, $record)));
        $windowClear = $windowGiven !== [] && array_filter($windowGiven, fn (string $k): bool => ! $this->blank($record[$k])) === [];

        $clear = [];
        foreach (self::CLEARABLE as $column => $canonical) {
            if (array_key_exists($column, $record) && $this->blank($record[$column])) {
                $clear[] = $canonical;
            }
        }
        if ($windowClear) {
            $clear[] = 'maintenance_window';
        }

        $input = new SiteInput(
            url: $this->text($record, 'url', $local),
            wpPath: $this->text($record, 'wp_path', $local),
            phpBinary: $this->text($record, 'php_binary', $local),
            tags: $this->items($record, 'tags', $local),
            strategy: $strategy,
            windowDays: $windowDays,
            windowStart: $this->text($record, 'window_start', $local),
            windowEnd: $this->text($record, 'window_end', $local),
            autoUpdate: $this->bool($record, 'auto_update', $local),
            verifyUrls: $this->items($record, 'verify_urls', $local),
            maskSelectors: $this->items($record, 'mask', $local),
            diffThreshold: $this->text($record, 'diff_threshold', $local),
            checkoutProduct: $this->text($record, 'checkout_product', $local),
            frozen: $this->items($record, 'freeze', $local),
            excluded: $this->items($record, 'exclude', $local),
            notify: $this->items($record, 'notify', $local),
            includeUploads: $this->bool($record, 'include_uploads', $local),
            externalBackup: $this->text($record, 'external_backup', $local),
        );

        foreach ($local as $message) {
            $errors[] = "{$label}: {$message}";
        }
        if ($name === null || $local !== []) {
            return null;
        }

        $fields = array_values(array_filter(
            array_keys($record),
            fn (string $key): bool => $key !== 'name' && (! $this->blank($record[$key]) || in_array($key, array_keys(self::CLEARABLE), true)),
        ));

        return new SiteImportEntry(
            $position, $name, $input, $clear, $fields,
            $this->text($record, 'server', $local), $this->text($record, 'hostname', $local),
            $port === null ? null : (int) $port, $panel, $this->text($record, 'account', $local), $driver,
        );
    }

    private function blank(mixed $value): bool
    {
        return $value === null || $value === [] || (is_string($value) && trim($value) === '');
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $errors
     */
    private function text(array $record, string $key, array &$errors): ?string
    {
        $value = $record[$key] ?? null;
        if ($this->blank($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_string($value)) {
            return trim($value);
        }
        $errors[] = "«{$key}» ha de ser un text.";

        return null;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  array<string, mixed>  $record
     * @param  class-string<T>  $enum
     * @param  list<string>  $errors
     * @return T|null
     */
    private function enum(array $record, string $key, string $enum, array &$errors): ?BackedEnum
    {
        $value = $this->text($record, $key, $errors);
        if ($value === null) {
            return null;
        }

        return $enum::tryFrom($value) ?? $this->invalid($key, $value, $errors);
    }

    /**
     * @param  list<string>  $errors
     */
    private function invalid(string $key, string $value, array &$errors): null
    {
        $errors[] = "valor no vàlid per a «{$key}»: «{$value}».";

        return null;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $errors
     * @return list<string>|null null = absent or empty (the caller decides whether that clears)
     */
    private function items(array $record, string $key, array &$errors): ?array
    {
        $value = $record[$key] ?? null;
        if ($this->blank($value)) {
            return null;
        }
        $parts = is_string($value) ? explode('|', $value) : $value;
        if (! is_array($parts) || ! array_is_list($parts)) {
            $errors[] = "«{$key}» ha de ser una llista.";

            return null;
        }
        $items = [];
        foreach ($parts as $part) {
            if (! is_scalar($part)) {
                $errors[] = "«{$key}» només pot contenir textos.";

                return null;
            }
            if (trim((string) $part) !== '') {
                $items[] = trim((string) $part);
            }
        }

        return $items === [] ? null : $items;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $errors
     */
    private function bool(array $record, string $key, array &$errors): ?bool
    {
        $value = $record[$key] ?? null;
        if ($this->blank($value)) {
            return null;
        }
        $text = is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? strtolower(trim((string) $value)) : '');

        return match ($text) {
            'true', 'yes', 'y', 'si', 'sí', 's', '1' => true,
            'false', 'no', 'n', '0' => false,
            default => $this->invalidBool($key, $errors),
        };
    }

    /**
     * @param  list<string>  $errors
     */
    private function invalidBool(string $key, array &$errors): null
    {
        $errors[] = "«{$key}» ha de ser sí o no (true/false, yes/no, 1/0).";

        return null;
    }
}
