<?php

declare(strict_types=1);

namespace App\Modules\Import\Domain\Data;

final readonly class ImportRowSourceData
{
    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $provided
     *                                  `_results` is the flat map written by the current phase writers.
     * @param  array<string, string>  $results
     */
    public function __construct(
        public array $values,
        public array $provided,
        public array $results,
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

        $results = [];
        $rawResults = $payload['_results'] ?? [];
        if (is_array($rawResults)) {
            foreach ($rawResults as $phase => $breadcrumbs) {
                if (! is_string($phase)) {
                    continue;
                }

                if (is_string($breadcrumbs)) {
                    $results[$phase] = $breadcrumbs;

                    continue;
                }

                if (! is_array($breadcrumbs)) {
                    continue;
                }

                foreach ($breadcrumbs as $name => $detail) {
                    if (is_string($name) && is_string($detail)) {
                        $results[$phase.'.'.$name] = $detail;
                    }
                }
            }
        }

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
            '_results' => $this->results,
        ];
        if ($this->placementPlan !== null) {
            $storage['_placement_plan'] = $this->placementPlan->toStorage();
        }

        return $storage;
    }
}
