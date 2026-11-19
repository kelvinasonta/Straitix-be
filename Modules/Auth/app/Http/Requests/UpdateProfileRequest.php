<?php

namespace Modules\Auth\app\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merges = [];

        if (! $this->has('first_name') && $this->has('firstName')) {
            $merges['first_name'] = $this->input('firstName');
        }

        if (! $this->has('last_name') && $this->has('lastName')) {
            $merges['last_name'] = $this->input('lastName');
        }

        $rawTz = $this->input('timezone') ?? $this->input('time_zone');
        if (is_string($rawTz)) {
            $cleaned = trim((string) preg_replace('/\s*\([^)]*\).*$/', '', $rawTz));
            $merges['timezone'] = $cleaned !== '' ? $cleaned : null;
        }

        if (! empty($merges)) {
            $this->merge($merges);
        }
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'min:2', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }
}
