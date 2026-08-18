# SV Stage 1 — carried M5 P3s

M5 accepted the Stage-1 lane with the following non-blocking debt. These items are deliberately
ticketed rather than folded into the accepted SV-1/SV-9/SV-10/SV-11 scope.

## 1. SV-12 rounding decomposition

The six-line drawer reveal can differ from authoritative `expected_cash` by one minor unit for
representable sub-scale inputs because `cash_sales_net` and `expected_cash` round through differently
nested decimal operations. The existing regression pins the difference; Stage 1 must not regroup the
authoritative expression to make the display add up.

Acceptance for SV-12:

- add the dossier-owned rounding decomposition without changing sealed/authoritative expected cash;
- make the displayed rows reconcile to the displayed expected value for the pinned fractional case;
- keep all money as decimal strings and preserve the existing blind-mode reveal boundary; and
- retain rendered English/French coverage for the complete summary.

## 2. Arabic compliance fallback merge

`apps/web/src/lib/i18n.ts` merges the Arabic compliance resource through three fixed object levels.
A future partial Arabic key under an unlisted sibling can replace its English object rather than merge
with it, silently dropping fallback strings.

Acceptance:

- replace the fixed-depth merge with a tested merge contract appropriate for locale resources, or
  explicitly cover every supported partial subtree;
- prove an Arabic leaf can be supplied while missing sibling leaves still resolve from English; and
- preserve the current rendered Arabic blind-count label test.

## 3. Vertical-default compatibility API

`CompanyFraudSettings::defaultsForVertical(bool $_isAutomotive)` now ignores its parameter and returns
the shared defaults. Its sole caller still computes the vertical boolean in the historical
`2026_04_25_100001_seed_company_fraud_settings_for_existing_companies.php` migration. The method name
therefore advertises a vertical policy split that no longer exists.

Acceptance:

- enumerate every caller before changing the compatibility surface;
- remove the dead vertical computation/parameter or replace the misleading API with an explicitly
  named compatibility shim that cannot be read as live vertical policy;
- preserve fresh-install migration behavior and the binding blind-count-on default for both verticals;
  and
- keep the red-by-design vertical-default tests explicit.

## Related carried UX ticket

The two policy-refresh display P3s from M4 round 5 remain in
`docs/superpowers/tickets/2026-08-17-blind-count-policy-unavailable-close.md`: blank body during a
pending refresh in `confirming`, and a false policy-not-synced error with an inert Cancel action.
