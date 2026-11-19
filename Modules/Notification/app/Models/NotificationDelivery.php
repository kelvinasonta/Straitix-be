<?php

declare(strict_types=1);

namespace Modules\Notification\app\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Modules\Auth\app\Models\User;

class NotificationDelivery extends Model
{
    use HasFactory;

    protected $table = 'notification_deliveries';

    protected $fillable = [
        'uuid',
        'user_id',
        'in_app_notification_id',
        'type',
        'channel',
        'recipient_target',
        'status',
        'error_message',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (NotificationDelivery $delivery): void {
            if (empty($delivery->uuid)) {
                $delivery->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inAppNotification(): BelongsTo
    {
        return $this->belongsTo(InAppNotification::class, 'in_app_notification_id');
    }
}
