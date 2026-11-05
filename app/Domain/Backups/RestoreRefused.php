<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use RuntimeException;

/**
 * A restore (`wpup backup:restore`, UZEROWPUP-46) that must not go ahead: the
 * selection is invalid, a copy is not verified or no longer holds its bytes,
 * or a shop's database would lose data without the operator accepting it.
 * Raised before the site is touched. Messages are for operators (Catalan) and
 * never contain secrets.
 */
final class RestoreRefused extends RuntimeException {}
