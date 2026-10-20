<?php

use App\Models\ApiLog;
use App\Models\Router;
use App\Services\MikrotikService;

it('logs a failed api call and returns success=false when router is unreachable', function () {
    $router = Router::factory()->create([
        'ip_address' => '10.255.255.1', // non-routable, akan timeout/refused
        'api_port' => 8728,
    ]);

    $result = app(MikrotikService::class)->testConnection($router);

    expect($result['success'])->toBeFalse();
    expect($result)->toHaveKey('error');

    expect(ApiLog::where('router_id', $router->id)->where('status', 'failed')->exists())->toBeTrue();
})->skip(fn () => ! config('app.run_network_dependent_tests', false), 'Skip by default: menunggu timeout koneksi. Set app.run_network_dependent_tests=true untuk menjalankan.');
