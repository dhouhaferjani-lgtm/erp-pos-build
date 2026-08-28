<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Services;

use App\Modules\Accounting\Domain\Enums\AccountType;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Single country-aware source for the two refund compensation accounts.
 *
 * New-company chart provisioning installs these definitions immediately after
 * the selected country chart/template. The brownfield backfill consumes the
 * same definitions but deliberately keeps its stricter patch-only semantics.
 */
final class RefundCompensationAccountProvider
{
    private const FR_TN_SALES_RETURN = [
        'code' => '709',
        'name' => 'Rabais, remises et ristournes accordés',
        'parent_code' => '70',
    ];

    private const FR_TN_REFUND_WRITE_OFF = [
        'code' => '6590',
        'name' => 'Perte sur remboursement (write-off)',
        'parent_code' => '65',
    ];

    private const GENERIC_SALES_RETURN = [
        'code' => '7090',
        'name' => 'Sales Returns',
        'parent_code' => '7000',
    ];

    private const GENERIC_REFUND_WRITE_OFF = [
        'code' => '6590',
        'name' => 'Refund Write-Off',
        'parent_code' => '6000',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array{
     *     sales_return: array{code: string, name: string, type: string, parent_code: string, purpose: string},
     *     refund_write_off: array{code: string, name: string, type: string, parent_code: string, purpose: string}
     * }
     */
    public function definitions(string $countryCode): array
    {
        $frenchPlan = in_array(strtoupper($countryCode), ['FR', 'TN'], true);
        $salesReturn = $frenchPlan ? self::FR_TN_SALES_RETURN : self::GENERIC_SALES_RETURN;
        $writeOff = $frenchPlan ? self::FR_TN_REFUND_WRITE_OFF : self::GENERIC_REFUND_WRITE_OFF;

        return [
            'sales_return' => [
                'code' => $salesReturn['code'],
                'name' => $salesReturn['name'],
                'type' => AccountType::Expense->value,
                'parent_code' => $salesReturn['parent_code'],
                'purpose' => SystemAccountPurpose::SalesReturn->value,
            ],
            'refund_write_off' => [
                'code' => $writeOff['code'],
                'name' => $writeOff['name'],
                'type' => AccountType::Expense->value,
                'parent_code' => $writeOff['parent_code'],
                'purpose' => SystemAccountPurpose::RefundWriteOff->value,
            ],
        ];
    }

    /**
     * Complete a freshly provisioned chart inside its surrounding transaction.
     */
    public function provisionNewCompany(string $companyId, string $tenantId, string $countryCode): void
    {
        foreach ($this->definitions($countryCode) as $definition) {
            $this->provisionDefinition($companyId, $tenantId, $definition);
        }
    }

    /**
     * @param  array{code: string, name: string, type: string, parent_code: string, purpose: string}  $definition
     */
    private function provisionDefinition(string $companyId, string $tenantId, array $definition): void
    {
        $accounts = $this->database->table('accounts')->where('company_id', $companyId);
        $purposeHolder = (clone $accounts)
            ->where('system_purpose', $definition['purpose'])
            ->first();

        if ($purposeHolder !== null) {
            $this->assertUsable($companyId, $purposeHolder, $definition);

            return;
        }

        $existing = (clone $accounts)->where('code', $definition['code'])->first();
        if ($existing !== null) {
            if ($existing->system_purpose !== null) {
                throw new RuntimeException(sprintf(
                    'Company %s account %s already carries system_purpose %s; refusing to repurpose it for %s.',
                    $companyId,
                    $definition['code'],
                    (string) $existing->system_purpose,
                    $definition['purpose'],
                ));
            }

            $this->database->table('accounts')->where('id', $existing->id)->update([
                'type' => $definition['type'],
                'system_purpose' => $definition['purpose'],
                'is_active' => true,
                'is_system' => true,
                'updated_at' => now(),
            ]);

            return;
        }

        $parentId = (clone $accounts)
            ->where('code', $definition['parent_code'])
            ->value('id');
        if (! is_string($parentId)) {
            throw new RuntimeException(sprintf(
                'Company %s is missing parent account %s; cannot provision refund compensation account %s.',
                $companyId,
                $definition['parent_code'],
                $definition['code'],
            ));
        }

        $now = now();
        $this->database->table('accounts')->insert([
            'id' => Str::uuid()->toString(),
            'tenant_id' => $tenantId,
            'company_id' => $companyId,
            'parent_id' => $parentId,
            'code' => $definition['code'],
            'name' => $definition['name'],
            'type' => $definition['type'],
            'system_purpose' => $definition['purpose'],
            'is_active' => true,
            'is_system' => true,
            'balance' => '0.000',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @param array{type: string, purpose: string} $definition */
    private function assertUsable(string $companyId, \stdClass $account, array $definition): void
    {
        if ((string) $account->type !== $definition['type']) {
            throw new RuntimeException(sprintf(
                'Company %s account %s for purpose %s has wrong type %s; expected %s.',
                $companyId,
                (string) $account->code,
                $definition['purpose'],
                (string) $account->type,
                $definition['type'],
            ));
        }

        if (! (bool) $account->is_active) {
            throw new RuntimeException(sprintf(
                'Company %s account %s for purpose %s is inactive.',
                $companyId,
                (string) $account->code,
                $definition['purpose'],
            ));
        }
    }
}
