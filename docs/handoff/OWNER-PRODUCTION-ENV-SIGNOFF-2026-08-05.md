# Production Environment — Owner Sign-Off Sheet

**For:** Houssam · **Date:** 2026-08-05 · **One pass to act on.**

This sheet consolidates the production-environment design and the launch-readiness register into the decisions and actions that are yours alone. Everything here blocks the build in some way; nothing on it can be decided by an engineer. The design has been through four rounds of adversarial review and has converged — the three remaining open items are internal backup/drain/bootstrap scripting details being finished in the next revision, and **none of them change any decision or cost below.**

**Bottom line on money:** **≈ €136 / month** (ex-VAT) **+ one container-registry line nobody has priced yet + €49 once** for server setup.

---

## 1. TL;DR — what I need from you, in priority order

**Two things are on the critical path. Start both today — they have the longest lead times and everything else waits on them.**

- [ ] **A. Place the server order (D-1).** Hetzner **AX42-1 dedicated** (64 GB DDR5 **ECC**, 2×512 GB NVMe), FSN1, **€97.30/mo + €1.70/mo IPv4 + €49 one-off**. No server means no build — this blocks *everything*. ECC is the point: this is a chained-hash fiscal ledger and a silent memory bit-flip is the worst failure we can have. Decide once; moving a live fiscal tenant off a box later is a full disaster-recovery exercise.

- [x] **B. Name the second humans — RESOLVED 2026-08-06 (owner ruling, see `DECISION-HANDOVER-prod-env-choices-2026-08-06.md`): the six roles collapse to TWO people.**
  - **Owner** wears: tenant operator (E-2), DBA/ops steward (E-3), walkthrough Reader (E-6), branch/location manager (E-8), and E-2 witness posture as owner-accepted.
  - **Owner's business partner — chartered accountant** takes the **Tunisia fiscal/legal reviewer** role (E-2 + E-4, incl. D-3b retention answer). E-4 is no longer the unnamed long pole.
  - *Remaining sub-action:* formally record the partner's name on the E-4 gate sheet and get their retention answer (D-3b) before the first fiscal record.

**Then the credential, domain and account decisions that unblock the build phases — these need YOUR accounts or DNS, so an engineer cannot proceed without them:**

- [ ] **C. Domains + DNS (D-2).** Confirm `riserpos.app` (web) and `api.riserpos.app` (API); create the DNS records at **TTL 300** *before* provisioning so TLS issues on first deploy.
- [ ] **D. Secret store account (D-4).** Approve **1Password** and create the vault — **two seats** (you + a second custodian, D-12). Blocks secret rotation (gate E-1).
- [ ] **E. Email account (D-5) — AMENDED 2026-08-06: Resend (was Brevo), owner-confirmed.** Create the Resend account, verify the sending domain, add **SPF + DKIM + DMARC** DNS records, and confirm current free-tier caps ≥ expected volume at signup. Today mail is silently swallowed; the app really does send invoices, email-verification and fraud alerts.
- [ ] **F. Alert channel (D-6) — AMENDED 2026-08-06: Sentry (Crons + Uptime) + email (Telegram dropped), owner-confirmed.** Owner action: ensure the Sentry account is on a **paid plan that includes Crons + Uptime** (thin/absent on free tier). Every "Telegram" mention in the design doc's §6 alert tables now reads as "the D-6 channel = Sentry alert + email backstop".
- [ ] **G. Image registry billing check (D-9).** Open the GitHub billing console, check the Packages storage/transfer allowance, and choose **private GHCR** (recommended) — this is the one recurring cost still unpriced. Do **not** choose public packages: the API image ships the full application source.

Everything not in this list runs on its recommended default — see §2. The human gates are in §3, the build sequence in §4, and the caveats you must formally acknowledge in §5.

---

## 2. Server & infrastructure decisions (D-1 … D-21)

**How this table works.** Every recommendation is a *binding default*: if you say nothing, that is what gets built. The **Action** column tells you the difference that matters —
**"Proceed"** = the agent builds the default, no input needed unless you object;
**"NEEDS YOU"** = it requires your account, your DNS, your money, or your explicit sign-off, and the build genuinely stalls without you.

