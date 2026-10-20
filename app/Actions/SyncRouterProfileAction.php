<?php

namespace App\Actions;

use App\Models\ServicePackage;
use App\Services\MikrotikService;

class SyncRouterProfileAction
{
    public function __construct(protected MikrotikService $mikrotikService) {}

    public function execute(ServicePackage $profile): array
    {
        if (! $profile->router) {
            return ['success' => false, 'error' => 'Profile ini belum terhubung ke router manapun.'];
        }

        return $this->mikrotikService->syncProfile($profile->router, $profile);
    }
}
