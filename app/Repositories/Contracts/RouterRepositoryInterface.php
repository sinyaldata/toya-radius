<?php

namespace App\Repositories\Contracts;

use App\Enums\RouterConnectionStatus;
use App\Models\Router;
use Illuminate\Database\Eloquent\Collection;

interface RouterRepositoryInterface
{
    public function find(int $id): ?Router;

    public function active(): Collection;

    public function updateConnectionStatus(Router $router, RouterConnectionStatus $status, ?string $version = null, ?string $identity = null): bool;
}
