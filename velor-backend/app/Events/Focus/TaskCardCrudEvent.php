<?php

namespace App\Events\Focus;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class TaskCardCrudEvent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    private string $eventId;

    private string $occurredAtUtc;

    public function __construct(
        private readonly int $userId,
        public readonly string $eventName,
        public readonly array $task,
        private readonly string $originDeviceId = 'server',
        string $eventId = '',
        string $occurredAtUtc = '',
    ) {
        $this->eventId = $eventId !== '' ? $eventId : 'evt_' . Str::lower((string) Str::ulid());
        $this->occurredAtUtc = $occurredAtUtc !== '' ? $occurredAtUtc : now('UTC')->toISOString();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("user.{$this->userId}.focus.tasks")];
    }

    public function broadcastAs(): string
    {
        return $this->eventName;
    }

    public function broadcastWith(): array
    {
        return [
            'event_id' => $this->eventId,
            'origin_device_id' => $this->originDeviceId,
            'occurred_at_utc' => $this->occurredAtUtc,
            'event' => $this->eventName,
            'task' => $this->task,
        ];
    }
}
