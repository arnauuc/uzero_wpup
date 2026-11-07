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

it('keeps the secret when the new one is left empty, and replaces it otherwise', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'chat-1'])
        ->expectsQuestion('Token o webhook', NOTIFY_TOKEN)->assertSuccessful();
    $ref = NotificationDestination::query()->sole()->credential_ref;

    command('notify:destination', ['name' => 'equip', '--deactivate' => true])
        ->expectsQuestion('Token o webhook nou (buit: es manté l\'actual)', '')->assertSuccessful();
    $kept = NotificationDestination::query()->sole();
    expect($kept->active)->toBeFalse()->and(revealedNotifySecret($kept))->toBe(NOTIFY_TOKEN);

    command('notify:destination', ['name' => 'equip'])
        ->expectsQuestion('Token o webhook nou (buit: es manté l\'actual)', 'nou-token-9999')->assertSuccessful();
    $replaced = NotificationDestination::query()->sole();
    expect($replaced->credential_ref)->toBe($ref)->and(revealedNotifySecret($replaced))->toBe('nou-token-9999');
});

it('refuses a destination without a TTY, without its secret or with a bad channel, and stores nothing', function (): void {
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'x', '--no-interaction' => true])
        ->expectsOutputToContain('terminal interactiu')->assertFailed();
    command('notify:destination', ['name' => 'equip', '--channel' => 'fake', '--address' => 'x'])
        ->expectsQuestion('Token o webhook', '')->expectsOutputToContain('necessita un token')->assertFailed();
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
