<?php

namespace App\Jobs;

use App\Models\MikrotikRouter;
use App\Services\MikrotikIspService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAllMorososJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 30;

    public function handle(MikrotikIspService $service): void
    {
        $routers = MikrotikRouter::where('is_active', true)->get();

        foreach ($routers as $router) {
            try {
                $result = $service->syncMorososToDb($router->id);
                Log::info("[SyncAllMorosos] Router \"{$router->name}\": {$result['message']}");
            } catch (\Throwable $e) {
                Log::error("[SyncAllMorosos] Router \"{$router->name}\": {$e->getMessage()}");
            }
        }
    }
}
