# ES/SV dispatch packages — brief gate, round 1

**Provenance**

| Field | Value |
|---|---|
| Artefact under gate | `docs/handoff/CODEX-DISPATCH-es-wave-a0-2026-08-11.md` + `docs/handoff/progress/es-wave-a0.progress.yaml`; `docs/handoff/CODEX-DISPATCH-sv-stage1-2026-08-11.md` + `docs/handoff/progress/sv-stage1.progress.yaml` |
| Gate type | Brief gate (pre-dispatch), round 1 |
| Tree state | `dev` @ `2bbee90b8` |
| Reviewer | Independent Codex reviewer, HIGH effort |
| Mode | In-band (verdict returned in-session; persisted here by the orchestrator) |
| Verdict | **BRIEF GATE: CHANGES-REQUIRED** |

---

## 1. Verdict

**CHANGES-REQUIRED.** Nine findings: one CRITICAL (the harness gate wiring makes both waves
un-runnable end-to-end), six HIGH (rider rationales that are factually wrong or contracts that are
underspecified enough to be implemented in a way the gate would then reject), two minor.

Both packages are otherwise well-formed: scope fences, evidence contracts, red-run obligations,
STOP conditions and reviewer lens assignments are sound and are **not** re-opened by this gate.

## 2. Rider audit — which binding riders survive verification

| Rider | Verdict | Basis |
|---|---|---|
| **SV R-6** (SV-9 sequencing: dossier Stage-1 vs handover §7 must-not) | **VALID** | The two sources genuinely conflict on their face; the brief's resolution (the ruling stands; the implementer *checks* per touch point rather than assumes) is the right shape. |
| **SV R-5** (device has no `ar` locale) | **VALID** | `apps/pos/src/locales/` carries `en/` and `fr/` only; `apps/pos/src/lib/i18n.ts` registers exactly those two. Standing up device Arabic is a new locale + RTL surface, not translation-file work. |
| **SV R-1/R-2/R-3** (SV-1: the gate is real, its stated reason is not; three artefacts; retirement-by-enumeration) | **VALID** | The dead takings-only formula, its three policy-asserting artefacts, and the double unreachability of the branch all hold as described. |
| **ES R-2** (receipt carve-out ⇒ dropping `whereNull` manufactures a false red) | **DEFECTIVE** | Mechanism disproven — see finding 2. |
| **ES R-3** (fleet-wide driver) | **DEFECTIVE (underspecified)** | The refusal it must reconcile is real, but the brief never says what the driver *is*, so the implementer must invent an identity model — see finding 5. |
| **ES R-4 / ES-42** (permission grant left open) | **DEFECTIVE (as dispatched)** | An open permission choice on a live device route is a coin-flip the implementer will be blamed for — see finding 6. |

## 3. Findings

### F-1 — CRITICAL · harness gate wiring makes both waves stop at their first conditional gate

`SELF-REVIEW-HARNESS.md:43-58` (per-milestone loop step 4 + STOP condition B) treats a milestone's
`owner_gate` field as an **unconditional** STOP: *"If the milestone's `owner_gate` field is set … you
may not decide it. STOP (condition B)."* There is no "if the condition fires" clause in the harness.

Three milestones carry the field where the brief text plainly intends a *conditional* escalation:

| YAML | Milestone | Field | Brief's actual intent |
|---|---|---|---|
| `es-wave-a0` | M4 | `owner_gate: ES-42-device-permission-grant` | Stop only if no existing permission fits |
| `sv-stage1` | M2 | `owner_gate: sv11-arabic-device-locale` | Never stop — ship en+fr, file a ticket (R-5 explicitly says "blocks nothing") |
| `sv-stage1` | M3 | `owner_gate: SV-9-blind-default-prerequisite` | Stop only if a touch point genuinely needs G-3/Stage-5 |

As dispatched, ES M4 stops before the guard class, and SV stops at M2 — before SV-9 and SV-10 —
on a gate its own rider declares non-blocking. The SV execution-mode block (`:26-32`) compounds this
by naming both SV gates as STOP conditions in prose.

