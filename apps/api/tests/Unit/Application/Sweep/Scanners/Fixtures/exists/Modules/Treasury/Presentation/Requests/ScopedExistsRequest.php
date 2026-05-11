<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Treasury\Presentation\Requests;

use Illuminate\Validation\Rule;

/**
 * Scanner fixture (negative case). Tenant-scoped Rule::exists() must NOT
 * be flagged.
 */
class ScopedExistsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => [
                'required',
                Rule::exists('payment_methods', 'id')->where('tenant_id', 42),
            ],
            'partner_id' => [
                'required',
                Rule::exists('partners', 'id')->where('company_id', 99),
            ],
        ];
    }
}
