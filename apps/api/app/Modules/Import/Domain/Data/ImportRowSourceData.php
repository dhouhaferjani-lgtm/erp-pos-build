<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportRowSourceData
{
    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $provided
     */
    public function __construct(
        public array $values,
        public array $provided,
        public ImportRowResultsData $results,
        public ?ImportPlacementPlanData $placementPlan,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromStorage(array $payload): self
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if ($key === '_provided' || $key === '_results' || str_starts_with($key, '_')) {
                continue;
            }

            if (is_string($value) || is_int($value) || is_bool($value)) {
                $values[$key] = (string) $value;
            } elseif ($value === null) {
                $values[$key] = '';
            }
        }

        $provided = [];
        $rawProvided = $payload['_provided'] ?? [];
        if (is_array($rawProvided)) {
            foreach ($rawProvided as $key) {
                if (is_string($key) && $key !== '' && ! in_array($key, $provided, true)) {
                    $provided[] = $key;
                }
            }
        }

        $rawResults = $payload['_results'] ?? [];
        $results = is_array($rawResults)
            ? ImportRowResultsData::fromStorage($rawResults)
            : new ImportRowResultsData([]);

        $rawPlacement = $payload['_placement_plan'] ?? null;
        $placementPlan = is_array($rawPlacement)
            ? ImportPlacementPlanData::fromStorage($rawPlacement)
            : null;

        return new self($values, $provided, $results, $placementPlan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        $storage = [
            ...$this->values,
            '_provided' => $this->provided,
            '_results' => $this->results->toStorage(),
        ];
        if ($this->placementPlan !== null) {
            $storage['_placement_plan'] = $this->placementPlan->toStorage();
        }

        return $storage;
    }
}
