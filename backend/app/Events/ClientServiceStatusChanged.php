<?php

namespace App\Events;

use App\Models\Client;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ClientServiceStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Client $client,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new Channel('network-status'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'service.status.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'client_id'       => $this->client->id,
            'nombres'         => $this->client->nombres,
            'apellidos'       => $this->client->apellidos,
            'dni'             => $this->client->dni,
            'mikrotik_user'   => $this->client->mikrotik_user,
            'previous_status' => $this->previousStatus,
            'new_status'      => $this->newStatus,
            'action'          => $this->action,
            'timestamp'       => now()->toISOString(),
        ];
    }
}
