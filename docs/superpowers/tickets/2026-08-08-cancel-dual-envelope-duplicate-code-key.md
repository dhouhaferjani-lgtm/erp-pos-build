# Follow-up — delete the duplicated top-level `code` on the cancel 422

**Raised by:** gate CF code review (fiscal half), ruling (b). **Status:** OPEN.
**Size:** small, but it MUST be one commit — the renderer and the three assertions move
together or the suite goes red.

## What ships today, and why

`POST /api/v1/invoices/{id}/cancel` returns, for `DOCUMENT_HAS_PAYMENTS`:

```jsonc
{
  "error": { "code": "DOCUMENT_HAS_PAYMENTS", "message": "…", "document_id": "…", "document_number": "…" },
  "code":  "DOCUMENT_HAS_PAYMENTS"          // ← the duplicate
}
```

The typed `error.code` is the CONTRACT — it is what `extractErrorCode`
(`apps/web/src/utils/errorHandling.ts`) reads, and the whole point of plan CF's T6
(frontend gate I-1) was to stop this refusal arriving through the generic flat envelope
`{error: <string>, code: <string>}`, against which `getErrorMessage` yields axios' bare
*"Request failed with status code 422"* and `extractErrorCode` yields `undefined`.

The top-level `code` exists **solely** to keep three pre-existing assertions green:

- `apps/api/tests/Feature/Document/DocumentCancelConsolidationTest.php` — three
  `assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS')` calls.

Plan CF §2 names that class as part of the lane's regression contract ("stays green
**unmodified**") while the same plan requires the typed envelope. Both are satisfiable
only by emitting both keys, so both ship — documented at the renderer
(`apps/api/bootstrap/app.php`, the `DocumentHasPaymentsException` arm), on the exception
class, and pinned deliberately in `GuidedCancelFlowTest`.

## Why it must not stay

The gate's ruling, verbatim in substance: *keep both for this merge; file a follow-up
that deletes the top-level `code` together with those three assertions in one commit —
otherwise an undocumented duplicate key silently becomes a second de-facto contract,
which is how the untyped envelope got entrenched in the first place.*

That is the actual risk. A duplicate that nobody removes is indistinguishable, to the
next reader, from a deliberate dual contract; the next client to be written will read
whichever one it happens to find first, and then removing either becomes a breaking
change.

## The work — ONE commit

1. `apps/api/bootstrap/app.php`: drop `'code' => DocumentHasPaymentsException::CODE`
   from the `DocumentHasPaymentsException` render arm (keep `error.code`).
2. `apps/api/tests/Feature/Document/DocumentCancelConsolidationTest.php`: change the
   three `assertJsonPath('code', 'DOCUMENT_HAS_PAYMENTS')` to
   `assertJsonPath('error.code', 'DOCUMENT_HAS_PAYMENTS')`.
3. `apps/api/tests/Feature/Document/GuidedCancelFlowTest.php`: drop the deliberate
   `assertJsonPath('code', …)` line in
   `test_document_has_payments_refuses_before_anything_is_created` and its explanatory
   comment.
4. Delete the backward-compatibility notes at the two sites named above, and this ticket.

## Acceptance

- `DocumentCancelConsolidationTest` and `GuidedCancelFlowTest` green.
- `grep -rn "'code' =>" apps/api/bootstrap/app.php` shows only keys nested under
  `error`.
- No client reads a top-level `code` on any cancel response
  (`grep -rn "data.code" apps/web/src` → no hits on the cancel path).
