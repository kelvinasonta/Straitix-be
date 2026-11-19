<?php

declare(strict_types=1);

namespace Modules\Payments\app\Services\Mail;

use Illuminate\Support\Facades\Log;
use Modules\Notification\app\Models\EmailTemplate;
use Modules\Notification\app\Services\MailerService;
use Modules\Payments\app\Models\Order;
use Throwable;

class SendOrderRefundedEmailService
{
    public function __construct(
        private MailerService $mailerService
    ) {}

    /**
     * Send refund confirmation to the ticket buyer/attendee.
     */
    public function execute(Order $order, ?string $reason = null, ?float $amount = null): void
    {
        if (empty($order->customer_email)) {
            return;
        }

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $order->loadMissing(['event']);

        $event = $order->event;
        $refundAmount = $amount ?? (float) $order->total;

        $payload = [
            'name' => $order->customer_name ?? 'Valued Attendee',
            'customerName' => $order->customer_name ?? 'Valued Attendee',
            'orderNumber' => $order->order_number,
            'eventTitle' => $event?->title ?? 'Your Event',
            'refundAmount' => strtoupper((string) $order->currency).' '.number_format($refundAmount, 2),
            'reason' => $reason ?: 'Requested by organizer / attendee',
            'viewOrderUrl' => "{$frontendUrl}/orders/{$order->uuid}",
            'siteName' => (string) config('app.name', 'EventsOS'),
        ];

        try {
            // Ensure email template exists in database
            EmailTemplate::firstOrCreate(
                ['slug' => 'order_refunded'],
                [
                    'subject' => '↩️ Refund Processed for Order #{{orderNumber}} - {{eventTitle}}',
                    'body' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #1e293b; background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0;">
    <div style="text-align: center; margin-bottom: 24px;">
        <h1 style="color: #0f172a; font-size: 24px; font-weight: 700; margin: 0 0 8px 0;">↩️ Order Refunded</h1>
        <p style="color: #64748b; font-size: 16px; margin: 0;">Order #{{orderNumber}} has been refunded</p>
    </div>
    <div style="background-color: #f8fafc; border-radius: 6px; padding: 20px; margin-bottom: 24px; border: 1px solid #f1f5f9;">
        <p style="font-size: 15px; line-height: 1.6; margin: 0 0 12px 0;">Hi <strong>{{customerName}}</strong>,</p>
        <p style="font-size: 15px; line-height: 1.6; margin: 0 0 16px 0;">Your order for <strong>"{{eventTitle}}"</strong> has been refunded. Any tickets issued under this order have been cancelled and voided.</p>
        <div style="margin: 16px 0; padding: 14px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 14px; line-height: 1.8;">
            <div>📦 <strong>Order Number:</strong> #{{orderNumber}}</div>
            <div>💳 <strong>Refund Amount:</strong> {{refundAmount}}</div>
            <div>📝 <strong>Reason:</strong> {{reason}}</div>
        </div>
    </div>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{{viewOrderUrl}}" style="display: inline-block; background-color: #4f46e5; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 15px; padding: 12px 24px; border-radius: 6px;">View Order Status</a>
    </div>
    <div style="margin-top: 24px; font-size: 14px; color: #334155;">
        <p style="margin: 0;">Best regards,<br><strong>The {{siteName}} Team</strong></p>
    </div>
</div>',
                    'type' => 'html',
                    'is_active' => true,
                ]
            );

            $this->mailerService->send($order->customer_email, 'order_refunded', $payload);
        } catch (Throwable $e) {
            Log::error("Failed to send order refunded email for order [{$order->id}]: {$e->getMessage()}");
        }
    }
}
