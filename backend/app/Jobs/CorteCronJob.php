<?php

namespace App\Jobs;

use App\Models\Client;
use App\Models\MikrotikRouter;
use App\Services\MikrotikIspService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Daily CRON (2:00 AM America/Lima):
 * Find clients past fecha_vencimiento with estado=activo,
 * suspend PPPoE on MikroTik, mark estado=moroso.
 */
class CorteCronJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $backoff = 60;

    public function handle(MikrotikIspService $service): void
    {
        $today = Carbon::now('America/Lima')->startOfDay();

        // Find clients: past due, still activo, with PPPoE configured
        $clients = Client::where('estado', 'activo')
            ->whereNotNull('fecha_vencimiento')
            ->where('fecha_vencimiento', '<', $today)
            ->whereNotNull('mikrotik_user')
            ->whereNotNull('mikrotik_router_id')
            ->get();

        if ($clients->isEmpty()) {
            Log::info('[CorteCron] No clients overdue. Nothing to do.');
            return;
        }

        $suspended = 0;
        $errors    = 0;

        foreach ($clients as $client) {
            try {
                // Suspend PPPoE on MikroTik
                $service->suspendUser($client->mikrotik_router_id, $client->mikrotik_user);

                // Update DB
                $client->updateQuietly([
                    'estado'         => 'moroso',
                    'service_status' => 'suspendido',
                ]);

                $suspended++;
                Log::info("[CorteCron] Suspended \"{$client->nombre_completo}\" (#{$client->id}) — vencido {$client->fecha_vencimiento}");
            } catch (\Throwable $e) {
                $errors++;
                Log::error("[CorteCron] Failed to suspend #{$client->id} ({$client->nombre_completo}): {$e->getMessage()}");
            }
        }

        Log::info("[CorteCron] Complete: {$suspended} suspended, {$errors} errors, {$clients->count()} total overdue.");
    }
}
