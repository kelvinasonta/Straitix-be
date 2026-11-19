<?php

declare(strict_types=1);

namespace Modules\Notification\app\DTOs;

class NotificationPayload
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string>  $channels  Channels requested, e.g. ['email', 'in_app']
     */
    public function __construct(
        public readonly string $type,
        public readonly string $category,
        public readonly string $title,
        public readonly string $message,
        public readonly ?string $actionUrl = null,
        public readonly ?string $icon = null,
        public readonly ?string $emailTemplateSlug = null,
        public readonly array $data = [],
        public readonly array $channels = ['in_app', 'email'],
        public readonly bool $isMandatory = false, // If true, bypasses user opt-out (e.g. security or payment receipt)
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(array $attributes): self
    {
        return new self(
            type: (string) ($attributes['type'] ?? 'general'),
            category: (string) ($attributes['category'] ?? 'general'),
            title: (string) ($attributes['title'] ?? ''),
            message: (string) ($attributes['message'] ?? ''),
            actionUrl: $attributes['action_url'] ?? $attributes['actionUrl'] ?? null,
            icon: $attributes['icon'] ?? 'bell',
            emailTemplateSlug: $attributes['email_template_slug'] ?? $attributes['emailTemplateSlug'] ?? null,
            data: (array) ($attributes['data'] ?? []),
            channels: (array) ($attributes['channels'] ?? ['in_app', 'email']),
            isMandatory: (bool) ($attributes['is_mandatory'] ?? $attributes['isMandatory'] ?? false),
        );
    }
}
