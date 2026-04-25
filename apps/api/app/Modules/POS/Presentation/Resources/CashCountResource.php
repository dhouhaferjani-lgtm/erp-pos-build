<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\ZReportCount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ZReportCount
 */
final class CashCountResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_method_id' => $this->payment_method_id,
            'payment_method_code' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod->code),
            'payment_method_name' => $this->whenLoaded('paymentMethod', fn () => $this->paymentMethod->name),
            'currency_code' => $this->currency_code,
            'expected_amount' => (string) $this->expected_amount,
            'actual_amount' => (string) $this->actual_amount,
            'variance_amount' => (string) $this->variance_amount,
            'variance_direction' => $this->variance_direction,
            'transaction_count' => (int) $this->transaction_count,
        ];
    }
}
