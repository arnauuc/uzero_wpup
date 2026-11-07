<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Notifications\NotificationAdministration;
use App\Domain\Secrets\SecretStore;
use App\Enums\NotificationThreshold;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\NotificationDestination;
use App\Models\NotificationSubscription;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\StoredSecret;
use App\Models\User;
use Tests\Support\FakeNotificationChannel;

/*
 * UZEROWPUP-79: destinations (admin, CLI only, secret typed without echo and
 * kept in the SecretStore) and the operators' subscriptions.
 */

const NOTIFY_TOKEN = '123456:AAH-bot-token-that-must-not-leak';

beforeEach(function (): void {
    FakeNotificationChannel::install();
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('admin1');
});

/**
 * @param  array<string, mixed>  $profile
 */
function notifySite(array $profile = []): Site
{
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id, ...$profile]);

    return $site;
}

function revealedNotifySecret(NotificationDestination $destination): string
{
    return app(SecretStore::class)->reveal((string) $destination->credential_ref, AuditActor::system('tests'), 'test')->reveal();
}

it('creates a destination with its secret in the SecretStore and never shows it', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'chat-1234567'])
        ->expectsQuestion('Token o webhook', NOTIFY_TOKEN)
        ->expectsOutputToContain('creada')
        ->doesntExpectOutputToContain(NOTIFY_TOKEN)
        ->doesntExpectOutputToContain('chat-1234567')
        ->assertSuccessful();

    $destination = NotificationDestination::query()->where('name', 'equip')->sole();
    $audit = AuditLogEntry::query()->where('action', 'notification.destination.set')->sole();
    expect(revealedNotifySecret($destination))->toBe(NOTIFY_TOKEN)
        ->and(StoredSecret::query()->findOrFail($destination->credential_ref)->purpose)->toBe(NotificationAdministration::SECRET_PURPOSE)
        ->and($audit->context)->toMatchArray(['name' => 'equip', 'channel' => 'fake', 'address' => 'ch***67', 'created' => true, 'secret_set' => true]);
    expect((string) json_encode(AuditLogEntry::query()->get()->toArray()))->not->toContain(NOTIFY_TOKEN);
});

it('edits without a terminal and keeps the secret, and asks for a new one only with --rotate-secret', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'chat-1'])
        ->expectsQuestion('Token o webhook', NOTIFY_TOKEN)->assertSuccessful();
    $ref = NotificationDestination::query()->sole()->credential_ref;

    command('notify:destination', ['name' => 'equip', '--deactivate' => true, '--address' => 'chat-2', '--no-interaction' => true])
        ->expectsOutputToContain('actualitzada')->assertSuccessful();
    $kept = NotificationDestination::query()->sole();
    expect($kept->active)->toBeFalse()->and($kept->address)->toBe('chat-2')->and(revealedNotifySecret($kept))->toBe(NOTIFY_TOKEN);

    command('notify:destination', ['name' => 'equip', '--rotate-secret' => true, '--no-interaction' => true])
        ->expectsOutputToContain('terminal interactiu')->assertFailed();
    command('notify:destination', ['name' => 'equip', '--rotate-secret' => true])
        ->expectsQuestion('Token o webhook', 'nou-token-9999')->assertSuccessful();
    $replaced = NotificationDestination::query()->sole();
    expect($replaced->credential_ref)->toBe($ref)->and(revealedNotifySecret($replaced))->toBe('nou-token-9999');
});

it('refuses a destination without a TTY, without its secret or with a bad channel, and stores nothing', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'x', '--no-interaction' => true])
        ->expectsOutputToContain('terminal interactiu')->assertFailed();
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'x'])
        ->expectsQuestion('Token o webhook', '')->expectsOutputToContain('Cal el token o webhook')->assertFailed();
    command('notify:destination', ['name' => 'equip', '--channel' => 'log'])->expectsOutputToContain('desconegut')->assertFailed();
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake'])->expectsOutputToContain('--address')->assertFailed();

    expect(NotificationDestination::query()->count())->toBe(0);
});

it('changes nothing in a dry run', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'x', '--dry-run' => true])
        ->expectsOutputToContain('[dry-run]')->assertSuccessful();

    expect(NotificationDestination::query()->count())->toBe(0);
});

it('lets only an admin manage destinations', function (): void {
    actingAsOperator('oper1');

    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'x'])->assertFailed();

    expect(NotificationDestination::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'command.denied')->exists())->toBeTrue();
});

it('lets an operator follow and leave a destination, and only an admin do it for someone else', function (): void {
    NotificationDestination::query()->create(['name' => 'equip', 'channel' => 'fake', 'address' => 'chat-1234567']);
    actingAsOperator('oper1');

    command('notify:subscribe', ['destination' => 'equip', '--min' => 'warnings'])->expectsOutputToContain('avisos i errors')->assertSuccessful();
    command('notify:subscribe', ['destination' => 'equip', '--min' => 'errors'])->assertSuccessful();
    expect(NotificationSubscription::query()->sole()->min_severity)->toBe(NotificationThreshold::Errors);

    command('notify:subscribe', ['destination' => 'equip', '--user' => 'admin1'])->expectsOutputToContain('Només un admin')->assertFailed();
    command('notify:subscribe', ['destination' => 'equip', '--min' => 'sovint'])->assertFailed();
    command('notify:subscribe', ['destination' => 'equip', '--remove' => true])->expectsOutputToContain('ja no segueix')->assertSuccessful();
    expect(NotificationSubscription::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'notification.subscription.removed')->exists())->toBeTrue();

    actingAsOperator('admin1');
    command('notify:subscribe', ['destination' => 'equip', '--user' => 'oper1'])->assertSuccessful();
    expect(NotificationSubscription::query()->sole()->user->unix_username)->toBe('oper1');
});

