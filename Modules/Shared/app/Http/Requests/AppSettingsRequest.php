<?php

namespace Modules\Shared\app\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AppSettingsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sitename' => ['string', 'required'],
            'site_tagline' => ['string', 'nullable'],
            'favicon' => ['string', 'nullable'],
            'logo' => ['string', 'nullable'],
            'logo_light' => ['string', 'nullable'],
            'logo_dark' => ['string', 'nullable'],
            'meta_title' => ['string', 'nullable', 'max:255'],
            'meta_description' => ['string', 'nullable', 'max:1000'],
            'meta_keywords' => ['string', 'nullable', 'max:500'],
            'og_image' => ['string', 'nullable'],
            'twitter_handle' => ['string', 'nullable'],
            'maintenance_message' => ['string', 'nullable'],
            'support_email' => ['email', 'nullable'],
            'timezone' => ['string', 'nullable'],
            'currency_id' => ['nullable'],
            'platform_fee_percentage' => ['numeric', 'min:0', 'max:100', 'nullable'],
            'platform_fee_fixed' => ['numeric', 'min:0', 'nullable'],
            'platform_fee_passthrough' => ['boolean', 'nullable'],
            'ticket_fee_percentage' => ['numeric', 'min:0', 'max:100', 'nullable'],
            'ticket_fee_fixed' => ['numeric', 'min:0', 'nullable'],
            'auth_captcha_provider' => ['string', 'in:none,recaptcha,turnstile', 'nullable'],
            'recaptcha_site_key' => ['string', 'nullable', 'max:255'],
            'recaptcha_secret_key' => ['string', 'nullable', 'max:255'],
            'cloudflare_turnstile_site_key' => ['string', 'nullable', 'max:255'],
            'cloudflare_turnstile_secret_key' => ['string', 'nullable', 'max:255'],
        ];
    }

    /**
     * Get custom error messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'sitename.required' => 'Website name is required.',
        ];
    }
}
