<?php

declare(strict_types=1);

namespace Modules\Tickets\app\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Audit\app\Services\AuditService;
use Modules\Auth\app\Models\User;
use Modules\Credits\app\Enums\CreditTxReason;
use Modules\Credits\app\Services\CreditLedgerService;
use Modules\Events\app\Models\Event;
use Modules\Notification\app\Models\EmailTemplate;
use Modules\Notification\app\Services\MailerService;
use Modules\Organizer\app\Models\Organizer;
use Modules\Tickets\app\Events\AttendeeCheckedIn;
use Modules\Tickets\app\Events\BulkJobProgressUpdated;
use Modules\Tickets\app\Events\CheckInUndone;
use Modules\Tickets\app\Exceptions\TicketCheckInException;
use Modules\Tickets\app\Models\Ticket;
use Throwable;

class AttendeeService
{
    public function __construct(
        protected ?CreditLedgerService $creditLedgerService = null,
        protected ?MailerService $mailerService = null,
        protected ?AuditService $auditService = null,
    ) {}
    /**
     * Get paginated attendee tickets for an event.
     */
    public function getEventAttendees(Organizer $organizer, string $eventUuid, array $filters = []): LengthAwarePaginator
    {
        $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();

        $query = Ticket::with(['ticketType', 'user', 'order'])
            ->where('event_id', $event->id)
            ->latest();

        if (! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($search) {
                $q->where('ticket_number', 'like', $search)
                    ->orWhere('attendee_name', 'like', $search)
                    ->orWhere('attendee_email', 'like', $search);
            });
        }

        if (isset($filters['checked_in']) && $filters['checked_in'] !== '') {
            $checkedIn = filter_var($filters['checked_in'], FILTER_VALIDATE_BOOLEAN);
            if ($checkedIn) {
                $query->whereNotNull('checked_in_at');
            } else {
                $query->whereNull('checked_in_at');
            }
        }

        if (! empty($filters['ticket_type_uuid'])) {
            $query->whereHas('ticketType', function ($q) use ($filters) {
                $q->where('uuid', $filters['ticket_type_uuid']);
            });
        }

        $perPage = min(max((int) ($filters['per_page'] ?? $filters['perPage'] ?? 15), 1), 100);

