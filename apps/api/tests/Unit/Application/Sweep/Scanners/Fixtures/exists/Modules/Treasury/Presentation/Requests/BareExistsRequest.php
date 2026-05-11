<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Treasury\Presentation\Requests;

/**
 * Scanner fixture (positive case). Two bare-exists rules over guarded
 * tables — both must be flagged by PhpPresentationExistsScanner.
 *
 * NOT loaded by Composer's autoloader; this file is only parsed as text by
 * the scanner. Namespace + class name are arbitrary.
 */
class BareExistsRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'partner_id' => ['required', 'exists:partners,id'],
        ];
    }
}
