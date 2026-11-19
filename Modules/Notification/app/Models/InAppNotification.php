<?php

declare(strict_types=1);

namespace Modules\Notification\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Auth\app\Models\User;
use Modules\Notification\app\Events\InAppNotificationCreated;
use Throwable;

class InAppNotification extends Model
{
    use HasFactory;

    protected $table = 'in_app_notifications';

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'title',
        'message',
        'action_url',
        'icon',
        'data',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (InAppNotification $notification): void {
            if (empty($notification->uuid)) {
                $notification->uuid = (string) Str::uuid();
            }
        });

        static::created(function (InAppNotification $notification): void {
            try {
                $userUuid = $notification->relationLoaded('user')
                    ? $notification->user?->uuid
                    : User::where('id', $notification->user_id)->value('uuid');

                if (! empty($userUuid)) {
                    event(new InAppNotificationCreated($notification, (string) $userUuid));
                }
            } catch (Throwable $e) {
                Log::warning("WebSocket broadcast skipped for notification [{$notification->uuid}]: {$e->getMessage()}");
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): bool
    {
        if ($this->read_at !== null) {
            return true;
        }

        return $this->update(['read_at' => now()]);
    }
}
