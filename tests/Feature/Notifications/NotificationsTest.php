<?php

declare(strict_types=1);

use App\Domain\Audit\AuditActor;
use App\Domain\Cleanup\CleanupResult;
use App\Domain\Cleanup\CleanupService;
use App\Domain\Notifications\Notifier;
use App\Domain\Runs\InterruptionDecision;
use App\Domain\Runs\Pipeline;
use App\Domain\Runs\PipelineResolver;
use App\Domain\Runs\RunEngine;
use App\Domain\Runs\RunInterruption;
use App\Domain\Runs\StepOutcome;
use App\Domain\Scheduling\AutoUpdateBlocking;
use App\Domain\Secrets\Secret;
use App\Domain\Secrets\SecretStore;
use App\Enums\NotificationDeliveryStatus;
use App\Enums\NotificationKind;
use App\Enums\NotificationSeverity;
use App\Enums\NotificationThreshold;
use App\Enums\RunStatus;
use App\Enums\RunStepKind;
use App\Enums\RunStepStatus;
use App\Enums\RunType;
use App\Enums\ScheduledWindowStatus;
use App\Models\NotificationDelivery;
use App\Models\NotificationDestination;
use App\Models\Run;
use App\Models\ScheduledWindow;
use App\Models\Site;
use App\Models\SiteProfile;
use App\Models\User;
use App\Notifications\RunFinishedNotification;
use App\Notifications\RunInterruptedNotification;
use App\Notifications\SiteBlockedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Tests\Support\FakeNotificationChannel;
use Tests\Support\Runs\ScriptedCompensableStep;
use Tests\Support\Runs\ScriptedStep;

/*
 * UZEROWPUP-79: notifications go to the log and to the destinations the
 * operators follow, filtered by the minimum severity of the site and of each
 * operator, and every delivery is recorded without secrets.
 */

beforeEach(function (): void {
    Redis::connection('locks')->command('flushdb');
    config(['wpup.runs.heartbeat_seconds' => 1]);
    FakeNotificationChannel::install();
});

function fakeChannel(): FakeNotificationChannel
{
    return app(FakeNotificationChannel::class);
}

function notifyDestination(string $name, ?string $secret = null, string $address = 'chat-1234567', bool $active = true): NotificationDestination
{
    return NotificationDestination::query()->create([
        'name' => $name,
        'channel' => FakeNotificationChannel::NAME,
        'address' => $address,
        'credential_ref' => $secret === null ? null : app(SecretStore::class)->put('notification-channel', new Secret($secret), AuditActor::system('tests')),
        'active' => $active,
    ]);
}

function follow(NotificationDestination $destination, NotificationThreshold $min = NotificationThreshold::All, bool $active = true): User
{
    $user = User::factory()->create(['active' => $active]);
    $destination->subscriptions()->create(['user_id' => $user->id, 'min_severity' => $min]);

    return $user;
}

/**
 * @param  array<string, mixed>  $profile
 */
function profiledSite(array $profile): Site
{
    $site = Site::factory()->create();
    SiteProfile::factory()->create(['site_id' => $site->id, ...$profile]);

    return $site;
}

function blockedNotice(Site $site, bool $blocked = true): SiteBlockedNotification
{
    return new SiteBlockedNotification($site, $blocked ? 'PHP < 7.4: no suportat' : null);
}

/**
 * @return list<string>
 */
function sentTo(): array
{
    return array_column(fakeChannel()->sent, 'destination');
}

it('maps the minimum severities', function (NotificationThreshold $min, array $allowed): void {
    $got = array_values(array_filter(NotificationSeverity::cases(), $min->allows(...)));

    expect($got)->toBe($allowed);
})->with([
    'all' => [NotificationThreshold::All, [NotificationSeverity::Info, NotificationSeverity::Warning, NotificationSeverity::Error]],
    'warnings' => [NotificationThreshold::Warnings, [NotificationSeverity::Warning, NotificationSeverity::Error]],
    'errors' => [NotificationThreshold::Errors, [NotificationSeverity::Error]],
]);

it('filters by the minimum severity of each operator', function (): void {
    follow(notifyDestination('tot'));
    follow(notifyDestination('avisos'), NotificationThreshold::Warnings);
    follow(notifyDestination('errors'), NotificationThreshold::Errors);
    $site = Site::factory()->create();

    app(Notifier::class)->notify(blockedNotice($site, false));
    expect(sentTo())->toBe(['tot']);

    fakeChannel()->sent = [];
    app(Notifier::class)->notify(blockedNotice($site));
    expect(sentTo())->toBe(['tot', 'avisos']);
});

it('applies the minimum severity and the channels of the site', function (): void {
    follow(notifyDestination('equip'));
    $quiet = profiledSite(['notification_min_severity' => NotificationThreshold::Errors]);
    $elsewhere = profiledSite(['notification_channels' => ['telegram']]);
    $open = profiledSite(['notification_channels' => [FakeNotificationChannel::NAME]]);

    foreach ([$quiet, $elsewhere, $open] as $site) {
        app(Notifier::class)->notify(blockedNotice($site));
    }

    expect(fakeChannel()->sent)->toHaveCount(1)
        ->and(fakeChannel()->sent[0]['subject'])->toContain($open->name)
        // The log always gets them.
        ->and(NotificationDelivery::query()->where('channel', 'log')->count())->toBe(3);
});

