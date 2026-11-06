<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Backups\BackupTarget;
use App\Domain\Backups\BackupTargetRegistry;
use App\Domain\Backups\DatabaseBackup;
use App\Domain\Backups\NewWritesDetector;
use App\Domain\Backups\OrderWatermark;
use App\Domain\Backups\RestorePointService;
use App\Domain\Backups\ShopWritesReader;
use App\Domain\Backups\SiteLocalDestination;
use App\Domain\Backups\SiteLocalSettings;
use App\Domain\Backups\SiteLocalTarget;
use App\Domain\Drivers\Data\RemoteFile;
use App\Domain\Drivers\Exceptions\RemoteCommandFailed;
use App\Domain\Secrets\SecretRedactor;
use App\Enums\BackupKind;
use App\Enums\BackupTargetType;
use App\Enums\NewWritesStatus;
use App\Models\Site;
use App\Models\SiteProfile;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeDriver;

/*
 * Order and user watermark of a database copy (UZEROWPUP-58) over FakeDriver:
 * read right before the dump, stored with the copy, compared before a restore.
 */

/** Records which WP-CLI calls happened before the dump. */
final class WatermarkOrderDriver extends FakeDriver
{
    /** @var list<list<string>> */
    public array $callsBeforeDump = [];

    public function dumpDatabase(string $remotePath): RemoteFile
    {
        $this->callsBeforeDump = $this->wpCalls;

        return parent::dumpDatabase($remotePath);
    }
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/** A site whose site-local destination is served by $driver. */
function watermarkSite(FakeDriver $driver): Site
{
    app(BackupTargetRegistry::class)->register(BackupTargetType::SiteLocal, static fn (): BackupTarget => new SiteLocalTarget(
        $driver, app(SecretRedactor::class), app(HttpFactory::class), app(SiteLocalSettings::class), 'https://fake.test',
    ));
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id]);
    app(SiteLocalDestination::class)->attach($site);

    return $site;
}

it('reads the watermark right before the dump, without plugins and themes', function (): void {
    $driver = new WatermarkOrderDriver;
    $driver->orders = [['id' => 4, 'type' => 'shop_order', 'status' => 'wc-completed', 'created' => '2026-11-01 10:00:00', 'modified' => '2026-11-01 12:00:00']];

    $result = app(DatabaseBackup::class)->run($driver, '42');

    $last = $driver->callsBeforeDump[count($driver->callsBeforeDump) - 1];
    expect($last)->toBe(ShopWritesReader::command(['mode' => 'watermark', 'order_types' => config('wpup.backups.watermark.order_types'), 'scan_cap' => 1000]))
        ->and(config('wpup.backups.watermark.order_types'))->toContain('shop_order', 'shop_subscription')
        ->and($result->watermark?->tables['wc_orders']->toArray())->toBe(['present' => true, 'readable' => true, 'max_id' => 4, 'max' => ['date_created_gmt' => '2026-11-01 10:00:00', 'date_updated_gmt' => '2026-11-01 12:00:00'], 'fingerprint' => null])
        ->and($result->watermark?->tables['wc_order_addresses']->present)->toBeFalse()
        ->and($result->warnings)->toBe([]);
});

it('stores the watermark with the database copy of the restore point', function (): void {
    $driver = new FakeDriver;
    $site = watermarkSite($driver);
    $driver->users = [['id' => 1, 'registered' => '2026-01-01 00:00:00', 'roles' => ['administrator']]];

    $point = app(RestorePointService::class)->create($site, $driver, null, AuditActor::system('tests'));

    $watermark = OrderWatermark::fromArray($point->local(BackupKind::Database)?->fresh()?->order_watermark);
    expect($watermark?->tables['users']->maxId)->toBe(1)
        ->and($point->local(BackupKind::Files)?->order_watermark)->toBeNull()
        ->and($point->warnings)->toBe([]);
});

it('still makes the restore point when the watermark cannot be read, with a warning and no watermark', function (?string $output): void {
    $driver = new FakeDriver;
    $site = watermarkSite($driver);
    if ($output === null) {
        $driver->failingCommands = ['eval'];
    }
    $driver->shopWritesOutput = $output;

    $point = app(RestorePointService::class)->create($site, $driver, null, AuditActor::system('tests'));

    expect($point->local(BackupKind::Database)?->verified_at)->not->toBeNull()
        ->and($point->local(BackupKind::Database)?->order_watermark)->toBeNull()
        ->and($point->warnings[0] ?? '')->toContain("No s'ha pogut llegir la marca d'aigua de comandes i usuaris")
        ->and($point->warnings[0] ?? '')->toContain('es tractarà com si hi hagués escriptures noves');
})->with([
    'WP-CLI fails' => [null],
    'not JSON' => ["Notice: something\n"],
    'a table query failed' => ['{"v":1,"error":"wc_orders"}'],
    'invalid read time' => ['{"v":1,"now":"ahir","unix":1,"tables":{}}'],
]);

