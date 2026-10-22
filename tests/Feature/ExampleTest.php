<?php

declare(strict_types=1);

use function Pest\Laravel\get;

it('returns a successful response from the home page', function (): void {
    get('/')->assertStatus(200);
});
