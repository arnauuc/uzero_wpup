<?php

declare(strict_types=1);

use App\Domain\Drivers\Ssh\HostKeyPinning;
use App\Domain\Drivers\Ssh\SshConnection;
use App\Enums\AuditResult;
use App\Enums\UserRole;
use App\Models\AuditLogEntry;
use App\Models\HostingServer;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\Support\Fixtures;

/*
 * site:test against the Docker test sites (make fixtures-up).
 */

if (! Fixtures::up('wp83')) {
    it('tests sites against the fixtures')->skip('Llocs de prova aturats: make fixtures-up');

    return;
}

beforeEach(function (): void {
    User::factory()->create(['unix_username' => 'admin1', 'role' => UserRole::Admin]);
    User::factory()->create(['unix_username' => 'oper1', 'role' => UserRole::Operator]);
    actingAsOperator('admin1');
});

function serverOf(Site $site): HostingServer
{
    return $site->hostingAccount()->firstOrFail()->hostingServer()->firstOrFail();
}

it('computes the same fingerprint as ssh-keygen', function (): void {
    $key = SshConnection::fetchHostKey('wp83', 22, 5);
    $file = (string) tempnam(sys_get_temp_dir(), 'hk');
    file_put_contents($file, "wp83 {$key}\n");
    $keygen = new Process(['ssh-keygen', '-lf', $file]);
    $keygen->mustRun();
    unlink($file);

    expect($keygen->getOutput())->toContain(HostKeyPinning::fingerprint($key));
});

it('reports every prerequisite of a healthy site', function (): void {
    $site = Fixtures::site('wp83');

    command('site:test', ['sites' => [$site->name]])
        ->expectsOutputToContain('pot escriure el codi del lloc')
        ->assertSuccessful();
});

it('pins the host key of a new server once the admin confirms the fingerprint', function (): void {
    $site = Fixtures::site('wp83', pin: false);
    $fingerprint = HostKeyPinning::fingerprint(SshConnection::fetchHostKey('wp83', 22, 5));

    command('site:test', ['sites' => [$site->name]])
        ->expectsConfirmation('Coincideix i la vols fixar?', 'yes')
        ->assertSuccessful();

    expect(serverOf($site)->fresh()?->pinned_host_key)->not->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'host_key.pin')->where('result', AuditResult::Success->value)->value('context'))
        ->toBe(['fingerprint' => $fingerprint]);
});

it('pins non-interactively only with the right fingerprint', function (): void {
    $site = Fixtures::site('wp83', pin: false);

    command('site:test', ['sites' => [$site->name], '--trust-host-key' => 'SHA256:wrong', '--no-interaction' => true])
        ->expectsOutputToContain('no és la confirmada')
        ->assertFailed();
    expect(serverOf($site)->fresh()?->pinned_host_key)->toBeNull();

    $fingerprint = HostKeyPinning::fingerprint(SshConnection::fetchHostKey('wp83', 22, 5));
    command('site:test', ['sites' => [$site->name], '--trust-host-key' => $fingerprint, '--no-interaction' => true])->assertSuccessful();
    expect(serverOf($site)->fresh()?->pinned_host_key)->not->toBeNull();
});

it('does not pin when the admin declines, in a dry run or for an operator', function (): void {
    $site = Fixtures::site('wp83', pin: false);

    command('site:test', ['sites' => [$site->name]])
        ->expectsConfirmation('Coincideix i la vols fixar?', 'no')
        ->assertFailed();
    command('site:test', ['sites' => [$site->name], '--dry-run' => true])
        ->expectsOutputToContain('[dry-run] Es fixaria')
        ->assertSuccessful();

    actingAsOperator('oper1');
    command('site:test', ['sites' => [$site->name]])
        ->expectsOutputToContain('Un admin')
        ->assertFailed();

    expect(serverOf($site)->fresh()?->pinned_host_key)->toBeNull();
});

it('fails clearly for an account without shell or without key', function (): void {
    $noShell = Fixtures::site('wp83', user: 'sub_wp83_nosh');
    $noKey = Fixtures::site('wp83', withKey: false);

    command('site:test', ['sites' => [$noShell->name]])->expectsOutputToContain('/bin/false')->assertFailed();
    command('site:test', ['sites' => [$noKey->name]])->expectsOutputToContain('no té cap clau SSH')->assertFailed();
});

it('tests several sites and prints JSON for scripts', function (): void {
    $a = Fixtures::site('wp83');
    $b = Fixtures::site('wp74');
    $a->forceFill(['tags' => ['prova']])->save();
    $b->forceFill(['tags' => ['prova']])->save();

    Artisan::call('site:test', ['--tag' => ['prova'], '--json' => true]);
    $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($json)->toHaveKey('sites')
        ->and(data_get($json, 'sites.'.$a->name.'.result'))->toBe('ok')
        ->and(data_get($json, 'sites.'.$b->name.'.result'))->toBe('ok');
});
