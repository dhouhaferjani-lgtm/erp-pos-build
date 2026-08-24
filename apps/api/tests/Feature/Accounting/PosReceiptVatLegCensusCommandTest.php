<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\JournalEntryStatus;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Company\Domain\Company;
use App\Modules\Company\Domain\Location;
use App\Modules\Identity\Domain\User;
use App\Modules\POS\Domain\Receipt;
use App\Modules\POS\Domain\ReceiptVatDetail;
use App\Modules\POS\Domain\Terminal;
use App\Modules\Tenant\Domain\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * W4-9 deploy check — `pos:census-vat-legs`.
 *
 * The fix changes what NEW receipts post; journal entries are immutable
 * (rule 8), so every receipt booked before it stays wrong. The census is how an
 * operator finds out whether a given tenant has any, and it must be able to say
 * "none" as confidently as it says "twelve" — a census that cannot report clean
 * is not a deploy check, it is noise.
 */
final class PosReceiptVatLegCensusCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $tenantId;

    private string $companyId;

    private string $locationId;

    private string $terminalId;

    private string $cashierId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->tenantId = $tenant->id;

        $company = Company::factory()->create([
            'tenant_id' => $this->tenantId,
            'country_code' => 'TN',
            'currency' => 'TND',
        ]);
        $this->companyId = $company->id;

        $location = Location::factory()->create(['company_id' => $this->companyId]);
        $this->locationId = $location->id;

        $terminal = Terminal::factory()->create([
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'location_id' => $this->locationId,
            'genesis_seed' => str_repeat('0', 64),
        ]);
        $this->terminalId = $terminal->id;

        $this->cashierId = User::factory()->create(['tenant_id' => $this->tenantId])->id;

        $this->app->make(ChartOfAccountsService::class)->seedForCompany(
            Company::query()->findOrFail($this->companyId),
        );
    }

    public function test_reports_clean_when_every_vat_bearing_receipt_has_a_vat_leg(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: true);

        $this->artisan('pos:census-vat-legs')
            ->expectsOutputToContain('none')
            ->assertExitCode(0);
    }

    public function test_flags_a_receipt_booked_without_a_vat_leg(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: false);

        $this->artisan('pos:census-vat-legs')
            ->expectsOutputToContain((string) $receipt->receipt_number)
            ->expectsOutputToContain('were booked without a VAT leg')
            ->assertExitCode(1);
    }

    public function test_a_zero_rated_receipt_is_not_flagged(): void
    {
        // No VAT was ever owed, so no VAT leg is missing. Flagging these would
        // bury the real hits under every exempt sale the shop ever made.
        $receipt = $this->receipt('100.000', '0.000', true);
        $this->bookEntry($receipt, withVatLeg: false);

        $this->artisan('pos:census-vat-legs')->assertExitCode(0);
    }

    public function test_a_receipt_that_never_reached_the_gl_is_reported_separately(): void
    {
        $this->receipt('119.000', '19.000', true);

        $this->artisan('pos:census-vat-legs')
            ->expectsOutputToContain('never reached the GL')
            ->assertExitCode(1);
    }

    public function test_the_tenant_filter_scopes_the_report(): void
    {
        $receipt = $this->receipt('119.000', '19.000', true);
        $this->bookEntry($receipt, withVatLeg: false);

        $this->artisan('pos:census-vat-legs', ['--tenant' => (string) Str::uuid()])
            ->assertExitCode(0);
        $this->artisan('pos:census-vat-legs', ['--tenant' => $this->tenantId])
            ->assertExitCode(1);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param  numeric-string  $total
     * @param  numeric-string  $taxAmount
     */
    private function receipt(string $total, string $taxAmount, bool $withSealedRows): Receipt
    {
        $receipt = Receipt::factory()
            ->withTotal($total, $taxAmount)
            ->create([
                'tenant_id' => $this->tenantId,
                'company_id' => $this->companyId,
                'location_id' => $this->locationId,
                'terminal_id' => $this->terminalId,
                'cashier_id' => $this->cashierId,
                'currency' => 'TND',
            ]);

        if ($withSealedRows) {
            ReceiptVatDetail::create([
                'id' => Str::uuid()->toString(),
                'receipt_id' => $receipt->id,
                'tax_category' => 'S',
                'tax_rate' => bccomp($taxAmount, '0', 3) > 0 ? '19.00' : '0.00',
                'net_amount' => bcsub($total, $taxAmount, 3),
                'vat_amount' => $taxAmount,
                'gross_amount' => $total,
            ]);
        }

        return $receipt;
    }

    /**
     * Book the receipt the way the pre-W4-9 writer did (Dr cash / Cr revenue for
     * the gross) or the way it does now (with the VAT leg).
     */
    private function bookEntry(Receipt $receipt, bool $withVatLeg): void
    {
        $cash = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::Cash);
        $revenue = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::ProductRevenue);
        $vat = Account::findByPurposeOrFail($this->companyId, SystemAccountPurpose::VatCollected);

        $entryId = Str::uuid()->toString();
        DB::table('journal_entries')->insert([
            'id' => $entryId,
            'tenant_id' => $this->tenantId,
            'company_id' => $this->companyId,
            'entry_number' => 'JE-CENSUS-'.substr($entryId, 0, 8),
            'entry_date' => $receipt->posted_at,
            'description' => 'census fixture',
            'status' => JournalEntryStatus::Posted->value,
            'source_type' => 'pos_receipt',
            'source_id' => $receipt->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $tax = (string) $receipt->tax_amount;
        $total = (string) $receipt->total;
        $lines = [[$cash->id, $total, '0']];
        if ($withVatLeg && bccomp($tax, '0', 3) > 0) {
            $lines[] = [$revenue->id, '0', bcsub($total, $tax, 3)];
            $lines[] = [$vat->id, '0', $tax];
        } else {
            $lines[] = [$revenue->id, '0', $total];
        }

        $order = 0;
        foreach ($lines as [$accountId, $debit, $credit]) {
            DB::table('journal_lines')->insert([
                'id' => Str::uuid()->toString(),
                'journal_entry_id' => $entryId,
                'account_id' => $accountId,
                'partner_id' => null,
                'debit' => $debit,
                'credit' => $credit,
                'description' => 'census fixture',
                'line_order' => $order++,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
