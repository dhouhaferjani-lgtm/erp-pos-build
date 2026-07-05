# POS First-Launch Offline Activation — Industry Research

**Date:** 2026-04-30
**Context:** AutoERP / IziPOS Tauri desktop POS for French parapharmacy. Field reality is flaky 4G during go-live. Founder asked: can the very first login on a brand-new device be made fully offline, and if not, what does industry actually do?
**Author:** Research subagent (Claude)

---

## TL;DR

- **No mainstream cloud-connected POS supports a fully offline first launch.** Square, Shopify, Lightspeed (R/X/S), Toast, Clover, Stripe Terminal, SumUp, Loyverse, Odoo, and Microsoft Dynamics 365 Commerce all require an internet round trip on the very first activation. The pattern is universal because the server has to issue a per-device token/cert and load the merchant's catalog/config.
- **The closest thing to "offline first launch" is pre-provisioned hardware** (Toast, Clover) where the vendor ships you a tablet that was already activated against your merchant account at the warehouse. The customer's first power-on is "offline-ish" because the heavy lifting happened at the depot.
- **France's NF525 / 2026 e-invoicing rules do NOT mandate online activation.** They mandate inalterability, hash-chained journals, archiving, and — from Sept 2026 — that the *software publisher* hold an external NF525 or LNE certificate. There is no per-terminal fiscal certificate registry the way Italy's RT or Germany's TSE has, so a French POS is technically free to bootstrap offline if the publisher engineers it.
- **The user's "per-merchant signed installer" idea is technically sound and rare in B2B SaaS POS but common in regulated enterprise software.** It works, but the build/CI cost is non-trivial and revocation is awkward. For a single-shop parapharmacy on flaky 4G it is overkill — a one-time-online activation with a strong offline fallback (the pattern you already have) is the industry norm and what NF525 tooling assumes.
- **Recommendation: keep first-time-online activation, but lower the bar to "5 seconds of cell signal at any point during install"** and make every subsequent operation fully offline-capable. Optionally add a "phone-tether activation" UX for shops where the WiFi is dead.

---

## 1. Industry Survey