it('skips inactive operators and destinations, and sends once to a shared destination', function (): void {
    $shared = notifyDestination('compartit');
    follow($shared);
    follow($shared, NotificationThreshold::Errors);
    follow(notifyDestination('de-baixa'), active: false);
    follow(notifyDestination('apagat', active: false));

    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));

    expect(sentTo())->toBe(['compartit']);
});

it('records every delivery with the masked destination and no content', function (): void {
    follow(notifyDestination('equip', address: 'chat-1234567'));
    $site = Site::factory()->create();

    app(Notifier::class)->notify(blockedNotice($site));

    $rows = NotificationDelivery::query()->orderBy('id')->get();
    $log = $rows->firstOrFail();
    $sent = NotificationDelivery::query()->where('channel', FakeNotificationChannel::NAME)->sole();
    expect($rows)->toHaveCount(2)
        ->and($log->channel)->toBe('log')
        ->and($log->notification_destination_id)->toBeNull()
        ->and($sent->destination)->toBe('equip (ch***67)')
        ->and($sent->kind)->toBe(NotificationKind::SiteBlocked)
        ->and($sent->severity)->toBe(NotificationSeverity::Warning)
        ->and($sent->status)->toBe(NotificationDeliveryStatus::Sent)
        ->and($sent->site_id)->toBe($site->id)
        ->and($sent->error)->toBeNull();
    expect((string) json_encode($rows->toArray()))->not->toContain('PHP < 7.4');
});

it('records a failing channel and goes on with the other destinations', function (): void {
    follow(notifyDestination('a'));
    follow(notifyDestination('b'));
    fakeChannel()->fail = true;

    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));

    $failed = NotificationDelivery::query()->where('status', NotificationDeliveryStatus::Failed->value)->get();
    expect($failed)->toHaveCount(2)
        ->and($failed->firstOrFail()->error)->toBe('the chat service is down')
        ->and(NotificationDelivery::query()->where('channel', 'log')->sole()->status)->toBe(NotificationDeliveryStatus::Sent);
});

it('skips a destination whose channel is no longer configured, with a warning', function (): void {
    $gone = notifyDestination('vell');
    $gone->forceFill(['channel' => 'pigeon'])->save();
    follow($gone);
    $logged = [];
    Log::listen(static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->level.' '.$event->message;
    });

    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));

    $row = NotificationDelivery::query()->where('channel', 'pigeon')->sole();
    expect($row->status)->toBe(NotificationDeliveryStatus::Skipped)
        ->and($row->error)->toContain('no està configurat')
        ->and($logged)->toContain('warning Notification destination skipped: its channel is not configured.');
});

it('never stores nor logs the secret of a channel', function (): void {
    $token = '7777777:AAH-super-secret-bot-token';
    $destination = notifyDestination('bot', $token);
    follow($destination);
    fakeChannel()->leak = true;
    $logged = [];
    Log::listen(static function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.json_encode($event->context);
    });

    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));

    $delivery = NotificationDelivery::query()->where('notification_destination_id', $destination->id)->sole();
    $error = (string) $delivery->error;
    $log = implode("\n", $logged);
    expect($delivery->status)->toBe(NotificationDeliveryStatus::Failed)
        ->and($error)->toContain('[secret]')
        ->and($log)->toContain('Notification delivery failed');
    expect($error)->not->toContain($token);
    expect($log)->not->toContain($token);
    expect((string) json_encode(NotificationDelivery::query()->get()->toArray()))->not->toContain($token);
});

it('notifies the end of a run and keeps the delivery with the run, but not a dry run', function (): void {
    follow(notifyDestination('equip'));
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('fet')),
    ]));
    $run = app(RunEngine::class)->execute(Run::factory()->create());
    app(RunEngine::class)->execute(Run::factory()->create(['flags' => ['dry_run' => true]]));

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(fakeChannel()->sent)->toHaveCount(1)
        ->and(fakeChannel()->sent[0]['kind'])->toBe('run_finished')
        ->and(fakeChannel()->sent[0]['severity'])->toBe('info')
        ->and($run->notificationDeliveries()->count())->toBe(2);
});

