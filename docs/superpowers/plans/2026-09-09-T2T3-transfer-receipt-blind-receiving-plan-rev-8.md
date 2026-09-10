# Execution plan T-2 / T-3 — transfer destination receipt (partial + discrepancy), close dispositions, blind receiving, minimum notifications (rev 8)

Date 2026-09-10 · Status: REV 8 — **DISPATCH-READY** per gate r6 (rev 8 = rev 7 + the §6.7b/§7.2 movement-creation amendment ruled 2026-09-10 on the S1 handback's blocking finding; the amendment is reviewed by stock-gl-interaction-reviewer at the S1 implementation gate, not by a new plan gate); rev 7 was (`docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r6.md`, 0 BLOCKER / 0 MAJOR / 1 MINOR); rev 7 differs from rev 6 ONLY by the four provenance sentences of gate r6 m1 and by the authority pointers (rev 6 → rev 7), so it needs no further plan gate · Lanes T-2, T-3, T-4-min · Four independently dispatchable ERP slices S1–S4 **plus one sibling-repository packet S4-mobile**.

**Supersedes** `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-7.md` (REV 7), which itself superseded `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-6.md` (REV 6, the gated text), `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-5.md` (REV 5), `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-4.md` (REV 4), `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-2.md` (REV 2) and `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-1.md` (REV 1). Rev 7 is the verbatim base of this document and is retained unchanged for diff review; **rev 8 is the only authority for implementation**. Nothing in this plan is stated "as in rev 1", "as in rev 2" or "as in rev 3": every contract an implementer needs is reproduced here.

**Gate closed by this revision:** `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r6.md` — **VERDICT: DISPATCH-READY**, 0 BLOCKER, 0 MAJOR, 1 MINOR (m1: four provenance sentences mixed the rev-4/5/6 pins), closed in §0A.

**Gate closed by the previous revision (rev 6):** `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r5.md` — **VERDICT: CHANGES-REQUIRED**, 0 BLOCKER, 1 MAJOR (M1: the dispatch packets and the embedded promotion checklist still named superseded rev 4 as authority), 3 MINOR (m1 a second "23"; m2 stale repin provenance labels; m3 one S1 checklist paragraph contradicting the inventory), all closed in §0A.

**Gate closed two revisions ago (rev 5):** `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r4.md` — **VERDICT: CHANGES-REQUIRED**, 0 BLOCKER, 1 MAJOR (M1: the S4 race child process could not autoload the test-local `RaceProbeNotification`), 1 MINOR (m1: "23" vs 24 Feature classes), plus the four NOT CLOSED rows of its Round-3 closure table, all closed in §0A.

**Gate closed three revisions ago (rev 4):** `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r3.md` — **VERDICT: CHANGES-REQUIRED**, 1 BLOCKER (B1), 3 MAJOR (M1–M3), 1 MINOR (m1), plus every NOT CLOSED / REGRESSED row of its "Round-2 closure table", every non-OK row of its "Slice executability" table, the one issue raised under "Source-ledger verification", and every non-`PRESENT` row of both sub-tables of its "Spec-fidelity table". Every one of those is dispositioned in §0A. The gate's "Preserve" list, its "Rejected false positives" list and its "Citation audit" findings are carried into rev 4 untouched except where a finding orders a change.

**Gate closed four revisions ago (rev 3):** `docs/superpowers/reviews/2026-09-09-t2-t3-plan-codex-gate-r2.md` — **VERDICT: CHANGES-REQUIRED**, 1 BLOCKER (B1), 6 MAJOR (M1–M6), 1 MINOR (m1), plus the non-OK rows of its "Slice executability" table and of all four sub-tables of its "Spec fidelity matrix". Every one of those is dispositioned in §0B, and gate r3's "Round-2 closure table" confirms which of them are CLOSED — all but r2-M1, which gate r3 records as REGRESSED and which §0A finishes.

**Gate closed five revisions ago (rev 2):** `docs/superpowers/reviews/2026-09-09-t2-t3-plan-codex-gate-r1.md` — **VERDICT: CHANGES-REQUIRED**, 1 BLOCKER (B1), 8 MAJOR (M1–M8), 1 MINOR (m1), plus the non-OK rows of its "Slice executability assessment" and "Spec fidelity matrix". Every one of those is dispositioned in §0C. The gate-r1 "Rejected false positives" and "Preserve" lists are carried forward untouched.

**Authority, read in this order.**

1. **Accepted spec:** `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md`.
   Gate: `docs/superpowers/reviews/2026-09-09-t2-t3-spec-codex-gate-r11.md` — **VERDICT: ACCEPT**, BLOCKER 0, MAJOR 0, one editorial MINOR (m1). That minor is discharged by §0 of this plan.
2. **Owner rulings:** `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:96-110`. Every T-2/T-3 item is RULED; none is open (§3).
3. **T-1 hand-off:** `docs/superpowers/reviews/2026-09-09-t1-transfers-gate-r2-stock-gl.md` section "Seams handed to T-2" (seven facts, §6.6 here) and the T-1 tickets (§15).
4. **Format exemplar:** `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md` (the current revision of the A-1a slice plan; its round change-log shape is reproduced in §0A/§0B/§0C here, newest round first, and its §11 first table — `docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2352-2367` — is the exact row set §12 here reproduces once per slice).
5. **Staging deployment manifest:** `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md` (five-push sequence §2, web block §3, gate checklist §4, and the per-slice variable list at `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:291-308`), with manifest U-1 resolved as separate Dokploy applications by `docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md`.
6. **Conventions:** `docs/conventions/09-SECOND-OF-EVERYTHING.md`, `docs/conventions/10-BENCHMARK-FIRST-SPECS.md`, `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md`, `docs/architecture/precision-contract.md`, `docs/glossary.md`, and `CLAUDE.md` rules 2, 3, 4, 6, 7, 8, 9, 12, 13, 17, 18, 19, 20, 21.

---

## 0. Errata to spec rev 11 (discharges gate r11 MINOR m1)

Gate r11's single minor is that several **prose cross-references inside the spec are stale**, while the **tables are correct**. An implementer who follows the prose will look for surfaces in the wrong rows and will mis-name test steps. The rule for this lane is therefore: **read the tables, never the prose cross-reference.** The five corrections, reproduced from `docs/superpowers/reviews/2026-09-09-t2-t3-spec-codex-gate-r11.md:32-38`:

| # | Stale statement in spec rev 11 | Correction (authoritative) |
|---|---|---|
| E1 | "the count of R5 rows below matches the count of R5 surfaces in §5.0 (items 1–10, table rows 19–25)" (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:295`) | The counts do **not** match one-to-one. There are **ten** numbered R5 families in §5.0, **seven** dedicated surface rows (19–25), and **twelve** `R5`-prefixed response-shape rows at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:313-324`, because the batch family is decomposed into three wire shapes and the POS device types are their own row. Implement against the twelve response-shape rows; ignore the sentence claiming a matching count. |
| E2 | "The POS device types that mirror those shapes" / "POS device stock types mirroring rows 19–23 and 25" (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:260,290`) | The cited POS types mirror **surface rows 8–9** (POS stock-levels and POS stock-distribution), not product show / counting / rebalance / batch. The real members are `ServerStockRow.quantity/reserved/available` (`apps/pos/src/lib/db/repositories/locationStockRepository.ts:45`) and `StockDistributionRow.on_hand` with `totals.on_hand` (`apps/pos/src/types/stockDistribution.ts:12,28`). Only `incoming_transfer` becomes nullable on the device (S4, §10). |
| E3 | Four references still say "rows 19–24" — cache hygiene, the `R` definition, and the applied-R5 ruling (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:507,604,698`) | The R5 surface range is **rows 19–25**. Row 25 (`GET /products/{product}` show, `apps/api/app/Modules/Product/routes.php:58`) was added by rev 11 and is inside every R5 statement: it is not masked, not re-gated, carries no `meta` flag, is not cache-dropped, and is asserted PRESENT by T9 step 6t-c. |
| E4 | The rev-11 change log claims the prior cross-references were corrected (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:18`) | Overstated. Treat the change-log claim as unverified prose; E1–E3 and E5 are the standing corrections. |
| E5 | T9's overview calls the stock matrix and both POS responses part of "rows 19–25" (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:577,597`) | Those three **mixed** surfaces are **rows 7–9** (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:273-275`). They carry a masked transfer member *and* an R5 stock-position member in one body, which is exactly why steps 6h/6i/6j prune with `withoutKeys(...)` before the value scan and steps 6p/6q/6r then assert presence on the same response. |

**Nothing else in spec rev 11 is amended by this plan.** Every Preserve item of gate r11 (`docs/superpowers/reviews/2026-09-09-t2-t3-spec-codex-gate-r11.md:74-83`) is carried unchanged: the document-only blind guarantee, R5 visibility and accepted delta inference, movement masking for every receiver while a transfer carries, the corrected Table-P arithmetic, both close dispositions, quantity-based remainder in exactly three readers plus the completed-only backfill, stored header/per-line events with receipt-id anchoring, manager/admin seeding of the two independently grantable permissions with close requiring both, one receipt writer with `/complete` delegation, and scale-4 decimal strings with no floats.

---

## 0A. Round-7 addendum — S1 movement-creation amendment (orchestrator ruling 2026-09-10)

Source: the S1 dispatch handback `docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md` (worktree `.worktrees/t2-receipt-spine`, "Blocking finding: mandated extraction violates an existing protected ratchet"). The implementer asked: "May I expand S1 to fix movement creation before extracting the helper?" — **approved, bounded as the §6.7b amendment states** (create transfer movements with their final type and linkage; delete the rewrite; shrink the DPA baseline; consumer census for the event label; T-1 regression gate; reviewed by stock-gl-interaction-reviewer at the S1 gate). No plan gate is re-run for rev 8: the change is confined to §6.7b/§7.2 and is the only way to satisfy a protected ratchet the gates did not model.

| Item | Disposition | Rev-8 anchor |
|---|---|---|
| Verbatim move of `markMovementAsTransfer` trips the append-only movement ratchet; the receipt writer would too | AMENDED — rewrite eliminated at creation time; helper deleted; baseline entry removed; `StockAdjustmentService::receive()/issue()` gain a backward-compatible final-type parameter; stored row byte-identical; event labels become real with a consumer census; scope + ledger + regression gate stated | §6.7b amendment, §7.2 amendment |

## 0B. Round-6 change log — DISPATCH-READY

The governing review is `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r6.md` — **VERDICT: DISPATCH-READY** (0 BLOCKER / 0 MAJOR / 1 MINOR). Rev 7 is a byte copy of rev 6 with only the edits below; the planning pin `f9f6f4480` is unchanged; no section was regenerated; no further plan gate is required (the changes are provenance prose and self-referential authority pointers).

### 0B.1 MINOR

| Finding | Disposition | Rev-7 anchor |
|---|---|---|
| m1 — four provenance statements mixed the rev-4, rev-5 and rev-6 pins | CLOSED — the §0B m2 closure row attributes `86ab1dc58..HEAD` to gate r5 and `f9f6f4480..HEAD` to rev 6's re-check; §1 states gate r4 (HEAD `c1878add6`, pin `e734ee872`), gate r5 (HEAD `6906b4f9e`, pin `86ab1dc58`) and rev 6's own `f9f6f4480..HEAD` check separately; the S4 inventory note says "rev-6 pin `f9f6f4480`"; the §18 checklist line says "Rev-6/rev-7 planning SHA" and uses `f9f6f4480..HEAD` | §0B.1 m2 row, §1, §10.2 note, §18 |
| authority pointers | The embedded promotion checklist, the S1 packet (inherited by S2–S4) and the S4-mobile packet name rev 7; revs 1–6 are superseded | §7.16, §17.1, §17.5 |

## 0C. Round-5 change log

The governing review is `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r5.md` (0 BLOCKER / 1 MAJOR / 3 MINOR — all self-referential or editorial). Rev 6 is a byte copy of rev 5 with only the edits below; no section was regenerated.

### 0C.1 MAJOR, MINOR

| Finding | Disposition | Rev-6 anchor |
|---|---|---|
| M1 — the executable dispatch packets and the embedded promotion checklist named superseded rev 4 as authority | CLOSED — the embedded checklist's `Plan:` line, S1's "Plan (authority)" sentence (now also stating that revs 1–5 are superseded; S2–S4 inherit it through §17.1) and S4-mobile's absolute authority path all name rev 6 | §7.16 embedded checklist, §17.1, §17.5 |
| m1 — a second "23" statement | CLOSED — "24 and not 26" | §6.7 classification paragraph |
| m2 — repin provenance labels and gate-history sentence stale | CLOSED — §1 attributes `364b1668b..HEAD` to gate r3, `e734ee872..HEAD` to gate r4, `86ab1dc58..HEAD` to gate r5, and `f9f6f4480..HEAD` to rev 6's own re-check (kept by rev 7); the two "planning HEAD" labels read rev 6; the header's gate-history paragraphs are one path per code span with correct revision labels | §1, §6.5 tail, §19, header |
| m3 — one S1 checklist paragraph said the existing checklist "does not appear in any push's file list" | CLOSED — the sentence now states the existing checklist is modified only by the one-line S1 successor pointer and appears exactly once in S1's inventory and the Push-1 ledger | §7.16 "Which file" |

### 0C.2 Round-4 closure table — rows gate r5 marked REGRESSED at dispatch

| Row | Disposition |
|---|---|
| r4-M1 closure survives actual Desktop dispatch | CLOSED by M1 above — every packet now points at rev 6, which carries the explicit child `require` |
| r4-m1 "23" versus 24 | CLOSED by m1 above |
| r3-M3 / r2-M5 residual / S4 red-first race evidence / §7-T10 race executability — "CLOSED in body, REGRESSED at dispatch" | CLOSED by M1 above (the body text is unchanged; only the authority pointer moved) |

Nothing under gate r5's Preserve list changed. §1 is re-pinned to the commit carrying gate r5's register; the production diff since the rev-5 pin is empty.

## 0D. Round-4 change log

The governing review is `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r4.md` (0 BLOCKER / 1 MAJOR / 1 MINOR). Rev 5 is a byte copy of rev 4 with only the edits below; no section was regenerated.

### 0D.1 MAJOR, MINOR

| Finding | Disposition | Rev-5 anchor |
|---|---|---|
| M1 — the S4 subprocess cannot autoload the test-local `RaceProbeNotification` | CLOSED — the child script gains `require 'tests/Feature/Notification/TransferNotificationRaceTest.php';` immediately after `vendor/autoload.php` (PSR-4 `Tests\` → `tests/`, `apps/api/composer.json:60-65`, cannot resolve a class declared below the test class); the exact child script is reproduced in the "Second worker" row; the stage-1 paragraph states the failure mode the require prevents; the red-first row names the require. The first-red order is preserved: `assertTrue($blocked, …)` passes, the parent commits, `assertSame(0, $contender->wait(), …)` fails with the child's `SQLSTATE[23505] … "notifications_pkey"` stderr | §10.10 "Second worker" row, stage-1 paragraph, `TransferNotificationRaceTest` red-first row |
| m1 — §6.7 intro says 23 Feature classes | CLOSED — "24 PHP Feature test classes"; every group value, the allowlist and `gated_ceiling 1277` unchanged | §6.7 intro |

### 0D.2 Round-3 closure table — rows gate r4 left NOT CLOSED

| Row | Disposition |
|---|---|
| r3-M3 unreachable notification-race first red | CLOSED by M1 above (the only remaining defect was the child's autoload) |
| r2-M5 qualified closure — residual S4 race defect | CLOSED by M1 above |
| S4 red-first race evidence | CLOSED by M1 above |
| §7/T10 race executability | CLOSED by M1 above |

Nothing under gate r4's Preserve list changed. §1 is re-pinned to the commit carrying gate r4's register; the production diff since the rev-4 pin is empty.

## 0E. Round-3 change log

Every finding of `docs/superpowers/reviews/2026-09-10-t2-t3-plan-codex-gate-r3.md`, in gate order: the BLOCKER, the three MAJORs, the MINOR, then every NOT CLOSED / REGRESSED row of the gate's "Round-2 closure table", then every non-OK row of its "Slice executability" table, then the one issue its "Source-ledger verification" raises, then every non-`PRESENT` row of both sub-tables of its "Spec-fidelity table". Every disposition names a rev-4 anchor that reproduces the content in full; nothing is closed by pointing at rev 3.

### 0E.1 BLOCKER, MAJOR, MINOR

| Finding | Disposition | Rev-4 anchor |
|---|---|---|
| **B1** — `/complete` has two incompatible final signatures and two incompatible controller call graphs (r2-M1 REGRESSED) | CLOSED by picking **one** final signature and **one** controller call graph and making every occurrence agree. The single final signature is `receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult`; the reproduced `StockTransferReceiptService` class body at §6.7b now declares exactly that, so the class an S1 implementer copies matches the forwarder that calls it. The single controller call graph is: `StockTransferController::complete()` → permission → `canAccessLocationOrFail($transfer->destination_location_id, $company->id, $user)` → `ExpectedQuantityVisibility::canSeeExpected(...)` else typed 422 → `$this->service->complete($transfer->id, $user->id)` → `StockTransferReceiptService::receiveAllRemaining($transferId, $userId, 'sys:complete:'.$transferId)`. The direct receipt-service call rev 3 put in the S2 blind-refusal paragraph is **deleted**; the controller never calls `receiveAllRemaining` itself, in S1 or in S2. The final `StockTransferService::complete()` forwarder, the final `StockTransferReceiptService::receiveAllRemaining()` declaration and the final `StockTransferController::complete()` action are each reproduced in full, in one place, and the "one published forwarder" ownership statement of §6.7b is restated as a rule with the grep that proves it | §6.7b ("Final constructors", the new "The ONE `/complete` call graph, reproduced end to end" block, "Call graph", "Transaction and lock ownership"); §7.5 rule 9; §7.6; §8.6 blind-refusal paragraph; §18 |
| **M1** — S4's "exact file" inventory contains five non-path ellipses | CLOSED. The five `.../fr/<ns>.json` aliases in §10.2 are replaced by their full repo-relative paths, one bullet per file: `apps/web/src/locales/fr/stock-transfers.json`, `apps/web/src/locales/fr/inventory.json`, `apps/web/src/locales/fr/notifications.json`, `apps/web/src/locales/fr/compliance.json`, `apps/web/src/locales/fr/replenishment.json`. All ten web locale paths and both POS locale paths were opened at the rev-4 pin and exist. The two `apps/pos` locale entries were already full paths and are re-listed one per bullet for the same machine-exactness. The ten-file count, the twelve-locale-file arithmetic of §11.4 and the 159/13/30/189/172 ledger totals are unchanged. Every file inventory and ledger row in the plan was re-scanned for `...`/`…`; the only remaining occurrences are inside quoted PHP/SQL bodies and prose, never in a path | §10.2 (Modify (production) locale bullets); §11.4; §0A.4 |
| **M2** — §12 is a combined lane table, not the manifest's exact per-slice variable shape | CLOSED. §12 is rewritten as **four** variables tables, one per slice, each in the exact row set and order of the accepted A-1a rev-9 §11 first table (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2352-2367`): `<slice>`, Migrations list, Flags, Commands, Censuses, Web changes, Device build, Queues, Push count, Env path, Application IDs, Container names, Durable state, Boot synchronization. Every slice that has no value for a row states **none** explicitly, with the reason. The manifest's `Collapsed pushes` variable (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:305`) is answered inside each slice's **Push count** row, which names which of the manifest's five pushes that slice collapses and why. Mobile repository, backup and rollback notes move **out** of the tables into three named blocks below them. The conclusion that activation is a per-company **setting**, not an environment flag, is kept verbatim and restated once per slice in its **Flags** row | §12 (four tables + "Mobile repository", "Backups", "Rollback" blocks) |
| **M3** — the S4 same-id notification race cannot produce its stated first failing assertion at the required capture point | CLOSED by making the race **self-contained at first-red time**. `TransferNotificationRaceTest` defines a test-local `RaceProbeNotification` — a deterministic-id `Illuminate\Notifications\Notification` declared inside the test file, whose `via()` returns Laravel's stock `Illuminate\Notifications\Channels\DatabaseChannel::class` at capture — so nothing in the production notification set has to exist for step 1 to run. The barrier is restated as the precedent's own mechanism rather than an advisory lock: the parent opens a transaction, sends the probe (the `notifications` row is written but **uncommitted**), tags and spawns the child, and the child's duplicate-key insert **blocks** on the uncommitted primary key, which PostgreSQL reports as `wait_event_type = 'Lock'`. The first run therefore asserts, in order: `self::assertTrue($blocked, …)`, then — after `DB::commit()` — `self::assertSame(0, $contender->wait(), $contender->getErrorOutput());`, which is the **first failing assertion**, failing with the child's `SQLSTATE[23505] duplicate key value violates unique constraint "notifications_pkey"` in `getErrorOutput()`, and only then `self::assertSame(1, DatabaseNotification::query()->whereKey($id)->count());`. After `IdempotentDatabaseChannel` lands, the probe's `via()` is repointed at it and a third method races the **production** `TransferInitiatedNotification` through the same harness as the integration assertion. The exact command drops the gitignored `docs/sessions/t1/pg-test.sh` helper in favour of a self-contained `phpunit-pgsql.xml` invocation with the per-session database exported. Every citation into the precedent is re-derived at the rev-4 pin | §10.10 (rewritten harness table, the three race rows, the capture-order block); §10.10b; §13 S4 block; §17.4; §18 |
| **m1** — `visibility_version` is incorrectly described as unsigned | CLOSED. Both prose occurrences are corrected to **integer**: §8.6's builder paragraph and the `TransferReconciliationData` docblock in §8.7b. The reproduced migration 1d already writes `$table->integer('visibility_version')->default(1)` and is **not** changed; that matches the accepted spec's `visibility_version integer NOT NULL DEFAULT 1` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:105`). The DTO text (`public int $visibility_version`, `public readonly int $visibility_version`) and the generated-type text (`visibility_version: number`) already agree and are restated beside each correction so the three layers read the same | §8.3 (unchanged, restated as the authority); §8.6; §8.7b; §18 |

### 0E.2 Round-2 closure table — every NOT CLOSED / REGRESSED row, and every qualified closure

| Gate-r3 row | Disposition | Rev-4 anchor |
|---|---|---|
| r2-M1 — incomplete receipt-writer call graph — **NOT CLOSED / REGRESSED** | CLOSED with B1. The regression was that rev 3 corrected the prose and the forwarder but left the reproduced class declaration at the old entity signature, and left an S2 paragraph calling the receipt service directly with a loaded model. Both are now the one signature and the one call graph, and the change log below lists every line rev 4 touched to make them agree | §6.7b, §7.5 rule 9, §7.6, §8.6 |
| r2-M5 — "CLOSED **for the specific round-2 rows**; a separate S4 race-capture defect remains" | CLOSED in full with M3. The four round-2 rows stay repaired and unmodified; the residual — the race row's unreachable first-red — is the M3 redesign. §10.10b's classification table gains the probe-based rows and keeps its two named controls | §10.10, §10.10b |
| r2-M6 — "CLOSED **arithmetically**; literal S4 locale paths remain non-exact" | CLOSED in full with M1. The arithmetic is preserved byte-for-byte (159 single-push files, 13 contention files, 30 contention touches, 189 push rows, 172 distinct ERP source files); only the five literal strings become machine-resolvable paths | §10.2, §11.4, §11.7 |
| r2-B1, r2-M2, r2-M3, r2-M4, r2-m1 — CLOSED | Carried unchanged. No rev-4 edit touches the three generated transfer DTOs of §7.12b, the reconciliation `visibility_version` writer of §8.7b, the T11 reader baseline of §7.4/§7.14, the entry/exit unrestricted baseline of §9.5/§9.9, or the §6.7.4 raise notes, except that §8.7b's docblock loses the word "unsigned" (m1) | §7.12b, §8.7b, §7.14, §9.9, §6.7.4 |

### 0E.3 Slice executability — every non-OK row of gate r3

| Row | Disposition | Rev-4 anchor |
|---|---|---|
| **S1 — "Not dispatchable because the final receipt-service signature conflicts with its forwarder."** | CLOSED with B1: the reproduced class, the forwarder, §7.5 rule 9 and the call-graph table all read `receiveAllRemaining(string $transferId, …)`, and §17.1's deliverable list names the reproduction an S1 implementer must copy | §6.7b, §7.5, §17.1 |
| **S2 — "Not dispatchable because its controller instruction restores the incompatible loaded-transfer call."** | CLOSED with B1: §8.6's blind-refusal paragraph now ends at `$this->service->complete($transfer->id, $user->id)`, and states explicitly that S2 adds no new caller of `receiveAllRemaining` | §8.6, §17.2 |
| **S4 — "Not dispatchable because five locale paths are non-exact and the same-id race's first-red capture is unreachable."** | CLOSED with M1 (five full locale paths, one bullet each) and M3 (the self-contained probe race, its barrier, its ordered first-red assertion and its exact command) | §10.2, §10.10, §10.10b, §13, §17.4 |
| Exact files — S4 **NOT CLOSED** (five `.../fr/...` aliases) | CLOSED with M1 | §10.2 |
| Exact symbols/call graph — S1, S2 **NOT CLOSED** (contradictory `/complete` delegate signatures and caller ownership) | CLOSED with B1 | §6.7b, §8.6 |
| Red-first evidence — S4 **NOT CLOSED** for the same-id notification race | CLOSED with M3 | §10.10 |
| S3, S4-mobile — "Dispatch structure is otherwise executable" / all elements present | Carried unchanged; rev 4 makes no S3 or S4-mobile edit other than the §17.5 authority path, which now names rev 4 | §9, §17.5 |

### 0E.4 Source-ledger verification — the one issue raised

| Gate-r3 statement | Disposition | Rev-4 anchor |
|---|---|---|
| "A read-only set parser verified the ledger **after resolving the five intended locale aliases**" — the ledger is only exact under human alias inference | CLOSED with M1. After the expansion, the §10.2 inventory contains no alias, so the same parser resolves the ledger with **no** normalisation step. Every number the gate verified is preserved and re-asserted unchanged: 159 single-push source files, 13 multi-push files, 30 multi-push touches, 189 push rows, 172 distinct ERP source files, 0 duplicate membership, 2 generated artifacts outside the source partition, 1 sibling mobile file outside the ERP ledger. The two drift gates the gate confirmed (`scripts/preflight.sh:110-155`, `.github/workflows/ci.yml:2683-2714`) are untouched | §10.2, §11.4, §11.5, §11.6, §11.7, §19 |

### 0E.5 Spec-fidelity table — every non-`PRESENT` row of both sub-tables

**Sub-table 1 — schema, events, API, masking, movements, and clients.**

| Row | Disposition | Rev-4 anchor |
|---|---|---|
| §3.1 fraud settings columns — "PRESENT, with minor **'unsigned'** prose error" | CLOSED with m1: both prose occurrences read "integer"; the reproduced migration is unchanged and is named as the authority | §8.3, §8.6, §8.7b |
| §3.4 state machine — "PRESENT **except `/complete` executable signature**" | CLOSED with B1 | §6.7b, §7.5 rule 9 |
| §5.0 rows 1–6 — "PRESENT **except row 4 `/complete` call graph**" | CLOSED with B1: row 4's call graph is the single end-to-end reproduction of §6.7b, and §8.6 no longer states a second one | §6.7b, §8.6 |
| §5.6 quantity-less complete — **ALTERED/AMBIGUOUS** by BLOCKER B1 | CLOSED with B1: one signature, one forwarder, one controller action, all reproduced in full; the delegation §5.6 requires is the only write path | §6.7b, §7.5, §7.6, §8.6 |
| §7 notifications — "PRESENT; **race test execution evidence defective**" | CLOSED with M3 | §10.10, §10.10b |

**Sub-table 2 — §10 tests.**

| Row | Disposition | Rev-4 anchor |
|---|---|---|
| T10 — "PRESENT contract; **race first-red capture NOT EXECUTABLE**" | CLOSED with M3: the probe fixture makes step 1 runnable against S4's starting tree; the ordered assertions name which one fails first and with what stderr; the production-notification integration assertion is retained as a separate method so the T10 contract still binds the shipped classes | §10.10, §10.10b, §19 |

**Sub-table 3 — rollout order and stated deviations.** Gate r3 records every row as `VALID` or `PRESENT`; §11's rollout content, the four deviations and the "deviation 5 is a test-ownership split" reading are carried unchanged. The one qualifier it attaches to §11 rollout steps 2/4/6 — "the deployment-variable handoff still fails the manifest's required table shape" — is closed by M2 in §12.

### 0E.6 Citation-audit findings

| Gate-r3 finding | Disposition | Rev-4 anchor |
|---|---|---|
| Overextension at plan `:5324-5350` — `StockTransferCompleteConcurrencyPostgresTest.php` supports the subprocess/committed-fixture harness but cannot support the claimed untouched-tree failure mode for `TransferNotificationRaceTest` | CLOSED with M3. The rewritten harness table claims from the precedent only what it demonstrates — subprocess construction, committed fixtures, the PG-only skip, the `application_name` tag, the `pg_stat_activity` wait-event poll, and the post-join exit-code assertion — and sources the *duplicate-key* failure mode from the test-local probe fixture, which the slice itself creates in its first commit. Four precedent line numbers rev 3 stated approximately were re-derived and corrected: the child-script bootstrap is `:98-100` (not `:104-106`), the parent's poll loop is `:112-123` (not `:112-125`), `assertTrue($blocked, …)` is `:124` (not `:126`), and `assertSame(0, $this->contender->wait(), …)` is `:126` (not `:128`). The same re-derivation corrects §6.7b's table row, which cited the parent call at `:94` and the child call at `:105-107`; the real lines are `:95` and `:104` | §10.10 harness table; §6.7b caller table row for `StockTransferCompleteConcurrencyPostgresTest.php`; §6.5 census row |
| Internal contradiction — two `receiveAllRemaining` signatures | CLOSED with B1 | §6.7b |
| Internal contradiction — "unsigned integer" at plan `:4226` | CLOSED with m1 | §8.6 |
| The five §0 errata were checked and are correct | Carried unchanged; §0 is not edited by rev 4 | §0 |

### 0E.7 Consistency sweeps rev 4 performed while closing the findings above

| Sweep | What it changed | Rev-4 anchor |
|---|---|---|
| Whole-plan grep of `receiveAllRemaining`, `complete(` and `StockTransferController::complete` | Two lines changed — the reproduced class declaration and the S2 blind-refusal call — and nine further occurrences confirmed already consistent with the chosen signature and call graph. The full grep output and the line list are in §0A.8 | §0A.8 |
| i18n locale coverage versus the exact inventory | §10.3's web-bell sentence said the three notification type strings get keys "in en/fr/ar" while §10.2 lists only `en` and `fr` files. `apps/web/src/locales/ar/stock-transfers.json` does not exist at the rev-4 pin (`apps/web/src/lib/i18n.ts:477` deliberately feeds the **English** stock-transfers bundle into the `ar` resource set), and `ar` notifications are a spread over English (`apps/web/src/lib/i18n.ts:482`), so an absent `ar` key falls back to English rather than rendering a raw key. The sentence now reads **en and fr**, matching the inventory and the ledger; no file is added and no count moves | §10.3 |
| PG-only invocation used by the M3 rows | `docs/sessions/t1/pg-test.sh` is a **gitignored session artifact** (CLAUDE.md rule 15) and is absent from a fresh worktree at the rev-4 pin; it survives only inside the stale `.worktrees/t1-transfers` checkout. The two race rows and the §13 S4 block now use a self-contained `phpunit-pgsql.xml` invocation with `DB_DATABASE`/`DB_CENTRAL_DATABASE` exported, which is the memory rule `feedback_pg_test_db_per_session` written out. A footnote records that the same substitution applies wherever else this plan writes that helper | §10.10, §13 |
| Remaining `...` / `…` path and enumeration aliases outside §10.2 | Gate r3 M1 orders a re-scan of **every** file inventory and ledger row. Five further alias sites were found and written out in full: §1's T-1 ticket list (seven `…-t1-*.md` abbreviations → eight full `docs/superpowers/tickets/2026-09-09-t1-*.md` paths); §11.1's migration row (three `.../2026_09_09_*.php` abbreviations → four full `apps/api/database/migrations/tenant/...` paths); §7.5's rule A3 (`CHECK ..._positive` → the real constraint name `stock_transfer_receipt_lines_positive`, which the reproduced migration writes); §7.14's T11 preservation row (three `…['<key>']` assertion abbreviations → all four `self::assertSame($baseline['sites'][...], $after[...]);` calls written out); §10.5's cache gate (three `... [<key>] ...` abbreviations → all four `queryClient.removeQueries({ queryKey: [...] })` calls written out). After the sweep the only `…`/`...` left outside fenced code are: the change-log and §18 rows that **name** the banned pattern (§0A.1, §0A.3, §10.2, §18), and `6u-a…6u-e` in §9.6 steps 7 and 8, which is range notation over five steps enumerated in full in the same section's step table. Inside fenced code the only matches are PHP first-class callable syntax `fromModel(...)`, a JavaScript spread, and the git range `dev...HEAD` — no elision | §1, §7.5, §7.14, §10.5, §11.1 |
| Self-referential plan paths | `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-3.md` appeared as the authority path in the promotion checklist template (§7.16), in the S1 dispatch packet (§17.1) and in the S4-mobile packet (§17.5). All three now name rev 4 | §7.16, §17.1, §17.5 |

### 0E.8 B1 — every line rev 4 changed to make the `/complete` call graph single-valued

The gate requires the change log to list the lines. These are line numbers **in rev 3**, with the rev-4 text stated:

| Rev-3 line | Rev-3 text | Rev-4 text |
|---|---|---|
| `:1152` | `public function receiveAllRemaining(StockTransfer $transfer, string $userId, string $idempotencyKey): TransferReceiptResult { /* §7.5 rule 9 */ }` | `public function receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult { /* §7.5 rule 9 */ }` |
| `:4441` | the sentence's final clause read `then \`StockTransferReceiptService::receiveAllRemaining($transfer, $user, 'sys:complete:'.$transfer->id)\`` | the same clause now reads `then the published forwarder \`$this->service->complete($transfer->id, $user->id)\`, which is the ONLY caller of \`receiveAllRemaining\` (§6.7b). S2 adds no new caller` |

Eight occurrences were confirmed already correct and are **not** changed: `:1203` (the forwarder body), `:1208` (the final-signature sentence), `:1214`, `:1227`, `:1232`, `:1235`, `:1258` (ownership and call-graph table rows) and `:2038` (§7.5 rule 9). One further occurrence, `:2088` (§7.6), already named the correct call graph — the controller keeps calling `$this->service->complete($existing->id, $user->id)` and keeps returning 200 — but said the action was "unchanged in the controller", which is false once S1 deletes `formatTransfer` and hoists the destination check and S2 inserts the blind gate; that bullet is restated to name S1's two deltas and S2's one, and to point at the full reproduction. §18's r2-M1 checklist line already required "`receiveAllRemaining` takes a transfer **id**" and is extended with the single-controller-path clause and the seventh S2 constructor argument. The new end-to-end reproduction block in §6.7b, the §8.6 "what changed here and why" paragraph, the new §18 `(r3 B1)` line and the two dispatch-packet clauses (§17.1's handback grep, §17.2's deliverable 4) are all additive.

**Nothing else changed.** Every item of gate r3's "Preserve" list is carried forward unaltered: all five verified §0 errata; every ruled owner decision — D1, D2, OQ-1 through OQ-4, pattern detection, freight, receiver/closer attribution, OD-1, close authority, OD-4 and R5; the seven T-1 seams (zero-only GL buffer boundary, no freight GL, terminal status before cost capitalization, transit outside stock/GL ledgers, movement identity/event distinctions, initiate-only replenishment settlement, reuse of the source-restock path); additive guarded tenant migrations, named constraints and indexes, PostgreSQL transactional recovery, SQLite clean-rerun scope and forward-only post-commit recovery; decimal strings at column scale, `QuantityScale`, bcmath, explicit currency for scale resolution, no float arithmetic, no `(float)` casts and no `parseFloat`/`Number(...)` on quantity or money; existing-event immutability and the three new stored receipt-event classes; **one receipt writer and one controller response owner, now that B1 is repaired**; receiver projection omission by construction, static quantity-free errors and `details: []`; the R5 zero-file production rule plus its executable presence controls; the T3/T9/T19 partial contracts with whole-row closing reruns; the per-slice PostgreSQL databases `u`, `v`, `x`, `y`, the named reviewer sets, the handbacks and the terminal `status: review`; the separate S4-mobile repository packet and its one-file ledger; the S1 → S2 → S3 → S4 merge order with blind activation only after the complete union; and the 159/13/30/189/172 ledger arithmetic with both generated-artifact drift gates. Every gate-r3 "Rejected false positive" stands unamended. Gate r3 records **no owner decision required**, and rev 4 opens none.

---

## 0F. Round-2 change log

Every finding of `docs/superpowers/reviews/2026-09-09-t2-t3-plan-codex-gate-r2.md`, in gate order: the BLOCKER, the six MAJORs, the MINOR, then every row of the gate's "Slice executability" table whose verdict is not `Present`/`Correct`, then every non-`PRESENT` row of all four sub-tables of its "Spec fidelity matrix". Every disposition names a rev-3 anchor that reproduces the content in full; nothing is closed by pointing at rev 2. Gate r3's "Round-2 closure table" confirms this register: it marks r2 B1, M2, M3, M4 and m1 CLOSED, r2 M5 and M6 closed but qualified, and r2 M1 **REGRESSED** — the qualifiers and the regression are finished by the rev-4 edits of §0A.

### 0F.1 BLOCKER, MAJOR, MINOR

| Finding | Disposition | Rev-3 anchor |
|---|---|---|
| **B1** — S4 depends on three generated transfer DTOs that no slice creates | CLOSED. `StockTransferData`, `StockTransferLineData` and `StockTransferLineBatchAllocationData` are **created by S1**, the slice that owns `TransferPayloadBuilder` and both read endpoints under spec §5.3. All three PHP files are reproduced in full (snake_case `public readonly` properties, `#[TypeScript]`, `fromModel()` mappings), assigned exact paths, added to S1's exact file list, to push 1, to S1's `php artisan typescript:transform` step and to the generated-type drift gate, and named as S4's consumed types. S2 adds `blind` and `visibility_version` to `StockTransferData` only, which puts that one file in the contention register | new §7.12b; §7.2, §7.15, §11.1, §11.6, §13, §17.1; §8.2, §8.6, §11.2; §10.4 |
| **M1** — the receipt-writer call graph is still incomplete | CLOSED. §6.7b now reproduces the final constructors of `StockTransferService`, `StockTransferReceiptService` and `StockTransferController`, adds `ProductCostLock` and `DocumentNumberingService` to the writer, makes `StockTransferService` constructor-inject `StockTransferReceiptService`, states the exact transaction / row-lock / advisory-lock ownership for `complete`, pins the `return_to_source` source-location check to `StockTransferController::canAccessLocationOrFail`, resolves the response-builder ownership contradiction in favour of the controller, and adds a per-method **call-graph table** naming every collaborator call with its exact signature, plus every caller of `StockTransferMovementSupport` | §6.7b (new "Final constructors", "Call graph", "Transaction and lock ownership", "Response ownership" blocks); §7.5 rules 1, 7 and 8; §7.6 controller bullet list |
| **M2** — `TransferReconciliationData` omits required `visibility_version` | CLOSED. The member is added, sourced from the requesting company's receiving-controls row through `TransferIncomingVisibility::visibilityVersion($companyId)`, and the whole DTO is reproduced again in full as the post-S2 file. The change is **assigned to S2**, with `TransferReconciliationData.php`, `TransferReconciliationService.php` and the reconciliation response mapping in S2's exact file list, its ledger and the contention register; `T19-S2` gains an exact API assertion and a generated-type assertion | new §8.7b; §7.5 reconciliation paragraph; §7.12 (S1 shape stated as fifteen members); §8.2, §8.11, §8.12, §11.2, §11.6; §10.4 |
| **M3** — T11 does not prove historical reader answers survive the backfill and the reader switch | CLOSED. `TransferLegacyCompletionBackfillTest` gains a **capture** method that writes a committed baseline of all four reader query sites on the pre-change tree, and a **preservation** method that replays the identical fixture after the migration, the backfill and the reader switch and asserts byte-equality of the captured JSON for completed, cancelled and in-transit transfers with and without lots. The first failing assertion, the fixture file, the two lanes and both exact commands are named | §7.4 (baseline capture), §7.13 (backfill row), §7.14 (two new rows + the first-failure note), §7.2, §11.1, §13, §17.1 |
| **M4** — the entry/exit scope fix lacks the required non-blind output-preservation proof | CLOSED. `EntryExitNoteLocationScopeTest` gains a third method with a committed pre-change baseline of the entry/exit response for an **unrestricted, non-blind** actor across two locations and a mixed-receiver note group, asserted byte-equal after the scope fix; its first assertion is the restricted-actor scoping assertion, which is red before the fix exists | §9.5 (preservation contract), §9.8 (T17 row), §9.9 (new row), §9.9b (classification), §9.1, §11.3, §13, §17.3 |
| **M5** — several rows classified as red-first name assertions that pass at HEAD | CLOSED. `TransferReceiptGlBoundaryTest` and `TransferCloseReplenishmentSettlementTest` open with a 404-today route assertion; `TransferMovementMaskingTest::test_second_company_masking_is_independent` opens with the missing `meta` key on company A; `TransferNotificationTest::test_no_notification_payload_carries_a_quantity_or_a_note` opens with a positive notification count that is `0` today; the web "none of the three read permissions" case is **reclassified as a CONTROL** with a named falsification mutation. Two new classification tables make RED-FIRST vs CONTROL explicit for S1 and S4, matching §9.9b's shape for S3 | §7.14 (T13/T14 rows), new §7.14b; §8.11 (S2 statement); §9.9, §9.9b; §10.10, new §10.10b; §13, §18 |
| **M6** — the source-push ledger is not an exact once-only file ledger | CLOSED. §11 is restated as an exact once-only ledger: the obsolete Push-2 seven-file row is deleted, every per-push list carries only files touched by **one** push, and the thirteen files touched by more than one push live **only** in the contention register §11.6 with per-push deltas. The `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` cross-reference is **assigned to S1** with an owning slice, file-list entry, ledger row, reviewer citation and handback line. Totals are recounted from the lists: 159 single-push rows + 30 register touches = 189 push rows over **172** distinct ERP files. The eleven-item S1 DTO list is called eleven | §11.1–§11.7; §7.2, §7.16, §7.15, §16 step 9, §17.1; §19 |
| **m1** — S1's feature-lane raise note misstates its PG-only classes | CLOSED. The Inventory raise note now names exactly the two wholly PG-only Inventory classes (`StockTransferReceiveConcurrencyPostgresTest`, `TransferReceiptPatternQueryPostgresTest`) and states that `TransferLegacyCompletionBackfillTest` is cross-lane with one PG-only method; `TransferReceiptSchemaRerunPostgresTest` moves to the `Migrations` raise note, which is reproduced | §6.7.4 (both note templates) |

### 0F.2 Slice executability — every non-OK row of gate r2

| Row | Disposition | Rev-3 anchor |
|---|---|---|
| Exact production/test files — S1 **FAIL** (missing full transfer DTO files; orphan checklist edit) | CLOSED with B1 (three DTO files added to §7.2) and M6 (`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` added to S1's modify list) | §7.2, §7.12b, §7.16 |
| Exact production/test files — S2 **FAIL** (reconciliation version files omitted; duplicate ledger row) | CLOSED with M2 (`TransferReconciliationData.php`, `TransferReconciliationService.php`, `StockTransferData.php` added to §8.2) and M6 (the duplicate Push-2 row deleted) | §8.2, §11.2 |
| Exact production/test files — S4 **FAIL** (imports DTOs no prior slice creates) | CLOSED with B1: every generated symbol S4 imports is produced by a PHP `#[TypeScript]` class created in S1 or S2 | §10.4, §7.12b |
| Exact symbols/call graph — S1 **FAIL** (receipt writer / `complete` DI incomplete) | CLOSED with M1 | §6.7b |
| Exact symbols/call graph — S2 "Mostly present" | CLOSED with M2: the reconciliation version writer, its reader and its response mapping are named symbols in S2 | §8.7b |
| Exact symbols/call graph — S4 "Blocked by missing generated entity DTOs" | CLOSED with B1 | §7.12b, §10.4 |
| Full DTO/enum/event text — S1 **FAIL** (transfer DTOs absent; reconciliation DTO incomplete) | CLOSED with B1 (three DTOs in full) and M2 (the fifteen-member S1 shape stated, the sixteen-member post-S2 file reproduced in §8.7b) | §7.12b, §7.12, §8.7b |
| Full DTO/enum/event text — S2 **FAIL** (no reconciliation-version delta) | CLOSED with M2 | §8.7b |
| Full DTO/enum/event text — S4 "Imports cannot be generated" | CLOSED with B1 | §7.12b |
| Red-first assertion and command — S1 **FAIL** (T13/T14) | CLOSED with M5 | §7.14, §7.14b |
| Red-first assertion and command — S3 **FAIL** (second-company masking misclassified) | CLOSED with M5 | §9.9, §9.9b |
| Red-first assertion and command — S4 **FAIL** (empty notification scan and no-permission control) | CLOSED with M5 | §10.10, §10.10b |
| PG-only classes named — S1 "Present; note wording inaccurate" | CLOSED with m1 | §6.7.4 |
| Convention-09 evidence — S1 "T11 historical-reader proof missing" | CLOSED with M3 | §7.13, §7.14 |
| Convention-09 evidence — S3 "T17 preservation gap" | CLOSED with M4 | §9.8, §9.9 |

### 0F.3 Spec fidelity matrix — every non-`PRESENT` row of all four sub-tables

**Sub-table 1 — schema, events, endpoints and cross-layer rules.**

| Row | Disposition | Rev-3 anchor |
|---|---|---|
| §3.1b decimal casts and strings — **INCOMPLETE** (reconciliation version and full transfer DTOs) | CLOSED with B1 and M2; every member of the three new DTOs is `public readonly string` / `int` / `bool` / enum and none is `float` or `mixed` | §7.12b, §8.7b |
| §5.0 surfaces 1–6 — "PRESENT except downstream DTO defects" | CLOSED with B1: the list, show, receive and close bodies are typed by generated DTOs S1 creates | §7.12b, §10.4 |
| §5.0 surfaces 7–11 — "PRESENT; T17 preservation proof incomplete" | CLOSED with M4 | §9.9 |
| §5.0 surface 12 reconciliation — **ALTERED** (missing `visibility_version`) | CLOSED with M2 | §8.7b |
| §5.0 surface 14 generated TS — **BLOCKED** (three source DTOs absent) | CLOSED with B1 | §7.12b |
| §5.0b exact response shapes — "PRESENT except missing reconciliation version / generated full entities" | CLOSED with B1 and M2 | §7.12b, §8.7b, §9.7 |
| §5.1 receive algorithm — "PRESENT subject to incomplete DI graph" | CLOSED with M1 | §6.7b, §7.5 |
| §5.2 close rules and `DISPOSITION_REQUIRED` — "PRESENT subject to incomplete authorization call graph" | CLOSED with M1: the `return_to_source` source-location check is a named controller method with its signature and its 403 body | §6.7b, §7.6 |
| §5.3 full/receiver builders — **INCOMPLETE** (generated full entity DTO source absent) | CLOSED with B1 | §7.12b |
| §5.4 reconciliation — **ALTERED** (missing version) | CLOSED with M2 | §8.7b |
| §5.6 complete delegation — "semantically present, **not executable** with stated constructors" | CLOSED with M1 | §6.7b |
| §5.10 movement masking / location scope — "non-blind scope regression missing" | CLOSED with M4 | §9.5, §9.9 |
| §6.1 movements and identities — "PRESENT subject to writer dependency fix" | CLOSED with M1 | §6.7b, §7.8 |
| §6.2 GL-buffer boundary — "named red assertion defective" | CLOSED with M5 (T13 opens on the 404 route, then on a positive journal count, and only then on `assertEmpty`) | §7.14 |
| §7 notification types, recipients, no quantity/note — "empty-payload scan needs liveness" | CLOSED with M5 | §10.10 |
| §8 web split, pages, permissions, cache invalidation — **BLOCKED** by missing generated full DTOs | CLOSED with B1 | §10.4 |

**Sub-table 2 — §10 tests.**

| Row | Disposition | Rev-3 anchor |
|---|---|---|
| T10 — "payload-absence method lacks liveness" | CLOSED with M5 | §10.10, §10.10b |
| T11 — **INCOMPLETE**, no historical reader-total comparison | CLOSED with M3 | §7.14 |
| T13 — "first-red assertion invalid" | CLOSED with M5 | §7.14, §7.14b |
| T14 — "first-red assertion invalid" | CLOSED with M5 | §7.14, §7.14b |
| T17 — **INCOMPLETE**, unrestricted output preservation absent | CLOSED with M4 | §9.9 |
| T19 — "reconciliation remains separately incomplete" | CLOSED with M2 | §8.7b, §8.11 |
| T20 — "one second-company case misclassified as red-first" | CLOSED with M5 | §9.9, §9.9b |

**Sub-table 3 — S-matrix.**

| Row | Disposition | Rev-3 anchor |
|---|---|---|
| complete delegate — "PRESENT subject to M1 call-graph correction" | CLOSED with M1 | §6.7b, §7.13 |
| backfill — "T11 reader preservation still missing" | CLOSED with M3 | §7.13, §7.14 |

**Sub-table 4 — §11 rollout.**

| Row | Disposition | Rev-3 anchor |
|---|---|---|
| Step 2 readers in the same merge as the backfill — "required preservation test missing" | CLOSED with M3 | §7.14 |
| Step 4 permissions and durable checklist — "the unowned old-checklist cross-reference must be removed or assigned" | CLOSED with M6: **assigned to S1**, with a file-list entry, a push-1 ledger row, a reviewer citation and a handback line | §7.16, §7.2, §11.1, §7.15, §17.1 |
| Step 6 glossary, generated types, permission map, POS types — **INCOMPLETE** (full generated transfer DTO sources absent) | CLOSED with B1 | §7.12b, §11.5 |

**Nothing else changed.** Every item of gate r2's "Preserve" list is carried forward unaltered: the five verified §0 errata; every T-2/T-3 owner ruling as closed (D1, D2, OQ-1–OQ-4, pattern detection, freight, attribution, OD-1, close authority, OD-4, R5); R5's zero-file rule for rows 19–25 and its positive-control oracle; the seven T-1 stock↔GL seams; additive tenant migrations, named constraints, the transactional PG backfill, clean reruns and forward-only recovery; decimal strings at column scale, `QuantityScale`, bcmath, explicit currency and the float/cast bans; existing-event immutability and the new receipt event classes; recipient resolution before queued work and operation without `CompanyContext`; T3/T9/T19 partial ownership with closing-slice whole-row re-runs; the per-slice PG databases `u`/`v`/`x`/`y`, three reviewers per ERP slice, the named handbacks and the `status: review` terminal state; the separate S4-mobile repository packet; and the S1→S2→S3→S4 order with activation only after the complete union. Gate r2's "Round-1 closure register" is likewise carried: every r1 item it marks CLOSED stays closed by the rev-2 edits recorded in §0C, and the four it marks NOT CLOSED (r1 M3, M4, M6 and the r1 executability/fidelity rows that depend on them) are the ones the rev-3 edits above finish. Gate r2 records **no owner decision required**, and rev 3 opens none.

---

## 0G. Round-1 change log

Every finding of `docs/superpowers/reviews/2026-09-09-t2-t3-plan-codex-gate-r1.md`, in gate order: the BLOCKER, the eight MAJORs, the MINOR, then every row of the gate's "Slice executability assessment" whose verdict is not `Present`/`Yes`, then every row of its "Spec fidelity matrix" whose result is not `PRESENT`. Rows whose closure is the same edit as an earlier row point at that row rather than repeating it, and still name the rev-2 anchor. Gate r2's "Round-1 closure register" confirms this register: it marks r1 B1, M1, M2, M5, M7, M8 and m1 CLOSED, and r1 M3, M4 and M6 NOT CLOSED — those three are finished by the rev-3 edits of §0B.

### 0G.1 BLOCKER, MAJOR, MINOR

| Finding | Disposition | Rev-2 anchor |
|---|---|---|
| **B1** — feature-lane manifest and live-gate work absent from every slice | CLOSED. `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml` are now files of **all four** pushes, with exact per-group and global arithmetic derived at HEAD (`Inventory 129`, `Replenishment 8`, `Migrations 14`, `Compliance 23`, `Notification 1`, `gated_ceiling 1253` — verified, §6.7), the truthful parked-lane note text per slice, the allowlist decision and its rule, and `php tools/feature-lane-manifest-check.php` added to every slice's verification block and to §13 | new §6.7; §7.2, §7.14, §7.15; §8.2, §8.11, §8.12; §9.1, §9.9, §9.10; §10.2, §10.10, §10.11; §11.1–§11.4, §11.6, §11.7; §13; §16 step 2b; §17.1–§17.4; §18 |
| **M1** — fraud-settings reset changes unrelated policy fields | CLOSED. The reset preserves `self::REFUND_EXPOSURE_KEYS`, reproduced verbatim from `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:59-63` and applied at `:200-203`; `CASH_CONTROL_KEYS` (`:30-38`) is a different constant with a different job, stated so. The T19 reset assertion now asserts the three refund-exposure columns are unchanged and that `blind_receiving` returns to `false` | §8.4 (constant table), §8.7 step 3, §8.10 (settings-reset row), §8.11 (`T19-S2` reset methods) |
| **M2** — receive responses expose close-only keys as `null` | CLOSED by **separate DTO classes**, not `Optional`: `StockTransferReceiptLineData` / `StockTransferReceiptLineLotData` (receipt + legacy_completion; the two close members **do not exist as properties**) and `TransferCloseLineData` / `TransferCloseLineLotData` (close only), under two header DTOs `StockTransferReceiptData` and `TransferCloseReceiptData`. No Spatie `Optional` is used anywhere in this lane, so no vendor behaviour needs citing | §7.12 (five DTOs reproduced in full + the selection rule), §7.5 rule 8 and the close response, §9.6 `K_receipt` + the new structural assertion, §10.4 (generated-type list and the two envelopes) |
| **M3** — the receipt writer cannot use its specified service seams | CLOSED by extracting a package-visible collaborator `App\Modules\Inventory\Application\Services\StockTransferMovementSupport` holding `lockTransfer()`, `markMovementAsTransfer()`, `computeAllocationWeights()`, `capitalizeTransferCost()`, `restockAtSource()` and `lineProductIds()`, with full constructor and method signatures, its owning module path, and every caller named. `StockTransferService` delegates and keeps `complete` delegating to `StockTransferReceiptService` (convention 11) | new §6.7b; §7.2 (file lists), §7.8, §7.11, §11.1, §11.6 |
| **M4** — required DTO/enum text not reproduced in full | CLOSED. Reproduced without ellipsis: the complete modified `TransferStatus` enum (all seven cases, all six methods), `TransferReconciliationData` (+ its three nested DTOs), `TransferReceiverViewData` (+ its four nested DTOs), the complete modified `CompanyFraudSettingsData`, and the M2 receipt/close line DTOs | §7.12 (`TransferStatus`, receipt/close DTOs, `TransferReconciliationData` block), §8.4b (`CompanyFraudSettingsData`), §8.6b (`TransferReceiverViewData`) |
| **M5** — test ownership incompatible with slice order | CLOSED by **named partial contracts**: `T19-S2`/`T19-S3`, `T9-S3`/`T9-S4`, `T3-S1`/`T3-S4`; each partial states the assertion its own slice can make red-first, and the **closing slice re-runs the whole row** (S3 for T19, S4 for T9 and T3). Ownership moves are recorded in §2.2 deviation 5 and in §19 | §2.2 deviation 5; §7.1, §7.14 (`T3-S1`); §8.1, §8.11 (`T19-S2`); §9.1, §9.6, §9.9 (`T9-S3`, `T19-S3`); §10.1, §10.10 (`T9-S4`, `T3-S4`); §13 (S3/S4 re-run blocks); §16 steps 14a/18a; §18; §19 |
| **M6** — several required tests absent or not genuinely red-first | CLOSED. Real first failing assertions supplied for T4b (route 404), the S4 plain-partial-notification row (positive case first), the migration rerun (column census before catalog equality) and the backfill; the S3 positive controls are **reclassified** as liveness controls with an executable falsification procedure and an expected-PASS capture; the T10 two-worker race is added, mirroring `StockTransferCompleteConcurrencyPostgresTest`'s `Symfony\Component\Process\Process` harness; T18 is enumerated as **eight** executable authority cases with status + error code | §7.14 (T4b, backfill, migration rerun), §8.11b (T18 matrix), §9.9 + new §9.9b (control classification and falsification), §10.10 (T10 race + notification ordering) |
| **M7** — spec §11 rollout step 4 not implemented | CLOSED. S1 creates `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` (the repo's only existing checklist, `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md`, is a dated ceremony record and is not amended — reason stated), with all four spec §11.4 items reproduced | new §7.16; §7.2, §11.1, §12, §16 step 9, §17.1 deliverables/handback, §18 |
| **M8** — S4 cannot commit its required mobile change | CLOSED. The erp-mobile edit becomes a separate dispatch packet **S4-mobile** against `/Users/houssamr/Projects/syneriva/erp-mobile` pinned at `51e3445`, with its own worktree, branch, exact file edit text, verification command and handback; the mobile path is removed from every S4 ERP list, and §15 records that S4-mobile is dispatched only after S4 merges | new §17.5; §10.1, §10.9, §11.4, §12, §15, §16 step 18b, §19 |
| **m1** — dirty-tree census says three untracked files but lists two | CLOSED. There are **two** unrelated untracked files at HEAD (`git status --porcelain` quoted in §1); every occurrence of "three untracked files" now reads "two" | §1, §16 step 2, §18 |

### 0G.2 Slice executability assessment — every non-`Present` row

| Finding | Disposition | Rev-2 anchor |
|---|---|---|
| Exact production/test files — S1 "Mostly present" | CLOSED with B1 (manifest + ci.yml), M7 (promotion checklist) and M3 (`StockTransferMovementSupport`): S1's file list is now complete | §7.2 |
| Exact production/test files — S2 "Mostly present" | CLOSED with B1: manifest + ci.yml added to S2's list | §8.2 |
| Exact production/test files — S4 "Incomplete: mobile sibling file omitted" | CLOSED with M8: the mobile file leaves S4 entirely and becomes S4-mobile's only file | §10.2, §17.5 |
| Exact symbols — S1 "Incomplete private-helper/extraction seam" | CLOSED with M3 | §6.7b, §6.5 census rows |
| Full DTO/enum/event text — S1 "Incomplete" | CLOSED with M4 and M2 | §7.12 |
| Full DTO/enum/event text — S2 "Incomplete" | CLOSED with M4 | §8.4b, §8.6b |
| Red-first assertion and exact command — S1 "Incomplete" | CLOSED with M6 (T4b, backfill, migration rerun) and M5 (`T3-S1`) | §7.14 |
| Red-first assertion and exact command — S2 "Incomplete T18/T19" | CLOSED with M6 (eight-case T18) and M5 (`T19-S2`) | §8.11, §8.11b |
| Red-first assertion and exact command — S3 "Incomplete T9" | CLOSED with M5 (`T9-S3`) and M6 (control reclassification + falsification) | §9.9, §9.9b |
| Red-first assertion and exact command — S4 "Incomplete T10" | CLOSED with M6 (two-worker race) and M5 (`T9-S4`, `T3-S4`) | §10.10 |
| Manifest group/ceiling update — S1 "Missing" | CLOSED with B1: Inventory 129→141, Replenishment 8→9, Migrations 14→15, `gated_ceiling` 1253→1267 | §6.7, §7.2, §7.14 |
| Manifest group/ceiling update — S2 "Missing" | CLOSED with B1: Inventory 141→143, Compliance 23→26, `gated_ceiling` 1267→1272 | §6.7, §8.2, §8.11 |
| Manifest group/ceiling update — S3 "Missing" | CLOSED with B1: Inventory 143→146, `gated_ceiling` 1272→1275 | §6.7, §9.1, §9.9 |
| Manifest group/ceiling update — S4 "Missing" | CLOSED with B1: Notification 1→2, `gated_ceiling` 1275→1276 | §6.7, §10.2, §10.10 |
| Named PG-only classes — S4 "Incomplete race specification" | CLOSED with M6: `TransferNotificationRaceTest` is a new PG-only class in the Notification group, with its harness, first failing assertion and exact command | §10.2, §10.10, §6.7 |

### 0G.3 Spec fidelity matrix — every non-`PRESENT` row

| Finding | Disposition | Rev-2 anchor |
|---|---|---|
| §3.1b decimal casts / quantity strings — "ALTERED/INCOMPLETE: receive-only key omission and full DTO text" | CLOSED with M2 (structural absence) and M4 (full DTO text) | §7.12 |
| §3.3 enums / reason applicability — "full modified enum text incomplete" | CLOSED with M4: the complete `TransferStatus` file is reproduced | §7.12 |
| §3.6 I8 settings singleton/version — "PRESENT, except reset mutation" | CLOSED with M1 | §8.7 step 3 |
| §4.5 `ReceivingControlsChangedV1` — "ALTERED by reset constant; carriage test ordered too early" | CLOSED with M1 (constant) and M5 (`T19-S2` / `T19-S3` split) | §8.7, §8.11, §9.6 |
| §5.0 surfaces 1–6 — "ALTERED: receive close-only keys emitted as null" | CLOSED with M2 | §7.5 rule 8, §7.12 |
| §5.0 surface 13 notifications — "PRESENT; T9 ownership incorrect" | CLOSED with M5 (`T9-S4` owns step 6o and re-runs the row) | §9.6, §10.10 |
| §5.0 surface 14 generated TS types — "PRESENT, subject to missing full source DTOs" | CLOSED with M4 and M2 | §7.12, §8.4b, §8.6b, §10.4 |
| §5.0 surface 15 mobile — "NOT EXECUTABLE across repositories" | CLOSED with M8 | §17.5 |
| §5.0b exact response shapes — "ALTERED by nullable close-only receipt keys" | CLOSED with M2 | §7.5, §7.12, §9.7 |
| §5.3 visibility/builders — "PRESENT; DTO text incomplete" | CLOSED with M4 (`TransferReceiverViewData` reproduced in full) | §8.6b |
| §5.4 reconciliation endpoint — "PRESENT; DTO body missing" | CLOSED with M4 (`TransferReconciliationData` reproduced in full) | §7.12 |
| §5.5 company setting — "ALTERED on reset" | CLOSED with M1 | §8.7 step 3 |
| §5.8 permissions / any-of gate — "PRESENT; T18 proof incomplete" | CLOSED with M6 (eight executable authority cases) | §8.11b |
| §6.1 movement identities/reasons — "SEMANTICALLY PRESENT; private helper seam unresolved" | CLOSED with M3 | §6.7b, §7.8 |
| §6.3 return-to-source / no GL — "PRESENT; red-first assertion defective" | CLOSED with M6 (T4b's first failing assertion is the missing route, 404) | §7.14 |
| §7 rollback/idempotency/context/timestamps/order — "INCOMPLETE: two-worker race absent" | CLOSED with M6 (`TransferNotificationRaceTest`) | §10.10 |
| §9 mobile contract — "REQUIRED CONTENT PRESENT, DISPATCH MECHANICS MISSING" | CLOSED with M8 | §17.5 |
| §11 step 4 permissions/promotion checklist — "MISSING checklist edit" | CLOSED with M7 | §7.16 |
| §11 step 6 glossary/generated types/maps — "PRESENT, subject to DTO and mobile defects" | CLOSED with M4, M2 and M8 | §7.12, §10.4, §17.5 |
| T3 — "ALTERED ownership: notification dependency is S4" | CLOSED with M5 (`T3-S1` / `T3-S4`; S4 re-runs the whole row) | §7.14, §10.10, §19 |
| T4b — "PRESENT, but first-red proof invalid" | CLOSED with M6 | §7.14 |
| T9 — "ALTERED ownership: notification surface arrives in S4" | CLOSED with M5 (`T9-S3` / `T9-S4`; S4 adds step 6o and re-runs the class) | §9.6, §10.10, §19 |
| T10 — "INCOMPLETE: required two-worker race absent" | CLOSED with M6 | §10.10 |
| T18 — "INCOMPLETE exact authority-matrix methods" | CLOSED with M6 | §8.11b |
| T19 — "ALTERED ownership: five carriage surfaces arrive in S3" | CLOSED with M5 (`T19-S2` / `T19-S3`; S3 re-runs the whole row) | §8.11, §9.6, §9.9, §19 |

**Nothing else changed.** Every gate-r1 "Rejected false positive" stands unamended, and every gate-r1 "Preserve" item is carried forward unaltered: the five §0 errata, the T-1 seam inventory and its seven facts, the additive self-guarding migrations and the forward-only recovery rule, the decimal-string contract, event immutability plus the three new stored receipt-event classes, recipient resolution before queuing, R5's zero-file rule and the positive-control oracle, the complete owner-ruling register, the per-slice PG database letters, the named reviewers, the handback paths, the `status: review` terminal state, the convention-09 matrices, and the S1→S2→S3→S4 order with activation only after S4.

---

## 1. Plan identity and dispatch pin

- Repository: `/Users/houssamr/Projects/syneriva/apps/erp`.
- Branch state at planning time: local `dev`, ahead of `origin/dev` by documentation-only commits.
- **Planning HEAD for rev 6 — kept unchanged by rev 7 — read in full and used for every `path:line` in this plan: `f9f6f4480f772d7644b28bc4379cd21a4aef64e0`** — re-derived as the FIRST step of this revision with `git rev-parse HEAD`. Rev 3 pinned `364b1668be62a252240e9459e720bf20299a2a61`, rev 2 pinned `ab4b321f0417e536d676d8c94b97e09491f18b3c` and rev 1 pinned `73f2040c6d997b26a2a55dfb7d3c8742d361792e`; the ancestry from the **rev-3 citation base `364b1668b`** to this HEAD is **documentation-only**, verified and quoted:

```text
$ git rev-parse HEAD
f9f6f4480f772d7644b28bc4379cd21a4aef64e0

$ git diff --name-only f9f6f4480..HEAD | grep -v '^docs/'
(no output — exit status 1 from grep, meaning zero non-docs paths)
```

  Every `path:line` in this plan is therefore still valid at `f9f6f4480`, and the rev-3 statement that the citations survive the T-1 merge stands. Gate r3 audited rev 3 at `5bd152035a941e0eecd0142ac40db1c768bb3efa` and ran the `364b1668b..HEAD` non-docs check (empty); gate r4 audited rev 4 at HEAD `c1878add6` against the rev-4 planning pin `e734ee872` and ran `e734ee872..HEAD` (empty); gate r5 audited rev 5 at HEAD `6906b4f9e` against the rev-5 planning pin `86ab1dc58` and ran `86ab1dc58..HEAD` (empty); rev 6 pinned `f9f6f4480` and re-ran `f9f6f4480..HEAD` above and found it empty; rev 7 keeps rev 6's planning pin unchanged (gate r6 audited rev 6 at that pin and returned DISPATCH-READY); the intervening commits between that audit HEAD and this one are the gate register itself and the T-2/T-3 spec/plan documents. The rev-3 ancestry note is preserved for completeness: `git diff --name-only ab4b321f0..HEAD | grep -v '^docs/'` was empty at the rev-3 pin, and the rev-2 note before it: `git diff --name-only 73f2040c6..HEAD | grep -v '^docs/'` was likewise empty at the rev-2 pin. For completeness, rev 1's own ancestry note is preserved: planning opened at `7dcc77afcf0365d4a3ab9ca605d3dd2e1e647395`; while the plan was being written, the **T-1 lane merged into local `dev`** (`73f2040c6`, "Merge lane/t1-transfers-edge into dev"). `git diff --name-only 7dcc77afc..73f2040c6 | grep -v '^docs/'` lists six production paths: `.github/workflows/ci.yml`, `apps/api/app/Modules/Replenishment/Presentation/Requests/CreateTransferFromRequestsRequest.php`, `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php`, `apps/api/tests/Feature/Inventory/StockTransferEdgeCasesTest.php`, `apps/api/tests/Feature/Replenishment/ReplenishmentEdgeCasesTest.php` and `apps/api/tests/feature-lane-manifest.json`. **Every citation in this plan was re-verified against `f9f6f4480`**: the two CI drift gates are at `.github/workflows/ci.yml:2683-2700` and `:2705-2714`; the `backend-test-pgsql` allowlist this lane extends is the single `--filter` at `.github/workflows/ci.yml:1141-1142`, whose T-1 comment block opens at `:1132`; the Inventory feature lane is `"feature-lane-inventory/Inventory"` in `apps/api/tests/feature-lane-manifest.json`. The T-1 tickets of §15 are on `dev` at `docs/superpowers/tickets/2026-09-09-t1-*.md`.
- **Four of the six T-1 files are now load-bearing for this plan, not merely ancestry:** `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml` are edit targets of all four pushes (§6.7), and `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php` is the cited harness precedent for the T10 two-worker race (§10.10).
- Plan preparation changed no production file, ran no test, and ran no git write command. The only file created is this plan.
- Every absolute `path:line` below was opened at that HEAD and the **line content** was read, not merely the path.
- **Dirty-tree census at `f9f6f4480` — exactly TWO unrelated untracked files** (gate r1 m1: rev 1's prose said "three" in two places while listing two; the true count is two; gate r2 confirmed the rev-2 statement accurate, gate r3 confirmed it again at its own audit HEAD, and the census is unchanged at the rev-6 pin). Quoted:

```text
$ git status --porcelain
?? apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md
?? docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md
```

  Preserve both; no slice may delete or commit either:
  - `apps/api/docs/sessions/2026-08-04-cross-tenant-scheduled-jobs-plan.md`
  - `docs/handoff/HANDBACK-enforcement-p1-2026-08-19.md`

  (This plan file itself is a third untracked path while it is unstaged; it is the lane's own artifact, not an unrelated file, and the orchestrator commits it with the lane's documentation.)
- The T-1 tickets referenced in §15 are on `dev` at the pinned HEAD: `docs/superpowers/tickets/2026-09-09-t1-cancel-in-progress.md`, `docs/superpowers/tickets/2026-09-09-t1-freight-capitalization-no-gl.md`, `docs/superpowers/tickets/2026-09-09-t1-idempotency-payload.md`, `docs/superpowers/tickets/2026-09-09-t1-location-denial-message-constant.md`, `docs/superpowers/tickets/2026-09-09-t1-product-detail-transit.md`, `docs/superpowers/tickets/2026-09-09-t1-recalled-in-transit.md`, `docs/superpowers/tickets/2026-09-09-t1-replenishment-dialog-refusal-i18n.md`, `docs/superpowers/tickets/2026-09-09-t1-settlement-replay.md` — eight files, each written out in full (gate r3 M1 sweep: rev 3 abbreviated seven of them with a leading ellipsis).
- **T-1 has already merged**, so the "Seams handed to T-2" facts of §6.6 describe code that is on `dev` now, and the T-1 current-behaviour pins named in those tickets are live tests that this lane must keep green.

### 1.1 DISPATCH_SHA repin block (run before writing any code)

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
DISPATCH_SHA="$(git rev-parse HEAD)"
test -n "$DISPATCH_SHA"
git status --short
git show -s --format='%H %cI %s' "$DISPATCH_SHA"
git diff --name-only f9f6f4480f772d7644b28bc4379cd21a4aef64e0.."$DISPATCH_SHA" | grep -v '^docs/' || echo 'NO PRODUCTION DRIFT'
```

Record `DISPATCH_SHA` in the handback. Rules:

1. If `DISPATCH_SHA` equals the rev-6/rev-7 planning HEAD `f9f6f4480f772d7644b28bc4379cd21a4aef64e0`, the citations are pinned; start.
2. If it differs but the last command prints `NO PRODUCTION DRIFT`, the pin is current — documentation-only ancestry does not block dispatch. This is the same structural test spec rev 11 states at `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:5`.
3. If any non-`docs/` path is listed, re-run the §6.5 named-symbol census against `DISPATCH_SHA`, re-open every cited seam the diff touches, and record corrected `path:line` values in a dispatch addendum pinned to `DISPATCH_SHA` before implementing.
4. **Two listed paths are expected and are NOT drift for S2, S3 or S4:** `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml`, because every earlier slice of this lane edits both (§6.7). When the diff lists **only** those two, treat the run as `NO PRODUCTION DRIFT` for citation purposes, and re-read §6.7's running arithmetic from the manifest at `DISPATCH_SHA` rather than from this document, so the ceiling you raise is the one actually on `dev`. Any third path is real drift and triggers rule 3.
5. There is no unconditional equality gate against the planning SHA.

### 1.2 Per-slice worktree and PostgreSQL leg

Standing rules: work in a `git worktree` off local `dev` (CLAUDE.md rule 21); never run the full PHPUnit or Vitest suite (memory `feedback_no_full_test_suite`); use a per-session PostgreSQL database (memory `feedback_pg_test_db_per_session`).

| Slice | Worktree | Branch | PG database (`DB_DATABASE` **and** `DB_CENTRAL_DATABASE`) |
|---|---|---|---|
| S1 | `.worktrees/t2-receipt-spine` | `lane/t2-receipt-spine` | `autoerp_test_u` |
| S2 | `.worktrees/t3-blind-core` | `lane/t3-blind-core` | `autoerp_test_v` |
| S3 | `.worktrees/t3-surface-closure` | `lane/t3-surface-closure` | `autoerp_test_x` |
| S4 | `.worktrees/t2t3-web-notifications` | `lane/t2t3-web-notifications` | `autoerp_test_y` |
| **S4-mobile** | `.worktrees/t2t3-mobile-contract` **inside `/Users/houssamr/Projects/syneriva/erp-mobile`** | `lane/t2t3-mobile-contract` | none — no database, no PHP, TypeScript only (§17.5) |

The four ERP letters are `u`, `v`, `x`, `y` and are pairwise distinct, which is the requirement — gate r2's executability table transcribes them from this table verbatim. Gate r1's "Private PG database letter" row transcribed them as `v`, `w`, `x`, `y`; that row is descriptive, not a finding, and **this table is authoritative**. `autoerp_test_w` is deliberately unused by this lane.

---

## 2. Outcome, hard scope, slicing, deferrals

### 2.1 What the lane guarantees when all four slices are merged and activated

1. A destination receiver can post **what physically arrived**, per line and per lot, through one write path: `POST /stock-transfers/{id}/receive`.
2. A partial receipt leaves the remainder **in transit** (`partially_received`); the receiver may receive again.
3. **Over-receipt is refused** with a typed 422 and zero movements; **damage** is declared at receipt with a reason and becomes a Shrinkage GL posting; **shortness** is never asked of a receiver and is confirmed only at close.
4. A supervisor holding **both** `inventory.transfers.reconcile` and `inventory.transfers.close` can close an open remainder with one disposition: `write_off` (stock out at destination + Shrinkage GL) or `return_to_source` (stock back at source, **no GL**).
5. "Incoming" for every stock reader becomes the **quantity remainder**, not the transfer status.
6. Every receipt and every close is an **immutable stored document plus stored per-line events** anchored on the receipt id, sufficient to replay the document and to run the owner's pattern-detection query.
7. **Freight** is capitalised only on the good units that landed; the residual is persisted on `stock_transfers.freight_uncapitalized` with no journal.
8. With the company setting `blind_receiving` on, a destination actor who cannot see expected quantities receives, from every **transfer, receipt, replenishment, movement/entry-exit and notification** surface, no sent/expected/remaining quantity, cannot post a quantity-less `complete`, and cannot close.
9. Destination **on-hand, available and lot-stock positions stay visible** (owner ruling, residual R5), and the T9 oracle asserts that as an executable positive control.
10. Two database notifications exist: transfer initiated (to destination receivers) and receipt-with-discrepancy / close (to reconcilers with destination access).

### 2.2 Slicing, and where it departs from the suggested shape

Four slices, each one worktree, one branch, one handback, one merge to local `dev`, gated by named reviewer agents.

| Slice | Title | Spec sections implemented | §10 rows owned |
|---|---|---|---|
| **S1** | T-2 receipt spine — schema, enums, models, quantity-based readers, legacy backfill, receipt/close service, stored events, movements + GL + freight, permissions and route gates | §1 (in-scope T-2), §2 (glossary), §3.1, §3.1b, §3.2, §3.3, §3.4, §3.5, §3.6 (I1–I7), §4.0–§4.4, §5.1, §5.2, §5.4, §5.6 (delegation only), §5.8, §6.1–§6.4, §11 steps 1a–1c, 1e, 2, 4 | T1, T2, **T3-S1**, T4, T4b, T5, T6, T7, T7b, T7c, T8, T11, T12, T13, T14, T15, T16 + S-matrix rows *receive*, *close write_off*, *close return_to_source*, *complete*, *backfill*, *migrations* |
| **S2** | T-3 blind core — `blind_receiving` + `visibility_version`, the two Shared contracts, `ExpectedQuantityVisibility`, the receiver projection and receiver-view route, blind refusal of quantity-less `complete`, fraud-settings transactional write with the atomic bump and `ReceivingControlsChangedV1` | §3.1 (`company_fraud_settings` half), §3.6 I8, §4.5, §5.0 rows 1–6, §5.0b receiver/receipt/error rows, §5.3, §5.5, §5.6 (blind gate), §5.8 read-gate third disjunct proof, §11 step 1d | T9b, T18, **T19-S2**, T19b + S-matrix rows *settings update*, *settings reset*, *settings initialisation* |
| **S3** | T-3 surface closure and the leak oracle — incoming aggregates (surfaces 7–9), replenishment feeds (17–18), movement/entry-exit feeds (10–11) with the location-scope fix, and the executable oracle with R5 presence controls | §5.0 rows 7–11, 17–25, §5.0b masked and R5 rows, §5.7, §5.9, §5.10, §10.1 | **T9-S3**, T17, T20, **T19-S3 (closes T19)** |
| **S4** | Web, POS device client, notifications (T-4-min) | §7, §8, §8b, §11 steps 5–6 | T10, **T9-S4 (closes T9)**, **T3-S4 (closes T3)** |
| **S4-mobile** | §9 mobile contract commitments — one file in the sibling repository `/Users/houssamr/Projects/syneriva/erp-mobile` (§17.5) | §9 | none — the mobile contract has no §10 row (spec §9 is a commitment, not a test row) |

**Deviation 1 (stated, with the reason).** The orchestrator brief suggested that the quantity-based remainder readers and the completed-row backfill live in slice 2. They are pulled into **S1** instead, because:

- Spec §11 step 2 is explicit: "Readers switch to `REMAINDER_SQL` + `CARRYING_STATUSES` in the **SAME merge** as the backfill" (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:686`). The two cannot be separated from each other.
- The moment `POST /receive` can set `partially_received`, today's status-based readers stop counting that transfer at all: all three filter on `status = 'in_transit'` exactly (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:144`, `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:228`, `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:402`, `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:124`). Shipping the write path one merge before the readers would silently drop a partially-received transfer's remainder out of matrix incoming, POS incoming, POS distribution **and the WAC owned-quantity denominator** — a valuation defect on staging, not only a display one. The two must land together, so both land in S1.

**Deviation 2 (stated, with the reason).** The brief's S1 would have shipped the receive/close API before the visibility service exists. That is kept, but with an explicit contract: **S1 implements exactly the `blind_receiving = false` behaviour**, using `TransferPayloadBuilder` unconditionally. That is not a placeholder — with no setting row and no `canSeeExpected` disjunct, `canSeeExpected` is unconditionally true in the final design too (§5.3: "setting off OR reconcile OR source access"), so S1's unconditional full builder is byte-identical to the final behaviour with the setting off. S2 then introduces the setting, the second builder and the per-transfer choice. No S1 wire member is later removed; S2 only **adds** `blind` and `visibility_version`.

**Deviation 3 (stated, with the reason).** Spec §10 row **T18** (authority matrix) mixes S1 facts (`receive` 201, `close` 403, `reconciliation` 403 under the widened read gate) with S2 facts (receiver shape, receiver-view route, setting ON). It is assigned **whole to S2**, because a matrix that cannot exercise the setting is not the matrix the spec describes. S1 must still prove its own route-gate widening red-first; it does so with a **plan-added** class `TransferReceiptAuthorityGateTest` (§7.14), which is not a §10 row and is declared as such in the self-census (§19).

**Deviation 4 (stated).** Spec §5.0 row 11 fix (a) — applying `LocationScopeResolver` to `GET /entry-exit-notes` — and its test T17 are placed in **S3**, with the §5.10 masking, because both edit `EntryExitNoteController` and splitting them would put one file in two slices for no benefit.

**Deviation 5 (stated — closes gate r1 M5).** Three spec §10 rows depend on production work that a later slice owns. Rev 1 assigned each row **whole** to a slice that could not make all of its assertions, which is not executable. Rev 2 keeps the S1→S2→S3→S4 order and splits those three rows into **named partial contracts**, each with the assertion its own slice can genuinely make red-first, and makes the **closing slice re-run the whole row** after the last dependency lands. Ownership is not moved to a later slice in any of the three cases, because each earlier half is a real, independently falsifiable contract and moving it would leave the earlier slice with no red-first evidence for behaviour it actually ships.

| Spec row | Rev-1 owner | Rev-2 partial contracts | What each partial can assert red-first | Closing slice (re-runs the WHOLE row) |
|---|---|---|---|---|
| **T19** — every settings write advances `visibility_version` in the row, the DTO and the next payload | S2 whole (rev 1 lines 1921, 2327–2329) | **T19-S2** = row + DTO + the **two transfer payloads** (`data.visibility_version` on show, `meta.visibility_version` on list) + flip-only event + first-write-creates-row-at-2 + the M1 reset preservation. **T19-S3** = the **five carriage surfaces** deferred to S3 (stock matrix `meta`, POS stock-levels `meta`, POS stock-distribution `meta`, replenishment WEB `meta`, replenishment POS **top-level**), asserted by the version column of `TransferBlindLeakOracleTest` steps 6h, 6i, 6j, 6k, 6l and re-asserted at `v+1`/`v+2` in step 8 | T19-S2: `self::assertSame($rowVersion, $response->json('data.visibility_version'));` — fails today, the column does not exist. T19-S3: `self::assertSame($v, $matrix->json('meta.visibility_version'));` — fails after S2, the matrix `meta` has no such key | **S3**. §13's S3 block re-runs `ReceivingControlsVisibilityVersionTest` and `ReceivingControlsConcurrencyPostgresTest` (S2's files, unmodified) alongside the oracle, so the whole T19 row is green in one slice |
| **T9** — the executable leak oracle over every transfer-derived surface | S3 whole (rev 1 lines 2355, 2434–2557), including notification step **6o** | **T9-S3** = steps 1–5d, 6a–6n, 6p–6u, 7 and 8 — everything except step 6o. **T9-S4** = step **6o** (`GET /notifications` scanned for `K_notification` and `S`), added by S4 **as a new method on the same class** `TransferBlindLeakOracleTest`, which S4 therefore lists as a *modify* (§11.6) | T9-S3: `6b: sentinel 7391.4517 at data.lines.0.quantity` (the captured first violation). T9-S4: `self::assertSame([], $violations, '6o');` — cannot even reach a body today, because no transfer notification type exists until S4 | **S4**. §13's S4 block re-runs the entire `TransferBlindLeakOracleTest` class on both DB lanes after the notifications land |
| **T3** — damaged units land-then-scrap, one Shrinkage journal, and a **discrepancy alert** | S1 whole (rev 1 §7.14) | **T3-S1** = the movement/GL/valuation half: land-then-scrap, exactly one Shrinkage journal, declared reason never changes the movement reason, periodic valuation refused before any movement, `has_discrepancy = true` on the row and `linesWithDiscrepancy` on the dispatched `StockTransferReceiptPosted`. **T3-S4** = the **alert**: one `inventory.transfer.received_with_discrepancy` notification per discrepant receipt, none for a clean partial | T3-S1: `self::assertSame(1, JournalEntry::query()->count());` after the damaged receipt — reached only once `POST /receive` exists, and today the request 404s first (§7.14 states the ordered pair). T3-S4: `self::assertSame(1, DatabaseNotification::query()->where('type', 'inventory.transfer.received_with_discrepancy')->count());` — 0 today | **S4**. §13's S4 block re-runs `StockTransferReceiveDamageTest` (S1's file, unmodified) beside `TransferNotificationTest` |

No other §10 row is split. The three closing re-runs are commands in §13, checklist items in §18, and dispatch-order steps 14a and 18a in §16.

### 2.3 Hard scope — what no slice may do

- No inter-company transfer support: `StockTransferService` still refuses it (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:96-101`).
- No receipt `void` / reversal path. An over-reported receipt is corrected by a stock-adjustment document; receipt rows, counters, reconciliation and terminal state stay as posted.
- No over-receipt tolerance, no discrepancy alert threshold (v1 = any).
- No push/Expo notification, no `ReplenishmentRequestedNotification`.
- No mobile screen. §9 of the spec is a **contract commitment** only; M-1 owns the screen.
- No PO blind receiving (`T-3b`), no `GET /purchase-orders/{id}/receiver-view`.
- No receiver note on the receipt line (D2 ticket, T-2b).
- No analytics read model or dashboard; S1 ships the reference query and its shape test only.
- No change to `Batch::recall()`, to FEFO allocation, to reservations, or to the POS sale path.
- No new `SystemAccountPurpose`; damage and write-off reuse the existing shrinkage bridge (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:185`).
- No sidebar any-of `permissions` prop.
- No client-side purge guarantee for pre-flip data (residual R4).
- No GL leg for transfer freight — that is ticket `2026-09-09-t1-freight-capitalization-no-gl.md`, explicitly deferred (§15).

---

## 3. Owner rulings (all applied; none open)

Source: `docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md`. Spec §12 records the same set; this table is the implementer's copy.

| Ruling | Owner line | Applied where in this plan |
|---|---|---|
| **D1** — reconciliation and expected quantities gated on a new `inventory.transfers.reconcile`, not `inventory.view` | ACCEPTED; seed to `manager` (+ `admin` via all); grantable per role/user (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:98`) | S1 §7.6 (seeder + route matrix); S2 §8.5 (`canSeeExpected` disjunct) |
| **D2** — discrepancy reason required only when damaged > 0; shortness computed server-side and reasoned by the supervisor at close; receiver note is a later ticket | ACCEPTED (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:99`) | S1 §7.5 rule B9, `TransferDiscrepancyReason::allowedFor()`; receiver note deferred (§15) |
| **OQ-1** — remainder physically returned to source | INCLUDE `disposition: write_off \| return_to_source`; "probably no GL entry; research + benchmark first" (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:100`) | S1 §7.5 close; §7.8 movements — `return_to_source` is a stock movement pair with **no** journal |
| **OQ-2** — replenishment request after a write-off close | Leave settled; notify processors; requester re-raises (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:101`) | S1 T14 asserts the settled row is untouched by both dispositions; no listener on close |
| **OQ-3** — `partially_received` visible to a blind receiver | ACCEPT the coarse signal (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:102`) | S2 residual R3; the status is never masked |
| **Pattern detection** | Every receipt/close must be a STORED event carrying receiver identity and per-line sent/received/damaged/reason/blind (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:103`) | S1 §7.9 events + §7.10 reference query + T16 |
| **Freight OQ-4** | CONFIRMED default: capitalisable pool = `transfer_cost × Σ good ÷ Σ sent`, landed-weight allocation, residual persisted, **no journal** (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`) | S1 §7.11 |
| **Multi-receiver attribution** | CONFIRMED: per-line events attribute exactly what each receiver posted; a close shortage is a transfer-level fact carried by the closer; the query is per company and never counts supervisors' own actions (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:106`) | S1 §7.9 `actorRole`, §7.10 query, T16 |
| **OD-1** — blind refuses quantity-less `complete` | RULED, default ACCEPTED: 422 `BLIND_REQUIRES_COUNTED_RECEIPT`; web hides "Receive all" in blind mode (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:108`) | S2 §8.6; S4 §10.4 |
| **Close authority** | RULED: BOTH `inventory.transfers.reconcile` AND `inventory.transfers.close`, both seeded to manager + admin, independently grantable (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:108`) | S1 §7.6 route matrix (two chained `can:` middlewares) |
| **OD-4** — activation semantics | RULED: effective server-side at commit; `visibility_version` is an advisory cache hint; clients converge on their next successful response (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:108`) | S2 §8.7; S3 residual R4; S4 §10.5 |
| **R5** — current-stock residual | RULED option (b), "the Oracle approach": hide the expected/sent/remaining quantities of the SPECIFIC DOCUMENT being received only, on mobile and the web receipt surfaces; "everywhere else it's okay"; destination on-hand, available and lot stock stay visible; current-stock delta inference (incl. the multi-receiver `NOTHING_TO_RECEIVE` composition) is an ACCEPTED residual (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:110`) | S3 §9.2 (nothing masked on rows 19–25), §9.6 (T9 presence controls 6p–6u) |
| **Greenfield framing** | "we are still in a green field, nothing to worry about like previous clients" (`docs/handoff/OWNER-QUESTIONS-consolidated-2026-09-06.md:108`) | No legacy-device or legacy-tenant compatibility claim is made anywhere in this plan, for activation, backfill or either client cache |

No slice may reopen any of these. A slice that believes a ruling is wrong stops and escalates; it does not implement an alternative.

---

## 4. Industry baseline (benchmark-first — convention 10, `docs/conventions/10-BENCHMARK-FIRST-SPECS.md:37`)

Copied from spec §0 (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:29-49`). Flow: inter-site stock transfer — ship, receive at destination, handle short/over/damaged, close the remainder (write-off or return), blind receipt, notify, audit. Reference systems: Odoo 17/18, ERPNext v15, Dolibarr. Sources for the GL and blind-visibility rows: `docs/superpowers/reviews/2026-09-09-benchmark-transfer-discrepancy-gl.md`, `docs/superpowers/reviews/2026-09-09-benchmark-owner-decisions-po-revert-freight-attribution.md`, `docs/superpowers/reviews/2026-09-09-benchmark-blind-receiving-on-hand-visibility.md`.

| # | Guarantee | Odoo | ERPNext | Dolibarr | AutoERP today (path:line) | Gap | Decision |
|---|---|---|---|---|---|---|---|
| B1 | Two-step transfer: ship, then destination confirms received quantities | Transit location, receipt at destination | Add to Transit → Receive at Warehouse | PO-only reception | `store` = initiate = decrement source + `in_transit` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:86`); `complete` takes no quantities (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317`) | MISSING | MATCH — `POST /stock-transfers/{id}/receive`, receipt document (S1) |
| B2 | Partial receipt / remainder | Backorder prompt | Remainder stays in transit | Partial PO reception | PO receipts only (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:1231-1234`) | MISSING | MATCH Odoo backorder — remainder stays in transit (`partially_received`); receive again or close (S1) |
| B3 | Short / over / damaged | Backorder or scrap; over by editing Done | Rejected qty + warehouse; over allowance % | Correct Stock | PO refuses over-receipt (`apps/api/app/Modules/Inventory/Application/Services/GoodsReceiptService.php:326-345`); transfer line table has no discrepancy columns (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:86`) | MISSING | over REFUSED (typed 422); damaged at receipt with reason; short confirmed only at close with reason + alert (S1) |
| B4 | Blind receiving — what exactly is hidden | Not native | Not native | Not native | Counting is blind by construction (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334`, `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:185`); cash blind count (`apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:36`); PO mobile types expose expected (`/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts:58-73`) | MISSING (deliberate DIVERGE) | `company_fraud_settings.blind_receiving` (default false); transfers first, PO second (T-3b). **Scope MATCHES Oracle Retail Store Inventory Operations Cloud** (`docs/superpowers/reviews/2026-09-09-benchmark-blind-receiving-on-hand-visibility.md:9`): what is hidden is the expected/sent/remaining quantity of the DOCUMENT being received, on the mobile receipt screen and the web receipt surfaces; on-hand, available and lot stock stay visible and the delta is accepted residual R5 (S2, S3) |
| B5 | Notifications | Chatter | Doc-event notifications | Agenda | None for transfers; the notification module is read-only (`apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:20`); bell switches by `type` (`apps/web/src/features/notifications/components/NotificationPanel.tsx:45`) | MISSING | DB channel + web bell; push later (S4) |
| B6 | Who may receive | Operation-type rights | Warehouse permissions | Warehouse permission | `canAccessLocation(destination)` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:215`, `apps/api/app/Modules/Company/Services/LocationContext.php:224`); `canSeeTransfer` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:347`) | ALREADY | destination membership + `inventory.transfers.complete`; no assignment; the read routes accept that authority (S1 §7.6) |
| B7 | Cancel after goods moved | Done cannot be cancelled | Not after receipt | — | `canBeCancelled()` = Draft \| InTransit (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:37`); cancel from `in_transit` restocks source (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:429`) | ALREADY | `partially_received` is NOT cancellable; the remainder is handled by close (S1) |
| B8 | Immutable receipt audit trail | Stock moves + chatter | Stock Ledger Entries | Stock movements | Transfer events are plain `Dispatchable` (`apps/api/app/Modules/Inventory/Domain/Events/StockTransferInitiated.php:9`); PO `GoodsReceived` is stored (`apps/api/app/Modules/Inventory/Domain/Events/GoodsReceived.php:18`, `apps/api/app/Shared/Domain/Events/DomainEvent.php:16`) | PARTIAL | MATCH — stored header + per-line events with a real aggregate anchor (S1 §7.9) |
| B9 | Idempotent posting | Picking-state guarded | docstatus | — | Initiate: `(tenant_id, company_id, idempotency_key)` unique (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:67`) + unique-violation reread (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:111`) | PARTIAL | MATCH — required key per receipt/close, replay 200 `meta.replayed` (S1 §7.5 rules 1–3) |
| B10 | Re-run safety (migrations, backfill) | n/a | n/a | n/a | Migrations run inside one PG transaction (`apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448-451`) and are logged only after success (`apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:252-258`); guard precedents `Schema::hasColumn` (`apps/api/database/migrations/tenant/2026_08_10_100000_add_inventory_valuation_mode_to_companies.php:32`), `pg_constraint` lookup (`apps/api/database/migrations/tenant/2026_08_10_100000_add_inventory_valuation_mode_to_companies.php:43-52`), `CREATE UNIQUE INDEX IF NOT EXISTS` (`apps/api/database/migrations/tenant/2026_04_24_000002_add_z_report_alert_type_unique_index_to_fraud_alerts.php:39`) | — | MATCH — transactional + idempotent statements; the backfill is idempotent by predicate (S1 §7.4) |
| B11 | Second company | Per company | Per company | Per entity | Transfer number unique per company (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66`) | — | MATCH — document keys carry `company_id`; S-matrix in every slice |
| B12 | Second location | Per picking | Per warehouse | Per warehouse | Destination is a transfer attribute (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:45`) | — | MATCH — S-matrix |
| B13 | Correction / reversal | Return picking | Cancel + amend | Manual | The stock-adjustment document refuses `damage`/`write_off` on every batch-tracked product (`apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:520`) | MISSING | DIVERGE v1: under-report → further receipt; over-report → no `void` (ticketed); receipt rows immutable |
| B14 | Remainder returned to source: stock back, no P&L | Internal move, no journal | No P&L | No posting | The cancel restock loop exists only from `in_transit` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:452`) | MISSING | MATCH (OQ-1) — `return_to_source`, stock movement only, no GL (S1 §7.8) |
| B15 | Every receipt carries who received what against what was sent, queryable | Moves carry user + qty | SLE carries user | — | No receipt facts exist | MISSING | MATCH — per-line stored event with receiver identity, role, sent/received/damaged/written_off/returned/reason/blind (S1 §7.9) |

**Domain norm.** Blind receiving is a WMS control against confirmation bias: the receiver counts without seeing the ASN quantity; the system compares afterwards and routes variances to a supervisor. It is documentary; it is not a mask on the site's own stock.

**Second-of-everything (convention 09, `docs/conventions/09-SECOND-OF-EVERYTHING.md:37`).** Every new writer in this lane carries second-company, second-location and re-run assertions on **data meaning** (§7.13, §8.10, §9.8, §10.8). No `CATALOGUE_TABLES` entity is touched: the three receipt tables are documents, and the ratchet excludes primary/id keys (`docs/conventions/09-SECOND-OF-EVERYTHING.md:54-59`), so the relationship-scoped uniques listed in §7.3 are legitimate without `company_id`.

---
## 5. Vocabulary and current-state evidence

### 5.1 Glossary rows (convention 11, `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md:44`)

`docs/glossary.md` today has no "Stock operations" section: its headings are at `docs/glossary.md:13`, `:22`, `:32`, `:48`, `:67` and `:80`. **S1 adds one new `## Stock operations` section carrying all nine rows below in one edit**, so `docs/glossary.md` appears in exactly one push. The three blind-receiving rows are added in S1 with the rest even though their code lands in S2/S3: the glossary is documentation, the definitions are fixed by the accepted spec, and splitting them would put the file in two pushes for no benefit. The section is inserted between `## Documents and POS` (`docs/glossary.md:67`) and `## Process terms` (`docs/glossary.md:80`), using the same five-column table shape as the existing entity sections.

| Term | Definition | Table / module | Canonical surface | Synonyms |
|---|---|---|---|---|
| **Transfer** | Intracompany movement of stock from a source to a destination location: source decremented on initiate (`in_transit`), destination incremented by one or more transfer receipts. | `stock_transfers`, `stock_transfer_lines`, `stock_transfer_line_batch_allocations` / Inventory | Stock → Transfers; writers `StockTransferService` (initiate/cancel) + `StockTransferReceiptService` (receive/close; `complete` delegates) | transfert, stock transfer |
| **Transfer receipt** | Posted, immutable document recording what a destination physically received from one transfer at one moment (per line received, damaged, lots, reason), or a close, or a legacy completion backfilled from pre-receipt history. | `stock_transfer_receipts`, `stock_transfer_receipt_lines`, `stock_transfer_receipt_line_lots` / Inventory | Transfer detail → Receive; `POST /stock-transfers/{id}/receive` | réception de transfert |
| **In-transit remainder** | Per line/lot: `quantity − received − damaged − written_off − returned`; the only definition of "incoming" for stock readers; positive only in `in_transit`/`partially_received`. | derived; `StockTransferLine::REMAINDER_SQL` + `StockTransfer::CARRYING_STATUSES` | Matrix/location "incoming"; detail "remaining" (visibility-gated) | reste à recevoir, backorder |
| **Discrepancy** | Confirmed variance: damaged (at receipt) or short (at close). Carries `TransferDiscrepancyReason`, constrained per action. Over-receipt is refused. An open remainder is NOT a discrepancy. | columns on `stock_transfer_receipt_lines` | Receive dialog; close dialog; reconciliation | écart, variance |
| **Close** | Terminal supervisor action (`inventory.transfers.reconcile` AND `inventory.transfers.close`) on a transfer with an open remainder, one disposition for the whole remainder: write-off (GL shrinkage) or return to source (no GL). Writes a receipt row of kind `close`. | `stock_transfers.status/closed_*/freight_uncapitalized`, `stock_transfer_receipts.kind = close` | Transfer detail → Close; `POST /stock-transfers/{id}/close` | clôture, close backorder |
| **Blind receiving** | `company_fraud_settings.blind_receiving` (default false): when on, a destination user without `inventory.transfers.reconcile` or source access cannot learn the expected/remaining quantity of an open transfer line from any TRANSFER, RECEIPT, REPLENISHMENT, MOVEMENT/ENTRY-EXIT or NOTIFICATION surface, cannot post a quantity-less completion, and cannot close. The control is documentary: destination on-hand, available and lot-stock POSITIONS stay visible and the delta they expose is accepted residual R5. | `company_fraud_settings.blind_receiving` / Compliance | Settings → Fraud & controls | réception à l'aveugle |
| **Receiver view** | Receiver-facing projection: identity, lines, lot identity, own prior receipts — never sent/expected/remaining, never sender-authored text or cost fields. Built only by `TransferReceiverPayloadBuilder`. | projection / Inventory | `GET /stock-transfers/{id}/receiver-view` | vue réceptionnaire |
| **Reconciliation** | Supervisor comparison of sent vs received/damaged/written-off/returned/remaining per line and lot with reasons. | projection / Inventory | `GET /stock-transfers/{id}/reconciliation` | rapprochement |
| **Visibility version** | Monotonic integer per company, initialised to 1 by the company-creation writer and bumped atomically on every fraud-settings controller write after that; carried by every transfer/incoming payload; ADVISORY — clients use it to discard cached transfer/incoming data on a mismatch. | `company_fraud_settings.visibility_version` / Compliance | payload field `visibility_version` / `meta.visibility_version` | — |

One surface per concept, restated as an enforceable contract for this lane:

- **Transfer receipt** has exactly one writer, `StockTransferReceiptService`. `POST /complete` delegates to it (§8.6); `completeLocked` is deleted in S1.
- **Receiver projection** has exactly one builder, `TransferReceiverPayloadBuilder`. No controller, resource or client may construct a receiver shape by omitting keys from the full builder's output.
- **Full transfer projection** has exactly one builder, `TransferPayloadBuilder`. `StockTransferController::formatTransfer` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:282`) is deleted in S1.
- **Blind predicate** has exactly one implementation, `ExpectedQuantityVisibility`, reached only through the two Shared contracts (§6.1). No module reads `company_fraud_settings` directly for this decision.
- **No hand-rolled FE type beside a generated DTO**: the six entity shadows at `apps/web/src/features/stock-transfers/types/index.ts:12-72` are deleted in S4 and replaced by generated DTOs.

### 5.2 Current-state evidence (opened at the planning HEAD)

Write path and lifecycle:

- `StockTransferService::initiate` is the only creator (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:86`); it replays a matching idempotency key without comparing the payload (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:111` — ticket `2026-09-09-t1-idempotency-payload.md`, out of scope here).
- `complete` locks the header then takes **all** line product advisory locks in one sorted call (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317`, comment at `:326`) and calls `completeLocked` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:346`), which receives every line with no receiver input (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:358`).
- The completed status is persisted **before** `capitalizeTransferCost` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:386`), and capitalisation runs only for a positive cost (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:400`).
- `cancel` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:429`) restocks the source, lot-aware (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:452`).
- Initiation issues the source lot (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:571`).
- `capitalizeTransferCost` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:631`) weights on shipped quantity (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:714`).
- `lockTransfer` is the header `lockForUpdate` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:734`); `markMovementAsTransfer` rewrites the movement row after creation (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:745`).
- `QTY_SCALE = 4` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:63`).

Status enum and schema:

- `TransferStatus` has four cases (`apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17`), with `isTerminal()` (`:22`), `canBeCompleted()` (`:32`), `canBeCancelled()` (`:37`) and `label()` (`:42`) as the only exhaustiveness sites in PHP.
- `stock_transfers.status` is already `string(20)` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:42`) — `closed_with_writeoff` is exactly 20 characters, so **no enum or column alteration is needed**.
- `transfer_cost decimal(15,4)` (`:49`), `initiated_by_user_id` restrict-on-delete (`:55`), the two company-scoped uniques (`:66`, `:67`), `stock_transfer_lines.quantity decimal(15,4)` (`:86`), `allocated_transfer_cost decimal(15,4)` (`:88`).
- `stock_transfer_line_batch_allocations.quantity decimal(15,4)` (`apps/api/database/migrations/tenant/2026_06_05_121000_create_stock_transfer_line_batch_allocations_table.php:25`) with unique `(stock_transfer_line_id, batch_id)` (`:28`).
- `product_batches.id` is `$table->id()` — an auto-increment big integer (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:14`); every `batch_id` column in this lane is therefore `unsignedBigInteger`, never `integer`.
- `company_fraud_settings` has a uuid PK (`apps/api/database/migrations/tenant/2025_12_23_160000_create_company_fraud_settings_table.php:17`) and a unique `company_id` (`:35`).
- `stored_events.event_properties` is `jsonb` (`apps/api/database/migrations/tenant/2025_11_30_102448_create_stored_events_table.php:17`) with unique `(aggregate_uuid, aggregate_version)` (`:23`).
- `notifications` uses `uuidMorphs('notifiable')` (`apps/api/database/migrations/tenant/2026_07_12_110000_create_notifications_table.php:16`).

Model decimal contract:

- `StockTransferLine` declares `@property numeric-string $quantity` (`apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:25`) and casts in `casts()` (`:56`); `StockTransferLineBatchAllocation` the same (`apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php:21`, `:46`); `StockTransfer::casts()` carries `'transfer_cost' => 'decimal:4'` (`apps/api/app/Modules/Inventory/Domain/StockTransfer.php:85`, `:91`).
- `QuantityScale::SCALE = 4` (`apps/api/app/Shared/Domain/QuantityScale.php:20`).
- The precision contract's storage tier requires the cast (`docs/architecture/precision-contract.md:15`); the quantity ingress regex is `^-?\d+(\.\d{1,4})?$` (`docs/architecture/precision-contract.md:35`).

Readers that must convert (exactly three files, four query sites):

- `LocationStockQueryService` — grouped incoming (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137`), root `DB::table('stock_transfer_lines')` (`:139`), status predicate (`:144`), aggregate (`:156`); and the distribution root (`:223`), predicate (`:228`), aggregate (`:233`).
- `StockMatrixQueryService::incoming` (`apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390`), root (`:396`), predicate (`:402`), aggregate inside `selectRaw` (`:404`). `cellsForRows` fills `on_hand` from `stock_levels.quantity` (`:287`) — that member is R5 and is **not** touched.
- `WeightedAverageCostService` — Eloquent root (`apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:119`), predicate (`:124`), `->sum('stock_transfer_lines.quantity')` (`:125`).

Movements and GL:

- `StockAdjustmentService::receive` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115`), its transaction (`:133`), its batch movement (`:168`); `issue` (`:253`).
- `InventoryGlPostingBuffer::flushIfOutermost` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56`), the nested-level branch (`:60`), the outside-transaction throw (`:66`).
- `InventoryGlPostingService` shrinkage counter (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:185`); `postForBatchWriteOff` requires batch number and product id (`:95`).
- `InventoryGlPostingBoundaryGuard` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBoundaryGuard.php:16`), wired for tests at `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:119`.
- PHPStan forbids direct `postFor*` calls (`apps/api/app/PHPStan/Rules/InventoryGlPostingViaBufferOnly.php:17`) and float casts on `decimal:*` properties (`apps/api/app/PHPStan/Rules/ForbidFloatCastOnDecimalProperty.php:20`).
- `MovementReason::Damage` (`apps/api/app/Modules/Inventory/Domain/Enums/MovementReason.php:26`) and `::WriteOff` (`:28`); `MovementGlKind::Exit` (`apps/api/app/Modules/Inventory/Domain/Enums/MovementGlKind.php:9`) and `::BatchWriteOff` (`:12`); the enqueue-context precedent (`apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:169`).
- `InventoryValuationModeResolver::requirePerpetual` (`apps/api/app/Modules/Inventory/Application/Services/InventoryValuationModeResolver.php:79`) throws `UnsupportedValuationModeException` (`apps/api/app/Modules/Inventory/Domain/Exceptions/UnsupportedValuationModeException.php:22`), which has no HTTP mapping today.
- `Product::resolveMovementUnitCost` fallback chain (`apps/api/app/Modules/Product/Domain/Product.php:315`).

Routes, gates and envelopes:

- The Inventory group middleware satisfies rule 12 (`apps/api/app/Modules/Inventory/Presentation/routes.php:31`); transfer routes are at `:99` (list), `:107` (show), `:111` (complete), `:115` (cancel).
- Stock levels `:57`, thresholds `:66`, matrix `:70`, rebalance `:73`, movements `:78`, entry/exit `:82`.
- `require.any.permission` alias (`apps/api/bootstrap/app.php:120`) → `RequireAnyPermission::handle` (`apps/api/app/Http/Middleware/RequireAnyPermission.php:14`), whose 403 message literal is currently specific to its first consumer (`apps/api/app/Http/Middleware/RequireAnyPermission.php:25`).
- The typed error helpers are `locationAccessDeniedResponse` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:359`) and `stateExceptionResponse` (`:389`); the pre-existing untyped `INSUFFICIENT_STOCK` branch is at `:187` and is not touched.
- Framework validation 422 rendering (`apps/api/bootstrap/app.php:325`).
- Permission seeding: the transfer permission block (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:196`, `inventory.transfers.cancel` at `:200`), `admin` via `permissionNames()` (`:582`), the `manager` map (`:585`) with the transfer line at `:602`; `inventory.view` is broadly seeded (`:702`).
- `LocationContext::getAllowedLocationIds` returns `null` for unrestricted (`apps/api/app/Modules/Company/Services/LocationContext.php:194`); `canAccessLocation` (`:224`); `LocationScopeResolver::resolve` (`apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31`).

Surfaces to close in S3:

- `StockMovementController::__construct` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:49`), `index` (`:54`), company/tenant predicates (`:71`), location scope (`:80`), the per-source-kind lookups (`:127`), the envelope (`:167`), `formatMovement` (`:208`) with `quantity` (`:218`), `quantity_before` (`:220`), `quantity_after` (`:221`), `reference_type` (`:228`), `user_id` (`:244`). **There is no actor predicate.**
- `EntryExitNoteController::__construct` (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:22`), the group query (`:44`), company/tenant predicates (`:52`), the `$meta` array (`:111`), `formatNote` (`:216`) with `quantity` (`:247`), `quantity_before` (`:249`), `quantity_after` (`:250`). **No actor predicate and no location scope.**
- `ReplenishmentRequestResource` (`apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:12`) emits `requested_qty` (`:25`), `suggested_qty` (`:26`), `note` (`:32`), `fulfillment_id` (`:40`); collection call sites `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:73` and `:83` (constructor at `:29`, `meta` at `:74`) and `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:107` (constructor at `:25`, envelope at `:106`).
- POS stock levels: constructor (`apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php:51`), `stock[]` (`:85`), `incoming[]` (`:104`), `meta` (`:112`). POS distribution: constructor (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:29`), the `Gate::authorize('pos.view_cross_location_stock')` (`:37`), the response (`:80`) with `incoming_transfer` (`:91`). Routes at `apps/api/app/Modules/POS/routes.php:142`, `:148`, `:161`.
- Shared reader contract `read` (`apps/api/app/Shared/Contracts/LocationStockReader.php:24`) and `stockDistributionForProduct` (`:45`); DTO members `LocationIncomingRowDTO::$incomingTransfer` (`apps/api/app/Shared/DTOs/LocationIncomingRowDTO.php:27`), `StockDistributionRowDTO::$incomingTransfer` (`apps/api/app/Shared/DTOs/StockDistributionRowDTO.php:31`), `StockDistributionDTO::$totalIncomingTransfer` (`apps/api/app/Shared/DTOs/StockDistributionDTO.php:29`); binding at `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:63`.

R5 surfaces that must stay untouched (E3: rows 19–**25**):

- `GET /stock-levels` list/show (`apps/api/app/Modules/Inventory/Presentation/routes.php:57`), DTO members (`apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php:18`).
- `GET /products/{product}/stock-levels` and `GET /products/{product}` show (`apps/api/app/Modules/Product/routes.php:58`; controller `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:304`; `ProductData` carries `stock_quantity` at `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:131`).
- `GET /inventory/stock-matrix/rebalance` (`apps/api/app/Modules/Inventory/Presentation/routes.php:73`), pair requirement (`apps/api/app/Modules/Inventory/Application/Services/StockRebalanceQueryService.php:92`), threshold writer that creates an absent row (`apps/api/app/Modules/Inventory/Application/Services/StockThresholdService.php:45`).
- Counting reconciliation (`apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:185`, payload member `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationPayloadBuilder.php:76`), whose `theoretical_qty` is a copy of `stock_levels.quantity` (`apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:297`); the counter side stays blind by construction (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334`) with shipped precedents `apps/api/tests/Feature/Inventory/BlindCountingTest.php:187`, `:207`, `:261`.
- Batch surfaces with no read permission (`apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22`), per-location triple (`apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:55`), POS suggestion envelope (`apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:23`).

Web and POS:

- Hand-written entity shadows and the "hand-written on purpose" header (`apps/web/src/features/stock-transfers/types/index.ts:1`, shadows from `:12`, request/query types from `:74`, filters `:97`, list envelope `:105` with `data: StockTransfer[]` at `:106`).
- API module: `list` keeps the envelope (`apps/web/src/features/stock-transfers/api/stockTransferApi.ts:31`), `show` returns the full type (`:37`), `complete` (`:45`), `cancel` (`:49`). `apiPost` unwraps `response.data.data` (`apps/web/src/lib/api.ts:422`).
- Query keys: namespace (`apps/web/src/features/stock-transfers/api/queries.ts:9`), list leaf (`:13`), detail leaf (`:20`), create hook (`:31`), complete hook + invalidation (`:43`); `tenantScopedKey` appends tenant/company as suffixes (`apps/web/src/lib/tenantScopedKey.ts:29`); client defaults `staleTime: 5 min` (`apps/web/src/lib/queryClient.ts:6`).
- Detail page: `canComplete` (`apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38`), the Complete button (`:98`), the summary section `:124`–`:176` (source name at `:131`).
- List page: `STATUS_OPTIONS` (`apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15`), row map (`:114`) with the two location cells (`:125`) and `created_at` (`:137`); badge record (`apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5`).
- Routes: list guard (`apps/web/src/routes/index.tsx:1402`, permission at `:1405`), detail guard (`:1422`, permission at `:1425`); `RequirePermission` props (`apps/web/src/features/auth/components/RequirePermission.tsx:7`, any-of branch at `:60`); sidebar item (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:239`).
- Movement feeds in web: `apps/web/src/features/inventory/StockMovementsPage.tsx:144` (key at `:145`), `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:85`, `apps/web/src/features/inventory/EntryExitNotesPage.tsx:73`; the shared row type members (`apps/web/src/features/inventory/types.ts:38`) and envelope (`:60`).
- Notifications: `KNOWN_TYPES` (`apps/web/src/features/notifications/components/NotificationPanel.tsx:20`), the `type` switch (`:45`), the deep-link navigation (`:94`).
- Replenishment web row type already nullable (`apps/web/src/features/replenishment/types/index.ts:4`); fraud-settings page section anchor (`apps/web/src/features/compliance/pages/FraudSettingsPage.tsx:397`).
- POS: distribution row and totals members (`apps/pos/src/types/stockDistribution.ts:12`, `:28`), server row shapes (`apps/pos/src/lib/db/repositories/locationStockRepository.ts:45`, `:55`), `replaceIncoming` (`:191`, bind at `:216`), the SQLite column (`apps/pos/src/lib/db/migrations.ts:1589`), distribution cache row (`apps/pos/src/lib/db/repositories/crossLocationStockRepository.ts:18`, upsert at `:43`), open replenishment cache (`apps/pos/src/lib/db/repositories/openReplenishmentRepository.ts:40`), sync metadata get/set (`apps/pos/src/lib/db/repositories/syncLogRepository.ts:19`, `:28`), the stock write transaction (`apps/pos/src/lib/sync/syncService.ts:1146`), the replenishment pull (`apps/pos/src/lib/replenishment/replenishmentSyncService.ts:97`), the already-nullable replenishment row type (`apps/pos/src/api/replenishmentApi.ts:17`), and the cross-location renderer (`apps/pos/src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx:104`).

Generated artifacts and their drift guards:

- `packages/shared/types/generated.d.ts` — `TransferStatus` today has four members (`packages/shared/types/generated.d.ts:1240`); a `string` PHP member is emitted as `string` (`:1095`). `scripts/preflight.sh:110` regenerates and `scripts/preflight.sh:116-133` fails on drift; CI repeats it (`.github/workflows/ci.yml:2683-2700`).
- `apps/web/src/hooks/permissionsMap.generated.ts` — regenerated at `scripts/preflight.sh:140` with a drift gate at `:147-155` and in CI (`.github/workflows/ci.yml:2705-2714`).

---

## 6. Shared contracts and named-symbol census

### 6.1 Visibility contract (S2 introduces; S3 consumes)

Two Shared contracts. Neither Inventory, POS nor Replenishment imports a Compliance model for this decision; the direct import at `apps/api/app/Modules/POS/Application/Services/FraudSettingsResolver.php` is prior debt and is not a precedent (CLAUDE.md rule 6).

```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface TransferIncomingVisibility
{
    public function canSeeIncomingAggregates(string $userId, string $companyId): bool;

    public function visibilityVersion(string $companyId): int;
}
```

```php
<?php

declare(strict_types=1);

namespace App\Shared\Contracts\Compliance;

interface ReceivingControlsReader
{
    public function blindReceivingEnabled(string $companyId): bool;

    public function visibilityVersion(string $companyId): int;
}
```

Semantics, fixed by spec §5.3 and not renegotiable by a slice:

- `canSeeExpected(User $user, StockTransfer $transfer): bool` = setting off **OR** `inventory.transfers.reconcile` **OR** `canAccessLocation(source)`. It is a method on `ExpectedQuantityVisibility` only; it is deliberately **not** on the Shared interface, because it needs a transfer.
- `canSeeIncomingAggregates(userId, companyId)` = setting off **OR** reconcile **OR** unrestricted membership (`getAllowedLocationIds` returns `null`, `apps/api/app/Modules/Company/Services/LocationContext.php:194`). It is the strictly-safe company-scoped superset used by surfaces 7–11 and 17–18, because those feeds are cross-transfer ledgers.
- Exactly one settings read per request; both methods are memoised per `(userId, companyId)` inside the request-scoped instance.

### 6.2 Decimal contract (all slices)

- Every new money/quantity column carries an Eloquent `decimal:N` cast and a `@property numeric-string` annotation, per `docs/architecture/precision-contract.md:15` and the sibling precedent `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:25`.
- Quantities are `decimal(15,4)`; arithmetic is `bcadd`/`bcsub`/`bccomp`/`bcmul`/`bcdiv` at `QuantityScale::SCALE` (`apps/api/app/Shared/Domain/QuantityScale.php:20`) or, for the freight pool, at the working scale of §7.11. Never a float, never `(float)$model->prop`, never `number_format` on a decimal property.
- Money is rounded once at the boundary through `CurrencyScale::bcformatStrict($value, $scaleResolver->getScale($currency))`; in the terminal transition, which may run without `CompanyContext`, use `getScaleSafe($company->currency_code, 3)` (CLAUDE.md rule 19).
- Every new DTO member for a quantity or money value is `public string` (or `public ?string`); `visibility_version` is `public int`. TypeScript therefore receives `string` / `number`.
- FormRequests keep `numeric` and ADD the scale ceiling: quantity `regex:/^\d+(\.\d{1,4})?$/` (non-negative in this lane).

### 6.3 Idempotency contract (S1)

- Key space is **company-wide**, not per transfer: unique `(tenant_id, company_id, idempotency_key)` on `stock_transfer_receipts`.
- The prefix `sys:` is a **reserved server namespace**. The only keys in it are `sys:complete:{transferId}` (§8.6) and `sys:legacy-completion:{transferId}` (§7.4). A client key matching `/^sys:/i` is refused by request rule A6 before any lookup, so a client can never pre-empt them.
- Replay discrimination: same transfer **and** equal `payload_hash` → the stored receipt, HTTP 200, `meta.replayed = true`, whatever the transfer's current state. Different transfer **or** different hash → typed 422 `IDEMPOTENCY_KEY_REUSED`.
- The canonical hash is computed by `ReceiptPayloadCanonicalizer` (§7.5 rule 3) and is the only definition of payload equality.

### 6.4 Event contract (S1, S2)

- Existing events are immutable (CLAUDE.md rule 8). `StockTransferInitiated`, `StockTransferCancelled` and `StockTransferCompleted` are **not** edited, renamed or restructured. `StockTransferCompleted` continues to fire on the `completed` transition whichever path caused it.
- New stored events are **new classes** extending `App\Shared\Domain\Events\DomainEvent` (`apps/api/app/Shared/Domain/Events/DomainEvent.php:16`), persisted through `StoredEventRepository::persist($event, $aggregateUuid)` with an explicit `setAggregateRootVersion(...)`. They are **never** routed through `event()`, which would store a second, anchor-less copy.
- Queued listeners run with **no** `CompanyContext` (CLAUDE.md rule 20). Notification recipients are resolved in the request, inside `DB::afterCommit`, never in the worker; the worker test clears the context before processing.

### 6.5 Named-symbol census at the planning HEAD

Re-run this census against `DISPATCH_SHA` before implementing (§1.1). Every row was opened at `7dcc77afcf0365d4a3ab9ca605d3dd2e1e647395`, re-verified at `73f2040c6d997b26a2a55dfb7d3c8742d361792e`, re-verified at `ab4b321f0417e536d676d8c94b97e09491f18b3c`, re-verified at `364b1668be62a252240e9459e720bf20299a2a61`, and re-verified again at the **rev-6 planning HEAD `f9f6f4480f772d7644b28bc4379cd21a4aef64e0`**. Rev 1 said "none of the six production files the T-1 merge moved appears in this census"; **rev 2 corrects that**: three of them now do appear, because this revision makes them load-bearing — `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml` are edit targets of all four pushes (gate r1 B1, §6.7) and `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php` is the cited harness precedent for the T10 race and an M3 regression target. Their rows are at the bottom of the table below and their line content was read, not merely their paths.

| Existing FQCN / symbol | Operative evidence | Touched by |
|---|---|---|
| `App\Modules\Inventory\Application\Services\StockTransferService::complete()` | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317` | S1 (delegation) |
| `App\Modules\Inventory\Application\Services\StockTransferService::completeLocked()` | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:346` | S1 (**deleted**) |
| `App\Modules\Inventory\Application\Services\StockTransferService::cancel()` | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:429` | S1 (restock loop extracted) |
| `App\Modules\Inventory\Application\Services\StockTransferService::capitalizeTransferCost()` — **`private`** at that line | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:631` | S1 — **MOVED** to `StockTransferMovementSupport::capitalizeTransferCost()` (§6.7b); the private method is deleted and both callers go through the collaborator |
| `App\Modules\Inventory\Application\Services\StockTransferService::computeAllocationWeights()` — **`private`** at that line | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:714` | S1 — **MOVED** to `StockTransferMovementSupport::computeAllocationWeights()` (§6.7b) |
| `App\Modules\Inventory\Application\Services\StockTransferService::lockTransfer()` — **`private`** at that line | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:734` | S1 — **MOVED** to `StockTransferMovementSupport::lockTransfer()` (§6.7b); body unchanged |
| `App\Modules\Inventory\Application\Services\StockTransferService::markMovementAsTransfer()` — **`private`** at that line | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:745` | S1 — **MOVED** to `StockTransferMovementSupport::markMovementAsTransfer()` (§6.7b); body unchanged |
| `App\Modules\Inventory\Application\Services\StockTransferService::lineProductIds()` — **`private`** at that line | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:1056` | S1 — **MOVED** to `StockTransferMovementSupport::lineProductIds()` (§6.7b); both existing call sites `:334` and `:448` re-point |
| `App\Modules\Inventory\Application\Services\StockTransferService::$costLock` (`ProductCostLock`, promoted `private readonly`) | `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:75` | S1 — also constructor-injected by `StockTransferMovementSupport` and by `StockTransferReceiptService` |
| `Symfony\Component\Process\Process` two-process PG race harness | `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php:89-131` (spawn at `:111`, `childEnvironment()` at `:163`, `pg_stat_activity` wait-event poll at `:115-123`, `assertTrue($blocked, …)` at `:124`, `assertSame(0, $this->contender->wait(), …)` at `:126`) | S1 (T7/T7b/T7c), S4 (T10 two-worker notification race, §10.10) |
| `apps/api/tests/feature-lane-manifest.json` — `groups.Inventory.classes`, `.Replenishment.classes`, `.Migrations.classes`, `.Compliance.classes`, `.Notification.classes`, top-level `gated_ceiling` | `apps/api/tests/feature-lane-manifest.json` (`debt_ceiling` at `:8`, `gated_ceiling` at `:9`, `"Compliance"` at `:749`, `"Inventory"` at `:834`, `"Migrations"` at `:874`, `"Notification"` at `:891`, `"Replenishment"` at `:957`) | S1, S2, S3, S4 (§6.7) |
| `.github/workflows/ci.yml` — the `backend-test-pgsql` `--filter` allowlist | `.github/workflows/ci.yml:1141-1142` (job `backend-test-pgsql` at `:578`; the T-1 precedent comment block at `:1132-1140`) | S1, S2, S3, S4 (§6.7) |
| `php tools/feature-lane-manifest-check.php` — the enforcing checker | invoked by CI at `.github/workflows/ci.yml:191` (job `backend-architecture`, no `if:` guard, in the all-checks-pass `needs` list at `:2769`) | run by every slice (§13) |
| `App\Modules\Inventory\Domain\Enums\TransferStatus` | `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php:17` | S1 (3 new cases + 3 new methods) |
| `App\Modules\Inventory\Domain\StockTransfer::casts()` | `apps/api/app/Modules/Inventory/Domain/StockTransfer.php:85` | S1 |
| `App\Modules\Inventory\Domain\StockTransferLine::casts()` | `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:56` | S1 |
| `App\Modules\Inventory\Domain\StockTransferLineBatchAllocation::casts()` | `apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php:46` | S1 |
| `App\Modules\Inventory\Presentation\Controllers\StockTransferController::formatTransfer()` | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:282` | S1 (**deleted**, replaced by `TransferPayloadBuilder`) |
| `App\Modules\Inventory\Presentation\Controllers\StockTransferController::canSeeTransfer()` | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:347` | S1 (reused), S2 (receiver-view) |
| `App\Modules\Inventory\Presentation\Controllers\StockTransferController::locationAccessDeniedResponse()` | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:359` | S1 (reused) |
| `App\Modules\Inventory\Presentation\Controllers\StockTransferController::stateExceptionResponse()` | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:389` | S1 (reused) |
| `App\Modules\Inventory\Application\Services\LocationStockQueryService` | `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:137` | S1 (remainder), S3 (flag) |
| `App\Modules\Inventory\Application\Services\StockMatrixQueryService::incoming()` | `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:390` | S1 (remainder), S3 (flag) |
| `App\Modules\Inventory\Application\Services\WeightedAverageCostService::companyOwnedQuantity()` | `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:117` | S1 |
| `App\Modules\Inventory\Domain\Services\StockAdjustmentService::receive()` / `::issue()` | `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115` / `:253` | S1 (called, not modified) |
| `App\Modules\Inventory\Application\Services\InventoryGlPostingBuffer::flushIfOutermost()` | `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56` | S1 (called) |
| `App\Modules\Inventory\Application\Services\InventoryValuationModeResolver::requirePerpetual()` | `apps/api/app/Modules/Inventory/Application/Services/InventoryValuationModeResolver.php:79` | S1 (called) |
| `App\Shared\Domain\Events\DomainEvent` | `apps/api/app/Shared/Domain/Events/DomainEvent.php:16` | S1, S2 (extended) |
| `Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository` binding | `apps/api/config/event-sourcing.php:74`; `dispatch_events_from_aggregate_roots` false at `:147` | S1, S2 (injected) |
| `Database\Seeders\RolesAndPermissionsSeeder` transfer permission block / `manager` map | `apps/api/database/seeders/RolesAndPermissionsSeeder.php:196` / `:585` / `:602` | S1 |
| `App\Http\Middleware\RequireAnyPermission::handle()` | `apps/api/app/Http/Middleware/RequireAnyPermission.php:14`; message literal at `:25` | S1 (message genericised) |
| `App\Modules\Company\Services\LocationContext::getAllowedLocationIds()` / `::canAccessLocation()` | `apps/api/app/Modules/Company/Services/LocationContext.php:194` / `:224` | S1, S2 |
| `App\Modules\Company\Services\LocationScopeResolver::resolve()` | `apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31` | S3 (entry/exit fix) |
| `App\Modules\Compliance\Domain\CompanyFraudSettings` (`$attributes`/`$fillable`/`casts()`/`getDefaults()`) | `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php:53` / `:108` / `:131` / `:165` | S2 |
| `App\Modules\Compliance\Presentation\Controllers\FraudSettingsController::update()` / `::reset()` | `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:146` / `:200`; `CASH_CONTROL_KEYS` at `:30`; validation at `:108` | S2 |
| `App\Modules\Compliance\Application\Services\CompanyFraudSettingsService::ensureForCompany()` | `apps/api/app/Modules/Compliance/Application/Services/CompanyFraudSettingsService.php:18`, invoked by `apps/api/app/Modules/Compliance/Listeners/EnsureFraudSettingsOnCompanyCreated.php:17`, wired at `apps/api/app/Providers/EventServiceProvider.php:69` | S2 (asserted unchanged; I8) |
| `App\Modules\Compliance\Infrastructure\Repositories\CompanyFraudSettingsRepository::findByCompany()` | `apps/api/app/Modules/Compliance/Infrastructure/Repositories/CompanyFraudSettingsRepository.php:11` | S2 (read by the Compliance adapter) |
| `App\Modules\Compliance\Providers\ComplianceServiceProvider::register()` | `apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php:57` | S2 (contract binding added beside the singleton) |
| `App\Modules\Inventory\Providers\InventoryServiceProvider::register()` (`LocationStockReader` binding) | `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:63` | S2 (`TransferIncomingVisibility` binding), S1 (`Event::listen` style at `:92`) |
| `App\Shared\Contracts\LocationStockReader::read()` / `::stockDistributionForProduct()` | `apps/api/app/Shared/Contracts/LocationStockReader.php:24` / `:45` | S3 |
| `App\Shared\DTOs\LocationIncomingRowDTO::$incomingTransfer` | `apps/api/app/Shared/DTOs/LocationIncomingRowDTO.php:27` | S3 (`?string`) |
| `App\Shared\DTOs\StockDistributionRowDTO::$incomingTransfer` | `apps/api/app/Shared/DTOs/StockDistributionRowDTO.php:31` | S3 (`?string`) |
| `App\Shared\DTOs\StockDistributionDTO::$totalIncomingTransfer` | `apps/api/app/Shared/DTOs/StockDistributionDTO.php:29` | S3 (`?string`) |
| `App\Modules\Inventory\Presentation\Controllers\StockMovementController::formatMovement()` | `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:208` | S3 |
| `App\Modules\Inventory\Presentation\Controllers\EntryExitNoteController::formatNote()` | `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:216` | S3 |
| `App\Modules\Replenishment\Presentation\Resources\ReplenishmentRequestResource` | `apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:12` | S3 |
| `App\Modules\POS\Presentation\Controllers\PosStockLevelController` / `StockDistributionController` | `apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php:51` / `apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:29` | S3 |
| `App\Modules\Notification\Presentation\Controllers\NotificationController::index()` | `apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:20` | S4 (contract asserted, not modified) |
| `StockTransferDetailPage()` / `StockTransferListPage()` | `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38` / `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15` | S4 |
| `AppRoutes` transfer guards | `apps/web/src/routes/index.tsx:1405` / `:1425` | S4 |
| `replaceIncoming()` (POS) | `apps/pos/src/lib/db/repositories/locationStockRepository.ts:191` | S4 |

NEW symbols are declared with complete FQCNs and full bodies or signatures in §7–§10. Laravel auto-resolution is the binding unless a provider binding is named. Every constructor added or changed uses promoted `private readonly` properties and correct FQCNs; no slice uses the `app()` helper (CLAUDE.md rule 13).

### 6.6 The seven T-1 seams, and what each slice must do about them

From `docs/superpowers/reviews/2026-09-09-t1-transfers-gate-r2-stock-gl.md`, section "Seams handed to T-2". Each row is a constraint on this lane, not background.

| # | Seam (T-1 fact) | Consequence for this lane |
|---|---|---|
| 1 | **Transfers post ZERO GL today**, including freight: no GL collaborator anywhere in the transfer call graph, no listener on the three transfer events, no GL subscriber on `StockMovementRecorded` | The damage and write-off postings of §7.8 are the **first** GL entries this document type has ever produced. They MUST go through `InventoryGlPostingBuffer` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:56`), never a direct `journal_entries` insert; PHPStan enforces it (`apps/api/app/PHPStan/Rules/InventoryGlPostingViaBufferOnly.php:17`). T13 asserts no leak alarm and no direct `postFor*`. `return_to_source` posts **nothing** (OQ-1) and T4b asserts `journal_entries` is unchanged. |
| 2 | **Freight capitalisation is document-less and GL-less**: `transfer_cost` is free-typed money capitalised into company-wide WAC at completion, with no carrier document and no expense/clearing credit | §7.11 decides what happens when the goods do not arrive: the pool shrinks to the good units and the residual is **persisted, not capitalised and not journalised** (`freight_uncapitalized`, I7). This lane does **not** create the missing GL counterpart — ticket `2026-09-09-t1-freight-capitalization-no-gl.md` owns it (§15). S1 must therefore **preserve** the T-1 zero-journal pins on the freight path and add its own: T4/T4b assert freight arithmetic with `journal_entries` unchanged by the capitalisation step itself. |
| 3 | **Capitalisation ordering is load-bearing**: the transfer must be marked terminal before `capitalizeTransferCost`, or the units are double-counted in the WAC denominator | S1 introduces `partially_received`, a status that is neither `InTransit` nor `Completed`. §7.7 fixes the denominator explicitly: `CARRYING_STATUSES = [in_transit, partially_received]`, so the WAC owned-quantity query counts the **remainder** of both carrying statuses. Capitalisation still runs **only** at the terminal transition, after the status write (§7.11 step 0). T1 asserts company-owned quantity is unchanged across a partial receipt; T4 asserts freight is capitalised exactly once. |
| 4 | **In-transit units live in no `stock_levels` row and no batch row** — only in `stock_transfer_lines` (+ allocations); WAC counts them as owned, the lot ledger does not | This lane creates **no transit location** and writes no `stock_levels`/`inventory_batch_stock` row for units in transit. The remainder stays derived (`REMAINDER_SQL`). Per-location `SUM(inventory_batch_stock) == stock_levels.quantity` therefore continues to hold at both endpoints. §7.8's land-then-scrap sequence exists precisely so a damaged unit has a destination row to be removed from. |
| 5 | **Movement rows are rewritten after creation**: `markMovementAsTransfer` `update()`s the row created by `receive()`/`issue()`, while the announcing event still carries the hardcoded label `'receipt'`/`'issue'` | T-2 receipt events must **not** inherit that split. The receipt's own stored events (§7.9) carry the movement **ids** and the semantic kind directly; no consumer of this lane classifies by the `StockMovementRecordedV2` label. §7.8 states the exact call order: create the movement, mark it, then record its id on the receipt line or lot row. |
| 6 | **Settlement is demand-only and quantity-blind**, fires on initiate, writes no stock and no GL, and swallows per-line failures | OQ-2 applies: no listener is added on close, and neither disposition re-opens or re-settles demand. T14 asserts the settled row is untouched after both dispositions. The lane must **not** add a compensating stock or GL action for the quantity-blind settlement defect — ticket `2026-09-09-t1-settlement-replay.md` owns it (§15). |
| 7 | **Cancel from `in_transit` restocks the source** with `TransferIn` movements, and the reopen path merges demand under a PG partial unique | `return_to_source` **reuses that loop**: the loop body at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:451-480` (inside the `costLock->acquire` closure opened at `:450`) becomes `StockTransferMovementSupport::restockAtSource(...)` (§6.7b), shared by `cancel` and by the close disposition. It is a reuse, not a second writer. `partially_received` is **not** cancellable (`canBeCancelled()` unchanged), so cancel and close can never both fire on the same remainder. |

### 6.7 Feature-lane manifest and live CI gate (closes gate r1 BLOCKER B1)

This lane adds **24 PHP Feature test classes** across five manifest groups, **every one of which is in a lane that is PARKED behind `vars.SELF_HOSTED_RUNNER_READY`**. `apps/api/tests/feature-lane-manifest.json` says so in its own words at `apps/api/tests/feature-lane-manifest.json:1`: "`classes` is an ENFORCED non-growth CEILING for `deferred`/`excluded` groups AND for groups laned into a lane that is still PARKED behind an execution gate: such a group may shrink freely and may NEVER grow, so a new class there fails CI until the ceiling is raised deliberately or the lane actually executes. It is NOT documentation."

Consequence, stated so no slice discovers it at CI time: **the first slice that adds a Feature class makes `php tools/feature-lane-manifest-check.php` fail unless that same commit raises the ceilings.** The checker runs as a discrete step of `backend-architecture` (`.github/workflows/ci.yml:191`), a job with no `if:` guard that is in the `all-checks-pass` `needs` list (`.github/workflows/ci.yml:2769`).

#### 6.7.1 Current values at the rev-2 planning HEAD

Read from `apps/api/tests/feature-lane-manifest.json` at `f9f6f4480` — byte-identical to the values rev 3 read at `364b1668b`, because the ancestry is documentation-only — after the T-1 merge's own raise (`groups.Inventory.raise_note_2026_09_09_t1`, `groups.Replenishment.raise_note_2026_09_09_t1`, `gated_ceiling_raise_note_2026_09_09_t1`). These are the numbers gate r1's B1 table quoted, verified line by line:

| Manifest group | Lane (all PARKED) | `classes` at `f9f6f4480` | Anchor |
|---|---|---:|---|
| `Inventory` | `feature-lane-inventory/Inventory` | **129** | `apps/api/tests/feature-lane-manifest.json:834` |
| `Replenishment` | `feature-lane-inventory/Replenishment` | **8** | `apps/api/tests/feature-lane-manifest.json:957` |
| `Migrations` | `feature-lane-data-console/Migrations` | **14** | `apps/api/tests/feature-lane-manifest.json:874` |
| `Compliance` | `feature-lane-fiscal-finance/Compliance` | **23** | `apps/api/tests/feature-lane-manifest.json:749` |
| `Notification` | `feature-lane-platform-misc/Notification` | **1** | `apps/api/tests/feature-lane-manifest.json:891` |
| — global `gated_ceiling` | — | **1253** | `apps/api/tests/feature-lane-manifest.json:9` |

`debt_ceiling` is **1** (`apps/api/tests/feature-lane-manifest.json:8`) and is **not touched by this lane**: no group of this lane is `deferred`, so no slice may change it.

#### 6.7.2 Which class lands in which group

Classification is by the top-level directory under `apps/api/tests/Feature/`. `apps/api/tests/Architecture/TransferInTransitReadersUseRemainderTest.php` and `apps/api/tests/Support/AssertsBlindPayloads.php` are **outside `tests/Feature/`** and therefore carry **no** manifest group and no ceiling consequence — that is why the totals below are 24 and not 26.

| Slice | Group | New classes | Count |
|---|---|---|---:|
| S1 | `Inventory` | `StockTransferReceiveTest`, `StockTransferReceiveDamageTest`, `StockTransferReceiveLotsTest`, `StockTransferReceiveValidationTest`, `StockTransferCloseTest`, `StockTransferReceiveConcurrencyPostgresTest`, `TransferReceiptEventStreamTest`, `TransferReceiptGlBoundaryTest`, `TransferReceiptPatternQueryPostgresTest`, `TransferLegacyCompletionBackfillTest`, `TransferReceiptSecondOfEverythingTest`, `TransferReceiptAuthorityGateTest` | **12** |
| S1 | `Replenishment` | `TransferCloseReplenishmentSettlementTest` | **1** |
| S1 | `Migrations` | `TransferReceiptSchemaRerunPostgresTest` | **1** |
| S2 | `Inventory` | `TransferReceiverPayloadBuilderKeyScanTest`, `TransferAuthorityMatrixTest` | **2** |
| S2 | `Compliance` | `ReceivingControlsVisibilityVersionTest`, `ReceivingControlsConcurrencyPostgresTest`, `ReceivingControlsSecondOfEverythingTest` | **3** |
| S3 | `Inventory` | `TransferBlindLeakOracleTest`, `TransferMovementMaskingTest`, `EntryExitNoteLocationScopeTest` | **3** |
| S4 | `Notification` | `TransferNotificationTest`, `TransferNotificationRaceTest` (**new in rev 2**, gate r1 M6) | **2** |

Inventory total `12 + 2 + 3 = 17`; Replenishment `1`; Migrations `1`; Compliance `3`; Notification `2`. Lane total **24**.

**This is one more than gate r1's 23**, and the difference is stated rather than silent: rev 2 adds `TransferNotificationRaceTest` to satisfy M6's two-worker requirement (§10.10). Every arithmetic figure below carries that extra class.

#### 6.7.3 Exact per-slice arithmetic — the numbers each commit writes

Each slice writes the **running total after its own additions**, never a delta. A slice that finds a different starting value on `dev` (because a parallel lane raised the same group) re-derives its target as `found + own additions` and records the correction in its handback; the arithmetic below is the expected chain when S1→S2→S3→S4 land in order with nothing else intervening.

| Group | at HEAD | after **S1** | after **S2** | after **S3** | after **S4** |
|---|---:|---:|---:|---:|---:|
| `Inventory` | 129 | **141** (+12) | **143** (+2) | **146** (+3) | 146 (+0) |
| `Replenishment` | 8 | **9** (+1) | 9 | 9 | 9 |
| `Migrations` | 14 | **15** (+1) | 15 | 15 | 15 |
| `Compliance` | 23 | 23 | **26** (+3) | 26 | 26 |
| `Notification` | 1 | 1 | 1 | 1 | **3** (+2) |
| **`gated_ceiling`** | 1253 | **1267** (+14) | **1272** (+5) | **1275** (+3) | **1277** (+2) |

Check: per-slice class additions `14 + 5 + 3 + 2 = 24`; `1253 + 24 = 1277`. Final group values `Inventory 146`, `Replenishment 9`, `Migrations 15`, `Compliance 26`, `Notification 3`, `gated_ceiling 1277`. Gate r1's B1 "Required minimum" column read `146 / 9 / 15 / 26 / 2 / 1276`; rev 2 exceeds the Notification and global minima by exactly one, for the one class M6 required.

#### 6.7.4 The raise-note text each slice writes (truthful, no observed-CI claim)

Each slice adds **one** new `raise_note_2026_09_09_t2t3_s<N>` key to each group it raises, and one `gated_ceiling_raise_note_2026_09_09_t2t3_s<N>` key at the top level. Existing notes are **never** edited or removed (they are the audit trail of prior raises). The note text follows the T-1 and PR-#221 precedent (`groups.Inventory.raise_note_2026_09_09_t1`, `groups.Inventory.raise_note_2026_09_08_qa_bug_09`) and must state four things and claim nothing else:

1. the exact `from -> to` numbers and the class names;
2. that the lane remains **PARKED** behind `vars.SELF_HOSTED_RUNNER_READY`, so without the raise the checker is red on every CI run;
3. whether each class is in the `backend-test-pgsql` `--filter` allowlist, and the **caveat** that `backend-test-pgsql` runs on `workflow_dispatch` / `base_ref==main` / `base_ref==dev` / `push->main` and **not** on `push->dev`, so the gate is live on PR runs and main pushes, not on a direct `dev` promotion (verbatim shape of the caveat at `.github/workflows/ci.yml:1128-1131` and in `groups.Inventory.raise_note_2026_09_08_qa_bug_09`);
4. "Remove the allowlist entries and lower this ceiling when the lane's gate is flipped, not before", plus the local per-file evidence path (the slice's handback) and the sentence **"No observed CI run is claimed."**

Template, with S1's `Inventory` values filled in; each slice substitutes its own group, numbers, class list and handback path. **The PG-only sentence of each note names only the classes of THAT group** (gate r2 MINOR m1: rev 2's Inventory note named `TransferReceiptSchemaRerunPostgresTest`, which is a `Migrations` class and is not one of the twelve Inventory classes; and it called `TransferLegacyCompletionBackfillTest` a plain cross-lane class while listing three wholly-PG classes where Inventory has two):

```text
DELIBERATE RAISE 129 -> 141 (2026-09-09, lane T-2/T-3 slice S1, lane/t2-receipt-spine).
Twelve classes added: StockTransferReceiveTest, StockTransferReceiveDamageTest,
StockTransferReceiveLotsTest, StockTransferReceiveValidationTest, StockTransferCloseTest,
StockTransferReceiveConcurrencyPostgresTest, TransferReceiptEventStreamTest,
TransferReceiptGlBoundaryTest, TransferReceiptPatternQueryPostgresTest,
TransferLegacyCompletionBackfillTest, TransferReceiptSecondOfEverythingTest,
TransferReceiptAuthorityGateTest. The feature lane remains PARKED behind
vars.SELF_HOSTED_RUNNER_READY, so without this raise tools/feature-lane-manifest-check.php
is red on every CI run. All twelve are explicitly selected in the backend-test-pgsql
--filter allowlist in .github/workflows/ci.yml, which is their only live gate while the
lane is parked. TWO of the twelve are wholly PostgreSQL-only by construction:
StockTransferReceiveConcurrencyPostgresTest (advisory-lock and row-lock races across two
OS processes) and TransferReceiptPatternQueryPostgresTest (jsonb operators, aggregate
FILTER clauses, bool_or). A THIRD, TransferLegacyCompletionBackfillTest, is cross-lane: it runs on
both lanes and exactly ONE of its methods,
test_interrupted_backfill_rolls_back_and_the_next_run_completes, is PostgreSQL-only and is
mark-skipped on SQLite. TransferReceiptSchemaRerunPostgresTest is NOT an Inventory class:
it is in the Migrations group and is named in that group's own raise note.
CAVEAT: backend-test-pgsql runs on
workflow_dispatch / base_ref==main / base_ref==dev / push->main - NOT on push->dev, so
this arms the classes on PR runs and main pushes, not on a direct dev promotion. Remove
the allowlist entries and lower this ceiling when the lane's gate is flipped, not before.
Local per-file evidence: docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md.
No observed CI run is claimed. gated_ceiling 1253 -> 1267.
```

S1 raises three groups, so it writes three notes. The `Migrations` note — the one that owns the schema-rerun class (gate r2 m1):

```text
DELIBERATE RAISE 14 -> 15 (2026-09-09, lane T-2/T-3 slice S1, lane/t2-receipt-spine).
One class added: TransferReceiptSchemaRerunPostgresTest. The feature lane
feature-lane-data-console remains PARKED behind vars.SELF_HOSTED_RUNNER_READY, so without
this raise tools/feature-lane-manifest-check.php is red on every CI run. The class IS
named in the backend-test-pgsql --filter allowlist in .github/workflows/ci.yml, which is
its only live gate while the lane is parked. It is wholly PostgreSQL-only by construction:
it reads pg_constraint, pg_indexes and information_schema.columns catalog dumps, which
SQLite does not expose. CAVEAT: backend-test-pgsql runs on workflow_dispatch /
base_ref==main / base_ref==dev / push->main - NOT on push->dev, so this arms the class on
PR runs and main pushes, not on a direct dev promotion. Remove the allowlist entry and
lower this ceiling when the lane's gate is flipped, not before. Local per-file evidence:
docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md.
No observed CI run is claimed. gated_ceiling 1253 -> 1267.
```

And the `Replenishment` note:

```text
DELIBERATE RAISE 8 -> 9 (2026-09-09, lane T-2/T-3 slice S1, lane/t2-receipt-spine).
One class added: TransferCloseReplenishmentSettlementTest. The feature lane
feature-lane-inventory remains PARKED behind vars.SELF_HOSTED_RUNNER_READY, so without
this raise tools/feature-lane-manifest-check.php is red on every CI run. The class IS
named in the backend-test-pgsql --filter allowlist in .github/workflows/ci.yml, which is
its only live gate while the lane is parked. It is NOT PostgreSQL-only: it runs on both
lanes. CAVEAT: backend-test-pgsql runs on workflow_dispatch / base_ref==main /
base_ref==dev / push->main - NOT on push->dev, so this arms the class on PR runs and main
pushes, not on a direct dev promotion. Remove the allowlist entry and lower this ceiling
when the lane's gate is flipped, not before. Local per-file evidence:
docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md.
No observed CI run is claimed. gated_ceiling 1253 -> 1267.
```

The `gated_ceiling_raise_note_2026_09_09_t2t3_s1` key carries the same four statements over the whole slice: `1253 -> 1267`, fourteen classes across three groups, the parked-lane consequence, the allowlist statement with its caveat, and "No observed CI run is claimed."

#### 6.7.5 The `backend-test-pgsql` allowlist decision

**Decision: every one of the 24 classes enters the `--filter` allowlist, in the slice that adds it.** The rule, stated once so no reviewer has to re-derive it:

> A new Feature class whose manifest group is PARKED executes on **no event** in CI. This lane's five groups are all parked. Therefore every class this lane adds is either allowlisted in `backend-test-pgsql` or it is an unarmed tripwire. The lane ships no unarmed tripwires.

That is exactly the precedent the repo already set three times, cited rather than asserted:

- **T-1, 2026-09-09** — `.github/workflows/ci.yml:1132-1140` comment block, `:1142` filter entries `StockTransferEdgeCasesTest|StockTransferCompleteConcurrencyPostgresTest|ReplenishmentEdgeCasesTest`; manifest note: "All three T-1 classes are explicitly selected in backend-test-pgsql."
- **PR #221 / QA-BUG-09, 2026-09-08** — `CountingMovementReferenceTest` at `.github/workflows/ci.yml:1142`, comment at `:1122-1131`; manifest note (`groups.Inventory.raise_note_2026_09_08_qa_bug_09`, quoted in full for the sentence this plan relies on): "The class IS named in the backend-test-pgsql --filter allowlist in .github/workflows/ci.yml (same precedent as CountingIndexStatusFilterTest and StockTransferIdempotencyCollisionPostgresTest), so it has a live CI gate on real PostgreSQL while this lane stays parked."
- **PR #218, 2026-09-07** — `CountingIndexStatusFilterTest`, same shape.

Mechanics: **one edit per slice**, appending that slice's class names to the single alternation at `.github/workflows/ci.yml:1142`, immediately before the closing `)::/`, and adding a comment block above `:1141` in the shape of `:1132-1140`. No slice adds a second `php artisan test` invocation and no slice reorders existing entries.

| Slice | Appended to the `:1142` alternation | PG-only by construction (the subset that would otherwise run nowhere at all) |
|---|---|---|
| S1 | `StockTransferReceiveTest`, `StockTransferReceiveDamageTest`, `StockTransferReceiveLotsTest`, `StockTransferReceiveValidationTest`, `StockTransferCloseTest`, `StockTransferReceiveConcurrencyPostgresTest`, `TransferReceiptEventStreamTest`, `TransferReceiptGlBoundaryTest`, `TransferReceiptPatternQueryPostgresTest`, `TransferLegacyCompletionBackfillTest`, `TransferReceiptSecondOfEverythingTest`, `TransferReceiptAuthorityGateTest`, `TransferCloseReplenishmentSettlementTest`, `TransferReceiptSchemaRerunPostgresTest` | `StockTransferReceiveConcurrencyPostgresTest` (advisory-lock/row-lock races), `TransferReceiptPatternQueryPostgresTest` (`jsonb` operators, `FILTER (WHERE …)`, `bool_or`), `TransferReceiptSchemaRerunPostgresTest` (`pg_constraint` / `pg_indexes` / `information_schema` catalog dumps), plus `TransferLegacyCompletionBackfillTest`'s single forced-failure case (mark-skipped on SQLite) |
| S2 | `TransferReceiverPayloadBuilderKeyScanTest`, `TransferAuthorityMatrixTest`, `ReceivingControlsVisibilityVersionTest`, `ReceivingControlsConcurrencyPostgresTest`, `ReceivingControlsSecondOfEverythingTest` | `ReceivingControlsConcurrencyPostgresTest` (two committed writers on one row lock) |
| S3 | `TransferBlindLeakOracleTest`, `TransferMovementMaskingTest`, `EntryExitNoteLocationScopeTest` | none — all three are asserted on **both** lanes by design (§9.1); the allowlist entry exists because the group is parked, not because the tests need PG |
| S4 | `TransferNotificationTest`, `TransferNotificationRaceTest` | `TransferNotificationRaceTest` (two OS processes against committed fixtures + `insertOrIgnore` / `ON CONFLICT DO NOTHING`, §10.10) |

**Launch relevance, stated per class group** (the second half of the allowlist decision the gate asked for): S1's classes guard the first GL postings this document type has ever produced, a backfill that runs on **every real tenant** at the S1 deploy, and a migration set that alters two live tables — all of that reaches staging on push 1, so none of it may be gated only by a local run. S2's classes guard a permission gate and a payload-shape switch that also reach staging on push 2. S3's classes guard an authorization narrowing (`GET /entry-exit-notes` location scope) that is **not** setting-gated and therefore changes behaviour for real users the moment push 3 deploys. S4's classes guard a queued writer whose failure mode is silent (a notification row that never appears). There is no class in this lane whose failure would be caught by anything else.

#### 6.7.6 Verification, ledger and contention

- Every slice adds `cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && php tools/feature-lane-manifest-check.php` to its verification block (§13) and must see it exit `0`. A slice that raises a ceiling without adding the class, or adds the class without the raise, fails this command locally before it can be dispatched for review.
- Both files appear in **all four** pushes. That breaks the "every file appears exactly once" rule by construction, so both are entered in the cross-push contention register (§11.6) with what each push adds, exactly as the seven pre-existing multi-push files are.
- Neither file is a generated artifact; both are hand-edited source and are reviewed as such by `tenancy-authz-reviewer` (the CI gate) in every slice.

### 6.7b The shared movement collaborator `StockTransferMovementSupport` (closes gate r1 MAJOR M3)

> **§6.7b AMENDMENT (rev 8, ruled 2026-09-10 — supersedes the "verbatim move" of `markMovementAsTransfer` below).** The S1 implementer proved on the untouched tree that moving `markMovementAsTransfer` verbatim into `StockTransferMovementSupport` trips the protected document-per-action ratchet: the scanner treats EVERY `stock_movements` update as a violation (`apps/api/tests/Architecture/Support/DocumentPerActionWriteScanner.php:110-123`), the existing entry `StockTransferService::markMovementAsTransfer::stock_movements::update#1` is a grandfathered violation, and the anti-growth test permits removals only (`apps/api/tests/Architecture/DocumentPerActionBaselineRatchetTest.php:110-172`). The new receipt writer would have tripped the same guard. Ruling: **the post-creation rewrite is eliminated, not relocated.**
>
> 1. `markMovementAsTransfer()` is **deleted**, not moved; its baseline entry is **removed** from the working DPA baseline (a shrink, which the ratchet permits; the owner-pinned protected blob is NOT re-pinned and the scanner is NOT weakened or given an allowlist).
> 2. `StockAdjustmentService::receive()` and `::issue()` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115-131`, `:253-270`) gain the ability to create a movement with its **final** semantic type and transfer linkage at creation: an optional, backward-compatible parameter (e.g. `?MovementType $movementType = null`, accepted only as `TransferIn` on `receive()` / `TransferOut` on `issue()`, any other value → `InvalidArgumentException`) plus the existing `referenceType`/`referenceId` pair or, if that enum cannot express it, an explicit transfer-linkage parameter. **The stored row must be byte-identical to what the rewrite produced today**: `movement_type` = `transfer_in` / `transfer_out`, `reference_type` = the exact value `markMovementAsTransfer` wrote (`StockTransfer::class`, `StockTransferService.php:749`), `reference_id` = the transfer id. Every existing caller of `receive()`/`issue()` is unaffected (defaults), and `StockAdjustmentService::transfer()` (`:383`, the single-shot two-location writer) is untouched.
> 3. `StockTransferService` (initiate/cancel paths) and the new `StockTransferReceiptService` call the writers with the final type; the six former `markMovementAsTransfer(...)` call sites (`StockTransferService.php:369,382,465,478,582,595`) disappear. `StockTransferMovementSupport` therefore carries `lockTransfer()`, `capitalizeTransferCost()`, `computeAllocationWeights()` and `restockAtSource()` only.
> 4. Announced events: `StockMovementRecorded` / `StockMovementRecordedV2` for transfer-typed rows carry the REAL label (`transfer_in` / `transfer_out`, as the `transfer()` path already does at `:486-539`) instead of the hardcoded `receipt` / `issue` — this closes T-1 seam fact 5. Before relying on it the implementer runs a **consumer census** of every listener/subscriber of both events (`ChannelServiceProvider.php`, `WeightedAverageCostService.php`, `OpeningBalancePostingService.php`, `ResetOpeningBalanceService.php`, `ReturnScrapWriteOffService.php`, `StockAdjustmentService.php` — re-grep at `DISPATCH_SHA`) and states per consumer whether it branches on the label; any consumer that does gets a named regression test pinning its output before and after. Rule 8 is respected: no event class changes shape.
> 5. Scope: `StockAdjustmentService.php` and the working DPA baseline file join S1's **modified** files (§7.2 and the Push-3 ledger row for S1); the T-1 classes `StockTransferEdgeCasesTest`, `StockTransferCompleteConcurrencyPostgresTest` (exclusive DB) and `StockTransferIdempotencyCollisionPostgresTest` are re-run green as the regression gate for this change, plus `DocumentPerActionBaselineRatchetTest` (both directions) and the SQLite `DocumentPerActionWriteGuardTest`. Red-first: a new test asserting a transfer-initiation movement row is created with `movement_type = transfer_out` and the transfer linkage **without any subsequent update** (assert `updated_at === created_at` or the scanner's absence) is the red before the change.
> 6. `stock-gl-interaction-reviewer` explicitly cites this amendment at the S1 gate (§14): no second writer, no label drift for non-transfer rows, WAC unchanged, GL unchanged, ratchet shrink only.
>
> Everything below that describes moving `markMovementAsTransfer` is read as "delete it per the amendment".


**The defect.** Rev 1 said `StockTransferReceiptService` "reuses `StockTransferService::lockTransfer()` and `markMovementAsTransfer()` unchanged". Both are `private` — `lockTransfer()` at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:734`, `markMovementAsTransfer()` at `:745`, `capitalizeTransferCost()` at `:631`, `computeAllocationWeights()` at `:714`, `lineProductIds()` at `:1056`. A separate class cannot call any of them. Rev 1 also asked for an extracted `restockAtSource()` without naming its owner.

**The resolution.** S1 extracts one package-visible collaborator and both services depend on it. This is the **extraction** option, not a visibility change: making five methods `public` on `StockTransferService` would give the receipt service a dependency on the initiate/cancel writer and would let any caller reach the freight allocator, which convention 11 forbids (one surface per concept). The collaborator is a leaf — it depends on `StockAdjustmentService` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115`, `:253`) and `WeightedAverageCostService` (same namespace, so no `use` line) and on nothing else. It deliberately does **not** take `ProductCostLock` (gate r2 MAJOR M1): the advisory lock is acquired once per write by the composite root — `StockTransferService::complete()` and `StockTransferReceiptService::receive()/close()` — and re-acquiring it inside the collaborator would put the lock's owner in two places, which convention 11 forbids and which the "Transaction and lock ownership" block below fixes by naming exactly one owner.

**Owning module path:** `apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php`, namespace `App\Modules\Inventory\Application\Services`. Same module, same layer as both callers (CLAUDE.md rule 6 is not engaged: nothing crosses a module boundary). Laravel auto-resolution is the binding; **no provider entry is added**, because every constructor argument is itself auto-resolvable, exactly as `StockTransferService` is today.

**Complete class signature** — bodies of the five moved methods are the current bodies at the cited lines, moved verbatim except where a note says otherwise:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Inventory\Domain\Enums\MovementType;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockMovement;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use App\Modules\Product\Domain\Product;
use App\Shared\Domain\CurrencyScale;
use App\Shared\Domain\QuantityScale;

/**
 * The transfer-movement seam shared by StockTransferService (initiate/cancel)
 * and StockTransferReceiptService (receive/close).
 *
 * Every method here was `private` on StockTransferService before T-2 and is
 * MOVED, not copied: the private originals are deleted in the same commit, so
 * there is exactly one implementation of each (convention 11).
 *
 * This class writes stock movements and capitalises freight. It NEVER opens a
 * transaction of its own, NEVER acquires an advisory lock and NEVER touches the
 * GL: the caller is the composite root and owns the transaction, the header row
 * lock, the ProductCostLock acquisition and InventoryGlPostingBuffer (§7.8).
 */
final class StockTransferMovementSupport
{
    /** Intermediate scale for transfer-cost allocation arithmetic; moved from StockTransferService:70. */
    public const int ALLOCATION_SCALE = 6;

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly WeightedAverageCostService $wacService,
    ) {
    }

    /**
     * Header row lock with lines and allocations eager-loaded.
     * Moved verbatim from StockTransferService::lockTransfer() (:734-743).
     */
    public function lockTransfer(string $transferId): StockTransfer
    {
        /** @var StockTransfer $transfer */
        $transfer = StockTransfer::query()
            ->with('lines.batchAllocations')
            ->lockForUpdate()
            ->findOrFail($transferId);

        return $transfer;
    }

    /**
     * Rewrite the row StockAdjustmentService just created into a transfer movement.
     * Moved verbatim from StockTransferService::markMovementAsTransfer() (:745-752).
     */
    public function markMovementAsTransfer(StockMovement $movement, MovementType $type, string $transferId): void
    {
        $movement->update([
            'movement_type' => $type->value,
            'reference_type' => StockTransfer::class,
            'reference_id' => $transferId,
        ]);
    }

    /**
     * Distinct product ids of a transfer's lines, for the ONE sorted advisory-lock
     * acquisition both writers take up front.
     * Moved verbatim from StockTransferService::lineProductIds() (:1056-1064).
     *
     * @return list<string>
     */
    public function lineProductIds(StockTransfer $transfer): array
    {
        $ids = [];
        foreach ($transfer->lines as $line) {
            $ids[(string) $line->product_id] = true;
        }

        return array_keys($ids);
    }

    /**
     * Per-line allocation weights for the chosen distribution.
     * Moved from StockTransferService::computeAllocationWeights() (:714-732) with ONE
     * change, stated in §7.11: the weight source becomes the LANDED quantity supplied
     * by the caller instead of `$line->quantity`, so a partially-received transfer
     * allocates freight on what arrived.
     *
     * @param  array<string, numeric-string>  $landedQuantityByLineId  quantity_received per line id
     * @return array<string, numeric-string>
     */
    public function computeAllocationWeights(StockTransfer $transfer, array $landedQuantityByLineId): array
    {
        $working = self::ALLOCATION_SCALE;

        $weights = [];
        foreach ($transfer->lines as $line) {
            /** @var numeric-string $qty */
            $qty = $landedQuantityByLineId[(string) $line->id] ?? '0';
            /** @var numeric-string $cost */
            $cost = (string) ($line->unit_cost_snapshot ?? '0');

            $weights[$line->id] = match ($transfer->transfer_cost_distribution) {
                TransferCostDistribution::ProRataValue => bcmul($qty, $cost, $working),
                TransferCostDistribution::ProRataQuantity => CurrencyScale::bcformat($qty, $working),
                TransferCostDistribution::EqualPerLine => bccomp($qty, '0', $working) > 0 ? '1' : '0',
            };
        }

        return $weights;
    }

    /**
     * Allocate `$pool` across the lines on landed weights and capitalise each share
     * into the company-wide WAC of the line's product. The LAST cost-bearing line
     * absorbs the residual so the allocations sum to `$pool` EXACTLY.
     * Moved from StockTransferService::capitalizeTransferCost() (:631-712); the only
     * changes are the added `$landedQuantityByLineId` argument it forwards to
     * computeAllocationWeights(), and that its money argument is the SHRUNK pool of
     * §7.11 step 3 rather than the raw `transfer_cost`.
     *
     * @param  numeric-string  $pool
     * @param  array<string, numeric-string>  $landedQuantityByLineId
     */
    public function capitalizeTransferCost(StockTransfer $transfer, string $pool, array $landedQuantityByLineId): void
    {
        $working = self::ALLOCATION_SCALE;

        $weights = $this->computeAllocationWeights($transfer, $landedQuantityByLineId);
        /** @var numeric-string $totalWeight */
        $totalWeight = '0';
        foreach ($weights as $weight) {
            $totalWeight = bcadd($totalWeight, $weight, $working);
        }

        $lineCount = max(1, $transfer->lines->count());

        // First pass: compute each line's allocation, skipping genuinely-zero
        // shares. The LAST cost-bearing line absorbs the residual
        // (pool − Σ others) so the allocations sum to pool EXACTLY, with no
        // millième lost or gained to independent rounding.
        /** @var list<array{line: StockTransferLine, product: Product, allocated: numeric-string}> $allocations */
        $allocations = [];
        foreach ($transfer->lines as $line) {
            $product = Product::query()
                ->where('tenant_id', $transfer->tenant_id)
                ->where('company_id', $transfer->company_id)
                ->findOrFail($line->product_id);

            if (bccomp($totalWeight, '0', $working) > 0) {
                $allocated = bcmul($pool, bcdiv($weights[$line->id], $totalWeight, $working), $working);
            } else {
                $allocated = bcdiv($pool, (string) $lineCount, $working);
            }

            if (bccomp($allocated, '0', $working) <= 0) {
                continue;
            }

            $allocations[] = ['line' => $line, 'product' => $product, 'allocated' => $allocated];
        }

        $lastIndex = count($allocations) - 1;

        // Reconcile at the PERSISTED scale (4), NOT the 6-dp working scale.
        // allocated_transfer_cost is stored at 4 dp and recordCostAdjustment
        // capitalizes the SAME value; reconciling the residual at 6 dp and then
        // truncating to 4 dp on persist loses a millième per line (e.g. 7 equal
        // lines of 100 -> 14.2857 x 7 = 99.9999 < 100). Every line EXCEPT the last
        // is formatted to 4 dp; the last absorbs (pool − Σ others) at 4 dp so
        // Σ persisted == pool EXACTLY at the stored scale.
        /** @var numeric-string $pool4dp */
        $pool4dp = CurrencyScale::bcformat($pool, QuantityScale::SCALE);
        /** @var numeric-string $runningSumOfOthers4dp */
        $runningSumOfOthers4dp = '0';

        foreach ($allocations as $index => $allocation) {
            $line = $allocation['line'];
            $product = $allocation['product'];

            $allocated4dp = $index === $lastIndex
                ? bcsub($pool4dp, $runningSumOfOthers4dp, QuantityScale::SCALE)
                : CurrencyScale::bcformat($allocation['allocated'], QuantityScale::SCALE);

            $runningSumOfOthers4dp = bcadd($runningSumOfOthers4dp, $allocated4dp, QuantityScale::SCALE);

            $line->allocated_transfer_cost = $allocated4dp;
            $line->save();

            $this->wacService->recordCostAdjustment(
                product: $product,
                // Pass the same 4-dp numeric-string persisted to the transfer
                // line so stored sum and capitalized sum both equal pool.
                additionalCost: $allocated4dp,
                reason: 'stock_transfer_cost',
                tenantId: $transfer->tenant_id,
                companyId: $transfer->company_id,
                reference: $transfer->transfer_number,
                referenceType: StockTransfer::class,
                referenceId: $transfer->id,
            );
        }
    }

    /**
     * Return `$quantity` of `$line` to the transfer's SOURCE location as TransferIn
     * movements, lot-aware. Extracted from the cancel restock loop at
     * StockTransferService.php:451-480 and called by BOTH `cancel()` and the
     * `return_to_source` close disposition (seam 7).
     *
     * The caller has already acquired every line product's advisory lock in ONE
     * sorted call, and is inside its own transaction.
     *
     * @param  numeric-string  $quantity  the line total to restock
     * @param  array<int, numeric-string>  $lotQuantities  batch_id => quantity; MUST be empty
     *                                                     for a lot-less line and MUST sum to
     *                                                     $quantity for a lot-tracked one
     * @return array<int|string, string>  created movement ids, keyed by batch_id for a
     *                                    lot-tracked line and by the single key '' for a
     *                                    lot-less one
     */
    public function restockAtSource(
        StockTransfer $transfer,
        StockTransferLine $line,
        string $quantity,
        array $lotQuantities,
        string $userId,
        string $reference,
    ): array {
        $movementIds = [];

        if ($lotQuantities !== []) {
            foreach ($lotQuantities as $batchId => $lotQuantity) {
                $movement = $this->stockAdjustmentService->receive(
                    productId: $line->product_id,
                    locationId: $transfer->source_location_id,
                    quantity: $lotQuantity,
                    reference: $reference,
                    userId: $userId,
                    batchId: $batchId,
                    expectedCompanyId: $transfer->company_id,
                    variantId: $line->variant_id,
                );

                $this->markMovementAsTransfer($movement, MovementType::TransferIn, $transfer->id);
                $movementIds[$batchId] = (string) $movement->id;
            }

            return $movementIds;
        }

        $movement = $this->stockAdjustmentService->receive(
            productId: $line->product_id,
            locationId: $transfer->source_location_id,
            quantity: $quantity,
            reference: $reference,
            userId: $userId,
            expectedCompanyId: $transfer->company_id,
            variantId: $line->variant_id,
        );

        $this->markMovementAsTransfer($movement, MovementType::TransferIn, $transfer->id);
        $movementIds[''] = (string) $movement->id;

        return $movementIds;
    }
}
```

**Three deliberate, stated differences from the moved originals** — everything else in the bodies above is the shipped code at the cited lines:

1. `computeAllocationWeights()` takes `$landedQuantityByLineId` and weights on the landed quantity instead of `$line->quantity`, and its `EqualPerLine` arm returns `'1'` only for a line with a positive landed quantity (§7.11 step 5). That is the T-2 freight rule; the pre-T-2 body weighted on the shipped quantity, which would allocate freight to units that never arrived.
2. `capitalizeTransferCost()`'s money argument is named `$pool` and is the **shrunk** pool of §7.11 step 3, not the raw `transfer_cost`. Every other line — the `totalWeight` accumulation, the product lookup, the zero-share skip, the 4-dp reconciliation and the last-line residual absorption, and the `recordCostAdjustment` call — is byte-identical to `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:633-712`.
3. The persisted scale is written `QuantityScale::SCALE` (`apps/api/app/Shared/Domain/QuantityScale.php:20`, value `4`) rather than the private `StockTransferService::QTY_SCALE` (`:63`, also `4`), because the private constant stays on `StockTransferService` and the collaborator must not depend on it. The value is unchanged, so the worked example of §7.11 (`49.9999`, `40.0001`, `30.0000`) is unaffected.

**Every caller, and every file the extraction modifies.** Nothing else in the repository calls any of the five moved methods; the census of §6.5 is the proof, and the S1 implementer re-runs `grep -rn 'lockTransfer\|markMovementAsTransfer\|capitalizeTransferCost\|computeAllocationWeights\|lineProductIds' apps/api/app apps/api/tests` at `DISPATCH_SHA` and records the output in the handback before deleting anything.

| File | Change |
|---|---|
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php` | **NEW** (the class above) |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php` | Constructor (`:72-77`) gains **two** properties, `private readonly StockTransferMovementSupport $movementSupport` and `private readonly StockTransferReceiptService $receiptService` (gate r2 M1: rev 2 named only the first while ordering a delegation that needs the second). `complete()` (`:317`) becomes the three-line forwarder reproduced above — no transaction, no row lock, no advisory lock — and `completeLocked()` (`:346`) is **deleted**. `initiate()` and `cancel()` keep their bodies but call `$this->movementSupport->lockTransfer(...)`, `->lineProductIds(...)`, `->markMovementAsTransfer(...)`; the cancel restock loop at `:451-480` is replaced by a `foreach ($transfer->lines as $line)` that calls `$this->movementSupport->restockAtSource($transfer, $line, (string) $line->quantity, $lotQuantities, $userId, $transfer->transfer_number.'-CANCEL')`, where `$lotQuantities` is built from `$line->batchAllocations` as `batch_id => (string) $allocation->quantity`. The five private methods at `:631`, `:714`, `:734`, `:745`, `:1056` are **deleted**. `ALLOCATION_SCALE` at `:70` is deleted and its two readers point at `StockTransferMovementSupport::ALLOCATION_SCALE`; `QTY_SCALE` at `:63` stays where it is |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php` | **NEW** in S1; its final nine-argument constructor is reproduced below; it calls `lockTransfer()`, `lineProductIds()`, `markMovementAsTransfer()`, `restockAtSource()` and `capitalizeTransferCost()` on the collaborator (§7.5, §7.8, §7.11) and owns the transaction, the header lock and the advisory lock |
| `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` | Constructor (`:28-32`) gains `StockTransferReceiptService`, `TransferPayloadBuilder` and `TransferReconciliationService`; the new private `canAccessLocationOrFail()` is added beside `canSeeTransfer()` (`:347`) and `locationAccessDeniedResponse()` (`:359`); the controller is the sole owner of response construction |
| `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php` | **Not modified.** It drives `StockTransferService::complete()` through the container (parent call at `:95`, child call at `:104`; gate r3's citation audit flagged rev 3's `:94` / `:105-107` as approximate and both were re-derived at the rev-4 pin) and asserts on rows, never on the private helpers, so the extraction is invisible to it. S1 re-runs it as a regression (§13) and the handback records the result |

**Final constructors of all three classes in the writer graph (closes gate r2 MAJOR M1).** Rev 2 gave a constructor for `StockTransferReceiptService` that its own specified body could not resolve — `$this->costLock` was called at §7.5 rule 5 with no `ProductCostLock` property, receipt-number generation named `DocumentNumberingService::generateForKey(...)` with no such property, and `StockTransferService` was told to delegate `complete()` to a service it did not inject. All three constructors are therefore reproduced here **in final form**, and nothing below is left to inference.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Document\Domain\Services\DocumentNumberingService;
use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Services\ProductCostLock;
use App\Modules\Inventory\Domain\Services\StockAdjustmentService;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Shared\Contracts\CurrencyScaleResolverInterface;
use Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository;

/**
 * The ONE receipt writer (convention 11). Owns the root transaction, the header
 * row lock and the up-front sorted advisory-lock acquisition for receive, close
 * and the /complete delegate. Returns entities; the controller maps them onto
 * the wire DTOs of §7.12.
 */
final class StockTransferReceiptService
{
    /** The document_sequences key and prefix for transfer receipt numbers (TRR-2026-0001). */
    private const SEQUENCE_KEY = 'stock_transfer_receipt';

    private const SEQUENCE_PREFIX = 'TRR';

    public function __construct(
        private readonly StockTransferMovementSupport $movementSupport,
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly InventoryGlPostingBuffer $glBuffer,
        private readonly InventoryValuationModeResolver $valuationMode,
        private readonly ReceiptPayloadCanonicalizer $canonicalizer,
        private readonly StoredEventRepository $storedEvents,
        private readonly CurrencyScaleResolverInterface $scaleResolver,
        private readonly ProductCostLock $costLock,
        private readonly DocumentNumberingService $numberingService,
    ) {
    }

    /** @param array{idempotency_key: string, notes?: string|null, lines: list<array<string, mixed>>} $payload */
    public function receive(string $transferId, string $userId, array $payload): TransferReceiptResult { /* §7.5 */ }

    /** @param array{idempotency_key: string, disposition: string, reason: string, note?: string|null} $payload */
    public function close(string $transferId, string $userId, array $payload): TransferReceiptResult { /* §7.5 */ }

    /**
     * The `/complete` delegate: receives every open remainder with no receiver input.
     *
     * It takes the transfer ID, never a loaded model, so that it owns its own
     * transaction, header row lock and advisory lock exactly as receive() and
     * close() do. Its ONLY caller is StockTransferService::complete().
     */
    public function receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult { /* §7.5 rule 9 */ }
}
```

**The `StockTransfer` import above is still required** by `receive()`, `close()` and `receiveAllRemaining()` internally — each calls `$this->movementSupport->lockTransfer($transferId): StockTransfer` and passes the resulting model to `lineProductIds()`, `restockAtSource()` and `capitalizeTransferCost()` — so no `use` line is dropped when the parameter type changes.

Two of the nine dependencies are new in rev 3 and each has an in-module precedent, cited rather than asserted:

- `ProductCostLock` — namespace `App\Modules\Inventory\Domain\Services` (`apps/api/app/Modules/Inventory/Domain/Services/ProductCostLock.php:5`), signature `acquire(string $tenantId, string $companyId, array $productIds, Closure $callback): mixed` (`:40`), already injected by `StockTransferService` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:75`). Rev 2's §6.7b import block wrote `App\Shared\Domain\Locks\ProductCostLock`, which does not exist; the import above is the real namespace.
- `DocumentNumberingService` — namespace `App\Modules\Document\Domain\Services`, signature `generateForKey(string $tenantId, string $companyId, string $sequenceType, string $prefix): string` (`apps/api/app/Modules/Document/Domain/Services/DocumentNumberingService.php:29`), producing `PREFIX-YYYY-NNNN` under a `FOR UPDATE` row lock on `document_sequences` with one unique-violation retry (`:42-70`). Inventory already constructor-injects it from an Application service twice — `apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:69` (call at `:207`) and `apps/api/app/Modules/Inventory/Application/Services/SupplierGoodsReturnNoteService.php:244` (call at `:456`) — and both call it **inside** the `ProductCostLock::acquire` closure, which is exactly the position §7.5 rule 7 puts it in. CLAUDE.md rule 6 is satisfied: `DocumentNumberingService` is a module's public Service class, not a model.

`StockTransferService` after S1 — the constructor gains **two** properties, not one:

```php
class StockTransferService
{
    private const QTY_SCALE = 4;

    public function __construct(
        private readonly StockAdjustmentService $stockAdjustmentService,
        private readonly WeightedAverageCostService $wacService,
        private readonly ProductCostLock $costLock,
        private readonly ProductVariantLookup $variantLookup,
        private readonly StockTransferMovementSupport $movementSupport,
        private readonly StockTransferReceiptService $receiptService,
    ) {}
```

The first four are today's list verbatim (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:72-77`); `ALLOCATION_SCALE` at `:65-70` is deleted and `QTY_SCALE` at `:63` stays. `$movementSupport` carries `initiate()` and `cancel()`; `$receiptService` carries `complete()`. There is **no** cycle: `StockTransferReceiptService` does not depend on `StockTransferService`, and Laravel auto-resolution binds both without a provider entry.

`StockTransferController` after S1 — the constructor gains **three** properties:

```php
class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $service,
        private readonly CompanyContext $companyContext,
        private readonly LocationContext $locationContext,
        private readonly StockTransferReceiptService $receiptService,
        private readonly TransferPayloadBuilder $payloadBuilder,
        private readonly TransferReconciliationService $reconciliationService,
    ) {}
```

The first three are today's list verbatim (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:28-32`). **After S2 there is a seventh**, `private readonly ExpectedQuantityVisibility $expectedQuantityVisibility` — the concrete class rather than the Shared `TransferIncomingVisibility` interface, because `canSeeExpected(User, StockTransfer)` is deliberately not on that interface (§6.1, §8.5). S2 adds no other constructor argument to this class, and no slice ever injects `StockTransferReceiptService` for the sake of `/complete`: the controller's `$receiptService` exists only for `receive()` and `close()`.

**`complete()` after S1 — the exact post-extraction body, so the transaction, the row lock and the advisory lock have one owner each.** Today `complete(string $transferId, string $userId): StockTransfer` opens `DB::transaction(attempts: 3)`, calls the private `lockTransfer()`, checks `canBeCompleted()`, computes `lineProductIds()` and wraps `completeLocked()` in `$this->costLock->acquire(...)` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:317-339`). Rev 2 left it ambiguous whether the delegate re-opened any of that. It does not:

```php
    public function complete(string $transferId, string $userId): StockTransfer
    {
        return $this->receiptService
            ->receiveAllRemaining($transferId, $userId, 'sys:complete:'.$transferId)
            ->transfer;
    }
```

`complete()` becomes a **three-line forwarder**: it opens no transaction, takes no lock, loads nothing and keeps its published signature and return type, so `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:226` and `StockTransferCompleteConcurrencyPostgresTest` are unchanged. `completeLocked()` (`:346-383`) is deleted. Consequently `receiveAllRemaining` takes a **transfer id**, not a loaded `StockTransfer` — rev 2's signature took an already-loaded entity and then could not say who had locked it. Its final signature is `receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult`, identical in ownership to `receive()` and `close()`.

**The ONE `/complete` call graph, reproduced end to end (closes gate r3 BLOCKER B1).** Gate r2's M1 was declared closed by rev 3, but rev 3 left the reproduced `StockTransferReceiptService` class declaring the *entity* signature while its own forwarder, §7.5 rule 9 and the call-graph table used the *string* signature, and rev 3's §8.6 then told S2 to call the receipt service directly with a loaded model. Those are three mutually exclusive instructions for one core endpoint. **This block is the single decision.** Two rules, then the three final bodies:

1. **One signature.** `StockTransferReceiptService::receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult`. Nowhere in this plan does that method take a `StockTransfer`.
2. **One caller.** `StockTransferService::complete()` is the **only** caller of `receiveAllRemaining`, in S1, in S2, in S3 and in S4. `StockTransferController` never calls it — not in `complete`, not anywhere. The controller's two direct uses of `$this->receiptService` are `receive()` and `close()` and nothing else. The proof an implementer must record in the handback is one grep, whose only two hits are the declaration and the forwarder:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
grep -rn 'receiveAllRemaining' app tests
```

**(a) `StockTransferService::complete()` after S1 — the published forwarder, unchanged signature, unchanged return type, unchanged HTTP status:**

```php
    public function complete(string $transferId, string $userId): StockTransfer
    {
        return $this->receiptService
            ->receiveAllRemaining($transferId, $userId, 'sys:complete:'.$transferId)
            ->transfer;
    }
```

**(b) `StockTransferReceiptService::receiveAllRemaining()` after S1 — the one receipt writer's quantity-less delegate, with the same lock spine as `receive()` and `close()` (§7.5 rules 1–8, rule 9 for the remainder derivation):**

```php
    /**
     * The `/complete` delegate: receive every open remainder on every line and
     * every lot, with quantity_damaged = 0 and no discrepancy reason.
     *
     * Called ONLY by StockTransferService::complete(). The idempotency key is the
     * server key `sys:complete:{transferId}` of spec §5.6, which rule A6 forbids a
     * client from supplying, so a client can never collide with this receipt.
     */
    public function receiveAllRemaining(
        string $transferId,
        string $userId,
        string $idempotencyKey,
    ): TransferReceiptResult {
        return DB::transaction(function () use ($transferId, $userId, $idempotencyKey): TransferReceiptResult {
            $transfer = $this->movementSupport->lockTransfer($transferId);

            $payload = $this->remainderPayload($transfer, $idempotencyKey);

            return $this->costLock->acquire(
                $transfer->tenant_id,
                $transfer->company_id,
                $this->movementSupport->lineProductIds($transfer),
                fn (): TransferReceiptResult => $this->postReceipt($transfer, $userId, $payload, TransferReceiptKind::Receipt),
            );
        }, attempts: 3);
    }
```

`remainderPayload()` and `postReceipt()` are the two private members `receive()` and `close()` already share (§7.5 rules 3–8); `remainderPayload()` builds the canonical line/lot array from `quantity_remaining` with `quantity_damaged = '0.0000'` and no `discrepancy_reason`, which is why rule 4's `requirePerpetual` preflight can never fire on this path.

**(c) `StockTransferController::complete()` after S2 — the one controller action, with S1's shape and S2's blind gate, in evaluation order:**

```php
    public function complete(Request $request, string $transfer): JsonResponse
    {
        $company = $this->companyContext->requireCompany();
        /** @var User $user */
        $user = $request->user();

        if (! Str::isUuid($transfer)) {
            abort(404);
        }

        $existing = StockTransfer::query()
            ->where('tenant_id', $company->tenant_id)
            ->where('company_id', $company->id)
            ->findOrFail($transfer);

        // Completing = receiving into the DESTINATION, so require destination access.
        if (($denied = $this->canAccessLocationOrFail($existing->destination_location_id, $company->id, $user)) !== null) {
            return $denied;
        }

        // S2 (OD-1): a blind actor may never post a quantity-less full receipt.
        // Evaluated BEFORE the service is entered, so a refused actor causes zero
        // writes and can never reach a stored receipt through the replay path.
        if (! $this->expectedQuantityVisibility->canSeeExpected($user, $existing)) {
            return response()->json([
                'error' => [
                    'code' => 'BLIND_REQUIRES_COUNTED_RECEIPT',
                    'message' => __('inventory.transfers.blind_requires_counted_receipt'),
                    'details' => [],
                ],
            ], 422);
        }

        try {
            $completed = $this->service->complete($existing->id, $user->id);
        } catch (TransferStateException $e) {
            return $this->stateExceptionResponse($e);
        }

        $completed->load(['sourceLocation', 'destinationLocation', 'completedBy', 'lines.product.unitOfMeasure', 'lines.variant', 'lines.batchAllocations.batch']);

        return response()->json([
            'data' => $this->payloadBuilder->build($completed, $user, includeLines: true),
        ]);
    }
```

Everything except the two inserted blocks and the response builder is byte-identical to today's action (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:205-236`). The three deltas, each with its owning slice:

| Delta | Slice | What it replaces |
|---|---|---|
| `canAccessLocationOrFail(...)` instead of the inline `canAccessLocation` + `locationAccessDeniedResponse` pair at `:221-223` | S1 | the same two calls, hoisted into the private helper below so `receive`, `close` and `complete` share one implementation |
| `$this->payloadBuilder->build(...)` instead of `$this->formatTransfer(...)` at `:234` | S1 | `formatTransfer` is deleted by S1; the builder returns `StockTransferData` (§7.12b) |
| the `canSeeExpected` gate and its 422 | S2 | nothing — it is new, and it is the ONLY line S2 adds to this action |

**No status change**: `response()->json([...])` with no status argument keeps `/complete` at **200**, which is what spec §5.6 and the REALIGNMENT-LOG entry of §11.4 state. Only `receive` and `close` return 201.

**Transaction and lock ownership — one owner per resource, stated once.**

| Resource | Owner | Where it is taken | Where it is released |
|---|---|---|---|
| Root DB transaction | `StockTransferReceiptService::receive` / `::close` / `::receiveAllRemaining` | `DB::transaction(function () { … }, attempts: 3)` as the FIRST statement of each of the three methods | commit at the end of that closure; `DB::afterCommit` callbacks fire after it |
| `stock_transfers` header row lock | the same three methods, via `$this->movementSupport->lockTransfer($transferId)` | first statement **inside** the transaction closure, before the idempotency lookup (§7.5 rule 1) | transaction end |
| Product advisory locks | the same three methods, via `$this->costLock->acquire($transfer->tenant_id, $transfer->company_id, $this->movementSupport->lineProductIds($transfer), $callback)` | immediately after the header lock and the idempotency discriminator, wrapping **all** of Layer B, the movements, the counters, the receipt rows, the status write, freight and the stored events | transaction end (PG transaction-scoped advisory locks) |
| `document_sequences` row lock | `DocumentNumberingService::generateForKey` | inside the advisory-lock closure, at receipt-row creation time (§7.5 rule 7) | its own nested `DB::transaction`, which Laravel realises as a savepoint because a transaction is already open |
| `InventoryGlPostingBuffer` | `StockTransferReceiptService` as the composite root | `mark()` immediately inside the advisory-lock closure | `flushIfOutermost()` at the end of the closure, `rollbackTo($marker)` on any earlier failure |
| `StockTransferService::complete` | **nothing** | — | — |

Because `complete()` no longer opens a transaction, `StockTransferReceiptService` is the outermost transaction on every path, which is the precondition `InventoryGlPostingBuffer` states at `apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:60` and `:66` and which T13 asserts.

**Call graph — every method `StockTransferReceiptService` calls, on which collaborator, with the exact signature, in order.** The three writer entry points share one ordered spine: **lock → validate → movements → GL buffer → events → afterCommit.**

| # | Caller method | Collaborator | Exact signature called | Phase |
|---|---|---|---|---|
| 1 | `receive` / `close` / `receiveAllRemaining` | `Illuminate\Support\Facades\DB` | `transaction(Closure $callback, int $attempts = 1): mixed` — called as `attempts: 3` | lock |
| 2 | same | `StockTransferMovementSupport` | `lockTransfer(string $transferId): StockTransfer` | lock |
| 3 | same | `ReceiptPayloadCanonicalizer` | `hash(array $payload): string` | lock (idempotency discriminator, §7.5 rule 3) |
| 4 | same | `StockTransferMovementSupport` | `lineProductIds(StockTransfer $transfer): array` | lock |
| 5 | same | `ProductCostLock` | `acquire(string $tenantId, string $companyId, array $productIds, Closure $callback): mixed` | lock |
| 6 | `receive` / `receiveAllRemaining` (Σ damaged > 0) and `close` (`write_off`) | `InventoryValuationModeResolver` | `requirePerpetual(string $companyId): void` (`apps/api/app/Modules/Inventory/Application/Services/InventoryValuationModeResolver.php:79`) | validate (§7.5 rule 4, before any write) |
| 7 | same | `StockTransferReceiptService` (private) | `validateLayerB(StockTransfer $transfer, array $payload): void` — throws `TransferReceiptFailureException` | validate (§7.5 rule 5, all lines before any write) |
| 8 | same | `InventoryGlPostingBuffer` | `mark(): int` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:29`) | movements (entry marker) |
| 9 | `receive` / `receiveAllRemaining` / `close write_off` | `StockAdjustmentService` | `receive(...)` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115`) | movements (the landing `TransferIn`) |
| 10 | same | `StockTransferMovementSupport` | `markMovementAsTransfer(StockMovement $movement, MovementType $type, string $transferId): void` | movements |
| 11 | `receive` (damage) / `close write_off` | `StockAdjustmentService` | `issue(...)` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:253`) with `reason: MovementReason::Damage` or `MovementReason::WriteOff`, `referenceType: StockMovementReferenceType::StockTransferReceipt`, `referenceId: $receiptId` | movements (destructive) |
| 12 | `close return_to_source` | `StockTransferMovementSupport` | `restockAtSource(StockTransfer $transfer, StockTransferLine $line, string $quantity, array $lotQuantities, string $userId, string $reference): array` | movements (source side, no GL) |
| 13 | `receive` (damage) / `close write_off`, once per destructive movement | `InventoryGlPostingBuffer` | `enqueue(MovementGlContext $ctx): void` (`:34`) | GL buffer |
| 14 | on any failure after step 8 | `InventoryGlPostingBuffer` | `rollbackTo(int $marker): void` (`:39`) | GL buffer |
| 15 | all three | `DocumentNumberingService` | `generateForKey(string $tenantId, string $companyId, string $sequenceType, string $prefix): string` — called as `generateForKey($transfer->tenant_id, $transfer->company_id, self::SEQUENCE_KEY, self::SEQUENCE_PREFIX)` | receipt rows (§7.5 rule 7) |
| 16 | all three, at a terminal transition only | `CurrencyScaleResolverInterface` | `getScaleSafe(?string $currency, int $default): int` — called as `getScaleSafe($company->currency_code, 3)` (CLAUDE.md rule 19; §7.11 step 2) | freight |
| 17 | all three, at a terminal transition only | `StockTransferMovementSupport` | `capitalizeTransferCost(StockTransfer $transfer, string $pool, array $landedQuantityByLineId): void` | freight |
| 18 | all three | `StoredEventRepository` | `persist(ShouldBeStored $event, ?string $uuid = null): StoredEvent` (`apps/api/vendor/spatie/laravel-event-sourcing/src/StoredEvents/Repositories/StoredEventRepository.php:23`) — called as `persist($event, $receiptId)` once for the header and once per line | events (§7.9) |
| 19 | all three | `InventoryGlPostingBuffer` | `flushIfOutermost(bool $contained = false): array` (`:56`) | GL flush, still inside the root transaction |
| 20 | all three | `Illuminate\Support\Facades\DB` | `afterCommit(Closure $callback): void` — dispatches the plain `StockTransferReceiptPosted`, plus `StockTransferCompleted` when the transition is terminal | afterCommit |

`StockTransferMovementSupport::computeAllocationWeights()` is **not** in this table on purpose: it is called only from inside `capitalizeTransferCost()` (row 17), never by a writer directly. That is the single-caller rule convention 11 asks for, and it is why the method stays on the collaborator rather than becoming a writer-side helper.

**Every caller of `StockTransferMovementSupport`, exhaustively.** Only two classes construct it, both by container auto-resolution, and no test constructs it directly:

| Caller | Methods it calls | Where |
|---|---|---|
| `StockTransferService::initiate()` | `lockTransfer`, `lineProductIds`, `markMovementAsTransfer` | the `initiate` body, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:86`, whose in-transit move is `moveSourceToInTransit` at `:514` |
| `StockTransferService::cancel()` | `lockTransfer`, `lineProductIds`, `markMovementAsTransfer`, `restockAtSource` | the `cancel` body, `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:429`; its restock loop at `:451-480` becomes the `restockAtSource` call of §6.7b |
| `StockTransferReceiptService::receive()` | `lockTransfer`, `lineProductIds`, `markMovementAsTransfer`, `capitalizeTransferCost` | rows 2, 4, 10, 17 above |
| `StockTransferReceiptService::close()` | `lockTransfer`, `lineProductIds`, `markMovementAsTransfer`, `restockAtSource`, `capitalizeTransferCost` | rows 2, 4, 10, 12, 17 above |
| `StockTransferReceiptService::receiveAllRemaining()` | `lockTransfer`, `lineProductIds`, `markMovementAsTransfer`, `capitalizeTransferCost` | rows 2, 4, 10, 17 above |

`StockTransferService::complete()` calls **none** of them after S1, because it is the three-line forwarder above.

**`return_to_source` source-location authorization — the exact symbol (gate r2 M1).** The check is a **controller** concern, not a service one, because it is an HTTP authorization decision with a 403 body, and the service must stay reachable from a console context. `StockTransferController` gains one private method beside the two existing helpers:

```php
    private function canAccessLocationOrFail(string $locationId, string $companyId, User $user): ?JsonResponse
    {
        $allowed = $this->locationContext->getAllowedLocationIds($companyId, $user);

        if ($allowed === null || in_array($locationId, $allowed, true)) {
            return null;
        }

        return $this->locationAccessDeniedResponse($locationId, $user->id);
    }
```

It reuses `LocationContext::getAllowedLocationIds` exactly as `canSeeTransfer` does (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:347-357`) and returns the existing static 403 body of `locationAccessDeniedResponse` (`:359-371`). `receive` calls it once with `$transfer->destination_location_id`; `close` calls it once with `$transfer->destination_location_id` and, **when `disposition === 'return_to_source'`, a second time with `$transfer->source_location_id`**, before the service is touched, so a refused actor causes zero writes. `complete` calls it once with the destination, as the controller does today.

**Response ownership — one owner, contradiction removed (gate r2 M1).** Rev 2 said in §6.7b that `TransferReceiptResult` carries raw entities and the controller maps the response, and in §11.6 that S2 "changes the receipt service so the response transfer goes through the gated builder". Those cannot both be true. **The controller is the sole owner of response construction, in S1 and in S2.** `StockTransferReceiptService` returns `TransferReceiptResult` and never builds a payload; S2's change to the receipt writer is therefore **only** that `is_blind` on the persisted receipt row is set from `ExpectedQuantityVisibility::canSeeExpected(...)` — a column value, not a response member. The `transfer` member of the 201/200 envelope is chosen in `StockTransferController` by the same per-transfer builder selection that `index` and `show` use (§8.6), and §11.6's row for `StockTransferReceiptService.php` is restated accordingly.

`TransferReceiptResult` is a plain `final readonly class` in `App\Modules\Inventory\Application\Services` carrying `public readonly StockTransferReceipt $receipt`, `public readonly StockTransfer $transfer` and `public readonly bool $replayed`; it is **not** `#[TypeScript]` and never reaches the wire — the controller maps it onto the DTOs of §7.12 and §7.12b. It is listed in S1's file inventory (§7.2).

---
## 7. Slice S1 — T-2 receipt spine

Worktree `.worktrees/t2-receipt-spine`, branch `lane/t2-receipt-spine`, PG database `autoerp_test_u`.

### 7.1 Scope and ownership

**Spec sections implemented:** §1 (T-2 in-scope), §2 (glossary section, all nine rows), §3.1 (transfer-side columns), §3.1b (models except `CompanyFraudSettings`), §3.2, §3.3, §3.4, §3.5, §3.6 invariants I1–I7, §4.0, §4.1, §4.2, §4.3, §4.4, §5.1, §5.2, §5.4, §5.6 (delegation half), §5.8, §6.1, §6.2, §6.3, §6.4, §11 steps 1a, 1b, 1c, 1e, 2 and 4.

**§10 rows owned:** T1, T2, **T3-S1** (the partial contract of §2.2 deviation 5 — the movement/GL/valuation half; the discrepancy **alert** is `T3-S4` and S4 re-runs the whole T3 row), T4, T4b, T5, T6, T7, T7b, T7c, T8, T11, T12, T13, T14, T15, T16, and the S-matrix rows *receive*, *close write_off*, *close return_to_source*, *complete (delegate)*, *backfill*, *migrations*.

**PG-only classes in this slice:** `StockTransferReceiveConcurrencyPostgresTest` (T7, T7b, T7c), `TransferReceiptPatternQueryPostgresTest` (T16), `TransferReceiptSchemaRerunPostgresTest` (migrations S-matrix row), and the single forced-failure case inside `TransferLegacyCompletionBackfillTest` (T11 PG-only case; mark-skipped on SQLite).

**Feature-lane manifest (gate r1 B1):** this slice adds **12** `Inventory`, **1** `Replenishment` and **1** `Migrations` Feature class, so it writes `Inventory 129 -> 141`, `Replenishment 8 -> 9`, `Migrations 14 -> 15`, `gated_ceiling 1253 -> 1267`, and appends all fourteen names to the `backend-test-pgsql` allowlist. Arithmetic, note text and allowlist rule: §6.7.

**Seams respected:** 1 (first GL on this document type, through the buffer only), 2 (freight residual persisted, no journal, no new GL counterpart), 3 (`CARRYING_STATUSES` fixes the WAC denominator; capitalisation still only at the terminal transition, after the status write), 4 (no transit location, remainder stays derived, land-then-scrap), 5 (receipt events carry movement ids, not the `StockMovementRecordedV2` label), 6 (no listener on close), 7 (`restockAtSource` extracted and reused).

**What S1 deliberately does not do.** No `blind_receiving` column, no `visibility_version`, no `TransferReceiverPayloadBuilder`, no receiver-view route, no masking of any surface, no notification listener, no web change. S1 is exactly the "setting off" behaviour (§2.2 deviation 2).

### 7.2 Files

> **§7.2 AMENDMENT (rev 8).** Add to S1's **Modify (production)** list: `apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php` (movement created with final type + transfer linkage, §6.7b amendment) and the working document-per-action baseline file under `apps/api/tests/Architecture/baselines/` (removal of the `StockTransferService::markMovementAsTransfer::stock_movements::update#1` entry only). Add to S1's **Add (tests)** list the red-first "created with final type, never updated" test named in the amendment. Ledger §11.1: both files appear once, in Push 3 (backend runtime), with S1.


Add (production):

- `apps/api/database/migrations/tenant/2026_09_09_100000_add_receipt_counters_to_transfer_lines.php`
- `apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php`
- `apps/api/database/migrations/tenant/2026_09_09_100200_add_close_columns_to_stock_transfers.php`
- `apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferReceipt.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferReceiptLine.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferReceiptLineLot.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/TransferReceiptKind.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/TransferReceiptStatus.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/TransferCloseDisposition.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/TransferDiscrepancyReason.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/TransferReceiptFailureReason.php`
- `apps/api/app/Modules/Inventory/Domain/Enums/TransferActorRole.php`
- `apps/api/app/Modules/Inventory/Domain/Exceptions/TransferReceiptFailureException.php`
- `apps/api/app/Modules/Inventory/Domain/Events/StockTransferReceivedV1.php`
- `apps/api/app/Modules/Inventory/Domain/Events/StockTransferClosedV1.php`
- `apps/api/app/Modules/Inventory/Domain/Events/StockTransferReceiptLineRecordedV1.php`
- `apps/api/app/Modules/Inventory/Domain/Events/StockTransferReceiptPosted.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferMovementSupport.php` (**rev 2**, gate r1 M3 — §6.7b)
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php`
- `apps/api/app/Modules/Inventory/Application/Services/TransferReceiptResult.php` (**rev 2**, gate r1 M3 — the writer's return type, §6.7b)
- `apps/api/app/Modules/Inventory/Application/Services/ReceiptPayloadCanonicalizer.php`
- `apps/api/app/Modules/Inventory/Application/Services/TransferReconciliationService.php`
- `apps/api/app/Modules/Inventory/Application/Services/TransferPayloadBuilder.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferReceiptData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferReceiptLineData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferReceiptLineLotData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferCloseReceiptData.php` (**rev 2**, gate r1 M2 — §7.12)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferCloseLineData.php` (**rev 2**, gate r1 M2)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferCloseLineLotData.php` (**rev 2**, gate r1 M2)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationLineData.php` (**rev 2**, gate r1 M4 — §7.12)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationLotData.php` (**rev 2**, gate r1 M4)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationSummaryData.php` (**rev 2**, gate r1 M4)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationReceiptData.php` (**rev 2**, gate r1 M4)
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferData.php` (**rev 3**, gate r2 B1 — §7.12b; S2 later adds `blind` and `visibility_version`, §11.6)
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineData.php` (**rev 3**, gate r2 B1 — §7.12b)
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineBatchAllocationData.php` (**rev 3**, gate r2 B1 — §7.12b)
- `apps/api/app/Modules/Inventory/Presentation/Requests/ReceiveStockTransferRequest.php`
- `apps/api/app/Modules/Inventory/Presentation/Requests/CloseStockTransferRequest.php`
- `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` (**rev 2**, gate r1 M7 — §7.16)

Modify (documentation):

- `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` (**rev 3**, gate r2 M6 — the one-line successor pointer of §7.16, now an owned S1 deliverable instead of an unowned orchestrator edit)

Modify (production):

- `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransfer.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferLine.php`
- `apps/api/app/Modules/Inventory/Domain/StockTransferLineBatchAllocation.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php`
- `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php`
- `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php`
- `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- `apps/api/app/Modules/Inventory/Presentation/routes.php`
- `apps/api/app/Shared/Domain/Enums/StockMovementReferenceType.php`
- `apps/api/app/Http/Middleware/RequireAnyPermission.php`
- `apps/api/database/seeders/RolesAndPermissionsSeeder.php`
- `apps/api/tests/feature-lane-manifest.json` (**rev 2**, gate r1 B1 — Inventory 129→141, Replenishment 8→9, Migrations 14→15, `gated_ceiling` 1253→1267, §6.7.3/§6.7.4)
- `.github/workflows/ci.yml` (**rev 2**, gate r1 B1 — fourteen class names appended to the `backend-test-pgsql` `--filter` alternation at `:1142`, with a comment block above `:1141` in the shape of `:1132-1140`, §6.7.5)
- `docs/glossary.md`

`apps/web/src/hooks/permissionsMap.generated.ts` and `packages/shared/types/generated.d.ts` are regenerated and committed in this push but are **generated artifacts**, listed once in §11.5 and outside the exactly-once source rule.

Add (tests):

- `apps/api/tests/Feature/Inventory/StockTransferReceiveTest.php` (T1, T2, T8)
- `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php` (T3)
- `apps/api/tests/Feature/Inventory/StockTransferReceiveLotsTest.php` (T6)
- `apps/api/tests/Feature/Inventory/StockTransferReceiveValidationTest.php` (T5)
- `apps/api/tests/Feature/Inventory/StockTransferCloseTest.php` (T4, T4b)
- `apps/api/tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php` (T7, T7b, T7c)
- `apps/api/tests/Feature/Inventory/TransferReceiptEventStreamTest.php` (T15)
- `apps/api/tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php` (T13)
- `apps/api/tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php` (T16)
- `apps/api/tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php` (T11 + backfill S-matrix row)
- `apps/api/tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php` (S-matrix rows *receive*, *close write_off*, *close return_to_source*, *complete*)
- `apps/api/tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php` (**plan-added**, not a §10 row — route-gate widening, §7.14)
- `apps/api/tests/Feature/Replenishment/TransferCloseReplenishmentSettlementTest.php` (T14)
- `apps/api/tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php` (S-matrix *migrations* row)
- `apps/api/tests/Architecture/TransferInTransitReadersUseRemainderTest.php` (T12)
- `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` (**rev 3**, gate r2 M3 — the committed pre-change snapshot of all four reader query sites, written by the capture method of §7.14 and read by the preservation method; it is a test asset, not a Feature class, so it carries **no** manifest group and no ceiling consequence, exactly as `apps/api/tests/Support/AssertsBlindPayloads.php` does not)

### 7.3 Migrations 1a–1c

All three follow the §3.1 recipe: one `Schema::create` carrying the primary key and every NOT NULL column; `Schema::hasColumn` per added column; named constraints only after a `pg_constraint` lookup; `CREATE [UNIQUE] INDEX IF NOT EXISTS`. Each is one PostgreSQL transaction and is logged only on success, so a rolled-back migration is re-selected by the next `tenants:migrate`. Post-commit drift is repaired by a **new forward migration**, never by `migrate:rollback`.

**(1a) `apps/api/database/migrations/tenant/2026_09_09_100000_add_receipt_counters_to_transfer_lines.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'stock_transfer_lines' => 'stock_transfer_lines_received_within_sent',
        'stock_transfer_line_batch_allocations' => 'stock_transfer_line_batch_allocations_received_within_sent',
    ];

    private const COUNTERS = [
        'quantity_received',
        'quantity_damaged',
        'quantity_written_off',
        'quantity_returned',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            foreach (self::COUNTERS as $column) {
                if (Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->decimal($column, 15, 4)->default(0);
                });
            }
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TABLES as $table => $constraint) {
            $this->addCheck($table, $constraint);
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table => $constraint) {
                DB::statement('ALTER TABLE '.$table.' DROP CONSTRAINT IF EXISTS '.$constraint);
            }
        }

        foreach (array_keys(self::TABLES) as $table) {
            foreach (self::COUNTERS as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                    $blueprint->dropColumn($column);
                });
            }
        }
    }

    private function addCheck(string $table, string $constraint): void
    {
        $existing = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$constraint]);

        if ($existing !== null) {
            return;
        }

        DB::statement(
            'ALTER TABLE '.$table.' ADD CONSTRAINT '.$constraint.' CHECK ('
            .'quantity_received >= 0 AND quantity_damaged >= 0 '
            .'AND quantity_written_off >= 0 AND quantity_returned >= 0 '
            .'AND quantity_received + quantity_damaged + quantity_written_off + quantity_returned <= quantity)'
        );
    }
};
```

**(1b) `apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_transfer_receipts')) {
            Schema::create('stock_transfer_receipts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                // tenant_id is a plain indexed uuid (NOT an FK): the `tenants` table lives in
                // the CENTRAL database, so under db-per-tenant a cross-DB FK is impossible.
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->uuid('transfer_id');
                $table->string('receipt_number', 80);
                $table->string('kind', 20);
                $table->string('disposition', 20)->nullable();
                $table->string('status', 20);
                $table->smallInteger('sequence');
                $table->boolean('is_blind');
                $table->boolean('has_discrepancy');
                $table->string('idempotency_key', 128);
                $table->char('payload_hash', 64);
                $table->uuid('received_by_user_id');
                $table->timestampTz('received_at');
                $table->text('notes')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('stock_transfer_receipt_lines')) {
            Schema::create('stock_transfer_receipt_lines', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('receipt_id');
                $table->uuid('transfer_line_id');
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->uuid('product_id');
                $table->uuid('variant_id')->nullable();
                $table->boolean('is_lot_tracked');
                $table->decimal('quantity_received', 15, 4)->default(0);
                $table->decimal('quantity_damaged', 15, 4)->default(0);
                $table->decimal('quantity_written_off', 15, 4)->default(0);
                $table->decimal('quantity_returned', 15, 4)->default(0);
                $table->decimal('quantity_sent_snapshot', 15, 4);
                $table->string('discrepancy_reason', 32)->nullable();
                $table->text('discrepancy_note')->nullable();
                $table->uuid('in_movement_id')->nullable();
                $table->uuid('scrap_movement_id')->nullable();
                $table->uuid('return_movement_id')->nullable();
                $table->timestampsTz();
            });
        }

        if (! Schema::hasTable('stock_transfer_receipt_line_lots')) {
            Schema::create('stock_transfer_receipt_line_lots', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('receipt_line_id');
                $table->uuid('batch_allocation_id');
                $table->uuid('tenant_id');
                $table->uuid('company_id');
                $table->unsignedBigInteger('batch_id');
                $table->decimal('quantity_received', 15, 4)->default(0);
                $table->decimal('quantity_damaged', 15, 4)->default(0);
                $table->decimal('quantity_written_off', 15, 4)->default(0);
                $table->decimal('quantity_returned', 15, 4)->default(0);
                $table->uuid('in_movement_id')->nullable();
                $table->uuid('scrap_movement_id')->nullable();
                $table->uuid('return_movement_id')->nullable();
                $table->timestampsTz();
            });
        }

        $this->createIndexes();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $this->addForeignKeys();
        $this->addChecks();
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_receipt_line_lots');
        Schema::dropIfExists('stock_transfer_receipt_lines');
        Schema::dropIfExists('stock_transfer_receipts');
    }

    private function createIndexes(): void
    {
        $statements = [
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipts_company_number_unique ON stock_transfer_receipts (tenant_id, company_id, receipt_number)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipts_idempotency_unique ON stock_transfer_receipts (tenant_id, company_id, idempotency_key)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipts_transfer_sequence_unique ON stock_transfer_receipts (transfer_id, sequence)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipts_company_transfer_idx ON stock_transfer_receipts (tenant_id, company_id, transfer_id)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipts_transfer_received_at_idx ON stock_transfer_receipts (transfer_id, received_at)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_receipt_line_unique ON stock_transfer_receipt_lines (receipt_id, transfer_line_id)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipt_lines_product_idx ON stock_transfer_receipt_lines (product_id)',
            'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_line_allocation_unique ON stock_transfer_receipt_line_lots (receipt_line_id, batch_allocation_id)',
            'CREATE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_batch_idx ON stock_transfer_receipt_line_lots (batch_id)',
        ];

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_in_movement_unique ON stock_transfer_receipt_lines (in_movement_id) WHERE in_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_scrap_movement_unique ON stock_transfer_receipt_lines (scrap_movement_id) WHERE scrap_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_lines_return_movement_unique ON stock_transfer_receipt_lines (return_movement_id) WHERE return_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_in_movement_unique ON stock_transfer_receipt_line_lots (in_movement_id) WHERE in_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_scrap_movement_unique ON stock_transfer_receipt_line_lots (scrap_movement_id) WHERE scrap_movement_id IS NOT NULL';
            $statements[] = 'CREATE UNIQUE INDEX IF NOT EXISTS stock_transfer_receipt_line_lots_return_movement_unique ON stock_transfer_receipt_line_lots (return_movement_id) WHERE return_movement_id IS NOT NULL';
        }

        foreach ($statements as $statement) {
            DB::statement($statement);
        }
    }

    private function addForeignKeys(): void
    {
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_company_id_foreign',
            'FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_transfer_id_foreign',
            'FOREIGN KEY (transfer_id) REFERENCES stock_transfers (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_received_by_user_id_foreign',
            'FOREIGN KEY (received_by_user_id) REFERENCES users (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_receipt_id_foreign',
            'FOREIGN KEY (receipt_id) REFERENCES stock_transfer_receipts (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_transfer_line_id_foreign',
            'FOREIGN KEY (transfer_line_id) REFERENCES stock_transfer_lines (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_company_id_foreign',
            'FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_product_id_foreign',
            'FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_receipt_line_id_foreign',
            'FOREIGN KEY (receipt_line_id) REFERENCES stock_transfer_receipt_lines (id) ON DELETE CASCADE'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_batch_allocation_id_foreign',
            'FOREIGN KEY (batch_allocation_id) REFERENCES stock_transfer_line_batch_allocations (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_batch_id_foreign',
            'FOREIGN KEY (batch_id) REFERENCES product_batches (id) ON DELETE RESTRICT'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_company_id_foreign',
            'FOREIGN KEY (company_id) REFERENCES companies (id) ON DELETE CASCADE'
        );
    }

    private function addChecks(): void
    {
        $this->addConstraint(
            'stock_transfer_receipts',
            'stock_transfer_receipts_close_has_disposition',
            "CHECK ((kind = 'close') = (disposition IS NOT NULL))"
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_quantities_non_negative',
            'CHECK (quantity_received >= 0 AND quantity_damaged >= 0 AND quantity_written_off >= 0 AND quantity_returned >= 0)'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_positive',
            'CHECK (quantity_received + quantity_damaged + quantity_written_off + quantity_returned > 0)'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_close_excludes_receipt',
            'CHECK (NOT (quantity_written_off > 0 OR quantity_returned > 0) OR (quantity_received = 0 AND quantity_damaged = 0))'
        );
        $this->addConstraint(
            'stock_transfer_receipt_lines',
            'stock_transfer_receipt_lines_lot_lines_have_no_parent_movement',
            'CHECK (is_lot_tracked = false OR (in_movement_id IS NULL AND scrap_movement_id IS NULL AND return_movement_id IS NULL))'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_quantities_non_negative',
            'CHECK (quantity_received >= 0 AND quantity_damaged >= 0 AND quantity_written_off >= 0 AND quantity_returned >= 0)'
        );
        $this->addConstraint(
            'stock_transfer_receipt_line_lots',
            'stock_transfer_receipt_line_lots_positive',
            'CHECK (quantity_received + quantity_damaged + quantity_written_off + quantity_returned > 0)'
        );
    }

    private function addConstraint(string $table, string $name, string $definition): void
    {
        $existing = DB::selectOne('SELECT 1 AS present FROM pg_constraint WHERE conname = ?', [$name]);

        if ($existing !== null) {
            return;
        }

        DB::statement('ALTER TABLE '.$table.' ADD CONSTRAINT '.$name.' '.$definition);
    }
};
```

**(1c) `apps/api/database/migrations/tenant/2026_09_09_100200_add_close_columns_to_stock_transfers.php`**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_transfers', 'closed_by_user_id')) {
            Schema::table('stock_transfers', function (Blueprint $table): void {
                $table->uuid('closed_by_user_id')->nullable();
            });
        }

        foreach ([
            'closed_at' => static fn (Blueprint $table) => $table->timestampTz('closed_at')->nullable(),
            'close_disposition' => static fn (Blueprint $table) => $table->string('close_disposition', 20)->nullable(),
            'close_reason' => static fn (Blueprint $table) => $table->string('close_reason', 32)->nullable(),
            'close_note' => static fn (Blueprint $table) => $table->text('close_note')->nullable(),
            'freight_uncapitalized' => static fn (Blueprint $table) => $table->decimal('freight_uncapitalized', 15, 4)->default(0),
        ] as $column => $definition) {
            if (Schema::hasColumn('stock_transfers', $column)) {
                continue;
            }

            Schema::table('stock_transfers', function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        $existing = DB::selectOne(
            'SELECT 1 AS present FROM pg_constraint WHERE conname = ?',
            ['stock_transfers_closed_by_user_id_foreign']
        );

        if ($existing === null) {
            DB::statement(
                'ALTER TABLE stock_transfers ADD CONSTRAINT stock_transfers_closed_by_user_id_foreign '
                .'FOREIGN KEY (closed_by_user_id) REFERENCES users (id) ON DELETE SET NULL'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stock_transfers DROP CONSTRAINT IF EXISTS stock_transfers_closed_by_user_id_foreign');
        }

        foreach (['freight_uncapitalized', 'close_note', 'close_reason', 'close_disposition', 'closed_at', 'closed_by_user_id'] as $column) {
            if (! Schema::hasColumn('stock_transfers', $column)) {
                continue;
            }

            Schema::table('stock_transfers', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
```

`stock_transfers.status` is untouched: it is already `string(20)` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:42`) and the longest new value, `closed_with_writeoff`, is exactly 20 characters.

**Uniques without `company_id`, and why each is legitimate under the convention-09 ratchet** (`docs/conventions/09-SECOND-OF-EVERYTHING.md:54-59`): `stock_transfer_receipts (transfer_id, sequence)`, `stock_transfer_receipt_lines (receipt_id, transfer_line_id)`, `stock_transfer_receipt_line_lots (receipt_line_id, batch_allocation_id)` and the six partial movement-id uniques. Each is **parent-scoped by a uuid** whose own parent already carries `company_id`, and none of the three tables is a catalogue entity (they are documents, not operator-edited per-company records). The two **document identity** uniques — `(tenant_id, company_id, receipt_number)` and `(tenant_id, company_id, idempotency_key)` — do carry `company_id`, exactly as the transfer table does (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:66-67`). No table is added to `CATALOGUE_TABLES`, and no `EXCLUDED_TABLES` waiver is requested.

### 7.4 Backfill migration 1e

`apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php`. Idempotent by predicate, chunked at 500 through the Query Builder (portable to the SQLite lane), hashes computed in PHP.

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const CHUNK = 500;

    public function up(): void
    {
        $now = now();

        do {
            $transfers = DB::table('stock_transfers')
                ->where('status', 'completed')
                ->whereNotExists(function ($query): void {
                    $query->select(DB::raw(1))
                        ->from('stock_transfer_receipts')
                        ->whereColumn('stock_transfer_receipts.transfer_id', 'stock_transfers.id');
                })
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get();

            foreach ($transfers as $transfer) {
                $this->backfillTransfer($transfer, $now);
            }
        } while ($transfers->count() === self::CHUNK);

        DB::table('stock_transfer_lines')
            ->whereIn('transfer_id', DB::table('stock_transfers')->select('id')->where('status', 'completed'))
            ->where('quantity_received', 0)
            ->update(['quantity_received' => DB::raw('quantity')]);

        DB::table('stock_transfer_line_batch_allocations')
            ->whereIn('stock_transfer_line_id', DB::table('stock_transfer_lines')
                ->select('id')
                ->whereIn('transfer_id', DB::table('stock_transfers')->select('id')->where('status', 'completed')))
            ->where('quantity_received', 0)
            ->update(['quantity_received' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        DB::table('stock_transfer_receipts')->where('kind', 'legacy_completion')->delete();
    }

    private function backfillTransfer(object $transfer, \Illuminate\Support\Carbon $now): void
    {
        $receiptId = (string) Str::orderedUuid();
        $idempotencyKey = 'sys:legacy-completion:'.$transfer->id;

        DB::table('stock_transfer_receipts')->insert([
            'id' => $receiptId,
            'tenant_id' => $transfer->tenant_id,
            'company_id' => $transfer->company_id,
            'transfer_id' => $transfer->id,
            'receipt_number' => 'TRR-LEGACY-'.$transfer->transfer_number,
            'kind' => 'legacy_completion',
            'disposition' => null,
            'status' => 'posted',
            'sequence' => 1,
            'is_blind' => false,
            'has_discrepancy' => false,
            'idempotency_key' => $idempotencyKey,
            'payload_hash' => hash('sha256', $idempotencyKey),
            'received_by_user_id' => $transfer->completed_by_user_id ?? $transfer->initiated_by_user_id,
            'received_at' => $transfer->completed_at ?? $transfer->updated_at,
            'notes' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lines = DB::table('stock_transfer_lines')->where('transfer_id', $transfer->id)->orderBy('id')->get();

        foreach ($lines as $line) {
            $allocations = DB::table('stock_transfer_line_batch_allocations')
                ->where('stock_transfer_line_id', $line->id)
                ->orderBy('id')
                ->get();

            $isLotTracked = $allocations->isNotEmpty();
            $receiptLineId = (string) Str::orderedUuid();

            DB::table('stock_transfer_receipt_lines')->insert([
                'id' => $receiptLineId,
                'receipt_id' => $receiptId,
                'transfer_line_id' => $line->id,
                'tenant_id' => $transfer->tenant_id,
                'company_id' => $transfer->company_id,
                'product_id' => $line->product_id,
                'variant_id' => $line->variant_id ?? null,
                'is_lot_tracked' => $isLotTracked,
                'quantity_received' => $line->quantity,
                'quantity_damaged' => '0.0000',
                'quantity_written_off' => '0.0000',
                'quantity_returned' => '0.0000',
                'quantity_sent_snapshot' => $line->quantity,
                'discrepancy_reason' => null,
                'discrepancy_note' => null,
                'in_movement_id' => $isLotTracked
                    ? null
                    : $this->uniqueTransferInMovementId($transfer, $line->product_id, null),
                'scrap_movement_id' => null,
                'return_movement_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($allocations as $allocation) {
                DB::table('stock_transfer_receipt_line_lots')->insert([
                    'id' => (string) Str::orderedUuid(),
                    'receipt_line_id' => $receiptLineId,
                    'batch_allocation_id' => $allocation->id,
                    'tenant_id' => $transfer->tenant_id,
                    'company_id' => $transfer->company_id,
                    'batch_id' => $allocation->batch_id,
                    'quantity_received' => $allocation->quantity,
                    'quantity_damaged' => '0.0000',
                    'quantity_written_off' => '0.0000',
                    'quantity_returned' => '0.0000',
                    'in_movement_id' => $this->uniqueTransferInMovementId($transfer, $line->product_id, $allocation->batch_id),
                    'scrap_movement_id' => null,
                    'return_movement_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function uniqueTransferInMovementId(object $transfer, string $productId, ?int $batchId): ?string
    {
        $query = DB::table('stock_movements')
            ->where('reference_type', 'App\\Modules\\Inventory\\Domain\\StockTransfer')
            ->where('reference_id', $transfer->id)
            ->where('movement_type', 'transfer_in')
            ->where('location_id', $transfer->destination_location_id)
            ->where('product_id', $productId);

        if ($batchId !== null) {
            $query->where('batch_id', $batchId);
        }

        $ids = $query->pluck('id');

        return $ids->count() === 1 ? (string) $ids->first() : null;
    }
};
```

Contract notes, each asserted by T11:

- `is_lot_tracked` is **"the transfer line has ≥ 1 batch allocation"** — the shipment-time grain — **never** the product's current `requires_batch_tracking` flag. A fixture line whose product flag was flipped after shipment keeps its shipment-time grain.
- `in_movement_id` resolves only when **exactly one** `transfer_in` movement matches; zero or several match → `null`, documented, never a guess.
- `in_transit` and `cancelled` transfers are untouched.
- Re-run adds nothing: the `whereNotExists` predicate excludes every transfer that already has a receipt, and the two counter updates are `WHERE quantity_received = 0`.
- **Interruption recovery is PostgreSQL-only.** On PG the migration transaction discards every chunk of a failed run, so a header without its lines cannot persist and the unlogged migration simply re-runs. On SQLite (no migration transaction) an interruption between a header insert and its lines would leave a row the predicate skips; no convergence-after-interruption is claimed there. T11 carries the forced-failure case on PG only and asserts single-pass idempotency on both lanes.

### 7.5 Receive and close API contract

**`POST /stock-transfers/{transfer}/receive`** — `can:inventory.transfers.complete` + `canAccessLocation(destination)`. Request body:

```json
{
  "idempotency_key": "client key (required; a UUID by convention; MUST NOT start with sys:)",
  "notes": "string|null",
  "lines": [
    {
      "transfer_line_id": "uuid",
      "quantity_received": "11.0000",
      "quantity_damaged": "1.0000",
      "discrepancy_reason": "damaged_in_transit",
      "discrepancy_note": "box crushed",
      "lots": [
        { "batch_id": 812, "quantity_received": "11.0000", "quantity_damaged": "1.0000" }
      ]
    }
  ]
}
```

Service rules, in order:

1. **Idempotency first.** `DB::transaction(attempts: 3)` → `$this->movementSupport->lockTransfer($transferId)` — the shared collaborator of §6.7b, whose body is the one moved verbatim from `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:734-743` — → lookup by `(tenant_id, company_id, idempotency_key)`. Same `transfer_id` and equal `payload_hash` → the stored receipt, **200**, `meta.replayed = true`, whatever the current state. Different transfer or different hash → `IDEMPOTENCY_KEY_REUSED`. Only then is `canReceive()` evaluated, else `TransferStateException`.
2. **Key race across transfers.** Catch `UniqueConstraintViolationException` **outside** the transaction, re-read the committed winner by key (precedent `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:111`), apply rule 1's discriminator. If no receipt exists for that key after the catch, the violation is **not** an idempotency race (every other unique is validated up front by A1/A5) and is re-thrown as a 500 data bug — never mapped to a 422.
3. **Canonical payload hash** — `ReceiptPayloadCanonicalizer::hash(array $payload): string`: drop `idempotency_key`; treat an absent `notes`/`discrepancy_note` as `null`; normalise every quantity to 4 dp with `bcadd($q, '0', 4)`; sort `lines` by `transfer_line_id` and `lots` by `batch_id`; recursive `ksort`; `json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)` (precedent `apps/api/app/Modules/Fiscal/Application/Services/TerminalRegistrySnapshotService.php:540`); `hash('sha256', $json)`.
4. **Valuation preflight.** If `Σ quantity_damaged > 0`, call `InventoryValuationModeResolver::requirePerpetual($companyId)` (`apps/api/app/Modules/Inventory/Application/Services/InventoryValuationModeResolver.php:79`) **before any movement**; its `UnsupportedValuationModeException` (`apps/api/app/Modules/Inventory/Domain/Exceptions/UnsupportedValuationModeException.php:22`) maps to typed 422 `VALUATION_MODE_UNSUPPORTED` and zero rows. The same preflight runs for a `write_off` close.
5. **Two validation layers, both complete before the first write.** A failure at either layer leaves zero `stock_transfer_receipts` / `_lines` / `_lots` rows, zero movements, zero `stored_events` and unchanged counters (T5 asserts each case).
   - **Layer A — `ReceiveStockTransferRequest`** (structural; runs in the FormRequest, therefore before rule 1). Cross-field rules go through `withValidator()` → `$validator->after()` exactly as `apps/api/app/Modules/Inventory/Presentation/Requests/StockAdjustmentLineRules.php:85`. Failures are the framework `VALIDATION_ERROR` 422 (`apps/api/bootstrap/app.php:325`) with static field messages.
   - **Layer B — `StockTransferReceiptService`** (data-dependent; inside the transaction after rule 1, the header lock and all line product advisory locks taken in one sorted call — `$this->costLock->acquire($transfer->tenant_id, $transfer->company_id, $this->movementSupport->lineProductIds($transfer), fn () => ...)`, the same shape as `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:334-336` and `:448-450`), evaluated for **every** line before any write; failure is a typed `TransferReceiptFailureReason` 422 with `details = {transfer_line_id, batch_id}` only.
   - `is_lot_tracked` is **not** a request field. The service derives it as "the transfer line has ≥ 1 batch allocation" and stores it on the receipt line.

| # | Rule (layer) | Schema constraint it mirrors | Failure |
|---|---|---|---|
| A1 | `lines` `required\|array\|min:1`; `lines.*.transfer_line_id` `required\|string\|uuid\|distinct` | UNIQUE `(receipt_id, transfer_line_id)` | `VALIDATION_ERROR` on `lines.{i}.transfer_line_id` |
| A2 | `lines.*.quantity_received`, `lines.*.quantity_damaged`, `lines.*.lots.*.quantity_received`, `lines.*.lots.*.quantity_damaged`: `required\|numeric\|regex:/^\d+(\.\d{1,4})?$/` | CHECK all four ≥ 0; `decimal(15,4)` | `VALIDATION_ERROR` on the field |
| A3 | `after()`: per line `bccomp(bcadd($received, $damaged, 4), '0', 4) > 0` | CHECK `stock_transfer_receipt_lines_positive` on the line | `VALIDATION_ERROR` on `lines.{i}.quantity_received` |
| A4 | `after()`: per lot row the same positivity | CHECK `stock_transfer_receipt_line_lots_positive` | `VALIDATION_ERROR` on `lines.{i}.lots.{j}.quantity_received` |
| A5 | `lines.*.lots` `nullable\|array`; `lines.*.lots.*.batch_id` `required\|integer\|min:1`; `after()`: `batch_id` unique **within each line**, written as a closure (not the `distinct` rule, which scopes to the leading explicit path `lines` and would wrongly forbid the same batch on two lines) | UNIQUE `(receipt_line_id, batch_allocation_id)` | `VALIDATION_ERROR` on `lines.{i}.lots.{j}.batch_id` |
| A6 | `idempotency_key` `required\|string\|max:128\|not_regex:/^sys:/i` with the static message "The idempotency key uses a reserved namespace."; `notes` `nullable\|string\|max:5000`; `lines.*.discrepancy_reason` `nullable\|string\|max:32`; `lines.*.discrepancy_note` `nullable\|string\|max:1000` | column widths; the reserved `sys:` namespace | `VALIDATION_ERROR` on `idempotency_key` |
| B1 | The line id belongs to this transfer and company | FK + document identity | `LINE_NOT_ON_TRANSFER` |
| B2 | `bccomp(bcadd($received, $damaged, 4), $line->remainingQuantity(), 4) <= 0` | CHECK `stock_transfer_lines_received_within_sent` | `OVER_RECEIPT` |
| B3 | The line has allocations (`is_lot_tracked = true`) ⇒ `lots` present and non-empty | I3; the lot-lines CHECK | `LOT_REQUIRED` |
| B4 | The line has no allocations ⇒ `lots` absent or empty | the same CHECK read the other way | `LOT_NOT_ALLOWED` |
| B5 | Each lot `batch_id` ∈ the line's shipped allocations | FK `batch_allocation_id` | `UNKNOWN_LOT` |
| B6 | Per lot `received + damaged ≤ allocation.remainingQuantity()` | CHECK on the allocation | `LOT_OVER_RECEIPT` |
| B7 | `Σ lots.received == line.received` **and** `Σ lots.damaged == line.damaged` (`bccomp` at 4) | I3; I4 counters | `LOT_SUM_MISMATCH` |
| B8 | `product.requires_batch_tracking` ⇒ the line has allocations | `is_lot_tracked` consistent with the product | `LOT_TRACKING_MISMATCH` |
| B9 | `discrepancy_reason` present ⇔ `damaged > 0`; when present it must satisfy `allowedFor(Receipt)` = `damaged_in_transit \| other` | column semantics | `DISCREPANCY_REASON_REQUIRED` (absent with damage) / `DISCREPANCY_REASON_INVALID` (present without damage, or outside the set) |
| B10 | `bccomp($line->remainingQuantity(), '0', 4) > 0` for every submitted line | carrying invariant I5 | `NOTHING_TO_RECEIVE` |

Layer A never reads the transfer, so no Layer-A message can carry a sent or remaining figure. Layer B messages are the static `message()` sentences of `TransferReceiptFailureReason`.

6. **Remainder vs discrepancy.** Omitted lines are an open remainder, not a discrepancy. `discrepancy_reason` is required **iff** `quantity_damaged > 0`; the movement reason is always `MovementReason::Damage` whatever the declared reason; `has_discrepancy = Σ damaged > 0`.
7. **Write order inside the root transaction:** movements (§7.8) → line and allocation counters → receipt rows (`receipt_number` = `$this->numberingService->generateForKey($transfer->tenant_id, $transfer->company_id, self::SEQUENCE_KEY, self::SEQUENCE_PREFIX)` → `TRR-2026-0001`, called inside the advisory-lock closure exactly as `apps/api/app/Modules/Inventory/Application/Services/StockAdjustmentDocumentService.php:207` does; `sequence` = existing count + 1) → transfer status (`completed` if `Σ remainder = 0`, else `partially_received`; on `completed` also `completed_by_user_id`/`completed_at`, then §7.11 capitalisation) → stored events via `persist($event, $receiptId)` → `InventoryGlPostingBuffer::flushIfOutermost()` → `DB::afterCommit`: the plain `StockTransferReceiptPosted`, and `StockTransferCompleted` when terminal.
8. **Response** — HTTP **201**: `{ "data": { "receipt": StockTransferReceiptData, "transfer": StockTransferData }, "meta": { "replayed": false } }`. A replay returns HTTP **200** with `meta.replayed = true`. **The controller builds this envelope, not the service** (gate r2 M1): `StockTransferReceiptService` returns a `TransferReceiptResult` of raw entities, and `StockTransferController` maps `->receipt` through `receiptPayload()` and `->transfer` through `$this->payloadBuilder->build($transfer, includeLines: true)`, which returns `StockTransferData` (§7.12b). There is exactly one owner of response construction on this route, in S1 and after S2 (§6.7b, "Response ownership").

   **`StockTransferReceiptData` is the RECEIPT header DTO and its line members have no close-only properties at all** (gate r1 M2). Spec §5.0b requires `quantity_written_off` and `quantity_returned` to be **absent** — not `null` — from a `kind = receipt` echo, and an ordinary nullable Spatie `Data` property always serialises as a key. Rev 2 therefore uses two disjoint DTO families rather than one nullable family, and no Spatie `Optional`:

   | `kind` | Header DTO | Line DTO | Lot DTO | `quantity_written_off` / `quantity_returned` |
   |---|---|---|---|---|
   | `receipt` | `StockTransferReceiptData` | `StockTransferReceiptLineData` | `StockTransferReceiptLineLotData` | **no such property exists** — `array_key_exists` is false at every depth |
   | `legacy_completion` | `StockTransferReceiptData` | `StockTransferReceiptLineData` | `StockTransferReceiptLineLotData` | **no such property exists** |
   | `close` | `TransferCloseReceiptData` | `TransferCloseLineData` | `TransferCloseLineLotData` | `public string` — always present, never null |

   The selection is made in **one** place, `StockTransferReceiptData::fromModel()` / `TransferCloseReceiptData::fromModel()`, chosen by `$receipt->kind` in `StockTransferController::receiptPayload(StockTransferReceipt $receipt): Data` (§7.6). No controller, resource or builder constructs a receipt shape by adding or removing keys from the other family's output (convention 11). Full source of all five DTOs: §7.12.

9. **`receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult`** — the `/complete` delegate. It takes the transfer **id**, not an already-loaded entity, and therefore owns its own transaction, header lock and advisory lock exactly as rules 1–7 describe for `receive` (gate r2 M1: rev 2's signature took a loaded `StockTransfer` and then could not say who had locked it). The server key is `sys:complete:{transferId}` (spec §5.6), which rule A6 forbids any client from supplying. It receives every open remainder on every line and lot with `quantity_damaged = 0` and no discrepancy reason, so rule 4's `requirePerpetual` preflight never fires on this path. `StockTransferService::complete()` is the three-line forwarder onto it reproduced in §6.7b and keeps returning `StockTransfer` and HTTP **200**.

**`POST /stock-transfers/{transfer}/close`** — `can:inventory.transfers.reconcile` **and** `can:inventory.transfers.close` (two chained `can:` middlewares, an AND) + `canAccessLocation(destination)`. Request body:

```json
{
  "idempotency_key": "client key (MUST NOT start with sys:)",
  "disposition": "write_off | return_to_source",
  "reason": "lost_in_transit",
  "note": "string|null"
}
```

- Allowed from `in_transit` or `partially_received`. Rules 1–3 above apply verbatim, and rule A6 including the `sys:` rejection lives in `CloseStockTransferRequest`.
- `disposition` required (`DISPOSITION_REQUIRED`); `reason` required for **both** dispositions and must satisfy `allowedFor(Close)` (all four reasons). One disposition for the whole remainder; a mixed close is refused by design. Zero remainder → `NOTHING_TO_RECEIVE`. `write_off` runs the rule-4 preflight.
- Writes one `kind = close` receipt whose lines carry `quantity_written_off = remainder` **or** `quantity_returned = remainder` per line and lot, the movements of §7.8, the status `closed_with_writeoff` / `closed_returned`, the `closed_*` columns, §7.11 capitalisation and `freight_uncapitalized`, `StockTransferClosedV1` plus per-line events with `actorRole = closer`.
- `return_to_source` additionally requires `canAccessLocation(source)`, and the exact symbol that performs it is `StockTransferController::canAccessLocationOrFail(string $locationId, string $companyId, User $user): ?JsonResponse`, reproduced in §6.7b and called a second time with `$transfer->source_location_id` **before** the service is entered, so a refused actor causes zero writes (gate r2 M1).
- An actor lacking either permission is refused by the framework `can` gate with its **static 403** body: authorization, not validation. Response **201**, envelope `{ "data": { "receipt": TransferCloseReceiptData, "transfer": StockTransferData }, "meta": { "replayed": false } }` — the same envelope **shape** as receive, with the close DTO family in the `receipt` member (rule 8's table), and the `transfer` member built by the controller through `TransferPayloadBuilder::build(...)`, never by the service. A replay returns 200 with `meta.replayed = true`.

**`GET /stock-transfers/{transfer}/reconciliation`** — `can:inventory.transfers.reconcile` + `canSeeTransfer` + `canAccessLocation(destination) || canAccessLocation(source)`. Response `{ "data": TransferReconciliationData }`. Vocabulary borrowed from `apps/api/app/Modules/Inventory/Application/Services/CountingDiscrepancyReportService.php:21`: per line and lot `sent, received, damaged, written_off, returned, remaining, variance`; `discrepancy_reasons[]`; `receipts[]{receipt_number, kind, disposition, sequence, received_by, received_at, is_blind, has_discrepancy}`; `summary{lines, lines_with_discrepancy, total_sent, total_received, total_damaged, total_written_off, total_returned, total_remaining, freight_uncapitalized}`; and — **from S2 onward** — a top-level `visibility_version` (spec §5.4, `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:386-388`). Quantities are formatted with `QuantityScale::formatForUnit` (`apps/api/app/Shared/Domain/QuantityScale.php:64`). **The complete source of `TransferReconciliationData` and its four nested DTOs as S1 creates them is reproduced in §7.12** (gate r1 M4), and **the complete file after S2 adds `visibility_version` is reproduced in §8.7b** (gate r2 M2): this endpoint is a cross-layer contract consumed by the generated TypeScript in S4 and is not left to implementation inference. S1 cannot carry the member, because `visibility_version` is a `company_fraud_settings` column that migration 1d (§8.3) creates and S1's dispatch packet forbids (§17.1).

### 7.6 Permissions, route matrix, controller

Two new permissions, defined next to the existing transfer block at `apps/api/database/seeders/RolesAndPermissionsSeeder.php:196-200`:

- `inventory.transfers.reconcile` — visibility. Gates reconciliation, is the "sees expected" disjunct consumed by S2, and is one of the two close gates.
- `inventory.transfers.close` — write authority. Required **in addition to** reconcile for `POST /{transfer}/close`.

Both are added to the `manager` map beside the existing transfer line (`apps/api/database/seeders/RolesAndPermissionsSeeder.php:602`) and reach `admin` through `permissionNames()` (`:582`). They are grantable per role and per user, independently. No other seeded role receives either.

| Route | Today | After S1 |
|---|---|---|
| `GET /stock-transfers` (`apps/api/app/Modules/Inventory/Presentation/routes.php:99`) | `can:inventory.transfers.view` | `require.any.permission:inventory.transfers.view,inventory.transfers.complete,inventory.transfers.reconcile` |
| `GET /stock-transfers/{transfer}` (`apps/api/app/Modules/Inventory/Presentation/routes.php:107`) | `can:inventory.transfers.view` | the same three-permission any-of |
| `POST /stock-transfers/{transfer}/receive` | new | `can:inventory.transfers.complete` |
| `POST /stock-transfers/{transfer}/complete` (`apps/api/app/Modules/Inventory/Presentation/routes.php:111`) | `can:inventory.transfers.complete` | unchanged |
| `POST /stock-transfers/{transfer}/close` | new | `can:inventory.transfers.reconcile` **+** `can:inventory.transfers.close` |
| `GET /stock-transfers/{transfer}/reconciliation` | new | `can:inventory.transfers.reconcile` |
| `POST /stock-transfers`, `POST /{transfer}/cancel` (`apps/api/app/Modules/Inventory/Presentation/routes.php:115`) | create / cancel | unchanged |

`receive` is deliberately **not** under the any-of gate: `inventory.transfers.view` is a read permission held by roles that never receive, and an any-of gate on a write route would let a view-only actor post receipts and land stock. The complete-only receiver's problem was reachability of the **read** routes, which the any-of gate solves; that receiver already holds `complete`. The third disjunct `inventory.transfers.reconcile` is read-only and blind-safe: any reconcile holder is served the full builder anyway.

`RequireAnyPermission`'s 403 message literal (`apps/api/app/Http/Middleware/RequireAnyPermission.php:25`) is currently specific to its first consumer ("view transaction destinations"). S1 replaces it with the generic static sentence **"You do not have permission to perform this action."** — still no number, no transfer field. The existing consumer (`apps/api/app/Modules/Company/routes.php`) keeps working; its own test, if it asserts the literal, is updated in the same commit.

Controller changes in `StockTransferController`:

- The constructor (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:28-32`) gains `StockTransferReceiptService`, `TransferPayloadBuilder` and `TransferReconciliationService`, all `private readonly` (CLAUDE.md rule 13); the full post-S1 constructor is reproduced in §6.7b.
- Delete `formatTransfer()` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:282`); `index` (`:83`) and `show` route through `TransferPayloadBuilder`, which returns `StockTransferData` (§7.12b) — so both read routes are typed by a generated DTO from S1 onward.
- A new **private** `canAccessLocationOrFail(string $locationId, string $companyId, User $user): ?JsonResponse` (body in §6.7b) is the one place an endpoint-location decision is made: `receive` calls it with the destination, `close` with the destination and — for `return_to_source` — with the source as well, and `complete` with the destination as it does today.
- Add `receive(ReceiveStockTransferRequest $request, string $transfer)`, `close(CloseStockTransferRequest $request, string $transfer)` and `reconciliation(string $transfer)`.
- `complete` (`:205-236`) **keeps its call graph and its status**: it keeps calling `$this->service->complete($existing->id, $user->id)` (`:226`) and **keeps returning 200** (the handler calls `response()->json(...)` with no status argument); the delegation to `StockTransferReceiptService::receiveAllRemaining(string $transferId, …)` happens one layer down, inside `StockTransferService::complete()`, which is its **only** caller (§6.7b, gate r3 B1). S1 changes exactly two lines inside the action — the destination check becomes `canAccessLocationOrFail(...)` and the response member becomes `$this->payloadBuilder->build(...)` because `formatTransfer` is deleted — and S2 inserts exactly one `if` block, the blind gate (§8.6). The action's final body is reproduced in full at §6.7b block (c). Only the new `receive` and `close` return 201, and **`receive` and `close` are the only two actions that touch `$this->receiptService`** — `complete` never does.
- `canSeeTransfer` (`:347`), `locationAccessDeniedResponse` (`:359`) and `stateExceptionResponse` (`:389`) are reused unchanged. A new `receiptFailureResponse(TransferReceiptFailureException $e): JsonResponse` builds `{error:{code,message,details}}` in the same shape, with `details` a PHP array so an empty case encodes as the JSON array `[]`, never `{}`.
- A new **private** `receiptPayload(StockTransferReceipt $receipt): \Spatie\LaravelData\Data` is the one place the receipt DTO family is chosen (gate r1 M2): `return $receipt->kind === TransferReceiptKind::Close ? TransferCloseReceiptData::fromModel($receipt) : StockTransferReceiptData::fromModel($receipt);`. Both `receive` and `close` route their `data.receipt` member through it; nothing else constructs a receipt payload.

Deploy consequences (carried into §12.1's `Commands` and `Boot synchronization` rows): `tenants:seed --class=RolesAndPermissionsSeeder`, `permission:cache-reset`, `php artisan permissions:export-frontend-map`.

### 7.7 Quantity-based remainder readers and the ratchet

Two shared symbols, declared once:

```php
// apps/api/app/Modules/Inventory/Domain/StockTransferLine.php
public const string REMAINDER_SQL = '(stock_transfer_lines.quantity - stock_transfer_lines.quantity_received - stock_transfer_lines.quantity_damaged - stock_transfer_lines.quantity_written_off - stock_transfer_lines.quantity_returned)';
```

```php
// apps/api/app/Modules/Inventory/Domain/StockTransfer.php
/** @var list<string> */
public const array CARRYING_STATUSES = [
    TransferStatus::InTransit->value,
    TransferStatus::PartiallyReceived->value,
];

public function scopeCarryingInTransit(Builder $query): Builder
{
    return $query->whereIn('stock_transfers.status', self::CARRYING_STATUSES);
}
```

The status predicate stays necessary: a cancelled transfer restocks the source while its counters remain 0, so a pure remainder test would count cancelled rows as incoming.

Exactly three files change, at four query sites; each replaces its status equality with `whereIn('stock_transfers.status', StockTransfer::CARRYING_STATUSES)` and its `SUM(quantity)` with `SUM(StockTransferLine::REMAINDER_SQL)`:

| File | Root | Predicate | Aggregate | Rounding |
|---|---|---|---|---|
| `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php` (grouped incoming) | `:139` | `:144` | `:156` | FLOOR, unchanged |
| `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php` (distribution) | `:223` | `:228` | `:233` | FLOOR, unchanged |
| `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php` | `:396` | `:402` | inside the `selectRaw` at `:404` | HALF_UP, unchanged — **not** harmonised in this lane |
| `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php` | `:119` | `:124` (`whereIn` on the same constant) | `->sum('stock_transfer_lines.quantity')` at `:125` becomes `->selectRaw('COALESCE(SUM('.StockTransferLine::REMAINDER_SQL.'), 0) AS remainder')->value('remainder')`; the `bcadd` at `:127` keeps its working scale | — |

None of the three readers is rooted in a `StockTransfer` Eloquent builder, so `scopeCarryingInTransit()` does **not** apply there; it exists for the transfer list and reconciliation queries only.

**Ratchet (T12).** `apps/api/tests/Architecture/TransferInTransitReadersUseRemainderTest.php` scans exactly those three files and asserts, per file, (a) no literal `TransferStatus::InTransit` and (b) both `REMAINDER_SQL` and `CARRYING_STATUSES` present. `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:441` is deliberately outside the scan set (it is the cancel path, not a reader). The class carries a liveness fixture per `docs/conventions/08-DETECTOR-LIVENESS.md`: a string constant holding a synthetic reader body with the old predicate, which the same detector must reject.

### 7.8 Movements and GL

Every movement goes through `StockAdjustmentService::receive` (`apps/api/app/Modules/Inventory/Domain/Services/StockAdjustmentService.php:115`) or `::issue` (`:253`), inside the receipt transaction, under the up-front product locks. The movement reason is a function of the **action column**, never of the declared discrepancy reason.

| Action / quantity | Movement(s) | Reason / GL |
|---|---|---|
| receipt — received (good) | destination `receive($qty, reference: $transferNumber, batchId: $batchId)` then `markMovementAsTransfer($movement, MovementType::TransferIn, $transferId)` | `TransferIn`: **no GL**; WAC untouched |
| receipt — damaged | the same receive + mark, then destination `issue($qty, reference: $receiptNumber, batchId: $batchId, reason: MovementReason::Damage, unitCost: Product::resolveMovementUnitCost(...), referenceType: StockMovementReferenceType::StockTransferReceipt, referenceId: $receiptId)` | **always `Damage`**: GL, Shrinkage → Dr Shrinkage / Cr Inventory |
| close `write_off` | receive + mark, then `issue(... reason: MovementReason::WriteOff ...)` with the same reference type/id | **always `WriteOff`**: GL, Shrinkage |
| close `return_to_source` | **source** `receive($qty, reference: $transferNumber.'-RETURN', batchId: $batchId)` + mark `TransferIn`, through the extracted `restockAtSource()` | `TransferIn`: **no GL** |

- `StockMovementReferenceType` gains one case, `StockTransferReceipt = 'stock_transfer_receipt'`, used **only** by the two destructive movements. `TransferIn`/`TransferOut` keep `reference_type = StockTransfer::class` exactly as `markMovementAsTransfer` writes it (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:745`).
- **Movement identity.** A lot-less line produces one movement per kind with quantity > 0, whose id is stored on the **receipt line**. A lot-tracked line produces one movement per lot row per kind with quantity > 0 (each call carrying that lot's `batchId`), whose ids are stored on the **lot rows**, with the three parent columns NULL — enforced by the CHECK `stock_transfer_receipt_lines_lot_lines_have_no_parent_movement`.
- **Land-then-scrap rationale.** The remainder is derived, not a `stock_levels` row, so the `TransferIn` gives the destination row the units the scrap then removes: net on-hand 0 for those units, one destructive movement, one GL leg (I2). This is what keeps seam 4 true.
- **`StockTransferMovementSupport::restockAtSource(StockTransfer $transfer, StockTransferLine $line, string $quantity, array $lotQuantities, string $userId, string $reference): array`** is extracted from the cancel restock loop at `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:451-480` and called by **both** `StockTransferService::cancel()` and the `return_to_source` disposition in `StockTransferReceiptService::close()`. It returns the created movement ids keyed by `batch_id`, or under the single key `''` for a lot-less line. One writer, two callers. Its complete signature, body and the full list of files the extraction modifies are in **§6.7b** (gate r1 M3) — it is not "reused, unchanged": `restockAtSource` did not exist as a method before this lane, and the four helpers rev 1 called "reused" (`lockTransfer`, `markMovementAsTransfer`, `capitalizeTransferCost`, `computeAllocationWeights`) were `private` and are **moved** to the same collaborator.
- `lockTransfer()`, `markMovementAsTransfer()` and `lineProductIds()` in this section are always `$this->movementSupport-><method>(...)` in both writers. `StockTransferService` no longer declares any of them.

GL bridge (D-28 buffer; the receipt service is the composite root):

- `InventoryGlPostingBuffer::mark()` at entry, `enqueue(MovementGlContext)` per destructive movement, `flushIfOutermost()` inside the root transaction after the body, `rollbackTo($marker)` on an earlier failure.
- The buffer registers a leak alarm and returns `[]` at `DB::transactionLevel() > 1` (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingBuffer.php:60`) and throws only outside any transaction (`:66`), so **the receipt service must be the outermost transaction**. T13 asserts no leak alarm.
- **Lot-less line:** `MovementGlKind::Exit` → `postForExit`, which resolves the shrinkage counter (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:185`). Context shaped as `apps/api/app/Modules/POS/Application/Services/ReturnScrapWriteOffService.php:169`, minus `batchNumber`/`productId`.
- **Lot line:** `MovementGlKind::BatchWriteOff` with the real `batchNumber` and `productId`, one context per lot movement; `postForBatchWriteOff` throws without them (`apps/api/app/Modules/Inventory/Application/Services/InventoryGlPostingService.php:95`) and performs **no** valuation-mode check, which is exactly why rule 4 preflights `requirePerpetual` at the receipt root for both paths. `postForBatchWriteOff` itself is not modified.
- A non-positive resolved unit cost warns and posts nothing.
- `BatchWriteOffService::writeOff` is **not** used by this lane.
- Direct `postFor*` calls fail PHPStan (`apps/api/app/PHPStan/Rules/InventoryGlPostingViaBufferOnly.php:17`).

`return_to_source` posts **no** journal at all (OQ-1, B14). T4b asserts `journal_entries` count is unchanged across the whole close.

### 7.9 Stored events

**Mechanism (why `event()` is not enough).** Spatie's wildcard subscriber stores a `ShouldBeStored` event with **no** uuid, and the Eloquent repository writes `aggregate_uuid` only from its `$uuid` argument. Our `DomainEvent` constructor uuid therefore never reaches the row on a direct dispatch. So:

- `StockTransferReceiptService` constructor-injects `Spatie\EventSourcing\StoredEvents\Repositories\StoredEventRepository` (the interface; bound to `EloquentStoredEventRepository` by `apps/api/config/event-sourcing.php:74`).
- Inside the receipt transaction, after the rows are written, it calls `persist($headerEvent, $receiptId)` then `persist($lineEvent, $receiptId)` per line — **aggregate uuid = the receipt id for the header and every line event**, one stream per receipt.
- Before persisting it sets `setAggregateRootVersion(1)` on the header and `2..N+1` on the lines in body order, so the unique `(aggregate_uuid, aggregate_version)` (`apps/api/database/migrations/tenant/2025_11_30_102448_create_stored_events_table.php:23`) makes a second stream for the same receipt id impossible.
- After each `persist`, `$storedEvent->handle()` runs sync projectors/reactors exactly as the subscriber would; there are none in `apps/api/app/` today.
- The events are **not** passed through `event()` — the subscriber would store a second, anchor-less copy. `dispatch_events_from_aggregate_roots` stays `false` (`apps/api/config/event-sourcing.php:147`).

Header events, one per posted document, aggregate uuid `receiptId`, version 1:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class StockTransferReceivedV1 extends DomainEvent
{
    /**
     * @param  list<int>  $lineEventVersions
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $transferNumber,
        public readonly string $receiptNumber,
        public readonly int $sequence,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $receivedByUserId,
        public readonly bool $isBlind,
        public readonly bool $hasDiscrepancy,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly int $lineCount,
        public readonly string $totalReceived,
        public readonly string $totalDamaged,
        public readonly ?string $receiptNotes,
        public readonly string $idempotencyKey,
        public readonly string $payloadHash,
        public readonly string $freightUncapitalized,
        public readonly array $lineEventVersions,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'inventory.stock_transfer.received.v1';
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class StockTransferClosedV1 extends DomainEvent
{
    /**
     * @param  list<int>  $lineEventVersions
     */
    public function __construct(
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $transferNumber,
        public readonly string $receiptNumber,
        public readonly int $sequence,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $closedByUserId,
        public readonly string $disposition,
        public readonly string $closeReason,
        public readonly ?string $closeNote,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly int $lineCount,
        public readonly string $totalWrittenOff,
        public readonly string $totalReturned,
        public readonly string $idempotencyKey,
        public readonly string $payloadHash,
        public readonly string $freightUncapitalized,
        public readonly array $lineEventVersions,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'inventory.stock_transfer.closed.v1';
    }
}
```

Per-line event, aggregate uuid `receiptId`, versions `2..N+1`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class StockTransferReceiptLineRecordedV1 extends DomainEvent
{
    /**
     * @param  list<array{receiptLotId: string, batchAllocationId: string, batchId: int, batchNumber: string, quantityReceived: string, quantityDamaged: string, quantityWrittenOff: string, quantityReturned: string, inMovementId: string|null, scrapMovementId: string|null, returnMovementId: string|null}>  $lots
     */
    public function __construct(
        public readonly string $receiptLineId,
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $transferLineId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $kind,
        public readonly ?string $disposition,
        public readonly int $sequence,
        public readonly string $sourceLocationId,
        public readonly string $destinationLocationId,
        public readonly string $actorUserId,
        public readonly string $actorRole,
        public readonly bool $isBlind,
        public readonly string $productId,
        public readonly ?string $variantId,
        public readonly bool $isLotTracked,
        public readonly string $quantitySent,
        public readonly string $quantityPreviouslyReceived,
        public readonly string $quantityPreviouslyDamaged,
        public readonly string $quantityReceived,
        public readonly string $quantityDamaged,
        public readonly string $quantityWrittenOff,
        public readonly string $quantityReturned,
        public readonly string $quantityRemainingAfter,
        public readonly ?string $discrepancyReason,
        public readonly ?string $discrepancyNote,
        public readonly ?string $inMovementId,
        public readonly ?string $scrapMovementId,
        public readonly ?string $returnMovementId,
        public readonly array $lots,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'inventory.stock_transfer.receipt_line.recorded.v1';
    }
}
```

All three parent movement ids are **NULL when `isLotTracked` is true**; `lots` is empty when the line is not lot-tracked, and each lot entry's movement id is non-null exactly when its own quantity for that kind is > 0.

In-process consumers get a plain, **non-stored** event dispatched in `DB::afterCommit` (precedent `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:606`):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class StockTransferReceiptPosted
{
    use Dispatchable;

    public function __construct(
        public readonly string $receiptId,
        public readonly string $transferId,
        public readonly string $tenantId,
        public readonly string $companyId,
        public readonly string $kind,
        public readonly ?string $disposition,
        public readonly bool $hasDiscrepancy,
        public readonly int $linesWithDiscrepancy,
        public readonly string $actorUserId,
        public readonly string $destinationLocationId,
        public readonly string $receiptNumber,
        public readonly string $transferNumber,
    ) {
    }
}
```

S1 dispatches it and registers **no** listener. S4 wires `NotifyOnTransferDiscrepancy` to it via `Event::listen` in `InventoryServiceProvider::boot` (the style at `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:92`); `EventServiceProvider::$listen` is not used for the stored classes.

**Replay and atomicity contract (T15).** From the three stored classes alone, in `stored_events.id` order, a rebuild reproduces `stock_transfer_receipts` (every column except `created_at`/`updated_at`, which the rebuilder sets to `occurredAt`; `status = 'posted'`), `stock_transfer_receipt_lines` (including `is_lot_tracked` and the parent movement ids), `stock_transfer_receipt_line_lots` (row ids and movement ids from `lots[]`), the four counters on lines and allocations, and `stock_transfers.status` / `closed_*` / `freight_uncapitalized`. Receipts of kind `legacy_completion` have **no** stream: the rebuilder skips receipt ids without events and T15 asserts those rows are untouched. `persist()` writes the `stored_events` row synchronously inside the receipt transaction, so rows and events commit or roll back together; movement events stay `DB::afterCommit` and are not part of this replay set, because the receipt events carry the movement ids themselves.

### 7.10 Pattern-detection reference query

Attribution rules (owner-confirmed): per-line receipt events record exactly what each receiver posted (`actorRole = 'receiver'`); a shortage discovered at close is a **transfer-level fact** whose line events carry `actorRole = 'closer'` and is never counted as the closer's mis-receiving nor attributed to the last receiver; the query is scoped per company; a line posted by several receivers counts for each with `shared_line = true`.

The query ships as a documented constant on `TransferReconciliationService` (`public const string PATTERN_DETECTION_SQL`) and is exercised by T16. No new table is needed: `event_class` is indexed and `event_properties` is `jsonb`.

```sql
WITH e AS (SELECT event_properties AS p FROM stored_events
           WHERE event_class = 'App\Modules\Inventory\Domain\Events\StockTransferReceiptLineRecordedV1'
             AND created_at >= now() - interval '90 days'),
postings AS (SELECT p->>'companyId' company_id, p->>'actorUserId' user_id, p->>'transferLineId' line_id,
                    (p->>'quantityDamaged')::numeric damaged FROM e WHERE p->>'actorRole' = 'receiver'),
receipts AS (SELECT company_id, user_id, line_id, COUNT(*) postings, SUM(damaged) damaged
             FROM postings GROUP BY 1, 2, 3),
closes AS (SELECT DISTINCT p->>'transferLineId' line_id
           FROM e WHERE p->>'actorRole' = 'closer'
             AND ((p->>'quantityWrittenOff')::numeric > 0 OR (p->>'quantityReturned')::numeric > 0)),
baseline AS (SELECT company_id, AVG((damaged > 0)::int) damage_rate FROM receipts GROUP BY 1)
SELECT r.company_id, r.user_id, COUNT(*) lines_posted, SUM(r.postings) postings,
       AVG((r.damaged > 0)::int) damage_rate, b.damage_rate company_damage_rate,
       COUNT(*) FILTER (WHERE c.line_id IS NOT NULL) lines_later_confirmed_short,
       bool_or((SELECT COUNT(*) FROM receipts r2 WHERE r2.line_id = r.line_id) > 1) shared_line
FROM receipts r JOIN baseline b USING (company_id) LEFT JOIN closes c USING (line_id)
WHERE r.company_id = :company_id GROUP BY 1, 2, b.damage_rate ORDER BY lines_later_confirmed_short DESC, damage_rate DESC;
```

**Grain.** The metric is line-based: `receipts` collapses every posting by the same receiver on the same transfer line into one row before any rate is computed, so `lines_posted`, `damage_rate`, the company baseline and `lines_later_confirmed_short` each count a `(receiver, transfer line)` pair once however many partial receipts that receiver posted; `postings` reports volume beside it. `damaged` per pair is the SUM over that receiver's postings. `closes` is `DISTINCT` per line so the left join cannot multiply rows.

**Both dispositions count.** The `closes` CTE matches when **either** `quantityWrittenOff` or `quantityReturned` is positive, and the output column is `lines_later_confirmed_short` rather than "written off": a remainder returned to source is as much a supervisor-confirmed shortage as one written off, and a write-off-only predicate would let a company that always returns short remainders show a flat zero. A line carries only one disposition, so no line is double-counted. T16 runs the same fixture through the write-off-only predicate and asserts it would yield the wrong number — the assertion is live, not decorative.

### 7.11 Freight

Runs **once per transfer, at the terminal transition** (`completed` or either `closed_*`), after the status has been persisted (seam 3). `partially_received` is **not** terminal and never triggers capitalisation.

0. Persist the terminal status first, exactly as `apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:386` already does, so the in-transit re-read in `recordCostAdjustment` does not double count.
1. `goodTotal = Σ lines.quantity_received`, `sentTotal = Σ lines.quantity` (4-dp strings).
2. Working scale `w = max(ALLOCATION_SCALE, s + 4)` where `ALLOCATION_SCALE = 6` — moved in S1 from the private `StockTransferService::ALLOCATION_SCALE` (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:65-70`) to the **public** `StockTransferMovementSupport::ALLOCATION_SCALE` (§6.7b), so both writers read one constant — and `s = CurrencyScaleResolverInterface::getScaleSafe($company->currency_code, 3)` — the safe form, because the terminal transition may run without `CompanyContext` (CLAUDE.md rule 19).
3. `pool = CurrencyScale::bcformat(bcmul($transferCost, bcdiv($goodTotal, $sentTotal, $w), $w), 4)` — one rounding, at the stored scale of `transfer_cost`, truncating.
4. `freight_uncapitalized = bcsub($transferCost, $pool, 4)` → `stock_transfers.freight_uncapitalized`. Exact by construction (I7).
5. The existing allocation loop runs on `pool` instead of `transfer_cost`, with **landed weights**: `ProRataQuantity` → `quantity_received`; `ProRataValue` → `quantity_received × unit_cost_snapshot`; `EqualPerLine` → `1` for lines with `quantity_received > 0`, else `0`. Zero-weight lines are skipped and the **last cost-bearing** line absorbs the residual, so `Σ allocated_transfer_cost = pool` exactly. `recordCostAdjustment` per line, as today. The call is `$this->movementSupport->capitalizeTransferCost($transfer, $pool, $landedQuantityByLineId)` where `$landedQuantityByLineId` is `line id => (string) $line->quantity_received` after the counters of §7.5 rule 7 are written; the three `match` arms above are the body of `StockTransferMovementSupport::computeAllocationWeights()` as reproduced in §6.7b, and they are the **only** change to the moved weight logic (the pre-T-2 version weighted on `$line->quantity`, the shipped quantity).
6. Damaged, written-off and returned units are all outside `goodTotal`; their freight share is the residual — never capitalised, no journal, surfaced in reconciliation and carried on the header events.

**Worked example, asserted verbatim by T4** (`transfer_cost = 120.0000`, `pro_rata_quantity`, TND `s = 3`, `w = 7`). Lines: A 8 sent, B 4 sent. Receipt: A 5 good, B 4 good; close `write_off` of A's remaining 3. `goodTotal = 9`, `sentTotal = 12`; `bcdiv('9','12',7) = 0.7500000`; `pool = bcformat(90.0000000, 4) = 90.0000`; `freight_uncapitalized = 120.0000 − 90.0000 = 30.0000`. Allocation on landed weights 5 and 4: A `bcmul('90.0000', bcdiv('5','9',7), 7) = 49.9999995` → 4 dp `49.9999`; B, last, `90.0000 − 49.9999 = 40.0001`. Persisted: `A.allocated_transfer_cost = 49.9999`, `B = 40.0001`, `freight_uncapitalized = 30.0000`; `49.9999 + 40.0001 + 30.0000 = 120.0000`.

Rejected alternatives, recorded and not open: (a) "expense with shrinkage" — needs a new `SystemAccountPurpose`, provisioning in `InventoryVarianceAccountProvisioner` and every seeded country chart, and double-hits P&L unless the clearing account is reconciled against the freight invoice; (b) mode-consistent pool ratio — identical to the default under `pro_rata_quantity`.

### 7.12 Enums, models and DTOs

`TransferStatus` gains three cases and three methods; `isTerminal()`, `canBeCompleted()` and `label()` are extended; `canBeCancelled()` and `canBeInitiated()` are **unchanged**, so `partially_received` is not cancellable. Gate r1 M4 asked for the complete modified enum rather than a fragment, because this file is the source of the generated `TransferStatus` TypeScript union (`packages/shared/types/generated.d.ts:1240`, four members today, seven after S1) and the only exhaustiveness site in PHP. **The whole file after S1, replacing `apps/api/app/Modules/Inventory/Domain/Enums/TransferStatus.php` in place:**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

/**
 * Stock-transfer lifecycle states.
 *
 * draft                — created, no stock motion yet
 * in_transit           — source stock decremented, nothing received yet
 * partially_received   — at least one receipt posted, a positive remainder is still carrying
 * completed            — every unit accounted for by receipts; WAC capitalization applied
 * closed_with_writeoff — an open remainder was written off at destination (Shrinkage GL)
 * closed_returned      — an open remainder was returned to source (stock only, no GL)
 * cancelled            — voided; in_transit stock has been returned to source
 *
 * `closed_with_writeoff` is exactly 20 characters, which is the width of
 * `stock_transfers.status` (`apps/api/database/migrations/tenant/2026_05_28_120000_create_stock_transfers_table.php:42`),
 * so this enum grows with NO column alteration.
 */
enum TransferStatus: string
{
    case Draft = 'draft';
    case InTransit = 'in_transit';
    case PartiallyReceived = 'partially_received';
    case Completed = 'completed';
    case ClosedWithWriteoff = 'closed_with_writeoff';
    case ClosedReturned = 'closed_returned';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed
            || $this === self::Cancelled
            || $this === self::ClosedWithWriteoff
            || $this === self::ClosedReturned;
    }

    public function canBeInitiated(): bool
    {
        return $this === self::Draft;
    }

    public function canBeCompleted(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    /** Unchanged from the pre-T-2 body: `partially_received` is deliberately NOT cancellable. */
    public function canBeCancelled(): bool
    {
        return $this === self::Draft || $this === self::InTransit;
    }

    public function canReceive(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    public function canBeClosed(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    /** The PHP twin of StockTransfer::CARRYING_STATUSES (§7.7). */
    public function isCarrying(): bool
    {
        return $this === self::InTransit || $this === self::PartiallyReceived;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::InTransit => 'In Transit',
            self::PartiallyReceived => 'Partially Received',
            self::Completed => 'Completed',
            self::ClosedWithWriteoff => 'Closed (Written Off)',
            self::ClosedReturned => 'Closed (Returned)',
            self::Cancelled => 'Cancelled',
        };
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferReceiptKind: string
{
    case Receipt = 'receipt';
    case Close = 'close';
    /** Backfill only — never written by the service, never carried by an event. */
    case LegacyCompletion = 'legacy_completion';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferReceiptStatus: string
{
    case Posted = 'posted';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferCloseDisposition: string
{
    case WriteOff = 'write_off';
    case ReturnToSource = 'return_to_source';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferDiscrepancyReason: string
{
    case ShortShipped = 'short_shipped';
    case LostInTransit = 'lost_in_transit';
    case DamagedInTransit = 'damaged_in_transit';
    case Other = 'other';

    /**
     * A receiver declares physical damage, never shortness (owner ruling D2);
     * a closer may use any of the four.
     */
    public function allowedFor(TransferReceiptKind $kind): bool
    {
        return match ($kind) {
            TransferReceiptKind::Receipt => $this === self::DamagedInTransit || $this === self::Other,
            TransferReceiptKind::Close => true,
            TransferReceiptKind::LegacyCompletion => false,
        };
    }
}
```

There is deliberately **no** `movementReason()` on this enum: the movement reason derives from the action column (§7.8).

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferReceiptFailureReason: string
{
    case OverReceipt = 'OVER_RECEIPT';
    case LotOverReceipt = 'LOT_OVER_RECEIPT';
    case UnknownLot = 'UNKNOWN_LOT';
    case LotRequired = 'LOT_REQUIRED';
    case LotNotAllowed = 'LOT_NOT_ALLOWED';
    case LotSumMismatch = 'LOT_SUM_MISMATCH';
    case LotTrackingMismatch = 'LOT_TRACKING_MISMATCH';
    case LineNotOnTransfer = 'LINE_NOT_ON_TRANSFER';
    case NothingToReceive = 'NOTHING_TO_RECEIVE';
    case DiscrepancyReasonRequired = 'DISCREPANCY_REASON_REQUIRED';
    case DiscrepancyReasonInvalid = 'DISCREPANCY_REASON_INVALID';
    case IdempotencyKeyReused = 'IDEMPOTENCY_KEY_REUSED';
    case DispositionRequired = 'DISPOSITION_REQUIRED';
    case BlindRequiresCountedReceipt = 'BLIND_REQUIRES_COUNTED_RECEIPT';
    case ValuationModeUnsupported = 'VALUATION_MODE_UNSUPPORTED';

    /** Static sentences only: no quantity, no transfer field, ever. */
    public function message(): string
    {
        return match ($this) {
            self::OverReceipt => 'The submitted quantity exceeds what is still open on this line.',
            self::LotOverReceipt => 'The submitted quantity exceeds what is still open on this lot.',
            self::UnknownLot => 'That lot was not shipped on this line.',
            self::LotRequired => 'This line is lot-tracked and requires lot detail.',
            self::LotNotAllowed => 'This line is not lot-tracked and accepts no lot detail.',
            self::LotSumMismatch => 'The lot quantities do not add up to the line quantities.',
            self::LotTrackingMismatch => 'The lot tracking of this line no longer matches the product.',
            self::LineNotOnTransfer => 'That line does not belong to this transfer.',
            self::NothingToReceive => 'There is nothing left to receive on this line.',
            self::DiscrepancyReasonRequired => 'A discrepancy reason is required when damage is declared.',
            self::DiscrepancyReasonInvalid => 'That discrepancy reason is not allowed for this action.',
            self::IdempotencyKeyReused => 'That idempotency key was already used for a different request.',
            self::DispositionRequired => 'A close disposition is required.',
            self::BlindRequiresCountedReceipt => 'This transfer must be received with counted quantities.',
            self::ValuationModeUnsupported => 'This action requires perpetual inventory valuation.',
        };
    }
}
```

`BlindRequiresCountedReceipt` is defined in S1 (it is one enum, one file) but is **thrown only by S2** (§8.6). S1 asserts nothing about it.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Enums;

enum TransferActorRole: string
{
    case Receiver = 'receiver';
    case Closer = 'closer';
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain\Exceptions;

use App\Modules\Inventory\Domain\Enums\TransferReceiptFailureReason;
use RuntimeException;

final class TransferReceiptFailureException extends RuntimeException
{
    /**
     * @param  array{transfer_line_id?: string, batch_id?: int}  $details
     */
    public function __construct(
        public readonly TransferReceiptFailureReason $reason,
        public readonly array $details = [],
    ) {
        parent::__construct($reason->message());
    }
}
```

**Model contract (§3.1b).** Every column below carries a `@property numeric-string` annotation and a `decimal:4` cast; no accessor, service method or DTO member for them is typed `float`.

| Model | `@property` additions | `$fillable` additions | `casts()` additions |
|---|---|---|---|
| `StockTransferLine` | `quantity_received`, `quantity_damaged`, `quantity_written_off`, `quantity_returned` (all `numeric-string`) | the same four | each `=> 'decimal:4'` |
| `StockTransferLineBatchAllocation` | the same four | the same four | each `=> 'decimal:4'` |
| `StockTransfer` | `numeric-string $freight_uncapitalized`; `string\|null $closed_by_user_id`; `Carbon\|null $closed_at`; `TransferCloseDisposition\|null $close_disposition`; `TransferDiscrepancyReason\|null $close_reason`; `string\|null $close_note` | all six | `'freight_uncapitalized' => 'decimal:4'`, `'closed_at' => 'datetime'`, `'close_disposition' => TransferCloseDisposition::class`, `'close_reason' => TransferDiscrepancyReason::class` |
| `StockTransferReceipt` (new) | `int $sequence`, `bool $is_blind`, `bool $has_discrepancy`, `TransferReceiptKind $kind`, `TransferCloseDisposition\|null $disposition`, `TransferReceiptStatus $status`, `Carbon $received_at` | every §7.3 column except `id` | `'sequence' => 'integer'`, `'is_blind' => 'boolean'`, `'has_discrepancy' => 'boolean'`, enum casts for `kind`/`disposition`/`status`, `'received_at' => 'datetime'` |
| `StockTransferReceiptLine` (new) | the five quantities (`numeric-string`), `bool $is_lot_tracked`, `TransferDiscrepancyReason\|null $discrepancy_reason` | every column except `id` | the five `=> 'decimal:4'`, `'is_lot_tracked' => 'boolean'`, `'discrepancy_reason' => TransferDiscrepancyReason::class` |
| `StockTransferReceiptLineLot` (new) | the four quantities (`numeric-string`), `int $batch_id` | every column except `id` | the four `=> 'decimal:4'`, `'batch_id' => 'integer'` |

Both `StockTransferLine::remainingQuantity(): string` and `StockTransferLineBatchAllocation::remainingQuantity(): string` are `bcsub` chains at `QuantityScale::SCALE`, returning a 4-dp string.

DTOs (all `#[TypeScript]`, all Spatie `Data`, quantities `public string`). **Gate r1 M2 — the serialization mechanism, decided and stated.** Spec §5.0b requires `quantity_written_off` and `quantity_returned` to be **absent** from a `kind = receipt` echo. An ordinary nullable Spatie `Data` property always serialises as a key with a `null` value, which is a different statement about the world and fails T9's `K_receipt` scan. Rev 2 makes the members **structurally absent** by giving `close` its own DTO family:

- **Chosen mechanism: two disjoint DTO families.** `StockTransferReceiptData` / `StockTransferReceiptLineData` / `StockTransferReceiptLineLotData` for `kind ∈ {receipt, legacy_completion}` — these classes **declare no such properties**, so `array_key_exists('quantity_written_off', …)` is false at every depth by construction, in the JSON, in the generated TypeScript and in `->toArray()`. `TransferCloseReceiptData` / `TransferCloseLineData` / `TransferCloseLineLotData` for `kind = close` — these declare all four counters as non-nullable `public string`.
- **Rejected: Spatie `Optional`.** It would work at runtime, but it makes every consumer's generated TypeScript member optional (`quantity_written_off?: string`) on the **close** payload too, where the value is always present, and it puts the absence decision inside a value object instead of inside the type. Two classes state the contract in the type system, which is what convention 11 and CLAUDE.md rule 3 ask for. **No `Optional` is used anywhere in this lane**, so no vendor behaviour needs to be cited to justify one.
- **One selection site:** `StockTransferController::receiptPayload()` (§7.6), keyed on `$receipt->kind`. Nothing else builds a receipt payload, and no code adds or removes a key from either family's output.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferReceiptLineLot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A lot row of a kind=receipt (or kind=legacy_completion) receipt.
 *
 * NEVER ADD quantity_written_off / quantity_returned HERE: their ABSENCE on a
 * receipt echo is the §5.0b contract, and TransferBlindLeakOracleTest asserts it
 * structurally (`K_receipt`, §9.6). The close family carries them instead.
 */
#[TypeScript]
final class StockTransferReceiptLineLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public string $quantity_received,
        public string $quantity_damaged,
    ) {
    }

    public static function fromModel(StockTransferReceiptLineLot $lot): self
    {
        return new self(
            batch_id: (string) $lot->batch_id,
            batch_number: (string) $lot->batch->batch_number,
            quantity_received: (string) $lot->quantity_received,
            quantity_damaged: (string) $lot->quantity_damaged,
        );
    }
}
```

`batch_id` is emitted as a **string** even though the column is `unsignedBigInteger` (`apps/api/database/migrations/tenant/2026_01_05_150000_create_product_batches_table.php:14`), so a 64-bit id cannot lose precision in JavaScript. The **request** side keeps `integer` (§7.5 rule A5), because that is a client-supplied lookup key, not a rendered identity.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\StockTransferReceiptLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of a kind=receipt (or kind=legacy_completion) receipt.
 *
 * NEVER ADD quantity_written_off / quantity_returned / quantity_sent_snapshot /
 * in_movement_id / scrap_movement_id / return_movement_id HERE — the first two
 * are close-only (§5.0b) and the last four are internal (`K_receipt`, §9.6).
 */
#[TypeScript]
final class StockTransferReceiptLineData extends Data
{
    /**
     * @param  list<StockTransferReceiptLineLotData>  $lots
     */
    public function __construct(
        public string $id,
        public string $transfer_line_id,
        public string $product_id,
        public ?string $variant_id,
        public bool $is_lot_tracked,
        public string $quantity_received,
        public string $quantity_damaged,
        public ?TransferDiscrepancyReason $discrepancy_reason,
        public ?string $discrepancy_note,
        public array $lots,
    ) {
    }

    public static function fromModel(StockTransferReceiptLine $line): self
    {
        return new self(
            id: (string) $line->id,
            transfer_line_id: (string) $line->transfer_line_id,
            product_id: (string) $line->product_id,
            variant_id: $line->variant_id === null ? null : (string) $line->variant_id,
            is_lot_tracked: (bool) $line->is_lot_tracked,
            quantity_received: (string) $line->quantity_received,
            quantity_damaged: (string) $line->quantity_damaged,
            discrepancy_reason: $line->discrepancy_reason,
            discrepancy_note: $line->discrepancy_note,
            lots: $line->lots->map(StockTransferReceiptLineLotData::fromModel(...))->all(),
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferReceiptStatus;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Header of a kind=receipt (or kind=legacy_completion) receipt.
 *
 * `disposition` is deliberately ABSENT: it is close-only and lives on
 * TransferCloseReceiptData. `kind` is still emitted, so a client can discriminate.
 */
#[TypeScript]
final class StockTransferReceiptData extends Data
{
    /**
     * @param  array{id: string, name: string}  $received_by
     * @param  list<StockTransferReceiptLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $receipt_number,
        public TransferReceiptKind $kind,
        public int $sequence,
        public TransferReceiptStatus $status,
        public bool $is_blind,
        public bool $has_discrepancy,
        public array $received_by,
        public string $received_at,
        public ?string $notes,
        public array $lines,
    ) {
    }

    public static function fromModel(StockTransferReceipt $receipt): self
    {
        return new self(
            id: (string) $receipt->id,
            receipt_number: (string) $receipt->receipt_number,
            kind: $receipt->kind,
            sequence: (int) $receipt->sequence,
            status: $receipt->status,
            is_blind: (bool) $receipt->is_blind,
            has_discrepancy: (bool) $receipt->has_discrepancy,
            received_by: ['id' => (string) $receipt->received_by_user_id, 'name' => (string) $receipt->receivedBy?->name],
            received_at: $receipt->received_at->toIso8601String(),
            notes: $receipt->notes,
            lines: $receipt->lines->map(StockTransferReceiptLineData::fromModel(...))->all(),
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferReceiptLineLot;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** A lot row of a kind=close receipt. All four counters are present and non-null. */
#[TypeScript]
final class TransferCloseLineLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public string $quantity_received,
        public string $quantity_damaged,
        public string $quantity_written_off,
        public string $quantity_returned,
    ) {
    }

    public static function fromModel(StockTransferReceiptLineLot $lot): self
    {
        return new self(
            batch_id: (string) $lot->batch_id,
            batch_number: (string) $lot->batch->batch_number,
            quantity_received: (string) $lot->quantity_received,
            quantity_damaged: (string) $lot->quantity_damaged,
            quantity_written_off: (string) $lot->quantity_written_off,
            quantity_returned: (string) $lot->quantity_returned,
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\StockTransferReceiptLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A line of a kind=close receipt. A close is authored by a holder of
 * `inventory.transfers.reconcile`, so `canSeeExpected` is unconditionally true
 * for its author and the sent snapshot is legitimately carried here.
 */
#[TypeScript]
final class TransferCloseLineData extends Data
{
    /**
     * @param  list<TransferCloseLineLotData>  $lots
     */
    public function __construct(
        public string $id,
        public string $transfer_line_id,
        public string $product_id,
        public ?string $variant_id,
        public bool $is_lot_tracked,
        public string $quantity_sent_snapshot,
        public string $quantity_received,
        public string $quantity_damaged,
        public string $quantity_written_off,
        public string $quantity_returned,
        public ?TransferDiscrepancyReason $discrepancy_reason,
        public ?string $discrepancy_note,
        public array $lots,
    ) {
    }

    public static function fromModel(StockTransferReceiptLine $line): self
    {
        return new self(
            id: (string) $line->id,
            transfer_line_id: (string) $line->transfer_line_id,
            product_id: (string) $line->product_id,
            variant_id: $line->variant_id === null ? null : (string) $line->variant_id,
            is_lot_tracked: (bool) $line->is_lot_tracked,
            quantity_sent_snapshot: (string) $line->quantity_sent_snapshot,
            quantity_received: (string) $line->quantity_received,
            quantity_damaged: (string) $line->quantity_damaged,
            quantity_written_off: (string) $line->quantity_written_off,
            quantity_returned: (string) $line->quantity_returned,
            discrepancy_reason: $line->discrepancy_reason,
            discrepancy_note: $line->discrepancy_note,
            lots: $line->lots->map(TransferCloseLineLotData::fromModel(...))->all(),
        );
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use App\Modules\Inventory\Domain\Enums\TransferReceiptStatus;
use App\Modules\Inventory\Domain\StockTransferReceipt;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Header of a kind=close receipt. `disposition` and `close_reason` exist only here. */
#[TypeScript]
final class TransferCloseReceiptData extends Data
{
    /**
     * @param  array{id: string, name: string}  $received_by
     * @param  list<TransferCloseLineData>  $lines
     */
    public function __construct(
        public string $id,
        public string $receipt_number,
        public TransferReceiptKind $kind,
        public TransferCloseDisposition $disposition,
        public TransferDiscrepancyReason $close_reason,
        public ?string $close_note,
        public int $sequence,
        public TransferReceiptStatus $status,
        public bool $is_blind,
        public bool $has_discrepancy,
        public array $received_by,
        public string $received_at,
        public ?string $notes,
        public string $freight_uncapitalized,
        public array $lines,
    ) {
    }

    public static function fromModel(StockTransferReceipt $receipt): self
    {
        return new self(
            id: (string) $receipt->id,
            receipt_number: (string) $receipt->receipt_number,
            kind: $receipt->kind,
            disposition: $receipt->disposition ?? throw new \LogicException('A close receipt always carries a disposition.'),
            close_reason: $receipt->transfer->close_reason ?? throw new \LogicException('A close receipt always carries a reason.'),
            close_note: $receipt->transfer->close_note,
            sequence: (int) $receipt->sequence,
            status: $receipt->status,
            is_blind: (bool) $receipt->is_blind,
            has_discrepancy: (bool) $receipt->has_discrepancy,
            received_by: ['id' => (string) $receipt->received_by_user_id, 'name' => (string) $receipt->receivedBy?->name],
            received_at: $receipt->received_at->toIso8601String(),
            notes: $receipt->notes,
            freight_uncapitalized: (string) $receipt->transfer->freight_uncapitalized,
            lines: $receipt->lines->map(TransferCloseLineData::fromModel(...))->all(),
        );
    }
}
```

**`TransferReconciliationData` and its three nested DTOs, in full** (gate r1 M4 — rev 1 described this shape in prose only, and it is a cross-layer contract the web consumes as a generated type in S4):

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One lot row of one reconciliation line. Every quantity is a 4-dp string. */
#[TypeScript]
final class TransferReconciliationLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public ?string $expiry_date,
        public string $sent,
        public string $received,
        public string $damaged,
        public string $written_off,
        public string $returned,
        public string $remaining,
        public string $variance,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One transfer line, reconciled.
 *
 * `variance` is `received − sent` at 4 dp and is NEGATIVE for a shortfall; it is
 * a derived presentation value, never a stored column.
 */
#[TypeScript]
final class TransferReconciliationLineData extends Data
{
    /**
     * @param  list<TransferReconciliationLotData>  $lots
     * @param  list<TransferDiscrepancyReason>  $discrepancy_reasons
     */
    public function __construct(
        public string $transfer_line_id,
        public string $product_id,
        public string $product_name,
        public string $product_sku,
        public ?string $variant_id,
        public int $unit_decimal_places,
        public bool $is_lot_tracked,
        public string $sent,
        public string $received,
        public string $damaged,
        public string $written_off,
        public string $returned,
        public string $remaining,
        public string $variance,
        public array $discrepancy_reasons,
        public array $lots,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferReceiptKind;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** One posted document in the transfer's receipt history. Carries no quantity. */
#[TypeScript]
final class TransferReconciliationReceiptData extends Data
{
    /**
     * @param  array{id: string, name: string}  $received_by
     */
    public function __construct(
        public string $receipt_number,
        public TransferReceiptKind $kind,
        public ?TransferCloseDisposition $disposition,
        public int $sequence,
        public array $received_by,
        public string $received_at,
        public bool $is_blind,
        public bool $has_discrepancy,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Transfer-level totals. Quantities are 4-dp strings; freight is at transfer_cost's stored scale. */
#[TypeScript]
final class TransferReconciliationSummaryData extends Data
{
    public function __construct(
        public int $lines,
        public int $lines_with_discrepancy,
        public string $total_sent,
        public string $total_received,
        public string $total_damaged,
        public string $total_written_off,
        public string $total_returned,
        public string $total_remaining,
        public string $freight_uncapitalized,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The `GET /stock-transfers/{transfer}/reconciliation` body (§5.4).
 *
 * Built ONLY by TransferReconciliationService. The route is gated on
 * `inventory.transfers.reconcile`, so its reader is by definition a
 * `canSeeExpected() = true` actor and this DTO carries expected quantities
 * on purpose; it is NEVER returned from any other endpoint.
 */
#[TypeScript]
final class TransferReconciliationData extends Data
{
    /**
     * @param  list<TransferReconciliationLineData>  $lines
     * @param  list<TransferReconciliationReceiptData>  $receipts
     */
    public function __construct(
        public string $transfer_id,
        public string $transfer_number,
        public TransferStatus $status,
        public string $source_location_id,
        public string $source_location_name,
        public string $destination_location_id,
        public string $destination_location_name,
        public string $initiated_at,
        public ?string $closed_at,
        public ?TransferCloseDisposition $close_disposition,
        public ?TransferDiscrepancyReason $close_reason,
        public ?string $close_note,
        public array $lines,
        public array $receipts,
        public TransferReconciliationSummaryData $summary,
    ) {
    }
}
```

Every quantity above is `public string` and every count is `public int`; nothing is `float` and no member is `mixed` (CLAUDE.md rules 3 and 19). All **eleven** DTOs above carry `#[TypeScript]` — three receipt, three close, and `TransferReconciliationData` plus its four nested classes — so `php artisan typescript:transform` emits them into `packages/shared/types/generated.d.ts` in S1 and the web consumes them in S4 (§10.4). (Gate r2 M6: rev 2's §11.1 called this enumerated list "the ten DTOs" while listing eleven; the count is **eleven**, and §11.1 now says so.) `TransferReconciliationData` as reproduced above is S1's **fifteen**-member shape; S2 adds a sixteenth, `visibility_version`, and the complete post-S2 file is reproduced in §8.7b.

### 7.12b The three full transfer entity DTOs (closes gate r2 BLOCKER B1)

**The defect.** Rev 2's §10.4 deleted six hand-written web entity shadows and imported `StockTransferData`, `StockTransferLineData` and `StockTransferLineBatchAllocationData` in their place, then used those names in `StockTransferListResponse`, `StockTransferShowResponse`, `StockTransferReceiveResponse` and `StockTransferCloseResponse`. At the planning HEAD **none of the three exists**: `packages/shared/types/generated.d.ts` carries the transfer *enums* only, no PHP class under `apps/api/app` carries those names, and `TransferPayloadBuilder` was specified as an application service returning an `array`, which Spatie's transformer cannot see. S4 could not have compiled.

**Which slice owns them.** Spec §5.3 makes `TransferPayloadBuilder` the single emitter of the full transfer projection, and this plan creates that builder in **S1** (§7.2), which is also the slice that deletes `StockTransferController::formatTransfer()` and re-points `index` and `show`. **S1 therefore creates all three DTOs**, in the same commit group as the builder (§7.15 step 10), and S1's `php artisan typescript:transform` (§13) is what puts them into `packages/shared/types/generated.d.ts` before S2, S3 or S4 runs. S2 later adds two members to `StockTransferData` only (§8.6), which is why that one file — and only that one — is in the cross-push contention register §11.6.

**The builder returns the DTO, not an array.** `TransferPayloadBuilder::build(StockTransfer $transfer, bool $includeLines = false): StockTransferData` is the whole of the mapping seam: it is the only place a `StockTransfer` entity becomes a wire shape, `StockTransferController::index`/`show`/`receive`/`close`/`complete`/`cancel` call it and nothing else, and because it returns a `#[TypeScript]` `Data` class the transformer generates the interface from the PHP source rather than from prose.

`apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineBatchAllocationData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferLineBatchAllocation;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One shipped lot of one transfer line, plus its four T-2 receipt counters and
 * the derived per-lot remainder.
 *
 * `expiry_status` is the STRING value of BatchExpiry's ExpiryStatus enum, not the
 * enum type: today's controller already emits `$allocation->batch->expiryStatus()->value`
 * (apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:329),
 * and typing the member as the foreign enum would import a BatchExpiry symbol into an
 * Inventory DTO for no wire benefit. The emitted JSON is byte-identical to today's.
 */
#[TypeScript]
final class StockTransferLineBatchAllocationData extends Data
{
    /**
     * @param  numeric-string  $quantity  the shipped allocation
     * @param  numeric-string  $quantity_received
     * @param  numeric-string  $quantity_damaged
     * @param  numeric-string  $quantity_written_off
     * @param  numeric-string  $quantity_returned
     * @param  numeric-string  $quantity_remaining  quantity − received − damaged − written_off − returned, at 4 dp
     */
    public function __construct(
        public readonly string $id,
        public readonly int $batch_id,
        public readonly string $batch_number,
        public readonly ?string $expiry_date,
        public readonly string $expiry_status,
        public readonly bool $can_be_sold,
        public readonly string $quantity,
        public readonly string $quantity_received,
        public readonly string $quantity_damaged,
        public readonly string $quantity_written_off,
        public readonly string $quantity_returned,
        public readonly string $quantity_remaining,
    ) {
    }

    public static function fromModel(StockTransferLineBatchAllocation $allocation): self
    {
        return new self(
            id: (string) $allocation->id,
            batch_id: (int) $allocation->batch_id,
            batch_number: (string) $allocation->batch->batch_number,
            expiry_date: $allocation->batch->expiry_date?->toDateString(),
            expiry_status: $allocation->batch->expiryStatus()->value,
            can_be_sold: $allocation->batch->canBeSold(),
            quantity: (string) $allocation->quantity,
            quantity_received: (string) $allocation->quantity_received,
            quantity_damaged: (string) $allocation->quantity_damaged,
            quantity_written_off: (string) $allocation->quantity_written_off,
            quantity_returned: (string) $allocation->quantity_returned,
            quantity_remaining: $allocation->remainingQuantity(),
        );
    }
}
```

`apps/api/app/Modules/Inventory/Application/DTOs/StockTransferLineData.php`:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\StockTransferLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of the FULL transfer projection (§5.3 TransferPayloadBuilder).
 *
 * This shape is served only to an actor for whom canSeeExpected() is true: it
 * carries the sent quantity, the unit cost snapshot and the allocated freight.
 * The receiver projection is a DIFFERENT class, TransferReceiverLineData (§8.6b),
 * which declares none of those members.
 */
#[TypeScript]
final class StockTransferLineData extends Data
{
    /**
     * @param  numeric-string  $quantity  the sent quantity
     * @param  numeric-string|null  $unit_cost_snapshot
     * @param  numeric-string  $allocated_transfer_cost
     * @param  numeric-string  $quantity_received
     * @param  numeric-string  $quantity_damaged
     * @param  numeric-string  $quantity_written_off
     * @param  numeric-string  $quantity_returned
     * @param  numeric-string  $quantity_remaining
     * @param  list<StockTransferLineBatchAllocationData>  $batch_allocations
     */
    public function __construct(
        public readonly string $id,
        public readonly string $product_id,
        public readonly ?string $product_name,
        public readonly ?string $product_sku,
        public readonly ?string $variant_id,
        public readonly ?string $variant_sku,
        public readonly ?string $variant_name,
        public readonly string $quantity,
        public readonly int $quantity_decimals,
        public readonly ?string $unit_cost_snapshot,
        public readonly string $allocated_transfer_cost,
        public readonly string $quantity_received,
        public readonly string $quantity_damaged,
        public readonly string $quantity_written_off,
        public readonly string $quantity_returned,
        public readonly string $quantity_remaining,
        public readonly array $batch_allocations,
    ) {
    }

    public static function fromModel(StockTransferLine $line): self
    {
        $product = $line->relationLoaded('product') ? $line->product : null;

        return new self(
            id: (string) $line->id,
            product_id: (string) $line->product_id,
            product_name: $product?->name,
            product_sku: $product?->sku,
            variant_id: $line->variant_id,
            variant_sku: $line->variant->sku ?? null,
            variant_name: $line->variant->name_suffix ?? null,
            quantity: (string) $line->quantity,
            quantity_decimals: $product?->unitOfMeasure->decimal_places ?? 4,
            unit_cost_snapshot: $line->unit_cost_snapshot === null ? null : (string) $line->unit_cost_snapshot,
            allocated_transfer_cost: (string) $line->allocated_transfer_cost,
            quantity_received: (string) $line->quantity_received,
            quantity_damaged: (string) $line->quantity_damaged,
            quantity_written_off: (string) $line->quantity_written_off,
            quantity_returned: (string) $line->quantity_returned,
            quantity_remaining: $line->remainingQuantity(),
            batch_allocations: $line->batchAllocations
                ->map(static fn (StockTransferLineBatchAllocation $a): StockTransferLineBatchAllocationData => StockTransferLineBatchAllocationData::fromModel($a))
                ->all(),
        );
    }
}
```

`apps/api/app/Modules/Inventory/Application/DTOs/StockTransferData.php` — **the S1 shape; S2 appends `blind` and `visibility_version` and the complete post-S2 constructor is reproduced in §8.6**:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferCostDistribution;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use App\Modules\Inventory\Domain\Enums\TransferType;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Inventory\Domain\StockTransferLine;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The FULL transfer projection (spec §5.3). Built ONLY by TransferPayloadBuilder.
 *
 * Members 1-24 are the exact shape StockTransferController::formatTransfer()
 * emits today (apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php:282-340),
 * key for key and type for type, so S1 changes no existing wire member. Members
 * 25-33 are the T-2 additions of §5.3: the close columns, the freight residual,
 * the receipt history and the lines.
 *
 * `lines` is NULL on the list route and a list on the show route: today's
 * formatTransfer OMITS the key entirely when $includeLines is false, and a Spatie
 * Data property always serialises, so the list body gains one member with the
 * value null. That is a deliberate, stated addition (a null means "not loaded",
 * never "no lines"); no existing member changes and nothing is removed.
 */
#[TypeScript]
final class StockTransferData extends Data
{
    /**
     * @param  numeric-string  $transfer_cost
     * @param  numeric-string  $freight_uncapitalized
     * @param  list<TransferReconciliationReceiptData>  $receipts
     * @param  list<StockTransferLineData>|null  $lines
     */
    public function __construct(
        public readonly string $id,
        public readonly string $transfer_number,
        public readonly TransferType $transfer_type,
        public readonly TransferStatus $status,
        public readonly string $source_location_id,
        public readonly ?string $source_location_name,
        public readonly string $destination_location_id,
        public readonly ?string $destination_location_name,
        public readonly ?string $notes,
        public readonly string $transfer_cost,
        public readonly ?string $transfer_cost_label,
        public readonly TransferCostDistribution $transfer_cost_distribution,
        public readonly string $initiated_by_user_id,
        public readonly ?string $initiated_by_name,
        public readonly ?string $completed_by_user_id,
        public readonly ?string $completed_by_name,
        public readonly ?string $cancelled_by_user_id,
        public readonly ?string $cancelled_by_name,
        public readonly ?string $initiated_at,
        public readonly ?string $completed_at,
        public readonly ?string $cancelled_at,
        public readonly ?string $cancellation_reason,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly ?string $closed_by_user_id,
        public readonly ?string $closed_by_name,
        public readonly ?string $closed_at,
        public readonly ?TransferCloseDisposition $close_disposition,
        public readonly ?TransferDiscrepancyReason $close_reason,
        public readonly ?string $close_note,
        public readonly string $freight_uncapitalized,
        public readonly array $receipts,
        public readonly ?array $lines,
    ) {
    }

    public static function fromModel(StockTransfer $transfer, bool $includeLines = false): self
    {
        return new self(
            id: (string) $transfer->id,
            transfer_number: (string) $transfer->transfer_number,
            transfer_type: $transfer->transfer_type,
            status: $transfer->status,
            source_location_id: (string) $transfer->source_location_id,
            source_location_name: $transfer->sourceLocation->name ?? null,
            destination_location_id: (string) $transfer->destination_location_id,
            destination_location_name: $transfer->destinationLocation->name ?? null,
            notes: $transfer->notes,
            transfer_cost: (string) $transfer->transfer_cost,
            transfer_cost_label: $transfer->transfer_cost_label,
            transfer_cost_distribution: $transfer->transfer_cost_distribution,
            initiated_by_user_id: (string) $transfer->initiated_by_user_id,
            initiated_by_name: $transfer->initiatedBy->name ?? null,
            completed_by_user_id: $transfer->completed_by_user_id,
            completed_by_name: $transfer->completedBy?->name,
            cancelled_by_user_id: $transfer->cancelled_by_user_id,
            cancelled_by_name: $transfer->cancelledBy?->name,
            initiated_at: $transfer->initiated_at?->toIso8601String(),
            completed_at: $transfer->completed_at?->toIso8601String(),
            cancelled_at: $transfer->cancelled_at?->toIso8601String(),
            cancellation_reason: $transfer->cancellation_reason,
            created_at: $transfer->created_at?->toIso8601String(),
            updated_at: $transfer->updated_at?->toIso8601String(),
            closed_by_user_id: $transfer->closed_by_user_id,
            closed_by_name: $transfer->closedBy?->name,
            closed_at: $transfer->closed_at?->toIso8601String(),
            close_disposition: $transfer->close_disposition,
            close_reason: $transfer->close_reason,
            close_note: $transfer->close_note,
            freight_uncapitalized: (string) $transfer->freight_uncapitalized,
            receipts: $transfer->receipts
                ->map(static fn ($receipt): TransferReconciliationReceiptData => TransferReconciliationReceiptData::fromModel($receipt))
                ->all(),
            lines: $includeLines
                ? $transfer->lines
                    ->map(static fn (StockTransferLine $line): StockTransferLineData => StockTransferLineData::fromModel($line))
                    ->all()
                : null,
        );
    }
}
```

**Three consequences the implementer must carry, each stated so no inference is needed.**

1. **`StockTransfer` gains two relations in S1**, beside the eight `@property-read` entries it already declares (`apps/api/app/Modules/Inventory/Domain/StockTransfer.php:45-52`, methods at `:133`, `:141`, `:149`, `:157`): `closedBy(): BelongsTo` on `closed_by_user_id` (the same shape as `completedBy()` at `:141`) and `receipts(): HasMany` on `StockTransferReceipt::class, 'transfer_id'` ordered by `sequence`. Both get a `@property-read` line. `StockTransferController::show` and `index` add `'closedBy'` and `'receipts.receivedBy'` to their existing `with([...])` lists (`:107` and `:43`) so the builder never N+1s.
2. **`receipts[]` reuses `TransferReconciliationReceiptData`** rather than declaring a second receipt-summary shape (convention 11: one surface per concept). That class already carries exactly the members spec §5.3 asks for on a transfer payload — `receipt_number`, `kind`, `disposition`, `sequence`, `received_by`, `received_at`, `is_blind`, `has_discrepancy` — and it carries **no quantity**, which is what lets `TransferReceiverPayloadBuilder` omit the whole member for a blind actor without a shape argument. Because it now has **two** callers (`TransferReconciliationService` and `TransferPayloadBuilder`), the mapping lives on the DTO rather than being written twice; S1 adds this one static method to the class reproduced in §7.12, and nothing else about that class changes:

```php
    public static function fromModel(StockTransferReceipt $receipt): self
    {
        return new self(
            receipt_number: (string) $receipt->receipt_number,
            kind: $receipt->kind,
            disposition: $receipt->disposition,
            sequence: (int) $receipt->sequence,
            received_by: ['id' => (string) $receipt->received_by_user_id, 'name' => (string) $receipt->receivedBy?->name],
            received_at: $receipt->received_at->toIso8601String(),
            is_blind: (bool) $receipt->is_blind,
            has_discrepancy: (bool) $receipt->has_discrepancy,
        );
    }
```
3. **Nothing else in this DTO family is nullable-by-default.** `transfer_cost` is `numeric-string` and non-null on the model (`apps/api/app/Modules/Inventory/Domain/StockTransfer.php:32`); `allocated_transfer_cost` is `numeric-string` and non-null (`apps/api/app/Modules/Inventory/Domain/StockTransferLine.php:27`); only `unit_cost_snapshot` is `numeric-string|null` (`:26`), and it is the one money member typed `?string`.

**Generated-type coverage (the B1 requirement).** All three classes carry `#[TypeScript]`, so S1's `CACHE_STORE=array php artisan typescript:transform` (§13) emits `StockTransferData`, `StockTransferLineData` and `StockTransferLineBatchAllocationData` into `packages/shared/types/generated.d.ts` in **push 1**, ahead of every consumer. That file has a hard CI drift gate at `scripts/preflight.sh:110-133` and `.github/workflows/ci.yml:2683-2700`, so a commit that adds a `#[TypeScript]` class without regenerating fails CI, and a regeneration without the class produces a diff the gate rejects. Two executable guards sit on top of that:

- **PHP, S1** — `TransferReceiptSecondOfEverythingTest` asserts the generated file actually contains the three interfaces, with a single readable assertion: `self::assertSame([], array_values(array_filter(['StockTransferData', 'StockTransferLineData', 'StockTransferLineBatchAllocationData'], static fn (string $name): bool => ! str_contains(file_get_contents(base_path('../../packages/shared/types/generated.d.ts')), 'interface '.$name))));` — today all three are missing, so the array is all three names and the assertion is red.
- **TypeScript, S4** — `apps/web/src/features/stock-transfers/__tests__/transferUnionNarrowing.test-d.ts` reads `transfer_cost` off the un-narrowed union under `// @ts-expect-error`. If `StockTransferData` is absent the import itself fails and `pnpm typecheck` is red; if it is present but the union is un-narrowed the `@ts-expect-error` is what keeps the read legal (§10.10).

### 7.13 Convention-09 evidence (S-matrix)

Every cell is a **data-meaning** assertion, not an HTTP status. Company B is provisioned through the real `POST /api/v1/companies` endpoint; L2 is a second `pos_enabled` location, and every location-bound assertion names L2 explicitly so the *selected* location is proven, never the default.

| Writer | Second company | Second location | Re-run / idempotency |
|---|---|---|---|
| `receive` | B receives its own transfer; `TRR-2026-0001` exists in **both** A and B; A's receipt and A's transfer 404 for a B actor | the `stock_levels` row for the product appears at **L2 only**; L1 unchanged; matrix and POS incoming drop at L2 only | same key + same body twice → one receipt, one movement set, HTTP 200 `meta.replayed`; same key, different body → `IDEMPOTENCY_KEY_REUSED`; same key on another transfer → `IDEMPOTENCY_KEY_REUSED`; `1`, `1.0` and `1.0000` and reordered lines hash equal |
| `close write_off` | B closes its own transfer; B's journal has one Shrinkage leg, A's has none | the WriteOff movement is at **L2**; L2 on-hand nets to 0 for the remainder; L1 untouched | close twice → HTTP 200 replayed after the transfer is terminal; one WriteOff movement, one GL leg |
| `close return_to_source` | B returns to **B's** source; A's source on-hand unchanged | the return from L2 restocks **that transfer's** source (L1), not the default location | close twice → replay; source on-hand `+remainder` exactly once |
| `complete` (delegate) | B's `complete` succeeds; A's `complete` 404 for a B actor | `complete` lands the `stock_levels` row at **L2 only** | `complete` twice → replay; `StockTransferCompleted` once; freight capitalised once |
| backfill (§7.4) | completed transfers in A and B each get one `legacy_completion` receipt under **their own** company's numbering; the reader baseline is captured and compared **per company**, so A's totals cannot be satisfied by B's rows | receipt lines' `in_movement_id` resolve to the `TransferIn` at **that transfer's** destination (L2 for L2 transfers); the baseline covers L1 and L2 separately at all four reader query sites | rerun → zero new rows; counters unchanged; **and (gate r2 M3) the four reader query sites return byte-identical JSON to the pre-change baseline for every completed, cancelled and untouched in-transit transfer, with and without lots** (§7.14, the capture/preservation pair) |
| migrations (§7.3) | n/a | n/a | (a) `tenants:migrate` on a fully migrated tenant prints "Nothing to migrate" and adds zero `migrations` rows; (b) catalog dumps (`information_schema.columns`, `pg_constraint`, `pg_indexes`) taken after the first run and after a clean rerun are **identical** |

### 7.14 Red-first tests

Every test is written first and must fail for the stated reason before any production line is written (CLAUDE.md rule 2). The handback records, per row, the captured failure output.

**How to read the "First failing assertion" column (gate r1 M6).** The column names the assertion that PHPUnit reports **first** when the method is run against the tree as it is before this slice's production code. Where the method cannot reach an assertion at all — because the route 404s, the table does not exist, or the class does not autoload — the column says so and names the **first line of the method that errors**, because "the test errors before assertion 1" is stronger red-first evidence than a passing count assertion, not weaker. Rev 1 had three rows whose stated assertion would have **passed** before the feature existed (T4b's `journal_entries` count of `0`, the migration rerun's catalog equality, and the backfill's receipt count read through a class that cannot load); all three are corrected here.

**Before this table's first row can be captured**, S1 raises the feature-lane ceilings of §6.7 in the same commit that adds the first test class, and runs:

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && php tools/feature-lane-manifest-check.php
```

It must exit `0`. Run without the raise it exits non-zero with the parked-group growth message, which is itself the B1 red-first evidence and is captured in the handback.

| Exact file | Exact class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Inventory/StockTransferReceiveTest.php` | `StockTransferReceiveTest::test_partial_receipt_leaves_remainder_in_transit_and_completes_on_the_second_receipt` (T1) | `self::assertSame('partially_received', $transfer->refresh()->status->value);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveTest::test_partial_receipt_leaves_remainder_in_transit_and_completes_on_the_second_receipt'` | PHPUnit PostgreSQL (`autoerp_test_u`) |
| same | `StockTransferReceiveTest::test_over_receipt_is_refused_with_zero_movements` (T2) | `$response->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveTest::test_over_receipt_is_refused_with_zero_movements'` | PHPUnit PostgreSQL |
| same | `StockTransferReceiveTest::test_sub_unit_quantities_round_trip_as_four_decimal_strings` (T8) | `self::assertSame('0.0001', $response->json('data.receipt.lines.0.quantity_received'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveTest::test_sub_unit_quantities_round_trip_as_four_decimal_strings'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php` | `StockTransferReceiveDamageTest::test_damaged_units_land_then_scrap_with_one_shrinkage_journal` (**T3-S1**) | **`$receive->assertStatus(201);`** — fails **404** today, because `POST /stock-transfers/{transfer}/receive` does not exist (`apps/api/app/Modules/Inventory/Presentation/routes.php:99-115`). Assertion order, load-bearing: (1) `assertStatus(201)`, (2) `self::assertSame(1, JournalEntry::query()->count());`, (3) `self::assertSame('0.0000', StockLevel::query()->where('location_id', $destination->id)->sole()->quantity);` (land-then-scrap nets to zero for the damaged units), (4) `self::assertTrue($receipt->has_discrepancy);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveDamageTest::test_damaged_units_land_then_scrap_with_one_shrinkage_journal'` | PHPUnit PostgreSQL |
| same | `StockTransferReceiveDamageTest::test_declared_reason_never_changes_the_movement_reason` (**T3-S1**) | `$receive->assertStatus(201);` — 404 today; then `self::assertSame(MovementReason::Damage, $scrap->reason);` for a receipt declaring `other` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveDamageTest::test_declared_reason_never_changes_the_movement_reason'` | PHPUnit PostgreSQL |
| same | `StockTransferReceiveDamageTest::test_periodic_valuation_company_is_refused_before_any_movement` (**T3-S1**, §7.5 rule 4) | `$response->assertStatus(422)->assertJsonPath('error.code', 'VALUATION_MODE_UNSUPPORTED');` — today the request 404s, so `assertStatus(422)` fails on a 404 | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveDamageTest::test_periodic_valuation_company_is_refused_before_any_movement'` | PHPUnit PostgreSQL |
| same | `StockTransferReceiveDamageTest::test_the_posted_event_carries_the_discrepancy_line_count` (**T3-S1**, rev 2 — the S1 half of the alert contract) | `self::assertSame(1, $captured->linesWithDiscrepancy);` on the `StockTransferReceiptPosted` captured with `Event::fake([StockTransferReceiptPosted::class])` — today the class does not exist. **`T3-S4` (§10.10) asserts the notification this event produces; S4 re-runs this whole file** (§13) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveDamageTest::test_the_posted_event_carries_the_discrepancy_line_count'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/StockTransferCloseTest.php` | `StockTransferCloseTest::test_close_write_off_posts_one_shrinkage_leg_and_persists_the_freight_residual` (T4) | `self::assertSame('70.0000', $transfer->refresh()->freight_uncapitalized);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferCloseTest::test_close_write_off_posts_one_shrinkage_leg_and_persists_the_freight_residual'` | PHPUnit PostgreSQL |
| same | `StockTransferCloseTest::test_two_line_worked_example_persists_exactly` (T4) | `self::assertSame('49.9999', $lineA->refresh()->allocated_transfer_cost);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferCloseTest::test_two_line_worked_example_persists_exactly'` | PHPUnit PostgreSQL |
| same | `StockTransferCloseTest::test_close_return_to_source_restocks_the_source_lot_exactly_and_posts_no_journal` (T4b) | **`$close = $this->actingAs($closer)->postJson("/api/v1/stock-transfers/{$transfer->id}/close", $body); $close->assertStatus(201);`** — fails **404** today, because `POST /stock-transfers/{transfer}/close` does not exist: the only transfer routes are list `:99`, show `:107`, complete `:111` and cancel `:115` (`apps/api/app/Modules/Inventory/Presentation/routes.php`). The method's assertion ORDER is load-bearing and is stated here so it cannot be reordered: (1) `assertStatus(201)`, (2) `self::assertSame('closed_returned', $transfer->refresh()->status->value);`, (3) `self::assertSame('9.0000', StockLevel::query()->where('location_id', $source->id)->where('product_id', $product->id)->sole()->quantity);` (the pre-close source figure plus the returned remainder, exactly once), (4) `self::assertSame(1, StockMovement::query()->where('reference_id', $transfer->id)->where('location_id', $source->id)->where('movement_type', MovementType::TransferIn)->count());`, and only then (5) `self::assertSame(0, JournalEntry::query()->count());`. Rev 1 named (5) as the first failing assertion; (5) passes vacuously before `close` exists and is a **liveness** assertion, not red-first evidence | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferCloseTest::test_close_return_to_source_restocks_the_source_lot_exactly_and_posts_no_journal'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveValidationTest.php` | `StockTransferReceiveValidationTest::test_every_schema_mirror_rule_refuses_before_any_write` (T5) | `self::assertSame(0, DB::table('stock_transfer_receipts')->count());` after the first refused body | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveValidationTest::test_every_schema_mirror_rule_refuses_before_any_write'` | PHPUnit PostgreSQL |
| same | `StockTransferReceiveValidationTest::test_reserved_sys_namespace_is_refused_and_the_server_key_still_works` (T5, A6) | `$response->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');` with `errors.idempotency_key` present | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveValidationTest::test_reserved_sys_namespace_is_refused_and_the_server_key_still_works'` | PHPUnit PostgreSQL |
| same | `StockTransferReceiveValidationTest::test_no_error_body_carries_a_fixture_quantity` (T5) | `$response->assertStatus(422)->assertJsonPath('error.code', 'OVER_RECEIPT');` — fails on a **404** today, and it must precede the scan for the same reason gate r2 M5 gave for T13: `assertStringNotContainsString('12.0000', …)` passes against a 404 body and is therefore a control, not evidence. The scan `self::assertStringNotContainsString('12.0000', $response->getContent());` runs second, once a typed 422 body exists to scan | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveValidationTest::test_no_error_body_carries_a_fixture_quantity'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveLotsTest.php` | `StockTransferReceiveLotsTest::test_lot_rules_and_per_lot_remainder` (T6) | `$response->assertStatus(422)->assertJsonPath('error.code', 'UNKNOWN_LOT');` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'StockTransferReceiveLotsTest::test_lot_rules_and_per_lot_remainder'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php` | `StockTransferReceiveConcurrencyPostgresTest::test_two_parallel_receipts_of_eight_of_twelve_yield_one_201_and_one_over_receipt` (T7) | `self::assertSame([201, 422], $statuses);` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php --filter test_two_parallel_receipts_of_eight_of_twelve_yield_one_201_and_one_over_receipt` | **PG-only**, exclusive database (`autoerp_test_u`) |
| same | `StockTransferReceiveConcurrencyPostgresTest::test_receive_and_close_serialise_in_both_forced_orderings` (T7b) | `self::assertSame(2, StockTransferReceipt::query()->where('transfer_id', $transfer->id)->count());` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php --filter test_receive_and_close_serialise_in_both_forced_orderings` | **PG-only** |
| same | `StockTransferReceiveConcurrencyPostgresTest::test_receive_and_complete_serialise_and_capitalise_freight_once` (T7c) | `self::assertSame(1, StockMovement::query()->where('reference_id', $transfer->id)->where('movement_type', MovementType::Adjustment)->count());` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php --filter test_receive_and_complete_serialise_and_capitalise_freight_once` | **PG-only** |
| `apps/api/tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php` | `TransferLegacyCompletionBackfillTest::test_every_completed_transfer_gets_one_legacy_receipt_and_rerun_adds_nothing` (T11) | **The method does not reach an assertion**: `StockTransferReceipt::query()` errors with `Error: Class "App\Modules\Inventory\Domain\StockTransferReceipt" not found` before migration 1b exists, and once the model file lands but the migration has not run it errors `SQLSTATE[42P01]: Undefined table: relation "stock_transfer_receipts" does not exist`. That error IS the red-first evidence and is what the handback captures. The **first assertion** the method then reaches, once the schema exists but the backfill migration does not, is `self::assertSame(1, StockTransferReceipt::query()->where('transfer_id', $completed->id)->count());` returning `0` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferLegacyCompletionBackfillTest::test_every_completed_transfer_gets_one_legacy_receipt_and_rerun_adds_nothing'` | PHPUnit PostgreSQL **and** SQLite |
| same | `TransferLegacyCompletionBackfillTest::test_lot_grain_follows_the_shipment_not_the_current_product_flag` (T11) | `self::assertFalse($receiptLine->is_lot_tracked);` for a line whose product flag was flipped after shipment | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferLegacyCompletionBackfillTest::test_lot_grain_follows_the_shipment_not_the_current_product_flag'` | PHPUnit PostgreSQL |
| same | `TransferLegacyCompletionBackfillTest::test_interrupted_backfill_rolls_back_and_the_next_run_completes` (T11, **PG-only**) | `self::assertSame(0, DB::table('stock_transfer_receipts')->count());` after the forced failure | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php --filter test_interrupted_backfill_rolls_back_and_the_next_run_completes` | **PG-only**, mark-skipped on SQLite |
| same | `TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline` (**T11, rev 3, gate r2 M3 — the CAPTURE half**) | **`self::assertNotFalse(getenv('T2T3_CAPTURE_BASELINE'), 'capture-only method');`** — without the environment variable the method calls `$this->markTestSkipped('capture-only; set T2T3_CAPTURE_BASELINE=1 on the PRE-CHANGE tree')` and never asserts, so it is inert in every ordinary run. Run **once, as step (1) of §7.15, before any production line is written**, it builds the shared fixture of the preservation method and writes `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` with the four reader answers per company and per location, then asserts `self::assertGreaterThan(0, strlen($json));` and `self::assertSame(4, count($baseline[DB::getDriverName()]['sites']));`. **Every identity in the fixture is a literal UUID constant declared on the test class** (CLAUDE.md rule 17), not a factory-generated one, so the captured JSON is byte-stable across runs and across machines; the file has one top-level key per DB driver (`pgsql`, `sqlite`) because `SUM` returns a different scalar type on each lane, and each capture run rewrites only its own key | `cd apps/api && T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline'` **and** `cd apps/api && T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit --filter 'TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline'` | PHPUnit PostgreSQL **and** SQLite, both writing into the one committed file |
| same | `TransferLegacyCompletionBackfillTest::test_historical_reader_answers_survive_the_backfill_and_the_reader_switch` (**T11, rev 3, gate r2 M3 — the PRESERVATION half**) | **The method does not reach an assertion at HEAD**: its first statement is `self::assertSame(['in_transit', 'partially_received'], StockTransfer::CARRYING_STATUSES, 'the reader switch must be in place before this regression can mean anything');` and PHP raises `Error: Undefined constant App\Modules\Inventory\Domain\StockTransfer::CARRYING_STATUSES` before the comparison happens. That error is the captured red-first evidence. Assertion order after the constant lands, all load-bearing: (2) the **changed** answer — `self::assertSame('5.0000', $this->readerAnswers()['location_incoming'][$l2->id]);` for a carrying transfer of 12 with a 7-of-12 receipt posted, which returns `'12.0000'` under the pre-switch predicate and is the behaviour change the switch exists to produce; (3)–(6) the **unchanged** answers, byte-equal against the captured baseline, one assertion per reader site: `self::assertSame($baseline['sites']['location_stock_grouped'], $after['location_stock_grouped']);`, `self::assertSame($baseline['sites']['location_stock_distribution'], $after['location_stock_distribution']);`, `self::assertSame($baseline['sites']['stock_matrix_incoming'], $after['stock_matrix_incoming']);`, `self::assertSame($baseline['sites']['wac_owned_quantity'], $after['wac_owned_quantity']);` — all four written out in full (gate r3 M1 sweep), where `$baseline` is the committed file's entry for `DB::getDriverName()`. **Why (2) is a carrying transfer and not a completed one, verified against code:** all four query sites filter `where('stock_transfers.status', TransferStatus::InTransit)` (`apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php:144`, `:228`; `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php:402`; `apps/api/app/Modules/Inventory/Application/Services/WeightedAverageCostService.php:124`), and `completed` is excluded by the old predicate and by `CARRYING_STATUSES` alike — so the reader switch **cannot** change a completed transfer's answer, and the backfill's job is to be invisible to all four readers. Proving that invisibility is exactly what (3)–(6) do, and (2) is what stops them passing vacuously | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferLegacyCompletionBackfillTest::test_historical_reader_answers_survive_the_backfill_and_the_reader_switch'` **and** `cd apps/api && ./vendor/bin/phpunit --filter 'TransferLegacyCompletionBackfillTest::test_historical_reader_answers_survive_the_backfill_and_the_reader_switch'` | PHPUnit PostgreSQL (`autoerp_test_u`) **and** SQLite |
| `apps/api/tests/Architecture/TransferInTransitReadersUseRemainderTest.php` | `TransferInTransitReadersUseRemainderTest::test_the_three_readers_use_remainder_sql_and_carrying_statuses` (T12) | `self::assertStringNotContainsString('TransferStatus::InTransit', $source, $file);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'TransferInTransitReadersUseRemainderTest::test_the_three_readers_use_remainder_sql_and_carrying_statuses'` | PHPUnit, no DB |
| same | `TransferInTransitReadersUseRemainderTest::test_the_detector_rejects_the_liveness_fixture` (T12) | `self::assertNotSame([], $violations);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit.xml --filter 'TransferInTransitReadersUseRemainderTest::test_the_detector_rejects_the_liveness_fixture'` | PHPUnit, no DB |
| `apps/api/tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php` | `TransferReceiptGlBoundaryTest::test_receipt_and_close_leave_the_gl_buffer_empty_with_no_leak_alarm` (T13) | **`$receive->assertStatus(201);`** — fails **404** today, because `POST /stock-transfers/{transfer}/receive` does not exist (`apps/api/app/Modules/Inventory/Presentation/routes.php:99-115`). **Gate r2 M5: rev 2 named `assertEmpty('request')` first, and the buffer is already empty before receipt or close exists, so that assertion passed on the untouched tree.** The corrected order is load-bearing: (1) `$receive->assertStatus(201);`, (2) `$close->assertStatus(201);`, (3) the POSITIVE evidence that a posting really happened — `self::assertSame(2, JournalEntry::query()->count());` (one Shrinkage entry for the damaged units of the receipt, one for the written-off remainder of the close), and only then (4) `$this->app->make(InventoryGlPostingBoundaryGuard::class)->assertEmpty('request');` and (5) `self::assertSame(0, $leakAlarms);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptGlBoundaryTest::test_receipt_and_close_leave_the_gl_buffer_empty_with_no_leak_alarm'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Replenishment/TransferCloseReplenishmentSettlementTest.php` | `TransferCloseReplenishmentSettlementTest::test_settled_request_stays_fulfilled_after_both_close_dispositions` (T14) | **`$closeWriteOff->assertStatus(201);`** — fails **404** today, for the same missing route. **Gate r2 M5: rev 2 named `assertSame('fulfilled', …)`, which is the status the request already carries after `initiate` settles it, so it passed before `close` existed.** Corrected order: (1) `$closeWriteOff->assertStatus(201);`, (2) `self::assertSame('closed_with_writeoff', $transferA->refresh()->status->value);`, (3) `self::assertSame('fulfilled', $requestA->refresh()->status->value);`, then the same three for a `return_to_source` close on a second request, and finally (7) `self::assertSame(0, ReplenishmentRequest::query()->where('status', 'pending')->count());` — no close re-opens a settled request | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferCloseReplenishmentSettlementTest::test_settled_request_stays_fulfilled_after_both_close_dispositions'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/TransferReceiptEventStreamTest.php` | `TransferReceiptEventStreamTest::test_header_and_line_events_share_the_receipt_stream_with_ordered_versions` (T15) | `self::assertSame([1, 2, 3], StoredEvent::query()->where('aggregate_uuid', $receiptId)->orderBy('id')->pluck('aggregate_version')->all());` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptEventStreamTest::test_header_and_line_events_share_the_receipt_stream_with_ordered_versions'` | PHPUnit PostgreSQL |
| same | `TransferReceiptEventStreamTest::test_replay_reproduces_every_row_and_leaves_legacy_receipts_untouched` (T15) | `self::assertSame($expectedRows, $rebuiltRows);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptEventStreamTest::test_replay_reproduces_every_row_and_leaves_legacy_receipts_untouched'` | PHPUnit PostgreSQL |
| same | `TransferReceiptEventStreamTest::test_a_failure_after_persist_leaves_no_stored_event` (T15) | `self::assertSame(0, StoredEvent::query()->where('aggregate_uuid', $receiptId)->count());` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptEventStreamTest::test_a_failure_after_persist_leaves_no_stored_event'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php` | `TransferReceiptPatternQueryPostgresTest::test_line_grain_and_both_dispositions_are_counted` (T16) | `self::assertSame(2, (int) $rowForB->lines_later_confirmed_short);` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php --filter test_line_grain_and_both_dispositions_are_counted` | **PG-only** |
| same | `TransferReceiptPatternQueryPostgresTest::test_repeated_partial_receipts_collapse_to_one_line_per_receiver` (T16) | `self::assertSame('0.5', (string) $rowForC->damage_rate);` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php --filter test_repeated_partial_receipts_collapse_to_one_line_per_receiver` | **PG-only** |
| `apps/api/tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php` | `TransferReceiptSecondOfEverythingTest::test_second_company_second_location_and_rerun_for_every_receipt_writer` (S-matrix) | `self::assertSame('7.0000', StockLevel::query()->where('location_id', $l2->id)->sole()->quantity);` with L1 unchanged | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptSecondOfEverythingTest::test_second_company_second_location_and_rerun_for_every_receipt_writer'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php` | `TransferReceiptSchemaRerunPostgresTest::test_the_four_counters_three_tables_and_named_checks_exist_after_the_first_run` (S-matrix migrations, **rev 2 — the existence proof that must precede the equality proof**) | `self::assertSame(['quantity_damaged', 'quantity_received', 'quantity_returned', 'quantity_written_off'], $addedColumns);` where `$addedColumns` is `DB::table('information_schema.columns')->where('table_name','stock_transfer_lines')->whereIn('column_name', self::COUNTERS)->orderBy('column_name')->pluck('column_name')->all()` — today `[]`. The method continues with the same census for `stock_transfer_line_batch_allocations`, `self::assertTrue(Schema::hasTable('stock_transfer_receipts'))` for all three receipt tables, and a `pg_constraint` lookup asserting the two named CHECKs of §7.3 are present | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php --filter test_the_four_counters_three_tables_and_named_checks_exist_after_the_first_run` | **PG-only** |
| same | `TransferReceiptSchemaRerunPostgresTest::test_clean_rerun_adds_no_migration_row_and_the_catalog_is_identical` (S-matrix migrations) | **Gate r1 M6: the catalog-equality assertion can pass vacuously on an empty catalog, so this method now OPENS with the existence census as a precondition and fails there first:** `self::assertGreaterThanOrEqual(3, $catalogBefore['tables'], 'the three receipt tables must exist before a rerun can be meaningful');` — today `0`. Only then `self::assertSame($migrationRowsBefore, $migrationRowsAfter);` and `self::assertSame($catalogBefore, $catalogAfter);`, where the catalog is the tuple of `information_schema.columns` (name, type, nullability, default), `pg_constraint` (conname, contype) and `pg_indexes` (indexname, indexdef) restricted to the five tables this lane touches | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php --filter test_clean_rerun_adds_no_migration_row_and_the_catalog_is_identical` | **PG-only** |
| `apps/api/tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php` (**plan-added**) | `TransferReceiptAuthorityGateTest::test_complete_only_receiver_reaches_transfer_list_and_show` | `$this->actingAs($completeOnly)->getJson('/api/v1/stock-transfers')->assertOk();` — fails **403** today, because the route is `can:inventory.transfers.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:99`) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptAuthorityGateTest::test_complete_only_receiver_reaches_transfer_list_and_show'` | PHPUnit PostgreSQL |
| same | `TransferReceiptAuthorityGateTest::test_close_requires_both_reconcile_and_close_permissions` | `$this->actingAs($reconcileOnly)->postJson($closeUrl, $body)->assertForbidden();` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptAuthorityGateTest::test_close_requires_both_reconcile_and_close_permissions'` | PHPUnit PostgreSQL |
| same | `TransferReceiptAuthorityGateTest::test_view_only_actor_cannot_post_a_receipt` | `$this->actingAs($viewOnly)->postJson($receiveUrl, $body)->assertForbidden();` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiptAuthorityGateTest::test_view_only_actor_cannot_post_a_receipt'` | PHPUnit PostgreSQL |

### 7.14b Red-first rows versus control rows for S1, and how each control is falsified (closes gate r2 MAJOR M5 for S1)

Gate r2 found two S1 rows whose named first assertion **passes on the untouched tree** — T13's `assertEmpty('request')` on an already-empty buffer, and T14's `fulfilled` status that `initiate` already set. Both are repaired in §7.14 by putting a 404-today route assertion and a positive-evidence assertion in front. The audit of that finding also caught a third row of the same shape, T5's fixture-quantity scan, which is repaired the same way. This section makes the classification explicit for **every** S1 method, in the shape §9.9b already uses for S3, so the three slices state the same contract in the same words.

**The rule.** A method is **RED-FIRST** when its first reachable statement fails, or errors, against the tree as it stands before this slice's production code. A method is a **CONTROL** when it is *expected to pass* at capture and its job is to go red only under a named mutation. Mislabelling either way blocks the slice: a control that is red at capture is a fixture bug, and a red-first row that is green at capture means the feature already exists.

| Method | Class | Expected at capture |
|---|---|---|
| `StockTransferReceiveTest::test_partial_receipt_leaves_remainder_in_transit_and_completes_on_the_second_receipt` | RED-FIRST (route 404) | RED |
| `StockTransferReceiveTest::test_over_receipt_is_refused_with_zero_movements` | RED-FIRST (404, not 422) | RED |
| `StockTransferReceiveTest::test_sub_unit_quantities_round_trip_as_four_decimal_strings` | RED-FIRST (no `data.receipt` in a 404 body) | RED |
| `StockTransferReceiveDamageTest::test_damaged_units_land_then_scrap_with_one_shrinkage_journal` | RED-FIRST (route 404) | RED |
| `StockTransferReceiveDamageTest::test_declared_reason_never_changes_the_movement_reason` | RED-FIRST (route 404) | RED |
| `StockTransferReceiveDamageTest::test_periodic_valuation_company_is_refused_before_any_movement` | RED-FIRST (404, not 422) | RED |
| `StockTransferReceiveDamageTest::test_the_posted_event_carries_the_discrepancy_line_count` | RED-FIRST (`StockTransferReceiptPosted` does not exist) | RED |
| `StockTransferCloseTest::test_close_write_off_posts_one_shrinkage_leg_and_persists_the_freight_residual` | RED-FIRST (`freight_uncapitalized` column does not exist) | RED |
| `StockTransferCloseTest::test_two_line_worked_example_persists_exactly` | RED-FIRST (route 404 before the allocation is read) | RED |
| `StockTransferCloseTest::test_close_return_to_source_restocks_the_source_lot_exactly_and_posts_no_journal` | RED-FIRST (route 404; repaired in rev 2) | RED |
| `StockTransferReceiveValidationTest::test_every_schema_mirror_rule_refuses_before_any_write` | RED-FIRST (`stock_transfer_receipts` does not exist, so the count errors) | RED |
| `StockTransferReceiveValidationTest::test_reserved_sys_namespace_is_refused_and_the_server_key_still_works` | RED-FIRST (404, not 422) | RED |
| `StockTransferReceiveValidationTest::test_no_error_body_carries_a_fixture_quantity` | RED-FIRST **after the rev-3 repair** (the typed-422 assertion now precedes the scan) | RED |
| `StockTransferReceiveLotsTest::test_lot_rules_and_per_lot_remainder` | RED-FIRST (404, not 422) | RED |
| `StockTransferReceiveConcurrencyPostgresTest::test_two_parallel_receipts_of_eight_of_twelve_yield_one_201_and_one_over_receipt` | RED-FIRST (`[404, 404]`, not `[201, 422]`) | RED |
| `StockTransferReceiveConcurrencyPostgresTest::test_receive_and_close_serialise_in_both_forced_orderings` | RED-FIRST (the receipt model does not autoload) | RED |
| `StockTransferReceiveConcurrencyPostgresTest::test_receive_and_complete_serialise_and_capitalise_freight_once` | RED-FIRST (no `Adjustment` movement is produced without the receipt path) | RED |
| `TransferLegacyCompletionBackfillTest::test_every_completed_transfer_gets_one_legacy_receipt_and_rerun_adds_nothing` | RED-FIRST (class/table missing; errors before assertion 1) | RED |
| `TransferLegacyCompletionBackfillTest::test_lot_grain_follows_the_shipment_not_the_current_product_flag` | RED-FIRST (receipt-line model missing) | RED |
| `TransferLegacyCompletionBackfillTest::test_interrupted_backfill_rolls_back_and_the_next_run_completes` | RED-FIRST (table missing) | RED |
| `TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline` | **CONTROL** (case S1-C1 — it is the baseline producer and is skipped without its env var) | SKIPPED, then PASS on the capture run |
| `TransferLegacyCompletionBackfillTest::test_historical_reader_answers_survive_the_backfill_and_the_reader_switch` | RED-FIRST (`StockTransfer::CARRYING_STATUSES` undefined; errors before assertion 1) | RED |
| `TransferInTransitReadersUseRemainderTest::test_the_three_readers_use_remainder_sql_and_carrying_statuses` | RED-FIRST (all three readers still contain the literal `TransferStatus::InTransit`) | RED |
| `TransferInTransitReadersUseRemainderTest::test_the_detector_rejects_the_liveness_fixture` | RED-FIRST (the detector and its fixture do not exist, so the method errors) | RED |
| `TransferReceiptGlBoundaryTest::test_receipt_and_close_leave_the_gl_buffer_empty_with_no_leak_alarm` | RED-FIRST **after the rev-3 repair** (route 404 first, positive journal count second) | RED |
| `TransferCloseReplenishmentSettlementTest::test_settled_request_stays_fulfilled_after_both_close_dispositions` | RED-FIRST **after the rev-3 repair** (route 404 first) | RED |
| `TransferReceiptEventStreamTest::test_header_and_line_events_share_the_receipt_stream_with_ordered_versions` | RED-FIRST (no stream exists; the pluck returns `[]`) | RED |
| `TransferReceiptEventStreamTest::test_replay_reproduces_every_row_and_leaves_legacy_receipts_untouched` | RED-FIRST (the three event classes do not exist) | RED |
| `TransferReceiptEventStreamTest::test_a_failure_after_persist_leaves_no_stored_event` | **CONTROL** (case S1-C2 — zero stored events is what an untouched tree already has) | PASS |
| `TransferReceiptPatternQueryPostgresTest::test_line_grain_and_both_dispositions_are_counted` | RED-FIRST (the query and its tables do not exist) | RED |
| `TransferReceiptPatternQueryPostgresTest::test_repeated_partial_receipts_collapse_to_one_line_per_receiver` | RED-FIRST (same) | RED |
| `TransferReceiptSecondOfEverythingTest::test_second_company_second_location_and_rerun_for_every_receipt_writer` | RED-FIRST (route 404 before the stock-level read) | RED |
| `TransferReceiptSchemaRerunPostgresTest::test_the_four_counters_three_tables_and_named_checks_exist_after_the_first_run` | RED-FIRST (the column census returns `[]`; repaired in rev 2) | RED |
| `TransferReceiptSchemaRerunPostgresTest::test_clean_rerun_adds_no_migration_row_and_the_catalog_is_identical` | RED-FIRST (the existence precondition returns `0`; repaired in rev 2) | RED |
| `TransferReceiptAuthorityGateTest::test_complete_only_receiver_reaches_transfer_list_and_show` | RED-FIRST (403 today) | RED |
| `TransferReceiptAuthorityGateTest::test_close_requires_both_reconcile_and_close_permissions` | RED-FIRST (the close route 404s, so `assertForbidden` fails on a 404) | RED |
| `TransferReceiptAuthorityGateTest::test_view_only_actor_cannot_post_a_receipt` | RED-FIRST (the receive route 404s, so `assertForbidden` fails on a 404) | RED |

**Falsification procedure for S1's two controls — mandatory, recorded in the handback.**

| Case | Control | Mutation to apply | Expected result under the mutation | Revert |
|---|---|---|---|---|
| **S1-C1** | `test_capture_the_pre_change_reader_baseline` | Change one literal UUID constant on the test class (for example the L2 location id) and re-run the capture, then run the preservation method against the unchanged committed baseline | The preservation method's assertion (3) fails with the two site maps keyed by different location ids, proving the baseline is really compared and not merely loaded | Restore the constant and re-capture |
| **S1-C2** | `test_a_failure_after_persist_leaves_no_stored_event` | In `StockTransferReceiptService`, temporarily move the `persist()` calls **outside** the root transaction closure (after `DB::transaction(...)` returns) and force the seeded failure | `self::assertSame(0, StoredEvent::query()->where('aggregate_uuid', $receiptId)->count());` fails with the header event committed, proving the control watches the atomicity it claims to | `git checkout -- ` that file |

### 7.15 Reviewers, order and rollback

Implementation order: (0) **raise the feature-lane ceilings of §6.7 and extend the `backend-test-pgsql` allowlist in the same commit as the first test class**, and confirm `php tools/feature-lane-manifest-check.php` exits `0` (§6.7.6); (1) write and capture every red assertion above, **and — before a single production line is written — run the two `T2T3_CAPTURE_BASELINE=1` commands of §7.14 on the untouched tree and commit `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json`** (gate r2 M3: a baseline captured after any production edit proves nothing, so this is step 1, not step 5); (2) migrations 1a–1c and their schema test; (3) enums, models and DTOs — including the three full transfer DTOs of §7.12b and the `TransferReconciliationReceiptData::fromModel()` mapper — then `php artisan typescript:transform`; (4) the three readers and the T12 ratchet, then the T11 preservation method against the committed baseline; (5) the backfill migration and the rest of T11; (6) **extract `StockTransferMovementSupport` (§6.7b), delete the five private helpers and re-point `initiate`/`cancel`, then re-run `StockTransferCompleteConcurrencyPostgresTest` and `StockTransferEdgeCasesTest` as regressions before any new behaviour is added**; (7) `ReceiptPayloadCanonicalizer`, the two FormRequests and Layer A; (8) `StockTransferReceiptService` with its final nine-argument constructor (§6.7b), Layer B, movements, GL buffer, freight; (9) stored events and the replay test; (10) permissions, routes, the controller's three new constructor arguments and `canAccessLocationOrFail()`, `receiptPayload()`, `TransferPayloadBuilder` returning `StockTransferData`, `TransferReconciliationService`, deletion of `formatTransfer` and `completeLocked`, and `StockTransferService::complete()` reduced to the three-line forwarder; (11) the S-matrix and pattern-query tests; (12) `docs/glossary.md`; (13) `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` (§7.16) **and the one-line successor pointer in `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md`**; (14) `php artisan permissions:export-frontend-map`.

**Step 6 is placed before any new behaviour on purpose.** The extraction is a pure refactor with an existing green regression suite (`StockTransferCompleteConcurrencyPostgresTest`, `StockTransferEdgeCasesTest`, both on `dev` since the T-1 merge). Doing it first means a later red is unambiguously the new feature's, not the move's.

Required reviewers, all three must return `ACCEPT` with `BLOCKER=0 MAJOR=0`:

- **`inventory-costing-reviewer`** — must explicitly cite: the four-decimal counters and their CHECKs; `REMAINDER_SQL` applied at all four query sites with unchanged rounding; the WAC denominator under `CARRYING_STATUSES`; the land-then-scrap sequence netting destination stock to zero without mutating WAC; the freight pool arithmetic and `Σ allocated + freight_uncapitalized = transfer_cost`; the backfill's shipment-time lot grain; and that no float touches a money or quantity value. **Rev 3, gate r2 M3:** it must additionally cite that `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` was captured on the pre-change tree (the handback's capture-command output is the evidence), that the preservation method compares all four reader query sites byte-for-byte, and that the only intentionally changed answer is the carrying transfer's.
- **`stock-gl-interaction-reviewer`** — must explicitly cite: that this is the first GL posting on this document type (seam 1); that every posting goes through `InventoryGlPostingBuffer` with the receipt service as the outermost transaction; that `return_to_source` posts nothing; that the `requirePerpetual` preflight covers **both** the lot-less and the batch-tracked paths; that movement identity is line-level for lot-less lines and lot-level for lot lines, with the parent columns NULL; and that no compensating stock or GL action was added for the T-1 settlement or freight tickets.
- **`tenancy-authz-reviewer`** — must explicitly cite: the two new permissions with their exact seeded roles; the route matrix, including that `receive` is **not** under the any-of gate and that `close` chains two `can:` middlewares; that every new route inherits the rule-12 group middleware at `apps/api/app/Modules/Inventory/Presentation/routes.php:31`; that both document-identity uniques carry `company_id`; that the genericised `RequireAnyPermission` message leaks nothing; that the second-company assertions prove isolation on data, not on status codes; **and (rev 2, gate r1 B1) that `apps/api/tests/feature-lane-manifest.json` carries the exact §6.7.3 numbers for this slice, that its raise notes contain all four §6.7.4 statements with no observed-CI claim, that all fourteen class names appear in the `.github/workflows/ci.yml:1142` alternation, and that `php tools/feature-lane-manifest-check.php` exits 0 on the slice tip**. **Rev 3 additions:** that the three raise notes name only their own group's PG-only classes (gate r2 m1); that `StockTransferController` performs the `return_to_source` source-location check through `canAccessLocationOrFail()` **before** entering the service (gate r2 M1); that the three DTOs of §7.12b exist as PHP `#[TypeScript]` classes and appear as interfaces in the regenerated `packages/shared/types/generated.d.ts` (gate r2 B1); and that `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` carries exactly the one-line successor pointer of §7.16 and no other change (gate r2 M6).

Rollback: revert the S1 merge commit. The four migrations are additive and their `down()` methods are written, but `down()` on 1b **drops receipt rows**, so a rollback after any receipt has been posted is a data-loss operation and is never performed on staging; the recovery path for post-commit drift is a new forward migration (§7.3). Reverting also reverts the manifest raise and the allowlist entries together with the classes they cover, which is correct: the ceiling and the classes must move as one commit in both directions.

### 7.16 Promotion checklist (spec §11 rollout step 4 — closes gate r1 MAJOR M7)

Spec §11.4 requires: "Permissions: seeder rows for `inventory.transfers.reconcile` and `inventory.transfers.close`, `tenants:seed RolesAndPermissionsSeeder`, `permission:cache-reset`, `permissions:export-frontend-map`; `docs/handoff/PROMOTION-CHECKLIST-*` gets the steps plus the §3.1 manual-recovery line." Rev 1 claimed the rollout step in S1's scope but put no checklist file in S1's file list, ledger or handback; dispatch-order shell instructions are not a durable checklist. Rev 2 closes that with a real file.

**Which file.** The repository has exactly **one** file matching the spec's glob today: `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` (104 lines, header "Promotion checklist — local `dev` → `origin/dev` (staging auto-deploy) — prepared 2026-08-25 for the 2026-08-26 ceremony"). It is a **dated record of one ceremony**: its §0 preconditions enumerate that day's Session A/B/C/D lanes and its §2 enumerates that day's migration set. Appending a 2026-09-09 lane to it would make a historical record un-auditable. **S1 therefore creates a new lane-scoped file, `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md`**, which the same `PROMOTION-CHECKLIST-*` glob picks up, and adds **one cross-reference line** to the existing file so a reader of the older checklist finds the newer one. The existing file is modified **only** by that one-line successor pointer, and it appears exactly once: in S1's file inventory (§7.2) and in the Push-1 ledger (§11.1).

Precedent that the existing file is the right shape to copy: its lines 70–79 are exactly this kind of per-lane permission block (`RolesAndPermissionsSeeder` + `permission:cache-reset` per tenant, the `SYNC_PERMISSIONS_ON_BOOT` caveat with its `apps/api/docker/entrypoint.sh:153-161` / `:176` anchors, the "`tenants:run`/`tenants:seed` exit 0 regardless — gate on the printed per-tenant output" warning, and the fail-closed frontend consequence). The new file reuses that structure verbatim for this lane's two permissions.

**Contents of `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md`** — the four spec §11.4 items reproduced, each as a tickable step with its own verification:

```markdown
# Promotion checklist — T-2 / T-3 transfer receipt + blind receiving (lane t2t3-transfer-receipt-blind-receiving)

Plan: docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-8.md
Spec: docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md (§11 rollout)
Applies to: source Push 1 (S1). Pushes 2-4 add no permission and no backfill.
Promoting to origin/dev = staging auto-deploy including `tenants:migrate` (memory
feedback_push_dev_autodeploys_migrations). Every step below runs AFTER that deploy settles.

## 1. Tenant permission seeding  (spec §11.4 item 1)
- [ ] `php artisan tenants:seed --force --class='Database\Seeders\RolesAndPermissionsSeeder'`
      Two NEW permissions ship in this lane: `inventory.transfers.reconcile` (visibility;
      gates GET /stock-transfers/{id}/reconciliation and is the "sees expected" disjunct)
      and `inventory.transfers.close` (write authority; required IN ADDITION to reconcile
      for POST /stock-transfers/{id}/close). Both are seeded to `manager` and reach `admin`
      through permissionNames(); no other seeded role receives either.
      Under database-per-tenant they do not exist in an already-provisioned tenant DB until
      this seeder runs THERE.
- [ ] Staging only: confirm `SYNC_PERMISSIONS_ON_BOOT=true` is still set on the API service
      in Dokploy before relying on the boot path
      (apps/api/docker/entrypoint.sh:153-161 runs the seeder, :176 runs the cache reset).
      The variable is in NO in-repo compose file and .env.example:178 ships `false`.
- [ ] `tenants:seed` and `tenants:run` exit 0 regardless of per-tenant failures.
      GATE ON THE PRINTED PER-TENANT OUTPUT, not on the exit code.
- [ ] Verify, per tenant DB:
      `SELECT name FROM permissions WHERE name IN
       ('inventory.transfers.reconcile','inventory.transfers.close') ORDER BY name;`
      -> exactly two rows.

## 2. Permission cache reset  (spec §11.4 item 2)
- [ ] `php artisan permission:cache-reset`
      Spatie caches the permission map per process. Without this, an already-warm API or
      Horizon worker keeps refusing the two new permissions until its cache expires.
- [ ] Re-verify after the reset with one real request: a manager on a destination location
      gets 200 from `GET /stock-transfers/{id}/reconciliation`.

## 3. Frontend permission-map export  (spec §11.4 item 3)
- [ ] `php artisan permissions:export-frontend-map` — BUILD TIME, on the laptop, committed
      in Push 1. NEVER on the host.
      It regenerates `apps/web/src/hooks/permissionsMap.generated.ts`, which has a hard CI
      drift gate (scripts/preflight.sh:140-155 and .github/workflows/ci.yml:2705-2714).
- [ ] Confirm the committed file contains both new permission keys before promoting.
- [ ] `php artisan typescript:transform` likewise, for
      `packages/shared/types/generated.d.ts` (gate: scripts/preflight.sh:110-133 and
      .github/workflows/ci.yml:2683-2700). TransferStatus must show SEVEN members.

## 4. Manual post-commit migration-drift recovery  (spec §3.1 / §11.4 item 4)
- [ ] Migrations run inside ONE PostgreSQL transaction and are logged to `migrations` only
      on success (apps/api/vendor/laravel/framework/src/Illuminate/Database/Migrations/Migrator.php:448-451
      and :252-258). A rolled-back, unlogged migration is simply re-selected by the next
      `tenants:migrate` — no operator action.
- [ ] **NEVER run `migrate:rollback` on this lane's migrations.** `down()` on
      2026_09_09_100100_create_stock_transfer_receipt_tables.php DROPS POSTED DOCUMENTS.
- [ ] Post-commit drift (a migration that COMMITTED but left the schema wrong) is repaired
      by a NEW FORWARD MIGRATION, never by a rollback and never by a manual ALTER on the host.
      Record the drift, write the forward migration, promote it as a normal push.
- [ ] Backfill-specific: interruption recovery is PostgreSQL-only. On PG the migration
      transaction discards every chunk of a failed run. On SQLite no convergence after
      interruption is claimed. Staging and production are PG.
- [ ] Lane census after Push 1, per tenant:
      `SELECT COUNT(*) FROM stock_transfers WHERE status = 'completed'
         AND id NOT IN (SELECT transfer_id FROM stock_transfer_receipts);`
      -> MUST return 0. A non-zero result means the backfill did not complete on that
      tenant; re-run `tenants:migrate` for it and re-census.

## 5. Backups
- [ ] One verified NON-ZERO host backup per tenant database BEFORE Push 1, because Push 1
      carries the backfill. Pushes 2-4 add no row-level write.

## 6. Activation (NOT part of any push)
- [ ] `blind_receiving` defaults to false everywhere and is a PER-COMPANY OPERATOR ACTION in
      Settings -> Fraud & controls, taken only AFTER Push 4. There is no environment
      variable and no config key to set on any application.
```

**Cross-reference line added to the existing checklist — an OWNED S1 deliverable (closes gate r2 MAJOR M6, second half).** Rev 2 ordered a one-line edit to `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` and then deliberately excluded that file from every push list on the grounds that "the orchestrator adds it at promotion time". Gate r2 is right that this is still a repository edit the plan requires, with no owning slice, no commit, no reviewer and no handback row — which is exactly what an exact once-only ledger forbids. Rev 3 **assigns it to S1**. The edit is one bullet under that file's §0:

```markdown
- Lane-scoped successor for the 2026-09-09 T-2/T-3 promotion: `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md`.
```

Nothing else in `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` changes — no step is added, renumbered or removed, so the 2026-08-26 ceremony record stays auditable. The file is now in S1's "Modify (documentation)" list (§7.2), in push 1 of the ledger (§11.1), in `tenancy-authz-reviewer`'s S1 citation list (§7.15), and in S1's handback (§17.1).

The new file `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` is in S1's exact file list (§7.2), in push 1 of the ledger (§11.1), in §12.1's `Commands` row, in §16 step 9, and is a named deliverable and handback item of dispatch packet §17.1.

---
## 8. Slice S2 — T-3 blind core

Worktree `.worktrees/t3-blind-core`, branch `lane/t3-blind-core`, PG database `autoerp_test_v`. **Merges after S1.**

### 8.1 Scope and ownership

**Spec sections implemented:** §3.1 (`company_fraud_settings` half and its model surfaces), §3.1b (`CompanyFraudSettings` row), §3.6 invariant I8, §4.5, §5.0 rows 1–6 and the accepted residuals R1–R4, §5.0b receiver / list-show / receive-201 / complete-422 / close-403 / any-of-403 / typed-422 / framework-422 rows, §5.3, §5.5, §5.6 (the blind gate), §5.8 (the reachability argument for the third read disjunct, proved executably), §11 step 1d.

**§10 rows owned:** T9b, T18, **T19-S2** (the partial contract of §2.2 deviation 5 — the row, the DTO and the **two transfer payloads**; the five carriage surfaces are `T19-S3` and **S3 re-runs the whole T19 row**), T19b, and the S-matrix rows *settings update*, *settings reset*, *settings initialisation*.

**PG-only classes:** `ReceivingControlsConcurrencyPostgresTest` (T19b).

**Feature-lane manifest (gate r1 B1):** this slice adds **2** `Inventory` and **3** `Compliance` Feature classes, so it writes `Inventory 141 -> 143`, `Compliance 23 -> 26`, `gated_ceiling 1267 -> 1272`, and appends all five names to the `backend-test-pgsql` allowlist. Arithmetic, note text and allowlist rule: §6.7. If the values found on `dev` differ (a parallel lane raised the same group), re-derive as `found + own additions` and record the correction in the handback.

**Seams respected:** none of the seven is touched by S2 — it adds no stock movement, no GL posting and no capitalisation. The reviewer gate still includes `inventory-costing-reviewer` because the builder choice decides whether a cost member reaches an actor.

**Merge safety.** `blind_receiving` defaults to `false` and `visibility_version` to `1`. With the setting off, `canSeeExpected` and `canSeeIncomingAggregates` are both unconditionally `true`, so every payload is byte-identical to S1's except for the two **added** members `blind: false` and `visibility_version`. Staging auto-deploy is therefore safe at this push with no operator action beyond the migration.

### 8.2 Files

Add (production):

- `apps/api/database/migrations/tenant/2026_09_09_110000_add_receiving_controls_to_company_fraud_settings.php`
- `apps/api/app/Shared/Contracts/TransferIncomingVisibility.php`
- `apps/api/app/Shared/Contracts/Compliance/ReceivingControlsReader.php`
- `apps/api/app/Modules/Inventory/Application/Services/ExpectedQuantityVisibility.php`
- `apps/api/app/Modules/Inventory/Application/Services/TransferReceiverPayloadBuilder.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReceiverViewData.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReceiverLineData.php` (**rev 2**, gate r1 M4 — §8.6b)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReceiverLotData.php` (**rev 2**, gate r1 M4)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReceiverOwnReceiptData.php` (**rev 2**, gate r1 M4)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReceiverLocationData.php` (**rev 2**, gate r1 M4)
- `apps/api/app/Modules/Compliance/Application/Services/CompanyReceivingControlsReader.php`
- `apps/api/app/Modules/Compliance/Domain/Events/ReceivingControlsChangedV1.php`

Modify (production):

- `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php`
- `apps/api/app/Modules/Compliance/Application/DTOs/CompanyFraudSettingsData.php`
- `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php`
- `apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php`
- `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php`
- `apps/api/app/Modules/Inventory/Application/Services/TransferPayloadBuilder.php`
- `apps/api/app/Modules/Inventory/Application/DTOs/StockTransferData.php` (**rev 3**, gate r2 B1/M2 — S1 created it, §7.12b; S2 appends the two members `blind` and `visibility_version`, §8.6)
- `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationData.php` (**rev 3**, gate r2 M2 — S1 created it, §7.12; S2 appends `visibility_version`, §8.7b)
- `apps/api/app/Modules/Inventory/Application/Services/TransferReconciliationService.php` (**rev 3**, gate r2 M2 — S1 created it, §7.5; S2 injects `TransferIncomingVisibility` and sources the new member from it, §8.7b)
- `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php`
- `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php`
- `apps/api/app/Modules/Inventory/Presentation/routes.php`
- `apps/api/tests/feature-lane-manifest.json` (**rev 2**, gate r1 B1 — Inventory 141→143, Compliance 23→26, `gated_ceiling` 1267→1272, §6.7.3/§6.7.4)
- `.github/workflows/ci.yml` (**rev 2**, gate r1 B1 — five class names appended to the `backend-test-pgsql` `--filter` alternation at `:1142`, §6.7.5)

Add (tests):

- `apps/api/tests/Feature/Inventory/TransferReceiverPayloadBuilderKeyScanTest.php` (T9b)
- `apps/api/tests/Feature/Inventory/TransferAuthorityMatrixTest.php` (T18, eight authority cases — §8.11b)
- `apps/api/tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php` (**T19-S2**)
- `apps/api/tests/Feature/Compliance/ReceivingControlsConcurrencyPostgresTest.php` (T19b)
- `apps/api/tests/Feature/Compliance/ReceivingControlsSecondOfEverythingTest.php` (S-matrix settings rows)

### 8.3 Migration 1d

`apps/api/database/migrations/tenant/2026_09_09_110000_add_receiving_controls_to_company_fraud_settings.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('company_fraud_settings', 'blind_receiving')) {
            Schema::table('company_fraud_settings', function (Blueprint $table): void {
                $table->boolean('blind_receiving')->default(false);
            });
        }

        if (! Schema::hasColumn('company_fraud_settings', 'visibility_version')) {
            Schema::table('company_fraud_settings', function (Blueprint $table): void {
                $table->integer('visibility_version')->default(1);
            });
        }
    }

    public function down(): void
    {
        foreach (['visibility_version', 'blind_receiving'] as $column) {
            if (! Schema::hasColumn('company_fraud_settings', $column)) {
                continue;
            }

            Schema::table('company_fraud_settings', function (Blueprint $table) use ($column): void {
                $table->dropColumn($column);
            });
        }
    }
};
```

Both columns are NOT NULL with a DEFAULT, so the add is valid on populated tables and existing tenants need **no data migration**: they land on `blind_receiving = false`, `visibility_version = 1`.

**This migration text is the authority on the column's type, and it is unchanged by rev 4 (gate r3 m1).** `visibility_version` is `$table->integer(...)` — a **signed** PostgreSQL `integer` — which is exactly the accepted spec's `visibility_version integer NOT NULL DEFAULT 1` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:105`). Rev 3's prose in §8.6 and §8.7b called it "unsigned integer"; both sentences are corrected, and **no implementer may change this migration to `unsignedInteger`** — doing so would put the PostgreSQL schema out of step with the accepted contract for no benefit, since the version only ever increases from 1. The matching PHP is `int` (`CompanyFraudSettingsData`, `StockTransferData`, `TransferReconciliationData`) and the matching generated TypeScript is `number`.

### 8.4 `CompanyFraudSettings` surfaces that must change in the same commit

Missing any one of these produces a model that silently drops the new columns. All are edit targets in `apps/api/app/Modules/Compliance/Domain/CompanyFraudSettings.php`:

| Surface | Anchor | Change |
|---|---|---|
| `@property` block | `:36` | add `@property bool $blind_receiving` and `@property int $visibility_version` |
| `$attributes` | `:53` | add `'blind_receiving' => false` |
| `$fillable` | `:108` | add `'blind_receiving'` **only** — `visibility_version` stays non-fillable |
| `casts()` | `:131` | add `'blind_receiving' => 'boolean'` and `'visibility_version' => 'integer'` |
| `getDefaults()` and its array-shape PHPDoc | `:165` | add `'blind_receiving' => false`; `visibility_version` is **not** in the defaults array — the column DEFAULT 1 supplies it |
| `defaultsForVertical()` array-shape PHPDoc | `:195` | extend the shape with `blind_receiving: bool` |

`CompanyFraudSettingsData` (`apps/api/app/Modules/Compliance/Application/DTOs/CompanyFraudSettingsData.php`) gains `public readonly bool $blind_receiving` and `public readonly int $visibility_version` in the constructor, in `fromModel()` and in `fromDefaults()` (where `visibility_version` is `1`). **The complete modified file is reproduced in §8.4b** (gate r1 M4): it is a cross-layer contract the web consumes as a generated type.

`FraudSettingsController` gains `'blind_receiving' => 'sometimes|boolean'` in the validation array (`apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:108`) under `can:fraud-settings.update` (`apps/api/app/Modules/Compliance/Presentation/routes.php:27`). `visibility_version` is never client-writable: it is absent from the validation list, absent from `$fillable`, and `reset` does not reset it.

**The two existing key constants on that controller, and which one `reset` uses (gate r1 MAJOR M1).** Rev 1 line 2219 said `reset` fills with `Arr::except(CompanyFraudSettings::getDefaults(), self::CASH_CONTROL_KEYS)` "exactly as today". That is **wrong**: the shipped code uses `REFUND_EXPOSURE_KEYS`, and the two constants have opposite jobs. `Arr::except` names the fields **preserved** through a reset, so the rev-1 text would have preserved the cash-control thresholds and **reset the refund-exposure ceilings** — silently WIDENING a ceiling a tenant had tightened, on a screen that shows nothing about refunds. Both constants, quoted from the file:

| Constant | Anchor | Members | Role in `reset` |
|---|---|---|---|
| `CASH_CONTROL_KEYS` | `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:30-38` | `cash_variance_over_soft`, `cash_variance_over_hard`, `cash_variance_under_soft`, `cash_variance_under_hard`, `require_blind_cash_count`, `require_manager_pin_above_hard`, `cash_variance_email_severity` | **None.** It is used only by `update()` at `:102` to decide whether the request touches cash controls and therefore needs the `pos.configure_cash_count` gate. `reset` never mentions it |
| `REFUND_EXPOSURE_KEYS` | `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:59-63` | `offline_refund_count_ceiling`, `offline_refund_value_ceiling`, `online_required_refund_threshold` | **This is the one.** `reset()` at `:200-203` is `CompanyFraudSettings::updateOrCreate(['company_id' => $companyId], Arr::except(CompanyFraudSettings::getDefaults(), self::REFUND_EXPOSURE_KEYS))`. The docblock at `:40-58` states why: they have no admin UI this pass (owner ruling 2026-08-01), are absent from `CompanyFraudSettingsData`, and resetting them would silently widen a tenant-tightened ceiling with no UI trace and no audit |

Accepted spec §4.5 says the same. **S2 changes neither constant and adds `blind_receiving` to neither.** `blind_receiving` IS in `getDefaults()` (as `false`), IS excluded from neither `Arr::except` set, and therefore **does** return to `false` on a reset — which is the intended behaviour and is what the `T19-S2` reset case asserts (§8.11).

### 8.4b `CompanyFraudSettingsData` after S2 — complete file (closes gate r1 MAJOR M4)

Replaces `apps/api/app/Modules/Compliance/Application/DTOs/CompanyFraudSettingsData.php` in place. The two new members are appended **before** `$is_configured` so the existing positional call sites keep their meaning; every other line is the shipped file.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\DTOs;

use App\Modules\Compliance\Domain\CompanyFraudSettings;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Canonical DTO for the /api/v1/fraud-settings endpoint payload.
 *
 * Mirrors the snake_case shape the FE has consumed since the cash-counting
 * remediation. Generated TypeScript counterpart is emitted into
 * packages/shared/types/generated.d.ts and replaces the hand-written
 * `apps/web/src/features/compliance/types/fraud.ts#FraudSettings`
 * (Q2 deferred-item M5).
 *
 * `is_configured` is derived from whether a CompanyFraudSettings row
 * has been persisted for the company; it is exposed alongside the
 * settings columns so the FE can render the "not yet configured"
 * banner without a second request.
 *
 * T-3: `blind_receiving` is the ONLY switch for blind receiving (no config
 * key, no environment variable). `visibility_version` is ADVISORY - a
 * monotonic per-company cache hint, never client-writable, absent from
 * $fillable and from getDefaults(); the column DEFAULT 1 supplies it.
 * The refund-exposure ceilings are deliberately NOT on this DTO
 * (FraudSettingsController::REFUND_EXPOSURE_KEYS) and T-3 does not add them.
 */
#[TypeScript]
final class CompanyFraudSettingsData extends Data
{
    /**
     * @param  list<string>|null  $alert_emails
     */
    public function __construct(
        public readonly ?string $id,
        public readonly string $company_id,
        public readonly int $abandoned_draft_threshold,
        public readonly int $time_window_days,
        public readonly ?array $alert_emails,
        public readonly bool $alert_enabled,
        public readonly bool $auto_trigger_counting,
        public readonly bool $auto_restrict_access,
        public readonly string $cash_variance_over_soft,
        public readonly string $cash_variance_over_hard,
        public readonly string $cash_variance_under_soft,
        public readonly string $cash_variance_under_hard,
        public readonly bool $require_blind_cash_count,
        public readonly bool $require_manager_pin_above_hard,
        public readonly string $cash_variance_email_severity,
        public readonly ?string $created_at,
        public readonly ?string $updated_at,
        public readonly bool $blind_receiving,
        public readonly int $visibility_version,
        public readonly bool $is_configured,
    ) {}

    /**
     * Build the DTO from a persisted CompanyFraudSettings row.
     *
     * `is_configured` is always true for a persisted row.
     */
    public static function fromModel(CompanyFraudSettings $settings): self
    {
        /** @var list<string>|null $alertEmails */
        $alertEmails = $settings->alert_emails;

        return new self(
            id: $settings->id,
            company_id: $settings->company_id,
            abandoned_draft_threshold: (int) $settings->abandoned_draft_threshold,
            time_window_days: (int) $settings->time_window_days,
            alert_emails: $alertEmails,
            alert_enabled: (bool) $settings->alert_enabled,
            auto_trigger_counting: (bool) $settings->auto_trigger_counting,
            auto_restrict_access: (bool) $settings->auto_restrict_access,
            cash_variance_over_soft: (string) $settings->cash_variance_over_soft,
            cash_variance_over_hard: (string) $settings->cash_variance_over_hard,
            cash_variance_under_soft: (string) $settings->cash_variance_under_soft,
            cash_variance_under_hard: (string) $settings->cash_variance_under_hard,
            require_blind_cash_count: (bool) $settings->require_blind_cash_count,
            require_manager_pin_above_hard: (bool) $settings->require_manager_pin_above_hard,
            cash_variance_email_severity: (string) $settings->cash_variance_email_severity,
            created_at: $settings->created_at->toIso8601String(),
            updated_at: $settings->updated_at->toIso8601String(),
            blind_receiving: (bool) $settings->blind_receiving,
            visibility_version: (int) $settings->visibility_version,
            is_configured: true,
        );
    }

    /**
     * Build the DTO from default values for a company that does not yet
     * have a persisted row. `is_configured` is false in this branch.
     *
     * `visibility_version` is 1 here because that is the column DEFAULT the
     * row would be created with; it is NOT read from getDefaults(), which
     * deliberately does not carry it (§8.4, invariant I8).
     */
    public static function fromDefaults(string $companyId): self
    {
        $defaults = CompanyFraudSettings::getDefaults();

        return new self(
            id: null,
            company_id: $companyId,
            abandoned_draft_threshold: (int) $defaults['abandoned_draft_threshold'],
            time_window_days: (int) $defaults['time_window_days'],
            alert_emails: null,
            alert_enabled: (bool) $defaults['alert_enabled'],
            auto_trigger_counting: (bool) $defaults['auto_trigger_counting'],
            auto_restrict_access: (bool) $defaults['auto_restrict_access'],
            cash_variance_over_soft: (string) $defaults['cash_variance_over_soft'],
            cash_variance_over_hard: (string) $defaults['cash_variance_over_hard'],
            cash_variance_under_soft: (string) $defaults['cash_variance_under_soft'],
            cash_variance_under_hard: (string) $defaults['cash_variance_under_hard'],
            require_blind_cash_count: (bool) $defaults['require_blind_cash_count'],
            require_manager_pin_above_hard: (bool) $defaults['require_manager_pin_above_hard'],
            cash_variance_email_severity: (string) $defaults['cash_variance_email_severity'],
            created_at: null,
            updated_at: null,
            blind_receiving: (bool) $defaults['blind_receiving'],
            visibility_version: 1,
            is_configured: false,
        );
    }
}
```

### 8.5 Visibility contracts and `ExpectedQuantityVisibility`

The two Shared interfaces are exactly as §6.1 declares them. Implementations:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Services;

use App\Modules\Compliance\Infrastructure\Repositories\CompanyFraudSettingsRepository;
use App\Shared\Contracts\Compliance\ReceivingControlsReader;

final class CompanyReceivingControlsReader implements ReceivingControlsReader
{
    /** @var array<string, array{blind: bool, version: int}> */
    private array $cache = [];

    public function __construct(
        private readonly CompanyFraudSettingsRepository $repository,
    ) {
    }

    public function blindReceivingEnabled(string $companyId): bool
    {
        return $this->load($companyId)['blind'];
    }

    public function visibilityVersion(string $companyId): int
    {
        return $this->load($companyId)['version'];
    }

    /**
     * @return array{blind: bool, version: int}
     */
    private function load(string $companyId): array
    {
        if (! array_key_exists($companyId, $this->cache)) {
            $settings = $this->repository->findByCompany($companyId);

            $this->cache[$companyId] = [
                'blind' => (bool) ($settings?->blind_receiving ?? false),
                'version' => (int) ($settings?->visibility_version ?? 1),
            ];
        }

        return $this->cache[$companyId];
    }
}
```

Bound in `ComplianceServiceProvider::register()` beside the existing singleton (`apps/api/app/Modules/Compliance/Providers/ComplianceServiceProvider.php:57`):

```php
        $this->app->scoped(ReceivingControlsReader::class, CompanyReceivingControlsReader::class);
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\Services;

use App\Modules\Company\Services\LocationContext;
use App\Modules\Inventory\Domain\StockTransfer;
use App\Modules\Identity\Domain\User;
use App\Shared\Contracts\Compliance\ReceivingControlsReader;
use App\Shared\Contracts\TransferIncomingVisibility;

final class ExpectedQuantityVisibility implements TransferIncomingVisibility
{
    /** @var array<string, bool> */
    private array $aggregateCache = [];

    public function __construct(
        private readonly ReceivingControlsReader $controls,
        private readonly LocationContext $locationContext,
    ) {
    }

    public function canSeeExpected(User $user, StockTransfer $transfer): bool
    {
        if (! $this->controls->blindReceivingEnabled((string) $transfer->company_id)) {
            return true;
        }

        if ($user->can('inventory.transfers.reconcile')) {
            return true;
        }

        return $this->locationContext->canAccessLocation(
            (string) $transfer->source_location_id,
            (string) $transfer->company_id,
            $user,
        );
    }

    public function canSeeIncomingAggregates(string $userId, string $companyId): bool
    {
        $key = $userId.'|'.$companyId;

        if (array_key_exists($key, $this->aggregateCache)) {
            return $this->aggregateCache[$key];
        }

        if (! $this->controls->blindReceivingEnabled($companyId)) {
            return $this->aggregateCache[$key] = true;
        }

        $user = User::query()->findOrFail($userId);

        if ($user->can('inventory.transfers.reconcile')) {
            return $this->aggregateCache[$key] = true;
        }

        return $this->aggregateCache[$key] = $this->locationContext->getAllowedLocationIds($companyId, $user) === null;
    }

    public function visibilityVersion(string $companyId): int
    {
        return $this->controls->visibilityVersion($companyId);
    }
}
```

Bound in `InventoryServiceProvider::register()` beside the existing `LocationStockReader` binding (`apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:63`):

```php
        $this->app->scoped(TransferIncomingVisibility::class, ExpectedQuantityVisibility::class);
```

`getAllowedLocationIds` returning `null` means an **unrestricted** membership (`apps/api/app/Modules/Company/Services/LocationContext.php:194`); an empty array is fail-closed and must **not** be read as unrestricted. Constructor injection only; no `app()` anywhere (CLAUDE.md rule 13). Neither Inventory nor POS nor Replenishment imports a Compliance model for this decision (CLAUDE.md rule 6).

### 8.6 Receiver projection, builders, receiver-view route, blind refusal of `complete`

**Two builders, separate array literals, one class per concept.**

`TransferPayloadBuilder` (S1) gains two members and nothing else: `blind: false` and `visibility_version: $this->visibility->visibilityVersion($companyId)`. Because the builder returns `StockTransferData` (§7.12b), those two members are **two new constructor properties on that DTO**, appended after `lines` so no existing argument position moves (gate r2 B1/M2):

```php
        public readonly array $receipts,
        public readonly ?array $lines,
        public readonly bool $blind,
        public readonly int $visibility_version,
    ) {
    }
```

and the mapper gains one parameter and two named arguments, with the version sourced from the requesting company's receiving-controls row through the Shared contract, never from a Compliance model. The whole method after S2, reproduced rather than described:

```php
    public static function fromModel(StockTransfer $transfer, bool $includeLines, int $visibilityVersion): self
    {
        return new self(
            id: (string) $transfer->id,
            transfer_number: (string) $transfer->transfer_number,
            transfer_type: $transfer->transfer_type,
            status: $transfer->status,
            source_location_id: (string) $transfer->source_location_id,
            source_location_name: $transfer->sourceLocation->name ?? null,
            destination_location_id: (string) $transfer->destination_location_id,
            destination_location_name: $transfer->destinationLocation->name ?? null,
            notes: $transfer->notes,
            transfer_cost: (string) $transfer->transfer_cost,
            transfer_cost_label: $transfer->transfer_cost_label,
            transfer_cost_distribution: $transfer->transfer_cost_distribution,
            initiated_by_user_id: (string) $transfer->initiated_by_user_id,
            initiated_by_name: $transfer->initiatedBy->name ?? null,
            completed_by_user_id: $transfer->completed_by_user_id,
            completed_by_name: $transfer->completedBy?->name,
            cancelled_by_user_id: $transfer->cancelled_by_user_id,
            cancelled_by_name: $transfer->cancelledBy?->name,
            initiated_at: $transfer->initiated_at?->toIso8601String(),
            completed_at: $transfer->completed_at?->toIso8601String(),
            cancelled_at: $transfer->cancelled_at?->toIso8601String(),
            cancellation_reason: $transfer->cancellation_reason,
            created_at: $transfer->created_at?->toIso8601String(),
            updated_at: $transfer->updated_at?->toIso8601String(),
            closed_by_user_id: $transfer->closed_by_user_id,
            closed_by_name: $transfer->closedBy?->name,
            closed_at: $transfer->closed_at?->toIso8601String(),
            close_disposition: $transfer->close_disposition,
            close_reason: $transfer->close_reason,
            close_note: $transfer->close_note,
            freight_uncapitalized: (string) $transfer->freight_uncapitalized,
            receipts: $transfer->receipts
                ->map(static fn ($receipt): TransferReconciliationReceiptData => TransferReconciliationReceiptData::fromModel($receipt))
                ->all(),
            lines: $includeLines
                ? $transfer->lines
                    ->map(static fn (StockTransferLine $line): StockTransferLineData => StockTransferLineData::fromModel($line))
                    ->all()
                : null,
            blind: false,
            visibility_version: $visibilityVersion,
        );
    }
```

Every named argument except the last two is byte-identical to §7.12b's S1 method; `$visibilityVersion` is supplied by `TransferPayloadBuilder`, which reads it once per request from `TransferIncomingVisibility::visibilityVersion($companyId)`.

`blind` is the hard-coded `false` of the full builder; `TransferReceiverPayloadBuilder` emits `blind: true` from its own array literal and never constructs `StockTransferData`. `visibility_version` is `int`, matching the **signed integer** column migration 1d creates — `$table->integer('visibility_version')->default(1)` (§8.3), which is the accepted spec's `visibility_version integer NOT NULL DEFAULT 1` (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:105`). **Gate r3 m1: rev 3's prose here called it "unsigned integer", which is wrong and would tempt an implementer to change the migration to `unsignedInteger` and diverge from the accepted contract. The migration is NOT changed; only this sentence is.** The three layers therefore read the same: PostgreSQL `integer`, PHP `public readonly int $visibility_version`, generated TypeScript `visibility_version: number`. The generated `StockTransferData` interface gains `blind: boolean` and `visibility_version: number` in push 2, and S4's `isReceiverTransfer` discriminant (`t.blind === true`) type-checks against both union arms (§10.4).

`TransferReceiverPayloadBuilder` emits exactly this shape and nothing more:

```
id, transfer_number,
source_location { id, name }, destination_location { id, name },
status, initiated_at, blind: true, visibility_version, receiving_open,
lines[] {
  id,
  product { id, name, sku, barcode },
  variant { id, sku, name_suffix } | null,
  unit { decimal_places },
  requires_batch_tracking,
  lots[] { batch_id, batch_number, expiry_date }
},
my_receipts[]
```

The guard comment lives **inside that PHP array literal**, exactly as counting already does (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334-377` region):

```php
        // NEVER INCLUDE: notes, transfer_cost, transfer_cost_label, cancellation_reason,
        // initiated_by, quantity, quantity_remaining, quantity_sent, quantity_received,
        // unit_cost_snapshot, allocated_transfer_cost, batch_allocations[].quantity,
        // receipts, close_disposition, close_reason, close_note, closed_at,
        // closed_by_user_id, freight_uncapitalized
```

`my_receipts[]` carries only the requesting actor's own posted lines. That is safe by construction: `complete` and `close` are refused to this actor, so nothing in `my_receipts` can be a figure the actor did not type.

`TransferReceiverViewData` is the `#[TypeScript]` DTO of that shape; it has **no** expected-quantity, note or cost member. The runtime key-scan test T9b is the enforcement, not the comment. **Its complete source, and the source of its four nested DTOs, is §8.6b** (gate r1 M4).

### 8.6b `TransferReceiverViewData` and its nested DTOs — complete source (closes gate r1 MAJOR M4 for §5.3)

Five new files under `apps/api/app/Modules/Inventory/Application/DTOs/`. They are the receiver-side twin of the full transfer shape and are the type the mobile receive screen (M-1) and the web `ReceiveTransferPage` consume. **A member that does not appear below does not exist on this type**, which is what makes the blind guarantee structural rather than a client-side hide.

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Lot IDENTITY only. There is deliberately no quantity member of any name. */
#[TypeScript]
final class TransferReceiverLotData extends Data
{
    public function __construct(
        public string $batch_id,
        public string $batch_number,
        public ?string $expiry_date,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One line of a receiver view.
 *
 * NEVER INCLUDE: quantity, quantity_remaining, quantity_sent, quantity_sent_snapshot,
 * quantity_received, quantity_damaged, unit_cost_snapshot, allocated_transfer_cost,
 * batch_allocations. `unit_decimal_places` is a UNIT property, not a quantity, and is
 * required so <QuantityInput> can emit strings at the right scale (CLAUDE.md rule 19).
 */
#[TypeScript]
final class TransferReceiverLineData extends Data
{
    /**
     * @param  array{id: string, name: string, sku: string, barcode: string|null}  $product
     * @param  array{id: string, sku: string, name_suffix: string|null}|null  $variant
     * @param  list<TransferReceiverLotData>  $lots
     */
    public function __construct(
        public string $id,
        public array $product,
        public ?array $variant,
        public int $unit_decimal_places,
        public bool $requires_batch_tracking,
        public array $lots,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the REQUESTING actor's own prior postings on this transfer.
 *
 * Safe by construction: `complete` and `close` are refused to a receiver-view
 * actor, so nothing here can be a figure the actor did not type (§8.6).
 * It carries no other receiver's posting and no sent/remaining figure.
 */
#[TypeScript]
final class TransferReceiverOwnReceiptData extends Data
{
    /**
     * @param  list<array{transfer_line_id: string, quantity_received: string, quantity_damaged: string}>  $lines
     */
    public function __construct(
        public string $receipt_number,
        public int $sequence,
        public string $received_at,
        public array $lines,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/** Location identity for a receiver view: id and name, never a stock figure. */
#[TypeScript]
final class TransferReceiverLocationData extends Data
{
    public function __construct(
        public string $id,
        public string $name,
    ) {
    }
}
```

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The receiver projection (spec §5.3). Built ONLY by TransferReceiverPayloadBuilder.
 *
 * NEVER INCLUDE: notes, transfer_cost, transfer_cost_label, transfer_cost_distribution,
 * cancellation_reason, initiated_by, initiated_by_name, completed_by_name,
 * cancelled_by_name, quantity, quantity_remaining, quantity_sent, quantity_received,
 * unit_cost_snapshot, allocated_transfer_cost, receipts, close_disposition,
 * close_reason, close_note, closed_at, closed_by_user_id, freight_uncapitalized.
 *
 * `blind` is ALWAYS true on this type - it is the discriminant the web narrows on
 * (`isReceiverTransfer`, §10.4) and the server is its only author.
 * `receiving_open` is `$transfer->status->isCarrying()`: a coarse boolean, never a
 * remainder. `status` is emitted unmasked, including `partially_received`
 * (owner ruling OQ-3, residual R3).
 */
#[TypeScript]
final class TransferReceiverViewData extends Data
{
    /**
     * @param  list<TransferReceiverLineData>  $lines
     * @param  list<TransferReceiverOwnReceiptData>  $my_receipts
     */
    public function __construct(
        public string $id,
        public string $transfer_number,
        public TransferReceiverLocationData $source_location,
        public TransferReceiverLocationData $destination_location,
        public TransferStatus $status,
        public string $initiated_at,
        public bool $blind,
        public int $visibility_version,
        public bool $receiving_open,
        public array $lines,
        public array $my_receipts,
    ) {
    }
}
```

`source_location` is present as **identity only** because the receive UI must name where the goods came from; it carries no stock figure and the actor's inability to see L1's stock is unaffected (`canAccessLocation(source)` is a separate check and is exactly the `canSeeExpected` disjunct of §8.5). The NEVER-INCLUDE comment above is duplicated **inside** `TransferReceiverPayloadBuilder`'s array literal (§8.6), and T9b is the runtime enforcement of both.

**Builder choice.** `GET /stock-transfers` and `GET /stock-transfers/{transfer}` choose **per transfer**: `canSeeExpected($user, $transfer)` true → `TransferPayloadBuilder`, false → `TransferReceiverPayloadBuilder`. One list page may therefore mix both shapes. The choice is made when the request is served; nothing is memoised and no response cache exists on these controllers.

**New route** (`apps/api/app/Modules/Inventory/Presentation/routes.php`, inside the group at `:31`):

| Route | Gate |
|---|---|
| `GET /stock-transfers/{transfer}/receiver-view` | `require.any.permission:inventory.transfers.view,inventory.transfers.complete,inventory.transfers.reconcile` + `canSeeTransfer` (404) + `canAccessLocation(destination)` (403) |

This route **always** uses `TransferReceiverPayloadBuilder`, for every actor, whatever the setting. That is what makes the mobile and web receipt surfaces blind by construction rather than by a client-side hide.

**Blind refusal of quantity-less `complete` (OD-1).** `StockTransferController::complete` evaluates, in this order: permission → `canAccessLocationOrFail($transfer->destination_location_id, $company->id, $user)` → **`canSeeExpected($user, $transfer)`, else typed 422 `BLIND_REQUIRES_COUNTED_RECEIPT`** — **before** the idempotency lookup, so a blind actor can never obtain a stored full receipt through the replay path — then the published forwarder `$this->service->complete($transfer->id, $user->id)`, which is the ONLY caller of `receiveAllRemaining` (§6.7b). S2 adds no new caller. The 422 body is `{"error":{"code":"BLIND_REQUIRES_COUNTED_RECEIPT","message":"<static>","details":[]}}`; `details` is a PHP array, so the empty case encodes as the JSON array `[]`, never `{}`. T18 asserts that representation verbatim.

**Gate r3 BLOCKER B1 — what changed here and why.** Rev 3 ended this paragraph with a direct call, `StockTransferReceiptService::receiveAllRemaining($transfer, $user, …)`, which contradicted both the string signature §7.5 rule 9 states and the §6.7b decision that `StockTransferService::complete()` is the one published forwarder. **S2's only edit to `StockTransferController::complete` is the `canSeeExpected` gate and its 422** — one `if` block, inserted between the destination-access check and the existing `try`. The action's final body, with S1's two deltas and this one, is reproduced in full at §6.7b block (c); S2 changes nothing else in it, keeps the **200** status, and adds no new use of `$this->receiptService`. The controller property S2 adds for this gate is `private readonly ExpectedQuantityVisibility $expectedQuantityVisibility` — the concrete class, not the Shared interface, because `canSeeExpected` is deliberately not on the interface (§6.1, §8.5) — taking `StockTransferController`'s constructor from six arguments after S1 to **seven** after S2. That is the only constructor change S2 makes to this class.

The `sys:` namespace is enforced at the **request** layer (rule A6), not in the service: the service accepts the key it is handed, and no client key can ever start with `sys:`.

### 8.7 Fraud-settings transactional write, `visibility_version`, `ReceivingControlsChangedV1`

`FraudSettingsController::update` and `::reset` each move their whole body into **one** `DB::transaction` executing these five steps in order:

1. `DB::table('company_fraud_settings')->insertOrIgnore([...CompanyFraudSettings::getDefaults(), 'id' => (string) Str::orderedUuid(), 'company_id' => $companyId, 'created_at' => now(), 'updated_at' => now()])` — PostgreSQL `INSERT … ON CONFLICT DO NOTHING` against the unique `company_id` (`apps/api/database/migrations/tenant/2025_12_23_160000_create_company_fraud_settings_table.php:35`). This replaces `updateOrCreate`'s unguarded create race (`apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:146`, `:200`). The id is explicit because the query builder bypasses the `HasUuids` generator.
2. `$settings = CompanyFraudSettings::query()->where('company_id', $companyId)->lockForUpdate()->firstOrFail();` — concurrent writers for the same company serialise here. `$before = $settings->blind_receiving;` is read from the **locked** row.
3. `$settings->fill($validated)->save();` — for `reset`, fill with **`Arr::except(CompanyFraudSettings::getDefaults(), self::REFUND_EXPOSURE_KEYS)`**, byte-for-byte the argument the shipped `reset()` already passes at `apps/api/app/Modules/Compliance/Presentation/Controllers/FraudSettingsController.php:200-203`, and the one accepted spec §4.5 requires. **Not `CASH_CONTROL_KEYS`** (gate r1 MAJOR M1): `Arr::except` names the fields **preserved** through the reset, so that constant would preserve the cash-control thresholds and reset the three refund-exposure ceilings — the exact widening the controller's own docblock at `:40-58` exists to prevent. The two constants and their separate jobs are tabulated in §8.4. The **only** change this step makes to `reset` is that its body moves inside the transaction and `updateOrCreate` is replaced by the step-1 `insertOrIgnore` + step-2 `lockForUpdate` pair; the `Arr::except` argument is untouched.
4. **One atomic bump:**

```php
        $row = DB::connection()->selectOne(
            'UPDATE company_fraud_settings SET visibility_version = visibility_version + 1 WHERE id = ? RETURNING visibility_version',
            [$settings->id],
            false,
        );

        $version = (int) $row->visibility_version;

        $settings->setRawAttributes(
            array_merge($settings->getAttributes(), ['visibility_version' => $version]),
            true,
        );
```

   The third argument `false` forces the **write** PDO. Eloquent's `increment()` is deliberately not used: it would advance the in-memory attribute from the pre-lock hydration, so the DTO could return a stale number. `RETURNING` is valid on PostgreSQL and on SQLite ≥ 3.35, which the test lane uses; no other SQL differs per driver.

5. The stored settings event, **only when `$before !== $settings->blind_receiving`**:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Events;

use App\Shared\Domain\Events\DomainEvent;

final class ReceivingControlsChangedV1 extends DomainEvent
{
    public function __construct(
        public readonly string $settingsId,
        public readonly string $companyId,
        public readonly string $tenantId,
        public readonly string $changedByUserId,
        public readonly bool $blindReceivingBefore,
        public readonly bool $blindReceivingAfter,
        public readonly int $visibilityVersion,
        public readonly string $occurredAt,
    ) {
        parent::__construct();
    }

    public function eventName(): string
    {
        return 'compliance.receiving_controls.changed.v1';
    }
}
```

   It is persisted through `StoredEventRepository::persist($event, $settings->id)` with `setAggregateRootVersion($version)` — the value **returned** by step 4. The aggregate is the settings row; because versions are handed out under the row lock, the unique `(aggregate_uuid, aggregate_version)` (`apps/api/database/migrations/tenant/2025_11_30_102448_create_stored_events_table.php:23`) can no longer collide. It is **not** routed through `event()` (§6.4), so the Compliance domain-event subscriber is not involved.

**Invariant I8, asserted by T19 and the settings-initialisation S-matrix row.** The row is initialised at `visibility_version = 1` by exactly one writer outside the controller: `CompanyFraudSettingsService::ensureForCompany()` (`apps/api/app/Modules/Compliance/Application/Services/CompanyFraudSettingsService.php:18`), invoked by `EnsureFraudSettingsOnCompanyCreated` on `CompanyCreated` (`apps/api/app/Modules/Compliance/Listeners/EnsureFraudSettingsOnCompanyCreated.php:17`, wired at `apps/api/app/Providers/EventServiceProvider.php:69`). It writes `getDefaults()`, which does **not** carry `visibility_version`, so the column DEFAULT 1 applies; it emits **no** `ReceivingControlsChangedV1`; and no seeder writes `company_fraud_settings`. S2 changes none of that code — it asserts it.

**Carriage, and where its test lives (gate r1 M5, extended by gate r2 M2).** `visibility_version` is emitted as `visibility_version` inside both transfer builders (beside `blind`), as `meta.visibility_version` on the transfer **list**, inside the fraud-settings DTO, and — the fourth surface, added in rev 3 — as a top-level member of the reconciliation body (§8.7b) — **all four of those are S2 and are asserted by `T19-S2`**. S3 adds it to `meta` on the stock matrix, POS stock-levels, POS stock-distribution and the two replenishment feeds — **those five surfaces are `T19-S3`, asserted by the version column of `TransferBlindLeakOracleTest` steps 6h/6i/6j/6k/6l and re-asserted at `v+1`/`v+2` in step 8, and S3 re-runs the whole T19 row** (§2.2 deviation 5, §9.6, §13). Rev 1 assigned T19 whole to S2, which could not have asserted five surfaces that do not exist until S3. It is **not** carried on `GET /stock-movements` or `GET /entry-exit-notes` (§9.5).

**What the version does and does not guarantee.** Nothing on the server depends on the client's stored version: every payload is built for the requesting actor at request time, and no server-side response cache exists on any of these controllers. `visibility_version` is **advisory** — a cache-invalidation hint. Clients use it best-effort. **Residual R4:** a client may keep showing data it fetched before a flip until its next successful response for that surface. No server path ever returns a hidden figure after the flip; the exposure is bounded by data the client had already legitimately received under the previous setting. Enabling blind receiving is effective server-side **at commit** (OD-4).

### 8.7b `TransferReconciliationData` after S2 — complete file (closes gate r2 MAJOR M2)

**The defect.** Accepted spec §5.4 lists `visibility_version` as a member of the reconciliation body (`docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md:386-388`). Rev 2 reproduced `TransferReconciliationData` in §7.12 and called it complete, but its constructor carried transfer identity, status, locations, close fields, lines, receipts and summary and **no version** — so the generated web type would have been wrong. Rev 2 also assigned S2 "version carriage to full transfer payloads and five aggregate surfaces" without listing `TransferReconciliationData.php`, `TransferReconciliationService.php` or the reconciliation response mapping in S2's files or ledger.

**The assignment.** The member belongs to **S2**, for the same reason `blind` does: `visibility_version` is a `company_fraud_settings` column that migration 1d creates in this slice, and S1's dispatch packet forbids creating it (§17.1). S2's exact file list (§8.2) now carries all three files, push 2 carries them (§11.2), and both are in the contention register with their per-push deltas (§11.6).

**Where the value comes from.** Not from a Compliance model, and not from a request parameter. `TransferReconciliationService` constructor-injects the Shared contract `App\Shared\Contracts\TransferIncomingVisibility` — the same binding surfaces 7–9 and 17–18 use (§6.1) — and reads `visibilityVersion($companyId)` **once per request**, for the **requesting company** resolved from `CompanyContext::requireCompany()`, exactly as `TransferPayloadBuilder` does (§8.6). It is never read per line, never per receipt and never cached across requests.

```php
final class TransferReconciliationService
{
    public function __construct(
        private readonly TransferIncomingVisibility $visibility,
    ) {
    }

    public function build(StockTransfer $transfer): TransferReconciliationData
    {
        $lines = $this->buildLines($transfer);
        $receipts = $transfer->receipts
            ->map(static fn (StockTransferReceipt $receipt): TransferReconciliationReceiptData => TransferReconciliationReceiptData::fromModel($receipt))
            ->all();

        return new TransferReconciliationData(
            transfer_id: (string) $transfer->id,
            transfer_number: (string) $transfer->transfer_number,
            status: $transfer->status,
            source_location_id: (string) $transfer->source_location_id,
            source_location_name: (string) ($transfer->sourceLocation->name ?? ''),
            destination_location_id: (string) $transfer->destination_location_id,
            destination_location_name: (string) ($transfer->destinationLocation->name ?? ''),
            initiated_at: (string) $transfer->initiated_at?->toIso8601String(),
            closed_at: $transfer->closed_at?->toIso8601String(),
            close_disposition: $transfer->close_disposition,
            close_reason: $transfer->close_reason,
            close_note: $transfer->close_note,
            lines: $lines,
            receipts: $receipts,
            summary: $this->buildSummary($transfer, $lines),
            visibility_version: $this->visibility->visibilityVersion($transfer->company_id),
        );
    }
}
```

`buildLines()` and `buildSummary()` are the two private methods S1 wrote and S2 does not touch; the constructor argument and the last named argument are the whole of the S2 delta.

**The complete file after S2** — this replaces `apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationData.php` in place, and it is the version the generated TypeScript must match:

```php
<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Application\DTOs;

use App\Modules\Inventory\Domain\Enums\TransferCloseDisposition;
use App\Modules\Inventory\Domain\Enums\TransferDiscrepancyReason;
use App\Modules\Inventory\Domain\Enums\TransferStatus;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The `GET /stock-transfers/{transfer}/reconciliation` body (§5.4).
 *
 * Built ONLY by TransferReconciliationService. The route is gated on
 * `inventory.transfers.reconcile`, so its reader is by definition a
 * `canSeeExpected() = true` actor and this DTO carries expected quantities
 * on purpose; it is NEVER returned from any other endpoint.
 *
 * `visibility_version` is the requesting company's receiving-controls version
 * (spec §5.4). It is ADVISORY — a cache-invalidation hint, exactly as on the
 * transfer payloads and the fraud-settings DTO (§8.7). It is an int, matching
 * the SIGNED integer column migration 1d creates:
 * $table->integer('visibility_version')->default(1), i.e. the accepted spec's
 * `visibility_version integer NOT NULL DEFAULT 1`. Never unsignedInteger.
 * Generated TypeScript: visibility_version: number.
 */
#[TypeScript]
final class TransferReconciliationData extends Data
{
    /**
     * @param  list<TransferReconciliationLineData>  $lines
     * @param  list<TransferReconciliationReceiptData>  $receipts
     */
    public function __construct(
        public string $transfer_id,
        public string $transfer_number,
        public TransferStatus $status,
        public string $source_location_id,
        public string $source_location_name,
        public string $destination_location_id,
        public string $destination_location_name,
        public string $initiated_at,
        public ?string $closed_at,
        public ?TransferCloseDisposition $close_disposition,
        public ?TransferDiscrepancyReason $close_reason,
        public ?string $close_note,
        public array $lines,
        public array $receipts,
        public TransferReconciliationSummaryData $summary,
        public int $visibility_version,
    ) {
    }
}
```

The delta against §7.12's S1 file is exactly **one appended property**, `public int $visibility_version`, in last position so no existing named or positional argument moves; every other line is byte-identical to the S1 file. `TransferReconciliationLineData`, `TransferReconciliationLotData`, `TransferReconciliationSummaryData` and `TransferReconciliationReceiptData` are **not** modified by S2 — the version is a body-level member, not a per-line or per-receipt one, exactly as spec §5.4 places it.

**`T19-S2` gains two assertions for this surface** (§8.11): one exact API assertion and one generated-type assertion. Both are named in full there rather than described here.

### 8.8 Route and payload deltas

| Route | After S1 | After S2 |
|---|---|---|
| `GET /stock-transfers` | three-permission any-of; full builder for everyone | unchanged gate; builder chosen **per transfer**; `meta.visibility_version` added |
| `GET /stock-transfers/{transfer}` | three-permission any-of; full builder | unchanged gate; builder chosen per transfer; `data.visibility_version` added |
| `GET /stock-transfers/{transfer}/receiver-view` | — | **new**, three-permission any-of, always the receiver builder |
| `POST /stock-transfers/{transfer}/receive` | `can:inventory.transfers.complete` | unchanged gate; the `transfer` member of the 201 body now goes through the gated builder |
| `POST /stock-transfers/{transfer}/complete` | `can:inventory.transfers.complete`, delegates | unchanged gate; **blind actor → 422 `BLIND_REQUIRES_COUNTED_RECEIPT`** before the idempotency lookup |
| `POST /stock-transfers/{transfer}/close` | reconcile + close | unchanged; the closer always holds reconcile, hence `canSeeExpected`, so `receipt` carries the close quantities |
| `GET /stock-transfers/{transfer}/reconciliation` | `can:inventory.transfers.reconcile` | unchanged |
| `PATCH /fraud-settings`, `POST /fraud-settings/reset` | untouched | five-step transactional write; DTO carries the returned `visibility_version` |

Clients never read `/fraud-settings` to decide the mode (it is gated on `can:fraud-settings.view`, `apps/api/app/Modules/Compliance/Presentation/routes.php:23`, which a receiver does not hold); they read `blind` and `visibility_version` from the payload they already fetched.

### 8.9 Residuals, restated as implementation constraints

These are accepted by the owner and must be **implemented as stated**, not closed:

- **R1** — probing `OVER_RECEIPT` reveals an upper bound. Accepted; the typed body still carries no figure.
- **R2** — once a transfer is terminal, destination stock history shows what landed. Accepted; S3's masking is scoped to **carrying** transfers only.
- **R3** — the coarse status `partially_received` is visible to a blind receiver. Accepted; the status is never masked or renamed.
- **R4** — client-side staleness across a flip (§8.7). Accepted and bounded by the advisory version plus zero-staleness queries in S4.
- **R5** — current-stock delta inference, including the multi-receiver composition in which a second receiver's posting moves the position while `NOTHING_TO_RECEIVE` proves the line is exhausted. **Accepted.** No slice may mask a stock position to close it; S3 asserts it is reachable rather than pretending it is closed.

### 8.10 Convention-09 evidence (S-matrix)

| Writer | Second company | Second location | Re-run / idempotency |
|---|---|---|---|
| settings update (`PATCH /fraud-settings`) | setting `blind_receiving = true` in **B** makes B's payloads blind and leaves A's full; each company's `visibility_version` advances independently (A unchanged, B +1); the first write for a company with no row creates exactly one row | n/a (company-level) | update twice with the same body → one row, `visibility_version` +2, exactly **one** `ReceivingControlsChangedV1` (the second write is not a flip) |
| settings reset (`POST /fraud-settings/reset`) | reset in B leaves A's `blind_receiving` as it was; B's returns to `false`; **B's three `REFUND_EXPOSURE_KEYS` columns keep the non-default values the fixture wrote directly, and so do A's** (gate r1 M1) | n/a | reset twice → one row, `visibility_version` +2, one flip event on the first if it flipped, none on the second; the refund-exposure columns are unchanged after **both** resets |
| settings initialisation (`CompanyCreated` → `ensureForCompany`) | a real `POST /api/v1/companies` for B creates exactly one `company_fraud_settings` row for B with `visibility_version = 1`, `blind_receiving = false` and **zero** `ReceivingControlsChangedV1` rows for B; A's row and version are untouched | n/a | `ensureForCompany(B)` called again → the same row, still version 1, still no event |
| receiver projection | a B actor requesting A's `receiver-view` gets 404 through `canSeeTransfer`, whatever the setting in either company | a source-only member of L1 with `complete` is refused `receive` at L2 with 403 `LOCATION_ACCESS_DENIED`; a destination member of L2 succeeds | the same transfer requested twice in one test — once as a reconcile holder, then as the blind receiver — yields the full shape then the receiver shape (no memoised builder, no response cache) |

### 8.11 Red-first tests

| Exact file | Exact class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Inventory/TransferReceiverPayloadBuilderKeyScanTest.php` | `TransferReceiverPayloadBuilderKeyScanTest::test_no_never_include_key_appears_at_any_depth` (T9b) | `self::assertSame([], $foundKeys, implode(PHP_EOL, $foundKeys));` on a fixture whose transfer has notes, costs, a cancellation reason, two other users' receipts and the close columns populated | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiverPayloadBuilderKeyScanTest::test_no_never_include_key_appears_at_any_depth'` | PHPUnit PostgreSQL (`autoerp_test_v`) |
| same | `TransferReceiverPayloadBuilderKeyScanTest::test_no_numeric_value_equals_a_sent_or_remaining_quantity` (T9b) | `self::assertSame([], $matches);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiverPayloadBuilderKeyScanTest::test_no_numeric_value_equals_a_sent_or_remaining_quantity'` | PHPUnit PostgreSQL |
| same | `TransferReceiverPayloadBuilderKeyScanTest::test_adding_a_forbidden_key_to_the_literal_fails_the_detector` (T9b liveness) | `self::assertNotSame([], $foundKeys);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiverPayloadBuilderKeyScanTest::test_adding_a_forbidden_key_to_the_literal_fails_the_detector'` | PHPUnit PostgreSQL |
| same | `TransferReceiverPayloadBuilderKeyScanTest::test_builder_selection_is_per_request_not_memoised` (T9b) | `self::assertTrue($secondResponse->json('data.blind'));` after the same transfer was fetched as a reconcile holder | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferReceiverPayloadBuilderKeyScanTest::test_builder_selection_is_per_request_not_memoised'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/TransferAuthorityMatrixTest.php` | `TransferAuthorityMatrixTest::test_reconcile_only_user_reaches_transfer_list_show_and_reconciliation` (T18) | `$this->actingAs($reconcileOnly)->getJson('/api/v1/stock-transfers')->assertOk();` **and** `self::assertFalse($show->json('data.blind'));` with the full-builder members present | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_reconcile_only_user_reaches_transfer_list_show_and_reconciliation'` | PHPUnit PostgreSQL |
| same | `TransferAuthorityMatrixTest::test_complete_only_receiver_gets_the_receiver_shape_with_the_setting_on` (T18) | `self::assertTrue($show->json('data.blind'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_complete_only_receiver_gets_the_receiver_shape_with_the_setting_on'` | PHPUnit PostgreSQL |
| same | `TransferAuthorityMatrixTest::test_blind_actor_cannot_post_a_quantity_less_completion` (T18, OD-1) | `$response->assertStatus(422)->assertJsonPath('error.code', 'BLIND_REQUIRES_COUNTED_RECEIPT');` and `self::assertSame([], $response->json('error.details'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_blind_actor_cannot_post_a_quantity_less_completion'` | PHPUnit PostgreSQL |
| same | `TransferAuthorityMatrixTest::test_close_only_user_reaches_no_read_route` (T18) | `$this->actingAs($closeOnly)->getJson('/api/v1/stock-transfers')->assertForbidden();` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_close_only_user_reaches_no_read_route'` | PHPUnit PostgreSQL |
| same | `TransferAuthorityMatrixTest::test_seeded_manager_and_admin_hold_both_new_permissions_and_no_other_role_does` (T18) | `self::assertSame(['admin', 'manager'], $rolesHoldingReconcile);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_seeded_manager_and_admin_hold_both_new_permissions_and_no_other_role_does'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php` | `ReceivingControlsVisibilityVersionTest::test_every_settings_write_advances_the_version_in_row_dto_and_next_payload` (**T19-S2**) | `self::assertSame($rowVersion, $response->json('data.visibility_version'));` — fails today, the key does not exist. The method then asserts the **two S2 carriage surfaces**: `self::assertSame($rowVersion, $show->json('data.visibility_version'));` on `GET /stock-transfers/{id}` and `self::assertSame($rowVersion, $list->json('meta.visibility_version'));` on `GET /stock-transfers`. **The five S3 carriage surfaces are `T19-S3` and are NOT asserted here** (§2.2 deviation 5) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsVisibilityVersionTest::test_every_settings_write_advances_the_version_in_row_dto_and_next_payload'` | PHPUnit PostgreSQL |
| same | `ReceivingControlsVisibilityVersionTest::test_the_reconciliation_body_carries_the_current_visibility_version` (**T19-S2**, rev 3, gate r2 M2 — the exact API assertion) | `self::assertSame($rowVersion, $reconciliation->json('data.visibility_version'));` on `GET /api/v1/stock-transfers/{id}/reconciliation` as a reconcile holder — **red today**, because `TransferReconciliationData` declares no such property, so `json('data.visibility_version')` returns `null` while `$rowVersion` is an int. The method then advances the version with a second `PATCH /fraud-settings` and re-issues the same request, asserting `self::assertSame($rowVersion + 1, $reconciliation2->json('data.visibility_version'));`, and asserts company B's reconciliation body still carries **B's** version, not A's | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsVisibilityVersionTest::test_the_reconciliation_body_carries_the_current_visibility_version'` | PHPUnit PostgreSQL |
| same | `ReceivingControlsVisibilityVersionTest::test_the_generated_reconciliation_type_declares_the_version` (**T19-S2**, rev 3, gate r2 M2 — the generated-type assertion) | `self::assertMatchesRegularExpression('/interface TransferReconciliationData \{[^}]*visibility_version: number;/s', file_get_contents(base_path('../../packages/shared/types/generated.d.ts')));` — red today on two counts: the interface carries no such member, and before S1 runs the interface does not exist at all | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsVisibilityVersionTest::test_the_generated_reconciliation_type_declares_the_version'` | PHPUnit PostgreSQL |
| same | `ReceivingControlsVisibilityVersionTest::test_only_a_flip_persists_a_receiving_controls_changed_event` (**T19-S2**) | `self::assertSame(1, StoredEvent::query()->where('event_class', ReceivingControlsChangedV1::class)->count());` after two writes of which one flips | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsVisibilityVersionTest::test_only_a_flip_persists_a_receiving_controls_changed_event'` | PHPUnit PostgreSQL |
| same | `ReceivingControlsVisibilityVersionTest::test_first_write_for_a_company_with_no_row_creates_exactly_one_row_at_version_two` (**T19-S2**) | `self::assertSame(2, (int) $settings->visibility_version);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsVisibilityVersionTest::test_first_write_for_a_company_with_no_row_creates_exactly_one_row_at_version_two'` | PHPUnit PostgreSQL |
| same | `ReceivingControlsVisibilityVersionTest::test_reset_restores_blind_receiving_and_preserves_the_refund_exposure_ceilings` (**T19-S2**, gate r1 M1) | The fixture writes the three `REFUND_EXPOSURE_KEYS` columns to **non-default** values by direct DB write, sets `blind_receiving = true` through `PATCH /fraud-settings`, then calls `POST /fraud-settings/reset`. First failing assertion: `self::assertFalse((bool) $settings->refresh()->blind_receiving);` — fails today because the column does not exist. The method then asserts, in order, `self::assertSame('3', (string) $settings->offline_refund_count_ceiling);`, `self::assertSame('250.000', (string) $settings->offline_refund_value_ceiling);` and `self::assertSame('500.000', (string) $settings->online_required_refund_threshold);` — the fixture's non-default values, **unchanged by the reset**. Had the implementer used `CASH_CONTROL_KEYS`, these three would come back as `getDefaults()` values and the test goes red. It also asserts the seven `CASH_CONTROL_KEYS` columns **did** return to their defaults, so the assertion cannot be satisfied by excepting everything | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsVisibilityVersionTest::test_reset_restores_blind_receiving_and_preserves_the_refund_exposure_ceilings'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Compliance/ReceivingControlsConcurrencyPostgresTest.php` | `ReceivingControlsConcurrencyPostgresTest::test_two_parallel_settings_writes_serialise_on_the_row_lock` (T19b) | `self::assertSame([$n + 1, $n + 2], $returnedVersions);` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Compliance/ReceivingControlsConcurrencyPostgresTest.php --filter test_two_parallel_settings_writes_serialise_on_the_row_lock` | **PG-only**, exclusive database (`autoerp_test_v`) |
| same | `ReceivingControlsConcurrencyPostgresTest::test_two_parallel_first_writes_create_one_row` (T19b) | `self::assertSame(1, CompanyFraudSettings::query()->where('company_id', $company->id)->count());` | `cd apps/api && sh docs/sessions/t1/pg-test.sh tests/Feature/Compliance/ReceivingControlsConcurrencyPostgresTest.php --filter test_two_parallel_first_writes_create_one_row` | **PG-only** |
| `apps/api/tests/Feature/Compliance/ReceivingControlsSecondOfEverythingTest.php` | `ReceivingControlsSecondOfEverythingTest::test_second_company_settings_are_independent_and_initialisation_emits_no_event` (S-matrix) | `self::assertSame(1, (int) $settingsB->visibility_version);` with zero `ReceivingControlsChangedV1` rows for B | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'ReceivingControlsSecondOfEverythingTest::test_second_company_settings_are_independent_and_initialisation_emits_no_event'` | PHPUnit PostgreSQL |

**RED-FIRST versus CONTROL for S2 (gate r2 M5 — the classification is stated for every slice, not only the two the gate flagged).** Gate r2 assessed S2's red-first row as "Present for core S2 cases", and the audit behind rev 3 confirms it: **every method in the table above is RED-FIRST, and S2 ships no CONTROL row.** The reason is structural rather than lucky — `blind_receiving` and `visibility_version` are columns migration 1d creates in this slice, `TransferReceiverPayloadBuilder` and the receiver-view route do not exist before it, and every S2 assertion reads one of those four things, so no S2 assertion can be satisfied by the pre-slice tree. The one method that could have been mistaken for a control, `TransferReceiverPayloadBuilderKeyScanTest::test_adding_a_forbidden_key_to_the_literal_fails_the_detector`, is red-first because the builder it mutates does not exist yet, so the method errors before its `assertNotSame` runs. S2's handback records `RED` in the classification column for every method of the table above **and** for every authority case of §8.11b; a `PASS` at capture in any of them blocks the slice exactly as §7.14b and §9.9b specify for S1 and S3.

### 8.11b T18 — the complete authority matrix as executable cases (closes gate r1 MAJOR M6)

Rev 1 asserted the matrix in prose and named four methods that did not cover it. Spec §5.8 requires a **complete** matrix; below it is enumerated as **eight** named actors × the six routes, every cell carrying an expected HTTP status and, where the body is a typed error, its `error.code`. Every cell is an executable assertion in `apps/api/tests/Feature/Inventory/TransferAuthorityMatrixTest.php`; nothing here is prose.

**Actors** (all members of the destination location L2 of company A unless the row says otherwise; none is `admin`; each is built by granting exactly the listed permissions to a fresh role, never by editing a seeded role):

| # | Actor | Permissions held | Membership |
|---|---|---|---|
| A1 | `viewOnly` | `inventory.transfers.view`, `inventory.view` | L2 |
| A2 | `completeOnly` | `inventory.transfers.complete`, `inventory.view` | L2 |
| A3 | `closeOnly` | `inventory.transfers.close`, `inventory.view` | L2 |
| A4 | `reconcileOnly` | `inventory.transfers.reconcile`, `inventory.view` | L2 |
| A5 | `reconcileAndClose` | `inventory.transfers.reconcile`, `inventory.transfers.close`, `inventory.view` | L2 |
| A6 | `manager` | the **seeded** `manager` role, unmodified | L1 **and** L2 (unrestricted) |
| A7 | `sourceOnly` | `inventory.transfers.complete`, `inventory.view` | **L1 only** — the transfer's source, not its destination |
| A8 | `noPermission` | `inventory.view` only | L2 |

**The matrix.** `blind_receiving` is **ON** for company A for every row, because that is the state that makes the builder choice observable; the two rows that depend on the setting being off are stated separately below.

| Route | A1 viewOnly | A2 completeOnly | A3 closeOnly | A4 reconcileOnly | A5 reconcile+close | A6 manager | A7 sourceOnly | A8 noPermission |
|---|---|---|---|---|---|---|---|---|
| `GET /stock-transfers` | **200** | **200** | **403** | **200** | **200** | **200** | **200** | **403** |
| `GET /stock-transfers/{T}` | **200**, `data.blind === true` (receiver shape: no `transfer_cost`, no `notes`, no `initiated_by_name`) | **200**, `data.blind === true` | **403** | **200**, `data.blind === false` (full shape) | **200**, `data.blind === false` | **200**, `data.blind === false` | **200**, `data.blind === false` — source access is the third `canSeeExpected` disjunct (§8.5) | **403** |
| `GET /stock-transfers/{T}/receiver-view` | **200**, always the receiver shape | **200**, receiver shape | **403** | **200**, receiver shape (unconditional for **every** actor, §8.6) | **200**, receiver shape | **200**, receiver shape | **403** — `canAccessLocation(destination)` fails; body `error.code === 'LOCATION_ACCESS_DENIED'` | **403** |
| `POST /stock-transfers/{T}/receive` | **403** — `receive` is deliberately NOT under the any-of gate (§7.6) | **201** | **403** | **403** | **403** | **201** | **403**, `error.code === 'LOCATION_ACCESS_DENIED'` | **403** |
| `POST /stock-transfers/{T}/complete` | **403** | **422**, `error.code === 'BLIND_REQUIRES_COUNTED_RECEIPT'`, `error.details === []` (OD-1) | **403** | **403** | **403** | **200** — manager holds reconcile, so `canSeeExpected` is true and the delegate runs | **403**, `error.code === 'LOCATION_ACCESS_DENIED'` | **403** |
| `POST /stock-transfers/{T}/close` | **403** | **403** | **403** — holds `close` but not `reconcile`; the first chained `can:` refuses | **403** — holds `reconcile` but not `close`; the second chained `can:` refuses | **201** | **201** | **403** | **403** |
| `GET /stock-transfers/{T}/reconciliation` | **403** | **403** | **403** | **200** | **200** | **200** | **200** — reconciliation accepts `canAccessLocation(source)` (§7.5) | **403** |

Two setting-dependent cells, asserted in their own method with `blind_receiving = false`: A2's `GET /stock-transfers/{T}` returns `data.blind === false` (the **full** shape) and A2's `POST /complete` returns **200**, not 422. That pair is what proves the blind gate is the setting and not the permission.

**Method decomposition** — one method per actor, so a failure names the actor:

| Exact class::method | First failing assertion | Exact command |
|---|---|---|
| `TransferAuthorityMatrixTest::test_view_only_actor_row` | `$this->actingAs($viewOnly)->getJson("/api/v1/stock-transfers/{$t->id}")->assertOk()` then `self::assertTrue($show->json('data.blind'));` — fails today: `blind` does not exist, so `json()` is `null` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_view_only_actor_row'` |
| `TransferAuthorityMatrixTest::test_complete_only_actor_row` | `$this->actingAs($completeOnly)->getJson('/api/v1/stock-transfers')->assertOk();` — fails **403** today, the route is `can:inventory.transfers.view` (`apps/api/app/Modules/Inventory/Presentation/routes.php:99`) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_complete_only_actor_row'` |
| `TransferAuthorityMatrixTest::test_close_only_actor_row` | `$this->actingAs($closeOnly)->getJson('/api/v1/stock-transfers')->assertForbidden();` — the proof that `inventory.transfers.close` is **not** a disjunct of the read any-of set. Fails today because the permission does not exist and role creation errors | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_close_only_actor_row'` |
| `TransferAuthorityMatrixTest::test_reconcile_only_actor_row` | `$this->actingAs($reconcileOnly)->getJson("/api/v1/stock-transfers/{$t->id}/reconciliation")->assertOk();` — 404 today, the route does not exist | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_reconcile_only_actor_row'` |
| `TransferAuthorityMatrixTest::test_reconcile_and_close_actor_row` | `$this->actingAs($reconcileAndClose)->postJson($closeUrl, $body)->assertStatus(201);` — 404 today | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_reconcile_and_close_actor_row'` |
| `TransferAuthorityMatrixTest::test_seeded_manager_row_is_2xx_on_every_route` | `self::assertSame([200, 200, 200, 201, 200, 201, 200], $statuses);` for the seven cells in table order — fails on the first missing route | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_seeded_manager_row_is_2xx_on_every_route'` |
| `TransferAuthorityMatrixTest::test_source_only_actor_is_refused_receive_and_receiver_view` | `$receive->assertStatus(403)->assertJsonPath('error.code', 'LOCATION_ACCESS_DENIED');` — 404 today | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_source_only_actor_is_refused_receive_and_receiver_view'` |
| `TransferAuthorityMatrixTest::test_no_permission_actor_is_forbidden_on_every_route` | `self::assertSame([403, 403, 403, 403, 403, 403, 403], $statuses);` — today the two missing routes answer 404, so the array differs | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_no_permission_actor_is_forbidden_on_every_route'` |
| `TransferAuthorityMatrixTest::test_blind_actor_cannot_post_a_quantity_less_completion` (OD-1) | `$response->assertStatus(422)->assertJsonPath('error.code', 'BLIND_REQUIRES_COUNTED_RECEIPT');` and `self::assertSame([], $response->json('error.details'));` — the empty-array wire form asserted verbatim | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_blind_actor_cannot_post_a_quantity_less_completion'` |
| `TransferAuthorityMatrixTest::test_with_the_setting_off_the_complete_only_actor_gets_the_full_shape_and_a_200_complete` | `self::assertFalse($show->json('data.blind'));` then `$complete->assertOk();` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_with_the_setting_off_the_complete_only_actor_gets_the_full_shape_and_a_200_complete'` |
| `TransferAuthorityMatrixTest::test_seeded_manager_and_admin_hold_both_new_permissions_and_no_other_role_does` | `self::assertSame(['admin', 'manager'], $rolesHoldingReconcile);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferAuthorityMatrixTest::test_seeded_manager_and_admin_hold_both_new_permissions_and_no_other_role_does'` |

Every 403 in the matrix is asserted to carry the **static** body — `RequireAnyPermission`'s genericised sentence (§7.6) or the framework `can` body — and `self::assertStringNotContainsString('12.0000', $response->getContent())` runs on every 403 and 422 of the whole matrix, so no authorization refusal can leak a fixture quantity.

### 8.12 Reviewers, order and rollback

Order: (0) **raise the §6.7 ceilings and extend the allowlist in the same commit as the first test class, and confirm `php tools/feature-lane-manifest-check.php` exits 0**; (1) capture the red assertions; (2) migration 1d and the model surfaces; (3) the two contracts, both implementations and their bindings; (4) `TransferReceiverPayloadBuilder`, the five `TransferReceiverViewData` DTOs of §8.6b and the T9b key scan; (5) the builder choice on list/show plus the receiver-view route; (6) the `complete` blind gate; (7) the five-step settings write with the `REFUND_EXPOSURE_KEYS` reset argument, the atomic bump and the stored event; (8) T18's eleven methods (§8.11b), `T19-S2`, T19b and the S-matrix; (9) `php artisan typescript:transform`.

Required reviewers, all returning `ACCEPT` with `BLOCKER=0 MAJOR=0`:

- **`tenancy-authz-reviewer`** — must explicitly cite: that `canSeeIncomingAggregates` treats `null` as unrestricted and `[]` as fail-closed; that the receiver-view route carries the same three-permission any-of and both `canSeeTransfer`/`canAccessLocation` checks; that the builder choice is per transfer and per request; that `visibility_version` is not client-writable; that no module imports a Compliance model for the blind decision; and that the T18 close-only row proves `inventory.transfers.close` is **not** in the read any-of set.
- **`inventory-costing-reviewer`** — must explicitly cite: that the receiver shape carries no cost member (`transfer_cost`, `unit_cost_snapshot`, `allocated_transfer_cost`, `freight_uncapitalized`), that no quantity semantics changed, and that `visibility_version` is an `int` and every other new member is a string or a boolean.
- **`fiscal-pos-reviewer`** — must explicitly cite the stored-event handling: `persist($event, $settings->id)` with the returned version, no `event()` double-store, one event per actual flip, and the row lock preventing an `(aggregate_uuid, aggregate_version)` collision. **Rev 2 additions:** that `reset()` still passes `self::REFUND_EXPOSURE_KEYS` to `Arr::except` and that the three refund-exposure columns are asserted unchanged across a reset (gate r1 M1), and that the `T19-S2` / `T19-S3` split is honoured — S2 asserts only the three carriage surfaces it ships.
- **`tenancy-authz-reviewer`** (rev 2 addition to its cite list, gate r1 B1) — that `apps/api/tests/feature-lane-manifest.json` carries `Inventory 143`, `Compliance 26`, `gated_ceiling 1272`, that the raise notes contain all four §6.7.4 statements with no observed-CI claim, that all five class names appear in the `.github/workflows/ci.yml:1142` alternation, and that `php tools/feature-lane-manifest-check.php` exits 0 on the slice tip. **Rev 3 additions (gate r2 M2/B1):** that `TransferReconciliationData` carries `visibility_version` as its sixteenth property with every other line byte-identical to the S1 file, that `TransferReconciliationService` sources it from `TransferIncomingVisibility::visibilityVersion($companyId)` and from no Compliance model (CLAUDE.md rule 6), that `StockTransferData` gained exactly `blind` and `visibility_version` appended in last position, and that the regenerated `packages/shared/types/generated.d.ts` shows all three deltas.

Rollback: revert the S2 merge commit. Migration 1d is additive and its `down()` drops two columns whose only writer is this slice; because `blind_receiving` defaults to `false`, a revert with the columns left in place is also safe and is the preferred staging action.

---
## 9. Slice S3 — T-3 surface closure and the leak oracle

Worktree `.worktrees/t3-surface-closure`, branch `lane/t3-surface-closure`, PG database `autoerp_test_x`. **Merges after S2.**

### 9.1 Scope and ownership

**Spec sections implemented:** §5.0 rows 7–11 and 17–25, §5.0b (the masked rows and the twelve R5 rows), §5.7, §5.9, §5.10, §10.1.

**§10 rows owned:** **T9-S3** (every step except **6o**, which is `T9-S4`; §2.2 deviation 5), T17, T20, and **T19-S3 — the five carriage surfaces, which CLOSES the T19 row: S3 re-runs S2's `ReceivingControlsVisibilityVersionTest` and `ReceivingControlsConcurrencyPostgresTest` unmodified alongside its own oracle** (§13).

**PG-only classes:** none. T9-S3 and T20 run on **both** lanes; there is no PG-only assertion in either. All three of this slice's classes still enter the `backend-test-pgsql` allowlist, because the `Inventory` group is PARKED and an unlisted class in a parked group executes on no event at all (§6.7.5).

**Feature-lane manifest (gate r1 B1):** this slice adds **3** `Inventory` Feature classes, so it writes `Inventory 143 -> 146`, `gated_ceiling 1272 -> 1275`, and appends all three names to the allowlist. `apps/api/tests/Support/AssertsBlindPayloads.php` is a **trait outside `tests/Feature/`** and carries no group and no ceiling consequence.

**Seams respected:** seam 5 — the masking keys on `reference_type`/`reference_id`, which `markMovementAsTransfer` writes (`apps/api/app/Modules/Inventory/Application/Services/StockTransferService.php:745`), and never on the `StockMovementRecordedV2` label, which is known to disagree with the row.

**Merge safety.** Every masking predicate is `! canSeeIncomingAggregates(...)`, which is unconditionally `false` while `blind_receiving` is off. With the setting off — the default everywhere — every payload in this slice is byte-identical to S2's except for the **added** `meta` booleans, which are all `false`. The one behavioural change that is **not** setting-gated is the entry/exit location-scope fix (§9.5), which narrows a response that is currently company-wide; that is a deliberate authorization correction, covered by T17.

**Files.** No new production file. Modify (lane infrastructure, **rev 2**, gate r1 B1): `apps/api/tests/feature-lane-manifest.json` (Inventory 143→146, `gated_ceiling` 1272→1275) and `.github/workflows/ci.yml` (three class names appended at `:1142`). Modify (production): `apps/api/app/Shared/Contracts/LocationStockReader.php`; `apps/api/app/Shared/DTOs/LocationIncomingRowDTO.php`; `apps/api/app/Shared/DTOs/StockDistributionRowDTO.php`; `apps/api/app/Shared/DTOs/StockDistributionDTO.php`; `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php`; `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php`; `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMatrixController.php`; `apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php`; `apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php`; `apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php`; `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php`; `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php`; `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php`; `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php`. Add (tests): `apps/api/tests/Support/AssertsBlindPayloads.php`; `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php`; `apps/api/tests/Feature/Inventory/TransferMovementMaskingTest.php`; `apps/api/tests/Feature/Inventory/EntryExitNoteLocationScopeTest.php`; `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` (**rev 3**, gate r2 M4 — the committed pre-change entry/exit body for an unrestricted, non-blind actor, captured by the method of §9.9 before the scope fix exists; like the trait and like S1's reader baseline it is **not** a Feature class, so it carries no manifest group and no ceiling consequence).

### 9.2 R5 — the surfaces this slice must NOT change

Owner ruling: blind receiving hides the expected/sent/remaining quantities of the **specific document being received**, and nothing else. The following surfaces are therefore **untouched by this lane**: no masking, no re-gating, no new `meta` flag, no cache drop, no type change. Any diff that touches one of them is a scope violation.

Per errata **E3**, the range is rows 19–**25**:

| Row | Surface | Emitter (must remain exactly as it is) |
|---|---|---|
| 19 | `GET /stock-levels` list and show | `apps/api/app/Modules/Inventory/Presentation/routes.php:57`; `apps/api/app/Modules/Inventory/Application/DTOs/StockLevelData.php:18` |
| 20 | `GET /products/{product}/stock-levels` | `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:1064-1094` rows and `:1096-1139` totals |
| 21 | `GET /inventory/stock-matrix/rebalance` | `apps/api/app/Modules/Inventory/Presentation/routes.php:73`; `apps/api/app/Modules/Inventory/Application/Services/StockRebalanceQueryService.php:92` |
| 22 | Counting reconciliation | `apps/api/app/Modules/Inventory/Presentation/Controllers/CountingItemController.php:185`; `apps/api/app/Modules/Inventory/Application/Services/CountingReconciliationPayloadBuilder.php:76`. The **counter-facing** endpoints stay blind by construction (`apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:334`) and are equally untouched |
| 23 | `GET /batches`, `GET /batches/{uuid}`, `GET /batches/{uuid}/stock`, `GET /products/{productId}/batch-stock`, `GET /pos/products/{productId}/batches` | `apps/api/app/Modules/BatchExpiry/Presentation/routes.php:22`; `apps/api/app/Modules/BatchExpiry/Presentation/Resources/BatchResource.php:55`; `apps/api/app/Modules/BatchExpiry/Application/DTOs/BatchSuggestionResultDTO.php:23` |
| 24 | POS device stock types | `apps/pos/src/lib/db/repositories/locationStockRepository.ts:45`; `apps/pos/src/types/stockDistribution.ts:12`. Only `incoming_transfer` becomes nullable, and that happens in **S4** |
| 25 | `GET /products/{product}` show | `apps/api/app/Modules/Product/routes.php:58`; the `withSum` at `apps/api/app/Modules/Product/Presentation/Controllers/ProductController.php:304`; `apps/api/app/Modules/Product/Application/DTOs/ProductData.php:131` |

Row 24 is a POS **type** row and belongs to S4's file set (E2). Row 25 is the rev-11 addition that four spec prose references still omit (E3). Rows 19–23 and 25 have **zero** files in this slice's ledger; that absence is itself an acceptance criterion, and T9 steps 6p–6u prove the values are still served (§9.6).

Three surfaces are **mixed** — they carry a masked transfer member and an R5 stock-position member in one body, and they are surface rows **7–9**, not 19–25 (errata **E5**): the stock matrix (`cells[].incoming` masked, `cells[].on_hand` R5), `GET /pos/stock-levels` (`incoming[].incoming_transfer` masked, `stock[].quantity` R5) and `GET /pos/products/{product}/stock-distribution` (`incoming_transfer` masked, `on_hand` R5).

### 9.3 Incoming aggregates — surfaces 7, 8, 9 (§5.7)

- `LocationStockReader::read(...)` (`apps/api/app/Shared/Contracts/LocationStockReader.php:24`) and `::stockDistributionForProduct(...)` (`:45`) each gain a trailing `bool $withTransferIncoming = true` parameter. The default keeps every existing caller compiling and behaving identically.
- `LocationIncomingRowDTO::$incomingTransfer` (`apps/api/app/Shared/DTOs/LocationIncomingRowDTO.php:27`), `StockDistributionRowDTO::$incomingTransfer` (`apps/api/app/Shared/DTOs/StockDistributionRowDTO.php:31`) and `StockDistributionDTO::$totalIncomingTransfer` (`apps/api/app/Shared/DTOs/StockDistributionDTO.php:29`) become `?string`. When masked, JSON emits `null` — **never** `"0.0000"`, which would be a false statement about the world.
- Both POS controllers constructor-inject `TransferIncomingVisibility` beside their existing Shared dependencies (`apps/api/app/Modules/POS/Presentation/Controllers/PosStockLevelController.php:51`, `apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:29`), compute `canSeeIncomingAggregates($userId, $companyId)` **once per request**, pass it down, and emit `meta.incoming_transfer_masked` plus `meta.visibility_version`.
- `StockMatrixQueryService::incoming(...)` takes the same flag directly from `StockMatrixController`; when the flag is false, `cells[].incoming` carries the **purchase-order** incoming only, and `meta.incoming_transfer_masked` is `true`. `cells[].on_hand`, `reserved`, `available`, `min_quantity` and `max_quantity` are **not** touched (R5).
- The §7.7 remainder conversion is independent of this flag: masking decides what is emitted, the remainder decides what is computed.

### 9.4 Replenishment feeds — surfaces 17, 18 (§5.9)

- `ReplenishmentRequestResource` (`apps/api/app/Modules/Replenishment/Presentation/Resources/ReplenishmentRequestResource.php:12`) gains a constructor flag `bool $withTransferQuantities = true` and a static `collectionFor(iterable $rows, bool $withTransferQuantities, Request $request): array` that resolves each row through `new self($row, $withTransferQuantities)`. The three collection call sites move to it: `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:73` and `:83`, and `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:107`. The POS `store` echo is the actor's own pending request and has no `fulfillment_type`, so the rule never masks it.
- Both controllers constructor-inject `TransferIncomingVisibility` (beside `apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:29` and `apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:25`) and compute the flag once per request.
- **Masking rule.** When the flag is false and `fulfillment_type === ReplenishmentFulfillmentType::Transfer`, the resource emits `requested_qty: null`, `suggested_qty: null` **and `note: null`**. The note is arbitrary requester text (`nullable|string|max:2000`), persisted and emitted verbatim, on a row the fulfilment linked to a transfer whose line copied the selected quantity — so a note reading "send 7391.4517" restates exactly the figure the two structured fields mask. Nulling it is the same rule as omitting the sender's `notes` from the receiver view. `fulfillment_id` (an identity) and `request_count` (a count of requests) stay. Rows **not** linked to a transfer keep their note and their quantities.
- The rule applies to **every** transfer-fulfilled row regardless of transfer state — a deliberate superset of the carrying set, chosen so Replenishment needs no cross-module status read. Terminal-transfer rows are R2 territory; pending rows never carry a transfer.
- **Envelope.** Web (`apps/api/app/Modules/Replenishment/Presentation/Controllers/ReplenishmentRequestController.php:74`): `meta.transfer_quantities_masked`, `meta.notes_masked` and `meta.visibility_version` **inside** the existing `meta` object. POS (`apps/api/app/Modules/POS/Presentation/Controllers/PosReplenishmentController.php:106`): the same three keys **top-level**, beside `as_of` and `truncated`, because that controller's envelope has no `meta` object at all. `notes_masked` is always equal to `transfer_quantities_masked` (both are the one flag); the second key exists so a client can state the note masking explicitly.
- Neither client's types change: `requested_qty` and `suggested_qty` are already `string | null` on both clients, and `note` is already nullable.

### 9.5 Movement feeds — surfaces 10, 11 (§5.10) and the entry/exit location-scope fix

**The defect this closes, restated so nobody re-argues it.** Neither controller has an **actor predicate**. `StockMovementController::index` filters on tenant/company (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:71`) and on the resolved location set (`:80`), and `formatMovement` emits `quantity` (`:218`), `quantity_before` (`:220`), `quantity_after` (`:221`), `reference_type` (`:228`) and the posting user (`:244`). `EntryExitNoteController::index` groups by reference/location/direction (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44`) with no location scope at all, and `formatNote` emits every movement of the group (`:216`) with its three quantity members (`:247`, `:249`, `:250`). So receiver A can read receiver B's `TransferIn` against the same transfer, and A's own row's `quantity_before`/`quantity_after` already encode the stock B landed — **filtering by `user_id` would not be sufficient**.

**Predicate (who is masked).** `TransferIncomingVisibility::canSeeIncomingAggregates($userId, $companyId)` — the same company-scoped disjunction surfaces 7–9 and 17–18 use. An actor for whom it returns `false` is masked. The per-transfer `canSeeExpected` is deliberately **not** used: both feeds are cross-transfer ledgers whose page can carry rows of many transfers, and a per-row per-transfer predicate would make the response shape depend on a join the controllers do not have. `canSeeIncomingAggregates` is the strictly-safe superset. Both controllers constructor-inject the contract beside their existing dependencies (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:49`, `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:22`) and compute the flag **once** per request.

**Carrying set (which rows are masked).** A movement row is *transfer-linked and carrying* when either:

1. `reference_type = App\Modules\Inventory\Domain\StockTransfer` and `reference_id` is a `stock_transfers` row whose `status` is in `StockTransfer::CARRYING_STATUSES` — the `TransferOut` at source and every `TransferIn` at destination, including the return-to-source restock; **or**
2. `reference_type = 'stock_transfer_receipt'` and `reference_id` is a `stock_transfer_receipts` row whose `transfer_id` points at such a transfer — the receipt-linked destructive movements of §7.8. A close makes the transfer terminal in the same transaction, so in practice only the receipt-linked `Damage` rows are ever masked through this clause; it is specified anyway so no destructive receipt movement can be read against a still-carrying transfer.

Resolution is **one extra query per page per clause** — `SELECT id FROM stock_transfers WHERE id IN (:transfer_reference_ids_on_this_page) AND status IN (:carrying_statuses)`, and `SELECT id, transfer_id FROM stock_transfer_receipts WHERE id IN (:receipt_reference_ids_on_this_page)` whose `transfer_id` values are matched against the same status set. This is the one-query-per-source-kind-per-page shape the movements controller already uses (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:127`).

Every other row — purchase-order receipts, countings, adjustments, POS scrap, and every movement of a `completed`, `closed_with_writeoff`, `closed_returned` or `cancelled` transfer — is untouched for **every** actor. That is the accepted R2 residual, and it is what "keep legitimate terminal history intact" means.

**Masked keys — exactly three, per feed, on a masked row only.**

- `GET /stock-movements`: `quantity`, `quantity_before`, `quantity_after` emitted as JSON `null`. `quantity_decimals`, `movement_type`, `reason`, `reference`, `reference_type`, `reference_id`, `source_document_*`, `notes`, `user_id`, `user_name`, `reverses_movement_id`, `is_reversed`, `created_at`, product and location identity are **unchanged**: identity and provenance are not quantities, and R1/R2 already accept them.
- `GET /entry-exit-notes`: the same three keys inside `lines[]` are `null`; `lines[].movement_id`, `lines[].product`, `lines[].quantity_decimals`, `lines[].movement_type`, `lines[].reason` and the note header are unchanged.

The actor's own posted quantities are **not** lost to them: `my_receipts[]` on the receiver view and the `receipt` echo of the 201 receive response return exactly what that actor typed, and neither can carry another receiver's figure.

**`meta` flag.** Both envelopes gain `meta.transfer_movement_quantities_masked: bool`, always equal to `! canSeeIncomingAggregates(...)`, emitted on **every** page whether or not the page happens to contain a masked row, so a client can label the column rather than infer masking from a `null`. On `GET /stock-movements` it joins the existing pagination `meta` (`apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:167`); on `GET /entry-exit-notes` it joins the existing `$meta` array (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:111`).

**`visibility_version` is NOT carried on these two feeds.** They are not in the §8.7 carriage set, and adding it would widen the advisory-hint contract beyond the gate minimum. T9 rows 6m/6n therefore keep "not asserted" in their version column, and the two web query prefixes are deliberately **excluded** from S4's `dropVisibilityCaches` removal set (§10.5): a removal keyed on a version the response never returns would have nothing to compare against. Convergence for these two feeds is zero-staleness plus focus refetching, under residual R4.

**Entry/exit location-scope fix (T17).** `sm.location_id` is constrained to `LocationScopeResolver::resolve($user, $requested)` (`apps/api/app/Modules/Company/Services/LocationScopeResolver.php:31`, signature `resolve(User $user, array $requestedIds = [], ?string $bypassPermission = null): array`), exactly as stock movements already do at `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php:80`. This is an authorization correction independent of the setting: a restricted user must see only groups in their allowed locations, and a request for another location is refused 403.

**Output preservation for the unrestricted, non-blind actor — a required proof, not an assumption (closes gate r2 MAJOR M4).** The two T17 methods rev 2 named prove only the *restricted* half: that a scoped user sees L2 and that a forbidden location returns 403. Both would still pass if the new scope join silently dropped or re-grouped rows for an **ordinary** actor — and this controller groups movement rows into notes (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44-72`) and emits every movement of a group (`:216-254`), so a wrong join condition or a wrong reading of "requested location" would change the note set, the group membership or the ordering for every user in the company, on a change that is **not** setting-gated and reaches real users the moment push 3 deploys. The contract is therefore stated as an equality, not as a hope:

> For an actor whose `LocationContext::getAllowedLocationIds($companyId, $user)` returns `null` (unrestricted) and for whom `canSeeIncomingAggregates(...)` returns `true` (non-blind), the body of `GET /api/v1/entry-exit-notes` after this slice must be **byte-identical** to the body the same request returned before it, except for the single added `meta.transfer_movement_quantities_masked: false`.

The fixture that proves it spans **two locations** (L1 and L2 of company A) and contains a **mixed-receiver note group** — one note whose group carries movements posted by two different destination receivers against the same transfer, which is exactly the grouping shape `:44-72` produces and the one a bad join would break. The baseline is captured on the pre-change tree into `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` and compared after; the method, its assertion order, its first red and its commands are in §9.9.

**Scope note.** No permission, route, gate or write path changes in this slice. `can:inventory.view` still gates both feeds (`apps/api/app/Modules/Inventory/Presentation/routes.php:78`, `:82`).

### 9.6 T9 — the executable leak oracle (§10.1)

`apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` with the trait `apps/api/tests/Support/AssertsBlindPayloads.php`. Both DB lanes run every step; there is **no** PG-only assertion in this test.

**Ownership split (gate r1 MAJOR M5).** Rev 1 assigned T9 whole to S3, including **step 6o**, the `GET /notifications` scan — but `TransferInitiatedNotification`, `TransferDiscrepancyNotification` and their listeners do not exist until S4 (§10.3), so S3 could not have written a red-first assertion for it, and a step 6o written in S3 would either fail forever or be silently skipped. Rev 2:

- **`T9-S3` (this slice)** owns steps 1, 2, 3, 4, 5, 5b, 5c, 5d, **6a–6n**, **6p–6u**, 7 and 8. The step table below is complete for those steps; **row 6o is present but marked `S4` and is NOT implemented here.** S3's class contains no method that issues `GET /notifications`.
- **`T9-S4` (S4)** adds step 6o as a **new method on this same class**, `test_the_notification_surface_returns_no_document_quantity_to_a_blind_actor`, plus the step-7 and step-8 re-issues of 6o. `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` therefore appears in S3 as an **add** and in S4 as a **modify**, and is registered in the cross-push contention register (§11.6).
- **S4 re-runs the WHOLE class on both DB lanes** (§13) after the notifications land. That re-run is what closes the T9 row.

**`T19-S3` lives inside this test too (gate r1 MAJOR M5).** The `Version` column of steps 6h, 6i, 6j, 6k and 6l — `meta.visibility_version == v` on the stock matrix, POS stock-levels, POS stock-distribution, the web replenishment feed and (top-level) the POS replenishment feed — **is** the carriage half of T19, and step 8 re-asserts it at `v+1` and `v+2`. No new class is added for it: the assertions already exist in the step table and are now **named** as the partial contract they satisfy. §13's S3 block additionally re-runs S2's two T19 files unmodified, so the whole T19 row is green in this slice.

**Two halves, both executable.**

- **Absence — transfer-derived surfaces only.** `assertNoForbiddenKeys`, `assertNoSentinelValues`, `assertNullOnTransferRows` and `assertNullOnTransferMovements` run on the transfer, receipt, replenishment, movement/entry-exit and notification surfaces: steps 6a–6o, the matching halves of step 7 and step 8.
- **Presence — stock-position surfaces (R5).** Steps 6p–6u assert with `assertSentinelsPresent` that the blind actor **does** receive the current-stock figures on rows 19–25. These are positive controls: they fail if a future change silently masks a stock position (which would break POS selling, counting and replenishment for the blind role) and they fail if the R5 acceptance was never real. This is what makes the residual falsifiable rather than prose.
- **Mixed bodies are pruned, never scanned whole.** For steps 6h, 6i and 6j the value scan runs on a **pruned copy** of the body with the R5 member keys removed by `withoutKeys(...)`, and the same body is then asserted present-ful on exactly those members in steps 6p, 6q and 6r. That is the only exception to "every response body is scanned whole", and it is explicit.

**Trait helpers (exact signatures).**

```php
protected function assertNoForbiddenKeys(array $json, array $forbiddenKeys, string $surface): void;
protected function assertNoSentinelValues(array $json, array $sentinels, string $surface): void;
protected function assertSentinelsPresent(array $json, array $sentinels, string $surface): void;
protected function assertCellEquals(array $json, string $path, string $expected): void;
protected function assertVisibilityVersion(array $json, string $path, int $expected): void;
protected function assertNullOnTransferRows(array $rows, array $fields): void;
protected function assertNullOnTransferMovements(array $rows, string $transferId, array $fields): void;
protected function withoutKeys(array $json, array $keys): array;
```

- `assertNoForbiddenKeys` is an **explicit recursive descent** over every array (associative and list) at any depth, failing with `"$surface: forbidden key <key> at <json.path>"`. `array_walk_recursive` is **not** used: it visits leaves only and never sees the keys of nested arrays.
- `assertNoSentinelValues` descends over every scalar. A numeric scalar fails when `bccomp((string) $value, $sentinel, 4) === 0`; a string fails when it **contains** any sentinel as a substring, which catches `"send 7391.4517"` inside a note or a message.
- `assertSentinelsPresent` is the inverse and is the liveness proof for the two scanners. It is only ever called with a subset from table P, never with the whole of `S`.
- `assertNullOnTransferMovements` asserts, for every row whose `reference_type` is `App\Modules\Inventory\Domain\StockTransfer` with `reference_id === $transferId`, **or** whose `reference_type` is `'stock_transfer_receipt'` and whose receipt belongs to that transfer, that each named key is **present and `null`**. Every other row in the same payload is left to the caller's own assertions, so a blanket-null bug is caught by the control row rather than passing. The entry/exit variant descends into `data[].lines[]` first, keying on the note's `source_id`/`source_type`.
- `withoutKeys` returns a deep copy with every occurrence of each named key removed at any depth, and **fails if it removed nothing** — a prune that removes nothing means the member was renamed and the surface must be re-audited.

**Sentinel set `S` — DOCUMENT quantities the guarantee forbids on transfer-derived surfaces:**

`S = { '7391.4517' (line-1 sent, and the P replenishment requested quantity), '5000.0000' (line-1 remainder after the blind receipt, and the quantity the SECOND destination receiver posts on line 1), '2617.2500' (line-2 sent, batch-allocated), '1017.2500' (the lot quantity the second receiver posts on line 2 against batch B1), '1600.0000' (line-2 remainder after step 5b), '613.7000' (transfer_cost), '41.3300' (unit_cost_snapshot), 'SENT-SENTINEL-7391' (transfer notes) }`

The P replenishment request's note is `'send 7391.4517'`, authored by `U_other`, carrying the first sentinel as a substring. The blind actor's own posting is `2391.4517` — legitimately echoed in the receipt, in `my_receipts` and in notes, but **not** in a movement-feed `quantity`/`quantity_before`/`quantity_after`, which §9.5 nulls for this actor on every transfer-linked carrying row **including their own**. Purchase-order incoming for product P at L2 is `12.0000` (a confirmed PO line, still outstanding) and is not a sentinel. The **movement control value** `'88.0000'` is a stock-adjustment receive of product PC at L2 on no transfer at all: it MUST stay visible on both movement feeds, so a blanket-null implementation fails as loudly as a leaking one.

**R5 control set `R` — STOCK POSITIONS the blind actor MUST still receive:**

`R = { '7391.4517' (product P's on-hand at L2 after step 5b), '1017.2500' (product PB's on-hand at L2, and batch B1's lot quantity at L2), '88.0000' (product PC's on-hand at L2 from the non-transfer adjustment) }`

`R` and `S` deliberately **overlap** on `7391.4517` and `1017.2500`: the same number is forbidden on transfer-derived surfaces and required on stock-position surfaces. That overlap is the entire content of the owner's ruling. The other fixture figures are chosen **outside both sets** so they can neither satisfy nor break an existing assertion: the thresholds `100.0000` and `1000.0000`, the derived rebalance `excess` `6391.4517`, the FEFO request `2000.0000` and its `shortfall` `982.7500`.

**Forbidden-key sets.**

- `K_transfer = { notes, transfer_cost, transfer_cost_label, cancellation_reason, initiated_by, quantity, quantity_remaining, quantity_sent, quantity_sent_snapshot, quantity_written_off, quantity_returned, unit_cost_snapshot, allocated_transfer_cost, receipts, close_disposition, close_reason, close_note, closed_at, closed_by_user_id, freight_uncapitalized }`. `quantity_received` and `quantity_damaged` are **allowed**, because `my_receipts[]` legitimately carries the actor's own; the sentinel scan covers the values.
- `K_receipt = { quantity_sent_snapshot, quantity_written_off, quantity_returned, in_movement_id, scrap_movement_id, return_movement_id, disposition, close_reason, close_note, freight_uncapitalized }` — on the 201 `receipt` member of a `kind = receipt` response. **Gate r1 M2: after rev 2 this is a STRUCTURAL guarantee, not a runtime one.** `StockTransferReceiptData` / `StockTransferReceiptLineData` / `StockTransferReceiptLineLotData` declare none of those ten properties (§7.12), so no value — not even `null` — can be serialised under those keys. The scan stays, as the runtime witness that the controller's `receiptPayload()` really chose the receipt family, and step 5 gains **one extra assertion beside it**: `self::assertFalse(array_key_exists('quantity_written_off', $body['data']['receipt']['lines'][0]), '6-pre: a receipt line must have NO quantity_written_off key at all, not a null one');` and the same for `quantity_returned`. That pair is the executable difference between "absent" and "present with null", and it is the assertion rev 1's design would have failed. The **close** response is scanned with `K_receipt \ {quantity_written_off, quantity_returned, quantity_sent_snapshot, disposition, close_reason, close_note, freight_uncapitalized}` — those seven are legitimate members of `TransferCloseReceiptData`, whose author always holds `inventory.transfers.reconcile` — and a blind actor never receives one, because `close` answers 403 to it (step 6e).
- `K_notification = { quantity, quantity_sent, notes, requested_qty }`.
- `K_none = {}` for both movement feeds: the three quantity keys stay **present** on every row and are asserted `null` on transfer-linked rows instead, through `N_movement`.
- `N_movement = { quantity, quantity_before, quantity_after }`; `N_replenishment = { requested_qty, suggested_qty, note }`.
- **No key set at all on the R5 surfaces.** Steps 6p–6u run neither `assertNoForbiddenKeys` nor `assertNoSentinelValues`: a stock position carries no forbidden key by definition under the owner's ruling, and asserting sentinel absence there is exactly the unsatisfiable claim gate r9 rejected.

**Fixture (step 1).** Company A, created through the normal company setup with **`allow_cross_location_stock_view => true`**, with:

- **L1** source, **L2** destination `pos_enabled`, and **L3** — a second in-scope destination-side location of company A that receives **no movement of any kind** for the whole test. L3 exists only so the rebalance surface can produce the deficit/surplus pair its algorithm requires; because nothing is ever posted there it adds no row to either movement feed and no note group, so steps 6m and 6n are unaffected.
- Lot-less product **P** (unit 4 dp, cost `41.3300`) seeded at L1 with exactly `7391.4517`, and batch-tracked product **PB** with one batch **B1** seeded at L1 with exactly `2617.2500` — so the `TransferOut` of initiation draws both L1 rows to `0.0000` and the company-wide sums asserted in 6t-a and 6t-c are the L2 positions alone. B1 carries a **future** `expiry_date`, `is_active = true` and `is_recalled = false`, so the FEFO predicates return it in 6u-e.
- A confirmed purchase order for P at L2 with `12.0000` outstanding.
- A third lot-less product **PC** on **no** transfer, brought into L2 by one posted stock-adjustment receive of `88.0000`, so both movement feeds carry a non-transfer control row.
- Company A has the `BatchExpiry` module enabled, which the batch routes require alongside authentication — without it step 6u would 403 on module gating rather than exercising the R5 residual.

Users:

- **`U_blind`** — membership restricted to **L2 and L3**; permissions `inventory.transfers.complete`, `inventory.view`, `replenishment.view`, `pos.operate_terminal`, `pos.view_cross_location_stock` and `products.view`. **Not** `inventory.transfers.view`, `inventory.transfers.reconcile`, `inventory.transfers.close`, `fraud-settings.view`, `replenishment.create`. The second membership (L3) is what makes step 6t-b deterministic, because the rebalance controller intersects the request with `LocationScopeResolver::resolve` and a single in-scope location can never yield both a deficit and a surplus for one product. L3 is **not** the transfer's source: `U_blind` still cannot see L1.
- Both `pos.view_cross_location_stock` **and** the company switch are required because `GET /pos/products/{product}/stock-distribution` is double-gated: `Gate::authorize('pos.view_cross_location_stock')` (`apps/api/app/Modules/POS/Presentation/Controllers/StockDistributionController.php:37`) and then a company-level abort. Without both, steps 6j, 7 and 8 receive 403 and neither the blind scan nor the positive control ever sees that response shape.
- **`U_other`** — a second destination user (membership restricted to L2) with `replenishment.create` and nothing else; the author of the replenishment notes and, from step 5d, counter 1 of counting C.
- **`U_blind2`** — a **second destination receiver**: membership restricted to L2, permissions `inventory.transfers.complete` and `inventory.view`, and none of the three transfer read/reconcile/close permissions. It exists to post the hidden sentinel of step 5b; it is a **distinct user** from `U_blind`, so the leak "A reads B's posting" is reproduced rather than assumed away.
- **`U_reconcile`** — `U_blind`'s membership and permissions plus `inventory.transfers.reconcile`.
- **`U_admin`** — the seeded admin.

**Procedure.**

2. As `U_other`, capture two replenishment requests at L2: P `7391.4517` with note `'send 7391.4517'`, PB `2617.2500` with note `'second lot please'`. As `U_admin`, `POST /replenishment-requests/actions/create-transfer` for both → one transfer **T** (`fulfillment_id = T` on both rows), with `notes = 'SENT-SENTINEL-7391'`, `transfer_cost = '613.7000'`, line 2 allocated wholly to B1. Then, as `U_other`, capture a third request for P at L2 with note `'PENDING-NOTE'` (stays pending, no transfer). The setting is **OFF**.
3. **Scanner liveness (setting OFF).** As `U_blind`: `GET /stock-transfers/{T}`, `GET /inventory/stock-matrix?include=incoming&location_ids[]=L2`, `GET /replenishment-requests?status=fulfilled` → `assertSentinelsPresent` with the **pre-receipt** column of table P. The R5 scanners are proven live in the same step: the matrix body's `cells[].on_hand` for PC at L2 reads `88.0000` and `GET /stock-levels` returns the same figure. A scanner that cannot find its subset here fails the test **before** any blind assertion runs.
4. As `U_admin`, `PATCH /fraud-settings {blind_receiving: true}` → `v = data.visibility_version`.
5. As `U_blind`, `POST /stock-transfers/{T}/receive` line 1 `quantity_received: '2391.4517'` (fresh key) → 201; assert `K_receipt` on `data.receipt`, `K_transfer` on `data.transfer`, `assertNoSentinelValues($body, S)`, and `assertVisibilityVersion($body, 'data.transfer.visibility_version', $v)`. Line-1 remainder is now `5000.0000`; line 2 untouched.
5b. **The second destination receiver posts the hidden sentinels.** As `U_blind2`, **one** `POST /stock-transfers/{T}/receive` (its own fresh key) → 201, carrying two lines: line 1 `quantity_received: '5000.0000'`, and line 2 with `lots: [{ batch_id: B1, quantity_received: '1017.2500' }]` and the matching line total. Line 1 is now fully received; **line 2 keeps `1600.0000` open, so T stays `partially_received` and remains receivable**. Assert on `U_blind2`'s own 201 body the same helpers as step 5, with `assertNoSentinelValues($body, S \ {'5000.0000', '1017.2500'})`: those two **are** its own submitted values and are echoed legitimately, so the exclusion is stated rather than being a silent hole. The destination `stock_levels` row for P is now `7391.4517`; PB's is `1017.2500`, all of it in B1's `batch_stock` row at L2.
5c. **`NOTHING_TO_RECEIVE` is exercised.** As `U_blind`, `POST /stock-transfers/{T}/receive` line 1 `quantity_received: '0.5000'` (fresh key) → 422 `NOTHING_TO_RECEIVE`; `assertNoSentinelValues($body, S)`; `array_keys($body['error']['details']) ⊆ {transfer_line_id, batch_id}`; the message is static. This is the probe that turns any surviving movement figure into an exact quantity, so it runs **before** the movement scans of step 6 and its 422 is asserted, not merely tolerated.
5d. **Deterministic fixtures for the two R5 surfaces that need one.** Both are written **after** step 5b so they observe P `7391.4517`, PB `1017.2500`, PC `88.0000` at L2. (a) **Rebalance thresholds** — as `U_admin`, two `PUT /inventory/stock-levels/thresholds` calls (`apps/api/app/Modules/Inventory/Presentation/routes.php:66`): `{product_id: P, location_id: L3, min_quantity: '100.0000'}` — the L3 row does not exist yet and the writer **creates** it at `quantity '0.0000'` (`apps/api/app/Modules/Inventory/Application/Services/StockThresholdService.php:45`), which is the deficit cell — and `{product_id: P, location_id: L2, max_quantity: '1000.0000'}`, which makes L2 the surplus cell. Only **one** bound is written per row, so the request-level `max_quantity ≥ min_quantity` check never fires. PB and PC receive no threshold, which is what keeps them out of 6t-b. (b) **Counting document** — as `U_admin`, `POST /inventory/countings` with `scope_type: 'location'`, `scope_filters: {location_ids: [L2]}`, `include_zero_stock: false`, `requires_count_2: false`, `requires_count_3: false`, `count_1_user_id: U_other` → counting **C**, whose item seeds copy `stock_levels.quantity` into `theoretical_qty` (`apps/api/app/Modules/Inventory/Application/Services/InventoryCountingService.php:297`) over the positive rows of L2, so C holds exactly three items whose theoretical quantities **are** the `R` set. **No activation is performed and none is needed**; `POST /inventory/countings/{C}/activate` is deliberately **not** called, which also keeps `replay_preview` `null`. Creating C posts **no** stock movement, so both movement feeds and every earlier assertion are untouched.
6. As `U_blind`, one request per row of the table below, in order — **except row 6o, which is `T9-S4` and is added by S4 (§2.2 deviation 5)**. Every response body is scanned **whole** (including `error` and `meta`), with exactly three stated exceptions — 6h, 6i and 6j, pruned by `withoutKeys` before the value scan — and rows 6p–6u carry **no** absence assertion at all, being the R5 positive controls, with one stated exception: **6s-d**, the counter-facing half of the counting control, which is an ABSENCE row inside the presence block and is the only row of the step issued as an actor other than `U_blind` (it runs as `U_other`, the assigned counter).
7. **Positive control.** As `U_reconcile` (full builder via reconcile; `canSeeIncomingAggregates = true`, so §9.5 does not mask it): re-issue 6b, 6c, 6h, 6i, 6j, 6k, 6l, **6m, 6n** and `GET /stock-transfers/{T}/reconciliation` → 200 each, `assertSentinelsPresent($body, P[surface])` with the "after step 5b" column, `blind === false` on 6b, `meta.incoming_transfer_masked === false` on 6h–6j, version `v` on every version-bearing row. 6a is re-issued too and must still pass its step-6 key/value assertions — the receiver builder is unconditional for everyone. **R5 half:** 6p, 6q, 6r, 6s-a, 6s-b, 6s-c, 6t-a, 6t-b, 6t-c and 6u-a…6u-e are re-issued as `U_reconcile` and must return the **same** `R` values as they did for `U_blind` — identical bodies on the stock-position surfaces for both actors. **6s-d is the one row NOT re-issued here**, deliberately: the counter-facing endpoints require the requester to be an assigned counter, and `U_reconcile` is not assigned to C, so it would receive a 403 that proves nothing. Its liveness comes from the other direction — the same three values ARE found by 6s-c on the admin-side payload of the same document.
8. **Flip case.** `PATCH /fraud-settings {blind_receiving: false}` → `v+1`; as `U_blind` re-issue 6a–6c and 6h–6n and assert `assertSentinelsPresent($body, P[surface])` for every non-EXCLUDED row of the "after step 5b" column, with version `v+1` on the version-bearing rows (6m/6n carry none); 6a keeps its step-6 assertions. Then `PATCH {blind_receiving: true}` → `v+2`; re-issue 6a–6n (and, from S4 onward, 6o): every row passes its step-6 assertions with version `v+2`. This is the server-side statement "no request made after the flip returns a hidden figure"; **no client cache is asserted** (R4). **R5 invariance across the flip:** 6p, 6q, 6r, 6s-a, 6s-b, 6s-c, 6t-a, 6t-b, 6t-c and 6u-a…6u-e are re-issued after **each** of the two flips and must return the same `R` values every time, with **no** `meta` masking flag appearing on any of those surfaces in any state. 6s-d is re-issued as `U_other` after each flip too and must stay empty of all three `R` values. A stock position that changed when `blind_receiving` flipped would mean the lane had silently taken the option the owner rejected, and this assertion is what catches it.
9. Both DB lanes run every step.

**Step table (step 6, as `U_blind` unless stated).**

| Step | Request | Status | Keys | Values | Surface-specific | Version |
|---|---|---|---|---|---|---|
| 6a | `GET /stock-transfers/{T}/receiver-view` | 200 | `K_transfer` | `S` | `blind === true`; `lines[1].lots[0].batch_number` present with **no** `quantity` beside it | `data.visibility_version == v` |
| 6b | `GET /stock-transfers/{T}` | 200 | `K_transfer` | `S` | receiver shape (`blind === true`) | `data.visibility_version == v` |
| 6c | `GET /stock-transfers?status=all` | 200 | `K_transfer` | `S` | T is listed (the any-of gate admits this actor) | `meta.visibility_version == v` |
| 6d | `POST /stock-transfers/{T}/complete` | 422 | — | `S` | `error.code == BLIND_REQUIRES_COUNTED_RECEIPT`; `error.details == []`; no `data` key | not asserted |
| 6e | `POST /stock-transfers/{T}/close {disposition: write_off, reason: lost_in_transit}` | 403 | — | `S` | static body; no `data` key | not asserted |
| 6f | `POST /receive` line 1 `'9999.0000'` (fresh key) | 422 | — | `S` | `OVER_RECEIPT`; `array_keys(error.details) ⊆ {transfer_line_id, batch_id}` | not asserted |
| 6g | `POST /receive` with line 1 twice (fresh key) | 422 | — | `S` | `VALIDATION_ERROR`; `error.errors` keys only; static messages | not asserted |
| 6h | `GET /inventory/stock-matrix?include=incoming&location_ids[]=L2` | 200 | `K_none` | `S` on `withoutKeys($body, ['on_hand','reserved','available','min_quantity','max_quantity'])` | P's cell `incoming == '12.0000'` (PO only — line 1 is fully received); PB's cell `incoming == '0.0000'`; `meta.incoming_transfer_masked === true`. R5 half in 6p on the SAME response | `meta.visibility_version == v` |
| 6i | `GET /pos/stock-levels` (L2 terminal) | 200 | `K_none` | `S` on `withoutKeys($body, ['quantity','reserved','available'])` | every `incoming[].incoming_transfer === null`; `meta.incoming_transfer_masked === true`. R5 half in 6q on the SAME response | `meta.visibility_version == v` |
| 6j | `GET /pos/products/{P}/stock-distribution` | 200 | `K_none` | `S` on `withoutKeys($body, ['on_hand'])` | the 200 is asserted **first** — a 403 means the step-1 preconditions regressed and the masking assertions would silently pass on an error body. Then every row and `totals`: `incoming_transfer === null`; `meta.incoming_transfer_masked === true`. R5 half in 6r | `meta.visibility_version == v` |
| 6k | `GET /replenishment-requests?status=fulfilled` | 200 | `K_none` | `S` | `assertNullOnTransferRows($data, N_replenishment)` on both transfer rows (quantities **and** `note` null — the plain `'second lot please'` note is masked by linkage, not by content); `meta.transfer_quantities_masked === true`; `meta.notes_masked === true`. Then the default (open) list: the pending P row shows `note == 'PENDING-NOTE'` and its `requested_qty` | `meta.visibility_version == v` |
| 6l | `GET /pos/replenishment-requests` | 200 | `K_none` | `S` | the same null-key assertion; `transfer_quantities_masked === true` and `notes_masked === true` **top-level** beside `as_of`/`truncated`; the pending row's note intact | `visibility_version == v` |
| 6m | `GET /stock-movements?location_id=L2` | 200 | `K_none` | `S` | the page contains **both** destination `TransferIn` rows for T — the actor's own (`2391.4517`) and `U_blind2`'s (`5000.0000`, whose `quantity_after` is `7391.4517`). Assert `assertNullOnTransferMovements($data, T, N_movement)` — every T-linked row has all three keys `null`, the actor's own row included; `meta.transfer_movement_quantities_masked === true`; the PC control row still reads `quantity == '88.0000'` with non-null before/after; identity survives on each T row (`reference_type`, `reference_id == T`, `movement_type`, `quantity_decimals`, `user_id`); every row `location_id == L2` | not asserted (§9.5) |
| 6n | `GET /entry-exit-notes` | 200 | `K_none` | `S` | groups only for L2 (`U_blind`'s L3 membership adds no group because nothing is ever posted there). The T note groups **both** receivers' `TransferIn` movements; assert the entry/exit variant of `assertNullOnTransferMovements` over `data[].lines[]` — all three keys `null` on every line of the T note — `meta.transfer_movement_quantities_masked === true`; the PC adjustment note's line still reads `quantity == '88.0000'` | not asserted |
| **6o — `T9-S4`, NOT implemented in S3** | `GET /notifications` | 200 | `K_notification` | `S` | the `inventory.transfer.initiated` row: `line_count == 2`, `deep_link` present. **S4 adds this row as `TransferBlindLeakOracleTest::test_the_notification_surface_returns_no_document_quantity_to_a_blind_actor` and re-runs the whole class** (§2.2 deviation 5, §10.10). S3's class must contain **no** `GET /notifications` request: no transfer notification type exists until S4, so a step 6o written here would scan an empty list and prove nothing | not asserted |
| **6p** | the SAME stock-matrix response as 6h, R5 half | 200 | none | **presence** | `assertSentinelsPresent($body, ['7391.4517','1017.2500','88.0000'])` against `cells[].on_hand` for P, PB and PC at L2 | not asserted (6h) |
| **6q** | the SAME `GET /pos/stock-levels` response as 6i, R5 half | 200 | none | **presence** | `assertSentinelsPresent($body, ['7391.4517','1017.2500','88.0000'])` against `stock[].quantity` (and `available`, with `reserved = 0`) | not asserted (6i) |
| **6r** | the SAME `GET /pos/products/{P}/stock-distribution` response as 6j, R5 half | 200 | none | **presence** | `assertSentinelsPresent($body, ['7391.4517'])` against the L2 row's `on_hand` and `totals.on_hand` | not asserted (6j) |
| **6s-a / 6s-b** | `GET /stock-levels?product_id=P&location_id=L2`, then the show route for the same pair, then the same two for PC | 200 each | none | **presence** | `7391.4517` on P's `quantity`/`available` and `88.0000` on PC's. **No** forbidden-key or sentinel-absence assertion runs on this surface at all | not asserted |
| **6s-c** | `GET /inventory/countings/{C}/reconciliation` | 200 | none | **presence** | `assertSentinelsPresent($body, ['7391.4517','1017.2500','88.0000'])` against `data.items[].theoretical_qty` for P, PB and PC — the three snapshots step 5d's creation copied out of `stock_levels.quantity` | not asserted |
| **6s-d** | as **`U_other`** (counter 1 of C): `GET /inventory/countings/{C}/counter-view`, then `GET /inventory/countings/{C}/items/to-count` | 200 each | `{ theoretical_qty }` | **absence — `R`** | `assertNoForbiddenKeys($body, ['theoretical_qty'], $surface)` and `assertNoSentinelValues($body, R)`: neither body may carry the key at any depth nor any of the three values. This is the **shipped** blind-counting rule, re-asserted on a document whose theoretical quantities are known sentinels | not asserted |
| **6t-a / 6t-b / 6t-c** | `GET /products/{P}/stock-levels`, then `GET /inventory/stock-matrix/rebalance` (no `location_ids[]`, so the resolver returns L2+L3), then `GET /products/{P}` | 200 each | none | **presence on all three** | 6t-a: `7391.4517` on the L2 row's `quantity` and on `totals.quantity` (P's only non-zero position is L2). 6t-b: `count(data) === 1`, `data[0].product_id === P`, `data[0].deficits === [{location_id: L3, available: '0.0000', min_quantity: '100.0000'}]`, `data[0].surpluses === [{location_id: L2, available: '7391.4517', max_quantity: '1000.0000', excess: '6391.4517'}]`, with PB and PC asserted **absent** from `data`. 6t-c: `7391.4517` on `data.stock_quantity`, and `data.cost_price` asserted `null` because `U_blind` lacks `pricing.view_cost_prices` | not asserted |
| **6u-a/6u-b/6u-c/6u-d/6u-e** | `GET /batches/{B1}/stock`, `GET /batches/{B1}`, `GET /products/{PB}/batch-stock`, `GET /batches?product_id=PB`, `GET /pos/products/{PB}/batches?location_id=L2&quantity=2000.0000` | 200 each | none | **presence, per lot** | `assertSentinelsPresent($body, ['1017.2500'])` on all five — on the list it comes from the repository's eager-loaded `batch_stock[]`, and on the POS route as `suggestions[0].quantity` **and** `total_quantity_suggested`. 6u-e asks for **more** than the lot holds, so FEFO caps the suggestion at the lot's own availability and the asserted figure is the lot POSITION, not an echo of the request; its `shortfall` is asserted `'982.7500'` and `fully_fulfilled` false. All five are additionally asserted 200 for an actor holding **no** batch read permission | not asserted |

**Positive-control table P (the "after step 5b" column, which is the state steps 6–8 actually observe).** Line 1: sent `7391.4517`, received in full, remainder `0.0000`. Line 2: sent `2617.2500`, received `1017.2500` against B1, remainder `1600.0000`. Destination positions at L2: P `7391.4517`, PB `1017.2500`, PC `88.0000`. PO incoming for P at L2: `12.0000`.

| Surface | `P` after step 5b | `P` pre-receipt (step 3) |
|---|---|---|
| 6a receiver-view | EXCLUDED | EXCLUDED |
| 6b `GET /stock-transfers/{T}` | `7391.4517`, `2617.2500`, `1600.0000`, `613.7000`, `41.3300`, `SENT-SENTINEL-7391` | `7391.4517`, `2617.2500`, `613.7000`, `41.3300`, `SENT-SENTINEL-7391` |
| 6c `GET /stock-transfers` | `613.7000`, `SENT-SENTINEL-7391` | same |
| 6d–6g error bodies | EXCLUDED | EXCLUDED |
| 6h stock matrix (`incoming` half) | `1600.0000` on PB's parent-row cell, plus `assertCellEquals` `'12.0000'` on P's parent-row cell `incoming` | `2617.2500`; P's cell `incoming` `'7403.4517'` |
| 6i `GET /pos/stock-levels` (`incoming[]` half) | `1600.0000` on PB's `incoming_transfer`; P's is `0.0000` and carries no sentinel | `7391.4517`, `2617.2500` |
| 6j POS distribution (`incoming_transfer` half) | EXCLUDED (P's line is fully received, so the L2 row and `totals.incoming_transfer` are both `0.0000`); the request is still made and `meta.incoming_transfer_masked` asserted | `7391.4517` (L2 row and totals) |
| 6k / 6l replenishment feeds | `7391.4517` (P row `requested_qty` **and** its note), `2617.2500` (PB row `requested_qty`) | same |
| 6m / 6n movement feeds | `5000.0000` (the second receiver's `TransferIn` quantity on line 1), `7391.4517` (that row's `quantity_after`), `1017.2500` (the second receiver's lot `TransferIn` quantity on line 2) — plus the `'88.0000'` control row asserted intact for **both** actors | EXCLUDED (no destination movement exists before step 5) |
| 6o notifications | EXCLUDED | EXCLUDED |
| 6p–6u (R5) | the `R` subset each surface emits for **every** actor: `7391.4517`, `1017.2500`, `88.0000` as the step table states per row | `88.0000` only, except 6t-b which returns `{data: []}` before step 5d and 6u-e which returns `suggestions: []` with `shortfall: '2000.0000'` |
| `GET /stock-transfers/{T}/reconciliation` | `7391.4517` (line-1 `sent`), `2617.2500` (line-2 `sent`), `1600.0000` (line-2 `remaining`), `1017.2500` (line-2 `received`) | n/a (not requested before step 5) |

### 9.7 Response-shape acceptance checklist (§5.0b)

Before the reviewer gate, the implementer walks the spec's §5.0b table and records, per row, the file and line that produces the stated shape. The rows that must be **produced by this slice**: stock matrix (masked), POS stock-levels / stock-distribution (masked), replenishment WEB (masked, flags inside `meta`), replenishment POS (masked, flags **top-level**), stock movements / entry-exit notes (masked, three keys `null`, `meta` flag). The rows that must be **produced unchanged** are the twelve R5 rows (E1: twelve response-shape rows, not seven) — the checklist records "unchanged, no file in this slice's ledger" for each.

### 9.8 Convention-09 evidence (S-matrix)

| Writer / reader | Second company | Second location | Re-run / idempotency |
|---|---|---|---|
| movement-feed masking | company B's blind actor sees **B's** rows only; A's masking state does not affect B, and B's setting does not affect A | the blind actor's page contains only L2 rows; L3 (no movement) contributes none; a request for L1 is refused | the same two requests issued twice return identical bodies; after the transfer becomes terminal the same actor's rows are **no longer masked** (accepted R2) |
| entry/exit location scope (T17) | a B actor sees no A group, and an **unrestricted** B actor's body is compared against **B's own** captured baseline, never A's | a restricted user sees only allowed-location groups; another location → 403; an **unrestricted, non-blind** actor keeps every group across **both** L1 and L2, including the mixed-receiver group, byte-identical to the pre-change baseline (gate r2 M4) | repeat request → identical group set; the byte-equality assertion is re-issued after a second identical request, so a per-request ordering wobble is caught rather than averaged away |
| replenishment masking | B's transfer-linked rows are masked by B's own setting; A's rows unaffected | rows are per (company, destination, product, variant) grain; the L2 row is masked and an L1-destination row belonging to another transfer is judged on its own linkage | repeat request → identical nulls; a row that becomes terminal-linked stays masked (the rule is linkage, not state) |
| incoming aggregates | B's matrix/POS payloads carry B's own flag and version | the masked member is nulled on **every** row of the page, and `on_hand` at L2 is untouched | repeat request → identical body; toggling the setting twice returns to the original body with the version advanced by 2 |

### 9.9 Red-first tests

| Exact file | Exact class::method | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Inventory/TransferMovementMaskingTest.php` | `TransferMovementMaskingTest::test_blind_actor_sees_null_quantities_on_every_carrying_transfer_row_including_its_own` (T20) | `self::assertNull($rowForOwnPosting['quantity']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferMovementMaskingTest::test_blind_actor_sees_null_quantities_on_every_carrying_transfer_row_including_its_own'` | PHPUnit PostgreSQL (`autoerp_test_x`) **and** SQLite |
| same | `TransferMovementMaskingTest::test_non_transfer_and_terminal_transfer_rows_keep_their_quantities` (T20 liveness) | `self::assertSame('88.0000', $adjustmentRow['quantity']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferMovementMaskingTest::test_non_transfer_and_terminal_transfer_rows_keep_their_quantities'` | PHPUnit PostgreSQL |
| same | `TransferMovementMaskingTest::test_receipt_linked_damage_row_is_masked_only_while_its_transfer_carries` (T20) | `self::assertNull($damageRowOnCarryingTransfer['quantity']);` with the terminal one intact | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferMovementMaskingTest::test_receipt_linked_damage_row_is_masked_only_while_its_transfer_carries'` | PHPUnit PostgreSQL |
| same | `TransferMovementMaskingTest::test_reconcile_actor_sees_every_quantity_with_the_flag_false` (T20 liveness) | `self::assertFalse($response->json('meta.transfer_movement_quantities_masked'));` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferMovementMaskingTest::test_reconcile_actor_sees_every_quantity_with_the_flag_false'` | PHPUnit PostgreSQL |
| same | `TransferMovementMaskingTest::test_entry_exit_note_groups_both_receivers_and_masks_every_line` (T20) | `self::assertNull($noteForT['lines'][1]['quantity_before']);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferMovementMaskingTest::test_entry_exit_note_groups_both_receivers_and_masks_every_line'` | PHPUnit PostgreSQL |
| same | `TransferMovementMaskingTest::test_second_company_masking_is_independent` (T20, convention 09) | **`self::assertTrue($responseA->json('meta.transfer_movement_quantities_masked'));`** — genuinely red today: the key does not exist, `json()` returns `null`, and `assertTrue(null)` fails. **Gate r2 M5: rev 2 named `assertSame('5.0000', $companyBRow['quantity'])` first, which today's unmasked behaviour already satisfies, so the method was classified RED while its stated first assertion passed.** Corrected order: (1) the missing `meta` key on **A**, (2) `self::assertNull($companyARow['quantity']);` — A's carrying-transfer row is masked, `'5000.0000'` today, (3) `self::assertFalse($responseB->json('meta.transfer_movement_quantities_masked'));` — B never turned the setting on, (4) `self::assertSame('5.0000', $companyBRow['quantity']);` — the isolation control, which is the assertion that *must* keep passing | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferMovementMaskingTest::test_second_company_masking_is_independent'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Inventory/EntryExitNoteLocationScopeTest.php` | `EntryExitNoteLocationScopeTest::test_restricted_user_sees_only_allowed_location_groups` (T17) | `self::assertSame([$l2->id], array_unique(array_column($response->json('data'), 'location_id')));` — fails today, because the controller applies no location scope (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44`) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'EntryExitNoteLocationScopeTest::test_restricted_user_sees_only_allowed_location_groups'` | PHPUnit PostgreSQL |
| same | `EntryExitNoteLocationScopeTest::test_requesting_a_forbidden_location_is_refused` (T17) | `$response->assertForbidden();` — today the controller applies no location scope at all (`apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php:44`), so the request returns **200** with the other location's groups | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'EntryExitNoteLocationScopeTest::test_requesting_a_forbidden_location_is_refused'` | PHPUnit PostgreSQL |
| same | `EntryExitNoteLocationScopeTest::test_capture_the_pre_change_unrestricted_baseline` (**T17, rev 3, gate r2 M4 — the CAPTURE half**) | `self::assertNotFalse(getenv('T2T3_CAPTURE_BASELINE'), 'capture-only method');` — without the environment variable the method calls `$this->markTestSkipped('capture-only; set T2T3_CAPTURE_BASELINE=1 on the PRE-CHANGE tree')` and never asserts. Run once, as step (1) of §9.10 and **before the scope fix exists**, it builds the two-location, mixed-receiver-group fixture from literal UUID constants declared on the test class (CLAUDE.md rule 17), issues `GET /api/v1/entry-exit-notes` as the unrestricted non-blind actor, and writes the response body to `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` under a top-level key per DB driver, asserting `self::assertGreaterThanOrEqual(2, count($body['data']));` and `self::assertGreaterThanOrEqual(2, count($mixedGroup['lines']));` so an empty or single-line capture cannot be committed | `cd apps/api && T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'EntryExitNoteLocationScopeTest::test_capture_the_pre_change_unrestricted_baseline'` **and** `cd apps/api && T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit --filter 'EntryExitNoteLocationScopeTest::test_capture_the_pre_change_unrestricted_baseline'` | PostgreSQL (`autoerp_test_x`) **and** SQLite |
| same | `EntryExitNoteLocationScopeTest::test_unrestricted_non_blind_output_is_preserved_and_a_restricted_actor_is_scoped` (**T17, rev 3, gate r2 M4 — the PRESERVATION half**) | **`self::assertSame([$l2->id], array_unique(array_column($restricted->json('data'), 'location_id')));`** — the location-scope refusal for a restricted actor, which fails today because the controller applies no scope and the restricted actor receives L1's groups as well. That is the red, and it is deliberately the FIRST assertion so the preservation half can never stand alone as a passing control. Order after it: (2) `self::assertSame($baseline['data'], $unrestricted->json('data'));` — byte-equality of the whole `data` member for the unrestricted, non-blind actor across both locations, including the mixed-receiver group's line order and every `quantity`, `quantity_before` and `quantity_after` string; (3) `self::assertFalse($unrestricted->json('meta.transfer_movement_quantities_masked'));` — the one member the change is allowed to add; (4) `self::assertSame($baseline['meta'], Arr::except($unrestricted->json('meta'), ['transfer_movement_quantities_masked']));` — nothing else in `meta` moved; (5) a second identical request whose `data` is asserted equal to (2)'s, catching a per-request ordering wobble | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'EntryExitNoteLocationScopeTest::test_unrestricted_non_blind_output_is_preserved_and_a_restricted_actor_is_scoped'` **and** `cd apps/api && ./vendor/bin/phpunit --filter 'EntryExitNoteLocationScopeTest::test_unrestricted_non_blind_output_is_preserved_and_a_restricted_actor_is_scoped'` | PostgreSQL (`autoerp_test_x`) **and** SQLite |
| `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` | `TransferBlindLeakOracleTest::test_scanners_are_live_before_any_blind_assertion` (**T9-S3**, step 3 — a **CONTROL**, expected PASS at capture; §9.9b case C1) | `self::assertSame([], $missingSentinels, 'liveness');` — **passes at capture time and is meant to** | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferBlindLeakOracleTest::test_scanners_are_live_before_any_blind_assertion'` | PostgreSQL **and** SQLite |
| same | `TransferBlindLeakOracleTest::test_stock_position_surfaces_still_serve_the_blind_actor` (**T9-S3**, steps 6p–6u — a **CONTROL**, expected PASS at capture; §9.9b case C2) | `self::assertSame([], $missing, '6t-b');` — **passes at capture time and is meant to.** Gate r1 M6 is right that it "can pass against current visible payloads": that is precisely what an R5 positive control must do — pass before the slice, after the slice and after **both** flips, and go red only if a future change masks a stock position. It is classified here rather than presented as red-first evidence | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferBlindLeakOracleTest::test_stock_position_surfaces_still_serve_the_blind_actor'` | PostgreSQL **and** SQLite |
| same | `TransferBlindLeakOracleTest::test_no_transfer_derived_surface_returns_a_document_quantity_to_a_blind_actor` (**T9-S3**, steps 4–**6n**) | `self::assertSame([], $violations, implode(PHP_EOL, $violations));` — the first violation today is `6b: sentinel 7391.4517 at data.lines.0.quantity`. **Step 6o is excluded from this method's range** and arrives with `T9-S4` (§9.6) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferBlindLeakOracleTest::test_no_transfer_derived_surface_returns_a_document_quantity_to_a_blind_actor'` | PostgreSQL **and** SQLite |
| same | `TransferBlindLeakOracleTest::test_reconcile_actor_receives_every_positive_control` (**T9-S3**, step 7) | **`self::assertFalse($movements->json('meta.transfer_movement_quantities_masked'));`** on the step-7 re-issue of 6m — genuinely red today: the key does not exist, so `json()` returns `null` and `assertFalse(null)` fails. Only then `self::assertSame([], $missing, '6m/6n');` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferBlindLeakOracleTest::test_reconcile_actor_receives_every_positive_control'` | PostgreSQL **and** SQLite |
| same | `TransferBlindLeakOracleTest::test_flipping_the_setting_changes_transfer_surfaces_and_no_stock_position` (**T9-S3**, step 8) | **`self::assertNull($tRowForOtherReceiver['quantity']);`** on the 6m re-issue after the SECOND flip (`blind_receiving` back to `true`, `v+2`) — genuinely red today: the row carries `'5000.0000'`. Rev 1 named `assertSame($r5BodyBefore, $r5BodyAfter)` on the rebalance response, which **passes** today and is therefore a control, not red-first evidence; it stays in the method, after this assertion, as the R5-invariance control (§9.9b) | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferBlindLeakOracleTest::test_flipping_the_setting_changes_transfer_surfaces_and_no_stock_position'` | PostgreSQL **and** SQLite |
| `apps/api/tests/Support/AssertsBlindPayloads.php` | trait, exercised by the five methods above | `withoutKeys` fails when it removes nothing: `self::fail("$surface: prune removed no key — the member was renamed");` | (covered by the T9 commands) | — |

### 9.9b Red-first rows versus control rows, and how each control is falsified (closes gate r1 MAJOR M6 for S3)

Gate r1 observed that S3's positive controls "can pass against current visible payloads and therefore are not first-red evidence". That is **true and intended**, and rev 2 states it instead of implying otherwise. An R5 positive control whose job is "this stock figure is still served" must pass on the tree before the slice, after the slice and after each flip — a control that goes red when the feature lands would be asserting the opposite of the owner's ruling. Mislabelling it as red-first evidence would be the defect; classifying it, and shipping an executable way to make it go red, is the fix.

**Classification of every method in this slice.** The handback records, per row, whether the capture-time result was the expected `RED` or the expected `PASS`; a control that is **red** at capture is a fixture bug and blocks the slice just as a red-first row that is **green** does.

| Method | Class | Expected at capture |
|---|---|---|
| `TransferMovementMaskingTest::test_blind_actor_sees_null_quantities_on_every_carrying_transfer_row_including_its_own` | RED-FIRST | RED |
| `TransferMovementMaskingTest::test_receipt_linked_damage_row_is_masked_only_while_its_transfer_carries` | RED-FIRST | RED |
| `TransferMovementMaskingTest::test_reconcile_actor_sees_every_quantity_with_the_flag_false` | RED-FIRST (the `meta` key does not exist, so `assertFalse(null)` fails) | RED |
| `TransferMovementMaskingTest::test_entry_exit_note_groups_both_receivers_and_masks_every_line` | RED-FIRST | RED |
| `TransferMovementMaskingTest::test_second_company_masking_is_independent` | RED-FIRST **after the rev-3 repair** (its first assertion is now the missing `meta` key on company A; gate r2 M5 was right that rev 2's stated first assertion passed at HEAD) | RED |
| `TransferMovementMaskingTest::test_non_transfer_and_terminal_transfer_rows_keep_their_quantities` | **CONTROL** (case C3) | PASS |
| `EntryExitNoteLocationScopeTest::test_restricted_user_sees_only_allowed_location_groups` | RED-FIRST | RED |
| `EntryExitNoteLocationScopeTest::test_requesting_a_forbidden_location_is_refused` | RED-FIRST | RED |
| `EntryExitNoteLocationScopeTest::test_capture_the_pre_change_unrestricted_baseline` | **CONTROL** (case C4 — the baseline producer, skipped without its env var) | SKIPPED, then PASS on the capture run |
| `EntryExitNoteLocationScopeTest::test_unrestricted_non_blind_output_is_preserved_and_a_restricted_actor_is_scoped` | RED-FIRST (its first assertion is the restricted-actor scoping assertion, which fails on today's unscoped controller) | RED |
| `TransferBlindLeakOracleTest::test_no_transfer_derived_surface_returns_a_document_quantity_to_a_blind_actor` | RED-FIRST | RED |
| `TransferBlindLeakOracleTest::test_reconcile_actor_receives_every_positive_control` | RED-FIRST (its first assertion is the missing `meta` key) | RED |
| `TransferBlindLeakOracleTest::test_flipping_the_setting_changes_transfer_surfaces_and_no_stock_position` | RED-FIRST (its first assertion is the post-flip `assertNull` on 6m) | RED |
| `TransferBlindLeakOracleTest::test_scanners_are_live_before_any_blind_assertion` | **CONTROL** (case C1) | PASS |
| `TransferBlindLeakOracleTest::test_stock_position_surfaces_still_serve_the_blind_actor` | **CONTROL** (case C2) | PASS |

**Falsification procedure — mandatory, once per control, recorded in the handback.** Each control ships with a **mutation** the implementer applies temporarily, runs the control against, observes RED, then reverts. Without this a control is decoration; with it the control is proven to be able to fail. This is the same requirement `docs/conventions/08-DETECTOR-LIVENESS.md` puts on a detector, applied to a positive control.

| Case | Control | Mutation to apply | Expected result under the mutation | Revert |
|---|---|---|---|---|
| **C1** | `test_scanners_are_live_before_any_blind_assertion` | In the test file only, replace the step-3 sentinel subset with one value that is in **neither** `S` nor `R` — `'6391.4517'`, the derived rebalance `excess` — and re-run | `assertSame([], $missingSentinels, 'liveness')` fails, listing `6391.4517` as missing on `GET /stock-transfers/{T}` | Restore the real subset |
| **C2** | `test_stock_position_surfaces_still_serve_the_blind_actor` | In `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php`, temporarily null `cells[].on_hand` for every row (the R5 member fed from `stock_levels.quantity` at `:287`) | Step 6p fails with `'7391.4517' missing from cells[].on_hand`, proving the control watches the exact member the owner ruled must stay visible | `git checkout -- ` that file |
| **C3** | `test_non_transfer_and_terminal_transfer_rows_keep_their_quantities` | In `apps/api/app/Modules/Inventory/Presentation/Controllers/StockMovementController.php`, temporarily widen the masking predicate from "transfer-linked **and carrying**" to "every row" | The assertion `self::assertSame('88.0000', $adjustmentRow['quantity']);` fails on the PC stock-adjustment control row, proving a blanket-null implementation is caught | `git checkout -- ` that file |
| **C4** (rev 3, gate r2 M4) | `test_capture_the_pre_change_unrestricted_baseline` | In `apps/api/app/Modules/Inventory/Presentation/Controllers/EntryExitNoteController.php`, temporarily change the grouping key at `:44` from reference/location/direction to reference alone — the mixed-receiver group collapses into a different note set — and run the **preservation** method against the unchanged committed baseline | `self::assertSame($baseline['data'], $unrestricted->json('data'));` fails with a different group count, proving the baseline is really compared and that a re-grouping regression is caught rather than absorbed | `git checkout -- ` that file |

Exact commands are the ones already in §9.9 for each method; the mutation changes nothing about how the test is invoked.

### 9.10 Reviewers, order and rollback

Order: (0) **raise the §6.7 ceilings and extend the allowlist in the same commit as the first test class, and confirm `php tools/feature-lane-manifest-check.php` exits 0**; (1) capture the red assertions, starting with the T9-S3 first-violation output; **run the two `T2T3_CAPTURE_BASELINE=1` commands of §9.9 on the untouched tree and commit `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` BEFORE the scope fix is written** (gate r2 M4); and capture the four §9.9b controls as expected PASSes with their falsification runs; (2) the entry/exit location-scope fix and T17, including the preservation method against that committed baseline; (3) the Shared reader flag and the three nullable DTO members; (4) the two POS controllers and the matrix; (5) the replenishment resource and its three call sites; (6) the two movement controllers, the carrying-set queries and the `meta` flags; (7) T20; (8) the trait and the full `T9-S3` procedure (steps 1–6n, 6p–6u, 7, 8 — **no step 6o**); (9) re-run S2's `ReceivingControlsVisibilityVersionTest` and `ReceivingControlsConcurrencyPostgresTest` unmodified, closing the T19 row (`T19-S3`); (10) the §9.7 checklist.

Required reviewers, all returning `ACCEPT` with `BLOCKER=0 MAJOR=0`:

- **`tenancy-authz-reviewer`** — must explicitly cite: that the masking predicate is company-scoped and computed once per request; that `null` membership is unrestricted and `[]` is fail-closed; that the entry/exit fix uses the same resolver as stock movements; that no permission, route or write path changed; and that the second-company assertions prove isolation on data.
- **`inventory-costing-reviewer`** — must explicitly cite: that no quantity is recomputed, only omitted; that a masked member is `null` and never `"0.0000"`; that terminal-transfer and non-transfer rows keep their exact quantity strings; and that rows 19–25 have **zero** files in this slice's ledger.
- **`stock-gl-interaction-reviewer`** — must explicitly cite: that the carrying-set resolution keys on `reference_type`/`reference_id` and not on the event label (seam 5); that no GL row, journal or movement is created, altered or hidden by this slice; and that the receipt-linked `Damage` clause cannot mask a movement of a terminal transfer.
- **`tenancy-authz-reviewer`** (rev 2 additions) — that `apps/api/tests/feature-lane-manifest.json` carries `Inventory 146` and `gated_ceiling 1275`, that all three class names appear at `.github/workflows/ci.yml:1142`, that `php tools/feature-lane-manifest-check.php` exits 0 (gate r1 B1); that the **four** §9.9b controls were each captured as an expected PASS **and** proven red under their mutation (gate r1 M6, extended by gate r2 M4/M5); and that step **6o is absent** from this slice's oracle, with `T19-S3`'s carriage assertions present in the version column of steps 6h–6l (gate r1 M5). **Rev 3 additions (gate r2 M4/M5):** that `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` was captured on the pre-change tree (the handback's capture-command output is the evidence) and that its fixture really contains two locations and a mixed-receiver note group; that the preservation method's FIRST assertion is the restricted-actor scoping assertion and that the byte-equality assertion follows it; and that `TransferMovementMaskingTest::test_second_company_masking_is_independent` now opens on company A's missing `meta` key rather than on company B's already-visible quantity.

Rollback: revert the S3 merge commit. No schema change, no data change; the only non-setting-gated behaviour is the entry/exit narrowing, whose revert restores the prior company-wide response.

---
## 10. Slice S4 — web, POS device client, notifications (T-4-min)

Worktree `.worktrees/t2t3-web-notifications`, branch `lane/t2t3-web-notifications`, PG database `autoerp_test_y`. **Merges after S3.**

**The mobile contract is NOT in this slice** (gate r1 MAJOR M8). It lives in the sibling repository `/Users/houssamr/Projects/syneriva/erp-mobile` and is dispatched separately as **S4-mobile** (§17.5), **after S4 merges**. No path under `/Users/houssamr/Projects/syneriva/erp-mobile` appears in any S4 file list, ledger row, commit or verification command.

### 10.1 Scope and ownership

**Spec sections implemented:** §7 (notifications), §8 (web), §8b (POS device client), §11 steps 5 and 6. **Spec §9 (mobile contract) is implemented by the separate packet S4-mobile, §17.5** — §10.9 keeps the three commitments as the ERP-side statement of the contract, and names the packet that executes them.

**§10 rows owned:** T10 (both halves — the backend notification contract and the web bell rendering), **T9-S4** (step 6o added to `TransferBlindLeakOracleTest`, plus the full-class re-run that **closes** the T9 row), and **T3-S4** (the discrepancy alert, plus the re-run of `StockTransferReceiveDamageTest` that **closes** the T3 row). See §2.2 deviation 5.

**PG-only classes:** `TransferNotificationRaceTest` (**rev 2**, gate r1 M6 — two OS processes racing one notification id; the harness needs committed fixtures and a real `ON CONFLICT DO NOTHING`, neither of which SQLite's in-memory lane can provide).

**Feature-lane manifest (gate r1 B1):** this slice adds **2** `Notification` Feature classes, so it writes `Notification 1 -> 3`, `gated_ceiling 1275 -> 1277`, and appends both names to the allowlist. `TransferBlindLeakOracleTest` is **modified**, not added, so it changes no ceiling.

**Merge safety.** The two notifications are new; nothing subscribes to them today. The web bundle is a separate deploy (staging web is its own Dokploy application), so a web change reaches users only when that application is deployed. Every web type change is compile-time enforced: the union members differ structurally, so `pnpm typecheck` fails on an un-narrowed read — that compiler failure is the mechanism, not a convention.

### 10.2 Files

Add (production):

- `apps/api/app/Modules/Inventory/Application/Notifications/TransferInitiatedNotification.php`
- `apps/api/app/Modules/Inventory/Application/Notifications/TransferDiscrepancyNotification.php`
- `apps/api/app/Modules/Inventory/Application/Listeners/NotifyOnTransferInitiated.php`
- `apps/api/app/Modules/Inventory/Application/Listeners/NotifyOnTransferDiscrepancy.php`
- `apps/api/app/Shared/Infrastructure/Notifications/IdempotentDatabaseChannel.php`
- `apps/web/src/features/stock-transfers/pages/ReceiveTransferPage.tsx`
- `apps/web/src/features/stock-transfers/components/CloseTransferDialog.tsx`
- `apps/web/src/features/stock-transfers/components/TransferReconciliationTab.tsx`
- `apps/web/src/features/stock-transfers/api/visibilityVersionGate.ts`
- `apps/web/src/features/compliance/components/ReceivingControlsSection.tsx`

Modify (production):

- `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php`
- `apps/web/src/features/stock-transfers/types/index.ts`
- `apps/web/src/features/stock-transfers/api/stockTransferApi.ts`
- `apps/web/src/features/stock-transfers/api/queries.ts`
- `apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx`
- `apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx`
- `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx`
- `apps/web/src/routes/index.tsx`
- `apps/web/src/features/inventory/types.ts`
- `apps/web/src/features/inventory/StockMovementsPage.tsx`
- `apps/web/src/features/inventory/components/ProductMovementsTab.tsx`
- `apps/web/src/features/inventory/EntryExitNotesPage.tsx`
- `apps/web/src/features/inventory/hooks/useRebalanceSuggestions.ts` (cache-gate registration only)
- `apps/web/src/features/replenishment/pages/ReplenishmentQueuePage.tsx`
- `apps/web/src/features/notifications/components/NotificationPanel.tsx`
- `apps/web/src/features/compliance/pages/FraudSettingsPage.tsx`
- `apps/web/src/lib/i18n.ts`
- `apps/web/src/locales/en/stock-transfers.json`
- `apps/web/src/locales/fr/stock-transfers.json`
- `apps/web/src/locales/en/inventory.json`
- `apps/web/src/locales/fr/inventory.json`
- `apps/web/src/locales/en/notifications.json`
- `apps/web/src/locales/fr/notifications.json`
- `apps/web/src/locales/en/compliance.json`
- `apps/web/src/locales/fr/compliance.json`
- `apps/web/src/locales/en/replenishment.json`
- `apps/web/src/locales/fr/replenishment.json`
- `apps/pos/src/types/stockDistribution.ts`
- `apps/pos/src/lib/db/repositories/locationStockRepository.ts`
- `apps/pos/src/lib/db/repositories/crossLocationStockRepository.ts`
- `apps/pos/src/lib/db/repositories/openReplenishmentRepository.ts`
- `apps/pos/src/lib/sync/syncService.ts`
- `apps/pos/src/lib/replenishment/replenishmentSyncService.ts`
- `apps/pos/src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx`
- `apps/pos/src/locales/en/pos.json`
- `apps/pos/src/locales/fr/pos.json`
- `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` (**rev 2**, gate r1 M5 — S3 created it; S4 adds the step-6o method `T9-S4` and its step-7/step-8 re-issues. Contention register row in §11.6)
- `apps/api/tests/feature-lane-manifest.json` (**rev 2**, gate r1 B1 — Notification 1→3, `gated_ceiling` 1275→1277, §6.7.3/§6.7.4)
- `.github/workflows/ci.yml` (**rev 2**, gate r1 B1 — two class names appended to the `backend-test-pgsql` `--filter` alternation at `:1142`, §6.7.5)
- `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`

**No path under `/Users/houssamr/Projects/syneriva/erp-mobile` is in this list** (gate r1 M8). The mobile edit is packet S4-mobile, §17.5.

**Every string above is a literal repo-relative path, with no alias and no ellipsis (closes gate r3 MAJOR M1).** Rev 3 abbreviated five French locale files as `.../fr/<ns>.json` inside the English bullets, so a literal dispatch inventory, an ownership checker or a handback comparison could not resolve them and the ledger was only exact under human inference. The ten web locale files and the two POS locale files are now one bullet each; all twelve were opened at the rev-6 pin `f9f6f4480` (kept by rev 7) and exist. The counts are **unchanged**, and now bullet count equals path count so a parser needs no normalisation: the "Modify (production)" block holds **40 bullets = 40 paths**. Three of them are the register's lane-infrastructure and test-class entries, explicitly marked as such in the bullets themselves (`apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php`, `apps/api/tests/feature-lane-manifest.json`, `.github/workflows/ci.yml`), leaving the **37** production-source modify paths rev 3 named; removing the fourth register member, `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php`, leaves the **36** single-push production modifies §11.4 counts as `16 + 7 + 12 + 1 = 36` — 16 `apps/web` source files, 7 `apps/pos` source files, **12** locale files (ten under `apps/web/src/locales`, two under `apps/pos/src/locales`) and `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`. The lane's ledger totals — 159 single-push files, 13 multi-push files, 30 touches, 189 push rows, 172 distinct ERP files — are therefore untouched, which is what gate r3's "Source-ledger verification" asked to preserve.

Add (tests):

- `apps/api/tests/Feature/Notification/TransferNotificationTest.php` (T10 backend half, **T3-S4**)
- `apps/api/tests/Feature/Notification/TransferNotificationRaceTest.php` (**rev 2**, gate r1 M6 — T10 two-worker race, **PG-only**)
- `apps/web/src/features/stock-transfers/pages/__tests__/ReceiveTransferPage.test.tsx`
- `apps/web/src/features/stock-transfers/pages/__tests__/StockTransferDetailPage.permissions.test.tsx`
- `apps/web/src/features/stock-transfers/pages/__tests__/StockTransferBlindRendering.test.tsx`
- `apps/web/src/features/stock-transfers/__tests__/visibilityVersionGate.test.tsx`
- `apps/web/src/features/stock-transfers/__tests__/transferUnionNarrowing.test-d.ts`
- `apps/web/src/features/inventory/__tests__/StockMovementsPage.masked.test.tsx`
- `apps/web/src/features/inventory/__tests__/EntryExitNotesPage.masked.test.tsx`
- `apps/web/src/features/notifications/__tests__/NotificationPanel.transfers.test.tsx` (T10 web half)
- `apps/pos/src/lib/db/repositories/__tests__/locationStockRepository.incoming.test.ts`
- `apps/pos/src/lib/db/repositories/__tests__/dropVisibilityCaches.test.ts`
- `apps/pos/src/components/organisms/CrossLocationStockSection/__tests__/CrossLocationStockSection.masked.test.tsx`

### 10.3 Notifications (T-4 minimum)

Both notifications extend `Illuminate\Notifications\Notification implements ShouldQueue`, use `Queueable`, set `public $afterCommit = true`, and run on the **default** queue — no new named queue, therefore no `apps/api/config/horizon.php` change and no risk of the "unlisted queue is silently never consumed" failure (CLAUDE.md rule 20). `HorizonQueueCoverageTest` continues to pass unchanged.

**Recipients are resolved in the listener, in the request, inside `DB::afterCommit` — never in the worker** (CLAUDE.md rule 20). The worker runs with **no** `CompanyContext`; T10 clears it explicitly before processing the job.

**Deterministic identity.** Each notification sets `$this->id = Uuid::uuid5(self::NAMESPACE_TRANSFER_NOTIFICATIONS, "{$type}:{$receiptOrTransferId}:{$userId}")` in its constructor. Laravel keeps a preset id, and `notifications.id` is the uuid primary key.

**Idempotent delivery inside the queued job.** Both notifications implement:

```php
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return ! DatabaseNotification::query()->whereKey($this->id)->exists();
    }
```

A replayed job finds the row, skips and completes. Because the stock `DatabaseChannel::send` is a relation `create()` that would throw on a concurrent duplicate, `via()` returns `[IdempotentDatabaseChannel::class]` — a project channel extending `DatabaseChannel` whose `send()` inserts through `insertOrIgnore` (PostgreSQL `ON CONFLICT DO NOTHING`). It writes **every column the stock path writes, plus the timestamps Eloquent would add**: `id`, `type`, `data` (json-encoded, because the query builder bypasses the model's `data => array` cast), `read_at`, `notifiable_type = $notifiable->getMorphClass()`, `notifiable_id = $notifiable->getKey()`, and **`created_at` and `updated_at` set to `(new DatabaseNotification)->freshTimestampString()`**. That last point is load-bearing: `notifications` uses `timestampsTz()`, which is nullable by construction, and a null `created_at` would reach the API as `created_at: null` — the list orders by `orderByDesc('created_at')` (`apps/api/app/Modules/Notification/Presentation/Controllers/NotificationController.php:20`) and the web contract types it as a non-null `string`. T10 asserts the contract on both sides. The primary key is the last line of defence and is never allowed to fail the job. `databaseType()` is honoured by `buildPayload()`, so the bell's `type` switch is unchanged in shape.

**`TransferInitiatedNotification`** — `databaseType()` returns `inventory.transfer.initiated`. `toDatabase()` data:

```
{ transfer_id, transfer_number, source_location_name, destination_location_id,
  destination_location_name, line_count, initiated_by_name, blind, deep_link }
```

No quantities, no notes. `deep_link = /inventory/stock-transfers/{id}/receive`. Recipients: destination-accessible memberships holding `inventory.transfers.complete`, **minus the initiator**. Listener `NotifyOnTransferInitiated` on the plain `StockTransferInitiated`.

**`TransferDiscrepancyNotification`** — `databaseType()` returns `inventory.transfer.received_with_discrepancy` when `kind = receipt` and `hasDiscrepancy`, and `inventory.transfer.closed` when `kind = close`. `toDatabase()` data:

```
{ transfer_id, transfer_number, receipt_id, receipt_number, kind, disposition,
  destination_location_name, lines_with_discrepancy, received_by_name, deep_link }
```

No quantities, no notes. `deep_link = /inventory/stock-transfers/{id}`. Recipients: `inventory.transfers.reconcile` holders with destination access — they can act on it (reconcile; close if they also hold `inventory.transfers.close`). Listener `NotifyOnTransferDiscrepancy` on the plain `StockTransferReceiptPosted`, wired with `Event::listen` in `InventoryServiceProvider::boot` in the style at `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php:92`. **A plain partial receipt with no discrepancy notifies nobody.**

**Reachability.** The deep link resolves for a reconcile-only recipient because `inventory.transfers.reconcile` is a disjunct of the backend list/show any-of gate (§7.6) **and** of the web detail route guard (§10.4). The bell navigates straight to `deep_link` (`apps/web/src/features/notifications/components/NotificationPanel.tsx:94`), so a notification is never sent to an actor who would land on a 403.

**Web bell.** `KNOWN_TYPES` (`apps/web/src/features/notifications/components/NotificationPanel.tsx:20`) and the `type` switch (`:45`) gain the three type strings, with `notifications` namespace keys in **en and fr** — the two locale files §10.2 lists, and no third. Rev 3 wrote "en/fr/ar" here while its inventory carried only `en` and `fr`; that is corrected rather than the inventory widened, because Arabic needs no file for this lane: `apps/web/src/lib/i18n.ts:482` builds the `ar` notifications bundle as `{ ...enNotifications, ...arNotifications }`, so an absent Arabic key resolves to the English string rather than rendering a raw key, and `apps/web/src/lib/i18n.ts:477` deliberately feeds the **English** `stock-transfers` bundle into the `ar` resource set because `apps/web/src/locales/ar/stock-transfers.json` does not exist at the rev-4 pin. Adding an Arabic file would add a path to the exact inventory and move the ledger, for no user-visible gain; Arabic coverage for this lane is a separate translation task and is recorded as such in §15.

### 10.4 Web — types, unions, narrowing, route guards, and the new surfaces

**Types.** `apps/web/src/features/stock-transfers/types/index.ts` is **split, not replaced**.

Deleted and replaced by generated DTOs (convention 11 rule 4): the entity shadows `StockTransferStatus`, `StockTransferType`, `TransferCostDistribution`, `StockTransferLine`, `StockTransferLineBatchAllocation` and `StockTransfer` (`apps/web/src/features/stock-transfers/types/index.ts:12-72`) plus the "hand-written on purpose" header comment (`:1-10`).

**Every generated symbol this slice imports, with the slice that produces it (closes gate r2 BLOCKER B1 on the consuming side).** S4 creates **no** PHP DTO; it consumes what S1 and S2 already committed, so nothing here can be missing at compile time:

| Generated TypeScript symbol | Produced by | PHP source, reproduced in full at |
|---|---|---|
| `StockTransferData` | **S1** creates it; **S2** appends `blind` and `visibility_version` | §7.12b, then §8.6 |
| `StockTransferLineData` | **S1** | §7.12b |
| `StockTransferLineBatchAllocationData` | **S1** | §7.12b |
| `TransferStatus` (seven members), `TransferType`, `TransferCostDistribution` | **S1** | §7.12 |
| `StockTransferReceiptData`, `StockTransferReceiptLineData`, `StockTransferReceiptLineLotData` | **S1** | §7.12 |
| `TransferCloseReceiptData`, `TransferCloseLineData`, `TransferCloseLineLotData` | **S1** | §7.12 |
| `TransferReconciliationData` (with `visibility_version`), `TransferReconciliationLineData`, `TransferReconciliationLotData`, `TransferReconciliationReceiptData`, `TransferReconciliationSummaryData` | **S1** creates all five; **S2** appends `visibility_version` to the first | §7.12, then §8.7b |
| `TransferReceiverViewData`, `TransferReceiverLineData`, `TransferReceiverLotData`, `TransferReceiverOwnReceiptData`, `TransferReceiverLocationData` | **S2** | §8.6b |
| `CompanyFraudSettingsData` (with `blind_receiving`, `visibility_version`) | **S2** | §8.4b |

The replacements therefore come from `php artisan typescript:transform` runs that already happened in pushes 1 and 2: `StockTransferData`, `StockTransferLineData`, `StockTransferLineBatchAllocationData`, the `TransferStatus` (now **seven** members, §7.12) / `TransferType` / `TransferCostDistribution` enums, the **receipt** family `StockTransferReceiptData` / `StockTransferReceiptLineData` / `StockTransferReceiptLineLotData`, the **close** family `TransferCloseReceiptData` / `TransferCloseLineData` / `TransferCloseLineLotData` (rev 2, gate r1 M2 — the close-only counters exist on the close family and on **no** receipt type), the **receiver** family `TransferReceiverViewData` / `TransferReceiverLineData` / `TransferReceiverLotData` / `TransferReceiverOwnReceiptData` / `TransferReceiverLocationData` (§8.6b), the **reconciliation** family `TransferReconciliationData` / `TransferReconciliationLineData` / `TransferReconciliationLotData` / `TransferReconciliationReceiptData` / `TransferReconciliationSummaryData` (§7.12), and `CompanyFraudSettingsData.blind_receiving` / `.visibility_version` (§8.4b).

**The web never reads `quantity_written_off` off a receive response**, because the generated `StockTransferReceiptLineData` has no such member and `pnpm typecheck` refuses the read. That is the client-side half of gate r1 M2, and it is a compiler failure, not a convention.

Kept in the file (request and query transport types with no generated counterpart): `CreateStockTransferLineInput`, `CreateStockTransferLineBatchAllocationInput`, `CreateStockTransferInput` (`:74-95`), `StockTransferListFilters` (`:97`), `StockTransferListResponse` (`:105`) re-pointed at the generated entity types with `status?: TransferStatus | 'all'` and **`data: (StockTransferData | TransferReceiverViewData)[]`**.

Added beside them:

```ts
export interface ReceiveStockTransferLotInput { batch_id: number; quantity_received: string; quantity_damaged: string }
export interface ReceiveStockTransferLineInput {
  transfer_line_id: string
  quantity_received: string
  quantity_damaged: string
  discrepancy_reason?: string | null
  discrepancy_note?: string | null
  lots?: ReceiveStockTransferLotInput[]
}
export interface ReceiveStockTransferInput { idempotency_key: string; notes?: string | null; lines: ReceiveStockTransferLineInput[] }
export interface CloseStockTransferInput { idempotency_key: string; disposition: 'write_off' | 'return_to_source'; reason: string; note?: string | null }

export type StockTransferShowResponse = StockTransferData | TransferReceiverViewData
export interface StockTransferReceiveResponse { receipt: StockTransferReceiptData; transfer: StockTransferShowResponse }
export interface StockTransferCloseResponse { receipt: TransferCloseReceiptData; transfer: StockTransferData }
export interface StockTransferReceiveEnvelope { data: StockTransferReceiveResponse; meta: { replayed: boolean } }
export interface StockTransferCloseEnvelope { data: StockTransferCloseResponse; meta: { replayed: boolean } }

export const isReceiverTransfer = (t: StockTransferShowResponse): t is TransferReceiverViewData => t.blind === true
```

**Envelope choice, stated.** `apiPost` returns `response.data.data` and discards the top-level `meta` (`apps/web/src/lib/api.ts:422`), so a type that puts `meta.replayed` on the unwrapped shape is a false contract. `receive` and `close` therefore use the **raw** `api.post` envelope, **because the caller consumes `meta.replayed`**: the receive page renders an idempotent replay (200 with `meta.replayed === true`) as success rather than as a duplicate post, and the close dialog does the same. Every other transfer mutation keeps `apiPost` — `complete` and `cancel` (`apps/web/src/features/stock-transfers/api/stockTransferApi.ts:45`, `:49`) have no `meta` member to lose. `list` already uses `api.get` to keep `meta` (`:31`) and keeps doing so, now with the union inside `StockTransferListResponse.data`. `show` (`:37`) is re-typed to `StockTransferShowResponse` and stays on `apiGet`; `receiverView` and `reconciliation` are new `apiGet` calls.

**List and show are unions, not the full DTO.** The server picks the builder **per transfer** on both read routes, so a web type of `StockTransferData` alone is a false contract for the very actor the any-of gate admits. `blind` is the discriminant — `TransferPayloadBuilder` emits `blind: false`, `TransferReceiverPayloadBuilder` emits `blind: true`, both always present. `isReceiverTransfer` is the **only** place the discriminant is read. Structural differences the narrowing must cover: the receiver shape carries nested `source_location{id,name}` / `destination_location{id,name}` and `initiated_at` where the full shape carries flat `source_location_name` / `destination_location_name` / `created_at`, and it carries **no** `transfer_cost`, `transfer_cost_label`, `transfer_cost_distribution`, `initiated_by_name`, `completed_by_name`, `cancelled_by_name`, `cancellation_reason` or `notes` member at all.

**Route guards.** In `apps/web/src/routes/index.tsx`, the list route (`:1402`, permission at `:1405`) and the detail route (`:1422`, permission at `:1425`) become `permissions={['inventory.transfers.view', 'inventory.transfers.complete', 'inventory.transfers.reconcile']}` — the same three-permission any-of as the backend read gate, using `RequirePermission`'s existing any-of semantics (`apps/web/src/features/auth/components/RequirePermission.tsx:60`). A new route `stock-transfers/:id/receive` renders `ReceiveTransferPage` with `permission="inventory.transfers.complete"`. All three, plus `stock-transfers/new`, get `moduleKey="inventory"` like the other inventory hub routes. The sidebar item stays on `inventory.transfers.view` (`apps/web/src/components/organisms/Sidebar/Sidebar.tsx:239`; its `permission` prop is single-valued) — a complete-only receiver, and equally a reconcile-only supervisor, enters through the bell deep link or the list URL. A sidebar any-of prop is out of scope.

**Detail page narrows on `blind` before reading any full-only member.** The summary section reads them unconditionally today — `transfer.source_location_name` (`apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:131`), the cost and cost-distribution rows, the initiated-by row, the completed rows, **the cancelled rows and the notes row** — all inside the section that opens at `:124` and closes at `:176`. The real edit target is therefore the whole block `:124`–`:176`. After the union it splits once, `if (isReceiverTransfer(transfer))`: the receiver branch renders transfer number, status, `transfer.source_location.name`, `transfer.destination_location.name` and `transfer.initiated_at`, and **nothing else** — no cost row, no cost-distribution row, no initiated-by / completed-by / cancelled-by rows, no notes, because those members do not exist on the receiver shape. The full branch is today's block verbatim. **No `?? '—'` fallback on a member the union does not carry**: an absent concept is an absent row, not an em dash on a full-shape label.

**Actions.** `canComplete` (`apps/web/src/features/stock-transfers/pages/StockTransferDetailPage.tsx:38`) is replaced by an exhaustive `TRANSFER_ACTIONS: Record<TransferStatus, { receive: boolean; close: boolean; cancel: boolean }>` (precedent: the badge record at `apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5`). **"Receive all"** — today's Complete button at `:98` — renders **iff `TRANSFER_ACTIONS[status].receive && transfer.blind === false`**; `blind` comes from the server's builder choice, so the receiver shape simply has no such button (OD-1). **Receive** is a link to `ReceiveTransferPage`, which uses `<QuantityInput>` emitting strings at the line's `unit.decimal_places`, a `Select` for `discrepancy_reason` limited to `damaged_in_transit | other` and enabled only when damaged > 0, lot rows for lot-tracked lines, **one `crypto.randomUUID()` idempotency key per page mount**, and treats a 200 with `meta.replayed === true` as success. **Close** is a `ConfirmDialog` with a disposition radio, a reason `Select` (all four) and a note `Textarea`, rendered **only** with both `inventory.transfers.reconcile` and `inventory.transfers.close`. **Reconciliation** is a tab guarded by `RequirePermission permission="inventory.transfers.reconcile"`, with variance cells using design tokens from `@/lib/designTokens` (CLAUDE.md rule 18).

**List page.** `STATUS_OPTIONS` (`apps/web/src/features/stock-transfers/pages/StockTransferListPage.tsx:15`) is replaced by an exhaustive object literal so a generated case missing from it fails `pnpm typecheck`:

```ts
const TRANSFER_STATUS_FILTERS = {
  draft: true, in_transit: true, partially_received: true, completed: true,
  closed_with_writeoff: true, closed_returned: true, cancelled: true,
} as const satisfies Record<TransferStatus, true>
const STATUS_OPTIONS = ['all', ...(Object.keys(TRANSFER_STATUS_FILTERS) as (keyof typeof TRANSFER_STATUS_FILTERS)[])] as const
```

The badge `Record` (`apps/web/src/features/stock-transfers/components/StockTransferStatusBadge.tsx:5`) gains the three new variants for the same reason. **List rows narrow on `blind` too**: four cells read full-only members unconditionally today — `tx.source_location_name` (`:125`), `tx.destination_location_name` (`:128`), `tx.transfer_cost` (`:134`) and `tx.created_at` (`:137`), inside the row map at `:114`. After the union each row computes its cells once through `isReceiverTransfer(tx)`: the two location cells fall back to `tx.source_location.name` / `tx.destination_location.name` on a receiver row (the same rendered string, a different member), and the cost and created-at cells render the em dash `'—'` on a receiver row. The table keeps one column set for both shapes, because a receiver may hold transfers of both kinds in one page.

**Settings.** A new `ReceivingControlsSection` on `apps/web/src/features/compliance/pages/FraudSettingsPage.tsx` (beside the existing section anchor at `:397`), following the `CashDrawerControlsSection` pattern, with a `Checkbox` labelled from the `compliance` namespace.

**Replenishment.** `ReplenishmentLine.requested_qty` is already `string | null` (`apps/web/src/features/replenishment/types/index.ts:4`). The history list renders `t('replenishment:matrix.requested_masked')` when `meta.transfer_quantities_masked && line.fulfillment_type === 'transfer'`; the response `meta` is read through `api.get`, since `apiGet` drops `meta`.

**i18n (CLAUDE.md rule 11).** `stock-transfers` gains `receive.*`, `close.*`, `reconciliation.*`, `status.{partially_received,closed_with_writeoff,closed_returned}`, `discrepancyReason.*` and `errors.<code>` for **every** `TransferReceiptFailureReason` case plus `INVALID_TRANSFER_STATE`, in en and fr; Arabic keeps this namespace's existing English fallback, which is stated rather than silently relied on. `notifications`, `compliance`, `inventory` and `replenishment` gain their new keys in en and fr. Editing `apps/web/src/lib/i18n.ts` touches three places (import, `resources`, `ns` array).

### 10.5 Cache hygiene and the visibility-version gate — best effort (residual R4)

**Zero staleness.** Transfer detail/list, receiver-view, stock-matrix (with incoming) and replenishment hooks set `staleTime: 0, refetchOnWindowFocus: true`, overriding the client defaults (`apps/web/src/lib/queryClient.ts:6` and the `refetchOnWindowFocus: false` beside it). **The three movement-feed queries join that list** — `apps/web/src/features/inventory/StockMovementsPage.tsx:144` (today `enabled` plus `placeholderData: keepPreviousData`, which is retained: it re-renders the previous page of the **same** key family and is scoped to one tenant/company, so it cannot resurrect a pre-flip response for a different setting), `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:85` and `apps/web/src/features/inventory/EntryExitNotesPage.tsx:73` — but **not** the removal set below, because neither endpoint carries `visibility_version` (§9.5). The R5 stock-position surfaces are not blind-relevant at all: they set nothing and are not dropped.

**Real cache keys.** `tenantScopedKey` appends tenant and company as **suffixes** (`apps/web/src/lib/tenantScopedKey.ts:29`), and React Query matches a filter key positionally **from the front**, so a filter of `tenantScopedKey(['stock-transfers'])` = `['stock-transfers', tenant, company]` never matches a leaf. The leaves this feature and its blind-relevant neighbours register are:

- `['stock-transfers', 'list', filters, tenant, company]` (`apps/web/src/features/stock-transfers/api/queries.ts:13`)
- `['stock-transfers', 'detail', id, tenant, company]` (`:20`)
- new `['stock-transfers', 'receiver-view', id, tenant, company]` and `['stock-transfers', 'reconciliation', id, tenant, company]` (same `namespace` constant at `:9`)
- `['inventory-stock-matrix', search, page, perPage, { locScope }, tenant, company]`
- `['inventory', 'stock-matrix', 'rebalance', { locScope }, tenant, company]` (`apps/web/src/features/inventory/hooks/useRebalanceSuggestions.ts`)
- `['replenishment', 'open', filters, tenant, company]` and `['replenishment', 'history', filters, tenant, company]`

Notification keys carry no quantity and are **not** dropped.

**Mechanism.** `dropVisibilityCaches(queryClient)` in `apps/web/src/features/stock-transfers/api/visibilityVersionGate.ts` runs, in order, `queryClient.removeQueries({ queryKey: ['stock-transfers'] })`, `queryClient.removeQueries({ queryKey: ['inventory-stock-matrix'] })`, `queryClient.removeQueries({ queryKey: ['inventory', 'stock-matrix'] })` and `queryClient.removeQueries({ queryKey: ['replenishment'] })` — four calls, each written out in full (gate r3 M1 sweep), all **bare literal prefixes**, the same form the existing mutations already use for invalidation (`apps/web/src/features/stock-transfers/api/queries.ts:43`), which match every leaf above whatever the trailing tenant/company. A bare prefix also removes another company's entries in the same browser session; for `removeQueries` that is harmless (nothing is refetched until a mount asks) and is deliberately preferred over a suffix-scope predicate. `useVisibilityVersionGate()` keeps the last seen version under `tenantScopedKey(['visibility-version'])` via `queryClient.setQueryData`; each blind-relevant hook passes its response through it in `select`: on a differing (or absent) stored version it **first** stores the new version, **then** calls `dropVisibilityCaches` — so the one refetch that removal provokes sees a matching version and cannot loop. The fraud-settings mutations (`apps/web/src/features/compliance/pages/FraudSettingsPage.tsx`, today invalidating only the fraud-settings predicate) store `data.data.visibility_version` and then call the same helper.

**No claim is made** that these steps are atomic across caches, or that a particular response is "first". The residual is R4.

### 10.6 Movement feeds in web — nullable quantities

Both masked feeds have exactly one web consumer family each; there is no other reader in `apps/web`.

**(a) `GET /stock-movements`** is read by `apps/web/src/features/inventory/StockMovementsPage.tsx:145` and `apps/web/src/features/inventory/components/ProductMovementsTab.tsx:85`, both bound to the exported row type `StockMovement` (`apps/web/src/features/inventory/types.ts:38` for `quantity`, with `quantity_before` and `quantity_after` beside it) and its envelope (`:60`). The three members become `string | null`, and `StockMovementsResponse.meta` becomes `OffsetPaginationMeta & { transfer_movement_quantities_masked: boolean }` — an **intersection at the call site**, not a new member on the shared `OffsetPaginationMeta`, which other endpoints share. Every read site must then narrow: the `bccomp(movement.quantity, '0')` and `formatQuantity(movement.quantity, …)` calls and the before/after cells in both files. A `null` renders the em dash `'—'` — no sign, no `formatQuantity` call, and **never** `parseFloat` (CLAUDE.md rule 19) — and the column header carries `t('inventory:stockMovements.maskedHint')` when the `meta` boolean is true.

**(b) `GET /entry-exit-notes`** is read by `apps/web/src/features/inventory/EntryExitNotesPage.tsx:73`. Its local `EntryExitNoteLine` members `quantity`, `quantity_before` and `quantity_after` become `string | null`, its `EntryExitNoteResponse.meta` gains the same boolean, and the single render site renders the em dash **without** the `'-'`/`'+'` direction prefix when the line's quantity is `null`.

`pnpm typecheck` fails on every un-narrowed read: that compiler failure is the mechanism.

### 10.7 POS device client (§8b)

Every line here is an edit target; "today" states the current code.

- **Types.** `incoming_transfer` becomes `string | null` in `apps/pos/src/types/stockDistribution.ts:12` (row) and `:28` (totals), and in `ServerIncomingRow` (`apps/pos/src/lib/db/repositories/locationStockRepository.ts:55`). `LocationStockRow`, `LocationStockDisplay`, and `ServerStockRow.quantity/reserved/available` (`:45`) stay non-nullable `string` — that is R5 row 24, and no on-hand member changes type, so no POS renderer, cache column or arithmetic on stock positions is touched. `ServerReplenishmentRow.requested_qty/suggested_qty` are already `string | null` (`apps/pos/src/api/replenishmentApi.ts:17`), the feed validator already accepts null and the cache columns are already nullable — **no POS replenishment type change**.
- **Persistence normalisation at the single write site.** `replaceIncoming` (`apps/pos/src/lib/db/repositories/locationStockRepository.ts:191`) binds `r.incoming_transfer ?? '0'` at `:216` (today it binds the raw value); the SQLite column stays `TEXT NOT NULL DEFAULT '0'` (`apps/pos/src/lib/db/migrations.ts:1589`). Step 1 of that function already zeroes every incoming column before the upsert, so exact values are replaced on every successful `pullLocationStock`, which is written under `withWriteTransaction('sync', ...)` (`apps/pos/src/lib/sync/syncService.ts:1146`).
- **Version hint (best effort).** Each of the three version-bearing pulls — `pullLocationStock` (page-1 `meta.visibility_version`), the distribution fetch (before `upsertDistribution`, `apps/pos/src/lib/db/repositories/crossLocationStockRepository.ts:43`) and the replenishment pull (`apps/pos/src/lib/replenishment/replenishmentSyncService.ts:97`, before `replaceOpenRequests`, `apps/pos/src/lib/db/repositories/openReplenishmentRepository.ts:40`) — compares the response version with `getSyncMetadata(db, 'visibility_version')` (`apps/pos/src/lib/db/repositories/syncLogRepository.ts:19`). On a difference, or when nothing is stored, the pull, **inside its own write transaction**, runs the shared helper `dropVisibilityCaches(db)`: `UPDATE location_stock SET incoming_transfer = '0'`, `DELETE FROM product_stock_distribution_cache` (a new `clearAllDistribution` in `crossLocationStockRepository.ts`), and the scoped `DELETE FROM open_replenishment_cache`. Then `setSyncMetadata(db, 'visibility_version', v)` (`apps/pos/src/lib/db/repositories/syncLogRepository.ts:28`), then its own upsert. One helper, three callers — with **no** claim that the device's caches are consistent as a set after any single response: a cache another pull has not yet refreshed is empty or zero until that pull runs. Residual R4: a device offline since before the flip keeps its cache until its first successful pull.
- **Renderers.** `ProductTable` and `ProductCard` read the normalised row, so their arithmetic is unchanged — a masked transfer incoming reads as `0`, and "arriving" derives from `incoming_po` alone. `CrossLocationStockSection` (`apps/pos/src/components/organisms/CrossLocationStockSection/CrossLocationStockSection.tsx:104`, today formatting the value with no masked branch) renders `t('crossLocationStock.masked')` — an em dash — when `row.incoming_transfer === null` or `data.totals.incoming_transfer === null`; keys are added to `apps/pos/src/locales/en/pos.json` and `apps/pos/src/locales/fr/pos.json` in the existing `crossLocationStock` block. `RequestRefillSheet` already treats a null `suggested_qty` as "no suggestion". The distribution cache stores the payload as opaque JSON, so `null` round-trips unchanged.
- **Movement feeds have no POS consumer.** Neither `GET /stock-movements` nor `GET /entry-exit-notes` is called anywhere in `apps/pos/src`, and no POS SQLite table caches either feed. The §9.5 masking therefore requires no POS type, renderer, cache or test change, and `meta.transfer_movement_quantities_masked` has no POS reader. This is recorded so a reviewer does not read the absence as an omission.
- **No device build is part of any staging push** — the POS/Tauri build is a laptop operation and is out of this lane's deployment scope (the `Device build` row of §12.1–§12.4 reads **no** for all four slices).

### 10.8 Convention-09 evidence (S-matrix)

| Writer / surface | Second company | Second location | Re-run / idempotency |
|---|---|---|---|
| `TransferInitiatedNotification` | recipients are resolved per company; a company-B receiver receives nothing for an A transfer | recipients are exactly the **destination-accessible** memberships holding `complete`; an L1-only member is not notified for an L2 transfer | processing the same queued job twice → **one** `notifications` row, zero `failed_jobs`; two workers racing on the same id → one row, no exception |
| `TransferDiscrepancyNotification` | as above, for reconcile holders | recipients are reconcile holders **with destination access** | same idempotency assertions; a rolled-back receipt sends nothing |
| `ReceiveTransferPage` (web) | the page is reached through the tenant-scoped list; a B transfer id 404s for an A actor | the receive form posts against the transfer's own destination; the page never chooses a location | one `crypto.randomUUID()` per page mount; a resubmit of the same mount replays and renders success, not a duplicate |
| POS `dropVisibilityCaches` | the device is bound to one company; a version change for that company clears its three caches and stores the new version | `location_stock` rows are per location and the zeroing statement covers every row of the device's own location set | a matching version leaves all three caches untouched; a mismatch clears and stores exactly once per pull |

### 10.9 Mobile — contract commitments, executed by the separate packet S4-mobile (closes gate r1 MAJOR M8)

The mobile receipt screen is the **primary receiving surface** and, for a blind actor, must be blind **by construction** (owner ruling). **The screen itself is M-1 and is NOT in this lane.**

**Why this is a separate packet, not part of S4.** S4's dispatch packet says "Work ONLY inside that worktree", and that worktree is in the ERP repository `/Users/houssamr/Projects/syneriva/apps/erp`. The file spec §9 requires — `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts` — is in a **different git repository**. One ERP commit cannot contain it: there is no submodule, no path mapping, and `git add` on that path from the ERP worktree fails. Rev 1 asked S4 to edit it anyway and gave it no pin, branch, commit, verification or handback. Rev 2 makes it **packet S4-mobile (§17.5)**, with its own repository pin `51e3445`, its own worktree and branch, the exact edit text, its own verification command and its own handback.

**This section is therefore the ERP-side statement of the contract** — the three commitments, unchanged in substance from rev 1 — and §17.5 is the executable packet. Nothing in this section is an S4 file, an S4 commit or an S4 verification step.

1. **Server-driven receiver projection.** The screen renders exclusively what `GET /stock-transfers/{id}/receiver-view` returns, built by `TransferReceiverPayloadBuilder` for the requesting actor at request time. The omission is the server's, never a client-side hide of a field the response carried.
2. **It never fetches or caches the full transfer shape.** No call to `GET /stock-transfers/{id}` or `GET /stock-transfers` from the receive flow, and nothing derived from a full payload is persisted in a mobile cache the receive screen reads — so there is no local copy of a sent/expected/remaining quantity for a blind actor to reach, online or offline.
3. **A NEVER-INCLUDE guard on the generated types**, in the exact shape counting already ships (`/Users/houssamr/Projects/syneriva/erp-mobile/src/features/counting/api/countingApi.ts:193-197`, whose live text is `// NEVER INCLUDE: theoretical_qty, count_1_qty, count_2_qty, count_3_qty` closing the `CountingItem` interface). The transfer equivalent is a new `TransferReceiverLine` type carrying its own NEVER-INCLUDE comment, and the existing supervisor shapes `ReceiptStatusLine` / `ReceiptStatus` (`/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts:58-73`, whose members `quantity_ordered`, `quantity_received`, `quantity_remaining`, `total_ordered`, `total_received` are legitimate for a PO supervisor) gain a SUPERVISOR-SHAPE comment saying so. **Exact edit text and packet: §17.5.**

M-1 will also add `transferReceivingKeys.all = ['transfer-receiving']` and store `visibility_version` per tenant+company, dropping both key families on a mismatch. Residual R4 applies. `close` is not a mobile action and no "receive all" exists on mobile. **The web receipt surface is under the same rule**: `ReceiveTransferPage` renders the receiver projection, the detail page narrows on `blind`, and "Receive all" is not rendered in blind mode (§10.4).

### 10.10 Red-first tests

**The two-worker notification race — harness specification (closes gate r1 MAJOR M6 and gate r3 MAJOR M3 for T10).** Spec §7 requires that **two workers racing the same notification id** write exactly one row. Rev 1 named only a sequential "process the same job twice", which exercises `shouldSend()` and never reaches the `insertOrIgnore` that the concurrent case exists to prove. Rev 2 added a dedicated PG-only class whose harness mirrors `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php`, the T-1 class already on `dev`.

**What gate r3 M3 found, and the redesign.** Rev 3 said the first run's first failing assertion was `self::assertSame(0, $contender->wait(), …)`, failing on a duplicate primary key. That is **not reachable at S4's capture point**: at the start of S4 no `TransferInitiatedNotification`, no discrepancy notification, no close notification and no `IdempotentDatabaseChannel` exists at HEAD, so both the parent and the child fail during construction/autoload long before any INSERT, and the promised `SQLSTATE[23505]` stderr cannot be captured. Rev 3's barrier was also unsound: it had the parent take `pg_advisory_lock(hashtext(:notification_id))` and expected the child to block, but nothing made the child take that same advisory lock.

Rev 4 makes the race **self-contained at first-red time** and replaces the barrier with the precedent's own mechanism. Two changes, both inside `TransferNotificationRaceTest` itself:

1. **A test-local notification fixture.** The class declares, in its own file below the test class, `final class RaceProbeNotification extends \Illuminate\Notifications\Notification`, with a constructor that presets `$this->id` to a caller-supplied UUID, a `toDatabase(object $notifiable): array` returning the constant `['probe' => true]`, `databaseType(): string` returning `'test.race.probe'`, and — **at capture time** — `via(object $notifiable): array` returning `[\Illuminate\Notifications\Channels\DatabaseChannel::class]`, Laravel's stock channel whose `send()` is a relation `create()`. Nothing in the production notification set has to exist for step 1 to run: `Illuminate\Notifications\Notification`, `DatabaseChannel` and `DatabaseNotification` are all vendor classes on `dev` today, and the notifiable is a `User` created by the class's own committed fixture.
2. **The barrier is an uncommitted duplicate key, not an advisory lock.** The parent opens a transaction, sends the probe (the `notifications` row for id `X` is written but **uncommitted**), tags its own `application_name`, and spawns the child, which sends the **same** probe with the **same** id. PostgreSQL makes the child's duplicate-key insert wait on the uncommitted tuple's transaction — reported in `pg_stat_activity` as `wait_event_type = 'Lock'` (wait event `transactionid`), which is the exact predicate the precedent polls. The parent asserts the child really blocked, commits, and only then joins. This is the precedent's shape — open transaction, poll, commit, join — with a unique index in place of a row lock.

| Element | Cited precedent in `apps/api/tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php` (re-derived at the rev-4 pin) | What `TransferNotificationRaceTest` does |
|---|---|---|
| Second worker | `Symfony\Component\Process\Process` spawned at `:111` with `[PHP_BINARY, '-r', $script, ...args]`, cwd `base_path()`, env `$this->childEnvironment()` (`:163`), `timeout: 20` — **not `pcntl_fork`**, which is unavailable in this test image and would share the parent's PDO handles | Identical construction. The child script boots the framework with the same three lines the precedent uses at `:98-100` — `require 'vendor/autoload.php'`, `$app = require 'bootstrap/app.php'`, `$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap()` — **plus one line the precedent does not need: `require 'tests/Feature/Notification/TransferNotificationRaceTest.php';` immediately after `vendor/autoload.php`.** Test classes are PSR-4 mapped `Tests\` → `tests/` (`apps/api/composer.json:60-65`), so a class declared *below* the test class in `TransferNotificationRaceTest.php` is **not** autoloadable by name in an independent `php -r` child; the explicit `require` loads the file (its `Tests\TestCase` parent and PHPUnit's `TestCase` resolve through the same Composer autoloader, which is a dev-mode autoloader in the test image) and defines `Tests\Feature\Notification\RaceProbeNotification` before the child constructs it. The exact child script is: `require 'vendor/autoload.php'; require 'tests/Feature/Notification/TransferNotificationRaceTest.php'; $app = require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); [$tag, $userId, $notificationId] = array_slice($argv, 1); DB::statement("SET application_name = '".$tag."'"); $user = App\Modules\Identity\Domain\User::query()->findOrFail($userId); $user->notify(new Tests\Feature\Notification\RaceProbeNotification($notificationId));` — then it tags `application_name` from `$argv`, loads the notifiable `User` by id, constructs `RaceProbeNotification` with the id passed in `$argv`, and calls `$notifiable->notify($notification)` with `QUEUE_CONNECTION=sync` (already set by `childEnvironment()` at `:166`), so delivery happens in-process |
| Committed fixtures | `setUp()` deliberately does **not** use `RefreshDatabase` (`:57`, comment "the second process must see committed fixtures"), and `tearDown()` deletes exactly this test tenant's rows (`:71-87`) | Identical: no `RefreshDatabase`; `tearDown()` deletes `notifications`, `users`, `locations`, `companies` and finally asserts `self::assertSame(1, DB::table('tenants')->where('id', $this->tenant->id)->delete());` |
| PG-only skip | `if (DB::getDriverName() !== 'pgsql') { $this->markTestSkipped(...); }` at `:54-55`, class attributed `#[Group('pg')]` at `:34` | Identical, with the message "Concurrent notification delivery and ON CONFLICT DO NOTHING require PostgreSQL." |
| Making the race real | The parent holds an open transaction (`DB::beginTransaction()` at `:92`, the conflicting write at `:95`), tags `application_name` and polls `pg_stat_activity` for `wait_event_type = 'Lock'` in the loop at `:115-123`, asserts `self::assertTrue($blocked, …)` at `:124`, then `DB::commit()` at `:125` | Same mechanism, same predicate. `DB::beginTransaction()`; `$notifiable->notify(new RaceProbeNotification($id))` writes the uncommitted `notifications` row; `$tag = 't2t3-notif-race-'.$id`; spawn; poll `SELECT wait_event_type FROM pg_stat_activity WHERE application_name = ?` with the same `pg_stat_clear_snapshot()` refresh and the same 10-second deadline; `self::assertTrue($blocked, …)`; `DB::commit()`. The child is blocked on the **primary key** of `notifications`, not on an advisory lock — no `pg_advisory_lock` is used anywhere |
| Assertions after the join | `self::assertSame(0, $this->contender->wait(), $this->contender->getErrorOutput());` at `:126`, before any row assertion | Identical position and identical ordering, and it is the **first failing assertion** at capture: with the stock `DatabaseChannel`, the child's insert unblocks on the parent's commit, finds the committed duplicate and raises `SQLSTATE[23505] duplicate key value violates unique constraint "notifications_pkey"`, so the child exits non-zero and `getErrorOutput()` carries that message verbatim |

**Capture order for the race, and what each step proves.** The gate requires the first failing assertion to be capturable **at** its capture point, so the class is written and run in three explicit stages, in this order, and the handback records the stderr of stage 1:

| Stage | Tree state | What runs | Expected |
|---|---|---|---|
| 1 — the red | S4's starting tree: no `TransferInitiatedNotification`, no `TransferDiscrepancyNotification`, no `IdempotentDatabaseChannel`. The **only** new artifact is `TransferNotificationRaceTest.php` itself, containing the probe fixture. `RaceProbeNotification::via()` returns the stock `DatabaseChannel::class` | `test_two_workers_racing_the_same_notification_id_write_exactly_one_row` | `self::assertTrue($blocked, …)` **passes** — the child really blocks on the uncommitted primary key. `self::assertSame(0, $contender->wait(), $contender->getErrorOutput());` **FAILS**, printing the child's `SQLSTATE[23505] … "notifications_pkey"`. That failure, with that stderr, is the captured red |
| 2 — the green | `IdempotentDatabaseChannel` added (§10.3), `RaceProbeNotification::via()` repointed at it. No production notification class is needed yet | the same method | all four assertions pass: `$blocked` true, child exit `0`, `self::assertSame(1, DatabaseNotification::query()->whereKey($id)->count());`, `self::assertSame(0, DB::table('failed_jobs')->count());` |
| 3 — the production binding | the two notification classes and the two listeners added | `test_two_workers_racing_the_production_initiated_notification_write_exactly_one_row` | green-gate only; it races the real `TransferInitiatedNotification` through the same harness, so the T10 contract binds the **shipped** classes and not only the probe. It is **not** a red-first row: it cannot run before its class exists, and it is classified accordingly in §10.10b |

Stage 1 is executable against the untouched tree because every symbol it needs is a vendor class or a fixture the test itself creates, and the child process loads that fixture with the explicit `require` of the test file (gate r4 M1) before constructing it — without that line the child would exit with `Class "Tests\Feature\Notification\RaceProbeNotification" not found` before any INSERT, the parent would never observe a lock, and `assertTrue($blocked, …)` rather than the duplicate-key assertion would fail first. That is the difference from rev 3, whose stage 1 needed a production class that does not exist. The exact commands are in the row table below and in §13; both use a self-contained `phpunit-pgsql.xml` invocation rather than the `docs/sessions/t1/pg-test.sh` helper, which is a **gitignored session artifact** (CLAUDE.md rule 15) absent from a fresh worktree at the rev-4 pin — wherever else this plan writes that helper, the same substitution applies and is the memory rule `feedback_pg_test_db_per_session` written out.

**`T9-S4` — step 6o added to S3's oracle.** S4 adds one method to `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` and re-runs the **whole class**, which is what closes the T9 row (§2.2 deviation 5, §9.6).

| Exact file | Exact class::method / test name | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` (**modified**, not added) | `TransferBlindLeakOracleTest::test_the_notification_surface_returns_no_document_quantity_to_a_blind_actor` (**T9-S4**, step 6o) | `self::assertNotSame([], $body['data'], '6o: the blind actor must actually HAVE a transfer notification, or the scan proves nothing');` — **empty today**, because no `inventory.transfer.initiated` notification type exists. The method then runs `assertNoForbiddenKeys($body, $K_notification, '6o')` and `assertNoSentinelValues($body, $S, '6o')` over the whole body, and asserts `line_count === 2` and a present `deep_link` on the `inventory.transfer.initiated` row | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferBlindLeakOracleTest::test_the_notification_surface_returns_no_document_quantity_to_a_blind_actor'` | PostgreSQL **and** SQLite |
| same | the **whole class**, re-run to close the T9 row | n/a — this is a green-gate re-run, not a red-first row. Every method of S3's class plus the new step 6o must pass on **both** lanes | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/TransferBlindLeakOracleTest.php` and `cd apps/api && ./vendor/bin/phpunit tests/Feature/Inventory/TransferBlindLeakOracleTest.php` | PostgreSQL **and** SQLite |
| `apps/api/tests/Feature/Inventory/StockTransferReceiveDamageTest.php` (**not modified**) | the whole class, re-run to close the **T3** row after `T3-S4` lands | n/a — green-gate re-run | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferReceiveDamageTest.php` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php` (**not modified**) | the whole class, re-run as a T19 regression after S3 closed the row | n/a — green-gate re-run | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php` | PHPUnit PostgreSQL |

**S4's own red-first rows:**

| Exact file | Exact class::method / test name | First failing assertion | Exact command | Lane |
|---|---|---|---|---|
| `apps/api/tests/Feature/Notification/TransferNotificationTest.php` | `TransferNotificationTest::test_initiated_notification_reaches_destination_receivers_and_excludes_the_initiator` (T10) | `self::assertSame([$receiverA->id, $receiverB->id], $notifiedUserIds);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferNotificationTest::test_initiated_notification_reaches_destination_receivers_and_excludes_the_initiator'` | PHPUnit PostgreSQL (`autoerp_test_y`) |
| same | `TransferNotificationTest::test_no_alert_on_a_plain_partial_receipt_and_one_alert_on_damage_and_on_each_close` (T10, **T3-S4**) | **Gate r1 M6: rev 1 named `assertSame(0, DatabaseNotification::count())` after a clean partial receipt, which passes trivially before any notification exists. The method's assertion ORDER is inverted in rev 2 so the POSITIVE case runs first:** (1) post a receipt **with damage**, then `self::assertSame(1, DatabaseNotification::query()->where('type', 'inventory.transfer.received_with_discrepancy')->count());` — **0 today, genuinely red**; (2) close with `write_off`, then `self::assertSame(1, DatabaseNotification::query()->where('type', 'inventory.transfer.closed')->count());`; (3) close a second transfer with `return_to_source`, same assertion at 2; (4) **only then** the negative control: a clean partial receipt on a third transfer adds **no** row, `self::assertSame(3, DatabaseNotification::query()->count());`. This method is the `T3-S4` half of the T3 row; S4 also re-runs S1's `StockTransferReceiveDamageTest` unmodified (§13), which closes T3 | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferNotificationTest::test_no_alert_on_a_plain_partial_receipt_and_one_alert_on_damage_and_on_each_close'` | PHPUnit PostgreSQL |
| same | `TransferNotificationTest::test_processing_the_same_job_twice_leaves_one_row_and_no_failed_job` (T10, **sequential** replay — the race is the separate class below) | `self::assertSame(1, DatabaseNotification::query()->whereKey($id)->count());` and `self::assertSame(0, DB::table('failed_jobs')->count());` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferNotificationTest::test_processing_the_same_job_twice_leaves_one_row_and_no_failed_job'` | PHPUnit PostgreSQL |
| `apps/api/tests/Feature/Notification/TransferNotificationRaceTest.php` (**rev 2**, gate r1 M6; harness redesigned in **rev 4**, gate r3 M3) | `TransferNotificationRaceTest::test_two_workers_racing_the_same_notification_id_write_exactly_one_row` (T10) | **`self::assertSame(0, $contender->wait(), $contender->getErrorOutput());`** — the first failing assertion, and it is reachable at S4's capture point because the notification under race is the test-local `RaceProbeNotification` declared in this same file — which the child script loads with an explicit `require 'tests/Feature/Notification/TransferNotificationRaceTest.php';` before constructing it (PSR-4 cannot find it by name; gate r4 M1) — whose `via()` returns Laravel's stock `Illuminate\Notifications\Channels\DatabaseChannel::class` at capture. Assertion order: (1) `self::assertTrue($blocked, 'the contender must actually block on the uncommitted notifications primary key: '.$contender->getErrorOutput().$contender->getOutput());` — **passes** at capture, because the barrier is the uncommitted duplicate key, not any code this slice adds; (2) `DB::commit();` then the failing `assertSame(0, $contender->wait(), …)`, whose message carries the child's `SQLSTATE[23505] duplicate key value violates unique constraint "notifications_pkey"` verbatim — **that stderr is the captured red-first evidence and goes in the handback**; (3) `self::assertSame(1, DatabaseNotification::query()->whereKey($notificationId)->count());`; (4) `self::assertSame(0, DB::table('failed_jobs')->count());`. It turns green when `IdempotentDatabaseChannel` lands and `RaceProbeNotification::via()` is repointed at it (stage 2 of the capture table above) | `cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && DB_DATABASE=autoerp_test_y DB_CENTRAL_DATABASE=autoerp_test_y ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Notification/TransferNotificationRaceTest.php --filter test_two_workers_racing_the_same_notification_id_write_exactly_one_row` | **PG-only**, exclusive database (`autoerp_test_y`) |
| same | `TransferNotificationRaceTest::test_two_workers_racing_two_distinct_ids_write_two_rows` (T10 liveness, **CONTROL** case S4-C1 — see §10.10b) | `self::assertSame(2, DatabaseNotification::query()->count());` — the control that proves the harness really runs both processes and that `insertOrIgnore` does not swallow a legitimate second row. It races two `RaceProbeNotification` instances with **different** ids, so neither blocks and both commit | `cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && DB_DATABASE=autoerp_test_y DB_CENTRAL_DATABASE=autoerp_test_y ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Notification/TransferNotificationRaceTest.php --filter test_two_workers_racing_two_distinct_ids_write_two_rows` | **PG-only** |
| same | `TransferNotificationRaceTest::test_two_workers_racing_the_production_initiated_notification_write_exactly_one_row` (T10, **rev 4**, gate r3 M3 — the production-notification integration assertion) | **Not a red-first row; it is stage 3 of the capture table above and is written only after `TransferInitiatedNotification` and `IdempotentDatabaseChannel` exist.** It replays the identical harness with the shipped `TransferInitiatedNotification` in place of the probe, so the T10 contract binds the production class and not only the fixture: `self::assertTrue($blocked, …)`, `self::assertSame(0, $contender->wait(), $contender->getErrorOutput());`, `self::assertSame(1, DatabaseNotification::query()->whereKey($notificationId)->count());`, `self::assertSame('inventory.transfer.initiated', DatabaseNotification::query()->whereKey($notificationId)->sole()->type);`, `self::assertSame(0, DB::table('failed_jobs')->count());` | `cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api && DB_DATABASE=autoerp_test_y DB_CENTRAL_DATABASE=autoerp_test_y ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Notification/TransferNotificationRaceTest.php --filter test_two_workers_racing_the_production_initiated_notification_write_exactly_one_row` | **PG-only** |
| same | `TransferNotificationTest::test_worker_without_company_context_still_delivers` (T10, rule 20) | `$this->app->make(CompanyContext::class)->clear();` then `self::assertSame(1, DatabaseNotification::query()->count());` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferNotificationTest::test_worker_without_company_context_still_delivers'` | PHPUnit PostgreSQL |
| same | `TransferNotificationTest::test_inserted_row_has_non_null_timestamps_and_the_api_orders_by_created_at` (T10) | `self::assertNotNull($row->created_at);` | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferNotificationTest::test_inserted_row_has_non_null_timestamps_and_the_api_orders_by_created_at'` | PHPUnit PostgreSQL |
| same | `TransferNotificationTest::test_no_notification_payload_carries_a_quantity_or_a_note` (T10) | **`self::assertSame(3, DatabaseNotification::query()->count());`** — the positive liveness assertion, `0` today because no transfer notification type exists, so the method is red before it scans anything. **Gate r2 M5: rev 2 named the forbidden-key scan first, and a scan over zero rows passes vacuously.** Corrected order: (1) the count of 3 (one `inventory.transfer.initiated`, one `inventory.transfer.received_with_discrepancy`, one `inventory.transfer.closed`), (2) `self::assertSame(['inventory.transfer.closed', 'inventory.transfer.initiated', 'inventory.transfer.received_with_discrepancy'], $typesSorted);` — the three rows are the three expected types, not three copies of one, (3) `self::assertSame([], $forbiddenKeysFound);` over the union of all three `data` payloads at every depth, and (4) `self::assertSame([], $sentinelValuesFound);` for the fixture quantities `'12.0000'`, `'7.0000'` and the note text | `cd apps/api && ./vendor/bin/phpunit -c phpunit-pgsql.xml --filter 'TransferNotificationTest::test_no_notification_payload_carries_a_quantity_or_a_note'` | PHPUnit PostgreSQL |
| `apps/web/src/features/notifications/__tests__/NotificationPanel.transfers.test.tsx` | `renders the three new transfer types from an API fixture, sorted, and navigates by deep link` (T10 web half) | `expect(screen.getByText(t('notifications:inventory.transfer.initiated'))).toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/features/notifications/__tests__/NotificationPanel.transfers.test.tsx` | Vitest |
| `apps/web/src/features/stock-transfers/pages/__tests__/StockTransferDetailPage.permissions.test.tsx` | `reconcile-only user opens the detail page and sees the reconciliation tab without Close` | `getByRole('tab', { name: t('reconciliation.tab') })` — fails today because the detail route is guarded by `permission="inventory.transfers.view"` (`apps/web/src/routes/index.tsx:1425`) and `RequirePermission` redirects to `/dashboard` before the page mounts | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/StockTransferDetailPage.permissions.test.tsx -t 'reconcile-only'` | Vitest |
| same | `reconcile+close user sees the reconciliation tab and the Close action` | `expect(screen.getByRole('button', { name: t('close.action') })).toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/StockTransferDetailPage.permissions.test.tsx -t 'reconcile+close'` | Vitest |
| same | `user with none of the three read permissions is blocked on list and detail` (**CONTROL**, case S4-C2 — see §10.10b) | **Not a red-first row.** `expect(screen.queryByRole('tab')).toBeNull()` with the redirect asserted on both routes — **it already passes at HEAD**, because today's single-permission guard `permission="inventory.transfers.view"` (`apps/web/src/routes/index.tsx:1405`, `:1425`) redirects that actor too. **Gate r2 M5: rev 2 listed it as red-first evidence; rev 3 reclassifies it as the CONTROL it is**, whose job is to prove the widened three-permission any-of did **not** open the route to an actor holding none of the three. Its falsification mutation is named in §10.10b | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/StockTransferDetailPage.permissions.test.tsx -t 'none of the three'` | Vitest |
| `apps/web/src/features/stock-transfers/pages/__tests__/StockTransferBlindRendering.test.tsx` | `a receiver payload renders with no cost row, no initiated-by row and no Receive all button` | `expect(screen.queryByRole('button', { name: t('detail.completeAction') })).toBeNull()` | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/StockTransferBlindRendering.test.tsx` | Vitest |
| same | `a list page mixing a receiver row and a full row renders both with em dashes only on the receiver row` | `expect(receiverRow).toHaveTextContent('—')` and no `undefined` printed | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/StockTransferBlindRendering.test.tsx -t 'mixing'` | Vitest |
| `apps/web/src/features/stock-transfers/__tests__/transferUnionNarrowing.test-d.ts` | type-level | `// @ts-expect-error` on reading `transfer_cost` off the un-narrowed union | `cd apps/web && pnpm typecheck` | TypeScript |
| `apps/web/src/features/stock-transfers/pages/__tests__/ReceiveTransferPage.test.tsx` | `a replayed 200 renders as success, not as a duplicate post` | `expect(screen.getByText(t('receive.replayed'))).toBeInTheDocument()` | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/ReceiveTransferPage.test.tsx` | Vitest |
| same | `the discrepancy reason select is disabled until damaged is positive and offers only the two receipt reasons` | `expect(select).toBeDisabled()` | `cd apps/web && pnpm vitest run src/features/stock-transfers/pages/__tests__/ReceiveTransferPage.test.tsx -t 'discrepancy reason'` | Vitest |
| `apps/web/src/features/stock-transfers/__tests__/visibilityVersionGate.test.tsx` | `a differing version removes the five leaf keys and keeps the notifications control` | `expect(queryClient.getQueryData(listLeaf)).toBeUndefined()` with the notifications leaf still defined | `cd apps/web && pnpm vitest run src/features/stock-transfers/__tests__/visibilityVersionGate.test.tsx` | Vitest |
| same | `a same-version response leaves all six keys` | `expect(queryClient.getQueryData(listLeaf)).toBeDefined()` | `cd apps/web && pnpm vitest run src/features/stock-transfers/__tests__/visibilityVersionGate.test.tsx -t 'same-version'` | Vitest |
| `apps/web/src/features/inventory/__tests__/StockMovementsPage.masked.test.tsx` | `a masked transfer row renders three em dashes and the PO row renders its quantities` | `expect(maskedCells.map((c) => c.textContent)).toEqual(['—', '—', '—'])` with no `NaN`, `undefined` or `'0'` | `cd apps/web && pnpm vitest run src/features/inventory/__tests__/StockMovementsPage.masked.test.tsx` | Vitest |
| `apps/web/src/features/inventory/__tests__/EntryExitNotesPage.masked.test.tsx` | `a null line quantity renders an em dash with no direction prefix` | `expect(cell.textContent).toBe('—')` | `cd apps/web && pnpm vitest run src/features/inventory/__tests__/EntryExitNotesPage.masked.test.tsx` | Vitest |
| `apps/pos/src/lib/db/repositories/__tests__/locationStockRepository.incoming.test.ts` | `a null incoming_transfer is persisted as '0'` | `expect(row.incoming_transfer).toBe('0')` | `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/locationStockRepository.incoming.test.ts` | Vitest |
| `apps/pos/src/lib/db/repositories/__tests__/dropVisibilityCaches.test.ts` | `a version mismatch clears all three caches and stores the new version from each of the three callers` | `expect(await getSyncMetadata(db, 'visibility_version')).toBe('7')` | `cd apps/pos && pnpm vitest run src/lib/db/repositories/__tests__/dropVisibilityCaches.test.ts` | Vitest |
| `apps/pos/src/components/organisms/CrossLocationStockSection/__tests__/CrossLocationStockSection.masked.test.tsx` | `a null incoming_transfer renders the masked label` | `expect(screen.getAllByText('—')).toHaveLength(2)` | `cd apps/pos && pnpm vitest run src/components/organisms/CrossLocationStockSection/__tests__/CrossLocationStockSection.masked.test.tsx` | Vitest |

### 10.10b Red-first rows versus control rows for S4, and how each control is falsified (closes gate r2 MAJOR M5 for S4)

Gate r2 named two S4 rows whose stated first assertion passes on the untouched tree: the notification payload scan (repaired in §10.10 by putting a positive count in front) and the web "none of the three read permissions" case (reclassified below). The same audit found one more of the same shape, the distinct-ids race liveness case, which rev 2 already labelled "liveness" in prose but did not classify. All three are settled here, in the shape §9.9b uses for S3 and §7.14b uses for S1, so the three ERP slices state the same contract in the same words.

| Method / test name | Class | Expected at capture |
|---|---|---|
| `TransferNotificationTest::test_initiated_notification_reaches_destination_receivers_and_excludes_the_initiator` | RED-FIRST (`$notifiedUserIds` is `[]` today) | RED |
| `TransferNotificationTest::test_no_alert_on_a_plain_partial_receipt_and_one_alert_on_damage_and_on_each_close` | RED-FIRST (repaired in rev 2 — the positive damage case runs first) | RED |
| `TransferNotificationTest::test_processing_the_same_job_twice_leaves_one_row_and_no_failed_job` | RED-FIRST (the row count is `0`, not `1`) | RED |
| `TransferNotificationTest::test_worker_without_company_context_still_delivers` | RED-FIRST (`0`, not `1`) | RED |
| `TransferNotificationTest::test_inserted_row_has_non_null_timestamps_and_the_api_orders_by_created_at` | RED-FIRST (`$row` is `null`, so `assertNotNull($row->created_at)` errors) | RED |
| `TransferNotificationTest::test_no_notification_payload_carries_a_quantity_or_a_note` | RED-FIRST **after the rev-3 repair** (the positive count of 3 precedes the scan) | RED |
| `TransferNotificationRaceTest::test_two_workers_racing_the_same_notification_id_write_exactly_one_row` | RED-FIRST (the child exits non-zero on `SQLSTATE[23505]`; reachable at capture because the notification under race is the file's own `RaceProbeNotification` on the stock `DatabaseChannel` — gate r3 M3) | RED |
| `TransferNotificationRaceTest::test_two_workers_racing_two_distinct_ids_write_two_rows` | **CONTROL** (case S4-C1 — the harness-liveness half) | PASS *(see the rev-4 note below)* |
| `TransferNotificationRaceTest::test_two_workers_racing_the_production_initiated_notification_write_exactly_one_row` | **GREEN-GATE** (rev 4, gate r3 M3 — stage 3; it cannot exist before `TransferInitiatedNotification` does, so it is neither red-first evidence nor a control) | PASS, at stage 3 only |
| `TransferBlindLeakOracleTest::test_the_notification_surface_returns_no_document_quantity_to_a_blind_actor` | RED-FIRST (`$body['data']` is `[]` today) | RED |
| `StockTransferDetailPage.permissions.test.tsx` · `reconcile-only user opens the detail page and sees the reconciliation tab without Close` | RED-FIRST (the route guard redirects before the page mounts) | RED |
| `StockTransferDetailPage.permissions.test.tsx` · `reconcile+close user sees the reconciliation tab and the Close action` | RED-FIRST (same redirect) | RED |
| `StockTransferDetailPage.permissions.test.tsx` · `user with none of the three read permissions is blocked on list and detail` | **CONTROL** (case S4-C2) | PASS |
| `StockTransferBlindRendering.test.tsx` · both cases | RED-FIRST (the receiver payload type does not exist, so the file fails to compile) | RED |
| `transferUnionNarrowing.test-d.ts` | RED-FIRST (the generated union does not exist, so `pnpm typecheck` fails on the import) | RED |
| `ReceiveTransferPage.test.tsx` · both cases | RED-FIRST (the page component does not exist) | RED |
| `visibilityVersionGate.test.tsx` · both cases | RED-FIRST (`visibilityVersionGate.ts` does not exist) | RED |
| `StockMovementsPage.masked.test.tsx` | RED-FIRST (the page renders the quantity string, not an em dash) | RED |
| `EntryExitNotesPage.masked.test.tsx` | RED-FIRST (same) | RED |
| `locationStockRepository.incoming.test.ts` | RED-FIRST (a `null` `incoming_transfer` throws on the current non-null column write) | RED |
| `dropVisibilityCaches.test.ts` | RED-FIRST (`dropVisibilityCaches` does not exist) | RED |
| `CrossLocationStockSection.masked.test.tsx` | RED-FIRST (the component renders `'0'`, not the masked label) | RED |

**Note on S4-C1 (restated for rev 4, gate r3 M3).** `test_two_workers_racing_two_distinct_ids_write_two_rows` is a control in purpose — its job is to keep passing forever, proving the harness really runs two processes and that `insertOrIgnore` does not swallow a legitimate second row. Rev 3 said it was "also red at capture, because before the notification classes exist neither process can write anything". **That is no longer true and the classification changes accordingly.** Under the rev-4 harness the notification under race is the test's own `RaceProbeNotification`, which exists from the file's first commit, so at capture two distinct ids produce two rows and the control **PASSES from stage 1 onward**. Its expectation is therefore `PASS` at every stage, and it earns its keep the way every control in this plan does — through the falsification mutation below, not through an initial red. A future failure is to be read as "the idempotency key is too coarse", never as "the feature regressed".

**Falsification procedure for S4's two controls — mandatory, recorded in the handback.**

| Case | Control | Mutation to apply | Expected result under the mutation | Revert |
|---|---|---|---|---|
| **S4-C1** | `test_two_workers_racing_two_distinct_ids_write_two_rows` | In the test file's own `RaceProbeNotification`, temporarily ignore the constructor's id argument and preset `$this->id` to a constant, so the two "distinct" sends collapse onto one id. (After stage 3 the same mutation is applied to `TransferInitiatedNotification` by dropping `$userId` from its `Uuid::uuid5(...)` identity, which collapses two recipients onto one id.) | `self::assertSame(2, DatabaseNotification::query()->count());` fails with `1`, proving the control watches the identity granularity and not merely "some row exists" | `git checkout -- ` that file |
| **S4-C2** | `user with none of the three read permissions is blocked on list and detail` | In `apps/web/src/routes/index.tsx`, temporarily remove the `permissions={[...]}` prop from the detail route so `RequirePermission` no longer gates it | `expect(screen.queryByRole('tab')).toBeNull()` fails, because the page mounts and renders its tabs for an actor holding none of the three, proving the control really watches the guard and does not merely observe an unrelated redirect | `git checkout -- ` that file |

### 10.11 Reviewers, order and rollback

Order: (0) **raise the §6.7 ceilings (`Notification 1 -> 3`, `gated_ceiling 1275 -> 1277`) and extend the allowlist in the same commit as the first test class, and confirm `php tools/feature-lane-manifest-check.php` exits 0**; (1) capture the red assertions — **including stage 1 of the race (§10.10): write `TransferNotificationRaceTest` with its own `RaceProbeNotification` on the stock `DatabaseChannel`, run it, and save the child's `SQLSTATE[23505]` stderr, all before any production notification class is written**; (2) the two notifications, the idempotent channel and the two listeners, then `TransferNotificationTest`, then race **stage 2** (repoint `RaceProbeNotification::via()` at `IdempotentDatabaseChannel` and confirm green) and race **stage 3** (add `test_two_workers_racing_the_production_initiated_notification_write_exactly_one_row`); (3) `php artisan typescript:transform` and the web type split; (4) the union, the narrowing helper and the two type-level tests; (5) route guards and the receive page; (6) the close dialog and the reconciliation tab; (7) the cache gate; (8) the movement-feed nullability across both web consumer families; (9) the POS device changes; (10) **step 6o added to `TransferBlindLeakOracleTest` (`T9-S4`)**; (11) i18n in en/fr for every namespace; (12) `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` — the **single consolidated entry for the whole lane** (§11.4); (13) the three closing re-runs of §10.10 (`TransferBlindLeakOracleTest` on both lanes, `StockTransferReceiveDamageTest`, `ReceivingControlsVisibilityVersionTest`); (14) `pnpm typecheck`, `pnpm lint` in both `apps/web` and `apps/pos`.

**The mobile type comments are NOT a step of this order** (gate r1 M8). They are packet S4-mobile, §17.5, dispatched after this slice merges.

Required reviewers, all returning `ACCEPT` with `BLOCKER=0 MAJOR=0`:

- **`frontend-conventions-reviewer`** — must explicitly cite: that the six entity shadows are gone and the generated DTOs are imported instead (no hand-rolled FE type beside a generated DTO); that `isReceiverTransfer` is the only reader of the discriminant and that an un-narrowed read fails `pnpm typecheck`; that no `?? '—'` fallback is applied to a member the receiver union does not carry; that `receive`/`close` use the raw envelope **because** they consume `meta.replayed` while every other mutation keeps `apiPost`; that money and quantity values are strings rendered through `formatQuantity`/`<QuantityInput>` with no `parseFloat`; that every user-facing string is a `t()` key present in en and fr; that variance and masked cells use design tokens; and that the query keys go through `tenantScopedKey`/`locationScopedKey` while the removal set uses bare literal prefixes.
- **`tenancy-authz-reviewer`** — must explicitly cite: that the two web route guards carry the same three-permission any-of as the backend; that the Close action requires **both** permissions on the client and that the server is still the authority; that "Receive all" is gated on the **server-authoritative** `blind` member and not on a client-side permission guess; and that notification recipients are resolved in the request, per company and per destination access.
- **`fiscal-pos-reviewer`** — must explicitly cite: the notification identity and `shouldSend` idempotency; that `IdempotentDatabaseChannel` writes non-null `created_at`/`updated_at`; that no new named queue was introduced; that the POS device changes touch no fiscal or shift path and no on-hand member; **and (rev 2, gate r1 M6; extended rev 4, gate r3 M3) that `TransferNotificationRaceTest` really runs two OS processes against committed fixtures, mirrors `StockTransferCompleteConcurrencyPostgresTest`'s `Symfony\Component\Process\Process` harness rather than inventing one, blocks the contender on the UNCOMMITTED `notifications` primary key rather than on an advisory lock, asserts `$blocked` and then the child's exit code before asserting the row count, carries the distinct-id liveness case, and races BOTH the file-local `RaceProbeNotification` (the stage-1 red) and the shipped `TransferInitiatedNotification` (the stage-3 production binding)**.
- **`tenancy-authz-reviewer`** (rev 2 additions) — that `apps/api/tests/feature-lane-manifest.json` carries `Notification 3` and `gated_ceiling 1277`, that both new class names appear at `.github/workflows/ci.yml:1142`, and that `php tools/feature-lane-manifest-check.php` exits 0 (gate r1 B1); **and that this slice's diff contains no path under `/Users/houssamr/Projects/syneriva/erp-mobile`** (gate r1 M8) — `git diff --name-only` on the slice branch is the evidence and goes in the handback. **Rev 3 additions (gate r2 B1/M5):** that this slice adds **no** PHP DTO and that every generated symbol it imports appears in the §10.4 producing-slice table and in the committed `packages/shared/types/generated.d.ts` at the S3 tip; and that the two §10.10b controls were each captured with their stated expectation **and** proven red under their named mutation, while `TransferNotificationTest::test_no_notification_payload_carries_a_quantity_or_a_note` opens on a positive notification count rather than on the key scan.

Rollback: revert the S4 merge commit; then redeploy the staging web application explicitly, because the web bundle does not follow the API deploy. **S4-mobile rolls back independently**, by reverting its own commit in `/Users/houssamr/Projects/syneriva/erp-mobile`; the two repositories have no shared revert (§17.5).

---
## 11. Source-push ledger

Four ERP pushes to local `dev`, promoted to `origin/dev` in the order S1 → S2 → S3 → S4 (CLAUDE.md rule 21: merge into local `dev` first, promote as a clean fast-forward), **plus one commit in the sibling repository `/Users/houssamr/Projects/syneriva/erp-mobile` (S4-mobile, §17.5), which is not part of this ledger and never shares a commit with an ERP push**.

**How to read this ledger — the exactly-once rule, restated so it is actually exact (closes gate r2 MAJOR M6).** Rev 2 said "within each ERP push, every file appears exactly once" and then listed multi-push files in both the per-push lists **and** the contention register, so `LocationStockQueryService.php` was countable twice and Push 2 carried two overlapping "Production add" rows whose totals disagreed. Rev 3 partitions the lane's files into two disjoint sets and counts each exactly once:

1. **Single-push files** — every file touched by exactly one of the four pushes. These are the only files the §11.1–§11.4 lists enumerate.
2. **Multi-push files** — the **thirteen** files legitimately touched by more than one push (`apps/api/tests/feature-lane-manifest.json`, `.github/workflows/ci.yml`, the leak-oracle test class, and ten production files). These appear in **§11.6 only**, with the delta each push contributes, and are **never** listed in a per-push list.

The two committed generated artifacts of §11.5 are outside both sets by construction: they are machine output regenerated per push, not source. Every per-push total below is therefore `single-push rows + register touches for that push`, and both numbers are given so the arithmetic is reproducible rather than asserted. The merge order in §16 is what keeps the register's files conflict-free.

### 11.1 Push 1 — S1, T-2 receipt spine

Migrations (4), each path written out in full (gate r3 M1 sweep: rev 3 abbreviated the last three with a leading ellipsis): `apps/api/database/migrations/tenant/2026_09_09_100000_add_receipt_counters_to_transfer_lines.php`; `apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php`; `apps/api/database/migrations/tenant/2026_09_09_100200_add_close_columns_to_stock_transfers.php`; `apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php`.

Production add, single-push (31): the three receipt models; the six enums; `TransferReceiptFailureException`; the three stored events plus `StockTransferReceiptPosted`; **`StockTransferMovementSupport`** (rev 2, M3); **`TransferReceiptResult`** (rev 2, M3); `ReceiptPayloadCanonicalizer`; and the DTOs `StockTransferReceiptData`, `StockTransferReceiptLineData`, `StockTransferReceiptLineLotData`, **`TransferCloseReceiptData`**, **`TransferCloseLineData`**, **`TransferCloseLineLotData`** (rev 2, M2), **`TransferReconciliationLineData`**, **`TransferReconciliationLotData`**, **`TransferReconciliationSummaryData`**, **`TransferReconciliationReceiptData`** (rev 2, M4), **`StockTransferLineData`** and **`StockTransferLineBatchAllocationData`** (rev 3, B1 — §7.12b); plus `ReceiveStockTransferRequest` and `CloseStockTransferRequest`. The exact paths are listed in §7.2.

Production add, in the register (4, not counted here): `StockTransferReceiptService.php`, `TransferPayloadBuilder.php`, `TransferReconciliationData.php` and `StockTransferData.php` are **created** by this push and **modified** by push 2, so they live in §11.6.

**The DTO count is eleven, not ten** (gate r2 M6): S1's enumerated DTO list is `StockTransferReceiptData`, `StockTransferReceiptLineData`, `StockTransferReceiptLineLotData`, `TransferCloseReceiptData`, `TransferCloseLineData`, `TransferCloseLineLotData`, `TransferReconciliationData`, `TransferReconciliationLineData`, `TransferReconciliationLotData`, `TransferReconciliationSummaryData`, `TransferReconciliationReceiptData` — eleven names, which rev 2's prose called "the ten DTOs" while listing eleven. Rev 3 adds three more (`StockTransferData`, `StockTransferLineData`, `StockTransferLineBatchAllocationData`), so **S1 creates fourteen DTO files in total**: the twelve in the single-push list above, plus `TransferReconciliationData.php` and `StockTransferData.php` in the register — `12 + 2 = 14`.

Documentation add (1): **`docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md`** (rev 2, M7 — §7.16).

Documentation modify (1): **`docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md`** (rev 3, gate r2 M6 — the one-line successor pointer of §7.16, now owned by S1 instead of being an unowned orchestrator edit).

Production modify, single-push (10): `TransferStatus.php`; `StockTransfer.php`; `StockTransferLine.php`; `StockTransferLineBatchAllocation.php`; `StockTransferService.php`; `WeightedAverageCostService.php`; `StockMovementReferenceType.php`; `RequireAnyPermission.php`; `RolesAndPermissionsSeeder.php`; `docs/glossary.md`.

Production modify, in the register (6, not counted here): `LocationStockQueryService.php`, `StockMatrixQueryService.php`, `StockTransferController.php`, `apps/api/app/Modules/Inventory/Presentation/routes.php`, `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml`.

Tests add (16): the fifteen classes listed in §7.2 plus `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` (rev 3, gate r2 M3).

**Push-1 single-push rows: 4 + 31 + 1 + 1 + 10 + 16 = 63. Register touches in this push: 11. Push-1 total: 74 file rows.**

Reconciliation with rev 2's 69, so the delta is auditable rather than asserted: **+3** production adds (the three transfer DTOs of §7.12b, gate r2 B1); **+1** documentation modify (`PROMOTION-CHECKLIST-2026-08-26.md`, gate r2 M6); **+1** test add (the reader baseline fixture, gate r2 M3). `69 + 3 + 1 + 1 = 74`. The redistribution between the per-push list and the register moves rows between the two columns but changes no total.

### 11.2 Push 2 — S2, T-3 blind core

Migration (1): `apps/api/database/migrations/tenant/2026_09_09_110000_add_receiving_controls_to_company_fraud_settings.php`.

Production add, single-push (11): `TransferIncomingVisibility.php`; `ReceivingControlsReader.php`; `ExpectedQuantityVisibility.php`; `TransferReceiverPayloadBuilder.php`; `CompanyReceivingControlsReader.php`; `ReceivingControlsChangedV1.php`; and the five-file receiver DTO family of §8.6b — `TransferReceiverViewData.php`, **`TransferReceiverLineData.php`**, **`TransferReceiverLotData.php`**, **`TransferReceiverOwnReceiptData.php`**, **`TransferReceiverLocationData.php`** (the last four are rev 2, gate r1 M4). **Gate r2 M6: rev 2 carried BOTH a seven-file row and this eleven-file row, the second repeating the first's seven entries while its stated total counted only eleven. The obsolete seven-file row is deleted; the eleven names above are the complete, once-only list.** None of the eleven is in the register: no other push touches any of them.

Production add, in the register: none — every file this push creates is created only here.

Production modify, single-push (4): `CompanyFraudSettings.php`; `CompanyFraudSettingsData.php`; `FraudSettingsController.php`; `ComplianceServiceProvider.php`.

Production modify, in the register (10, not counted here): `InventoryServiceProvider.php`; `TransferPayloadBuilder.php`; `StockTransferReceiptService.php`; `StockTransferController.php`; `apps/api/app/Modules/Inventory/Presentation/routes.php`; **`StockTransferData.php`** and **`TransferReconciliationData.php`** and **`TransferReconciliationService.php`** (rev 3, gate r2 B1/M2 — S1 created all three, S2 adds the version members); **`apps/api/tests/feature-lane-manifest.json`**; **`.github/workflows/ci.yml`** (the last two are rev 2, gate r1 B1).

Tests add (5): the five classes listed in §8.2.

**Push-2 single-push rows: 1 + 11 + 4 + 5 = 21. Register touches in this push: 10. Push-2 total: 31 file rows.** Reconciliation with rev 2's 28: **+3** modifies (`StockTransferData.php`, `TransferReconciliationData.php`, `TransferReconciliationService.php`, gate r2 B1/M2). `28 + 3 = 31`.

### 11.3 Push 3 — S3, surface closure and the oracle

Production add: none.

Production modify, single-push (12): the fourteen files listed in §9.1 **minus** the two in the register — so `LocationStockReader.php`; `LocationIncomingRowDTO.php`; `StockDistributionRowDTO.php`; `StockDistributionDTO.php`; `StockMatrixController.php`; `PosStockLevelController.php`; `StockDistributionController.php`; `ReplenishmentRequestResource.php`; `ReplenishmentRequestController.php`; `PosReplenishmentController.php`; `StockMovementController.php`; `EntryExitNoteController.php`.

Production modify, in the register (2, not counted here): `LocationStockQueryService.php` and `StockMatrixQueryService.php`.

Lane-infrastructure modify, in the register (2, not counted here, **rev 2**, gate r1 B1): `apps/api/tests/feature-lane-manifest.json` (Inventory 143→146, `gated_ceiling` 1272→1275); `.github/workflows/ci.yml` (three class names at `:1142`).

Tests add, single-push (4): `AssertsBlindPayloads.php`; `TransferMovementMaskingTest.php`; `EntryExitNoteLocationScopeTest.php`; `entry-exit-notes-unrestricted-baseline.json` (rev 3, gate r2 M4). `TransferBlindLeakOracleTest.php` is created here and modified by push 4, so it is in the register.

**Push-3 single-push rows: 12 + 4 = 16. Register touches in this push: 5. Push-3 total: 21 file rows.** Reconciliation with rev 2's 20: **+1** test add (the entry/exit baseline fixture, gate r2 M4). `20 + 1 = 21`.

Rows 19–25 of §9.2 contribute **zero** files. That absence is an acceptance criterion, not an omission: a diff in this push touching `StockLevelData`, `ProductController`, `StockRebalanceQueryService`, `CountingReconciliationPayloadBuilder`, `BatchResource`, `BatchController`, `BatchRepository`, `FEFOInventoryService` or any POS on-hand type is a scope violation and must be rejected by the reviewer gate.

### 11.4 Push 4 — S4, web, POS, notifications

**No mobile file** (gate r1 M8). The erp-mobile edit is packet S4-mobile (§17.5), a commit in a different repository, dispatched after this push merges. It has its own one-file ledger, stated in §17.5, and appears nowhere here.

Production add, single-push (10) and tests add, single-push (13): the twenty-three paths listed in §10.2 — rev 1's twenty-two plus **`apps/api/tests/Feature/Notification/TransferNotificationRaceTest.php`** (rev 2, gate r1 M6). None of the twenty-three is in the register.

Production modify, single-push (36): §10.2's "Modify (production)" block holds **40 bullets, one per literal path** (gate r3 M1 — rev 3 abbreviated five French locale files inside the English bullets; every path is now written out). Subtract the **four** register members it marks inline — `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php`, `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php`, `apps/api/tests/feature-lane-manifest.json` and `.github/workflows/ci.yml`, each counted below in its own register line — and **36** single-push production modifies remain: **16** `apps/web` source files (six under `features/stock-transfers`, five under `features/inventory`, plus `routes/index.tsx`, `ReplenishmentQueuePage.tsx`, `NotificationPanel.tsx`, `FraudSettingsPage.tsx` and `lib/i18n.ts`), **7** `apps/pos` source files, **12** locale files (ten in `apps/web/src/locales` — `en` and `fr` for `stock-transfers`, `inventory`, `notifications`, `compliance` and `replenishment` — and two in `apps/pos/src/locales`, `en/pos.json` and `fr/pos.json`) and `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md`. `40 − 4 = 36`, and `16 + 7 + 12 + 1 = 36`; the two derivations agree.

Production modify, in the register (1, not counted here): `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php` — S2 added the `TransferIncomingVisibility` binding, S4 adds the two `Event::listen` registrations.

Tests modify, in the register (1, not counted here, **rev 2**, gate r1 M5): `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` — S3 created it, S4 adds the step-6o method (`T9-S4`).

Lane-infrastructure modify, in the register (2, not counted here, **rev 2**, gate r1 B1): `apps/api/tests/feature-lane-manifest.json` (Notification 1→3, `gated_ceiling` 1275→1277); `.github/workflows/ci.yml` (two class names at `:1142`).

**Push-4 single-push rows: 10 + 13 + 36 = 59. Register touches in this push: 4. Push-4 total: 63 file rows.** Unchanged from rev 2's 63: rev 3 adds no S4 file and removes none; it only moves the four register members out of the per-push counts and into §11.6.

**`REALIGNMENT-LOG.md` is edited exactly once, in this push, as one consolidated lane entry** (CLAUDE.md rule 9). S1, S2 and S3 each carry a "REALIGNMENT-LOG lines owed" block in their handback, and S4 consolidates them. The entry must name, at minimum:

- The new endpoints `POST /stock-transfers/{id}/receive`, `POST /stock-transfers/{id}/close`, `GET /stock-transfers/{id}/receiver-view`, `GET /stock-transfers/{id}/reconciliation`.
- The `TransferStatus` value set, now seven members.
- `POST /{id}/complete` returning 422 `BLIND_REQUIRES_COUNTED_RECEIPT` in blind mode, and keeping **200** otherwise.
- Close requiring `inventory.transfers.reconcile` **and** `inventory.transfers.close` (403 otherwise), and the three-permission any-of gate on transfer list and show.
- Nullable `incoming_transfer` plus `meta.incoming_transfer_masked` and `meta.visibility_version` on both POS stock endpoints, and nullable `incomingTransfer` on the three Shared DTOs.
- Masked replenishment quantities and note, with `transfer_quantities_masked`, `notes_masked` and `visibility_version` — **inside `meta`** on the web feed, **top-level beside `as_of`/`truncated`** on the POS feed.
- Nullable `quantity`, `quantity_before` and `quantity_after` on transfer-linked carrying rows, plus `meta.transfer_movement_quantities_masked`, on `GET /stock-movements` and `GET /entry-exit-notes`; and the **location scope now applied** to `GET /entry-exit-notes`.
- `visibility_version` on transfer payloads and the fraud-settings DTO, declared **advisory**.
- The three new notification `type` values.

### 11.5 Generated artifacts (outside the exactly-once rule, by construction)

Two committed files are machine output, not source. Each push that changes their inputs **must** regenerate and commit them in the same push, because both have a hard CI drift gate:

| Artifact | Producer | Drift gate | Pushes that must regenerate |
|---|---|---|---|
| `packages/shared/types/generated.d.ts` | `cd apps/api && php artisan typescript:transform` | `scripts/preflight.sh:110-133`; `.github/workflows/ci.yml:2683-2700` | S1 (the fourteen DTOs of §11.1 — including **`StockTransferData`, `StockTransferLineData` and `StockTransferLineBatchAllocationData`**, rev 3 gate r2 B1 — plus the seven-member `TransferStatus` and the five new enums), S2 (the five receiver DTOs, `CompanyFraudSettingsData`, and the appended members on `StockTransferData` and `TransferReconciliationData`), S4 (no new PHP DTO, but the file is re-verified byte-identical) |
| `apps/web/src/hooks/permissionsMap.generated.ts` | `cd apps/api && php artisan permissions:export-frontend-map` | `scripts/preflight.sh:140-155`; `.github/workflows/ci.yml:2705-2714` | S1 only (the two new permissions) |

Never hand-edit either file (CLAUDE.md rule 7).

### 11.6 Cross-push contention register

**Thirteen** files are touched by more than one push (rev 1: seven; rev 2: ten). **This register is the ONLY place they appear** (gate r2 M6): none of them is counted in a §11.1–§11.4 per-push list, so no file is countable twice anywhere in this ledger. Each row states what each push adds, so a reviewer can see that the later edit is additive and does not rewrite the earlier one. The three rev-3 rows are at the bottom, after the three rev-2 rows.

| File | First push | Second push | What the second push adds |
|---|---|---|---|
| `apps/api/app/Modules/Inventory/Application/Services/LocationStockQueryService.php` | S1 — `REMAINDER_SQL` + `CARRYING_STATUSES` at both query sites | S3 | the `bool $withTransferIncoming` parameter and the `null` emission |
| `apps/api/app/Modules/Inventory/Application/Services/StockMatrixQueryService.php` | S1 — the same conversion in `incoming()` | S3 | the same flag, threaded from `StockMatrixController` |
| `apps/api/app/Modules/Inventory/Presentation/Controllers/StockTransferController.php` | S1 — `receive`, `close`, `reconciliation`, `TransferPayloadBuilder`, deletion of `formatTransfer` | S2 | the per-transfer builder choice, the `receiver-view` action, the `complete` blind gate |
| `apps/api/app/Modules/Inventory/Presentation/routes.php` | S1 — three new routes and the widened read gate | S2 | one new route, `GET /{transfer}/receiver-view` |
| `apps/api/app/Modules/Inventory/Application/Services/TransferPayloadBuilder.php` | S1 — created | S2 | two members: `blind: false` and `visibility_version` |
| `apps/api/app/Modules/Inventory/Application/Services/StockTransferReceiptService.php` | S1 — created with the final nine-argument constructor of §6.7b | S2 | **exactly one change: `is_blind` on the persisted receipt row is set from `ExpectedQuantityVisibility::canSeeExpected(...)`.** Rev 2's wording ("the response's `transfer` member goes through the gated builder") contradicted §6.7b's statement that the service returns entities and the controller maps the response; gate r2 M1 is right that both cannot hold. The response is the controller's, in S1 and in S2, so the builder switch is a `StockTransferController.php` delta and appears on that row, not this one |
| `apps/api/app/Modules/Inventory/Providers/InventoryServiceProvider.php` | S2 — the `TransferIncomingVisibility` binding | S4 | the two `Event::listen` registrations for the notification listeners |
| **`apps/api/tests/feature-lane-manifest.json`** (rev 2, gate r1 B1) | S1 — Inventory 129→141, Replenishment 8→9, Migrations 14→15, `gated_ceiling` 1253→1267, plus S1's three `raise_note_2026_09_09_t2t3_s1` keys | **S2, S3 and S4** | Each adds its OWN new `raise_note_2026_09_09_t2t3_s<N>` key and rewrites only the numeric `classes` / `gated_ceiling` values for its own groups. S2: Inventory 143, Compliance 26, ceiling 1272. S3: Inventory 146, ceiling 1275. S4: Notification 3, ceiling 1277. **No slice edits or removes another slice's note**, so a merge conflict on this file is always a numeric-line conflict resolved as a UNION of the class additions, never a pick-one — the precedent and its warning are already in `groups.Inventory.note`, which records that a prior lane's ceiling conflict "fired for real, as a genuine merge conflict on this line, and was resolved as a union rather than a pick-one" |
| **`.github/workflows/ci.yml`** (rev 2, gate r1 B1) | S1 — a comment block above `:1141` and fourteen class names appended to the `--filter` alternation at `:1142` | **S2, S3 and S4** | Each appends its own names to the SAME alternation, immediately before the closing `)::/`, and adds its own comment lines below S1's block: S2 five names, S3 three, S4 two. No slice reorders or removes an existing entry, so a conflict here is also resolved as a union |
| **`apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php`** (rev 2, gate r1 M5) | S3 — created, covering steps 1–6n, 6p–6u, 7 and 8 (`T9-S3`) | S4 | one new method for step 6o (`T9-S4`) plus its step-7/step-8 re-issues; S4 then re-runs the whole class on both DB lanes, which closes the T9 row |
| **`apps/api/app/Modules/Inventory/Application/DTOs/StockTransferData.php`** (rev 3, gate r2 B1) | S1 — created, the thirty-three-member full transfer projection of §7.12b, returned by `TransferPayloadBuilder::build()` | S2 | two properties appended in last position, `public readonly bool $blind` (always `false` on this builder) and `public readonly int $visibility_version`, plus the matching two `fromModel()` arguments (§8.6). No existing property is renamed, retyped or moved, so the generated interface only grows |
| **`apps/api/app/Modules/Inventory/Application/DTOs/TransferReconciliationData.php`** (rev 3, gate r2 M2) | S1 — created with the fifteen members of §7.12 | S2 | one property appended in last position, `public int $visibility_version` (§8.7b). Every other line is byte-identical to the S1 file; the four nested reconciliation DTOs are **not** touched by S2 |
| **`apps/api/app/Modules/Inventory/Application/Services/TransferReconciliationService.php`** (rev 3, gate r2 M2) | S1 — created, building the reconciliation body from the transfer, its lines, its lots and its receipts | S2 | one constructor-injected dependency, `private readonly TransferIncomingVisibility $visibility`, and one call, `visibilityVersion($transfer->company_id)`, whose result becomes the DTO's new last argument (§8.7b). It reads the Shared contract, never a Compliance model (CLAUDE.md rule 6) |

### 11.7 Ledger totals

Two numbers, each derived from the lists above rather than asserted.

**Single-push rows — the §11.1–§11.4 lists, which contain no register member:**

| Push | Migrations | Production add | Documentation | Production modify | Tests add | Single-push rows |
|---|---:|---:|---:|---:|---:|---:|
| Push 1 — S1 | 4 | 31 | 2 | 10 | 16 | **63** |
| Push 2 — S2 | 1 | 11 | 0 | 4 | 5 | **21** |
| Push 3 — S3 | 0 | 0 | 0 | 12 | 4 | **16** |
| Push 4 — S4 | 0 | 10 | 0 | 36 | 13 | **59** |
| **Total** | **5** | **52** | **2** | **62** | **38** | **159** |

`4 + 31 + 2 + 10 + 16 = 63`; `1 + 11 + 0 + 4 + 5 = 21`; `0 + 0 + 0 + 12 + 4 = 16`; `0 + 10 + 0 + 36 + 13 = 59`; `63 + 21 + 16 + 59 = 159`. (Push 1's documentation column is the new lane checklist plus the one-line pointer into the 2026-08-26 checklist; Push 4's `REALIGNMENT-LOG.md` is counted in its production-modify column, where §10.2 lists it.)

**Register touches — the §11.6 files, counted once per push that touches them:**

| Register file | Pushes | Touches |
|---|---|---:|
| `LocationStockQueryService.php` | S1, S3 | 2 |
| `StockMatrixQueryService.php` | S1, S3 | 2 |
| `StockTransferController.php` | S1, S2 | 2 |
| `apps/api/app/Modules/Inventory/Presentation/routes.php` | S1, S2 | 2 |
| `TransferPayloadBuilder.php` | S1, S2 | 2 |
| `StockTransferReceiptService.php` | S1, S2 | 2 |
| `InventoryServiceProvider.php` | S2, S4 | 2 |
| `apps/api/tests/feature-lane-manifest.json` | S1, S2, S3, S4 | 4 |
| `.github/workflows/ci.yml` | S1, S2, S3, S4 | 4 |
| `TransferBlindLeakOracleTest.php` | S3, S4 | 2 |
| `StockTransferData.php` (rev 3) | S1, S2 | 2 |
| `TransferReconciliationData.php` (rev 3) | S1, S2 | 2 |
| `TransferReconciliationService.php` (rev 3) | S1, S2 | 2 |
| **13 files** | | **30** |

Per push: S1 touches 11 of them, S2 touches 10, S3 touches 5, S4 touches 4 — `11 + 10 + 5 + 4 = 30`.

**Totals.**

- **Push rows: `159 + 30 = 189`** — `74 + 31 + 21 + 63`, matching the four per-push totals of §11.1–§11.4.
- **Distinct ERP files: `159 + 13 = 172`** — every single-push file once, every register file once.
- Extra rows beyond the first occurrence: `189 − 172 = 17`, which is also `30 − 13 = 17`. The two derivations agree, which is the arithmetic check.

Plus the **2 generated artifacts** of §11.5, which are outside both sets by construction, plus **1 file in the sibling repository** (`/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts`, packet S4-mobile, §17.5), which is outside this ledger entirely because it is not an ERP file and never shares a commit with one.

Reconciliation with rev 2's `180 − 14 = 166`: rev 3 adds **six** distinct ERP files — the three transfer DTOs of §7.12b (gate r2 B1), `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` (gate r2 M6), and the two baseline fixtures of §7.14 and §9.9 (gate r2 M3 and M4) — so `166 + 6 = 172`; and it adds **nine** push rows — those six plus the three S2 modifies of `StockTransferData.php`, `TransferReconciliationData.php` and `TransferReconciliationService.php` — so `180 + 9 = 189`.

---

## 12. Deployment variables

> Deployment follows `docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`
> (five-push sequence §2, web block §3, gate checklist §4). This lane supplies only the variables
> below; it does not restate deploy mechanics.

Manifest U-1 is resolved as **separate Dokploy applications**, not a compose stack (`docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:7`), so there is no compose branch and no "Push 6" for any slice of this lane.

There is **no fail-closed fleet protocol in this plan**. Every push is safe under staging auto-deploy on its own: the migrations are additive and self-guarding, the only feature switch is a company-level **setting** that defaults off (not an environment flag), no queue is added, and the four pushes are ordinary source pushes. Push 5 of the manifest ("Activation") is, for this lane, a **per-company operator action** in Settings → Fraud & controls — not an environment mutation and not a fleet operation.

**Shape of what follows (closes gate r3 MAJOR M2).** Rev 3 supplied **one combined** T-2/T-3 table, which mixed all five migrations, all commands and all census decisions across four slices, so a dispatched S2, S3 or S4 handoff could not instantiate the deployment manifest from its own table. Rev 4 supplies **four** tables, one per slice, each in the exact row set and order of the accepted A-1a rev-9 §11 first table (`docs/superpowers/plans/2026-09-06-WLOTA-1a-permissions-roles-web-rev-9.md:2352-2367`), which is this repository's live instantiation of the manifest's per-slice variable list (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:291-308`). Three rules for reading them:

1. **A slice with no value for a row says `none`, explicitly, with the reason.** No row is omitted and no row is left blank, so a promotion operator substitutes rather than infers.
2. **The manifest's `Collapsed pushes` variable (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:305`) is answered inside each slice's `Push count` row**, which names which of the manifest's five pushes that slice collapses and why — the same placement A-1a rev 9 uses.
3. **Mobile repository, backups and rollback are NOT variables** and are therefore not rows in these tables. They are three named blocks after the four tables.

The four application IDs, container names and the boot-synchronisation fact are identical for all four slices — they describe the one staging environment, not the slice — and are repeated in each table rather than cross-referenced, because the manifest's contract is that each slice's table is self-sufficient.

### 12.1 S1 — T-2 receipt spine

| Variable | S1 value |
|---|---|
| `<slice>` | `t2t3-s1-receipt-spine` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_09_100000_add_receipt_counters_to_transfer_lines.php` — additive, self-guarding; `apps/api/database/migrations/tenant/2026_09_09_100100_create_stock_transfer_receipt_tables.php` — additive, self-guarding; `apps/api/database/migrations/tenant/2026_09_09_100200_add_close_columns_to_stock_transfers.php` — additive, self-guarding; `apps/api/database/migrations/tenant/2026_09_09_100300_backfill_legacy_transfer_completions.php` — **backfill**, idempotent by predicate, prerequisite = the three above, same push. All tenant-path; run through `tenants:migrate` |
| Flags | **none.** S1 introduces no config key and no environment variable. It implements exactly the `blind_receiving = false` behaviour (§2.2 deviation 2); the company setting that switches it does not exist until S2 |
| Commands | `php artisan tenants:seed --class=RolesAndPermissionsSeeder` for the two new permissions `inventory.transfers.reconcile` and `inventory.transfers.close`, then `php artisan permission:cache-reset`. Neither runs under `tenants:run`, so there is no stdout marker to grep; verify instead by asserting the two permission rows exist per tenant. `php artisan permissions:export-frontend-map` and `php artisan typescript:transform` are **build-time** commands run on the laptop and committed — never on the host. The durable operator sheet is `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` (§7.16, created in this push) |
| Censuses | `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'` before this push; grep `DAY-ONE CENSUS` and `DRIFT(` because `tenants:run` discards exit codes. Plus the lane-specific backfill check after it, per tenant: `SELECT COUNT(*) FROM stock_transfers WHERE status = 'completed' AND id NOT IN (SELECT transfer_id FROM stock_transfer_receipts)` must return **0** |
| Web changes | **none.** S1 touches no path under `apps/web` or `apps/pos`; there is no fingerprint for this push and the web application is not deployed for it |
| Device build | **no.** The POS/Tauri build is a laptop operation and is never part of a staging push. S1 changes no `apps/pos` source at all |
| Queues | **none.** S1 dispatches `StockTransferReceiptPosted` and `StockTransferCompleted` as plain after-commit events with no listener until S4, adds no `onQueue`, and leaves `apps/api/config/horizon.php` untouched |
| Push count | **one** source push, the first of the lane's four. Of the manifest's five: manifest Push 1 (preflight/census) is **collapsed into** this push because its census is run immediately before the same fast-forward; manifest Push 4 (backfill) is **collapsed into** this push because migration `2026_09_09_100300` is in this push's own migration set and spec §11.2 requires the readers and the backfill in one merge; manifest Pushes 2 and 3 run as themselves; manifest Push 5 (activation) does not apply to S1 and is deferred to the post-S4 setting toggle |
| Env path | **none used.** S1 sets no environment variable on any application, so neither the Dokploy Environment tab nor a compose `x-api-env` edit is touched |
| Application IDs | API `x5wfthp8-7cVbiUfI6Hq7`; worker `KKYDsAvk4UpYfJXVmsDj2`; scheduler `HSXqHvmo_vq7NAYIjrE3T`; web `mY6P_PHb4pw-2LdG1Y7Ml` (`docs/superpowers/plans/2026-09-09-parapharmacy-staging-topology-U1-resolution.md:13-17`). API, worker and scheduler redeploy on this push; web is not deployed |
| Container names | API `erp-staging-api-kghqex`; worker `erp-staging-worker-p2yjl1`; scheduler `erp-staging-scheduler-iqjxrv`; web `erp-staging-web-dqepfa` |
| Durable state | `/root/t2t3/` on the staging host `157.180.71.252` — the backup taken before this push, its verified byte size, the census outputs and the `DISPATCH_SHA`/merge SHA record all live there. Container-local files do not survive a redeploy; host files under `/root` do |
| Boot synchronization | The API application carries `SYNC_PERMISSIONS_ON_BOOT=true`, so **every** push is a `tenants:seed --class=RolesAndPermissionsSeeder` run (`apps/api/docker/entrypoint.sh:161-165`) and `permission:cache-reset` runs at every boot (`apps/api/docker/entrypoint.sh:176`). For S1 that is load-bearing rather than incidental: the two new permissions reach existing tenants on the push itself, and the explicit `Commands` row above is the fallback that proves it happened rather than a separate mechanism |

### 12.2 S2 — T-3 blind core

| Variable | S2 value |
|---|---|
| `<slice>` | `t2t3-s2-blind-core` |
| Migrations list | `apps/api/database/migrations/tenant/2026_09_09_110000_add_receiving_controls_to_company_fraud_settings.php` — additive, self-guarding, no prerequisite. Tenant-path; run through `tenants:migrate`. It adds `blind_receiving boolean NOT NULL DEFAULT false` and `visibility_version integer NOT NULL DEFAULT 1`, both with defaults, so existing rows need no data migration |
| Flags | **the lane's only switch, and it is not an environment flag.** `company_fraud_settings.blind_receiving`, a per-company **setting**, default `false`, written through `PATCH /fraud-settings` under `can:fraud-settings.update`. There is no `config/*.php` key and no `*_ENABLED` variable, so there is nothing to set on the API, worker or scheduler application. This push ships the switch **off** for every company and does not activate it anywhere |
| Commands | **none.** S2 adds no artisan command and re-runs none. `php artisan typescript:transform` is a build-time command run on the laptop and committed |
| Censuses | **none specific to this push.** The lane's day-one census brackets the lane at S1 and S4 (§12.1, §12.4); S2 adds no row-level write to back-fill or count. The one readback an operator does perform is a per-tenant `SELECT COUNT(*) FROM company_fraud_settings WHERE visibility_version IS NULL OR blind_receiving IS NULL`, which must return **0** |
| Web changes | **none.** S2 touches no path under `apps/web` or `apps/pos`; no fingerprint, no web deploy |
| Device build | **no.** S2 changes no `apps/pos` source |
| Queues | **none.** S2 stores `ReceivingControlsChangedV1` synchronously inside the settings transaction and adds no `onQueue`; `apps/api/config/horizon.php` is untouched |
| Push count | **one** source push, the second of the lane's four. Of the manifest's five: manifest Pushes 1 and 4 are **collapsed away** for this slice — there is no backfill and no census gate on a defaults-only additive migration; manifest Pushes 2 and 3 run as themselves; manifest Push 5 (activation) is **deliberately not taken here** even though this push creates the switch, because activation waits for the complete S1–S4 union (§2.1) |
| Env path | **none used.** S2 sets no environment variable on any application |
| Application IDs | API `x5wfthp8-7cVbiUfI6Hq7`; worker `KKYDsAvk4UpYfJXVmsDj2`; scheduler `HSXqHvmo_vq7NAYIjrE3T`; web `mY6P_PHb4pw-2LdG1Y7Ml`. API, worker and scheduler redeploy on this push; web is not deployed |
| Container names | API `erp-staging-api-kghqex`; worker `erp-staging-worker-p2yjl1`; scheduler `erp-staging-scheduler-iqjxrv`; web `erp-staging-web-dqepfa` |
| Durable state | `/root/t2t3/` on `157.180.71.252` — this push's merge SHA, its deployment `deploymentId`s and the settings readback output are appended to the same directory S1 created. No new host path |
| Boot synchronization | The API application carries `SYNC_PERMISSIONS_ON_BOOT=true`, so this push is also a seeder run (`apps/api/docker/entrypoint.sh:161-165`). S2 adds **no** permission, so the seeder must be inert for this push: the operator asserts the per-tenant permission count is unchanged from the S1 readback, which is the "prove the seeder is inert" discipline the U-1 resolution requires of every slice |

### 12.3 S3 — T-3 surface closure and the leak oracle

| Variable | S3 value |
|---|---|
| `<slice>` | `t2t3-s3-surface-closure` |
| Migrations list | **none.** S3 adds, alters and drops no column, table, index or constraint; its whole diff is reader, controller, resource and test code |
| Flags | **none.** S3 introduces no config key and no environment variable. It consumes the S2 company setting through `TransferIncomingVisibility` and adds no switch of its own |
| Commands | **none.** S3 adds no artisan command, seeds nothing and re-runs no export |
| Censuses | **none specific to this push.** S3 writes no row. Its equivalent of a census is executable and lives in CI rather than on the host: `TransferBlindLeakOracleTest` must pass on **both** DB lanes, and rows 19–25 must contribute **zero** files to this push's diff (§9.2, §11.3) |
| Web changes | **none.** S3 touches no path under `apps/web` or `apps/pos`. It changes the JSON those clients consume — nullable quantities and new `meta` flags — but ships no client code, which is why S4 must follow it before the flag is ever turned on |
| Device build | **no.** S3 changes no `apps/pos` source |
| Queues | **none.** No `onQueue`, no `apps/api/config/horizon.php` change |
| Push count | **one** source push, the third of the lane's four. Of the manifest's five: manifest Pushes 1 and 4 are **collapsed away** — no migration, no backfill, nothing to census; manifest Pushes 2 and 3 run as themselves; manifest Push 5 (activation) does not apply |
| Env path | **none used.** S3 sets no environment variable on any application |
| Application IDs | API `x5wfthp8-7cVbiUfI6Hq7`; worker `KKYDsAvk4UpYfJXVmsDj2`; scheduler `HSXqHvmo_vq7NAYIjrE3T`; web `mY6P_PHb4pw-2LdG1Y7Ml`. API, worker and scheduler redeploy on this push; web is not deployed |
| Container names | API `erp-staging-api-kghqex`; worker `erp-staging-worker-p2yjl1`; scheduler `erp-staging-scheduler-iqjxrv`; web `erp-staging-web-dqepfa` |
| Durable state | `/root/t2t3/` on `157.180.71.252` — merge SHA and deployment records appended. No new host path |
| Boot synchronization | The API application carries `SYNC_PERMISSIONS_ON_BOOT=true`, so this push is a seeder run (`apps/api/docker/entrypoint.sh:161-165`). S3 adds no permission; the operator asserts the per-tenant permission count is unchanged from the S2 readback |

### 12.4 S4 — web, POS device client, notifications

| Variable | S4 value |
|---|---|
| `<slice>` | `t2t3-s4-web-pos-notifications` |
| Migrations list | **none.** S4 adds no migration. The `notifications` table it writes into already exists on every tenant |
| Flags | **none of its own.** S4 introduces no config key and no environment variable; it reads the S2 company setting through the server-authoritative `blind` member on the payload. The lane's only switch remains `company_fraud_settings.blind_receiving`, default `false`, a per-company setting rather than an environment flag, and this push still does not activate it anywhere |
| Commands | **none on the host.** `php artisan typescript:transform` is a build-time command run on the laptop and committed; no artisan command is added, and nothing is seeded |
| Censuses | `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'` after this push, closing the bracket S1 opened; grep `DAY-ONE CENSUS` and `DRIFT(` because `tenants:run` discards exit codes. Plus one notification readback per tenant: `SELECT COUNT(*) FROM notifications WHERE created_at IS NULL` must return **0**, which is the host-side counterpart of the T10 timestamp assertion |
| Web changes | **yes** — this is the lane's only web push. Feature fingerprint string: `t2t3-transfer-receipt-blind-receiving-v1`. Staging web is its own Dokploy application and **must be deployed explicitly**; verify both the served asset hash **and** a grep of the served bundle for the fingerprint, per manifest §3 |
| Device build | **no.** The POS/Tauri build is a laptop operation and is never part of a staging push, even though this push changes seven `apps/pos` sources and two `apps/pos` locale files |
| Queues | **none.** Both notifications use the **default** queue, so `apps/api/config/horizon.php` is unchanged and `HorizonQueueCoverageTest` passes untouched. This is the CLAUDE.md rule 20 "unlisted queue is silently never consumed" hazard avoided by construction rather than by a config edit |
| Push count | **one** source push, the fourth and last of the lane's four. Of the manifest's five: manifest Push 1 is **collapsed away** (no migration to preflight); manifest Push 4 is **collapsed away** (no backfill); manifest Pushes 2 and 3 run as themselves, with Push 3's web block mandatory here; manifest Push 5 (activation) is **replaced** — after this push merges and the combined gate returns ACCEPT, activation is a per-company operator toggle in Settings → Fraud & controls, with no environment mutation and no fleet operation |
| Env path | **none used.** S4 sets no environment variable on any application. The web application still requires its own explicit redeploy, which is a deploy action, not an env path |
| Application IDs | API `x5wfthp8-7cVbiUfI6Hq7`; worker `KKYDsAvk4UpYfJXVmsDj2`; scheduler `HSXqHvmo_vq7NAYIjrE3T`; **web `mY6P_PHb4pw-2LdG1Y7Ml` — the one this push must deploy and verify** |
| Container names | API `erp-staging-api-kghqex`; worker `erp-staging-worker-p2yjl1`; scheduler `erp-staging-scheduler-iqjxrv`; **web `erp-staging-web-dqepfa`** |
| Durable state | `/root/t2t3/` on `157.180.71.252` — the closing census output, the web asset hash, the fingerprint grep result and this push's four `deploymentId`s are written there beside S1–S3's records, and the directory is the lane's promotion evidence |
| Boot synchronization | The API application carries `SYNC_PERMISSIONS_ON_BOOT=true`, so this push is a seeder run (`apps/api/docker/entrypoint.sh:161-165`) and `permission:cache-reset` runs at boot (`apps/api/docker/entrypoint.sh:176`). S4 adds no permission; the operator asserts the per-tenant permission count is unchanged from the S3 readback. The **worker** application carries no such boot step, which is why the two listeners must be safe to start mid-deploy: recipients are resolved in the request before queueing (§10.3), so a worker booting on the new image finds only jobs whose recipients were already decided |

### 12.5 Mobile repository — not a variable of any slice

**One commit in `/Users/houssamr/Projects/syneriva/erp-mobile`, packet S4-mobile (§17.5), after Push 4 merges.** It is a TypeScript comment-and-type change with no runtime effect, no screen and no route; it is **not** part of any staging deploy, triggers no mobile build, has no Dokploy application, no container and no host state. Its own handback records its pin `51e3445c1aa794f2a76d58c4771ecbbdca2f3f84` and its commit SHA. It appears in no slice table above because it is not deployed.

### 12.6 Backups — one, before Push 1 only

One verified non-zero host backup per tenant database **before S1's push**, because that push carries the backfill migration (manifest row I). Pushes 2, 3 and 4 add no row-level write to existing data — S2's migration lands defaults on new columns, S3 writes nothing, S4 only inserts new `notifications` rows — so no further backup is required by the manifest. The backup file and its verified byte size live under `/root/t2t3/` on the staging host.

### 12.7 Rollback — per slice, never `migrate:rollback`

Per slice, `git revert` of that slice's merge commit (§7.15, §8.12, §9.10, §10.11). **Never `migrate:rollback` on this lane's migrations**: `down()` on the receipt-tables migration drops posted documents. After reverting S4, the staging **web** application must be redeployed explicitly, because the web bundle does not follow the API deploy. **S4-mobile rolls back independently**, by reverting its own commit in `/Users/houssamr/Projects/syneriva/erp-mobile`; the two repositories share no revert.

---

## 13. Verification commands

Run only the named files; never the full PHPUnit or Vitest suite. Each slice runs its own block in its own worktree with its own PG database.

**S1** (`autoerp_test_u`):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api

# M3 (gate r2): the reader baseline is captured ONCE, on the PRE-CHANGE tree, as
# step (1) of §7.15 — before any production line is written. Both lanes write
# into the one committed fixture, each under its own driver key.
T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline'
T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit \
  --filter 'TransferLegacyCompletionBackfillTest::test_capture_the_pre_change_reader_baseline'
# The resulting apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json is
# committed with the slice's first test commit (§11.1) and never regenerated after.

./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Inventory/StockTransferReceiveTest.php \
  tests/Feature/Inventory/StockTransferReceiveDamageTest.php \
  tests/Feature/Inventory/StockTransferReceiveLotsTest.php \
  tests/Feature/Inventory/StockTransferReceiveValidationTest.php \
  tests/Feature/Inventory/StockTransferCloseTest.php \
  tests/Feature/Inventory/TransferReceiptEventStreamTest.php \
  tests/Feature/Inventory/TransferReceiptGlBoundaryTest.php \
  tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php \
  tests/Feature/Inventory/TransferReceiptSecondOfEverythingTest.php \
  tests/Feature/Inventory/TransferReceiptAuthorityGateTest.php \
  tests/Feature/Replenishment/TransferCloseReplenishmentSettlementTest.php

./vendor/bin/phpunit -c phpunit.xml \
  tests/Architecture/TransferInTransitReadersUseRemainderTest.php

./vendor/bin/phpunit \
  tests/Feature/Inventory/TransferLegacyCompletionBackfillTest.php

sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/StockTransferReceiveConcurrencyPostgresTest.php
sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/TransferReceiptPatternQueryPostgresTest.php
sh docs/sessions/t1/pg-test.sh tests/Feature/Migrations/TransferReceiptSchemaRerunPostgresTest.php

# M3 regression: the StockTransferMovementSupport extraction must leave the T-1
# transfer suite untouched. Both files are on dev since the T-1 merge.
sh docs/sessions/t1/pg-test.sh tests/Feature/Inventory/StockTransferCompleteConcurrencyPostgresTest.php
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferEdgeCasesTest.php

# B1: the feature-lane ceilings and the allowlist must agree with the tree.
php tools/feature-lane-manifest-check.php

CACHE_STORE=array php artisan typescript:transform
php artisan permissions:export-frontend-map
./vendor/bin/phpstan
./vendor/bin/pint --test
```

**S2** (`autoerp_test_v`):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Inventory/TransferReceiverPayloadBuilderKeyScanTest.php \
  tests/Feature/Inventory/TransferAuthorityMatrixTest.php \
  tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php \
  tests/Feature/Compliance/ReceivingControlsSecondOfEverythingTest.php

sh docs/sessions/t1/pg-test.sh tests/Feature/Compliance/ReceivingControlsConcurrencyPostgresTest.php

# B1
php tools/feature-lane-manifest-check.php

CACHE_STORE=array php artisan typescript:transform
./vendor/bin/phpstan
./vendor/bin/pint --test
```

**S3** (`autoerp_test_x`):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api

# M4 (gate r2): the entry/exit baseline for the UNRESTRICTED, NON-BLIND actor is
# captured ONCE, on the PRE-CHANGE tree, as step (1) of §9.10 — before the
# location-scope fix is written.
T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit -c phpunit-pgsql.xml \
  --filter 'EntryExitNoteLocationScopeTest::test_capture_the_pre_change_unrestricted_baseline'
T2T3_CAPTURE_BASELINE=1 ./vendor/bin/phpunit \
  --filter 'EntryExitNoteLocationScopeTest::test_capture_the_pre_change_unrestricted_baseline'
# The resulting apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json
# is committed with the slice's first test commit (§11.3) and never regenerated after.

./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Inventory/TransferBlindLeakOracleTest.php \
  tests/Feature/Inventory/TransferMovementMaskingTest.php \
  tests/Feature/Inventory/EntryExitNoteLocationScopeTest.php

./vendor/bin/phpunit \
  tests/Feature/Inventory/TransferBlindLeakOracleTest.php \
  tests/Feature/Inventory/TransferMovementMaskingTest.php \
  tests/Feature/Inventory/EntryExitNoteLocationScopeTest.php

# M5: T19-S3 CLOSES the T19 row, so S3 re-runs S2's two T19 files UNMODIFIED
# alongside its own carriage assertions. A red here means the carriage half
# broke the row half, which is exactly what a split contract must catch.
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php
sh docs/sessions/t1/pg-test.sh tests/Feature/Compliance/ReceivingControlsConcurrencyPostgresTest.php

# B1
php tools/feature-lane-manifest-check.php

./vendor/bin/phpstan
./vendor/bin/pint --test
```

**S4** (`autoerp_test_y`):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp/apps/api
./vendor/bin/phpunit -c phpunit-pgsql.xml \
  tests/Feature/Notification/TransferNotificationTest.php \
  tests/Unit/Config/HorizonQueueCoverageTest.php

# M6 + gate r3 M3: the two-worker race needs committed fixtures and a real ON
# CONFLICT, so it runs on its OWN exclusive database with no RefreshDatabase.
# The class is self-contained at capture: it declares its own RaceProbeNotification
# and races that through Laravel's stock DatabaseChannel, so stage 1 is runnable
# before any production notification class exists. Do NOT use the old
# docs/sessions/t1/pg-test.sh helper: docs/sessions/ is gitignored (CLAUDE.md
# rule 15) and the script is absent from a fresh worktree.
DB_DATABASE=autoerp_test_y DB_CENTRAL_DATABASE=autoerp_test_y \
  ./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Notification/TransferNotificationRaceTest.php

# M5: the three CLOSING re-runs. T9-S4 adds step 6o to S3's oracle, so the WHOLE
# class runs again on BOTH lanes; T3-S4 closes T3, so S1's damage class runs
# again; the T19 row is re-run as a regression after S3 closed it.
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/TransferBlindLeakOracleTest.php
./vendor/bin/phpunit tests/Feature/Inventory/TransferBlindLeakOracleTest.php
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Inventory/StockTransferReceiveDamageTest.php
./vendor/bin/phpunit -c phpunit-pgsql.xml tests/Feature/Compliance/ReceivingControlsVisibilityVersionTest.php

# B1
php tools/feature-lane-manifest-check.php

# M8: this slice's diff must contain NO path in the sibling mobile repository.
git diff --name-only dev...HEAD | grep -c 'erp-mobile' || echo 'NO MOBILE PATH IN S4 — correct'

CACHE_STORE=array php artisan typescript:transform
./vendor/bin/phpstan
./vendor/bin/pint --test

cd /Users/houssamr/Projects/syneriva/apps/erp/apps/web
pnpm vitest run \
  src/features/stock-transfers/pages/__tests__/ReceiveTransferPage.test.tsx \
  src/features/stock-transfers/pages/__tests__/StockTransferDetailPage.permissions.test.tsx \
  src/features/stock-transfers/pages/__tests__/StockTransferBlindRendering.test.tsx \
  src/features/stock-transfers/__tests__/visibilityVersionGate.test.tsx \
  src/features/inventory/__tests__/StockMovementsPage.masked.test.tsx \
  src/features/inventory/__tests__/EntryExitNotesPage.masked.test.tsx \
  src/features/notifications/__tests__/NotificationPanel.transfers.test.tsx
pnpm typecheck
pnpm lint

cd /Users/houssamr/Projects/syneriva/apps/erp/apps/pos
pnpm vitest run \
  src/lib/db/repositories/__tests__/locationStockRepository.incoming.test.ts \
  src/lib/db/repositories/__tests__/dropVisibilityCaches.test.ts \
  src/components/organisms/CrossLocationStockSection/__tests__/CrossLocationStockSection.masked.test.tsx
pnpm typecheck
pnpm lint
```

**S4-mobile** (sibling repository, no database — closes gate r1 M8):

```bash
cd /Users/houssamr/Projects/syneriva/erp-mobile
git rev-parse HEAD          # MUST print 51e3445c1aa794f2a76d58c4771ecbbdca2f3f84 or a descendant
git status --short          # MUST be clean before starting
npx tsc --noEmit            # the only gate this change can move; it must stay green
```

There is no PHPUnit, no PG database, no `preflight.sh` and no build for this packet: it edits comments and adds one exported interface. Full packet: §17.5.

**Per-slice preflight before promotion** (a `paths` run with no paths skips PHPUnit and is **not** a green):

```bash
cd /Users/houssamr/Projects/syneriva/apps/erp
PREFLIGHT_TEST_PATHS='<this slice's PHPUnit paths>' ./scripts/preflight.sh
```

Note the vitest zombie hazard: hung worker pools survive the parent kill. After any interrupted web or POS run, `ps aux | grep 'node (vitest'` and kill the workers.

---

## 14. Combined reviewer gate

No slice is merged on the implementer's word. Each slice's diff is gated by the reviewers named in its own section; the **combined** diff of all four slices is gated once more before the lane is declared closed.

| Slice | Reviewers (all must ACCEPT) |
|---|---|
| S1 | `inventory-costing-reviewer`, `stock-gl-interaction-reviewer`, `tenancy-authz-reviewer` |
| S2 | `tenancy-authz-reviewer`, `inventory-costing-reviewer`, `fiscal-pos-reviewer` |
| S3 | `tenancy-authz-reviewer`, `inventory-costing-reviewer`, `stock-gl-interaction-reviewer` |
| S4 | `frontend-conventions-reviewer`, `tenancy-authz-reviewer`, `fiscal-pos-reviewer` |
| Combined (S1..S4) | `inventory-costing-reviewer`, `stock-gl-interaction-reviewer`, `tenancy-authz-reviewer`, `frontend-conventions-reviewer` |

Required result, per reviewer, per gate:

```text
ACCEPT
BLOCKER=0
MAJOR=0
```

The **combined** gate must additionally cite, explicitly:

- That the blind guarantee holds over the union of all four diffs: no transfer, receipt, replenishment, movement/entry-exit or notification surface returns a document quantity to a `canSeeExpected() = false` actor, and `TransferBlindLeakOracleTest` proves it on both DB lanes.
- That R5 is applied, not reopened: rows 19–25 have no file in any ledger push, and steps 6p–6u assert the values are still served both before and after each flip.
- That the receipt document has exactly one writer and `/complete` delegates to it.
- That freight identity `transfer_cost = Σ allocated_transfer_cost + freight_uncapitalized` holds at 4 dp on every terminal transfer.
- That no float touches a money or quantity value anywhere in the four diffs, and that every FormRequest money/quantity rule carries its scale ceiling.
- That existing events are unmodified and every new stored event is a new class with an explicit aggregate uuid and version.
- That queued listeners resolve recipients in the request and run correctly with no `CompanyContext`.
- That every unique key added by the lane on a document table either carries `company_id` or is parent-scoped by a uuid, and no `CATALOGUE_TABLES` entry or `EXCLUDED_TABLES` waiver was needed.
- **(rev 2, gate r1 B1)** That `apps/api/tests/feature-lane-manifest.json` on the union tip reads `Inventory 146`, `Replenishment 9`, `Migrations 15`, `Compliance 26`, `Notification 3` and `gated_ceiling 1277`; that all **24** new Feature class names appear in the `.github/workflows/ci.yml:1142` alternation; that each slice's raise note carries all four §6.7.4 statements and claims no observed CI run; and that `php tools/feature-lane-manifest-check.php` exits 0.
- **(rev 2, gate r1 M2)** That no receipt-family DTO declares `quantity_written_off` or `quantity_returned`, so a `kind = receipt` echo cannot carry those keys even as `null`, and that the generated TypeScript shows the two disjoint families.
- **(rev 2, gate r1 M3)** That `StockTransferService` declares none of `lockTransfer`, `markMovementAsTransfer`, `capitalizeTransferCost`, `computeAllocationWeights` or `lineProductIds`, that `StockTransferMovementSupport` declares each exactly once, and that both writers reach them only through it.
- **(rev 2, gate r1 M5)** That the three split rows are closed: T19 by S3, T9 and T3 by S4, each with the closing slice's full-row re-run recorded in its handback.
- **(rev 2, gate r1 M7)** That `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` exists on the union tip and carries all four spec §11.4 items.
- **(rev 2, gate r1 M8)** That the union diff `S1..S4` contains **no** path under `/Users/houssamr/Projects/syneriva/erp-mobile`, and that S4-mobile's separate handback records its own repository pin and commit SHA.

Reviewers gate merges; they never merge. The orchestrator session performs every merge to local `dev` and every promotion to `origin/dev`.

---

## 15. Boundaries to later lanes

**Nothing below is in this plan.** Each item names its owner so no slice absorbs it silently.

| Item | Owner | Boundary |
|---|---|---|
| PO blind receiving (`GET /purchase-orders/{id}/receiver-view`, masked PO incoming) | **T-3b** | This lane ships transfers only. The `blind_receiving` setting is shared, so T-3b needs no new setting — only new surfaces. |
| Mobile receive screen | **M-1** | §10.9 states the three contract commitments; **packet S4-mobile (§17.5) executes the type/comment change in `/Users/houssamr/Projects/syneriva/erp-mobile`, dispatched only AFTER S4 merges** — see the sequencing note below. M-1 builds the screen, the key factories and the version store. |
| Analytics read model / fraud-alert rule over receipt events | **T-4 or later** | S1 ships the stored facts and the reference query with its shape test (§7.10). No read model, no dashboard, no scheduled job. |
| Receiver note on a receipt line | **T-2b** (ticketed from owner ruling D2) | Nullable text, written by the receiver after submit, never gating, never revealing expected. |
| Push / Expo notifications; `ReplenishmentRequestedNotification` | later | S4 ships DB channel + web bell only. |
| Receipt `void` / reversal | ticketed | An over-reported receipt has no reversal in v1; a supervisor corrects on-hand with a stock-adjustment document. Receipt rows, counters, reconciliation and terminal state stay as posted. |
| Over-receipt tolerance; discrepancy alert threshold | ticketed | v1 refuses over-receipt outright and alerts on any discrepancy. |
| Inter-company transfers | out of scope | `StockTransferService` still refuses them. |
| Sidebar any-of `permissions` prop | out of scope | The sidebar item stays single-valued; receivers enter by deep link or URL. |
| Client-side purge guarantee for pre-flip data | out of scope (residual R4) | The version hint is advisory and best effort. |
| Arabic (`ar`) translations of this lane's new web strings | later translation pass | **rev 4, gate r3 M1 sweep.** S4 ships `en` and `fr` for all five web namespaces, which is CLAUDE.md rule 11's requirement and what §10.2's exact inventory lists. Arabic is not broken by the omission: `apps/web/src/lib/i18n.ts:482` spreads `arNotifications` over `enNotifications`, so a missing Arabic notification key falls back to English, and `apps/web/src/lib/i18n.ts:477` already feeds the English `stock-transfers` bundle into the `ar` resource set because `apps/web/src/locales/ar/stock-transfers.json` does not exist. Adding Arabic files here would add paths to the exact inventory and move the ledger totals gate r3 asked to preserve. |

**S4-mobile sequencing (rev 2, gate r1 M8).** The mobile packet is **dispatched after S4 merges into local `dev`**, not before and not in parallel. Two reasons, both concrete: (a) the `TransferReceiverLine` type it declares is the hand-written mobile mirror of `TransferReceiverViewData`'s line shape (§8.6b), so it must be written against a `TransferReceiverPayloadBuilder` whose output is already merged and stable, or the mirror is written against a moving target; (b) the mobile repository has no dependency on the ERP repository — no submodule, no package, no generated-type share — so an S4-mobile commit landing first would be an unverifiable claim about a contract that does not yet exist on `dev`. The packet is **not** a blocker for the ERP lane's closure: §14's combined gate runs on the ERP union diff `S1..S4`, and S4-mobile's handback is a separate deliverable recorded in §16 step 18b.

**T-1 tickets this lane must respect and must not fix** (on `dev` at `docs/superpowers/tickets/2026-09-09-t1-*.md`):

| Ticket | Why it is not this lane's work |
|---|---|
| `2026-09-09-t1-freight-capitalization-no-gl.md` | Freight has no justifying document and no GL counterpart. This lane **shrinks** the capitalised pool and persists the residual (§7.11); it does **not** create the missing expense/clearing leg. S1 must preserve the T-1 zero-journal pins on that path. |
| `2026-09-09-t1-settlement-replay.md` | Settlement is quantity-blind and demand-only, writes no stock and no GL. Do **not** add a compensating stock or GL action; T14 only asserts that both close dispositions leave the settled row alone (OQ-2). |
| `2026-09-09-t1-recalled-in-transit.md` | A recalled lot can still land at the destination today. Its refusal-versus-quarantine ruling, and the write-off + GL arm that follows, are **owner decisions not yet taken**. This lane adds no recall check at receipt. If a reviewer proposes one, it is out of scope and goes back to the ticket. |
| `2026-09-09-t1-idempotency-payload.md` | Transfer **initiate** replays a matching key without comparing the payload. This lane fixes idempotency for **receipt and close** (§6.3) and leaves `initiate` exactly as it is. |
| `2026-09-09-t1-product-detail-transit.md` | `GET /products/{id}/stock-levels` shows purchase-order remainder only, and defaults a quantity to a scale-2 literal. That surface is R5 row 20 — **untouched** by this lane, and its `incoming` semantics are the ticket's problem. |
| `2026-09-09-t1-cancel-in-progress.md` | Replenishment cancellation policy; unrelated to receipts. |
| `2026-09-09-t1-location-denial-message-constant.md` | The location-denial literal should be hoisted to a constant. This lane genericises a **different** literal (`RequireAnyPermission`, §7.6) and leaves `ValidLocationAccess` alone. |
| `2026-09-09-t1-replenishment-dialog-refusal-i18n.md` | The replenishment transfer dialog surfaces raw English server messages. S4 adds i18n for the **transfer** namespace only; the replenishment dialog mapping stays ticketed. |

---

## 16. Dispatch order

1. Run the §1.1 `DISPATCH_SHA` block; on production drift, re-run the §6.5 census and record a pinned addendum before any code.
2. Confirm the **two** untracked files of §1 are preserved and never committed (gate r1 m1: rev 1 said "three" here and listed two).
2b. Read the five current values of §6.7.1 out of `apps/api/tests/feature-lane-manifest.json` at `DISPATCH_SHA` and confirm they are `Inventory 129`, `Replenishment 8`, `Migrations 14`, `Compliance 23`, `Notification 1`, `gated_ceiling 1253`. If any differs, re-derive §6.7.3's chain as `found + own additions` per slice and record the corrected chain in a dispatch addendum before S1 starts.
3. Take one verified non-zero host backup per tenant database (Push 1 carries the backfill).
4. Run `php artisan tenants:run tenant:census-day-one --option='fail-on-drift=1'` and grep for `DAY-ONE CENSUS` / `DRIFT(`; a drifted fleet blocks the lane.
5. Create the S1 worktree and dispatch packet §17.1. Implement S1 red-first.
6. Gate S1 with `inventory-costing-reviewer`, `stock-gl-interaction-reviewer` and `tenancy-authz-reviewer`; all three ACCEPT with zero BLOCKER/MAJOR.
7. Merge S1 into **local** `dev`. Promote to `origin/dev` as a clean fast-forward; the push **is** the staging deploy, so expect the boot migration and a short API outage.
8. After the S1 deploy settles, run the lane census of §12.1's `Censuses` row: per tenant, zero completed transfers without a receipt.
9. Work through `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` — the file Push 1 created (§7.16), reachable from the older `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` through the one-line successor pointer Push 1 also added there (gate r2 M6; the orchestrator writes nothing at this step, because both edits are S1 deliverables) — sections 1 to 5: `php artisan tenants:seed --class=RolesAndPermissionsSeeder`, then `php artisan permission:cache-reset`, verifying the two new permission rows exist **per tenant** from the printed per-tenant output rather than from an exit code, and confirming the committed `permissionsMap.generated.ts` and `generated.d.ts` carry the new keys and the seven-member `TransferStatus`.
10. Create the S2 worktree and dispatch packet §17.2. Implement S2 red-first.
11. Gate S2 with `tenancy-authz-reviewer`, `inventory-costing-reviewer` and `fiscal-pos-reviewer`.
12. Merge S2 into local `dev`; promote fast-forward. Verify `blind_receiving = false` and `visibility_version = 1` on every existing company.
13. Create the S3 worktree and dispatch packet §17.3. Implement S3 red-first, starting from the captured first violation of `TransferBlindLeakOracleTest`.
14. Gate S3 with `tenancy-authz-reviewer`, `inventory-costing-reviewer` and `stock-gl-interaction-reviewer`.
14a. **Confirm S3 closed the T19 row** (§2.2 deviation 5): S3's handback must show `ReceivingControlsVisibilityVersionTest` and `ReceivingControlsConcurrencyPostgresTest` re-run green **unmodified** beside the oracle's carriage assertions. A slice that skipped the re-run is not merged.
15. Merge S3 into local `dev`; promote fast-forward.
16. Create the S4 worktree and dispatch packet §17.4. Implement S4 red-first.
17. Gate S4 with `frontend-conventions-reviewer`, `tenancy-authz-reviewer` and `fiscal-pos-reviewer`.
18. Merge S4 into local `dev`; promote fast-forward. **Then deploy the staging web application explicitly** and verify both the served asset hash and a grep of the served bundle for `t2t3-transfer-receipt-blind-receiving-v1`.
18a. **Confirm S4 closed the T9 and T3 rows** (§2.2 deviation 5): S4's handback must show the whole `TransferBlindLeakOracleTest` class green on **both** DB lanes with step 6o present, and `StockTransferReceiveDamageTest` re-run green unmodified.
18b. **Dispatch packet §17.5 (S4-mobile)** into the sibling repository `/Users/houssamr/Projects/syneriva/erp-mobile`, pinned at `51e3445`. Gate it with `frontend-conventions-reviewer`. It is a separate commit in a separate repository with a separate handback (`docs/handoff/HANDBACK-T2T3-mobile-contract-2026-09-09.md`, written into the **ERP** repo's `docs/handoff/` so the lane's handbacks stay together); it is **not** part of any ERP push, any staging deploy or any mobile build, and it does **not** block step 19.
19. Run the combined reviewer gate of §14 over the ERP union diff `S1..S4`.
20. Run the manifest §4 gate checklist and a second `tenant:census-day-one`.
21. Enable `blind_receiving` on **one** pilot company through Settings → Fraud & controls, and confirm on that company: a complete-only receiver's `GET /stock-transfers/{id}` returns `blind: true`, `POST /complete` returns 422 `BLIND_REQUIRES_COUNTED_RECEIPT`, and `GET /stock-levels` still returns the destination position.
22. Record the four merge SHAs, the reviewer verdicts, the census outputs and the pilot observation in the handbacks.
23. Dispatch T-3b, M-1 and T-2b separately; they are not part of this lane.

---

## 17. Codex Desktop dispatch packets

Each packet is pasted as a **new** Codex Desktop thread. The implementer stops at `status: review`; the orchestrator session runs every reviewer, every merge and every promotion. **There are FIVE packets in rev 2**: §17.1–§17.4 in the ERP repository, and §17.5 in the sibling mobile repository (gate r1 M8).

### 17.1 S1 — paste as a NEW thread named `T-2 receipt spine`

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Create your worktree first: `git worktree add .worktrees/t2-receipt-spine -b lane/t2-receipt-spine dev` (base = local `dev`). Work ONLY inside that worktree. PostgreSQL test database for this lane: **`autoerp_test_u`** — set both `DB_DATABASE` and `DB_CENTRAL_DATABASE` to it for every PG leg. Never run the full PHPUnit suite; run by file only. Plan (authority — revs 1–7 are SUPERSEDED, read only rev 8): `docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-8.md` (revs 1–7 must NOT be read). Spec (authority): `docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md`, gate `docs/superpowers/reviews/2026-09-09-t2-t3-spec-codex-gate-r11.md` (ACCEPT). Read in this order: **§0 Errata** (the spec's prose cross-references are stale; the tables are correct), §1 (run the `DISPATCH_SHA` block and the repin procedure on production drift — documentation-only ancestry does not block), §2 (outcome, hard scope, deviations), §3 (owner rulings — none is reopenable), §6 (shared contracts and the named-symbol census — re-run it), §6.6 (the seven T-1 seams), **§7 in full**, §13 (verification commands), §15 (boundaries and the T-1 tickets you must NOT fix). YOUR SCOPE = §7 only. Do not create `blind_receiving`, `visibility_version`, `TransferReceiverPayloadBuilder`, the receiver-view route or any masking; S1 implements exactly the setting-off behaviour. Rules: `CLAUDE.md` rules 2 (TDD — every test red first; capture the first failing assertion verbatim in the handback), 3 (strict typing, no `mixed`), 4 (no scope creep), 6 (module boundaries), 7 (`php artisan typescript:transform`; never hand-edit `packages/shared/types/generated.d.ts`), 8 (existing events are immutable — new events are new classes), 9 (enums for every status/type column), 12 (route middleware), 13 (constructor injection with `private readonly`; never `app()`), 17 (valid UUIDs in fixtures; check the real schema before writing seeders), 19 (decimal strings at column scale — quantity 4, money 3, cost 6; no float, ever); `docs/conventions/09-SECOND-OF-EVERYTHING.md` (§7.13 names the exact S-matrix cells); `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md` (§7.3 glossary rows go in `docs/glossary.md` in **one** edit, all nine). Deliverables, as commit groups in this order so the orchestrator can review them incrementally: **(0) the feature-lane ceiling raise of §6.7 (`Inventory 129 -> 141`, `Replenishment 8 -> 9`, `Migrations 14 -> 15`, `gated_ceiling 1253 -> 1267`) with its four-statement raise notes, plus the fourteen class names appended to the `backend-test-pgsql` `--filter` alternation at `.github/workflows/ci.yml:1142` — in the SAME commit as your first test class, verified with `php tools/feature-lane-manifest-check.php` exiting 0; (0b) the `StockTransferMovementSupport` extraction of §6.7b, with `StockTransferCompleteConcurrencyPostgresTest` and `StockTransferEdgeCasesTest` re-run green BEFORE any new behaviour; (0c) the reader baseline — run the two `T2T3_CAPTURE_BASELINE=1` commands of §13 on the UNTOUCHED tree and commit `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` before step 0b, because a baseline captured after any production edit proves nothing (gate r2 M3);** (1) the four migrations plus `TransferReceiptSchemaRerunPostgresTest`; (2) enums, models and **all fourteen DTOs — including `StockTransferData`, `StockTransferLineData` and `StockTransferLineBatchAllocationData` reproduced verbatim from §7.12b, and the `TransferReconciliationReceiptData::fromModel()` mapper** — then `php artisan typescript:transform`; (3) the three readers, `REMAINDER_SQL`/`CARRYING_STATUSES` and the T12 ratchet with its liveness fixture; (4) the backfill migration and T11 including the PG-only forced-failure case **and the reader-preservation method against the baseline you captured in step (0c)**; (5) `ReceiptPayloadCanonicalizer`, the two FormRequests, `StockTransferReceiptService`, movements, the GL buffer and freight, with T1–T8, T13 and T14; (6) the stored events and T15; (7) permissions, routes, the controller, `TransferPayloadBuilder`, `TransferReconciliationService`, the deletion of `formatTransfer` and `completeLocked`, plus `TransferReceiptAuthorityGateTest` and `php artisan permissions:export-frontend-map`; (8) T16, the S-matrix test, `docs/glossary.md`, **`docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` reproduced verbatim from §7.16, and the ONE-LINE successor pointer added under §0 of `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` — that pointer is your deliverable, not the orchestrator's, and nothing else in that older file may change** (gate r2 M6). Handback `docs/handoff/HANDBACK-T2-receipt-spine-2026-09-09.md`: `DISPATCH_SHA`, per-task red-first evidence (test → first failing assertion → green command), **the captured `php tools/feature-lane-manifest-check.php` failure BEFORE the raise and its exit-0 after, the manifest numbers you wrote, the `grep -rn` census output that proved nothing else called the five moved private helpers (§6.7b), **the output of `grep -rn 'receiveAllRemaining' apps/api/app apps/api/tests`, which must have exactly two hits — the declaration on `StockTransferReceiptService` and the call inside `StockTransferService::complete()` — and must show the `string $transferId` signature in both (rev 4, gate r3 B1)**, the promotion-checklist path and the diff of the one-line pointer you added to the 2026-08-26 checklist, the captured output of the two baseline-capture commands together with the committed fixture's byte size, and the RED/CONTROL classification column of §7.14b filled in for every method**, the §11.1 ledger with the commit SHA carrying each file, a "REALIGNMENT-LOG lines owed" block, anything owed, and a resume recipe. Do NOT merge into `dev`, do NOT push to origin. Stop with `status: review`.

### 17.2 S2 — paste as a NEW thread named `T-3 blind core`

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Create your worktree first: `git worktree add .worktrees/t3-blind-core -b lane/t3-blind-core dev` (base = local `dev`, which must already contain the S1 merge). Work ONLY inside that worktree. PostgreSQL test database: **`autoerp_test_v`** — both `DB_DATABASE` and `DB_CENTRAL_DATABASE`. Never run the full suite. Plan and spec as in §17.1. Read: **§0 Errata**, §1, §2, §3, §6.1 (the two Shared contracts, verbatim), §6.4, §6.5, **§8 in full**, §13, §15. YOUR SCOPE = §8 only. Do not touch any masking of the stock matrix, the POS feeds, the replenishment feeds or the movement feeds — that is S3. Do not touch any web or POS file — that is S4. Rules: `CLAUDE.md` rules 2, 3, 4, 6 (Inventory, POS and Replenishment must reach the setting **only** through `App\Shared\Contracts\Compliance\ReceivingControlsReader`; the direct Compliance import in `apps/api/app/Modules/POS/Application/Services/FraudSettingsResolver.php` is prior debt, not a precedent), 7, 8, 9, 12, 13, 19; `docs/conventions/09-SECOND-OF-EVERYTHING.md` (§8.10 names the cells); `docs/conventions/11-ONE-SURFACE-PER-CONCEPT.md` (one builder per concept; the NEVER-INCLUDE comment lives inside the receiver builder's array literal and the runtime key scan is the enforcement). Deliverables, in this commit order: **(0) the §6.7 ceiling raise (`Inventory 141 -> 143`, `Compliance 23 -> 26`, `gated_ceiling 1267 -> 1272`) and the five allowlist entries, in the SAME commit as your first test class, `php tools/feature-lane-manifest-check.php` exiting 0;** (1) migration 1d, the six `CompanyFraudSettings` surfaces, the complete DTO of §8.4b and `php artisan typescript:transform`; (2) the two Shared contracts, `CompanyReceivingControlsReader`, `ExpectedQuantityVisibility` and both provider bindings; (3) `TransferReceiverPayloadBuilder`, the FIVE receiver DTOs of §8.6b, the receiver-view route and T9b including its liveness case; (4) the per-transfer builder choice on list and show, and the `complete` blind gate with its exact `details: []` wire form — **rev 4, gate r3 B1: your ONLY edit to `StockTransferController::complete` is that one `canSeeExpected` `if` block plus the `ExpectedQuantityVisibility` constructor argument it needs. Do NOT call `StockTransferReceiptService::receiveAllRemaining` from the controller; the action keeps calling the published forwarder `$this->service->complete($existing->id, $user->id)` and keeps returning 200. The action's final body is reproduced in full at §6.7b block (c); run `grep -rn 'receiveAllRemaining' apps/api/app apps/api/tests` before you stop and record in the handback that its only two hits are the declaration and that forwarder**; (5) the five-step transactional fraud-settings write with the atomic `RETURNING` bump and `ReceivingControlsChangedV1` — **step 3 keeps `Arr::except(..., self::REFUND_EXPOSURE_KEYS)`; using `CASH_CONTROL_KEYS` there is the gate-r1 M1 defect and its test will go red** — plus **`T19-S2`** (the row, the DTO, the TWO transfer payloads **and — rev 3, gate r2 M2 — the reconciliation body**; the five aggregate carriage surfaces are S3's `T19-S3`), T19b and the settings S-matrix; (5b) **the reconciliation version delta of §8.7b: append `public int $visibility_version` to `TransferReconciliationData` in last position with every other line byte-identical to S1's file, constructor-inject `TransferIncomingVisibility` into `TransferReconciliationService`, source the value from `visibilityVersion($transfer->company_id)`, and add the two named `T19-S2` methods (the API assertion and the generated-type assertion) — and append `blind` and `visibility_version` to `StockTransferData` in last position, §8.6**; (6) **T18's eleven methods of §8.11b, covering all eight actors × six routes**. Handback `docs/handoff/HANDBACK-T3-blind-core-2026-09-09.md`, same shape as §17.1, including the "REALIGNMENT-LOG lines owed" block. Do NOT merge, do NOT push to origin. Stop with `status: review`.

### 17.3 S3 — paste as a NEW thread named `T-3 surface closure and leak oracle`

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Create your worktree first: `git worktree add .worktrees/t3-surface-closure -b lane/t3-surface-closure dev` (base = local `dev`, which must already contain the S1 and S2 merges). Work ONLY inside that worktree. PostgreSQL test database: **`autoerp_test_x`** — both `DB_DATABASE` and `DB_CENTRAL_DATABASE`. Never run the full suite. Plan and spec as in §17.1. Read: **§0 Errata** (E1, E3 and E5 matter directly to your work — the R5 range is rows 19–**25**, the mixed surfaces are rows **7–9**, and there are **twelve** R5 response-shape rows), §1, §2, §3 (the R5 ruling is the whole shape of this slice), §6.1, §6.6 (seam 5), **§9 in full**, §13, §15. YOUR SCOPE = §9 only. **Rows 19–25 must contribute ZERO files to your diff** — if you find yourself editing `StockLevelData`, `ProductController`, `StockRebalanceQueryService`, `CountingReconciliationPayloadBuilder`, `BatchResource`, `BatchController`, `BatchRepository`, `FEFOInventoryService` or any POS on-hand type, stop: that is the option the owner rejected. Do not add a permission, a route or a write path. Rules: `CLAUDE.md` rules 2, 3, 4, 6, 12, 13, 19, 20. Start by writing `TransferBlindLeakOracleTest` and capturing its **first violation** verbatim; that output is the red-first evidence for the whole slice. Deliverables, in this commit order: **(0) the §6.7 ceiling raise (`Inventory 143 -> 146`, `gated_ceiling 1272 -> 1275`) and the three allowlist entries, in the SAME commit as your first test class, `php tools/feature-lane-manifest-check.php` exiting 0; (0c) the entry/exit baseline — run the two `T2T3_CAPTURE_BASELINE=1` commands of §13 on the UNTOUCHED tree and commit `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` BEFORE you write the scope fix; a baseline captured afterwards proves nothing (gate r2 M4);** (1) the entry/exit location-scope fix and `EntryExitNoteLocationScopeTest` (T17), **whose preservation method asserts the unrestricted non-blind body byte-equal to that baseline and whose FIRST assertion is the restricted-actor scoping assertion**; (2) the Shared reader flag, the three nullable DTO members, the matrix and both POS controllers with `meta.incoming_transfer_masked` and `meta.visibility_version`; (3) the replenishment resource flag, `collectionFor`, the three call sites, and the web-`meta` versus POS-top-level envelope difference; (4) the two movement controllers: the carrying-set queries, the three nulled keys, `meta.transfer_movement_quantities_masked`, and **no** `visibility_version` on these two feeds; (5) `TransferMovementMaskingTest` (T20) with its liveness halves; (6) `apps/api/tests/Support/AssertsBlindPayloads.php` and the **`T9-S3`** procedure on both DB lanes — steps 1–6n, 6p–6u, 7 and 8, including the R5 presence controls and the 6s-d counter-facing absence control, and **DELIBERATELY WITHOUT step 6o**, which is S4's `T9-S4`; classify each method per §9.9b and run its falsification mutation; (7) **re-run S2's `ReceivingControlsVisibilityVersionTest` and `ReceivingControlsConcurrencyPostgresTest` UNMODIFIED — that, with your carriage assertions, is `T19-S3` and it CLOSES the T19 row**; (8) the §9.7 response-shape checklist recorded in the handback. Handback `docs/handoff/HANDBACK-T3-surface-closure-2026-09-09.md`, same shape as §17.1, plus the §9.7 checklist and the "REALIGNMENT-LOG lines owed" block. Do NOT merge, do NOT push to origin. Stop with `status: review`.

### 17.4 S4 — paste as a NEW thread named `T-2/T-3 web, POS and notifications`

Repo: `/Users/houssamr/Projects/syneriva/apps/erp`. Create your worktree first: `git worktree add .worktrees/t2t3-web-notifications -b lane/t2t3-web-notifications dev` (base = local `dev`, which must already contain the S1, S2 and S3 merges). Work ONLY inside that worktree. PostgreSQL test database: **`autoerp_test_y`** — both `DB_DATABASE` and `DB_CENTRAL_DATABASE`. Never run the full PHPUnit or Vitest suite; run by file only, and after any interrupted web run check `ps aux | grep 'node (vitest'` and kill surviving workers. Plan and spec as in §17.1. Read: **§0 Errata** (E2 — the POS device types mirror surface rows **8–9**, not the product/counting/rebalance/batch rows), §1, §2, §3 (OD-1 and OD-4), **§10 in full**, §13, §15. YOUR SCOPE = §10 only. Do not touch any backend masking, builder or service other than the two notification listeners and their provider wiring. **TOUCH NOTHING under `/Users/houssamr/Projects/syneriva/erp-mobile`.** That directory is a DIFFERENT git repository; your worktree cannot commit it, and rev 2 moves the mobile contract into its own packet §17.5, dispatched after your slice merges. §10.9 is the ERP-side statement of the contract and produces no file here. Before you stop, run `git diff --name-only dev...HEAD | grep -c 'erp-mobile'` and record the result (it must find nothing) in your handback. Rules: `CLAUDE.md` rules 2, 3 (no `any`; use `unknown` + type guards), 4, 7 (types flow from the backend — run `php artisan typescript:transform`, never hand-edit `packages/shared/types/generated.d.ts`), 11 (no hardcoded frontend strings — every user-facing string is a `t()` key in en and fr), 13, 14 (`apiGet`/`apiPost` already unwrap `response.data.data`; `receive` and `close` deliberately use the RAW `api.post` envelope **because** they consume `meta.replayed`), 18 (design tokens from `@/lib/designTokens` for every colour class you touch), 19 (never `parseFloat`/`Number(...)` on money or quantity; `<QuantityInput>` and `formatQuantity` only), 20 (queued notification jobs run with no `CompanyContext`), plus the TanStack rule that every tenant-data query key uses `tenantScopedKey`/`locationScopedKey`. Deliverables, in this commit order: **(0) the §6.7 ceiling raise (`Notification 1 -> 3`, `gated_ceiling 1275 -> 1277`) and the two allowlist entries, in the SAME commit as your first test class, `php tools/feature-lane-manifest-check.php` exiting 0;** (1) the two notifications, `IdempotentDatabaseChannel`, the two listeners, the provider wiring, `TransferNotificationTest` (T10 backend half + **`T3-S4`**, with the POSITIVE discrepancy case asserted FIRST) and **`TransferNotificationRaceTest`, the PG-only two-worker race whose harness mirrors `StockTransferCompleteConcurrencyPostgresTest` (§10.10). Run it in the three STAGES §10.10 names: stage 1 BEFORE you write either notification class — the file declares its own `RaceProbeNotification` on Laravel's stock `DatabaseChannel`, and the child's `SQLSTATE[23505] … "notifications_pkey"` stderr is your captured red; stage 2 after `IdempotentDatabaseChannel`; stage 3 the production-notification method. Use the self-contained `phpunit-pgsql.xml` command of §13, never `docs/sessions/t1/pg-test.sh` (gitignored, absent from your worktree)** — no new named queue; (2) `php artisan typescript:transform`, the web type split, the discriminated union, `isReceiverTransfer` and the type-level test; (3) route guards, `ReceiveTransferPage`, the close dialog and the reconciliation tab, with their Vitest files; (4) `visibilityVersionGate.ts` and its test, using REAL leaf-shaped keys; (5) the movement-feed nullability across both web consumer families and their two masked-render tests; (6) the POS device changes and their three Vitest files; (7) **`T9-S4`: add the step-6o method to `apps/api/tests/Feature/Inventory/TransferBlindLeakOracleTest.php` and re-run the WHOLE class on both DB lanes, then re-run `StockTransferReceiveDamageTest` (`T3-S4`) and `ReceivingControlsVisibilityVersionTest` — the three closing re-runs of §13;** (8) i18n in en and fr for every touched namespace; (9) the single consolidated `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` entry covering the whole lane, using the S1/S2/S3 handbacks' "lines owed" blocks plus §11.4. Handback `docs/handoff/HANDBACK-T2T3-web-notifications-2026-09-09.md`, same shape as §17.1, plus the three closing re-run outputs, the two-worker race's captured child stderr from the **stage-1** red run together with the stage-2 and stage-3 green outputs, the `RED`/`CONTROL`/`GREEN-GATE` classification column of §10.10b filled in for every method, the twelve literal locale paths you touched, and the `erp-mobile` absence grep. Do NOT merge, do NOT push to origin, and do NOT deploy the staging web application — the orchestrator does that after the reviewer gate. Stop with `status: review`.

### 17.5 S4-mobile — paste as a NEW thread named `T-2/T-3 mobile contract` (closes gate r1 MAJOR M8)

**Dispatched only AFTER S4 has merged into local `dev`** (§15 sequencing note, §16 step 18b).

Repo: **`/Users/houssamr/Projects/syneriva/erp-mobile`** — a DIFFERENT git repository from the ERP monorepo. It is not a submodule of `/Users/houssamr/Projects/syneriva/apps/erp` and shares no commit with it. Do **not** open the ERP worktree; do **not** attempt to stage an `erp-mobile` path from an ERP branch.

**Pin.** Before anything:

```bash
cd /Users/houssamr/Projects/syneriva/erp-mobile
git rev-parse HEAD
git status --short
```

`git rev-parse HEAD` must print `51e3445c1aa794f2a76d58c4771ecbbdca2f3f84` (short `51e3445`) — the pin at which every `path:line` below was opened — or a descendant of it. `git status --short` must be empty. If HEAD is a descendant, re-open both cited files and confirm the line numbers before editing; if either has moved, record the corrected `path:line` in the handback and use the corrected values.

**Worktree and branch:**

```bash
git worktree add .worktrees/t2t3-mobile-contract -b lane/t2t3-mobile-contract
```

Work ONLY inside that worktree.

**Authority.** ERP plan §10.9 (the three commitments) and accepted spec §9, both in the ERP repository at `/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/plans/2026-09-09-T2T3-transfer-receipt-blind-receiving-plan-rev-8.md` and `/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/specs/2026-09-09-transfer-destination-receipt-and-blind-receiving-design-rev-11.md`. Read them; do not edit them.

**Exact file — ONE file, no other:** `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts`.

**Edit 1 — mark the existing supervisor shapes as such.** At `:58-73` the file declares `ReceiptStatusLine` (with `quantity_ordered`, `quantity_received`, `quantity_remaining`) and `ReceiptStatus` (with `total_ordered`, `total_received`). These are the **purchase-order** receipt-status shapes fed by `GET /purchase-orders/{id}/receipt-status`; they are legitimate for a PO supervisor and are **not** touched by T-3 (PO blind receiving is T-3b). Replace the existing one-line docblock above `ReceiptStatusLine` — currently `/** Authoritative per-line progress from \`GET /{id}/receipt-status\`. */` — with:

```ts
/**
 * Authoritative per-line progress from `GET /{id}/receipt-status`.
 *
 * SUPERVISOR SHAPE. This is the PURCHASE-ORDER receipt-status contract and it
 * legitimately carries expected quantities. It is NOT a blind-receiving surface
 * and must never be rendered on a receiving screen for an actor who cannot see
 * expected quantities. PO blind receiving is lane T-3b and will add its own
 * receiver projection; until then, no screen may derive a receive form from this
 * type. The TRANSFER equivalent is TransferReceiverLine below, which carries no
 * quantity at all.
 */
```

**Edit 2 — add the transfer receiver line type with its NEVER-INCLUDE guard.** Append to the same file, in the exact shape counting already ships at `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/counting/api/countingApi.ts:193-197` (whose live guard reads `// NEVER INCLUDE: theoretical_qty, count_1_qty, count_2_qty, count_3_qty` as the last line inside its interface body):

```ts
/**
 * A line of `GET /stock-transfers/{id}/receiver-view`, the server-built receiver
 * projection (ERP TransferReceiverLineData).
 *
 * THREE CONTRACT COMMITMENTS this type exists to make checkable (ERP plan §10.9):
 *
 * 1. SERVER-DRIVEN. The receive screen renders exclusively what
 *    `GET /stock-transfers/{id}/receiver-view` returns, built by
 *    TransferReceiverPayloadBuilder for the requesting actor at request time.
 *    The omission is the SERVER'S, never a client-side hide of a field the
 *    response carried.
 * 2. NEVER FETCH THE FULL SHAPE. The receive flow makes no call to
 *    `GET /stock-transfers/{id}` or `GET /stock-transfers`, and nothing derived
 *    from a full transfer payload is persisted in any cache the receive screen
 *    reads - so there is no local copy of a sent/expected/remaining quantity for
 *    a blind actor to reach, online or offline.
 * 3. NO "RECEIVE ALL". A quantity-less completion is refused server-side with
 *    422 BLIND_REQUIRES_COUNTED_RECEIPT (owner ruling OD-1), and `close` is not
 *    a mobile action at all.
 *
 * The receive SCREEN is lane M-1 and is not in this repository yet. This type is
 * the contract it must be built against.
 */
export interface TransferReceiverLine {
  id: string;
  product: { id: string; name: string; sku: string; barcode: string | null };
  variant: { id: string; sku: string; name_suffix: string | null } | null;
  // Unit precision for <QuantityInput>; a UNIT property, not a quantity.
  unit_decimal_places: number;
  requires_batch_tracking: boolean;
  // Lot IDENTITY only - there is deliberately no quantity beside batch_number.
  lots: Array<{ batch_id: string; batch_number: string; expiry_date: string | null }>;
  // NEVER INCLUDE: quantity, quantity_ordered, quantity_remaining, quantity_sent,
  // quantity_sent_snapshot, quantity_received, quantity_damaged, notes,
  // unit_cost_snapshot, allocated_transfer_cost
}
```

**Nothing else.** No screen, no route, no navigator entry, no API client function, no query key, no test file, no dependency. `TransferReceiverLine` is exported and, at this commit, unused — that is intended: it is a contract commitment for M-1, and an unused exported type is not a lint error in this repository (confirm with the verification below; if the repo's lint disagrees, report it in the handback rather than adding a consumer).

**Verification — the whole gate for this packet:**

```bash
cd /Users/houssamr/Projects/syneriva/erp-mobile/.worktrees/t2t3-mobile-contract
npx tsc --noEmit
git diff --stat        # MUST show exactly one file: src/features/receiving/types.ts
```

`npx tsc --noEmit` must be green. There is no PHPUnit, no PostgreSQL database, no `preflight.sh` and no Expo/EAS build in this packet — it changes no runtime behaviour.

**Ledger for this packet — one file, one commit:**

| File | Change |
|---|---|
| `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts` | edit 1 (SUPERVISOR SHAPE docblock at `:58`) + edit 2 (appended `TransferReceiverLine`) |

**Reviewer:** `frontend-conventions-reviewer`, which must cite that the file contains no expected-quantity member on `TransferReceiverLine`, that the NEVER-INCLUDE comment is the last line of the interface body in the counting shape, that `ReceiptStatusLine` and `ReceiptStatus` are unchanged in structure and only re-documented, and that the diff is one file.

**Handback:** `docs/handoff/HANDBACK-T2T3-mobile-contract-2026-09-09.md`, written into the **ERP** repository's `docs/handoff/` so this lane's five handbacks stay together. It records: the mobile repository pin printed by `git rev-parse HEAD` at start, the branch name, the commit SHA, the `npx tsc --noEmit` output, the `git diff --stat`, and — if HEAD was a descendant of `51e3445` — the re-derived line numbers for both cited anchors.

Do NOT merge into the mobile repository's default branch and do NOT push to its origin; the orchestrator does that after the reviewer gate. Stop with `status: review`.

---

## 18. Final verification checklist

- [ ] Rev-6/rev-7 planning SHA `f9f6f4480f772d7644b28bc4379cd21a4aef64e0` and its branch state are recorded; `git diff --name-only f9f6f4480..HEAD | grep -v '^docs/'` was empty at planning time; each slice recorded its own `DISPATCH_SHA` and, on production drift other than the two lane-infrastructure files of §1.1 rule 4, a pinned addendum.
- [ ] The five §0 errata are reproduced in every dispatch packet and no implementer followed a stale spec cross-reference.
- [ ] The **two** untracked files of §1 are preserved and uncommitted.
- [ ] Every owner ruling in §3 is applied and none was reopened.
- [ ] The §4 benchmark table is the spec's, unaltered, with its fifteen decision rows.
- [ ] `docs/glossary.md` carries one new `## Stock operations` section with all nine rows, added in exactly one push.
- [ ] Every §10 spec test row appears in exactly one slice, or — for T3, T9 and T19 — in exactly one pair of NAMED partial contracts whose closing slice re-ran the whole row (§2.2 deviation 5, §19).
- [ ] Every file in the ledger appears exactly once: the §11.1–§11.4 lists contain **only** single-push files, the **thirteen** multi-push files appear **only** in the §11.6 register, and §11.7's two derivations agree — `159 + 30 = 189` push rows, `159 + 13 = 172` distinct ERP files, `189 − 172 = 17 = 30 − 13`.
- [ ] `packages/shared/types/generated.d.ts` and `apps/web/src/hooks/permissionsMap.generated.ts` are regenerated and byte-identical to the committed artifacts in every push that changes their inputs.
- [ ] All four migrations of Push 1 and the one of Push 2 are additive and self-guarding; none alters an existing column type; `stock_transfers.status` is untouched.
- [ ] The backfill is idempotent by predicate; a rerun adds zero rows; the PG-only interruption case is asserted and no SQLite convergence claim is made.
- [ ] `REMAINDER_SQL` and `CARRYING_STATUSES` are applied at exactly four query sites in exactly three files, with rounding unchanged at each; the T12 ratchet rejects its liveness fixture.
- [ ] Company-owned quantity is unchanged across a partial receipt, and −1 exactly once for a damaged unit.
- [ ] `transfer_cost = Σ allocated_transfer_cost + freight_uncapitalized` at 4 dp on every terminal transfer, with the worked example persisted exactly (`49.9999`, `40.0001`, `30.0000`).
- [ ] Freight is capitalised exactly once, after the terminal status write, never on `partially_received`.
- [ ] Every damage and write-off posting goes through `InventoryGlPostingBuffer` with the receipt service as the outermost transaction; no leak alarm; no direct `postFor*`.
- [ ] `return_to_source` posts zero journal rows and reuses the extracted `restockAtSource`.
- [ ] `requirePerpetual` preflights **both** the lot-less and the batch-tracked destructive paths.
- [ ] Movement identity is line-level for lot-less lines and lot-level for lot lines, with the parent columns NULL and the CHECK enforcing it.
- [ ] Idempotency keys are company-wide; `sys:` is refused at the request layer; a hash mismatch is a typed 422; a unique violation with no matching receipt is re-thrown, never mapped to 422.
- [ ] Header and per-line events share one stream anchored on the receipt id with versions `1..N+1`; a rebuild reproduces every row and leaves `legacy_completion` receipts untouched; existing transfer events are unmodified.
- [ ] The pattern query is line-grained and counts **both** close dispositions; T16 proves the write-off-only predicate would give the wrong number.
- [ ] `inventory.transfers.reconcile` and `inventory.transfers.close` are seeded to `manager` and `admin` only, grantable independently; close requires both; `receive` is not under the any-of gate; `close` is not in the read any-of set.
- [ ] `blind_receiving` defaults `false` and `visibility_version` `1` on every existing company; the version is never client-writable; `ensureForCompany` still emits no event.
- [ ] Every settings write advances the version by exactly one under the row lock, and exactly one `ReceivingControlsChangedV1` is stored per actual flip.
- [ ] The receiver builder's output contains no NEVER-INCLUDE key at any depth and no value equal to a sent or remaining quantity; the liveness case fails when a forbidden key is added.
- [ ] A blind actor's `POST /complete` returns 422 `BLIND_REQUIRES_COUNTED_RECEIPT` with `error.details == []`, before the idempotency lookup.
- [ ] Surfaces 7–11 and 17–18 mask exactly the named members, emit `null` and never `"0.0000"`, and carry their `meta` flags on every page.
- [ ] The two movement feeds null exactly three keys on transfer-linked carrying rows including the actor's own, keep identity and provenance, keep terminal and non-transfer rows intact, and carry **no** `visibility_version`.
- [ ] `GET /entry-exit-notes` is location-scoped by the same resolver as `GET /stock-movements`.
- [ ] `TransferBlindLeakOracleTest` passes on **both** DB lanes: liveness before any blind assertion, absence on every transfer-derived surface, presence of `R` on rows 19–25, the reconcile positive control including 6m/6n, and R5 invariance across both flips.
- [ ] Rows 19–25 contribute zero files to any ledger push.
- [ ] Both notifications are idempotent under replay and under two racing workers, write non-null timestamps, resolve recipients in the request, run with no `CompanyContext`, add no named queue, and carry no quantity or note.
- [ ] The six web entity shadows are deleted and replaced by generated DTOs; `isReceiverTransfer` is the only discriminant reader; an un-narrowed read fails `pnpm typecheck`.
- [ ] "Receive all" renders only when the server-authoritative `blind` is false; Close renders only with both permissions.
- [ ] `dropVisibilityCaches` removes the five leaf families with bare literal prefixes and leaves the notifications control intact; the gate stores the new version before removing, so it cannot loop.
- [ ] Every user-facing string added in `apps/web` and `apps/pos` is a `t()` key present in en and fr.
- [ ] `docs/03-ERP-INTEGRATION/REALIGNMENT-LOG.md` carries one consolidated lane entry naming every published-contract change of §11.4.
- [ ] Every slice gate and the combined gate returned `ACCEPT`, `BLOCKER=0`, `MAJOR=0`.
- [ ] `tenant:census-day-one` is clean before Push 1 and after Push 4, and the lane census returns zero completed transfers without a receipt.
- [ ] The staging web application was deployed explicitly after Push 4 and both the asset hash and the fingerprint `t2t3-transfer-receipt-blind-receiving-v1` were verified.
- [ ] No T-1 ticket in §15 was silently fixed inside this lane.
- [ ] **(B1)** `apps/api/tests/feature-lane-manifest.json` on the union tip reads `Inventory 146`, `Replenishment 9`, `Migrations 15`, `Compliance 26`, `Notification 3`, `gated_ceiling 1277`; each slice's raise note carries all four §6.7.4 statements and claims no observed CI run; all 24 new class names are in the `.github/workflows/ci.yml:1142` alternation; `php tools/feature-lane-manifest-check.php` exits 0 on every slice tip.
- [ ] **(M1)** `FraudSettingsController::reset` still passes `self::REFUND_EXPOSURE_KEYS` to `Arr::except`, and the three refund-exposure columns are asserted unchanged across a reset while the seven cash-control columns are asserted reset.
- [ ] **(M2)** No receipt-family DTO declares `quantity_written_off` or `quantity_returned`; the receive echo is asserted with `array_key_exists(...) === false`, not with a null check; the close family carries all four counters non-null.
- [ ] **(M3)** `StockTransferService` declares none of the five moved helpers; `StockTransferMovementSupport` declares each exactly once; both writers reach them only through it; `StockTransferCompleteConcurrencyPostgresTest` and `StockTransferEdgeCasesTest` were re-run green after the extraction and before any new behaviour.
- [ ] **(M4)** The complete `TransferStatus`, `TransferReconciliationData` (+3 nested), `TransferReceiverViewData` (+4 nested), `CompanyFraudSettingsData` and the M2 line DTOs are in the plan without ellipsis, and the generated TypeScript matches them.
- [ ] **(M5)** T19 closed by S3, T9 and T3 closed by S4, each with the closing slice's whole-row re-run recorded in its handback; step 6o is absent from S3's oracle and present in S4's.
- [ ] **(M6)** Every red-first row's stated first failing assertion was actually observed; every row classified as a CONTROL in §9.9b was observed PASSING and was proven red under its named mutation; the two-worker notification race ran two OS processes and its child's pre-fix stderr is captured.
- [ ] **(M7)** `docs/handoff/PROMOTION-CHECKLIST-2026-09-09-t2t3.md` exists on the union tip with all four spec §11.4 items, and the orchestrator worked through it at §16 step 9.
- [ ] **(M8)** The ERP union diff `S1..S4` contains no path under `/Users/houssamr/Projects/syneriva/erp-mobile`; S4-mobile was dispatched after S4 merged, against pin `51e3445`, with its own branch, commit, `npx tsc --noEmit` green and its own handback.
- [ ] **(m1)** Every mention of the untracked-file census says two, and lists the same two paths.

Gate-r3 items, each a separate line so a reviewer can tick them independently:

- [ ] **(r3 B1)** There is exactly **one** final signature for the quantity-less complete delegate, `receiveAllRemaining(string $transferId, string $userId, string $idempotencyKey): TransferReceiptResult`, and the reproduced `StockTransferReceiptService` class declaration, the `StockTransferService::complete()` forwarder, §7.5 rule 9, §6.7b's call-graph table and §6.7b's ownership table all state it identically. There is exactly **one** controller call graph: `StockTransferController::complete()` → permission → `canAccessLocationOrFail(destination)` → `canSeeExpected` else 422 → `$this->service->complete($existing->id, $user->id)`; `grep -rn 'receiveAllRemaining' apps/api/app apps/api/tests` on the union tip returns only the declaration and the forwarder, and that grep output is in the S1 and S2 handbacks. `/complete` still returns **200**.
- [ ] **(r3 M1)** §10.2's "Modify (production)" block contains **40 bullets = 40 literal repo-relative paths** with no `...`, no `…` and no alias; the ten `apps/web/src/locales/{en,fr}/*.json` files and the two `apps/pos/src/locales/{en,fr}/pos.json` files are each their own bullet and each exists on the tree; and re-running the once-only ledger parser over the literal inventories with **no** alias normalisation still yields 159 / 13 / 30 / 189 / 172.
- [ ] **(r3 M2)** §12 carries **four** per-slice variables tables, §12.1–§12.4, each with exactly the fourteen rows `<slice>`, Migrations list, Flags, Commands, Censuses, Web changes, Device build, Queues, Push count, Env path, Application IDs, Container names, Durable state, Boot synchronization, in that order; every row a slice has no value for says **none** with a reason; each `Push count` row names the manifest pushes that slice collapses; and mobile repository, backups and rollback are outside the tables in §12.5–§12.7. The conclusion that activation is a per-company setting and not an environment flag is in every `Flags` row.
- [ ] **(r3 M3)** `TransferNotificationRaceTest` was captured in the three stages of §10.10 and the handback carries all three: stage 1 run **before** any production notification class existed, with `assertTrue($blocked, …)` passing and `assertSame(0, $contender->wait(), …)` failing on the child's `SQLSTATE[23505] … "notifications_pkey"` stderr, quoted verbatim; stage 2 green after `IdempotentDatabaseChannel`; stage 3's production-notification method green. The barrier is the uncommitted `notifications` primary key, not an advisory lock, and no `pg_advisory_lock` appears in the class. The commands used are the self-contained `phpunit-pgsql.xml` invocations of §13, not `docs/sessions/t1/pg-test.sh`.
- [ ] **(r3 m1)** No sentence anywhere in the plan calls `visibility_version` unsigned; migration 1d still writes `$table->integer('visibility_version')->default(1)`; the DTOs declare `int`; the generated TypeScript declares `number`; and the shipped PostgreSQL column type is `integer`.

Gate-r2 items, each a separate line so a reviewer can tick them independently:

- [ ] **(r2 B1)** `StockTransferData`, `StockTransferLineData` and `StockTransferLineBatchAllocationData` exist as PHP `#[TypeScript]` classes created by S1 at the exact paths of §7.12b, `TransferPayloadBuilder::build()` returns `StockTransferData`, all three appear as interfaces in the committed `packages/shared/types/generated.d.ts` from push 1 onward, S4 imports them and creates no PHP DTO of its own, and the push and union totals were recalculated (§11.7).
- [ ] **(r2 M1, finished by r3 B1)** The final constructors of `StockTransferService` (six arguments), `StockTransferReceiptService` (nine) and `StockTransferController` (six after S1, **seven** after S2's `ExpectedQuantityVisibility`) are on the tree as §6.7b reproduces them; `complete()` is a three-line forwarder that opens no transaction and takes no lock; `receiveAllRemaining` takes a transfer **id** in **every** occurrence, including the reproduced class declaration; `StockTransferService::complete()` is its **only** caller and the controller never calls it; every row of §6.7b's call-graph table resolves to a real method with the stated signature; `canAccessLocationOrFail()` performs the `return_to_source` source check before the service is entered; and the response is built by the controller on every receipt route.
- [ ] **(r2 M2)** `TransferReconciliationData` carries `visibility_version` as its last property, sourced from `TransferIncomingVisibility::visibilityVersion($companyId)`; `TransferReconciliationService` and the reconciliation response mapping are in S2's diff; the two named `T19-S2` assertions (API and generated type) were captured red and are green on the S2 tip.
- [ ] **(r2 M3)** `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` was captured on the pre-change tree, contains a driver key per lane, and the preservation method asserts all four reader query sites byte-equal for completed, cancelled and in-transit transfers with and without lots, with its first failure captured verbatim.
- [ ] **(r2 M4)** `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` was captured before the scope fix, spans two locations and a mixed-receiver note group, and the preservation method asserts byte-equality for the unrestricted non-blind actor with the restricted-actor scoping assertion first.
- [ ] **(r2 M5)** Every row of §7.14b, §9.9b and §10.10b was captured with the classification it states; the four repaired rows (T13, T14, `test_second_company_masking_is_independent`, `test_no_notification_payload_carries_a_quantity_or_a_note`) opened on their new first assertion; the web "none of the three" case is recorded as a CONTROL and was proven red under its mutation; and no method is labelled RED-FIRST anywhere in §7–§10, §13 or §18 while passing at capture.
- [ ] **(r2 M6)** No ERP file appears in more than one place in §11: the per-push lists carry only single-push files, the thirteen multi-push files are only in §11.6, the obsolete Push-2 seven-file row is gone, `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` is an owned S1 file with a ledger row and a reviewer citation, and S1's DTO list is called eleven where it enumerates eleven.
- [ ] **(r2 m1)** The `Inventory` raise note names exactly two wholly PG-only Inventory classes and describes `TransferLegacyCompletionBackfillTest` as cross-lane with one PG-only method; `TransferReceiptSchemaRerunPostgresTest` is named in the `Migrations` raise note and nowhere else.

---

## 19. Self-census

**Spec §10 test-row coverage — 25 rows. Twenty-two are in exactly one slice; three (T3, T9, T19) are split into NAMED partial contracts whose closing slice re-runs the whole row (§2.2 deviation 5, gate r1 M5).**

| Row | Slice | Row | Slice | Row | Slice |
|---|---|---|---|---|---|
| T1 | S1 | T7c | S1 | T15 | S1 |
| T2 | S1 | T8 | S1 | T16 | S1 |
| **T3** | **S1 (`T3-S1`) + S4 (`T3-S4`); CLOSED BY S4** | **T9** | **S3 (`T9-S3`) + S4 (`T9-S4` step 6o); CLOSED BY S4** | T17 | S3 |
| T4 | S1 | T9b | S2 | T18 | S2 |
| T4b | S1 | T10 | S4 | **T19** | **S2 (`T19-S2`) + S3 (`T19-S3` carriage); CLOSED BY S3** |
| T5 | S1 | T11 | S1 | T19b | S2 |
| T6 | S1 | T12 | S1 | T20 | S3 |
| T7 | S1 | T13 | S1 | — | — |
| T7b | S1 | T14 | S1 | — | — |

Counting each row exactly once, by its **closing** slice:

| Closing slice | Rows closed | Count |
|---|---|---:|
| S1 | T1, T2, T4, T4b, T5, T6, T7, T7b, T7c, T8, T11, T12, T13, T14, T15, T16 | 16 |
| S2 | T9b, T18, T19b | 3 |
| S3 | T17, T20, **T19** (via `T19-S3`) | 3 |
| S4 | T10, **T9** (via `T9-S4`), **T3** (via `T3-S4`) | 3 |
| **Total** | | **25** |

`16 + 3 + 3 + 3 = 25`. Every row has exactly one closing slice; the three split rows additionally have a named earlier partial contract (`T3-S1`, `T9-S3`, `T19-S2`) that its own slice proved red-first, so no slice ships behaviour without evidence and no slice claims a row it cannot finish.

**S-matrix rows — 9 writers, each in exactly one slice.** *receive*, *close write_off*, *close return_to_source*, *complete (delegate)*, *backfill*, *migrations* → S1 (§7.13); *settings update*, *settings reset*, *settings initialisation* → S2 (§8.10). S3 (§9.8) and S4 (§10.8) carry additional convention-09 evidence for their own readers and writers, which the spec's S-matrix does not enumerate because those slices add no spec-named writer.

**Plan-added test classes beyond the spec §10 matrix — 2 backend classes plus the client-side classes the spec names in prose.** `TransferReceiptAuthorityGateTest` (S1, §7.14) exists because T18 is assigned whole to S2 and S1 still needs red-first evidence for the route-gate widening. **`TransferNotificationRaceTest` (S4, §10.10) is new in rev 2** and exists because spec §7 requires a two-worker race that rev 1's sequential replay could not exercise (gate r1 M6); it carries no §10 row number of its own and is part of T10's evidence. **Rev 4 redesigns its harness (gate r3 M3)** so its first red is capturable at S4's starting tree — a test-local `RaceProbeNotification` on Laravel's stock `DatabaseChannel`, an uncommitted-primary-key barrier instead of an advisory lock, and a third method that races the shipped `TransferInitiatedNotification` after the production classes land. It is still one Feature class, so the manifest arithmetic does not move. The web and POS Vitest files of S4 (§10.10) are the classes spec §8 and §8b describe in prose; they carry no §10 row number.

**Ledger — 189 push rows, 172 distinct ERP files, 2 generated artifacts, 1 sibling-repository file outside the ledger.** The lane's files are partitioned into two disjoint sets and each file is counted **once** (gate r2 M6): **159** single-push rows in the §11.1–§11.4 lists, and **13** multi-push files in the §11.6 register contributing **30** touches. `159 + 30 = 189` push rows; `159 + 13 = 172` distinct files; the extra-row check `189 − 172 = 17` equals `30 − 13 = 17`, so the two derivations agree. Per push: `74 + 31 + 21 + 63 = 189`. The sibling file is `/Users/houssamr/Projects/syneriva/erp-mobile/src/features/receiving/types.ts` (packet S4-mobile, §17.5).

**Rev-3 file additions, so the delta against rev 2 is auditable rather than asserted — six distinct files and nine push rows.** New files: `StockTransferData.php`, `StockTransferLineData.php` and `StockTransferLineBatchAllocationData.php` (S1, gate r2 B1); `docs/handoff/PROMOTION-CHECKLIST-2026-08-26.md` as an owned S1 modify (gate r2 M6); `apps/api/tests/Fixtures/Inventory/transfer-reader-baseline.json` (S1, gate r2 M3); `apps/api/tests/Fixtures/Inventory/entry-exit-notes-unrestricted-baseline.json` (S3, gate r2 M4). New rows on existing files: S2 modifies of `StockTransferData.php`, `TransferReconciliationData.php` and `TransferReconciliationService.php` (gate r2 B1/M2). `166 + 6 = 172`; `180 + 9 = 189`. **No new Feature test class is added by rev 3**, so §6.7's manifest arithmetic is untouched: the lane still adds 24 classes and still ends at `gated_ceiling 1277`. The three new fixtures and the checklist are not Feature classes and carry no group.

**Rev-4 file additions — none. The ledger is byte-identical to rev 3's (gate r3 M1 and "Source-ledger verification").** Rev 4 adds no file, removes no file and moves no file between the single-push lists and the contention register. It expands five abbreviated locale strings in §10.2 into full paths, which changes the **bullet** count of that block from 34 to 40 while leaving the **path** count at 40, and therefore leaves every total untouched: 159 single-push rows, 13 multi-push files, 30 register touches, 189 push rows, 172 distinct ERP files, 2 generated artifacts and 1 sibling-repository file. `189 − 172 = 17 = 30 − 13` still holds and `74 + 31 + 21 + 63 = 189` still holds. **No new Feature test class is added by rev 4** either — `TransferNotificationRaceTest` already existed in rev 2 and rev 3, and its rev-4 redesign adds one method to that same class plus a test-local `RaceProbeNotification` fixture declared inside the same file, neither of which is a new Feature class — so §6.7's manifest arithmetic is untouched: the lane still adds 24 classes and still ends at `gated_ceiling 1277`.

**Red-first classification — stated for all four ERP slices (gate r2 M5, extended by gate r3 M3 for the S4 race).** §7.14b classifies every S1 method (two controls, S1-C1 and S1-C2, each with a falsification mutation); §8.11 states that S2 ships no control and why; §9.9b classifies every S3 method (four controls, C1–C4); §10.10b classifies every S4 method and test name (two controls, S4-C1 and S4-C2, plus one GREEN-GATE row). The four rows gate r2 named as passing at HEAD — T13, T14, the second-company masking case and the notification payload scan — now open on an assertion that is red at HEAD, and the web "none of the three read permissions" case is reclassified as a CONTROL rather than being presented as red-first evidence. **Gate r3 M3 additionally found that the S4 same-id race's stated first failure was not reachable at its capture point**, because every notification class it needed arrives later in the same slice; rev 4 makes the race self-contained with a test-local `RaceProbeNotification` on Laravel's stock `DatabaseChannel`, states the three capture stages explicitly, reclassifies S4-C1 from "RED at capture, then PASS" to **PASS at every stage** (the probe exists from the file's first commit, so two distinct ids really do write two rows at capture), and adds a third race method, the production-notification binding, classified **GREEN-GATE** because it cannot exist before its class does.

**Reviewers — every slice names its own.** S1: `inventory-costing-reviewer`, `stock-gl-interaction-reviewer`, `tenancy-authz-reviewer`. S2: `tenancy-authz-reviewer`, `inventory-costing-reviewer`, `fiscal-pos-reviewer`. S3: `tenancy-authz-reviewer`, `inventory-costing-reviewer`, `stock-gl-interaction-reviewer`. S4: `frontend-conventions-reviewer`, `tenancy-authz-reviewer`, `fiscal-pos-reviewer`. **S4-mobile: `frontend-conventions-reviewer` (rev 2, §17.5).** Combined: all four of `inventory-costing-reviewer`, `stock-gl-interaction-reviewer`, `tenancy-authz-reviewer`, `frontend-conventions-reviewer`.

**Citations.** Every `path:line` in this plan was opened at `7dcc77afcf0365d4a3ab9ca605d3dd2e1e647395`, re-verified at `73f2040c6d997b26a2a55dfb7d3c8742d361792e`, re-verified at `ab4b321f0417e536d676d8c94b97e09491f18b3c`, re-verified at `364b1668be62a252240e9459e720bf20299a2a61`, and re-verified again at the **rev-6 planning HEAD `f9f6f4480f772d7644b28bc4379cd21a4aef64e0`** (documentation-only ancestry, §1), with its line content read rather than only its path, across seven verification batches covering the transfer service and its enums, the transfer controller and routes, the models and their casts, the compliance settings stack, the three readers, the movement and replenishment surfaces, the seeder and the migrations, the web and POS files, the generated artifacts and their drift gates, and the convention, precision-contract and glossary anchors.

**Spec items placed elsewhere, with the reason.**

| Spec item | Where it went | Why |
|---|---|---|
| §11 step 2 (readers switch in the same merge as the backfill) | S1, not S2 | Deviation 1, §2.2: the spec requires it, and shipping `receive` first would drop a `partially_received` transfer out of every incoming aggregate and out of the WAC denominator |
| §5.0 row 11 fix (a) — entry/exit location scope — and T17 | S3, not S1 | Deviation 4, §2.2: same controller as the §5.10 masking |
| T18 | S2, not S1 | Deviation 3, §2.2: the matrix's defining rows need the setting and the receiver-view route |
| §9 (mobile) | **S4-mobile, a separate packet in a separate repository (§17.5)** | Rev 1 put the edit in S4, whose worktree is the ERP repository and cannot commit a path in `/Users/houssamr/Projects/syneriva/erp-mobile` (gate r1 M8). The spec scopes the SCREEN to M-1; the type/comment contract is executable now, in its own repository, at pin `51e3445` |
| §8b POS device build | not deployed by this lane | §12.4's `Device build` row: the Tauri build is a laptop operation and is never part of a staging push |

**Spec items I could not place, and why.** None. Every numbered section of spec rev 11 (§0–§12) is implemented by S1–S4, or is explicitly deferred with a named owner in §15, or is documentation this plan reproduces (§0 errata, §4 baseline, §5.1 glossary). The one spec statement this plan **declines to reproduce** is §11's "no feature flag" sentence as a deployment instruction: it is true (there is no config flag), but §12 restates it precisely, because the company-level setting is a switch of a different kind and a reader of the manifest's five-push sequence would otherwise look for an environment variable that does not exist.

---
