<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\POS\Domain\PosCustomerAlias;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PosCustomerAlias
 */
final class PosCustomerAliasResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'client_customer_uuid' => $this->client_customer_uuid,
            'server_partner_id' => $this->server_partner_id,
            'tenant_id' => $this->tenant_id,
            'company_id' => $this->company_id,
            'resolved_at' => $this->updated_at?->toISOString(),
        ];
    }
}