| System | Online required for first activation? | Mechanism | Notes |
|---|---|---|---|
| **Square Register** | Yes — hard requirement | First boot prompts for WiFi/Ethernet, runs mandatory firmware update against Square cloud, then activates against merchant account. No captive-portal networks allowed. | Vendor-locked hardware. ([Square setup docs](https://squareup.com/help/us/en/article/8597-set-up-square-register-2nd-generation), [Square Register FAQ](https://squareup.com/help/ca/en/article/6256-square-register-set-up-faqs)) |
| **Shopify POS** | Yes | First user must be a store owner / org admin with the "Set up new or updated POS devices" role. App fetches merchant config and catalog from Shopify cloud. | Offline mode after activation supports card payments only if explicitly enabled in admin (per-device + per-transaction caps). Card reader requires its own pairing flow on top. ([Shopify offline checkout changelog](https://changelog.shopify.com/posts/enable-offline-checkout-from-pos-device-setting), [Shopify offline features](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/selling-offline/offline-features)) |
| **Lightspeed Retail (R / S / X-Series)** | Yes | iPad/desktop browser logs in with store URL + username + password against the cloud; smart terminal then uses a pairing code (`07139` admin PIN to generate). First-time terminal pairing actually requires a firmware update before pairing succeeds. | X-Series (formerly Vend) explicitly cannot run offline until a device has previously logged in and loaded store data. ([Lightspeed S700 setup](https://retail-support.lightspeedhq.com/hc/en-us/articles/45668974132123-Setting-up-Smart-Terminal-v2-S700-S710-with-Lightspeed-Payments), [X-Series offline mode](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534272395163-Selling-in-offline-mode)) |
| **Toast POS** | Effectively no — devices ship pre-activated | Toast hardware is pre-provisioned at their depot for the specific restaurant. The on-site flow is "Device Setup Tool" — restart, walk a wizard. Reusing a device at a new location requires a factory reset. | Closest thing in industry to "offline first launch" but only because the activation happened at the warehouse, not the restaurant. ([Toast device setup overview](https://central.toasttab.com/s/article/Device-Setup-Overview-1493004445768?language=en_US), [Toast — reuse at new location](https://support.toasttab.com/en/article/Reusing-Approved-Hardware-Devices-at-a-New-Location)) |
| **Clover** | Effectively no — same as Toast | Devices arrive **pre-configured and locked to the merchant services provider** that sold them. Activation codes are surfaced in the Clover Dashboard. Devices physically cannot be reprogrammed to a different MSP. | Strongest example of "factory provisioning". ([Clover reprogramming](https://hostmerchantservices.com/articles/reprogramming-clover-pos-station-why-you-can-and-cannot-do/), [Clover FAQs](https://www.clover.com/resources/faqs)) |
| **Stripe Terminal (S700/S710, WisePOS E)** | Yes | Reader generates a pairing code (admin PIN `07139` → "Generate pairing code"), merchant types it into the Stripe Dashboard or POSTs it to the API to bind reader to a Stripe Location. Pairing code expires in minutes. | Server-issued one-time code is the canonical "register-a-reader" pattern. ([Stripe — Register readers](https://docs.stripe.com/terminal/fleet/register-readers), [Stripe Reader S700/S710 setup](https://docs.stripe.com/terminal/payments/setup-reader/stripe-reader-s700-s710)) |
| **SumUp POS Lite / Solo** | Yes | Power on tablet, install app, sign in / register account online, then pair card reader over WiFi/cellular. | Reader explicitly requires WiFi or mobile network even after pairing — so offline is largely irrelevant for them. ([SumUp POS Lite manual](https://help.sumup.com/en-US/articles/4ujBPYfsRS7p7r9sdzceNV-sumup-lite-manual), [SumUp Solo connection](https://help.sumup.com/en-US/articles/5pYlfAutUaUDyeiCT9Jmz3-sumup-solo-how-to-connect)) |
| **Loyverse POS** | Yes | Install from app store, register account, log in. After that, sales/shifts work offline; refunds/customer-creation/item-add require connectivity. | Closest "free SaaS POS" comparable to IziPOS positioning. ([Loyverse offline](https://help.loyverse.com/help/offline-work-of-pos)) |
| **Odoo POS** | Yes — explicitly stated | Odoo's docs are blunt: "for the very first time, an internet connection is always required to connect with the server in order to start and load the data." After session is loaded into the browser, it runs offline. New sessions cannot be opened offline. | Same architecture as IziPOS-style cached SPA. ([Odoo offline mechanism](https://www.odoo.com/forum/help-1/whats-the-mechanism-of-pos-offline-283217)) |
| **Microsoft Dynamics 365 Commerce (Modern POS / Store Commerce)** | Yes | "Device activation" issues a token stored in the install folder, tied to a register/store record in HQ. Token has a configurable max lifetime — when it expires, POS prompts for reactivation. Microsoft Entra auth requires online; on-prem mode does not. Two installers exist: Modern POS, and Modern POS *with offline* (extra local DB). | Most explicit "device token" model documented in industry. ([MPOS device activation](https://learn.microsoft.com/en-us/dynamics365/commerce/dev-itpro/retail-modern-pos-device-activation), [POS device activation](https://learn.microsoft.com/en-us/dynamics365/commerce/dev-itpro/retail-device-activation)) |

**Pattern across all 10 vendors:** zero of them advertise a "fully offline first launch" for software. Two of them (Toast, Clover) achieve the user-facing illusion of one by activating at the warehouse before shipping the box.

---

## 2. Pattern Catalog

### A. Activation code / device pairing code (server-issued, short-lived)
**Used by:** Stripe Terminal, Lightspeed smart terminals, Microsoft Dynamics MPOS, Square Reader.
**How it works:** Device generates or displays a 6–10 char code; merchant pastes into a web dashboard; server binds device → location → merchant. Code expires in minutes.
**Security model:** Out-of-band pairing — the secret is shown on the trusted device's screen and typed into an authenticated dashboard session, so a passive network attacker can't capture it. Vulnerable to MITM in the "association model" phase if not carefully protocol-designed ([academic survey on misbinding attacks](https://arxiv.org/pdf/1902.07550)).
**UX cost:** ~30–90 seconds. Requires the merchant to be logged into the back-office on a second device.
**Failure modes:** Code expiry during slow-network typing; misclicked location binding (device gets attached to the wrong store).
**When to use:** When the device hardware is generic (laptop, iPad) and you need to bind it server-side. This is what IziPOS already does.

### B. Pre-provisioned hardware (factory activation)
**Used by:** Toast, Clover, Verifone/Ingenico for traditional payment terminals.
**How it works:** Merchant places order, vendor depot images and activates the device against the merchant account, ships it. First boot at the merchant runs a "device setup tool" but the cryptographic identity is already loaded.
**Security model:** Trust-on-first-flash. Strong if the depot is well-controlled. Devices are locked to the original merchant services provider and physically un-reprogrammable.
**UX cost:** Zero on-site. Merchant unboxes and turns on.
**Failure modes:** Device returned to the depot or shipped to wrong merchant; secondary-market resale impossible.
**When to use:** You ship hardware and have a fulfillment pipeline. **Not applicable** to a software-only POS on commodity laptops.

### C. License file shipped with the installer (signed bundle)
**Used by:** Enterprise software (Cisco IronPort historically, FANUC, FARO, TEKLYNX, many engineering CAD vendors). Rare in B2B SaaS POS.
**How it works:** Build pipeline emits a per-merchant `.msi`/`.pkg` containing a signed JWT or `.lic` blob naming the merchant, expiry, allowed terminal count. App verifies signature on first launch — fully offline.
**Security model:** As strong as your code-signing key. Revocation is hard: once a leaked installer is in the wild, you can only mitigate via expiry dates or by breaking compatibility in a future release.
**UX cost:** Zero on-site. The friction shifts to your build/distribution pipeline (per-merchant artifact registry).
**Failure modes:** Leaked installer used by N merchants; lost installer means re-issue; no telemetry that activation actually happened.
**When to use:** Air-gapped, government, defense, regulated-medical environments where the merchant *cannot* reach your servers. Overkill for a parapharmacy with 4G.

### D. QR-code pairing from another already-paired device
**Used by:** Some MDM-style flows (Knox, Apple Configurator) and emerging POS vendors. Less common in retail SaaS.
**How it works:** An already-bootstrapped tablet (back-office iPad) shows a QR encoding a short-lived enrollment token; new device scans → token enrolls device server-side.
**Security model:** Same as pattern A but more ergonomic. Requires camera + an existing paired device on the LAN.
**UX cost:** ~10 seconds.
**Failure modes:** New shop with no prior paired device falls back to pattern A.
**When to use:** Multi-terminal stores adding a 2nd/3rd register. Not useful for the very first device.

### E. USB/smartcard-based provisioning
**Used by:** German fiscalization (TSE devices, e.g. Epson, Swissbit), French health professional cards (Carte CPS — actually used in pharmacies for SESAM-Vitale), some defense industry.
**How it works:** A physical token holds the cryptographic identity. Insert into POS at first launch.
**Security model:** Strong (hardware-bound key). Token can be physically lost/stolen.
**UX cost:** Vendor must ship the token; merchant must keep it plugged in or nearby.
**Failure modes:** Token lost = day blocked.
**When to use:** Where the regulator requires it (Germany TSE). NF525 does **not** require this.

### F. Email-link magic activation
**Used by:** Loyverse (email confirmation as part of signup), generic SaaS onboarding.
**How it works:** Merchant signs up online, receives email with confirmation link, clicks; POS app then logs in normally.
**Security model:** Weak (email account compromise = POS compromise). Acceptable for low-stakes activation but not for fiscal device identity.
**UX cost:** ~1–2 minutes assuming the email arrives.
**Failure modes:** Spam folder; mistyped email.
**When to use:** Account creation, not device binding.

### G. First-online-then-fully-offline (the IziPOS model today)
**Used by:** Odoo POS, Lightspeed X-Series, Loyverse, IziPOS.
**How it works:** First launch authenticates against the cloud, downloads catalog/config/keys into local storage, sets the device into "trusted, ready-to-sell" state. Subsequent sessions can be fully offline as long as the cached identity hasn't expired.
**Security model:** Trust-on-first-use plus a server-issued device token (can be short-lived to force periodic re-attestation). Same model as Microsoft Dynamics MPOS.
**UX cost:** A one-time, ~30s online window.
**Failure modes:** Token expires while the shop is offline → POS bricks until reconnect. **This is the failure mode the user is trying to avoid.**
**When to use:** Default for cloud-backed SaaS POS. What you have. Just tune token lifetime aggressively (months, not days).

### H. Demo / kiosk mode (no real account)
**Used by:** Most POS vendors offer a "Try it now" sandbox.
**How it works:** App ships with a hardcoded demo tenant, fake catalog, fake users, and an explicit "this is not a real shop" banner. No fiscal records emitted.
**Security model:** Irrelevant — all data is fake.
**UX cost:** Zero.
**Failure modes:** Cashier accidentally rings up real sales in demo mode (mitigation: visible watermark, no receipt printing, sandbox tenant prefix).
**When to use:** Sales demo, training, install-time exploration before activation.

---

## 3. France / NF525 Regulatory Constraints

NF525 is built around four pillars (ISCA): **Inalterability, Security, Conservation, Archiving** ([Infocert NF525 spec](https://infocert.org/en/nf525/), [Fiskaly summary](https://www.fiskaly.com/blog/cash-register-system-certification-france)). Concretely:

- **Inalterability** — every transaction must be hashed, signed, and chained into a tamper-evident journal. Daily/monthly/annual closings (clôtures) must be calculated and immutable.
- **Security** — strong access controls + signatures. No specification of *online* attestation.
- **Conservation** — records kept 6 years (7 for off-calendar fiscal years).
- **Archiving** — must be exportable and traceable.

**What NF525 does NOT require:**

- It does **not** mandate a per-terminal fiscal certificate issued by the State (unlike Italy's `Registratore Telematico` or Germany's TSE). The certificate applies to the **publisher's software**, not to each installed register.
- It does **not** mandate online activation, online attestation, or real-time transmission to the DGFiP.
- It does **not** require a hardware secure element.

**What changed Sept 2025 → Sept 2026:**

- Self-attestation by software editors is no longer accepted. Publishers must obtain a third-party certification from AFNOR/Infocert (NF525) or LNE ([fiskaly extension to August 2026](https://www.fiskaly.com/blog/pos-software-certification-france-deadline-extension-august-2026), [efsta — end of self-certification](https://www.efsta.eu/en/newsletter/france-end-of-self-certification-and-new-obligation-for-pos-certification-from-2025), [Infocert NF525](https://infocert.org/en/nf525/)).
- Penalty for non-compliant register: **€7,500 per system** ([Innovorder NF525 vs LNE](https://www.innovorder.com/en/blog/nf525-or-lne-certification-cash-register), [Yzico guide](https://www.yzico.fr/caisse-enregistreuse-nf525-votre-guide-pour-etre-conforme-des-le-1er-janvier-2026/)).

**E-invoicing 2026 (a separate regime layered on top):**

- From **September 2026**, all French businesses must be able to *receive* B2B e-invoices via a Plateforme Agréée (PA, formerly PDP) or the public PPF; large/mid-cap must also issue ([Avalara — France 2026](https://www.avalara.com/blog/en/europe/2025/09/france-e-invoicing-e-reporting-mandate-2026-2027.html), [Sovos — 2026 Budget Law](https://sovos.com/regulatory-updates/vat/france-approves-2026-budget-law-confirming-key-amendments-to-the-e-invoicing-and-e-reporting-mandate/)).
- B2C and cross-border are exempt — **a parapharmacy selling to walk-in customers is largely B2C and outside the e-invoicing mandate**, except for the rare B2B sale (e.g. invoicing a clinic). The PA integration is at the back-office layer, not the POS terminal.
- Regulator does not require any per-terminal online step.

**Bottom line:** NF525 + 2026 e-invoicing impose constraints on **what** the POS records and **how** data is archived/transmitted. They impose **no** constraint on whether device activation is online or offline. A French parapharmacy POS could legally activate offline.

(One pharmacy-specific aside: the **SESAM-Vitale / Carte CPS** flow used by full *pharmacies d'officine* dispensing reimbursable drugs DOES require a smartcard reader and online connection to the SCOR / Assurance Maladie servers. **Parapharmacies do not dispense reimbursable drugs**, so this regime does not apply — confirm with the user.)

---

## 4. Hardware vs Software-Only Considerations

Hardware-cert vendors (Verifone, Ingenico, PAX, plus the iPad-wielding Square/Toast/Clover) lean on TPM, secure elements, or factory provisioning. They can ship a device whose private key was burned at the factory and never leaves the chip — closest to "trust on first use" with minimal exposure ([Rambus device provisioning](https://www.rambus.com/security/provisioning-and-key-management/device-provisioning/), [Axelspire factory floor provisioning](https://axelspire.com/business/device-identity-factory-provisioning/)).

Software-only POS on a commodity laptop has **no factory step you control**. Your options collapse to:

1. Generate a key pair on the laptop at install time and have the user prove ownership against a backend (= one-time online).
2. Embed a key in the installer (= per-merchant signed installer, pattern C).
3. Rely on a USB token or smartcard (= pattern E, only viable if the merchant is willing to carry one).

There is no software-only path to "fully offline first launch" without one of the above. Generic flash encryption / TPM-bound keys are useful for protecting the cached credentials *after* activation but don't eliminate the activation step itself.

---

## 5. Per-Merchant Signed Installer — Feasibility Analysis

The user's specific question: **"the installer itself could contain validating info."**

### Is it doable?

Yes. Mechanically straightforward:

1. CI/CD endpoint: when a merchant signs a contract, the back-office issues a signed JWT/license bundle naming the merchant_id, allowed terminal count, expiry, signing CA.
2. The installer is built/repackaged with that bundle injected into a known location.
3. The merchant downloads `IziPOS-Setup-Pharmacie-Dupont-2026.msi` (or `.pkg` for macOS, `.AppImage` for Linux).
4. On first launch, the Tauri app reads the bundle, verifies the signature against an embedded public key, and self-activates with no network call.

### Who actually does this?

- Enterprise software (Cisco IronPort historically, CAD/CAE vendors like FARO and TEKLYNX, FANUC industrial controllers) ship customer-bound installers ([Cisco IronPort license activation](https://www.cisco.com/c/dam/en_us/services/acquisitions/downloads/ironport-sw-license-activation-key-process.pdf), [FARO licensing](https://knowledge.faro.com/Essentials/Software/License_Activation_for_FARO_Software), [TEKLYNX activation](https://www.teklynx.com/en/support/support-options/software-activation), [Software Potential offline activation](https://support.softwarepotential.com/hc/en-us/articles/115001588585-Getting-Started-With-License-Activation)).
- It is **rare in B2B SaaS POS** because (a) the installer can be re-downloaded on demand, so per-merchant builds add CI cost without UX gain; (b) revocation is awkward; (c) telemetry is missing.

### Pros

- **True offline first launch** — even an air-gapped laptop activates.
- **No backend dependency at install time** — go-live is unblocked when 4G is dead.
- **Tamper-evident** — signed bundles can include policy (terminal count, feature flags, expiry).
- **Useful adjunct even if you keep online activation** — you can ship a "rescue installer" to a customer whose token expired.

### Cons

- **CI cost**: build pipeline must produce a per-merchant artifact, sign it, store it, expire it. Code-signing certificates have to remain hot in CI. Roughly 1–2 weeks of platform engineering.
- **Distribution cost**: per-merchant download URL with auth. Cannot host a single public installer.
- **Revocation**: a leaked installer is hard to disable. Mitigation = short bundle expiry (30–90 days) + a "license refresh" online check (which reintroduces online dependency, just less frequent).
- **No telemetry**: you don't know whether the merchant actually installed it until first sync.
- **Cross-platform packaging**: Tauri can do this with a build matrix but you commit to the matrix forever.
- **Auditor optics**: French NF525 auditors are used to the "online activation + journal hash chain" pattern. A signed-installer activation is fine on paper but slightly off the beaten path.

### What you'd need to build

- A "license issuance" service in the platform-API (Laravel) that emits signed JWTs with merchant + terminal limits.
- An offline code-signing root key (HSM ideally; CloudHSM or YubiHSM for low-cost) plus a build-time signing key derived from it.
- A CI job that, on contract signature, builds a per-merchant Tauri installer, uploads to a signed URL, and emails the merchant.
- Tauri-side: bundle reader + signature verifier (use `ed25519-dalek` or `ring`); a "first-launch attests to bundle" path that bypasses the normal cloud activation.
- A revocation list (CRL) check at next-online — but if the POS ever syncs, you may as well validate centrally then.

**Effort estimate:** 2–3 weeks for a competent team to ship the first version. Ongoing CI maintenance burden is non-zero.

---

## 6. Recommendation for IziPOS Parapharmacy

Keep first-time-online activation. Don't build per-merchant installers for go-live.

**Rationale:** A parapharmacy gets at least one moment of cellular connectivity during install — even if the shop's WiFi is dead, the technician's phone hotspot covers it for the 30 seconds activation needs. This is exactly the model that Square, Shopify, Lightspeed, Toast, Clover, Stripe, SumUp, Loyverse, Odoo, and MPOS all converged on. NF525 doesn't push you off it; e-invoicing 2026 doesn't either. The per-merchant installer is a real pattern but its cost (CI pipeline, signing infra, revocation strategy) buys you a niche capability that one-shop parapharmacies don't actually need. **The pragmatic upgrade is in the activation UX, not the architecture**: (1) make the cached device token last 6–12 months so a once-every-rare-while online dip is enough; (2) provide a phone-tether wizard ("plug your phone in via USB / share hotspot now — we need 30 seconds") for the case where the shop's WiFi is broken; (3) ship a "demo mode" so the cashier can train and explore *before* activation (pattern H); (4) keep a "reactivate via paired tablet" QR flow in your back pocket for store #2. Save the per-merchant signed installer for a later enterprise tier or for any deployment that genuinely is air-gapped (clinic networks, government, military).

---

## 7. Open Questions

1. **Does Apotheka / LGPI / Smart Rx** (the dominant French pharmacy-management software) **publish anything about first-launch flow?** Their websites are gated behind partner portals; web search didn't surface developer docs. Worth a direct ask to a parapharmacy customer who has used them.
2. **Does NF525 / LNE require the publisher to log device activations centrally?** I could not find a direct citation either way. Most NF525 attestations focus on the journal hash chain, not on activation. Worth asking AFNOR/Infocert directly during your own certification.
3. **What's the actual bandwidth ceiling for "30 seconds of 4G is enough"?** Activation payload sizes (catalog seed, signing keys) are vendor-private. Need to instrument your own activation against a throttled connection and measure.
4. **Does the SESAM-Vitale carte CPS regime ever apply to a parapharmacy?** Generally no, but if the user's parapharmacy ever bills mutuelles/Assurance Maladie for any product, the answer flips and you suddenly need a smartcard reader on every terminal. Confirm with the merchant.
5. **Is there a "warehouse activation" model the user could imitate at low scale?** E.g. preload the laptop at the IziPOS office before shipping it to the merchant. This is essentially the Toast/Clover trick. Could remove on-site activation pain entirely if IziPOS controls the laptop supply chain.
6. **Toast device-token max lifetime** — Toast docs are silent on whether the on-device cache expires. Microsoft Dynamics MPOS makes this a tunable parameter; doing the same in IziPOS is the lowest-risk improvement.

---

## Sources

- [Square Register setup (2nd gen)](https://squareup.com/help/us/en/article/8597-set-up-square-register-2nd-generation)
- [Square Register setup FAQ (Canada)](https://squareup.com/help/ca/en/article/6256-square-register-set-up-faqs)
- [Shopify enable offline checkout changelog](https://changelog.shopify.com/posts/enable-offline-checkout-from-pos-device-setting)
- [Shopify POS offline features](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/selling-offline/offline-features)
- [Shopify accepting card payments offline](https://help.shopify.com/en/manual/sell-in-person/shopify-pos/selling-offline/offline-payments)
- [Lightspeed Smart Terminal v2 setup](https://retail-support.lightspeedhq.com/hc/en-us/articles/45668974132123-Setting-up-Smart-Terminal-v2-S700-S710-with-Lightspeed-Payments)
- [Lightspeed X-Series logging in](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534087936539-Logging-into-Retail-POS-X-Series)
- [Lightspeed X-Series offline mode](https://x-series-support.lightspeedhq.com/hc/en-us/articles/25534272395163-Selling-in-offline-mode)
- [Toast POS device setup overview](https://central.toasttab.com/s/article/Device-Setup-Overview-1493004445768?language=en_US)
- [Toast — reuse approved hardware at new location](https://support.toasttab.com/en/article/Reusing-Approved-Hardware-Devices-at-a-New-Location)
- [Clover device reprogramming](https://hostmerchantservices.com/articles/reprogramming-clover-pos-station-why-you-can-and-cannot-do/)
- [Clover FAQs](https://www.clover.com/resources/faqs)
- [Stripe Terminal — register readers](https://docs.stripe.com/terminal/fleet/register-readers)
- [Stripe Reader S700/S710 setup](https://docs.stripe.com/terminal/payments/setup-reader/stripe-reader-s700-s710)
- [Stripe — connect to a reader](https://docs.stripe.com/terminal/payments/connect-reader?terminal-sdk-platform=server-driven&reader-type=internet)
- [SumUp POS Lite manual](https://help.sumup.com/en-US/articles/4ujBPYfsRS7p7r9sdzceNV-sumup-lite-manual)
- [SumUp Solo connection](https://help.sumup.com/en-US/articles/5pYlfAutUaUDyeiCT9Jmz3-sumup-solo-how-to-connect)
- [Loyverse offline use](https://help.loyverse.com/help/offline-work-of-pos)
- [Odoo POS offline mechanism](https://www.odoo.com/forum/help-1/whats-the-mechanism-of-pos-offline-283217)
- [Microsoft Dynamics MPOS device activation](https://learn.microsoft.com/en-us/dynamics365/commerce/dev-itpro/retail-modern-pos-device-activation)
- [Microsoft Dynamics POS device activation overview](https://learn.microsoft.com/en-us/dynamics365/commerce/dev-itpro/retail-device-activation)
- [Infocert NF525 reference](https://infocert.org/en/nf525/)
- [Fiskaly — France POS certification overview](https://www.fiskaly.com/blog/cash-register-system-certification-france)
- [Fiskaly — France certification deadline extension to August 2026](https://www.fiskaly.com/blog/pos-software-certification-france-deadline-extension-august-2026)
- [efsta — end of self-certification, Sept 2025](https://www.efsta.eu/en/newsletter/france-end-of-self-certification-and-new-obligation-for-pos-certification-from-2025)
- [Innovorder — NF525 vs LNE](https://www.innovorder.com/en/blog/nf525-or-lne-certification-cash-register)
- [Yzico — NF525 guide for 1 January 2026](https://www.yzico.fr/caisse-enregistreuse-nf525-votre-guide-pour-etre-conforme-des-le-1er-janvier-2026/)
- [AddicTill — caisse parapharmacie NF525](https://addictgroup.fr/caisse-enregistreuse-nf525-parapharmacie/)
- [Avalara — France e-invoicing 2026/2027](https://www.avalara.com/blog/en/europe/2025/09/france-e-invoicing-e-reporting-mandate-2026-2027.html)
- [Sovos — France 2026 Budget Law e-invoicing](https://sovos.com/regulatory-updates/vat/france-approves-2026-budget-law-confirming-key-amendments-to-the-e-invoicing-and-e-reporting-mandate/)
- [Cisco IronPort SW license activation process](https://www.cisco.com/c/dam/en_us/services/acquisitions/downloads/ironport-sw-license-activation-key-process.pdf)
- [Software Potential — offline activation](https://support.softwarepotential.com/hc/en-us/articles/115001588585-Getting-Started-With-License-Activation)
- [FARO software license activation](https://knowledge.faro.com/Essentials/Software/License_Activation_for_FARO_Software)
- [TEKLYNX software activation](https://www.teklynx.com/en/support/support-options/software-activation)
- [Rambus secure device provisioning](https://www.rambus.com/security/provisioning-and-key-management/device-provisioning/)
- [Axelspire factory-floor device identity](https://axelspire.com/business/device-identity-factory-provisioning/)
- [Misbinding attacks on secure device pairing (arXiv)](https://arxiv.org/pdf/1902.07550)
