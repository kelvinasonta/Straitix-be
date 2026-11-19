<?php

namespace Modules\Credits\app\Transformers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Credits\app\Models\OrganizerCreditWallet;

/**
 * @mixin OrganizerCreditWallet
 */
class CreditWalletResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $threshold = (int) ($this->low_balance_threshold ?? 500);
        $alertEnabled = (bool) ($this->low_balance_alert_enabled ?? true);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'organizer_id' => $this->organizer_id,
            'balance' => (int) $this->balance,
            'reserved_balance' => (int) $this->reserved_balance,
            'available_balance' => (int) $this->available_balance,
            'low_balance_threshold' => $threshold,
            'low_balance_alert_enabled' => $alertEnabled,
            'is_below_threshold' => $alertEnabled && (int) $this->available_balance <= $threshold,
            'last_low_balance_alert_at' => $this->last_low_balance_alert_at?->toISOString(),
            'currency' => $this->currency,
            'version' => (int) $this->version,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
