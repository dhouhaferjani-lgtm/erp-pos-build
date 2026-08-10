<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Modules\Accounting\Domain\JournalEntry;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Enums\PostingContext;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Workshop\WorkOrder\Domain\WorkOrder;
use Database\Seeders\CountryDocumentSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * 🚨 FIX ROUND 1 / inventory P1-1 — the guided path's delivery note must NOT
     * report itself as "delivered, never invoiced".
     *
     * `invoiced_at` had exactly two writers in `app/`, both converters, and
     * neither is on the T25c composite. Under `require_delivery_first` that
     * composite is THE path for every standalone goods invoice, so every one of
     * them would have permanently populated the 418 accrual bucket — while its
     * invoice was posted and sealed in the SAME transaction. `generateYearEnd
     * Adjustment` would then accrue revenue ON TOP of revenue already recognised.
     */
    public function test_a_delivery_note_created_by_the_guided_path_is_not_in_the_uninvoiced_bucket(): void
    {
        $invoice = $this->dpConfirmedInvoice([$this->dpPhysicalLine('2.0000')]);

        $this->actingAs($this->dpUser)
            ->postJson("/api/v1/invoices/{$invoice->id}/create-delivery-and-post")
            ->assertStatus(200);

        $deliveryNote = Document::query()
            ->where('type', DocumentType::DeliveryNote)
            ->where('source_document_id', $invoice->id)
            ->firstOrFail();

        // The linkage is recorded on the note itself, the way the converters do it.
        $this->assertNotEmpty($deliveryNote->payload['invoiced_at'] ?? null);
        $this->assertSame($invoice->id, $deliveryNote->payload['invoice_id'] ?? null);
        $this->assertSame('pre_post_delivery', $deliveryNote->payload['invoiced_via'] ?? null);

        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertStatus(200);
        $this->assertSame(
            [],
            array_column($response->json('data.uninvoiced_delivery_notes'), 'id'),
            'A delivery note whose invoice was posted in the same transaction is not uninvoiced.',
        );
    }

    /**
     * 🚨 FIX ROUND 2 / inventory N-1 — a RECORDED EXEMPTION IS NOT A HOLE.
     *
     * The D-c register's whole claim is "under require_delivery_first no new
     * invoice can join this population, so a row carrying a LIVE policy is
     * evidence of an unguarded posting path — investigate it". Fix round 1's F-1
     * exemption created a third population that lands in the same bucket looking
     * exactly like that: a WO-generated invoice, posted deliberately, under a
     * ruling, with the fact recorded on its own stamp — and the register threw
     * that fact away. An operator chasing it finds a legitimate repair job; an
     * operator who learns the register cries wolf stops reading it, which is how
     * the real hole gets missed.
     *
     * So the exemption travels with the row, and it is separable from a
     * pre-policy row on both axes.
     */
    public function test_a_work_order_exempt_invoice_is_listed_as_exempt_and_not_as_a_hole(): void
    {
        // (1) the exempt row — posted through the F-1 exemption, deliberately.
        $workOrder = WorkOrder::factory()->create([
            'tenant_id' => $this->dpTenant->id,
            'company_id' => $this->dpCompany->id,
            'customer_partner_id' => $this->dpPartner->id,
        ]);
        $exempt = $this->dpConfirmedInvoice(
            [$this->dpPhysicalLine()],
            ['work_order_id' => $workOrder->id],
        );
        app(DocumentPostingService::class)->post($exempt, PostingContext::WorkOrderGeneratedInvoice);

        // (2) the legacy row — posted before the policy existed, no stamp at all.
        $legacy = $this->dpConfirmedInvoice([$this->dpPhysicalLine()]);
        $legacy->update([
            'status' => DocumentStatus::Posted,
            'fiscal_status' => FiscalStatus::Sealed,
            'fiscal_hash' => hash('sha256', 'legacy-exempt-separation'),
            'chain_sequence' => 99,
        ]);

        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertStatus(200);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $response->json('data.invoiced_not_delivered');
        $byId = array_column($rows, null, 'id');

        $this->assertArrayHasKey($exempt->id, $byId);
        $this->assertArrayHasKey($legacy->id, $byId);

        // The exempt row SAYS SO.
        $this->assertTrue($byId[$exempt->id]['delivery_requirement_exempted']);
        $this->assertSame(
            PostingContext::WorkOrderGeneratedInvoice->value,
            $byId[$exempt->id]['posting_context'],
        );

        // The legacy row is separable on BOTH axes — the exemption flag AND the
        // policy-at-post-time. Neither alone would do it: a future exemption
        // could be granted under a live policy, and a pre-policy document could
        // be anything.
        $this->assertFalse($byId[$legacy->id]['delivery_requirement_exempted']);
        $this->assertSame('pre_policy', $byId[$legacy->id]['posting_context']);
        $this->assertSame('pre_policy', $byId[$legacy->id]['policy_at_post_time']);
        $this->assertSame('require_delivery_first', $byId[$exempt->id]['policy_at_post_time']);
    }

    /**
     * 🚨 FIX ROUND 2 / inventory N-2 — the report must not 500 under `allow`.
     *
     * Same argument as fix round 1's F-3, one layer up: the report RESOLVED the
     * policy through the throwing accessor, so the first company to carry `allow`
     * lost the very report that would show them what that setting did. A report
     * observes; it does not enforce.
     */
    public function test_the_report_renders_for_a_company_whose_policy_is_allow(): void
    {
        DB::table('companies')
            ->where('id', $this->dpCompany->id)
            ->update(['pre_delivery_invoicing_policy' => 'allow']);

        $response = $this->actingAs($this->dpUser)->getJson('/api/v1/reports/lane-separation');

        $response->assertStatus(200);
        $response->assertJsonPath('data.policy', 'allow');
        $response->assertJsonPath('data.policy_source', 'company');
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