it('lists destinations with the address masked and never the secret', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'chat-1234567'])
        ->expectsQuestion('Token o webhook', NOTIFY_TOKEN)->assertSuccessful();
    command('notify:subscribe', ['destination' => 'equip'])->assertSuccessful();

    command('notify:list')
        ->expectsOutputToContain('ch***67')
        ->doesntExpectOutputToContain('chat-1234567')
        ->doesntExpectOutputToContain(NOTIFY_TOKEN)
        ->assertSuccessful();
    command('notify:list', ['--json' => true])
        ->expectsOutputToContain('"user": "admin1"')
        ->doesntExpectOutputToContain(NOTIFY_TOKEN)
        ->assertSuccessful();
});

it('sets the minimum severity of a site', function (): void {
    $site = Site::factory()->create(['name' => 'botiga']);
    SiteProfile::factory()->create(['site_id' => $site->id]);

    command('site:edit', ['name' => 'botiga', '--notify-min' => 'errors'])->assertSuccessful();
    command('site:edit', ['name' => 'botiga', '--notify-min' => 'sovint'])->expectsOutputToContain('severitat mínima')->assertFailed();

    expect(SiteProfile::query()->where('site_id', $site->id)->sole()->notification_min_severity)->toBe(NotificationThreshold::Errors);
});

it('saves and audits nothing when nothing changes', function (): void {
    NotificationDestination::query()->create(['name' => 'equip', 'channel' => 'fake', 'address' => 'chat-1', 'active' => false]);
    $updated = NotificationDestination::query()->sole()->updated_at;

    command('notify:destination', ['name' => 'equip', '--deactivate' => true, '--address' => 'chat-1', '--no-interaction' => true])
        ->expectsOutputToContain('no hi ha canvis')->assertSuccessful();

    expect(AuditLogEntry::query()->where('action', 'notification.destination.set')->exists())->toBeFalse()
        ->and(NotificationDestination::query()->sole()->updated_at?->toIso8601String())->toBe($updated?->toIso8601String());
});

it('still lists and deactivates a destination whose channel was removed, but gives it no new secret', function (): void {
    NotificationDestination::query()->create(['name' => 'vell', 'channel' => 'pigeon', 'address' => 'colom-123456']);

    command('notify:list')->expectsOutputToContain('pigeon')->assertSuccessful();
    command('notify:destination', ['name' => 'vell', '--deactivate' => true, '--no-interaction' => true])->assertSuccessful();
    command('notify:destination', ['name' => 'vell', '--rotate-secret' => true])->expectsOutputToContain('desconegut')->assertFailed();

    expect(NotificationDestination::query()->sole()->active)->toBeFalse();
});

it('says so when no channel can be chosen', function (): void {
    config(['wpup.notifications.channels' => ['log' => config('wpup.notifications.channels.log')]]);

    command('notify:destination', ['name' => 'equip', '--channel' => 'telegram'])
        ->expectsOutputToContain('no hi ha cap canal configurat')->assertFailed();
    command('site:edit', ['name' => notifySite()->name, '--notify' => ['telegram']])
        ->expectsOutputToContain('no hi ha cap canal configurat')->assertFailed();
});

it('validates the channels of a site against the configured ones', function (): void {
    $site = notifySite();

    command('site:edit', ['name' => $site->name, '--notify' => ['sms']])->expectsOutputToContain('opcions: fake')->assertFailed();
    command('site:edit', ['name' => $site->name, '--notify' => ['fake']])->assertSuccessful();

    expect(SiteProfile::query()->sole()->notification_channels)->toBe(['fake']);
});

it('reports no changes for an unchanged minimum severity, and clears it', function (): void {
    $name = notifySite(['notification_min_severity' => NotificationThreshold::Errors])->name;

    command('site:edit', ['name' => $name, '--notify-min' => 'errors'])->expectsOutputToContain('no hi ha canvis')->assertSuccessful();
    command('site:edit', ['name' => $name, '--notify-min' => 'warnings'])->expectsOutputToContain('warnings')->assertSuccessful();
    command('site:edit', ['name' => $name, '--clear' => ['notify-min']])->assertSuccessful();

    expect(SiteProfile::query()->sole()->notification_min_severity)->toBe(NotificationThreshold::All);
});

it('imports the minimum severity of a site and clears it with an empty cell', function (): void {
    $file = static function (string $csv): string {
        $path = tempnam(sys_get_temp_dir(), 'wpup-import-').'.csv';
        file_put_contents($path, $csv);

        return $path;
    };
    $csv = "name,url,wp_path,notify_min,server,hostname,account\n"
        .'botiga-m,https://botiga-m.example.test,/var/www/m,errors,srv-m,srv-m.example.test,botiga_m'."\n";
    command('site:import', ['file' => $file($csv), '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->where('name', 'botiga-m')->sole()->profile?->notification_min_severity)->toBe(NotificationThreshold::Errors);

    command('site:import', ['file' => $file("name,notify_min\nbotiga-m,\n"), '--no-interaction' => true])->assertSuccessful();
    expect(Site::query()->where('name', 'botiga-m')->sole()->profile?->notification_min_severity)->toBe(NotificationThreshold::All);
});
