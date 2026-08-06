# Production-Environment Choices — Decision Handover for Double-Check

**Date:** 2026-08-06 · **For:** owner review + an independent Fable-5 confirmation pass
**Context docs:** design `docs/superpowers/plans/2026-08-05-production-environment-design.md` (v6, converged); owner sheet `docs/handoff/OWNER-PRODUCTION-ENV-SIGNOFF-2026-08-05.md`; readiness `docs/superpowers/audits/2026-08-05-production-v1-readiness.md`.

> **Purpose.** The owner questioned four choices. Each is stated below as a decision record: the original recommendation, the challenge, the reasoning, and the RESULTING recommendation. A reviewer should be able to confirm or refute each on the evidence.
>
> **STATUS 2026-08-06: ALL FOUR CONFIRMED BY OWNER.** Rulings:
> 1. **Email = Resend** — confirmed.
> 2. **Launch humans = TWO** — owner + **owner's business partner (chartered accountant)** who takes the E-4 TN fiscal/legal sign-off. E-4 is no longer the open long-pole; the reviewer is named-in-principle.
> 3. **Alerts = Sentry (Crons + Uptime) + email** — confirmed; owner will ensure the Sentry account is on a paid plan that supports Crons + Uptime.
> 4. **Super-admin security = MFA required (at minimum) before production; IP-restriction added later** as defence-in-depth. MFA is now a pre-production BUILD ITEM (TOTP on the `sanctum-admin` guard), not an open question.
>
> These rulings are folded into the owner sheet and design doc (amendment notes at D-5/D-6). The per-decision "reviewer check" lines below remain valid for an independent confirmation pass.

---

## Decision 1 — Transactional email provider: Resend (was: Brevo)

- **Original (D-5):** Brevo free tier (300 emails/day, €0).
- **Challenge (owner):** prefers Resend.
- **Reasoning:** Brevo was selected on ONE axis — free-tier daily volume — which is the wrong axis at this scale. A launch parapharmacy sends nowhere near 100 transactional emails/day (invoices, email verification, fraud alerts), so Brevo's 300/day headroom buys nothing. On DX, deliverability reputation, and DKIM/domain onboarding, Resend is the stronger product. Cost difference at this scale is negligible (Resend free tier covers it; ~$20/mo only if volume ever exceeds it).
- **RESULT: Resend.** Setup notes: requires a verified sending domain (already covered by the `riserpos.app` DNS work); **confirm current free-tier limits at signup rather than trusting a remembered figure**; add SPF + DKIM + DMARC DNS records (owner action, same as any provider).
- **Reviewer check:** confirm Resend's current free-tier daily/monthly caps ≥ expected tenant volume; confirm domain-verification + DKIM is the only prerequisite; confirm no code assumes a Brevo-specific SMTP/API shape (the app uses standard Laravel mail — provider-agnostic).

## Decision 2 — Number of "second humans" for launch gates: as few as TWO (was: six roles)

- **Original:** six roles named across gates E-2/E-3/E-4/E-6/E-8.
- **Challenge (owner):** "can't the six humans be one?"
- **Reasoning:** they are ROLES, not six distinct people, and most collapse onto the owner:
  - Owner can personally hold: DBA/ops steward (E-3), walkthrough Reader (E-6), branch/location manager (E-8, if owner runs the shop), and the operator half of the device smoke test (E-2).
  - **Cannot collapse to the owner: the Tunisia fiscal/legal reviewer (E-4)** — a qualified expert-comptable / legal attestation that the fiscal setup meets DGI requirements. A professional sign-off cannot be self-issued unless the owner personally holds that qualification. This is also the longest-lead item.
  - The **E-2 independent witness** (someone other than the operator confirming the smoke test passed on a real device) exists for audit integrity. Collapsing it to self-attestation is an owner integrity decision, not a technical one — flagged, not silently waived, because a self-certified fiscal smoke test defeats its purpose. It can be the tenant's own operator.
- **RESULT:** collapses to **as few as two people — the owner (wearing ops/reader/manager/operator hats) + one qualified Tunisian accountant/legal reviewer for E-4.** It can be literally ONE (just the owner) **only if the owner is themselves qualified to give the TN fiscal/legal sign-off**; otherwise E-4 requires a second, qualified human.
- **Reviewer check:** confirm whether E-4 / the launch program's fiscal sign-off can be discharged by the owner (depends on the owner's own credentials and TN law); confirm the launch-program gate sheet permits role-collapse (it names roles, not persons); decide the E-2 witness-independence posture for a first real tenant.

