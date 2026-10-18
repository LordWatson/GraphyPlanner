<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Step I-2.8 — Horizon runs the Redis queues (see `config/horizon.php` for the per-queue
 * supervisors). Its dashboard is for platform super admins only, in every environment.
 */
class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Unlike Horizon's default, `local` gets no free pass: the dashboard shows job payloads.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (?User $user = null): bool => $user?->isSuperAdmin() ?? false);
    }
}
