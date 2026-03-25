<?php

namespace App\Jobs;

use App\Models\Client;
use App\Services\MikrotikIspService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncClientWithMikrotikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 10;

    public function __construct(
        public readonly int $clientId,
    ) {}

    public function handle(MikrotikIspService $service): void
    {
        $client = Client::find($this->clientId);

        if (! $client) {
            Log::warning("[SyncClientWithMikrotik] Client ID {$this->clientId} not found");
            return;
        }

        try {
            $result = $service->syncSingleClient($client);

            if ($result['matched']) {
                Log::info("[SyncClientWithMikrotik] {$client->nombre_completo}: IP={$result['ip']}, estado={$result['estado']}");
            } else {
                Log::debug("[SyncClientWithMikrotik] {$client->nombre_completo}: {$result['reason']}");
            }
        } catch (\Throwable $e) {
            Log::error("[SyncClientWithMikrotik] Error syncing {$client->nombre_completo}: {$e->getMessage()}");
        }
    }
}