## Decision 3 — Ops/alert channel: consolidate on Sentry, drop Telegram (was: Telegram + email)

- **Original (D-6):** Telegram + email for infra/ops alerts.
- **Challenge (owner):** "we use Sentry — what will Telegram be used for exactly?"
- **Reasoning:** different layers. **Sentry = application-layer** (exceptions, stack traces, releases) — needs the app alive and the SDK firing. The Telegram channel was proposed for **infra/ops alerts Sentry's app-SDK can't see**: host down/unreachable, disk full, queue depth, and the **backup dead-man switch** (hourly backup pings; silence = alarm) — with the hard rule that this alerter must run OFF the monitored host so it fires even when the box is down. **Sentry now covers that layer**: Sentry Crons (heartbeat/dead-man for the backup cycle + scheduled jobs) and Sentry Uptime are both external SaaS, satisfying host-independence.
- **RESULT: drop Telegram.** Route app errors + backup dead-man (Sentry Cron) + uptime through **Sentry**, with **email** as the backstop channel. One fewer service and credential.
- **Caveat:** confirm the Sentry plan includes **Crons + Uptime** (limited/absent on the free tier). If it does not: fallback = a tiny free dead-man (e.g. healthchecks.io) + email; Telegram stays only as an optional phone-push nicety.
- **Reviewer check:** confirm the current Sentry plan includes Crons + Uptime monitors; confirm the backup script's dead-man ping (design §6 O-3) can target a Sentry Cron URL; confirm host-level disk/CPU alerts have a home (Sentry infra monitoring vs the VPS provider's own metrics/Dokploy).

## Decision 4 — Super-admin panel readiness (owner question: "is it ready to go live?")

- **Finding (verified against code):** the super-admin **management** panel is real and works (list/show, extend-trial, change-plan, suspend/activate, update-extras, audit; separate `sanctum-admin` guard + `EnsureSuperAdmin`; full `apps/web` admin UI). **But it has NO create-tenant capability** — tenant creation runs only through the public self-registration flow (`POST /api/v1/auth/register` → `TenantProvisioningService`, which does the full db-per-tenant provision synchronously in-request).
- **RESULT: usable-with-manual-steps.** Onboard tenant #1 by: (1) set `SUPER_ADMIN_PASSWORD` + run `SuperAdminSeeder`; (2) create the tenant via the register endpoint/form (vertical=izipos, country=TN, currency=TND, owner creds) — provisions DB + migrations + init synchronously; (3) log into the panel, confirm, set plan/extras; (4) if in-app tax-rate editing is needed, first patch R-11 (seeder + reseed + `permission:cache-reset`).
- **Gaps to decide:**
  - 🚨 **No MFA on an internet-exposed super-admin login** (password + rate-limit only). Recommend MFA **or** IP-restricting the admin surface before production.
  - **R-11 (P0-for-management):** `taxation.tax_configurations.manage` never seeded → in-app tax-config editing dead even for the owner (TN defaults still sell day-1). Seeder fix + reseed.
  - **R-21:** `POST /companies` commits-then-500s (first company from provisioning unaffected; only additional companies).
  - Synchronous provisioning → HTTP-timeout risk on a slow migration (acceptable for one manual onboarding; revisit for self-serve).
- **Reviewer check:** confirm no create-tenant path was missed; confirm the register flow is the intended production onboarding path (vs an artisan command); decide the MFA/IP-restriction posture; confirm R-11/R-21 severity for the specific tenant-1 profile.

---

## Net changes pending owner confirmation
1. Design D-5 + owner sheet: **Brevo → Resend.**
2. Design D-6 + owner sheet: **Telegram dropped; Sentry (Crons + Uptime) + email**, with the plan-tier caveat.
3. Owner sheet §human gates: reframe six roles → **owner + one qualified TN fiscal/legal reviewer (E-4)**, with the E-2 witness-independence posture as an owner ruling.
4. Add a pre-production item: **super-admin MFA / IP-restriction** decision; note onboarding = register-then-manage; keep R-11/R-21 on the fix list.

Once confirmed, these fold into the design doc and owner sheet in one edit; none change the server choice (D-1), the ~€136/mo band materially (Resend/Sentry are cost-neutral-to-lower vs Brevo/Telegram), or the two critical-path actions (order the server; secure the E-4 reviewer).
