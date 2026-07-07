<?php

declare(strict_types=1);

namespace App\Modules\DocumentIngestion\Presentation\Requests;

use App\Modules\DocumentIngestion\Domain\Enums\DocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreDocumentIngestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var int $maxBytes */
        $maxBytes = config('media.documents.max_file_size');
        $maxKb = (int) ($maxBytes / 1024);

        /** @var array<int, string> $allowedMime */
        $allowedMime = config('media.documents.allowed_mime_types');

        return [
            'kind' => ['required', 'string', Rule::in(array_column(DocumentKind::cases(), 'value'))],
            'file' => ['required', 'file', 'max:'.$maxKb, 'mimetypes:'.implode(',', $allowedMime)],
        ];
    }
}
