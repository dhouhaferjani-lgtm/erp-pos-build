# Adversarial brief gate — round 11

**Target:** `docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md` r19, `docs/handoff/progress/enforcement-p1.progress.yaml`, `enforcement-p2.progress.yaml`, `enforcement-p3.progress.yaml`, and `docs/handoff/LEDGER.md` rows S-14/S-15, together with `scripts/adversarial-review-final.sh`, `docs/handoff/enforcement-control-manifest.yaml`, and the out-of-repo dispatch-receipt template, reviewed as one integrated uncommitted working-tree dispatch package on `dev` at `a5520f23c`.

**Prior inputs:** gate registers r1 (`4C/11H/2M`), r2 (`2C/7H/2M`), r3 (`2C/4H/0M`), r4 (`2C/5H/0M`), r5 (`3C/4H/0M`), r6 (`1C/5H/0M`), r7 (`2C/2H/0M`), r8 (`1C/3H/1M`), r9 (`1C/4H/1M`), r10 (`1C/4H/2M`), and `docs/superpowers/reviews/2026-08-17-enforcement-guards-brief-round0-r19.md` (PASS). All eleven were read before this gate. Round-0 mechanics were not repeated; only finding-dependent source and behavior spot checks were made.

**Mode:** hostile, read-only review of the dispatch package; no implementation, no acceptance-command execution, and no target-file edits. The only write is this report.

## Verdict

**CHANGES-REQUIRED.** r19 repairs the receipt authority, fail-closed YAML projection, verdict finality, canonical paths, refreshed hashes, and universal close-tag read-back. One High defect remains: P3's operative M0 text asks the close tag at C for the manifest/control fields that the producer places on the pin tag at A, making the required P1/P2 evidence conjunction either unsatisfiable or dependent on an undocumented substitution. Two Minor control-contract inconsistencies remain. The High repair is a narrow tag-consumer clarification and does not require new P1/P2/P3 application architecture.

## Round-10 resolution audit