**Required:** remove the `owner_gate` field from those three milestones; encode the conditionality
inside the milestone instruction text ("if `<condition>` fires, set `blocked_owner` and STOP;
otherwise proceed"); keep the top-level `owner_gates:` entries as informational records with
`blocks_milestone: none`; fix the SV execution-mode block.

### F-2 — HIGH · ES R-2's false-red mechanism is disproven by the code

R-2 asserts that dropping `whereNull('fiscal_event_id')` "feeds canonical-bytes-hashed rows into a
pipe-string recomputation and manufactures a **false red** on a healthy tenant". That is not what the
code does.

`VerifyPosChainCommand::verifyReceiptChain()` (`:293-323`) uses the `whereNull` predicate only to
compute `$count`; verification itself delegates to `ReceiptHashService::verifyTerminalChain()`
(`:318`), which **self-partitions**: `verifyTerminalChain()` (`ReceiptHashService.php:178-213`) runs
`verifyTerminalChainFiscalArm()` (`:234-252`) — canonical-bytes re-hash against `current_hash` with
genesis-seed linkage — and only then `verifyLegacyArm()` (`:334-398`), which itself re-applies
`whereNull('fiscal_event_id')` (`:355`) and dispatches per-row on `sealed_hash_algorithm`. A
canonical-bytes row can never reach a pipe recomputation regardless of the command's filter.

The **defect is still real**, but it is two different things:
(a) **coverage/reporting** — fiscal-era receipts are not counted, so a terminal whose receipts are
all projected returns `is_valid: true` over a count of 0 and the fiscal-arm result is never surfaced
per-arm; and (b) the **missing mirror cross-check** — nothing anywhere compares
`pos_receipts.fiscal_hash` to `fiscal_events.current_hash`.

**Required:** rewrite R-2 and M2 around (a) and (b); drop the false-red rationale and the mandate to
add a duplicate fiscal arm; leave the mechanism (safe filter removal + per-arm reporting, or an
explicit fiscal-era pass) to the implementer, guided by the code reality above. The red-run contract
is unchanged.

### F-3 — HIGH · ES M4 conflates three different contracts under one refusal shape

M4 applies a single "refusal contract" to ES-09, ES-41 and ES-42. Only ES-42 is a refusal.

- **ES-09** is a **correctness** defect: both `resolveChainPlacement()` implementations key the head
  on `(tenant_id, terminal_id)` only — `TerminalRegistrySnapshotService.php:443-463`,
  `VirtualAdminFiscalEventService.php:372-388` — while the correct pattern
  (`OutboxIngestor.php:172-181`) keys on `(tenant_id, company_id, terminal_id, chain_context)`, and
  the schema has enforced that shape since
  `2026_05_24_100000_add_chain_context_to_fiscal_events.php:20-31` (unique on
  `tenant_id, company_id, terminal_id, chain_context, sequence_number`). The correct after-state is
  that a two-context append **succeeds** with per-context heads — not that something is refused.
- **ES-41** is a **trigger-presence regression** scoped to its snapshot row; the non-PG driver gap is
  documented, not "fixed".

**Required:** split M4's contract three ways — ES-09 correctness (before-fix red shows context-blind
head resolution; after-fix green shows a successful two-context append and no unconditional
`Verified` stamp), ES-41 trigger presence, ES-42 the two-sided refusal.

### F-4 — HIGH · the straggler contract-approval flow does not fit the harness

R-7/M3 require the ES-16/ES-17 contracts to be **proposed, reviewed, approved, then implemented** —
but the harness reviews a **committed range** (`--range <base_sha>..HEAD`) once per milestone. A
proposal that lives only "in the review input" is invisible to the reviewer, and an approval that
arrives mid-milestone has no place to land.

