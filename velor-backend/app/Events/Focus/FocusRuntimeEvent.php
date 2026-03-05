<?php

namespace App\Events\Focus;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class FocusRuntimeEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private string $type;

    private string $eventId;

    private string $emittedAtUtc;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly int $userId,
        string $type,
        public readonly array $data,
        private readonly string $originDeviceId = 'server',
        string $eventId = '',
        string $emittedAtUtc = '',
    ) {
        $this->type = ltrim($type, '.');
        $this->eventId = $eventId !== '' ? $eventId : 'evt_' . Str::lower((string) Str::ulid());
        $this->emittedAtUtc = $emittedAtUtc !== '' ? $emittedAtUtc : now('UTC')->toISOString();
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("user.{$this->userId}.focus"),
        ];
    }

    public function broadcastAs(): string
    {
        return $this->type;
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'meta' => [
                'user_id' => (string) $this->userId,
                'event_id' => $this->eventId,
                'emitted_at_utc' => $this->emittedAtUtc,
                'origin_device_id' => $this->originDeviceId,
            ],
            'data' => $this->data,
        ];
    }
}