| Round-10 finding | R11 disposition | Re-derived result |
|---|---|---|
| R10-C-1 | **RESOLVED** | The parent-owned template exists at the named out-of-repo location and defines package, true base, canonical manifest path/digest, and package projection fields (`~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/TEMPLATE.receipt.yaml:2-16`). The bridge accepts `--receipt`, loads package/base/manifest path/digest from it, authenticates the manifest before parsing, requires the receipt base to be a strict ancestor of A, field-checks candidate `base_sha` and `control_manifest`, and rejects canonical or surrogate control-manifest changes (`scripts/adversarial-review-final.sh:27-44,52-80,127-164`). The old candidate-selected later-base/alternate-manifest scenario therefore fails closed. The unused duplicate receipt projection fields are a fresh Minor, R11-M-2. |
| R10-H-1 | **RESOLVED** | The bridge requires PyYAML before reading either receipt or progress YAML and has no partial regex mode (`scripts/adversarial-review-final.sh:49-50`). Its full projection check always requires matching base, top-level max, structured manifest pin, exactly one final milestone, `status: review`, and an in-budget fix-round count (`scripts/adversarial-review-final.sh:130-154`). PyYAML is absent on this host, but that now causes exit 3 rather than skipped predicates, and F-9 names installation as a parent pre-dispatch prerequisite (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:480`). |
| R10-H-2 | **PARTIAL** | The pin-tag producer now carries `manifest_sha256` as line 2, step 4a re-verifies the exact annotation, and S-14 repeats the manifest-bearing pin-tag payload (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:462-463`; `docs/handoff/LEDGER.md:56`). The bridge also emits the manifest and control values that must feed that tag (`scripts/adversarial-review-final.sh:101-121,220-231`). But P3's operative consumer associates the manifest/control comparison with the later close tag, whose producer schema has only C/check/A. The end-to-end identity link is therefore not yet executable as written; see R11-H-1. |
| R10-H-3 | **RESOLVED** | The bridge still requires exactly one exact verdict line and now also compares it to the final non-empty output line before accepting (`scripts/adversarial-review-final.sh:234-242`). An exact ACCEPT followed by a correction reaches exit 3, not exit 0. |
| R10-H-4 | **RESOLVED** | Receipt, candidate, handback destination, and output are canonicalized before receipt parsing or copy/write side effects; the two output destinations are rejected inside the candidate, and the receipt-supplied manifest must be absolute and present (`scripts/adversarial-review-final.sh:46-80`). Only absolute snapshot/handback paths reach the neutral-cwd prompt (`scripts/adversarial-review-final.sh:175-203`). The prior relative-handback failure is closed. |
| R10-M-1 | **RESOLVED** | Fresh re-derivation against current bytes matches every manifest pin: bridge, brief, harness, and all six lens contracts (`docs/handoff/enforcement-control-manifest.yaml:9-33`). In particular, the on-disk harness hashes to the value at `enforcement-control-manifest.yaml:14`; the former whitespace drift no longer burns a tool attempt. |
| R10-M-2 | **RESOLVED** | Step 5 now requires the parent after every close-tag push, including P3/no-workflow, to re-fetch the tag, verify its target is C, and require exactly one each of `closing_commit == C`, `closing_check == pass`, and `accepted_sha == A` before closure (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:464`). S-14 carries the same universal producer read-back (`docs/handoff/LEDGER.md:56`). |

## Critical findings

None.

## High findings

### R11-H-1 — P3 reads manifest/control identity from the wrong tag, so its P1/P2 evidence gate cannot be executed literally

**Evidence:** The package deliberately creates two distinct owner-authenticated objects. The pin tag `ci-pin/enforcement-<pkg>-r<n>` points at A and carries `accepted_sha`, `manifest_sha256`, register/handback digests, and `control_sha256` lines (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:462`). The later close tag `ci-close/enforcement-<pkg>-r<n>` points at C and has exactly `{closing_commit, closing_check, accepted_sha}` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:464`). The bridge supplies the manifest line and the concrete control set—bridge, brief, harness, and package lenses—for the pin-tag payload (`scripts/adversarial-review-final.sh:101-125,220-231`).

P3 first describes the P1 close tag at C and its three fields, then requires **its** `control_sha256`/`manifest_sha256` lines to match the dispatch manifest (`docs/handoff/progress/enforcement-p3.progress.yaml:39-43`). The P2 clause similarly mixes the A-tag name with the close-tag-at-C predicates and leaves the control/manifest comparison attached to that combined “closing tag” object (`docs/handoff/progress/enforcement-p3.progress.yaml:52-58`). That contradicts the r19 rule that P3 parses manifest identity from the **pin tag** (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:110`) and the actual close-tag producer schema.

**Failure scenario:** P1 and P2 are promoted exactly as §5 requires. Each A pin tag contains the manifest/control lines, while each C close tag contains only `closing_commit`, `closing_check`, and `accepted_sha`. At P3-M0, a literal implementation asks the C tag for fields it cannot contain and blocks forever. If the executor instead silently skips those absent fields or guesses that the A tag was intended, M0 no longer implements its operative text and can omit the promised manifest-identity comparison. No malicious parent, owner, or actor outside the recorded trust model is required.

**Required repair:** Rewrite P3 1d/2d as two separately named tag predicates. The A pin tag must resolve to the landed accepted tip and contain exactly one manifest digest, every register/handback digest, and the exact control-name set emitted by the bridge—including `harness`; compare those values with the package's dispatch-base manifest/control pin and the landed register. The C close tag must resolve to derived C and contain only the exact C/check/A schema. Apply the same explicit separation to the brief/YAML summaries so “closing tag” cannot name both objects.

## Minor findings

### R11-M-1 — The nine-argument invocation is executable, but its operator-facing documentation still names removed inputs and an invalid package placeholder