it('notifies a rollback as a warning and a failure as an error', function (): void {
    follow(notifyDestination('equip'));
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedCompensableStep('backup', static fn (): StepOutcome => StepOutcome::succeeded('fet'), static fn (): StepOutcome => StepOutcome::succeeded('desfet')),
        new ScriptedStep('update', static fn (): StepOutcome => StepOutcome::failed('fatal nou')),
    ]));
    app(PipelineResolver::class)->register(RunType::Inventory, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('inventory', static fn (): StepOutcome => StepOutcome::failed('sense connexió')),
    ]));

    $rolledBack = app(RunEngine::class)->execute(Run::factory()->create());
    $failed = app(RunEngine::class)->execute(Run::factory()->create(['type' => RunType::Inventory]));

    expect([$rolledBack->status, $failed->status])->toBe([RunStatus::RolledBack, RunStatus::Failed])
        ->and(array_map(static fn (array $s): array => [$s['kind'], $s['severity']], fakeChannel()->sent))
        ->toBe([['run_rolled_back', 'warning'], ['run_finished', 'error']])
        ->and(fakeChannel()->sent[0]['subject'])->toContain('revertida');
});

it('never lets a failing channel change the outcome of a run', function (): void {
    follow(notifyDestination('equip'));
    fakeChannel()->fail = true;
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('fet')),
    ]));

    $run = app(RunEngine::class)->execute(Run::factory()->create());

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and($run->notificationDeliveries()->where('status', NotificationDeliveryStatus::Failed->value)->count())->toBe(1);
});

it('notifies an interruption, as an error when the run cannot go on', function (): void {
    follow(notifyDestination('equip'));
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('a', static fn (): StepOutcome => StepOutcome::succeeded('a')),
    ]));
    $run = Run::factory()->create(['status' => RunStatus::Interrupted, 'started_at' => now(), 'deadline_at' => now()->addHour()]);
    $run->steps()->create(['sequence' => 1, 'name' => 'a', 'status' => RunStepStatus::Running, 'attempts' => 1, 'kind' => RunStepKind::Forward]);

    app(RunEngine::class)->execute($run);

    expect(array_column(fakeChannel()->sent, 'kind'))->toBe(['run_interrupted', 'run_finished'])
        ->and(fakeChannel()->sent[0]['severity'])->toBe('warning')
        // A success after an interruption is worth a look.
        ->and(fakeChannel()->sent[1]['severity'])->toBe('warning')
        ->and((new RunInterruptedNotification($run, new RunInterruption(now()->toImmutable(), 'running', 'a', RunStepKind::Forward, 1, InterruptionDecision::Fail)))->severity())
        ->toBe(NotificationSeverity::Error);
});

it('notifies a site blocked for automatic updates', function (): void {
    follow(notifyDestination('equip'), NotificationThreshold::Warnings);
    config(['wpup.scheduler.failures.block_after' => 1]);
    $site = Site::factory()->create(['auto_update' => true]);
    $run = Run::factory()->create(['site_id' => $site->id, 'status' => RunStatus::Failed]);
    ScheduledWindow::query()->create(['site_id' => $site->id, 'window_date' => now()->format('Y-m-d'), 'opens_at' => now()->subHour(), 'closes_at' => now()->addHour(),
        'status' => ScheduledWindowStatus::Failed, 'run_id' => $run->id]);

    expect(app(AutoUpdateBlocking::class)->afterFailure($site, $run, AuditActor::system('tests')))->not->toBeNull()
        ->and(array_column(fakeChannel()->sent, 'kind'))->toBe(['auto_update_blocked'])
        ->and(NotificationDelivery::query()->where('channel', FakeNotificationChannel::NAME)->sole()->run_id)->toBe($run->id);
});

it('limits the log channel to its minimum severity', function (): void {
    config(['wpup.notifications.log_min_severity' => 'errors']);

    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));

    expect(NotificationDelivery::query()->count())->toBe(0);
});

it('makes a successful run with warnings in its steps a warning', function (): void {
    follow(notifyDestination('equip'), NotificationThreshold::Warnings);
    app(PipelineResolver::class)->register(RunType::Update, static fn (): Pipeline => new Pipeline([
        new ScriptedStep('smoke_check', static fn (): StepOutcome => StepOutcome::succeeded('ok', '', ['warnings' => ['2 warning(s) nou(s) de PHP al log']])),
    ]));

    $run = app(RunEngine::class)->execute(Run::factory()->create());

    expect($run->status)->toBe(RunStatus::Succeeded)
        ->and(fakeChannel()->sent)->toHaveCount(1)
        ->and(fakeChannel()->sent[0]['severity'])->toBe('warning')
        ->and((new RunFinishedNotification($run))->lines())->toContain('Avís: 2 warning(s) nou(s) de PHP al log');
});

it('purges the delivery records after their retention, and not before', function (): void {
    config(['wpup.notifications.deliveries_retention_days' => 30]);
    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));
    $old = NotificationDelivery::query()->sole();
    $old->forceFill(['created_at' => now()->subDays(31)])->save();
    app(Notifier::class)->notify(blockedNotice(Site::factory()->create()));

    $result = collect(app(CleanupService::class)->run(AuditActor::system('tests'), CarbonImmutable::now(), false))
        ->first(static fn (CleanupResult $r): bool => $r->task === 'notification_deliveries');

    expect($result?->count)->toBe(1)
        ->and(NotificationDelivery::query()->count())->toBe(1)
        ->and(NotificationDelivery::query()->whereKey($old->id)->exists())->toBeFalse();
});
