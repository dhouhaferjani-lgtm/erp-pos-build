<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\BuildsDeliveryPolicyFixtures;

/**
 * DPA Wave 3 / sub-wave 3E — **T24**: the lane-separation report.
 *
 * Two mirrored buckets, LISTING ONLY. The 418 year-end accrual and its reversal
 * stay operator-driven on their own endpoints — an accrual posted because
 * somebody opened a report is an accrual nobody decided to make.
 */
class LaneSeparationReportTest extends TestCase
{
    use BuildsDeliveryPolicyFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootDeliveryPolicyFixtures();
        (new CountryDocumentSettingsSeeder)->run();
    }

    public function test_it_lists_confirmed_delivery_notes_with_no_invoice(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);

        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertStatus(200);
        $this->assertSame(
            [$deliveryNote->id],
            array_column($response->json('data.uninvoiced_delivery_notes'), 'id'),
        );
    }

    public function test_the_mirror_bucket_lists_the_invoiced_not_delivered_population(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $invoice->update([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', 'legacy-report'),
            'chain_sequence' => 1,
        ]);

        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertStatus(200);
        $this->assertSame(
            [$invoice->id],
            array_column($response->json('data.invoiced_not_delivered'), 'id'),
        );
        $this->assertSame(
            'pre_policy',
            $response->json('data.invoiced_not_delivered.0.policy_at_post_time'),
            'The row must say WHEN it happened relative to the policy, or an operator '
            .'cannot tell a legacy document from a live hole.',
        );
    }

    /**
     * 🚨 THE SUB-WAVE'S REGRESSION TEST (T24.2). One assertion that proves 3E
     * works end to end: after the policy takes effect, the exception bucket is
     * empty for anything posted through the gate.
     */
    public function test_the_mirror_bucket_is_empty_for_documents_posted_after_the_policy(): void
    {
        $deliveryNote = $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $this->dpLinkConvertedShape($invoice, [$deliveryNote]);
        app(DocumentPostingService::class)->post($invoice);

        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data.invoiced_not_delivered'));
    }

    public function test_it_reports_the_resolved_policy_in_force(): void
    {
        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertJsonPath('data.policy', 'require_delivery_first');
        $response->assertJsonPath('data.policy_source', 'country');
    }

    /**
     * Viewing a report must not move the ledger. The 418 accrual is a separate,
     * deliberate act.
     */
    public function test_viewing_the_report_creates_no_journal_entry(): void
    {
        $this->dpConfirmedDeliveryNote([$this->dpPhysicalLine()]);
        $before = JournalEntry::query()->count();

        $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation')->assertStatus(200);
        $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation')->assertStatus(200);

        $this->assertSame($before, JournalEntry::query()->count());
    }
}
