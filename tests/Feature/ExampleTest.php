<?php

declare(strict_types=1);

use function Pest\Laravel\get;

test('the application health check returns a successful response', function (): void {
    $response = get('/up');

    $response->assertStatus(200);
});