it('passes the stored watermark as data only: the snippet stays constant', function (): void {
    $args = ShopWritesReader::command(['now' => "'); system('id'); //", 'limit' => 5]);

    expect($args[0])->toBe('eval')
        ->and($args[1])->toMatch("/^\\\$in = json_decode\\(base64_decode\\('[A-Za-z0-9+\\/=]+'\\), true\\);\n/")
        ->and(substr($args[1], strpos($args[1], "\n") + 1))->toBe(ShopWritesReader::SNIPPET)
        ->and(array_slice($args, 2))->toBe(['--skip-plugins', '--skip-themes']);
});

it('detects the orders and users written above the watermark of a copy', function (): void {
    $driver = new FakeDriver;
    $driver->orders = [['id' => 4, 'type' => 'shop_order', 'status' => 'wc-completed', 'created' => '2026-11-01 10:00:00', 'modified' => '2026-11-01 12:00:00']];
    $stored = app(ShopWritesReader::class)->watermark($driver)->toArray();
    $detector = app(NewWritesDetector::class);

    expect($detector->detect($driver, $stored)->status)->toBe(NewWritesStatus::None);

    $driver->orders[] = ['id' => 5, 'type' => 'shop_order', 'status' => 'wc-processing', 'created' => '2026-11-02 08:00:00', 'modified' => '2026-11-02 08:00:00'];
    $writes = $detector->detect($driver, $stored);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and(array_map(static fn ($r): array => $r->toArray(), $writes->orders))->toBe([['id' => 5, 'new' => true, 'date' => '2026-11-02 08:00:00', 'label' => 'comanda, processing']])
        ->and($writes->users)->toBe([]);
});

it('asks the site for the rows dated within the safety margin before the read', function (): void {
    $driver = new FakeDriver;
    $driver->siteTime = 1762000000;
    $stored = app(ShopWritesReader::class)->watermark($driver)->toArray();

    app(NewWritesDetector::class)->detect($driver, $stored);

    $code = $driver->wpCalls[count($driver->wpCalls) - 1][1];
    preg_match("/base64_decode\\('([A-Za-z0-9+\\/=]+)'\\)/", $code, $m);
    $input = json_decode((string) base64_decode($m[1] ?? ''), true);
    expect($input)->toMatchArray(['mode' => 'since', 'since_unix' => 1762000000 - 300, 'since_utc' => gmdate('Y-m-d H:i:s', 1762000000 - 300), 'limit' => 50, 'max_roles' => 5, 'scan_cap' => 1000]);
});

it('detects a user updated in place by the fingerprint of the users table', function (): void {
    $driver = new FakeDriver;
    $driver->users = [['id' => 1, 'registered' => '2026-01-01 00:00:00', 'roles' => ['customer']]];
    $stored = app(ShopWritesReader::class)->watermark($driver)->toArray();
    $driver->users[0]['roles'] = ['administrator'];

    $writes = app(NewWritesDetector::class)->detect($driver, $stored);

    expect($writes->status)->toBe(NewWritesStatus::Found)
        ->and($writes->unexplained)->toBe(['users'])
        ->and($writes->describe())->toContain('usuaris modificats: no es pot saber quins');
});

it('answers unknown (treated as new writes) without a watermark or when the site cannot be read', function (mixed $stored, ?Throwable $failure, string $reason): void {
    $driver = new FakeDriver;
    $driver->wpFailure = $failure;
    $stored = $stored === 'valid' ? app(ShopWritesReader::class)->watermark(new FakeDriver)->toArray() : $stored;

    $writes = app(NewWritesDetector::class)->detect($driver, $stored);

    expect($writes->status)->toBe(NewWritesStatus::Unknown)
        ->and($writes->mayLoseData())->toBeTrue()
        ->and($writes->reason)->toContain($reason);
})->with([
    'no watermark' => [null, null, "la còpia no té marca d'aigua"],
    'not a watermark' => [['version' => 1, 'tables' => []], null, 'no és vàlida'],
    'site unreadable' => ['valid', new RemoteCommandFailed('connexió tallada'), "no s'ha pogut llegir el lloc: connexió tallada"],
]);
