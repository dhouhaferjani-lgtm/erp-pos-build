# Ticket: 3 minor findings from the W-2 (config/pricing) + IDEM/RET/UOM money-campaign wave

From the W-2 execution agent's money-campaign leg (2026-08-02) — new surfaces `IDEM`, `UOM`,
`FRD`, `RET`, plus the config/pricing wave. Live-proven against demo-pharmacy-tn. None block
launch on their own; grouped here per the house pattern for wave-residue findings (cf.
`2026-08-02-treasury-fix-lane-minor-followups.md`).

## 1 (P2) — Payment-method GL routing fields are write-only: never surfaced by any read path

`PaymentMethodController::formatMethod()` (`apps/api/app/Modules/Treasury/Presentation/Controllers/PaymentMethodController.php:264-286`)
— the shape used by `store()`'s 201 response, `update()`'s 200 response, AND `index()` — omits
`default_account_id` and `fee_account_id` entirely. Only `default_repository_id` survives.

Live-proven (`MTP-PMT-01`, `MTP-PMT-03`, `apps/web/e2e/money-campaign/config-pricing.spec.ts`):
POSTing/PATCHing a payment method with `default_account_id`/`fee_account_id` returns 201/200 and
the columns ARE written (confirmed via direct DB read), but no API response — create, update, or
list — ever reports them back. A treasury admin configuring GL routing through this endpoint (or
any future FE settings page built on it) has no way to verify what account is actually wired up
without a database query.

**Fix direction:** add both fields to `formatMethod()`.

## 2 (P1/P2, correctness risk) — "Default" price list resolution is non-deterministic once more than one exists

`PricingService::getDefaultPriceListPrice()` (`apps/api/app/Modules/Pricing/Domain/Services/PricingService.php:201-214`)
resolves the company's default TND price list via a bare `->first()` with no explicit ordering.
Neither `PricingController::store()` nor `update()` enforce a uniqueness constraint on
`is_default=true` per `(company_id, currency)` — nothing stops a second (or third) default list
from being created.

**Consequence:** once 2+ active `is_default=true, currency=TND` price lists exist for a company,
which one actually prices a given quote/invoice line becomes an accident of Postgres row order,
not a defined precedence rule. Live-reproduced while authoring `MTP-PRC-03`
(`apps/web/e2e/money-campaign/config-pricing.spec.ts`): the SAME test flipped PASS→FAIL between
two runs purely because an earlier run's leftover default list won the `->first()` race instead
of the current run's own list. On a real tenant this would surface as "the price I set on my
default list isn't the price the system actually charges," with no error, no warning, and no way
to tell why from the UI.

**Fix direction:** either (a) enforce single-default via a partial unique index / application
guard (auto-un-defaulting the prior one on assignment, same pattern many "default X" features
use), or (b) make `getDefaultPriceListPrice()`'s tiebreak explicit and documented (e.g.
`ORDER BY created_at DESC` / `updated_at DESC`) if multiple defaults are an intentional
possibility. (a) is almost certainly the right call — "default" implies singular.

## 3 (P2, UX/defense-in-depth) — Payment CREATE has no default idempotency protection; the only UI never opts in

`PaymentController::store()` supports an `Idempotency-Key`/`idempotency_key` dedupe mechanism
(proven live via `MTP-IDEM-06`), but it is **opt-in** — omitting it entirely (the default) lets
the exact same request, submitted twice, create two real, separately-allocated payment rows
(`MTP-IDEM-05`, live-reproduced: two `201`s, two distinct payment ids, both allocations landed on
the same invoice). This matches the behaviour the codebase's own
`PaymentIdempotencyTest.php`'s `no_idempotency_key_behaves_exactly_as_before...` test documents as
intentional at the API layer.

The gap: `RecordPaymentModal.tsx` — the only production UI that creates a payment — never sends
an `Idempotency-Key` header or `idempotency_key` body field. Its only double-submit guard is a
client-side `disabled={mutation.isPending}` on the submit button. A lost disable-race (slow
network, a resumed/duplicated tab, a browser-level request retry) creates a real duplicate cash
receipt with zero server-side backstop.

**Fix direction:** have `RecordPaymentModal` generate a stable idempotency key (e.g. on mount /
form-open) and send it with every submit attempt for that form instance, mirroring how
`refund_request_id` is already generated client-side for refunds.

## Disposition

None fixed here (money-campaign agent scope is spec/ticket-only). Recommend folding #1 into
whichever lane next touches `PaymentMethodController`; #2 into the next pricing-module lane (flag
as correctness-risk, not just cosmetic); #3 into the treasury/POS UI hardening backlog alongside
the existing refund-idempotency pattern it should mirror.
