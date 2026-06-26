# R-8 Opus Review

Opus was invoked headlessly as an adversarial post-review for the implemented
R-8 diff:

```sh
perl -e 'alarm shift; exec @ARGV' 120 claude --safe-mode --model opus -p ...
```

The process exited with code 142 after the 120 second alarm and produced no
review output.

Local adversarial review:

- `DocumentLineEditor` calls `useCompanyConfig()` unconditionally and derives
  `canSearchServices` from `hasModule('Workshop')`.
- The `/services` query `enabled` guard is gated by `canSearchServices` and
  `activeSearchTab === 'service'`, so non-Workshop companies cannot trigger the
  client-side service fetch path from this editor.
- The Service tab is hidden when Workshop is disabled.
- Placeholder, result-list branch, and create-new-product footer behavior use
  `activeSearchTab`, so stale local `searchTab === 'service'` state falls back
  to product behavior when Workshop is unavailable.
- Existing `DocumentLineEditor.test.tsx` and tenant-scope mocks were updated to
  provide `hasModule()` so component tests do not crash on the new hook read.
- Invoice/media attachment wiring is untouched.

No issues found in the local review.
