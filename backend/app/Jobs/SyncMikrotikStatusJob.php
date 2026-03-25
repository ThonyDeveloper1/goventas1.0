<?php

namespace App\Jobs;

use App\Events\ClientServiceStatusChanged;
use App\Models\Client;
use App\Services\MikrotikService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncMikrotikStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function handle(MikrotikService $mikrotik): void
    {
        $clients = Client::whereNotNull('mikrotik_user')
            ->select('id', 'mikrotik_user', 'service_status')
            ->get();

        if ($clients->isEmpty()) {
            return;
        }

        $usernames = $clients->pluck('mikrotik_user')->toArray();

        try {
            $statuses = $mikrotik->getBatchStatus($usernames);
        } catch (\Throwable $e) {
            Log::warning('MikroTik sync failed', ['error' => $e->getMessage()]);
            return;
        }

        $statusMap = [];
        foreach ($statuses as $s) {
            $statusMap[$s['username']] = $s['status'] ?? null;
        }

        $updated = 0;

        DB::transaction(function () use ($clients, $statusMap, &$updated) {
            foreach ($clients as $client) {
                $routerStatus = $statusMap[$client->mikrotik_user] ?? null;

                if (
                    $routerStatus
                    && $routerStatus !== 'no_encontrado'
                    && $routerStatus !== $client->service_status
                ) {
                    $previous = $client->service_status;
                    $client->update(['service_status' => $routerStatus]);
                    event(new ClientServiceStatusChanged($client, $previous, $routerStatus, 'auto_sync'));
                    $updated++;
                }
            }
        });

        Log::info("MikroTik sync completed: {$updated}/{$clients->count()} updated.");
    }
}
