<?php

namespace App\Observers;

use App\Jobs\SyncClientWithMikrotikJob;
use App\Models\Client;
use App\Services\MikrotikIspService;
use Illuminate\Support\Facades\Log;

class ClientObserver
{
    /**
     * When a new client is created → auto-sync with MikroTik
     * to find their IP and moroso status.
     * If client has a plan and router → auto-provision PPPoE.
     */
    public function created(Client $client): void
    {
        SyncClientWithMikrotikJob::dispatch($client->id);

        // Auto-provision PPPoE if plan + router assigned and no mikrotik_user yet
        if (! empty($client->plan_id) && ! empty($client->mikrotik_router_id) && empty($client->mikrotik_user)) {
            try {
                $client->load('plan');
                app(MikrotikIspService::class)->provisionPppoe($client, $client->mikrotik_router_id);
                Log::info("[ClientObserver] Auto-provisioned PPPoE for #{$client->id}");
            } catch (\Throwable $e) {
                Log::warning("[ClientObserver] PPPoE auto-provision failed for #{$client->id}: {$e->getMessage()}");
            }
        }
    }

    /**
     * When a client is updated → re-sync if name changed,
     * so MikroTik data stays in sync.
     */
    public function updated(Client $client): void
    {
        if ($client->wasChanged(['nombres', 'apellidos'])) {
            SyncClientWithMikrotikJob::dispatch($client->id);
        }
    }
}
