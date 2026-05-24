<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Presentation\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read array{
 *   id: string,
 *   source: string,
 *   fiscal_event_id: ?string,
 *   event_type: string,
 *   parsed: array<string, mixed>,
 *   defects: list<array{path: string, code: string, message: string}>
 * } $resource
 */
final class BestEffortParseResource extends JsonResource
{
    /**
     * @return array{
     *   id: string,
     *   source: string,
     *   fiscal_event_id: ?string,
     *   event_type: string,
     *   parsed: array<string, mixed>,
     *   defects: list<array{path: string, code: string, message: string}>
     * }
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'source' => $this->resource['source'],
            'fiscal_event_id' => $this->resource['fiscal_event_id'],
            'event_type' => $this->resource['event_type'],
            'parsed' => $this->resource['parsed'],
            'defects' => $this->resource['defects'],
        ];
    }
}