        return $query->paginate($perPage);
    }

    /**
     * Export attendees as CSV.
     */
    public function exportAttendeesCsv(Organizer $organizer, string $eventUuid): string
    {
        $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();

        $tickets = Ticket::with(['ticketType', 'order'])
            ->where('event_id', $event->id)
            ->latest()
            ->get();

        $totalTickets = $tickets->count();
        $exportJobId = 'EXP-'.strtoupper(Str::random(10));

        $this->emitBulkJobProgress(
            organizerUuid: (string) $organizer->uuid,
            jobId: $exportJobId,
            jobType: 'attendee_export',
            status: 'running',
            total: $totalTickets,
            processed: 0,
            sent: 0,
            failed: 0,
            eventUuid: (string) $event->uuid,
            message: "Preparing CSV export for {$totalTickets} attendee(s)..."
        );

        $output = fopen('php://temp', 'r+');
        fputcsv($output, [
            'Ticket Number',
            'Attendee Name',
            'Attendee Email',
            'Ticket Tier',
            'Price Paid',
            'Currency',
            'Status',
            'Checked In',
            'Checked In At',
            'Order Number',
        ]);

        foreach ($tickets as $ticket) {
            fputcsv($output, [
                $ticket->ticket_number,
                $ticket->attendee_name ?: 'N/A',
                $ticket->attendee_email ?: 'N/A',
                $ticket->ticketType ? $ticket->ticketType->name : 'General',
                $ticket->price_paid,
                $ticket->currency,
                $ticket->status,
                $ticket->checked_in_at ? 'Yes' : 'No',
                $ticket->checked_in_at ? $ticket->checked_in_at->toIso8601String() : '',
                $ticket->order ? $ticket->order->order_number : 'N/A',
            ]);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $this->emitBulkJobProgress(
            organizerUuid: (string) $organizer->uuid,
            jobId: $exportJobId,
            jobType: 'attendee_export',
            status: 'completed',
            total: $totalTickets,
            processed: $totalTickets,
            sent: $totalTickets,
            failed: 0,
            eventUuid: (string) $event->uuid,
            message: "Exported {$totalTickets} attendee(s) to CSV."
        );

        return $csv ?: '';
    }

    /**
     * Check in an attendee using ticket number or QR code string.
     */
    public function checkInAttendee(Organizer $organizer, string $eventUuid, string $identifier): Ticket
    {
        $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();

        $ticket = Ticket::with(['ticketType', 'user', 'order'])
            ->where('event_id', $event->id)
            ->where(function ($q) use ($identifier) {
                $q->where('ticket_number', $identifier)
                    ->orWhere('qr_code', $identifier);
            })
            ->first();

        if (! $ticket) {
            throw new TicketCheckInException('Invalid ticket for this event.');
        }

        if (in_array(strtolower($ticket->status), ['cancelled', 'refunded', 'void'])) {
            throw new TicketCheckInException("Ticket is invalid or {$ticket->status}.");
        }

        if ($ticket->checked_in_at !== null) {
            $formattedTime = $ticket->checked_in_at->format('M d, Y h:i A');
            throw new TicketCheckInException("Ticket was already checked in at {$formattedTime}.");
        }

        $ticket->update([
            'checked_in_at' => now(),
            'status' => 'used',
        ]);

        $freshTicket = $ticket->fresh(['ticketType', 'user', 'order']);

        try {
            $stats = $this->getEventGateStats($organizer, $eventUuid);
            event(new AttendeeCheckedIn((string) $event->uuid, $freshTicket, $stats));
        } catch (Throwable $e) {
            Log::warning("Failed to broadcast AttendeeCheckedIn for event [{$event->uuid}]: {$e->getMessage()}");
        }

        return $freshTicket;
    }

    /**
     * Undo check-in for an attendee using ticket number, QR code, or ticket uuid.
     */
    public function undoCheckInAttendee(Organizer $organizer, string $eventUuid, string $identifier): Ticket
    {
        $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();

        $ticket = Ticket::with(['ticketType', 'user', 'order'])
            ->where('event_id', $event->id)
            ->where(function ($q) use ($identifier) {
                $q->where('ticket_number', $identifier)
                    ->orWhere('qr_code', $identifier)
                    ->orWhere('uuid', $identifier);
            })
            ->first();

        if (! $ticket) {
            throw new TicketCheckInException('Invalid ticket for this event.');
        }

        if (in_array(strtolower($ticket->status), ['cancelled', 'refunded', 'void'])) {
            throw new TicketCheckInException("Ticket is invalid or {$ticket->status}.");
        }

        if ($ticket->checked_in_at === null) {
            throw new TicketCheckInException('Ticket is not currently checked in.');
        }

        $ticket->update([
            'checked_in_at' => null,
            'status' => 'valid',
        ]);

        $freshTicket = $ticket->fresh(['ticketType', 'user', 'order']);

        try {
            $stats = $this->getEventGateStats($organizer, $eventUuid);
            event(new CheckInUndone((string) $event->uuid, $freshTicket, $stats));
        } catch (Throwable $e) {
            Log::warning("Failed to broadcast CheckInUndone for event [{$event->uuid}]: {$e->getMessage()}");
        }

        return $freshTicket;
    }

    /**
     * Get live gate check-in statistics for an event.
     *
     * @return array{
     *     total_tickets: int,
     *     checked_in_count: int,
     *     pending_count: int,
     *     check_in_rate: float,
     *     recent_check_ins: array<int, array{
     *         ticket_number: string,
     *         attendee_name: string|null,
     *         attendee_email: string|null,
     *         ticket_tier: string,
     *         checked_in_at: string|null
     *     }>
     * }
     */
    public function getEventGateStats(Organizer $organizer, string $eventUuid): array
    {
        $event = Event::where('uuid', $eventUuid)->where('organizer_id', $organizer->id)->firstOrFail();

        $query = Ticket::where('event_id', $event->id);

        $totalTickets = (clone $query)->count();
        $checkedInCount = (clone $query)->whereNotNull('checked_in_at')->count();
        $pendingCount = (clone $query)->whereNull('checked_in_at')->count();

        $checkInRate = $totalTickets > 0
            ? round(($checkedInCount / $totalTickets) * 100, 2)
            : 0.0;

        $recentCheckIns = Ticket::with('ticketType')
            ->where('event_id', $event->id)
            ->whereNotNull('checked_in_at')
            ->orderByDesc('checked_in_at')
            ->limit(5)
            ->get()
            ->map(fn (Ticket $ticket) => [
                'ticket_number' => $ticket->ticket_number,
                'attendee_name' => $ticket->attendee_name,
                'attendee_email' => $ticket->attendee_email,
                'ticket_tier' => $ticket->ticketType?->name ?? 'General',
                'checked_in_at' => $ticket->checked_in_at?->toISOString(),
            ])
            ->values()
            ->all();

        return [
            'total_tickets' => $totalTickets,
            'checked_in_count' => $checkedInCount,
            'pending_count' => $pendingCount,
            'check_in_rate' => $checkInRate,
            'recent_check_ins' => $recentCheckIns,
        ];
    }

    /**
     * Broadcast an announcement email to event ticket holders using the two-phase credit reservation ledger.
     *
     * @return array<string, mixed>
     */
    public function broadcastEmail(
        Organizer $organizer,
        string $eventUuid,
        string $subject,
        string $message,
        ?string $ticketTypeUuid = null,
        ?User $actor = null
    ): array {
        $event = Event::where('uuid', $eventUuid)
            ->where('organizer_id', $organizer->id)
            ->firstOrFail();

        $ticketsQuery = Ticket::with(['order', 'ticketType'])
            ->where('event_id', $event->id)
            ->whereNotIn('status', ['cancelled', 'refunded', 'void']);

        if (! empty($ticketTypeUuid) && $ticketTypeUuid !== 'all') {
            $ticketsQuery->whereHas('ticketType', function ($q) use ($ticketTypeUuid): void {
                $q->where('uuid', $ticketTypeUuid)
                    ->orWhere('name', $ticketTypeUuid);
            });
        }

        $tickets = $ticketsQuery->get();

        /** @var array<string, string> $recipients */
        $recipients = [];
        foreach ($tickets as $ticket) {
            $email = strtolower(trim((string) ($ticket->attendee_email ?: ($ticket->order?->customer_email ?? ''))));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                if (! isset($recipients[$email])) {
                    $recipients[$email] = $ticket->attendee_name ?: ($ticket->order?->customer_name ?: 'Attendee');
                }
            }
        }

        if (empty($recipients)) {
            throw new InvalidArgumentException('No registered attendees with valid email addresses found for the selected audience.');
        }

        $ledgerService = $this->creditLedgerService ?? app(CreditLedgerService::class);
        $mailer = $this->mailerService ?? app(MailerService::class);
        $audit = $this->auditService ?? app(AuditService::class);

        $requiredCredits = count($recipients);
        $wallet = $ledgerService->getOrCreateWallet($organizer);
        $campaignRef = 'BRC-'.strtoupper(Str::random(10));

        // Phase 1: Reserve credits for all recipients
        $reservation = $ledgerService->reserveCredits(
            wallet: $wallet,
            amount: $requiredCredits,
            referenceType: 'EMAIL_BROADCAST',
            referenceId: $campaignRef,
            ttlMinutes: 60
        );

        EmailTemplate::firstOrCreate(
            ['slug' => 'event_attendee_broadcast'],
            [
                'subject' => '📢 {{broadcastSubject}} — {{eventTitle}}',
                'body' => '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 24px; color: #1e293b; background-color: #ffffff; border-radius: 8px; border: 1px solid #e2e8f0;">
    <div style="margin-bottom: 20px; border-bottom: 1px solid #e2e8f0; padding-bottom: 16px;">
        <span style="display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: #e31b23; margin-bottom: 6px;">Event Announcement · {{organizerName}}</span>
        <h1 style="color: #0f172a; font-size: 20px; font-weight: 700; margin: 0;">{{broadcastSubject}}</h1>
        <p style="color: #64748b; font-size: 13px; margin: 4px 0 0 0;">Regarding: <strong>{{eventTitle}}</strong></p>
    </div>
    <div style="background-color: #f8fafc; border-radius: 6px; padding: 20px; margin-bottom: 24px; border: 1px solid #f1f5f9;">
        <p style="font-size: 15px; line-height: 1.6; margin: 0 0 12px 0;">Hi <strong>{{attendeeName}}</strong>,</p>
        <div style="font-size: 15px; line-height: 1.7; color: #1e293b; white-space: pre-line;">{{broadcastMessage}}</div>
    </div>
    <div style="text-align: center; margin: 24px 0;">
        <a href="{{eventUrl}}" style="display: inline-block; background-color: #0f172a; color: #ffffff; text-decoration: none; font-weight: 600; font-size: 14px; padding: 12px 24px; border-radius: 6px;">View Event Details</a>
    </div>
    <div style="margin-top: 24px; font-size: 13px; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 16px;">
        <p style="margin: 0;">Sent by <strong>{{organizerName}}</strong> via {{siteName}}</p>
    </div>
</div>',
                'type' => 'html',
                'is_active' => true,
            ]
        );

        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:5173'), '/');
        $eventUrl = "{$frontendUrl}/events/{$event->slug}";
        $siteName = (string) config('app.name', 'EventsOS');

        $sentCount = 0;
        $failedCount = 0;
        $progressStep = min(25, max(1, (int) ceil($requiredCredits / 20)));

        $this->emitBulkJobProgress(
            organizerUuid: (string) $organizer->uuid,
            jobId: $campaignRef,
            jobType: 'email_broadcast',
            status: 'running',
            total: $requiredCredits,
            processed: 0,
            sent: 0,
            failed: 0,
            eventUuid: (string) $event->uuid,
            message: "Starting email broadcast to {$requiredCredits} attendee(s)..."
        );

        foreach ($recipients as $email => $attendeeName) {
            try {
                $mailer->send(
                    to: $email,
                    slug: 'event_attendee_broadcast',
                    data: [
                        'attendeeName' => $attendeeName,
                        'broadcastSubject' => $subject,
                        'broadcastMessage' => $message,
                        'eventTitle' => $event->title,
                        'organizerName' => $organizer->name,
                        'eventUrl' => $eventUrl,
                        'siteName' => $siteName,
                    ],
                    replyToEmail: $organizer->email ?: null,
                    replyToName: $organizer->name
                );
                $sentCount++;
            } catch (Throwable $e) {
                $failedCount++;
                Log::warning("Broadcast email delivery failed for [{$email}] on event [{$event->id}]: {$e->getMessage()}");
            }

            $processedCount = $sentCount + $failedCount;
            if ($processedCount < $requiredCredits && $processedCount % $progressStep === 0) {
                $this->emitBulkJobProgress(
                    organizerUuid: (string) $organizer->uuid,
                    jobId: $campaignRef,
                    jobType: 'email_broadcast',
                    status: 'running',
                    total: $requiredCredits,
                    processed: $processedCount,
                    sent: $sentCount,
                    failed: $failedCount,
                    eventUuid: (string) $event->uuid,
                    message: "Sent {$sentCount} of {$requiredCredits} email(s)..."
                );
            }
        }

        // Phase 2: Settle reservation for actual delivered emails (or release if all failed)
        if ($sentCount > 0) {
            $ledgerService->settleReservation(
                reservation: $reservation,
                actualConsumedAmount: $sentCount,
                reason: CreditTxReason::EMAIL_BROADCAST,
                idempotencyKey: "broadcast_{$campaignRef}",
                reference: $campaignRef,
                description: "Email broadcast '{$subject}' to {$sentCount} attendee(s) for {$event->title}",
                metadata: [
                    'event_id' => $event->id,
                    'event_uuid' => $event->uuid,
                    'subject' => $subject,
                    'recipients_total' => $requiredCredits,
                    'sent_count' => $sentCount,
                    'failed_count' => $failedCount,
                    'ticket_type_uuid' => $ticketTypeUuid,
                ],
                event: $event
            );
        } else {
            $ledgerService->releaseReservation($reservation, 'All broadcast email deliveries failed');
        }

        $audit->log(
            action: 'event.email_broadcast_sent',
            description: "Sent email broadcast '{$subject}' to {$sentCount} attendee(s) for event '{$event->title}'.",
            organizer: $organizer,
            user: $actor,
            metadata: [
                'campaign_reference' => $campaignRef,
                'event_uuid' => $event->uuid,
                'subject' => $subject,
                'recipients_count' => $requiredCredits,
                'sent_count' => $sentCount,
                'failed_count' => $failedCount,
                'credits_charged' => $sentCount,
            ],
            targetType: Event::class,
            targetId: (string) $event->id
        );

        $freshWallet = $wallet->fresh();

        $this->emitBulkJobProgress(
            organizerUuid: (string) $organizer->uuid,
            jobId: $campaignRef,
            jobType: 'email_broadcast',
            status: 'completed',
            total: $requiredCredits,
            processed: $requiredCredits,
            sent: $sentCount,
            failed: $failedCount,
            eventUuid: (string) $event->uuid,
            message: "Broadcast completed: {$sentCount} sent, {$failedCount} failed."
        );

        return [
            'reference' => $campaignRef,
            'recipients_count' => $requiredCredits,
            'sent_count' => $sentCount,
            'failed_count' => $failedCount,
            'credits_charged' => $sentCount,
            'available_balance' => (int) $freshWallet->available_balance,
            'balance' => (int) $freshWallet->balance,
        ];
    }

    private function emitBulkJobProgress(
        string $organizerUuid,
        string $jobId,
        string $jobType,
        string $status,
        int $total,
        int $processed,
        int $sent,
        int $failed,
        ?string $eventUuid = null,
        ?string $message = null
    ): void {
        try {
            event(new BulkJobProgressUpdated(
                organizerUuid: $organizerUuid,
                jobId: $jobId,
                jobType: $jobType,
                status: $status,
                total: $total,
                processed: $processed,
                sent: $sent,
                failed: $failed,
                eventUuid: $eventUuid,
                message: $message
            ));
        } catch (Throwable $e) {
            Log::warning("Failed to broadcast BulkJobProgressUpdated [{$jobId}]: {$e->getMessage()}");
        }
    }
}
