# Third-pass reassessment — Offline-First Fiscal Source-of-Truth v3

**Reviewer:** Codex  
**Assessment date:** 2026-05-14  
**Document assessed:** `/Users/houssamr/Projects/syneriva/apps/erp/docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v3.md`  
**Verdict:** SOUND-WITH-CORRECTIONS  
**Total new findings:** 0 CRITICAL, 0 MAJOR, 3 MINOR

## Verdict

v3 is materially sound as the foundational architecture document. The 7 MAJOR + 2 MINOR findings from the v2 assessment are genuinely addressed rather than papered over: the exception model is per-class, off-device durability is now mandatory, JET reuse is narrowed to the XML builder, chain-recovery no longer pretends hash-only events are signed, company manifests are immutable, the no-live-data assumption is gated, the RFC 7797 analogy is bounded, and closure-period assignment is normative.

I do not find a new MAJOR. The remaining corrections are narrow but should be fixed before downstream phase specs copy the wording: §7 should classify strict-parse failure explicitly; §10 overstates the Tunisian cash-register rollout as eventually "sector-agnostic"; §10/D15 should mention the live ARP deferral process around LF 2026 article 53 when talking about service e-invoicing.

## v2 Findings Resolution

| # | v2 finding | Verdict | Evidence |
|---|---|---|---|
| 1 | The "accept-and-flag, never block" rule is overbroad and can corrupt fiscal totals | RESOLVED | v3 replaces the blanket rule with per-class handling: hash mismatch/time anomaly accept + quarantine, sequence gap accept + incident mode + explicit exception totals, and signature invalid stop/emergency in signature-required jurisdictions (`v3` lines 121-138). Quarantined records must appear in exports with reconciliation totals, not disappear from legal totals (`v3` lines 136-138). |
| 2 | The durability controls do not survive terminal theft or destruction | RESOLVED | v3 states on-device AES-GCM is crash recovery only and mandates at least one off-device durability path with separately-held key custody, visible unsynced risk, forced archive/export, and an incident register (`v3` lines 151-163). This directly answers the codebase reality that the current key is a plaintext app-data file (`docs/superpowers/research/2026-05-14-pos-fiscal-codebase-reality.md` lines 102-105). |
| 3 | §12 overstates JET export reuse; the current pipeline is not unaffected | RESOLVED | v3 says only `Nf525XmlBuilder` is reuse and explicitly reclassifies `Nf525DataProvider` plus the JET verification path as rework (`v3` lines 221-224). This matches code: `Nf525DataProvider` reads `Receipt` models and filters by receipt fields (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php` lines 104-117), verifies by `ReceiptHashService::calculateHash()` (lines 307-308), and maps structured receipt fields into DTOs (lines 506-531). The XML builder only serializes DTOs to XML (`apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php` lines 99-164), so reuse is correctly narrowed. |
| 4 | Signature and recovery model contradict the hash-only first slice | RESOLVED | v3 reserves "signed" for active signature providers and states pre-signature events are never retroactively upgraded (`v3` lines 80-89, 258). Chain recovery is now "two chained incident events with operator authorization evidence," not signed events (`v3` lines 140-147, 259). |
| 5 | Company-level manifests are not themselves made immutable | RESOLVED | v3 makes `TERMINAL_REGISTRY_SNAPSHOT` and `COMPANY_DAY_CLOSURE_MANIFEST` immutable, chained records carrying expected terminals, chain heads, offline exceptions, approval metadata, hashes, and links to prior manifests (`v3` lines 167-176). Appendix A also classifies them as fiscal/technical event types (`v3` lines 298-300). |
| 6 | "No live fiscal data" is an unverified operational assumption | RESOLVED | v3 keeps the owner clean-rebuild decision but demotes "no live fiscal data" to an operational precondition requiring staging/production tenant queries and written owner sign-off; if data exists, it requires archival/export before proceeding (`v3` lines 17-19, 217). This matches the reality audit, which proves current receipt/offline/Z-report infrastructure exists but cannot prove production emptiness (`docs/.../2026-05-14-pos-fiscal-codebase-reality.md` lines 9-47, 79-100). |
| 7 | Tunisia sufficiency is asserted without primary-source support | RESOLVED-BUT-NEW-ISSUE | v3 adds a Tunisia-specific boundary: general B2C goods retail is in scope, on-site food service is out unless central integration is added (`v3` lines 184-190, 261). Independent sources support the boundary: Tunisia MoF says TVA taxpayers must use uninterrupted invoice numbering and required invoice mentions (https://www.finances.gov.tn/fr/node/75, lines 106-114); JIBAYA/DGI Note Commune 11/2009 extends Article 18 invoicing duties and uninterrupted numbering across covered taxpayers, including some food/pharmacy retailers (https://jibaya.tn/wp-content/uploads/2024/02/Note-commune-n-11-4.pdf, lines 17-21, 61-68, 91-97); the 2025 JORT order defines on-site-consumption providers as food/drink prepared or ready for consumption with on-site service and starts phase 1 on 2025-11-01 for tourist restaurants, tea rooms, and category 2/3 cafés (https://www.webmanagercenter.com/wp-content/uploads/2025/10/Arrete2025_3233.pdf, lines 38-58); Decree 2019-1126 requires homologation, accredited suppliers, permanent communication with the Finance Ministry platform, QR ticketing, and failure reporting (https://jibaya.tn/wp-content/uploads/2025/10/1126%20trait%C3%A9%20fr.pdf?_t=1761205132, lines 41-52, 56-68, 81-95, 129-164). New minor issue: v3's "trajectory" language overstates the rollout as sector-agnostic; see new finding 2. |
| 8 | RFC 7797 analogy is misleading without JWS | RESOLVED | v3 now says the model is only analogous to the detached/unencoded-payload principle and explicitly says Phase 1 is not JWS and inherits no authorship/signature guarantees (`v3` lines 26-37). |
| 9 | Closure-period assignment remains vague | RESOLVED | v3 adds a normative rule: business day is assigned by terminal-configured fiscal timezone and session boundary, not by `server_received_at` or raw device time; clock anomalies do not move events between closure periods without explicit correction; manifests reference sequence ranges (`v3` lines 103-117). This is strong enough for downstream Z-report/export specs. |

## Tunisia Re-verification

The general-retail-OK / food-service-not-served boundary is substantially accurate.

For general B2C retail goods, I found general Tunisian invoicing and retention obligations, not a primary-source obligation for ordinary retail goods POS software to be homologated or centrally integrated. MoF's TVA FAQ requires uninterrupted invoice numbering and standard invoice mentions (https://www.finances.gov.tn/fr/node/75, lines 106-114; https://www.finances.gov.tn/fr/node/952, lines 106-114). DGI Note Commune 11/2009 applies Article 18 invoicing obligations broadly and repeats uninterrupted numbering (https://jibaya.tn/wp-content/uploads/2024/02/Note-commune-n-11-4.pdf, lines 17-21, 91-97). The MoF retention FAQ says accounting registers/documents must be kept for at least ten years (https://www.finances.gov.tn/ar/node/940). v3's "broadly adequate" wording is defensible as an architectural inference, provided it remains paired with the advisor/go-live caveat at `v3` line 188.

For on-site food service, v3 is correct and appropriately excludes the segment. The 2025 JORT order defines covered businesses as selling prepared/ready food or drinks while providing on-site consumption services and sets phased deadlines (`Arrete2025_3233.pdf` lines 38-68). Decree 2019-1126 defines a fiscal-data module that sends collected data to a Finance Ministry platform, requires homologation and accredited suppliers, requires permanent communication, and includes QR-coded ticket fields (`1126 traité fr.pdf` lines 41-52, 56-68, 81-95, 129-164). An offline-first verify-only mirror cannot satisfy "communication permanente et sans interruption" with a central fiscal platform.

The service e-invoicing line is the only volatile point. Decree 2016-1066 assigns electronic invoice processing to Tunisie Tradenet and requires signatures, unique references, registration, and systematic copies to Finance Ministry services (https://www.finances.gov.tn/sites/default/files/CODE%20TVA%202017%20FR.pdf, lines 4919-4946). But ARP's Finance Committee stated on 2026-04-02 that it approved an amendment direction to defer effective application of LF 2026 article 53 while preserving the principle of electronic invoicing for services (https://www.arp.tn/blog/1/post/2026-9508, lines 52-60). v3 should not state service-line TTN scope as a settled go-live requirement without this watch note.

## New Findings

### [MINOR] Strict-parse failure is declared an integrity exception but not classified in §7

**Location:** v3 §5 lines 95-99; §7.1 lines 125-133  
**Evidence:** v3 says structured payload is derived from verified canonical bytes by a strict parser and that strict-parse failure is an integrity exception (`v3` lines 95-98). §7.1 then defines handling only for `canonical_hash_mismatch`, `time_anomaly`, `sequence_gap`, and `signature_invalid` (`v3` lines 125-133).  
**Issue:** A canonical byte string can hash correctly yet fail the strict grammar: duplicate keys, invalid Unicode, unsupported number format, or an event-type grammar error. That is not the same as `canonical_hash_mismatch`.  
**Impact:** Ingestor/export specs could disagree on whether to accept, quarantine, reject, or block parser-invalid but hash-valid events.  
**Correction:** Add `canonical_parse_failure` / `payload_schema_invalid` to §7.1, likely as accept + quarantine + no projection until operator/developer resolution, with the raw canonical bytes still conserved.

### [MINOR] Tunisia rollout trajectory is overstated as sector-agnostic

**Location:** v3 §10 line 187  
**Evidence:** v3 says the cash-register regime widens through phases to July 2028 using "sector-agnostic language" and that general retail is plausibly reached eventually. The 2025 JORT order's later phases still refer back to the Article 1 category: on-site consumption service providers selling prepared/ready food or drinks and providing on-site consumption service (`Arrete2025_3233.pdf` lines 38-68). JIBAYA's NACEF page also presents all phases as for "entreprises prestataires de services de consommation sur place" (https://jibaya.tn/blog/nacef-le-systeme-national-de-caisses-enregistreuses-fiscales-2/, lines 143-167).  
**Issue:** The currently cited primary sources do not show a sector-agnostic expansion to ordinary retail goods. They show progressive inclusion of legal persons/natural persons within on-site consumption services.  
**Impact:** Roadmap planning may over-prioritize NACEF-style homologation for general retail on the basis of a source that does not actually say that.  
**Correction:** Reword to: "The on-site-consumption cash-register regime widens by taxpayer category through July 2028; separate future retail expansion remains possible but is not established by JORT n°125."

### [MINOR] Service e-invoicing status needs a live-watch caveat

**Location:** v3 §10 line 189; D15 line 261 by implication  
**Evidence:** v3 says VAT-liable service lines are in TTN/El Fatoora scope under LF 2026 article 53 (`v3` line 189). Primary e-invoicing rules do route electronic invoices through Tunisie Tradenet with signatures, unique references, registration, and Finance Ministry copies (`CODE TVA 2017 FR.pdf` lines 4919-4946). But ARP's Finance Committee stated on 2026-04-02 that it approved a direction to defer effective application of Article 53 while preserving the principle (https://www.arp.tn/blog/1/post/2026-9508, lines 52-60).  
**Issue:** The scope principle may be right, but effective timing/status is moving. v3 already treats French NF525 status as volatile; Tunisia Article 53 deserves the same treatment.  
**Impact:** A tenant with mixed retail/services may receive an overconfident implementation requirement before the effective date and transition rules are settled.  
**Correction:** Change the sentence to "service lines may require TTN/El Fatoora e-invoicing under LF 2026 art. 53, but effective timing is under active legislative deferral; verify before Tunisian go-live."

## New-issue Scan Of Reworked Sections

- **§7 per-class policy:** Sound with the strict-parse correction above. It fixes the v2 MAJOR by avoiding absolute "never block" and by keeping quarantined records in reconciled exports (`v3` lines 121-147).
- **§8 off-device durability:** Sound. It directly rejects same-device encrypted backup as conservation and requires off-device custody (`v3` lines 151-163), matching the code audit's plaintext `.izipos_key` reality (`docs/.../2026-05-14-pos-fiscal-codebase-reality.md` lines 102-105).
- **§9 immutable manifests:** Sound. The manifests are immutable/chained fiscal or certified archive records, not mutable reports (`v3` lines 167-176).
- **§10 Tunisia:** Sound with the two Tunisia corrections above. Food-service exclusion is strongly sourced; ordinary goods retail is defensible as an inference plus advisor caveat; service e-invoicing timing is volatile.
- **§12 JET correction:** Sound and matches code. `Nf525DataProvider` is rework; `Nf525XmlBuilder` is reusable derived-artifact serialization (`v3` lines 221-224; code citations in v2 finding #3 above).
- **D15:** Sound as a product boundary. The "central-system-integration fiscal regime" exclusion is correct for Tunisian on-site food service.

## Locked-decisions Re-assessment

- **D1:** sound. Device-authority is acceptable paired with the new clock, per-class exception, off-device durability, and immutable company-integrity controls (`v3` lines 247; owner strategy `/Users/houssamr/Downloads/fiscal_chain_architecture_strategy.md` lines 510-539).
- **D2:** sound. Narrowed no-reserialize rule preserves canonical fiscal truth while allowing derived export serialization (`v3` lines 98, 248).
- **D3:** sound. Canonical bytes are authoritative and server structured payload is derived by strict parser (`v3` lines 34-37, 249).
- **D4:** sound. Append-only typed ledger and compensating corrections match owner strategy lines 24-45 and 263-292.
- **D5:** sound. Three-layer model matches printable strategy lines 34-87 and v3 lines 41-51.
- **D6:** sound. `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` remain correctly separated (`v3` lines 65, 252; owner strategy lines 340-380).
- **D7:** sound. It no longer overclaims JET/provider/verification reuse (`v3` lines 221-224, 253).
- **D8:** sound as an owner decision, now properly subject to the preflight gate (`v3` lines 19, 254).
- **D9:** sound. Current POS has no charge-to-account GL/payment path; reality audit lines 52-62 support later-phase scope.
- **D10:** sound. Receipt-chain rebuild is included and `SALE_RECEIPT` is first-class, so no bridge is needed (`v3` lines 64, 256; printable strategy lines 139-187).
- **D11:** sound. Guardrails are appropriate and now include no unverified codebase/operational claims (`v3` lines 231-239).
- **D12:** sound. Hash vs signature naming and non-retroactive signature semantics are corrected (`v3` lines 80-89, 258).
- **D13:** sound with minor correction. Per-class handling and reconciled quarantine are sound, but add strict-parse failure to the class list (`v3` lines 125-138, 259).
- **D14:** sound. Option B remains locked and matches the owner printable strategy recommendation (`/Users/houssamr/Downloads/pos_printable_documents_architecture.md` lines 332-380; `v3` line 260).
- **D15:** sound with minor Tunisia wording correction. The food-service exclusion is right; replace the sector-agnostic trajectory claim and add a live-watch caveat for Article 53 service e-invoicing (`v3` lines 184-190, 261).

