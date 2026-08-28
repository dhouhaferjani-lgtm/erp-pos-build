<?php

declare(strict_types=1);

use App\Modules\Accounting\Application\Services\RefundCompensationAccountProvider;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\POS\Domain\Enums\FiscalStatus;
use App\Modules\POS\Domain\Enums\TerminalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Owner ruling 2026-08-28 — v4 refund authoring is DEFAULT-ON.
 *
 * The earlier per-terminal two-phase offer remains useful as a brownfield
 * safety mechanism, not a business gate. This forward-only migration enables
 * every still-disabled PHYSICAL terminal that:
 *
 *  - has zero legacy-sealed fiscalized receipts (`fiscal_event_id IS NULL`),
 *    the verifiable v3-from-birth proxy; and
 *  - belongs to a company resolving both refund compensation purposes.
 *
 * There is deliberately NO single-till restriction. Each physical terminal is
 * classified independently, so every qualifying till in a multi-till company
 * is enabled. Web and virtual-admin terminals are never candidates.
 *
 * The census partitions every disabled physical candidate into enabled,
 * legacy-history, or missing-accounts (legacy wins if both apply). Code/name
 * drift is an orthogonal informational bucket: an existing purpose holder is
 * authoritative and remains eligible, but its expected and actual definition
 * is reported explicitly. Already enabled terminals are outside the candidate
 * population, making repeated runs a true no-write no-op. The structured
 * warning survives production's warning log level and attributes one result to
 * every tenant database.
 */
return new class extends Migration
{
    private const GATE_TOKEN = 'V4 REFUND AUTHORING DEFAULT-ON MIGRATION:';

    public function up(): void
    {
        $tenantKey = (string) (tenant()?->getTenantKey() ?? DB::connection()->getDatabaseName());

        if (! $this->requiredSchemaExists()) {
            Log::warning(self::GATE_TOKEN, [
                'tenant' => $tenantKey,
                'status' => 'skipped',
                'reason' => 'required-schema-absent',
                'enabled' => 0,
                'skipped' => 0,
                'reasons' => [
                    'legacy-history' => 0,
                    'missing-accounts' => 0,
                ],
                'drift' => 0,
                'drifts' => [],
            ]);

            return;
        }

        try {
            $enabledIds = [];
            $reasons = [
                'legacy-history' => 0,
                'missing-accounts' => 0,
            ];
            /** @var array<string, true> $censusedCompanies */
            $censusedCompanies = [];
            /** @var list<array{company_id: string, purpose: string, expected: array{code: string, name: string}, actual: array{code: string, name: string}}> $drifts */
            $drifts = [];

            $candidates = DB::table('pos_terminals')
                ->select(['id', 'company_id'])
                ->where('type', TerminalType::Physical->value)
                ->where('v4_refund_authoring_enabled', false)
                ->orderBy('id')
                ->get();

            foreach ($candidates as $terminal) {
                $hasLegacyHistory = DB::table('pos_receipts')
                    ->where('terminal_id', $terminal->id)
                    ->whereNull('fiscal_event_id')
                    ->where('fiscal_status', FiscalStatus::Fiscalized->value)
                    ->exists();

                if ($hasLegacyHistory) {
                    $reasons['legacy-history']++;

                    continue;
                }

                $purposeCount = DB::table('accounts')
                    ->where('company_id', $terminal->company_id)
                    ->whereIn('system_purpose', [
                        SystemAccountPurpose::SalesReturn->value,
                        SystemAccountPurpose::RefundWriteOff->value,
                    ])
                    ->distinct()
                    ->count('system_purpose');

                if ($purposeCount !== 2) {
                    $reasons['missing-accounts']++;

                    continue;
                }

                $companyId = (string) $terminal->company_id;
                if (! isset($censusedCompanies[$companyId])) {
                    $countryCode = (string) DB::table('companies')
                        ->where('id', $companyId)
                        ->value('country_code');

                    foreach (RefundCompensationAccountProvider::canonicalDefinitions($countryCode) as $definition) {
                        $purposeHolder = DB::table('accounts')
                            ->where('company_id', $companyId)
                            ->where('system_purpose', $definition['purpose'])
                            ->first(['code', 'name']);
                        if ($purposeHolder === null) {
                            continue;
                        }

                        $drift = RefundCompensationAccountProvider::driftRecord(
                            $companyId,
                            $definition,
                            (string) $purposeHolder->code,
                            (string) $purposeHolder->name,
                        );
                        if ($drift !== null) {
                            $drifts[] = $drift;
                        }
                    }

                    $censusedCompanies[$companyId] = true;
                }

                $enabledIds[] = (string) $terminal->id;
            }

            $enabled = 0;
            if ($enabledIds !== []) {
                $enabled = DB::transaction(static fn (): int => DB::table('pos_terminals')
                    ->whereIn('id', $enabledIds)
                    ->where('type', TerminalType::Physical->value)
                    ->where('v4_refund_authoring_enabled', false)
                    ->update([
                        'v4_refund_authoring_enabled' => true,
                        'updated_at' => now(),
                    ]));
            }

            Log::warning(self::GATE_TOKEN, [
                'tenant' => $tenantKey,
                'status' => 'ok',
                'enabled' => $enabled,
                'skipped' => array_sum($reasons),
                'reasons' => $reasons,
                'drift' => count($drifts),
                'drifts' => $drifts,
            ]);
        } catch (Throwable $exception) {
            Log::error(self::GATE_TOKEN, [
                'tenant' => $tenantKey,
                'status' => 'FAILED',
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Forward-only data transition. A rollback cannot distinguish a terminal
     * enabled here from one enabled later by the default creation path or the
     * operator command, and disabling it could halt refunds.
     */
    public function down(): void {}

    private function requiredSchemaExists(): bool
    {
        foreach (['pos_terminals', 'pos_receipts', 'accounts', 'companies'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        foreach ([
            'pos_terminals' => ['id', 'company_id', 'type', 'v4_refund_authoring_enabled', 'updated_at'],
            'pos_receipts' => ['terminal_id', 'fiscal_event_id', 'fiscal_status'],
            'accounts' => ['company_id', 'system_purpose'],
            'companies' => ['id', 'country_code'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }
};
