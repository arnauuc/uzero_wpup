<?php

declare(strict_types=1);

namespace Tests\Support\Runs;

use App\Domain\Drivers\Data\AppliedUpdate;
use App\Domain\Drivers\Data\ComponentVersion;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Data\UpdatePackage;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use RuntimeException;
use Tests\Support\FakeDriver;

/**
 * The FakeDriver site of the update crash tests (tests/Process, UZEROWPUP-62),
 * shared by the test and its worker processes through a state file. The worker
 * kills itself (SIGKILL) the first time it reaches $crashAt, halfway through
 * the operation, after writing the site's state as it is at that moment: what
 * a real site would keep when the tool dies. A worker that ends normally
 * saves it too (save()).
 *
 * Crash points: "archiveFiles" (the database dump made, the code archive just
 * written), "applyUpdate:<slug>" (the new version's header in place, the rest
 * of the files not), "restoreFiles" (the mirror started, the old files not
 * back yet; `.maintenance` is kept, as SiteDriver::restoreFiles() promises)
 * and "smoke" (the smoke check's first request).
 */
final class CrashingDriver extends FakeDriver
{
    public ?string $crashAt = null;

    /** @var list<string> the crash points already reached */
    public array $crashed = [];

    /** An update of this slug fails, so the run rolls back. */
    public ?string $failOn = null;

    /** @var list<string> the maintenance mode as each restore starts ("files on", "database off"…) */
    public array $maintenanceAtRestore = [];

    private string $stateFile = '';

    public static function load(string $stateFile): self
    {
        $driver = unserialize((string) file_get_contents($stateFile));
        if (! $driver instanceof self) {
            throw new RuntimeException("No driver state in {$stateFile}.");
        }
        $driver->stateFile = $stateFile;

        return $driver;
    }

    public function saveTo(string $stateFile): void
    {
        $this->stateFile = $stateFile;
        $this->save();
    }

    public function save(): void
    {
        file_put_contents($this->stateFile, serialize($this));
    }

    /** Dies here the first time, as the site is now. */
    public function crash(string $point): void
    {
        if ($this->crashAt !== $point || in_array($point, $this->crashed, true)) {
            return;
        }
        $this->crashed[] = $point;
        $this->save();
        posix_kill((int) getmypid(), SIGKILL);
        sleep(10);
    }

    public function archiveFiles(string $remotePath, array $excludes): RemoteFile
    {
        $archive = parent::archiveFiles($remotePath, $excludes);
        $this->crash('archiveFiles');

        return $archive;
    }

    public function applyUpdate(UpdatePackage $package): AppliedUpdate
    {
        if ($package->slug === $this->failOn) {
            $this->writeCalls[] = 'applyUpdate';

            throw new RemoteCommandFailed("Error: no s'ha pogut descomprimir {$package->slug}");
        }
        $key = "{$package->type->value}:{$package->slug}";
        $point = "applyUpdate:{$package->slug}";
        if ($this->crashAt === $point && ! in_array($point, $this->crashed, true)) {
            // Half installed: the main file already says the new version.
            $this->addComponent(new ComponentVersion($package->type, $package->slug, $package->targetVersion, $this->components[$key]->active ?? false));
            $this->crash($point);
        }

        return parent::applyUpdate($package);
    }

    public function restoreFiles(RemoteFile $archive): void
    {
        // The interrupted mirror is recorded too: the shop must be closed from its start.
        $this->maintenanceAtRestore[] = 'files '.($this->maintenance ? 'on' : 'off');
        $this->crash('restoreFiles');
        parent::restoreFiles($archive);
    }

    public function restoreDatabase(RemoteFile $dump): void
    {
        $this->maintenanceAtRestore[] = 'database '.($this->maintenance ? 'on' : 'off');
        parent::restoreDatabase($dump);
    }
}
