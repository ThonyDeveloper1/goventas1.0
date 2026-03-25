<?php

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\MikrotikIspService;
use App\Models\MikrotikRouter;

$isp = app(MikrotikIspService::class);
$routerId = MikrotikRouter::where('is_active', true)->value('id');

if (! $routerId) {
    echo "No hay router activo en BD.\n";
    exit(1);
}

echo "Usando router activo ID={$routerId}\n";

$morosos = [];

echo "=== 1. CORTE MOROSO entries ===\n";
try {
    $morosos = $isp->getMoresoFromRouter((int) $routerId);
    echo "Total: " . count($morosos) . "\n";
    foreach (array_slice($morosos, 0, 10) as $m) {
        echo "  IP: {$m['address']} | ClientId: " . ($m['clientId'] ?? 'null') . "\n";
    }
    if (count($morosos) > 10) {
        echo "  ... y " . (count($morosos) - 10) . " mas\n";
    }
} catch (\Throwable $e) {
    echo "ERROR morosos: " . $e->getMessage() . "\n";
}

echo "\n=== 2. PPP Secrets ===\n";
try {
    $secrets = $isp->getSecrets((int) $routerId);
    echo "Total: " . count($secrets) . "\n";
    foreach (array_slice($secrets, 0, 20) as $s) {
        echo "  Name: {$s['name']} | RemoteAddr: " . ($s['remoteAddress'] ?? 'null') . " | Comment: " . ($s['comment'] ?? 'null') . " | Disabled: " . ($s['disabled'] ? 'YES' : 'NO') . "\n";
    }
    if (count($secrets) > 20) echo "  ... and " . (count($secrets) - 20) . " more\n";
} catch (\Throwable $e) {
    echo "ERROR secrets: " . $e->getMessage() . "\n";
}

echo "\n=== 3. DB Clients ===\n";
$clients = App\Models\Client::select('id', 'nombres', 'apellidos', 'mikrotik_user', 'ip_address', 'estado', 'mikrotik_router_id')->get();

$morosoIps = [];
foreach ($morosos as $m) {
    if (! empty($m['address'])) {
        $morosoIps[] = trim((string) $m['address']);
    }
}

foreach ($clients as $c) {
    $ip = trim((string) ($c->ip_address ?? ''));
    $inCorte = ($ip !== '' && in_array($ip, $morosoIps, true)) ? 'YES' : 'NO';
    echo "  ID:{$c->id} | {$c->nombres} {$c->apellidos} | User: " . ($c->mikrotik_user ?? 'null') . " | IP: " . ($c->ip_address ?? 'null') . " | Estado: {$c->estado} | RouterID: " . ($c->mikrotik_router_id ?? 'null') . " | InCORTEByIP: {$inCorte}\n";
}

echo "\n=== 4. Snapshot MikroTik (vista clientes) ===\n";
try {
    $snapshot = $isp->buildClientMikrotikSnapshot((int) $routerId, $clients);
    foreach ($clients as $c) {
        $s = $snapshot[$c->id] ?? ['status' => 'sin_datos', 'ip' => null];
        echo "  ID:{$c->id} | SnapshotStatus={$s['status']} | SnapshotIP=" . ($s['ip'] ?? 'null') . "\n";
    }
} catch (\Throwable $e) {
    echo "ERROR snapshot: " . $e->getMessage() . "\n";
}
