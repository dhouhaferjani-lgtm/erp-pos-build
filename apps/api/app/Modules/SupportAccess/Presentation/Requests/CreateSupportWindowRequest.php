<?php

declare(strict_types=1);

namespace App\Modules\SupportAccess\Presentation\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;

final class CreateSupportWindowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(Repository $config): array
    {
        $maxWindowHours = $config->get('support_access.max_grant_window_hours', 168);
        $maxWindowHours = is_int($maxWindowHours) && $maxWindowHours > 0 ? $maxWindowHours : 168;

        return [
            'subject_user_id' => ['nullable', 'uuid'],
            'reason' => ['required', 'string', 'max:2000'],
            'ticket_ref' => ['required', 'string', 'max:100'],
            'starts_at' => ['required', 'date'],
            'expires_at' => [
                'required',
                'date',
                'after:starts_at',
                'before:'.CarbonImmutable::now()->addHours($maxWindowHours)->toIso8601String(),
            ],
        ];
    }
}
