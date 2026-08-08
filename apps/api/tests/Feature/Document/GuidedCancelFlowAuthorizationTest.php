<?php

declare(strict_types=1);

namespace Tests\Feature\Document;

use App\Modules\Company\Domain\Enums\MembershipRole;
use App\Modules\Company\Domain\Enums\PeriodStatus;
use App\Modules\Company\Domain\FiscalPeriod;
use App\Modules\Company\Domain\UserCompanyMembership;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\ReturnDecisionMode;
use App\Modules\Document\Domain\Exceptions\ReturnDecisionForbiddenException;
use App\Modules\Identity\Domain\Enums\UserStatus;
use App\Modules\Identity\Domain\User;
use App\Modules\Inventory\Domain\StockMovement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;
use Tests\Traits\BuildsCancelFlowFixtures;

/**
 * Gate CF fix round 1 — CF-D8's per-leg authorization, end to end. [PG]
 *
 * The route carries `can:invoices.cancel`; the composite ADDITIONALLY authorizes
 * `deliveries.create` before creating the return note and `deliveries.confirm` before
 * confirming it — the exact abilities the standalone routes require. Default roles make
 * that a no-op, which is why it is easy to dismiss as dead code. It is not: roles are
 * TENANT-EDITABLE, and a composite silently performing a leg the caller could not
 * perform standalone is a privilege-escalation seam.
 *
 * The gate found the whole leg untested AND its typed 403 unreachable:
 * `ReturnDecisionForbiddenException extends AuthorizationException`, not
 * `\DomainException`, so `RefundController`'s generic `catch (\Exception)` swallowed it
 * before Laravel could convert it and before the dedicated `bootstrap/app.php` arm was
 * consulted. It was delivered as
 * `{error: "This action is unauthorized.", code: "This action is unauthorized."}` — the
 * untyped envelope frontend I-1 exists to eliminate — so `extractErrorCode` yielded
 * undefined and the "ask a manager" copy T12 wrote could never render.
 *
 * The SAFETY property always held (the refusal is raised inside the transaction, before
 * `performCancel()`), and these tests pin that too: nothing is written on refusal.
 *
 * Registered in the PostgreSQL merge gate by T17.
 */
final class GuidedCancelFlowAuthorizationTest extends TestCase
{
    use BuildsCancelFlowFixtures;
    use RefreshDatabase;

    private string $issuedOn;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCancelFlowFixtures('cf-authz');
        FiscalPeriod::query()->where('company_id', $this->cfCompany->id)->update([
            'status' => PeriodStatus::Open,
        ]);
        $this->issuedOn = Carbon::today()->subDays(5)->toDateString();
    }

    public function test_a_caller_without_deliveries_create_is_refused_the_typed_403(): void
    {
        $invoice = $this->deliveredInvoice();
        $user = $this->userWithAbilities(['invoices.cancel', 'invoices.view', 'deliveries.confirm']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ReturnDecisionForbiddenException::CODE)
            ->assertJsonPath('error.ability', 'deliveries.create');

        $this->assertNothingHappened($invoice);
    }

    /**
     * `already_returned` needs BOTH legs. A caller who may create a draft return note but
     * not confirm one must not have the composite seal it on their behalf.
     */
    public function test_a_caller_without_deliveries_confirm_is_refused_for_already_returned(): void
    {
        $invoice = $this->deliveredInvoice();
        $user = $this->userWithAbilities(['invoices.cancel', 'invoices.view', 'deliveries.create']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => [
                    'mode' => ReturnDecisionMode::AlreadyReturned->value,
                    'returned_on' => Carbon::today()->toDateString(),
                ],
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', ReturnDecisionForbiddenException::CODE)
            ->assertJsonPath('error.ability', 'deliveries.confirm');

        $this->assertNothingHappened($invoice);
    }

    /**
     * `will_return` only creates a draft, so `deliveries.confirm` must NOT be demanded —
     * over-authorizing would block a legitimate caller.
     */
    public function test_will_return_does_not_require_the_confirm_ability(): void
    {
        $invoice = $this->deliveredInvoice();
        $user = $this->userWithAbilities(['invoices.cancel', 'invoices.view', 'deliveries.create']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer cancelled',
                'return_decision' => ['mode' => ReturnDecisionMode::WillReturn->value],
            ])
            ->assertOk();

        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);
    }

    /**
     * A non-goods decision performs no delivery leg at all, so neither ability is
     * required — the composite must not demand permissions for work it never does.
     */
    public function test_a_no_return_decision_requires_neither_delivery_ability(): void
    {
        $invoice = $this->deliveredInvoice();
        $user = $this->userWithAbilities(['invoices.cancel', 'invoices.view']);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/invoices/{$invoice->id}/cancel", [
                'reason' => 'Customer kept them',
                'return_decision' => ['mode' => ReturnDecisionMode::NoReturn->value],
            ])
            ->assertOk();

        self::assertSame(DocumentStatus::Cancelled, $invoice->refresh()->status);
    }

    private function assertNothingHappened(Document $invoice): void
    {
        $invoice->refresh();

        self::assertSame(DocumentStatus::Posted, $invoice->status, 'The refusal must roll the cancel back.');
        self::assertArrayNotHasKey('return_decisions', $invoice->payload ?? []);
        self::assertSame(0, Document::query()->where('type', DocumentType::ReturnNote)->count());
        self::assertSame(0, StockMovement::query()->count());
    }

    /**
     * @param  list<string>  $abilities
     */
    private function userWithAbilities(array $abilities): User
    {
        $user = User::create([
            'tenant_id' => $this->cfTenant->id,
            'name' => 'CF Limited User',
            'email' => 'cf-limited-'.bin2hex(random_bytes(3)).'@example.com',
            'password' => bcrypt('password'),
            'status' => UserStatus::Active,
        ]);

        // Deliberately NOT `assignRole('admin')` — the whole point is a tenant-edited
        // role whose ability set is a strict subset.
        $user->givePermissionTo($abilities);

        UserCompanyMembership::create([
            'user_id' => $user->id,
            'company_id' => $this->cfCompany->id,
            'role' => MembershipRole::Manager,
        ]);

        return $user;
    }

    private function deliveredInvoice(): Document
    {
        $dn = $this->cfConfirmedDeliveryNote([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]], ['document_date' => $this->issuedOn]);

        $invoice = $this->cfPostedInvoice([[
            'product_id' => $this->cfProduct->id,
            'quantity' => '4.0000',
            'unit_price' => '100.000',
        ]], ['document_date' => $this->issuedOn]);

        $this->cfLinkInvoiceToDeliveryNotes($invoice, [$dn]);

        return $invoice->refresh();
    }
}
