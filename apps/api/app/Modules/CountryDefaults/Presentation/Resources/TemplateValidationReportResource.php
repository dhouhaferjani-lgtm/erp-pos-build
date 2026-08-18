<?php

declare(strict_types=1);

namespace App\Modules\CountryDefaults\Presentation\Resources;

use App\Modules\CountryDefaults\Application\DTOs\TemplateValidationReportData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

final class TemplateValidationReportResource extends JsonResource
{
    /** @return array{valid: bool, scope: list<string>, errors: list<array{code: string, parameters: array<string, string>}>} */
    public function toArray(Request $request): array
    {
        if (! $this->resource instanceof TemplateValidationReportData) {
            throw new LogicException('Template validation resources require TemplateValidationReportData.');
        }

        $errors = [];
        foreach ($this->resource->errors as $error) {
            $errors[] = [
                'code' => $error->code,
                'parameters' => $error->parameters,
            ];
        }

        return [
            'valid' => $this->resource->valid,
            'scope' => $this->resource->scope,
            'errors' => $errors,
        ];
    }
}
