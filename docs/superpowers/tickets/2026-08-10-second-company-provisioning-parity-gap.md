# Ticket: second-company provisioning parity gap — no payment repositories/methods seeded (M-8, pre-existing)

**Filed:** 2026-08-10 (DPA session 2 — H-3 gate M-8 debt; corroborated independently by the SEEDS lane)
**Owner:** unassigned — Company/Treasury provisioning
**Severity:** IMPORTANT latent (low real-world risk today: multi-branch is modelled as Locations, not Companies)

## Defect

`apps/api/app/Modules/Company/Presentation/Controllers/CompanyController.php:117` creates
additional companies WITHOUT the registration-time provisioning steps. Consequences for a tenant's
second company:

1. **Zero payment repositories** → `TenderRepositoryResolver` returns null → `TreasuryReceiptBridge`
   throws on the first tender. The "every tenant is born with two repositories" guarantee
   (H-3 lane) is per-company-at-registration only.
2. **Zero payment methods** (same gap, SEEDS-lane observation 2026-08-10).
3. ~~Zero expense categories~~ — **CLOSED** by SEEDS fix rounds 1–2 (`4fece134a` + `22e630654`:
   `ExpenseCategoryProvisioningService` injected into `CompanyController` step 5.5).

## Fix shape

Extend the second-company path with the same provisioning steps registration gets
(`TenantInitializationService` is the reference sequence). The SEEDS lane's
`ExpenseCategoryProvisioningService` wrapper (Expense Application layer) is the house pattern to
copy for repositories/methods: thin Application-layer service the controller injects, so deptrac
stays clean and the seeder isn't invoked from Presentation.

## History

- H-3 gate review M-8 (2026-08-10, `gate-h3-review.md:258`) — pre-existing, out of H-3's lane.
- SEEDS fix-round exit report (2026-08-10) — same gap found from the expense-category side;
  provisioning-parity ticket noted there as owed.
