# Codex Adversarial Review — Offline-First POS Shifts Phase 5 (B5/B6/F-8)

**Date:** 2026-06-14
**Scope:** `git diff ac1e77707..b8a4d547d` (commit `b8a4d547d` — eager offline-EOD caching)
**Reviewer:** Codex (gpt-5.x-codex), review-only
**Verdict:** REQUEST-CHANGES (confidence 0.86)

> The Codex runtime could not write into the worktree sandbox; this transcribes
> its findings + the lead's disposition.

Confirmed-correct mechanics: `companyId` guarded before `refreshFraudSettingsCache`;
both eager calls run after `scheduler.start()`; rejections caught/logged without
propagating into activation; eager `pullOperatorPins` vs the scheduler pull is
benign (`upsertOperators ... ON CONFLICT DO UPDATE`).

---

## F-1 — HIGH — Empty fraud-settings cache → offline EOD fails OPEN (no cash-count/variance gate)

If `company_fraud_settings_cache` is empty (the activation eager refresh never
succeeded — e.g. a network blip during activation) and the device is offline at
EOD, `Header.tsx` offline catch sets no fraud settings → `fraudSettings` stays
null → `EndOfDayPreviewModal` disables cash reconciliation → the close proceeds
preview-only with `cashCountPayload = null` (no counts, no blind-count
enforcement, no variance severity, no above-hard manager gate).

**Disposition: FOLDED INTO B7.** This is *pre-existing* behaviour — before B6 the
offline path never loaded fraud settings either, so this diff does not regress
it; B6 partially improves it. The complete fail-closed offline-EOD gate (block
the close when the cash-count policy and/or authorized managers are unavailable
offline) is exactly what B7 builds (offline manager-PIN approval). B7 must make
the offline above-hard close FAIL CLOSED rather than degrade to preview-only.

## F-2 — MED — Online EOD fetch did not refresh the durable cache

The live `fetchFraudSettings()` on EOD-open mapped settings into React state but
never wrote them back to `company_fraud_settings_cache`, so a long online shift
that later closes offline would use only the activation-time snapshot.

**Disposition: FIXED** (commit follows). The online EOD-open success path now
`upsertCompanyFraudSettings(db, …)` from the freshly-fetched settings
(non-fatal try/catch), so a later offline close reads fresh thresholds.

## F-3 — MED — No offline authorized-managers cache/read path

Spec B6 calls for caching authorized managers; on the first offline EOD open the
`authorizedManagers` list is `[]`, so an above-hard offline close can't select a
manager to approve.

**Disposition: B7.** The `manager_pins` mirror (B7) provides the offline manager
identities + bcrypt hashes together; the offline managers list + read path land
there. Already deferred when scoping B5/B6/F-8.

## F-4 — LOW — Tests cover the prewarm, not the Header offline-read

`terminalStore.preWarm.test.ts` covers the eager operator-PIN/fraud-cache calls +
error-swallow, but there's no Header test for the offline cache-read mapping
(the EOD modal is null-mocked, making it brittle).

**Disposition: ACCEPTED / B7.** B7's offline-EOD work adds integration coverage
for the full offline close (counts + variance + manager approval).
