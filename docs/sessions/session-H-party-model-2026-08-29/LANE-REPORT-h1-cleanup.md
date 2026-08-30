# Session H Phase 1 — h1-cleanup lane report

Status: DONE_WITH_CONCERNS. `fix/h1-shape-neutral-cleanup` code HEAD `77d67e4cf`; branch remains unmerged.
Range: `23b1b8a65..77d67e4cf`, accepted milestones M1–M3.
Files: Partner/import PHP + tests; generated PartnerData; POS customer mirrors; web partner/picker/routes/vehicle/locales/tests; E2E and review evidence.
M1: aligned VAT/generated partner shapes, Parties code max 50, and nullable optimistic POS category without sealed-byte/schema changes.
M2: required create Nature, preserved legacy-null B2B visibility, and wired decimal-safe customer credit exposure warnings.
M3: retired Companies, corrected partner/contact gates, composed supplier permissions, scoped Type/vehicle-owner choices, and aligned company-scoped code uniqueness.
Web: combined M1–M3 Vitest 20 files/198 tests; typecheck passed; full lint passed with 0 errors/6,463 existing warnings and 160/160 tool tests.
POS: typecheck passed; touched Vitest 2 files/10 tests.
API: by-path Parties/import replay/partner suites 61 tests/208 assertions; PHPStan no errors; Pint passed on all 8 touched PHP files.
Repository gates: feature-lane manifest passed with standing notices; range diff-check passed after three review-only EOF repairs; final status clean.
Browser evidence: committed M1/M2/M3 specs; recorded runs M1 3/3, M2 3/3, M3 4 passed/1 named runtime skip.
Screenshots: all 10 nonempty artifacts present under `.playwright-mcp/session-h/{m1,m2,m3}/`.
Fixture cleanup: `demo-pharmacy-tn` has 0 active `session-h-m3-*` users and 0 temporary roles; deleted-user audit rows follow API deactivation semantics.
Deferred M1: public import row errors remain 201 + error resource, not a dedicated 422 preview contract.
Deferred M2: Arabic Nature/Type label collision, inline-modal Nature gap, supplier credit semantics, and accepted legacy-null UI edges remain parent/Phase 2 work.
Deferred M3: client communication for cross-company code 422→201, Otospex unprovisioned-DB browser skip, i18n burn-down, form-context collapse, and pre-existing vehicle enum drift remain owed_parent.
Session G: legacy `ImportType::Partners`, its rules/card/deprecation surface, and ownership remain untouched.
Concerns: known lint/React-test warning noise and the named Otospex skip only; no Critical/Important integration finding remains.
Second-of-everything: second-company = CreatePartnerTest::test_second_company_can_reuse_partner_code_with_independent_nature_and_list_scope:207 + UpdatePartnerTest::test_can_reuse_another_companys_code_without_opening_cross_company_updates:202 + PartnerForm.test.tsx:168; second-location = N/A — `partners` carries no location binding (create_partners_table 2025_11_30_052119); re-run = CreatePartnerTest::test_rejects_a_duplicate_code_in_the_same_company:261 (G1.1).
