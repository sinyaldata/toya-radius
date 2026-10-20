<?php

namespace App\Repositories;

use App\Enums\RouterConnectionStatus;
use App\Models\Router;
use App\Repositories\Contracts\RouterRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class RouterRepository implements RouterRepositoryInterface
{
    public function __construct(protected Router $model) {}

    public function find(int $id): ?Router
    {
        return $this->model->find($id);
    }

    public function active(): Collection
    {
        return $this->model->newQuery()->where('is_active', true)->get();
    }

    public function updateConnectionStatus(Router $router, RouterConnectionStatus $status, ?string $version = null, ?string $identity = null): bool
    {
        return $router->update([
            'connection_status' => $status,
            'routeros_version' => $version ?? $router->routeros_version,
            'identity' => $identity ?? $router->identity,
            'last_checked_at' => now(),
        ]);
    }
}
