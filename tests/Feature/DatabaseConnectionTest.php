<?php

declare(strict_types=1);

use App\Models\Run;
use Illuminate\Support\Facades\DB;

it('uses the pgsql driver by default', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('can run a query against PostgreSQL', function (): void {
    expect(DB::scalar('select 1'))->toBe(1);
});

it('connects as the runtime role wpup_app', function (): void {
    expect(DB::scalar('select current_user'))->toBe('wpup_app');
});

it('stores and reads timestamps at the same instant', function (): void {
    $run = Run::factory()->create(['deadline_at' => now()->subMinute()]);

    $stored = DB::scalar('select deadline_at < now() from runs where id = ?', [$run->id]);

    expect($stored)->toBeTrue()
        ->and($run->fresh()?->deadline_at?->isPast())->toBeTrue();
});
