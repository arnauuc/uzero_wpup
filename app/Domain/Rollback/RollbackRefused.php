<?php

declare(strict_types=1);

namespace App\Domain\Rollback;

use RuntimeException;

/**
 * A rollback (UZEROWPUP-57) that must not go ahead: the run is not an update
 * of this site, or it has no usable restore point. Raised before the site is
 * touched. Messages are for operators (Catalan) and never contain secrets.
 */
final class RollbackRefused extends RuntimeException {}