**Required:** make the proposals a committed artefact
(`docs/handoff/reviews/es-wave-a0/M3-straggler-contracts.md`) reviewed by M3's own bridge call, and
move implementation to a new **M3b** with its own review.

### F-5 — HIGH · R-3's fleet driver forces the implementer to invent an identity model

R-3 says the driver must "resolve an authorised actor per tenant" but never says *from where*.
`VerifyEventChainCommand`'s gate is anchored on an actor row read inside the bound tenant
(`:198-260`), with `--tenant`, `--terminal`, `--chain-context` and `--actor-id` all required
(`:68-100`). "Resolve an actor per tenant" with no source is exactly the invitation to improvise a
service account that the same rider forbids.

**Required:** make the driver **manifest-driven** — an operator-supplied `tenant → actor id` manifest
at invocation; per listed tenant, run the existing single-tenant verification with the actor gate
preserved exactly; tenants absent from the manifest or whose actor fails authorization are reported
loudly and drive a non-zero aggregate exit, never skipped. No new identity model. If the manifest
shape proves unworkable → STOP condition C. State the terminal/context enumeration source (distinct
`(terminal_id, chain_context)` pairs in `fiscal_events` within the bound tenant).

### F-6 — HIGH · ES-42's permission is decidable now, and leaving it open is the expensive choice

The brief defers "which permission" to the implementer with a possible owner STOP. But an existing
permission fits: `pos.operate_terminal` is seeded and already gates comparable POS write surfaces
(`RolesAndPermissionsSeeder.php:515-520, 590-597, 638-658` — granted to manager and cashier;
`ZReportSyncController.php:61` gates the sibling device sync endpoint with it). The device principal
is an ordinary tenant `User` bearing a Sanctum token (`apps/pos/src/lib/api.ts:61-80`;
`AuthController.php:291-310`), so it carries its role's permissions.

**Required:** lock `pos.operate_terminal`. M4 must first verify the fixture's device principal holds
it; only if it does **not** does the milestone go `blocked_owner`. No new permission, no reseed; add
the "no permission migration needed" deploy note and the device-success obligation (the fixture
device sync path — `syncService.ts:406-440` — must still succeed while an unauthorized principal
403s).

### F-7 — HIGH · SV M5 drops a lens the wave used

M5 is the whole-lane gate and the harness requires it to re-apply **every lens used in the wave**.
`treasury` rides M1 (it rewrites Treasury's config and listener docblock) but is missing from M5's
lens list in both the brief and `sv-stage1.progress.yaml`.

**Required:** add `treasury` to M5 in both files.

### F-8 — minor · the device-Arabic ticket has no path or minimum contents

R-5 requires a ticket but names neither. **Required:** pin
`docs/superpowers/tickets/2026-08-11-pos-device-arabic-locale.md` with minimum contents (no `ar`
tree under `apps/pos/src/locales`, `lib/i18n.ts` registers `en`/`fr` only, RTL implications, owner
gate reference).

### F-9 — minor · SV-11 line 2 "reserve it structurally" is an invitation to ship dead DOM

M2 item 3 says line 2 of the reveal decomposition is where SV-12's rounding split will land and to
"reserve it structurally" — while SV-12 is Stage 5 and out of scope. That reads as a mandate for a
placeholder slot.

**Required:** state the Stage-1 rendering contract — render only the cash-sales row; no rounding
placeholder, no reserved DOM slot; structural extension deferred explicitly to SV-12.

## 4. Not re-opened

Scope fences, the nine-row / four-row scope tables, the evidence contracts, the A0 exit rule (R-11),
the red-run obligations, R-1's Z-arm out-of-scope ruling, R-5's device-Arabic finding, R-6's
sequencing resolution, and the SV-1 rider set are all sound and are unchanged by this gate.

## 5. Disposition

Fix the nine findings and re-gate, or dispatch on the orchestrator's authority once the fixes are in
the tree. The base SHA remains the single open item at dispatch in both packages.

Round 2: PASS — see 2026-08-11-es-briefs-gate-r2.md.
