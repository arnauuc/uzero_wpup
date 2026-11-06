<?php

declare(strict_types=1);

namespace App\Domain\Planning;

use Carbon\CarbonImmutable;
use UnexpectedValueException;

/**
 * Warns about sites that a future release will leave frozen on the branch they
 * have (UZEROWPUP-52, ADR-0007). Pure: it compares the site's versions with the
 * table `wpup.future_minimums`, at the time it is given. A row only warns when
 * the component is older than the row's version and the site does not meet its
 * minimum PHP (or WordPress, for WooCommerce). A version that cannot be read
 * never warns: there is nothing certain to say.
 *
 * @phpstan-type Row array{component: string, label: string, version: string, min_php: ?string, min_wp: ?string, effective: ?string, note: string}
 */
final readonly class FutureMinimums
{
    public const string COMPONENT_WORDPRESS = 'wordpress';

    public const string COMPONENT_WOOCOMMERCE = 'woocommerce';

    /**
     * @param  list<Row>  $rows
     */
    public function __construct(private array $rows) {}

    /**
     * Reads `wpup.future_minimums`; a malformed row is a configuration error, not a silent gap.
     */
    public static function fromConfig(mixed $rows): self
    {
        if (! is_array($rows)) {
            throw new UnexpectedValueException('wpup.future_minimums must be a list.');
        }

        $parsed = [];
        foreach ($rows as $row) {
            $component = is_array($row) ? ($row['component'] ?? null) : null;
            $label = is_array($row) ? ($row['label'] ?? null) : null;
            $version = is_array($row) ? ($row['version'] ?? null) : null;
            $note = is_array($row) ? ($row['note'] ?? null) : null;
            $minPhp = is_array($row) ? ($row['min_php'] ?? null) : null;
            $minWp = is_array($row) ? ($row['min_wp'] ?? null) : null;
            $effective = is_array($row) ? ($row['effective'] ?? null) : null;
            if (! is_string($component) || ! is_string($label) || ! is_string($version) || ! WpVersion::isValid($version) || ! is_string($note)
                || ($minPhp !== null && (! is_string($minPhp) || ! WpVersion::isValid($minPhp)))
                || ($minWp !== null && (! is_string($minWp) || ! WpVersion::isValid($minWp)))
                || ($effective !== null && (! is_string($effective) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $effective) !== 1))) {
                throw new UnexpectedValueException('Malformed row in wpup.future_minimums.');
            }
            $parsed[] = ['component' => $component, 'label' => $label, 'version' => $version, 'min_php' => $minPhp, 'min_wp' => $minWp, 'effective' => $effective, 'note' => $note];
        }

        return new self($parsed);
    }

    /**
     * @param  bool  $phpVerified  false when the PHP is the CLI's, which may not be the web's
     * @return list<string>
     */
    public function warnings(?string $php, bool $phpVerified, ?string $wordpress, ?string $woocommerce, CarbonImmutable $now): array
    {
        $versions = [self::COMPONENT_WORDPRESS => $wordpress, self::COMPONENT_WOOCOMMERCE => $woocommerce];
        $warnings = [];
        foreach ($this->rows as $row) {
            $current = $versions[$row['component']] ?? null;
            if (WpVersion::satisfies($current, $row['version']) !== false) {
                // Already there, not installed, or unreadable.
                continue;
            }

            $unmet = [];
            if ($row['min_php'] !== null && WpVersion::satisfies($php, $row['min_php']) === false) {
                $unmet[] = "exigeix PHP {$row['min_php']} i el lloc té PHP {$php}".($phpVerified ? '' : ' (no verificat pel web: és el del CLI)');
            }
            if ($row['min_wp'] !== null && WpVersion::satisfies($wordpress, $row['min_wp']) === false) {
                $unmet[] = "exigeix WordPress {$row['min_wp']} i el lloc té WordPress {$wordpress}";
            }
            if ($unmet === []) {
                continue;
            }

            $warnings[] = sprintf('%s abans de %s %s%s: %s.', $this->prefix($row, $now), $row['label'], $row['version'], $this->date($row), implode('; ', $unmet));
        }

        return $warnings;
    }

    /**
     * @param  Row  $row
     */
    private function prefix(array $row, CarbonImmutable $now): string
    {
        $effective = $this->effective($row);

        return $effective !== null && $effective->lessThanOrEqualTo($now) ? 'Congelat' : 'Quedarà congelat';
    }

    /**
     * @param  Row  $row
     */
    private function date(array $row): string
    {
        $effective = $this->effective($row);

        return $effective === null ? '' : ' ('.$effective->settings(['locale' => 'ca'])->translatedFormat('F Y').')';
    }

    /**
     * @param  Row  $row
     */
    private function effective(array $row): ?CarbonImmutable
    {
        if ($row['effective'] === null) {
            return null;
        }

        // Validated as YYYY-MM by fromConfig().
        return CarbonImmutable::parse($row['effective'].'-01', 'UTC');
    }
}