**Evidence:** The live parser accepts exactly nine options: receipt, package, candidate, accepted, handback-source, handback, round, attempt, and out (`scripts/adversarial-review-final.sh:27-40`). The exact brief template names the same option set, but spells the package value as `<p_n_>` (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:460`), whereas the receipt and manifest use only `p1`, `p2`, or `p3` (`~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/TEMPLATE.receipt.yaml:9`; `docs/handoff/enforcement-control-manifest.yaml:34-49`). The executable's current r19 trust-design header still tells the reader that the parent supplies `--expected-manifest-sha256` and `--base`, although both options were removed from the parser (`scripts/adversarial-review-final.sh:5-12,27-40`).

**Failure scenario:** an operator follows the executable's own header and supplies either removed option, or substitutes the exact `<p_n_>` placeholder literally. The parser exits 3 on the obsolete option or the receipt-package equality check fails, consuming a parent-owned tool attempt even though the candidate is valid. This fails closed and is therefore Minor.

**Required repair:** Update the script header to describe `--receipt` as the sole base/manifest authority and change the brief placeholder to `<p1|p2|p3>` (or provide three concrete invocations).

### R11-M-2 — Four authoritative-looking receipt fields are not consumed or cross-checked

**Evidence:** The shipped receipt schema includes `progress_path`, `final_milestone`, `lenses`, and `max_fix_rounds` (`~/.claude/projects/-Users-houssamr-Projects-syneriva-apps-erp/memory/dispatch-receipts/TEMPLATE.receipt.yaml:13-16`), matching the r19 schema claim (`docs/handoff/CODEX-DISPATCH-enforcement-guards-2026-08-12.md:108`). The bridge reads only receipt `package`, `base_sha`, `manifest_path`, and `manifest_sha256` (`scripts/adversarial-review-final.sh:60-75`); it obtains the four projection values solely from the authenticated manifest (`scripts/adversarial-review-final.sh:113-125`) and never compares them with their receipt copies.

**Failure scenario:** an honest parent creates a P1 receipt with the correct package/base/manifest authority but accidentally leaves P2's progress path, final milestone, lenses, and max in the lower half. The final gate safely uses P1's manifest values and can ACCEPT, while the durable parent receipt falsely describes what it controlled. Later reconstruction sees two conflicting owner records and has no bridge-produced mismatch to identify the stale half. This does not permit false acceptance because the authenticated manifest wins, so it is Minor.

**Required repair:** After manifest authentication, compare all four receipt projection fields with the selected manifest package block and fail closed on mismatch, or remove those duplicate fields from the authoritative receipt schema and state that the manifest alone owns them.

## Round-0 r19 note reassessment

None of the eight note-only observations independently rises to a finding. PyYAML's absence is a real owner action, but the bridge fails closed and F-9 makes installation an explicit pre-dispatch prerequisite. Null `control_manifest` pins, template-only receipt state, and uncommitted control artifacts are likewise explicit pre-dispatch states with fail-closed M0/F-9 conditions. The inline nine-option enumeration is mechanically complete; only its package-value placeholder and the executable's stale header rise to the Minor R11-M-1. The tag-object mismatch in R11-H-1 is not a round-0 note elevation: it is a fresh endpoint-link contradiction in the P3 consumer.

## Required disposition

Do not dispatch r19 as written. **R11-H-1 is dispatch-blocking:** distinguish the A pin-tag manifest/control predicates from the C close-tag C/check/A predicates before P1/P2 are promoted or P3's M0 is relied upon. This is a focused control-plane edit that a fix round can absorb without new architecture. **R11-M-1 and R11-M-2 are absorbable owner-ruled dispatch-time conditions:** use only the live nine-option parser with package `p1`/`p2`/`p3`, and ensure the receipt's duplicate projection fields equal the authenticated manifest; record both for the fix-at-dispatch-time list if they are not edited immediately. Re-run round-0 only where the tag-consumer/control hashes change, then perform the next adversarial gate against the resulting integrated working-tree revision.
