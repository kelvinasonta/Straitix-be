<?php

namespace Modules\Events\app\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Modules\Tickets\app\Transformers\TicketTypeResource;

class EventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $schedule = $this->relationLoaded('schedule') ? $this->schedule : null;
        $location = $this->relationLoaded('location') ? $this->location : null;

        $startDate = $schedule?->start_date;
        $endDate = $schedule?->end_date;
        $recurringEndDate = $schedule?->recurring_end_date;

        $startDateStr = $startDate instanceof \DateTimeInterface ? $startDate->format('Y-m-d') : ($startDate ? (string) $startDate : null);
        $endDateStr = $endDate instanceof \DateTimeInterface ? $endDate->format('Y-m-d') : ($endDate ? (string) $endDate : null);
        $recurringEndDateStr = $recurringEndDate instanceof \DateTimeInterface ? $recurringEndDate->format('Y-m-d') : ($recurringEndDate ? (string) $recurringEndDate : null);

        $startTimeStr = $schedule?->start_time;
        $endTimeStr = $schedule?->end_time;
        if ($startTimeStr && strlen((string) $startTimeStr) > 5) {
            $startTimeStr = substr((string) $startTimeStr, 0, 5);
        }
        if ($endTimeStr && strlen((string) $endTimeStr) > 5) {
            $endTimeStr = substr((string) $endTimeStr, 0, 5);
        }

        $scheduleItemsRel = $this->relationLoaded('scheduleItems') ? $this->getRelation('scheduleItems') : null;
        $schedulesData = ($scheduleItemsRel instanceof Collection && $scheduleItemsRel->isNotEmpty())
            ? $scheduleItemsRel->map(fn ($item) => [
                'id' => $item->uuid ?? (string) $item->id,
                'day' => $item->schedule_date ? ('Date: '.$item->schedule_date) : 'Day 1',
                'title' => $item->title,
                'description' => $item->description,
                'startTime' => $item->start_time ? substr((string) $item->start_time, 0, 5) : '09:00',
                'endTime' => $item->end_time ? substr((string) $item->end_time, 0, 5) : '10:00',
            ])->values()->all()
            : [];

        $lineupRel = $this->relationLoaded('lineup') ? $this->getRelation('lineup') : null;
        $lineupData = ($lineupRel instanceof Collection && $lineupRel->isNotEmpty())
            ? $lineupRel->map(fn ($item) => [
                'id' => $item->uuid ?? (string) $item->id,
                'name' => $item->name,
                'role' => $item->role,
                'bio' => $item->bio,
                'avatarUrl' => $item->avatarUrl,
                'links' => $item->links ?? [],
            ])->values()->all()
            : [];

        $settingsRel = $this->relationLoaded('settings') ? $this->getRelation('settings') : null;
        $customQuestionsRel = $this->relationLoaded('customQuestions') ? $this->getRelation('customQuestions') : null;
        $promotersRel = $this->relationLoaded('promoters') ? $this->getRelation('promoters') : null;

        $customQuestionsData = ($customQuestionsRel instanceof Collection && $customQuestionsRel->isNotEmpty())
            ? $customQuestionsRel->map(fn ($item) => [
                'id' => $item->uuid ?? (string) $item->id,
                'question' => $item->question,
                'type' => $item->type,
                'options' => $item->options,
                'isRequired' => (bool) $item->is_required,
                'sortOrder' => (int) $item->sort_order,
            ])->values()->all()
            : [];

        $promotersData = ($promotersRel instanceof Collection && $promotersRel->isNotEmpty())
            ? $promotersRel->map(fn ($item) => [
                'id' => $item->uuid ?? (string) $item->id,
                'code' => $item->code,
                'discount' => (float) $item->discount,
                'discountType' => $item->discount_type,
                'uses' => (int) $item->uses,
                'sales' => (float) $item->sales,
                'maxUses' => $item->max_uses,
                'status' => $item->status,
                'sortOrder' => (int) $item->sort_order,
            ])->values()->all()
            : [];

        $faqs = $settingsRel?->faqs ?? $this->meta['faqs'] ?? [];
        $galleryUrls = $settingsRel?->gallery_urls ?? $this->meta['galleryUrls'] ?? [];

        return [
            'id' => $this->uuid,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'eventCountry' => $this->event_country,
            'country' => $this->event_country,
            'eventType' => $this->event_type?->value ?? $this->event_type,
            'eventFormat' => $this->event_format,
            'eventCategory' => $this->event_category ?? [],
            'venueName' => $location?->venue_name,
            'city' => $location?->city,
            'state' => $location?->state,
            'startDate' => $startDateStr,
            'startTime' => $startTimeStr,
            'endDate' => $endDateStr,
            'endTime' => $endTimeStr,
            'location' => [
                'eventLocationId' => $this->event_location_id,
                'venueName' => $location?->venue_name,
                'venueAddress' => $location?->venue_address,
                'streetAddress' => $location?->street_address,
                'city' => $location?->city,
                'state' => $location?->state,
                'zip' => $location?->zip,
                'landmark' => $location?->landmark,
                'longitude' => $location?->longitude,
                'latitude' => $location?->latitude,
                'onlineMeetingUrl' => $location?->online_meeting_url,
            ],
            'schedule' => [
                'type' => $schedule?->schedule_type ?? 'single',
                'timezone' => $schedule?->schedule_timezone ?? 'UTC',
                'eventFrequency' => $schedule?->event_frequency,
                'startDate' => $startDateStr,
                'startTime' => $startTimeStr,
                'endDate' => $endDateStr,
                'endTime' => $endTimeStr,
                'recurringEndType' => $schedule?->recurring_type,
                'recurringEndDate' => $recurringEndDateStr,
                'recurringOccurrences' => $schedule?->recurring_occurrences,
            ],
            'schedules' => $schedulesData,
            'scheduleItems' => $schedulesData,
            'lineup' => $lineupData,
            'status' => $this->status?->value ?? $this->status,
            'visibility' => $this->visibility?->value ?? $this->visibility,
            'bannerUrl' => $this->banner_url,
            'isFeatured' => (bool) $this->is_featured,
            'streamProvider' => $settingsRel?->stream_provider,
            'streamInstructions' => $settingsRel?->stream_instructions,
            'refundPolicy' => $settingsRel?->refund_policy,
            'refundOption' => $settingsRel?->refund_option,
            'ageRestriction' => $settingsRel?->age_restriction,
            'dressCode' => $settingsRel?->dress_code,
            'eventRules' => $settingsRel?->event_rules,
            'amenities' => $settingsRel?->amenities ?? [],
            'confirmationEmailMsg' => $settingsRel?->confirmation_email_msg,
            'statusMessage' => $settingsRel?->status_message,
            'isStatusMsgEnabled' => (bool) ($settingsRel?->is_status_msg_enabled ?? false),
            'statusMessageType' => $settingsRel?->status_message_type,
            'preSaleMessage' => $settingsRel?->pre_sale_message,
            'postSaleMessage' => $settingsRel?->post_sale_message,
            'themeColor' => $settingsRel?->theme_color,
            'bannerStyle' => $settingsRel?->banner_style,
            'settings' => $settingsRel ? [
                'streamProvider' => $settingsRel->stream_provider,
                'streamInstructions' => $settingsRel->stream_instructions,
                'refundPolicy' => $settingsRel->refund_policy,
                'refundOption' => $settingsRel->refund_option,
                'ageRestriction' => $settingsRel->age_restriction,
                'dressCode' => $settingsRel->dress_code,
                'eventRules' => $settingsRel->event_rules,
                'amenities' => $settingsRel->amenities ?? [],
                'confirmationEmailMsg' => $settingsRel->confirmation_email_msg,
                'statusMessage' => $settingsRel->status_message,
                'isStatusMsgEnabled' => (bool) $settingsRel->is_status_msg_enabled,
                'statusMessageType' => $settingsRel->status_message_type,
                'preSaleMessage' => $settingsRel->pre_sale_message,
                'postSaleMessage' => $settingsRel->post_sale_message,
                'themeColor' => $settingsRel->theme_color,
                'bannerStyle' => $settingsRel->banner_style,
                'faqs' => $faqs,
                'galleryUrls' => $galleryUrls,
            ] : null,
            'customQuestions' => $customQuestionsData,
            'promoters' => $promotersData,
            'faqs' => $faqs,
            'galleryUrls' => $galleryUrls,
            'meta' => $this->meta,
            'ticketTypes' => $this->whenLoaded('ticketTypes', function () {
                return TicketTypeResource::collection($this->ticketTypes);
            }),
            'organizer' => $this->whenLoaded('organizer', function () {
                $status = $this->organizer->verification_status instanceof \BackedEnum
                    ? $this->organizer->verification_status->value
                    : ($this->organizer->verification_status ?? 'unverified');

                return [
                    'id' => $this->organizer->uuid,
                    'name' => $this->organizer->name,
                    'slug' => $this->organizer->slug,
                    'logo' => $this->organizer->logo,
                    'cover_photo' => $this->organizer->cover_photo,
                    'cover_image' => $this->organizer->cover_photo,
                    'banner_url' => $this->organizer->cover_photo,
                    'bannerUrl' => $this->organizer->cover_photo,
                    'website' => $this->organizer->website,
                    'verification_status' => $status,
                    'is_verified' => $status === 'verified',
                ];
            }),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
