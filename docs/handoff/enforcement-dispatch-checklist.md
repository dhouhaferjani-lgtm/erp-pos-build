# Enforcement-guards dispatch checklist (owner/parent) — brief GATED r21, 2026-08-18

Per package (P1 first; P2 parallel-capable; P3 strictly last), from `CODEX-DISPATCH-enforcement-guards-2026-08-12.md`:

## Pre-dispatch (parent/owner)
1. **Upstream gate open?** P1: DPA 3C accepted+merged (`dpa_3c_merge_sha`). P2: quiet window approved + preliminary notice sent (`quiet_window_ack`). P3: P1 + P2 + country-defaults ALL landed (whole-package proofs).
2. **Pre-dispatch commit:** refresh ALL nine `enforcement-control-manifest.yaml` hashes after control files are byte-stable (F-9(i)); commit so `base_sha` contains the manifest.
3. **Create the dispatch receipt** at `~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/enforcement-<pkg>.receipt.yaml` (TEMPLATE alongside; F-9(ii)); name its path in LEDGER S-14.
4. **Fill the YAML pins:** `base_sha`, package-specific landed SHAs, `commit_series` (F-1), `ratchet_trust_model_ack` (F-8/R5-H-1), structured `control_manifest: {path, sha256}`.
5. **Parent host:** PyYAML installed (`pip3 install pyyaml` — the final bridge fails closed without it).

## During the wave
- Executor runs mid-wave milestones under SELF-REVIEW-HARNESS.md; **the FINAL milestone is parent-invoked** via `scripts/adversarial-review-final.sh --receipt … --package <p1|p2|p3> …` (exact template in §5 step 1). Hand-over = `status: review` + exactly one dirty entry (the untracked handback).

## Promotion (S-14 sequence, serialized, fast-forward-only)
0. Freshness assert → 1. parent-run final gate (sealed snapshot; digests from own invocation) → 2. ratchet packages: preflight tag name, set repo variable(s) `DPA_/I18N_BASELINE_PROTECTED_BLOB`, verify read-back; P2: send final announcement → 3. push annotated PIN tag at A (schema incl. `manifest_sha256`; never deleted); workflow-touching: throwaway ref + `workflow_dispatch`, head == A, green, jobs executed → 4/4a. re-assert freshness + tag/annotation/variable; reject contents-write workflow grants; fast-forward EXACTLY A → 5. ONE closing admin commit C (package-specific closing set; preflight before commit) + close tag at C (`{C, pass, A}`) + parent read-back. Re-gate protocol on ANY failure; LEDGER S-14 is the audit row.
