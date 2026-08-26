<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class ValidationEngine
{
    /**
     * Validate data against rules
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string>>  $rules
     * @param  array<string, string>  $messages  Per-rule messages keyed `field.rule`. Row errors have
     *                                           no separate code channel, so a type that needs a
     *                                           machine-readable refusal code carries it as the
     *                                           message's leading token (W4-1: `expiry_unparseable`).
     * @return array{is_valid: bool, errors: array<string, array<string>>}
     */
    public function validate(array $data, array $rules, ?string $tenantId = null, array $messages = []): array
    {
        // Process custom rules like unique with tenant scope
        $processedRules = $this->processRules($rules, $tenantId);

        $validator = Validator::make($data, $processedRules, $messages);

        if ($validator->fails()) {
            return [
                'is_valid' => false,
                'errors' => $validator->errors()->toArray(),
            ];
        }

        return [
            'is_valid' => true,
            'errors' => [],
        ];
    }

    /**
     * Process rules to handle custom unique validation with tenant scope
     *
     * @param  array<string, array<string>>  $rules
     * @return array<string, array<mixed>>
     */
    private function processRules(array $rules, ?string $tenantId): array
    {
        $processed = [];

        foreach ($rules as $field => $fieldRules) {
            $processed[$field] = [];

            foreach ($fieldRules as $rule) {
                if (str_starts_with($rule, 'unique:') && $tenantId !== null) {
                    // Parse unique:table,column,tenant_id,{tenant_id}
                    $parts = explode(',', substr($rule, 7));
                    $table = $parts[0];
                    $column = $parts[1] ?? $field;
                    $tenantColumn = $parts[2] ?? 'tenant_id';

                    // Convert to custom callback validation
                    $processed[$field][] = function (string $attribute, mixed $value, \Closure $fail) use ($table, $column, $tenantColumn, $tenantId): void {
                        $exists = DB::table($table)
                            ->where($column, $value)
                            ->where($tenantColumn, $tenantId)
                            ->exists();

                        if ($exists) {
                            $fail("The {$attribute} has already been taken.");
                        }
                    };
                } else {
                    $processed[$field][] = $rule;
                }
            }
        }

        return $processed;
    }

    /**
     * Validate that referenced entities exist
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, array{table: string, column: string, tenant_column?: string}>  $references
     * @return array{is_valid: bool, errors: array<string, array<string>>}
     */
    public function validateReferences(
        array $data,
        array $references,
        ?string $tenantId = null
    ): array {
        $errors = [];

        foreach ($references as $field => $config) {
            $value = $data[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $query = DB::table($config['table'])
                ->where($config['column'], $value);

            if ($tenantId !== null && isset($config['tenant_column'])) {
                $query->where($config['tenant_column'], $tenantId);
            }

            if (! $query->exists()) {
                $errors[$field] = ["The referenced {$field} does not exist."];
            }
        }

        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Validate CSV headers match expected columns
     *
     * @param  array<string>  $headers
     * @param  array<string>  $requiredColumns
     * @param  array<string>  $optionalColumns
     * @return array{is_valid: bool, missing: array<string>, unknown: array<string>}
     */
    public function validateHeaders(
        array $headers,
        array $requiredColumns,
        array $optionalColumns = []
    ): array {
        $headers = array_map('strtolower', array_map('trim', $headers));
        $allAllowed = array_merge($requiredColumns, $optionalColumns);

        $missing = array_diff($requiredColumns, $headers);
        $unknown = array_diff($headers, $allAllowed);

        return [
            'is_valid' => empty($missing),
            'missing' => array_values($missing),
            'unknown' => array_values($unknown),
        ];
    }
}
