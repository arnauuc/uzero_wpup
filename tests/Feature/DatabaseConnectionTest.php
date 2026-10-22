<?php

declare(strict_types=1);

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