| # | Decision | Recommendation | ~ / month | One-off | Blocks | Reversible? | Action |
|---|---|---|---|---|---|---|---|
| **D-1** | Server + location | **AX42-1 dedicated, 64 GB DDR5 ECC, 2×512 GB NVMe, FSN1** (fallback NBG1) | €97.30 + €1.70 IPv4 | **€49** | **Everything** | ❌ decide once | **NEEDS YOU — place the order** |
| **D-2** | Domains + DNS | Keep `riserpos.app` + `api.riserpos.app`; create records at TTL 300 | 0 | — | TLS, first deploy, CORS, API URL | ✅ (domain change = rebuild) | **NEEDS YOU — create DNS** |
| **D-3** | Backup destinations | Two legs: Hetzner Object Storage (WORM) €4.99 + Storage Box BX11 ~€3.20 | €8.19 | — | All of backup/DR; restore rehearsal; **first fiscal record** | ✅ swap dest; ❌ too-short retention | Proceed (but D-3b below needs E-4) |
| **D-3b** | Long-term fiscal retention | **NON-WAIVABLE.** Route through E-4 (TN accountant/legal) with a named approver. No multi-year WORM lock set until they answer | incl. | — | The first fiscal record | ❌ | **NEEDS YOU — via E-4** |
| **D-4** | Secret store | **1Password** (Business), two seats | ~€14.80 | — | Secret rotation (E-1) | ✅ | **NEEDS YOU — create account** |
| **D-5** | Email provider | **Resend** (amended 2026-08-06, owner-confirmed; was Brevo). Free tier expected to cover launch volume; verify caps at signup | 0 (Resend free) | — | Onboarding email, invoice delivery | ✅ | **NEEDS YOU — account + DNS** |
| **D-6** | Alert destination | **Sentry (Crons + Uptime) + email** (amended 2026-08-06, owner-confirmed; Telegram dropped). Requires paid Sentry plan with Crons + Uptime | Sentry plan cost | — | All alerting | ✅ | **NEEDS YOU — confirm paid plan** |
| **D-7** | Dokploy plan tier | **Startup, ~$15/mo** (3 servers, unlimited users) | ~€13.90 | — | Registering the 3rd server | ✅ | Proceed |
| **D-8** | Dokploy security upgrade | **GO — upgrade panel to ≥ v0.29.13 BEFORE registering the prod server** (fixes ~15 CVE-class issues incl. command injection) | 0 | — | Hard-blocks registering the prod server | n/a | Proceed (agent executes) |
| **D-9** | Image registry | **Private GHCR** — contingent on your billing-allowance check | ⚠️ **UNQUANTIFIED — requote** | — | The whole deploy pipeline (Phase 0) | ✅ (registry swappable) | **NEEDS YOU — check billing, choose** |
| **D-10** | WAL / point-in-time recovery | Decision **date-bound**: GO/NO-GO by launch + 30 days (or tenant #3) | 0 | — | Nothing at launch; RPO wording | ✅ | Proceed (revisit on the date) |
| **D-11** | WORM lock mode + duration | Interim **governance mode, 90-day** lock (reversible); no compliance lock until E-4 answers | incl. D-3 | — | Backup retention; E-4 | ❌ compliance locks are permanent | Proceed (interim) |
| **D-12** | Second custodian + break-glass | **Required.** Second 1Password seat + a sealed offline recovery package, rehearsed once | ~€7.40 (2nd seat) | — | The claim that DR is executable | ✅ | **NEEDS YOU — name the custodian** |
| **D-13** | Off-provider 3rd backup leg | Decision **date-bound**: GO/NO-GO by first fiscal record + 14 days | ⚠️ single-digit € | — | Nothing at launch; closes biggest residual risk | ✅ | Proceed (revisit on the date) |
| **D-14** | Accept honest RPO/RTO | Committed total-host **RTO ≤ 8 h** (4 h target); RPO conditional. These are the numbers a customer is told | 0 | — | Any availability statement | ✅ on measured evidence | **NEEDS YOU — accept** |
| **D-15** | One-time bootstrap approval | **APPROVE.** Name the release SHA in writing; approve via the `production` GitHub environment; sunset ≤ 14 days | 0 | — | The entire first release | ✅ auto-superseded | **NEEDS YOU — approve + name SHA** |
| **D-16** | Amend gate sheet with E-4a row | **DO IT before Phase 4.1.** Add the non-waivable fiscal-retention row (agent cannot edit that human-only file) | 0 | — | Retention gate; WORM lock config | ❌ | **NEEDS YOU — manual edit** |
| **D-17** | Accept fleet-wide queue pause on restore | **ACCEPT at launch.** A single-tenant restore pauses the job queue for *all* tenants (see §5) | 0 | — | Nothing at launch | ✅ | **NEEDS YOU — acknowledge** |
| **D-18** | Media deletions don't reach WORM leg | **ACCEPT with the privacy consequence** (see §5). Erasure is a manual owner action, ≤ 30-day committed response | incl. D-3 | — | Erasure obligation | ⚠️ partial | **NEEDS YOU — acknowledge** |
| **D-19** | Accept provisioning-credential residual | **ACCEPT.** Real reduction; the API container still holds a create-DB credential (ticketed exit) | 0 | — | Nothing | ✅ | **NEEDS YOU — acknowledge** |
| **D-20** | Move release-set lock out of git | **APPROVE.** Digests live in a signed, attested lock artefact, not in git | 0 | — | Deploy pipeline internals | ✅ before first release | Proceed (technical; agent executes) |
| **D-21** | Accept worker stop grace period | **ACCEPT.** A worker stop can block up to ~1 h in the worst case to drain a long import (see §5); ticketed exit | 0 | — | Deploy/restore drain | ✅ | **NEEDS YOU — acknowledge** |

### Authoritative monthly total

| | Low | **Base — the number to quote** | High |
|---|---|---|---|
| **Monthly (ex-VAT)** | ≈ €121 | **≈ €136 + ⚠️ registry (D-9)** | open, ≥ €136 |
| **One-off** | €49 | **€49** | €49 |

Read it as: **"About €136 a month, plus a container-registry line nobody has priced yet, plus €49 once."** Any total that omits the registry qualifier is wrong. The €121 low band only holds if 1Password already exists and packages are public (not recommended). The same design on cloud (CCX33) would be ≈ €169/mo — 32 % more for half the RAM, half the disk, and no ECC. **Requote every external price from the provider console at decision time** — none of these numbers should be treated as current when read.

*Excluded from every figure:* VAT, domain renewal, paid mail growth, the D-13 third backup leg, DR cloud-instance runtime during an incident, restore/registry egress beyond allowances, and incident labour.

---

## 3. The human gates (E-1 … E-10)

**All ten gates are OPEN. Not one evidence cell has been filled.** The gate documents are human-only — no agent may write into them. Below: who each gate needs, and which is non-waivable.

| Gate | What it is | Needs a 2nd person? | Owner-solo? | Status |
|---|---|---|---|---|
| **E-1** | Secret rotation | No | ✅ you | OPEN — 0 of 9 rows revoked |
| **E-2** | Real-device smoke test | **YES ×3** (tenant operator, Synerivia observer, TN legal reviewer) | — | OPEN — **cannot start** until named |
| **E-3** | Production migration rehearsal | **YES** if the DBA/ops steward isn't you | — | OPEN — no rehearsal yet |
| **E-4** | Tunisia accountant/legal sign-off | **YES** (a TN accountant or legal reviewer) | — | OPEN — **and scope grew** (3 new questions) |
| **E-5** | Runbook preflight = zero | No | ✅ you | OPEN — cheapest gate on the sheet |
| **E-6** | Go-live walkthrough rehearsal | **YES** (the Reader role) | — | OPEN |
| **E-7** | Fiscal correction-chain closure | No | ✅ you | **OPEN — NON-WAIVABLE, the hard blocker** |
| **E-8** | Target-device rollout | **YES** (branch/location manager at the briefing) | — | OPEN — **fix the device version: sheet says v64, must be v67** |
| **E-9** | Staging-runbook execution | No | ✅ you | OPEN — 0 filled cells |
| **E-10** | Production release / cutover | No, but the environment decision is non-delegable | ✅ you | Decision made, **not yet transcribed** into the gate sheet |

**The second humans (RESOLVED 2026-08-06 — roles collapse to TWO people, owner ruling):**

| # | Role | Gate(s) | Held by |
|---|---|---|---|
| 1 | Tenant operator | E-2 | **Owner** |
| 2 | Synerivia observer | E-2 | **Owner** (witness-independence posture owner-accepted) |
| 3 | **Tunisia legal / accounting reviewer** | **E-2 + E-4** | **Owner's business partner (chartered accountant)** — record name on gate sheet; D-3b retention answer owed before first fiscal record |
| 4 | DBA / ops steward | E-3 | **Owner** |
| 5 | Walkthrough Reader | E-6 | **Owner** |
| 6 | Branch / location manager | E-8 | **Owner** |

*Rule as written: an unnamed required second human blocks the gate from **starting**, not just from closing.* A seventh person — the **second custodian** for the break-glass package (D-12) — is separate from these roles and still needs naming (the business partner is the natural candidate).

**New pre-production build item (owner ruling 2026-08-06):**
- **Super-admin MFA is REQUIRED before production** — TOTP on the `sanctum-admin` guard (the login is internet-exposed, password + rate-limit only today). IP-restriction of the admin surface follows later as defence-in-depth. This is a code lane, not an open question.

**Two standing rulings you must record:**
- **E-8 device version:** correct the gate row from **v64 → v67**. Below v67, refunds are hard-refused or the terminal silently splits paths, and the test-campaign evidence is void. Only you can edit that file.
- **E-10 environment decision** (production, after the staging campaign passes) is *made* but not yet written into the human-only gate sheet. Decision made ≠ gate closed — you transcribe it.

**Non-waivable:** **E-7** is the one gate no risk-acceptance can bypass. Until it closes, the standing **NO REFUNDS / NO VOIDS on any terminal** prohibition stays in force.

---

## 4. What happens after you sign off

The agent executes the build through Dokploy, capturing verification evidence at every step. High-level sequence:

1. **Panel upgrade & prep** — upgrade Dokploy to the patched version (D-8), back up and verify the encryption keyring, create DNS records.
2. **Code prerequisites & image pipeline (Phase 0)** — land the database-naming, realtime-config and monitoring code; stand up the CI image-build pipeline that pushes signed, digest-pinned images to the registry. **This ends with the one and only `dev` → `main` promotion.** Production deploys **from `main`**, not `dev` — and `main` is ~4,300 commits behind, so that promotion is its own gated step (a fresh security audit / doc-realignment ruling gates it).
3. **Server registration (Phase 1)** — order confirmed, host hardened, registered as the third Dokploy server.
4. **Services (Phases 2–3)** — Postgres/TimescaleDB, Redis, MinIO, then the API/worker/scheduler/websocket/web apps, all from digest-pinned images, with domains + TLS.
5. **Backups + a REHEARSED restore (Phase 4 & 7)** — both backup legs, then an actual restore from the offsite copy. *A backup never restored is a hypothesis* — this rehearsal is what **feeds gate E-3** and measures the real recovery time.
6. **Observability (Phase 5)** — uptime, heartbeat, disk and queue alarms, with the alarm deliberately broken once to prove it fires.
7. **Cutover (E-10)** — a fresh just-in-time backup on the actual target, then tenant #1 onboards — only after every gate cell is filled by its named human.

---

## 5. Critical caveats you must acknowledge

These are consequences baked into the design. Acknowledging them is part of sign-off.

- [ ] **Media deletions never reach the WORM backup leg (D-18).** The append-only offsite copy is what makes "a compromised host cannot destroy our media" true — and the price is that an image deleted in the app **stays in the offsite copy indefinitely**. If a customer or regulator requires an image erased, deleting it in the app is **not** sufficient: the offsite copy must be dealt with separately, by you, using a console credential the server does not hold. Committed response time: **≤ 30 days** from a documented erasure request.

- [ ] **A single-tenant restore can pause the job queue fleet-wide for up to ~1 hour (D-21 / D-17).** To restore one tenant safely, background job processing is paused for *all* tenants. Normally this is a few minutes; in the worst case it must wait out the longest running import — up to ~1 hour today. Web/HTTP for other tenants is unaffected. At one tenant this costs nothing; there's a ticketed fix (a dedicated queue for long imports) before it matters.

- [ ] **No point-in-time recovery at launch (interim RPO).** Recovery is to the last backup, not the last transaction — so the recovery-point objective is "up to 1 hour of data," conditional. Continuous WAL archiving is a dated decision (D-10), GO/NO-GO by launch + 30 days; a NO-GO must be recorded as an explicit, dated risk acceptance.

- [ ] **The first fiscal tenant is blocked on the Tunisia retention answer (E-4 / D-3b).** No fiscal record can be created until the TN accountant/legal reviewer answers the long-term archival-retention question and a named approver signs off. This is **non-waivable** — an owner risk acceptance does not close it. It is why the Tunisia reviewer is the longest-lead name to fill.

---

*Sources: production-environment design (v4) and production-v1 readiness register, both 2026-08-05, committed state. This sheet does not modify either.*
