# `Document::$partner_id` is annotated non-nullable and has not been for months

**Raised by** treasury gate P3-2 on `feat/r2f4-correcting-documents`, 2026-08-21.
**Status** OPEN. Repo-wide sweep; deliberately not attempted inside the r2f4 lane.

---

## The lie

`apps/api/app/Modules/Document/Domain/Document.php:44`

```php
 * @property string $partner_id
```

`documents.partner_id` has been **nullable** since
`database/migrations/tenant/2026_06_27_110000_make_documents_partner_id_nullable.php`.
Verified against the live schema — the column has no `NOT NULL`.

## Why it is not merely untidy

PHPStan believes the annotation. It is the declared type, so at level 8 the
analyser treats `$document->partner_id` as `string` and **reports a null check on
it as dead code**:

```
Strict comparison using === between string and null will always evaluate to false.
```

That is the exact error the r2f4 lane hit when adding a genuine null guard. The
annotation does not just fail to help — it actively argues against the correct
code.

## The live consequence

`AccountingService::refreshPartnerBalanceAfterGlPersistence()` takes
`string $partnerId`. Four call sites pass `$document->partner_id` straight in:

| Call site | Context |
|---|---|
| `AccountingService.php:601` | `createInvoiceGLEntries` |
| `AccountingService.php:814` | credit-note GL |
| `AccountingService.php:1044` | `reverseDocumentGl` |
| `AccountingService.php` (correcting path) | fixed in-lane — resolves per leg, calls only for non-null |

PHPStan cannot see the risk on any of them. The r2f4 lane reproduced it: on a
partnerless target the pre-fix correcting path died with

```
refreshPartnerBalanceAfterGlPersistence(): Argument #2 ($partnerId)
must be of type string, null given
```

— a 500, from a `TypeError`, on a code path the analyser had signed off. The
other three sites are reachable by the same route (a partnerless invoice or
credit note); nothing has exercised them yet.

## Fix

1. Correct the annotation to `@property ?string $partner_id`.
2. Expect PHPStan to go red across the repo. **The failures are the inventory** —
   each is a place that assumed a partner and never checked. Do not suppress
   them, and do not widen `refreshPartnerBalanceAfterGlPersistence()`'s parameter
   to `?string` as a shortcut: that would silently skip the refresh instead of
   deciding what a partnerless document's balance refresh means.
3. Decide per call site: guard and skip, or refuse. For GL paths, "skip the
   refresh, log nothing" is probably wrong — a document with no partner posting
   to a partner control account is the case r2f4 now refuses outright.
4. Sweep for the same class of drift: other `@property` annotations that predate
   a nullability migration. Worth a one-off script comparing model annotations
   against `information_schema.columns`.

## Interim

`AccountingService::usablePartnerId()` reads the attribute through
`getAttribute()` so `is_string()` proves what the docblock only asserts. It is a
local defence with a comment pointing here — delete it when this lands.
