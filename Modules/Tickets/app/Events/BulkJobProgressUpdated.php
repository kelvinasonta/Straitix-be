<?php

namespace Modules\Tickets\app\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class BulkJobProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $organizerUuid,
        public string $jobId,
        public string $jobType,
        public string $status,
        public int $total,
        public int $processed,
        public int $sent = 0,
        public int $failed = 0,
        public ?string $eventUuid = null,
        public ?string $message = null
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("organizers.{$this->organizerUuid}.jobs"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'bulk.job.progress';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $percentage = $this->total > 0
            ? min(100, (int) round(($this->processed / $this->total) * 100))
            : 100;

        return [
            'job_id' => $this->jobId,
            'job_type' => $this->jobType,
            'organizer_uuid' => $this->organizerUuid,
            'event_uuid' => $this->eventUuid,
            'status' => $this->status,
            'total' => $this->total,
            'processed' => $this->processed,
            'sent' => $this->sent,
            'failed' => $this->failed,
            'percentage' => $percentage,
            'message' => $this->message,
        ];
    }
}
