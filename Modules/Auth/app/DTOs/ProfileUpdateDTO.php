<?php

namespace Modules\Auth\app\DTOs;

class ProfileUpdateDTO
{
    public function __construct(
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $phone = null,
        public readonly ?string $timezone = null,
        public readonly bool $hasPhone = false,
        public readonly bool $hasTimezone = false,
    ) {}

    public static function fromArray(array $data): self
    {
        $hasTimezone = array_key_exists('timezone', $data) || array_key_exists('time_zone', $data);
        $rawTz = $data['timezone'] ?? $data['time_zone'] ?? null;

        return new self(
            firstName: $data['first_name'] ?? null,
            lastName: $data['last_name'] ?? null,
            phone: $data['phone'] ?? null,
            timezone: is_string($rawTz) && trim($rawTz) !== '' ? trim($rawTz) : null,
            hasPhone: array_key_exists('phone', $data),
            hasTimezone: $hasTimezone,
        );
    }
}
