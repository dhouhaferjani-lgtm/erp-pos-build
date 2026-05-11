<?php

declare(strict_types=1);

namespace Tests\Fixtures\Sweep\Modules\Treasury\Presentation\Requests;

/**
 * Scanner fixture (positive case for stable_key uniqueness). Two bare-exists
 * rules over the SAME guarded table in the same rules() method. Before
 * Codex Phase 1 review #1 these collapsed to one stable_key because the
 * scanner only fingerprinted (class, method, table, pattern_type). They
 * must now produce two distinct stable_keys via per-statement fingerprint.
 */
class MultipleExistsInOneMethodRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'first_payment_method_id' => ['required', 'exists:payment_methods,id'],
            'second_payment_method_id' => ['required', 'exists:payment_methods,id'],
        ];
    }
}
