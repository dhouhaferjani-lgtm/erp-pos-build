# Adversarial reassessment — Offline-First Fiscal Source-of-Truth v2

**Reviewer:** Codex  
**Assessment date:** 2026-05-14  
**Document assessed:** apps/erp/docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth-v2.md  
**Verdict:** MAJOR-REVISION-NEEDED  
**Total findings:** 0 CRITICAL, 7 MAJOR, 2 MINOR

## Verdict

v2 is materially better than v1 and resolves the most damaging fidelity and French-certification errors. It is not yet sound as a grounding document because new §§7-12 introduce overbroad recovery, durability, and reuse claims that downstream specs would likely implement incorrectly. The core architecture is salvageable; the document needs major correction before it becomes the anti-drift anchor.

## Compliance re-verification

The corrected French certification wording is accurate. BOFiP's current `BOI-TVA-DECLA-30-10-30` says conformity can be justified by either an accredited certificate or an individual publisher attestation, and notes that Article 125 of Law 2026-103 restored the attestation route from 2026-02-21 (`https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant%3DBOI-TVA-DECLA-30-10-30-20260325`, lines 314-319). Service-Public states the planned 2026-09-01 removal of self-certification was cancelled (`https://entreprendre.service-public.gouv.fr/actualites/A18087`, lines 108-120). BOFiP's 2026 news item says the same (`https://bofip.impots.gouv.fr/bofip/15035-PGP.html/ACTU-2026-00073`, lines 67-70).

The NF525 architecture wording is also more accurate: BOFiP remains technology-neutral and accepts reliable inalterability techniques such as private-key fingerprints, chaining, and electronic signature; v2 now avoids claiming that device-authority is "the dominant compliant pattern across every regime" (`v2 §10 lines 194-198`). Germany remains correctly bounded as not hash-chain-only: BSI TR-03153 requires a technical security system protecting integrity, authenticity, completeness, immediate recording, and standardized export (`https://www.bsi.bund.de/EN/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/Technische-Richtlinien/TR-nach-Thema-sortiert/tr03153/tr03153_node.html`).

## v1 findings resolution

| # | v1 finding | Verdict | Evidence |
|---|---|---|---|
| 1 | French mandatory-certification claim false | RESOLVED | v2 corrects the statement at §10 lines 194-198; BOFiP current text confirms certificate or attestation at lines 314-319. |
| 2 | Device loss before sync omitted | PARTIALLY-RESOLVED | v2 adds §8 lines 164-175, but its on-device AES-GCM backup does not survive theft/destruction; see new finding 2. |
| 3 | Canonical bytes vs structured payload authority missing | RESOLVED | v2 §1.2 lines 42-45 and §5 lines 106-109 make canonical bytes authoritative and require server-derived strict parsing. |
| 4 | Business Document layer dropped | RESOLVED | v2 restores the three-layer model at §2 lines 49-61, matching printable strategy doc 2 lines 34-87. |
| 5 | Device-authority across comparable regimes overstated | RESOLVED | v2 §10 lines 194-198 now states compatibility plus jurisdiction-specific controls instead of dominance. |
| 6 | Chained-SHA256 under-specified as signature provider | PARTIALLY-RESOLVED | v2 renames the first provider and separates authorship at §4 lines 81-99, but the signature/recovery model now contradicts itself; see new finding 4. |
| 7 | JET export contradicts "server never serializes" | RESOLVED-BUT-NEW-ISSUE | v2 narrows D2 at §5 lines 106-110, but §12 overclaims that the existing JET pipeline is "entire" and "unaffected"; see new finding 3. |
| 8 | "Directly reusable" verification/export patterns not directly reusable | PARTIALLY-RESOLVED | v2 §12 lines 226-229 reclassifies receipt verification as rework, but still misclassifies JET and chain-prefix logic as reuse. |
| 9 | Clock trust absent | PARTIALLY-RESOLVED | v2 adds §6 lines 114-129, but closure-period assignment remains policy prose with no immutable source for `last_server_time_seen`; see minor finding 9. |
| 10 | Multi-terminal company-level integrity absent | PARTIALLY-RESOLVED | v2 adds §9 lines 179-187, but manifests/registry snapshots are not themselves made immutable or chained; see new finding 5. |
| 11 | Receipt-chain divergence under-justified | RESOLVED | v2 replaces divergence with a clean rebuild and single pattern at §1 lines 23-27 and D8 lines 258-259. |
| 12 | Chain recovery missing | PARTIALLY-RESOLVED | v2 adds §7 lines 132-160, but the "signed" recovery events conflict with the hash-only first slice and the "never block" rule is too broad; see new findings 1 and 4. |
| 13 | Partial-payment Option B not locked | RESOLVED | v2 locks Option B at D14 lines 263-265, matching printable strategy doc 2 lines 362-380. |
| 14 | Event taxonomy incomplete | RESOLVED | v2 Appendix A lines 288-313 carries the canonical fiscal and printable taxonomy without "etc." |
| 15 | Genesis seed lifecycle too thin | RESOLVED | v2 Appendix B lines 317-327 covers generation, storage, replacement, decommissioning, compromise, and no silent reset. |

## Fidelity check

v2 is now broadly faithful to the two owner strategy documents. It carries the append-only ledger, typed-event model, POS ledger as source of truth, session/Z-report direction, and future signature abstraction from `fiscal_chain_architecture_strategy.md` (strategy lines 24-45, 127-150, 236-249, 474-506, 510-560; v2 lines 21-45, 65-77, 81-99). It restores the Business Document / Fiscal Event / Printable Representation split and locks Option B from `pos_printable_documents_architecture.md` (strategy lines 34-87, 332-380; v2 lines 49-61, 263-265).

The new clean-rebuild decision is an owner decision and resolves the v1 divergence. The document still needs to distinguish "owner decision" from "verified codebase fact" where it says there is no live fiscal data and where it quantifies the rebuild as 65/25/5/5.

## New findings

### [MAJOR] The "accept-and-flag, never block" rule is overbroad and can corrupt fiscal totals

**Dimension:** architecture / compliance  
**Location:** v2 §1.1 line 32; §7 lines 132-160; D13 lines 263-264  
**Evidence:** v2 says the server flags anomalies and "never blocks" (§1.1 line 32), persists anomalous records to quarantine, excludes them from clean totals (§7.2 lines 149-151), and says the terminal continues operating in degraded mode after chain breaks (§7.3 lines 153-160). BOFiP requires original line data and inalterability/traceability proof to be conserved for six years, not silently excluded from the legal picture (`BOI-TVA-DECLA-30-10-30`, current version lines 255-258, 275-277 in the prior assessment). BSI TR-03153 frames German TSE protection around integrity, authenticity, completeness, and immediate recording.  
**Issue:** The rule is too absolute. Accepting and annotating already-completed offline records is reasonable. Excluding quarantined records from "clean totals" and never blocking future operations even after `signature_invalid` or repeated chain breaks is not a foundationally safe compliance rule. It risks creating two fiscal realities: clean totals for reports and quarantined real sales elsewhere.  
**Impact:** Downstream specs can implement exports that omit real transactions from primary totals, or keep a compromised terminal selling indefinitely because the source-of-truth document says never block.  
**Suggested fix:** Split policies by anomaly and jurisdiction. `canonical_hash_mismatch` can be accepted/annotated. `sequence_gap` should force incident mode and explicit exception totals. `signature_invalid` in a signature-required jurisdiction must trigger a market-specific stop/switch-to-emergency procedure. Fiscal exports must include quarantine sections and reconciliation totals, not simply exclude quarantined records from "clean" totals.

### [MAJOR] The durability controls do not survive terminal theft or destruction

**Dimension:** gap / codebase-grounding  
**Location:** v2 §8 lines 164-175  
**Evidence:** v2 mandates an encrypted local backup of the unsynced segment using the existing AES-GCM file-key crypto (§8 lines 168-170). The actual Tauri crypto stores the AES key in a plaintext `.izipos_key` file under the app data directory, with Unix `0600` permissions (`apps/pos/src-tauri/src/commands/crypto.rs:10-52`; reality audit §3.3 lines 102-105).  
**Issue:** An on-device backup encrypted with a key stored on the same device is not a conservation control for theft/destruction. If the terminal is destroyed, both backup and key are gone. If the terminal is stolen, the attacker may get both ciphertext and key.  
**Impact:** v2 claims to close the device-loss gap, but downstream implementation could satisfy the text while still losing the only authoritative unsynced fiscal copy.  
**Suggested fix:** Require at least one off-device durability path: encrypted removable archive with separately held recovery key, LAN peer replication, local NAS, or periodic cloud sync when available. Define key custody separately from the terminal disk. Keep the on-device AES-GCM copy as crash recovery only, not as the conservation answer.

### [MAJOR] §12 overstates JET export reuse; the current pipeline is not unaffected

**Dimension:** codebase-grounding  
**Location:** v2 §12 lines 222-230  
**Evidence:** v2 says the "entire NF525 JET export pipeline" is reused and unaffected because it reads stored hashes and serializes XML (§12 line 226). In code, `Nf525DataProvider` reads `Receipt` models directly and filters by `pos_receipts` fields (`apps/api/app/Modules/POS/Application/Services/Nf525DataProvider.php:104-117`), maps structured receipt fields into DTOs (`Nf525DataProvider.php:506-531`), and verifies receipt chains by calling `ReceiptHashService::calculateHash()` (`Nf525DataProvider.php:268-329`). `Nf525XmlBuilder` serializes DTO fields into ticket XML (`apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:99-164`).  
**Issue:** The builder can be reused, but the pipeline is not unaffected. The provider and verification path must be reworked to read verified canonical bytes, quarantine state, integrity exceptions, company manifests, and possibly fiscal_events, not just current `pos_receipts` model fields.  
**Impact:** A downstream implementation could leave the export provider on the old structured-receipt model and claim conformance to v2, while exports omit quarantine/manifest data and still use server recomputation in verification.  
**Suggested fix:** Reclassify JET as: XML builder reusable, DTOs likely extended, data provider reworked, verification reworked. Add explicit export requirements for canonical hash, canonical byte reference, quarantine records, chain-restart incidents, terminal manifests, and company closure manifests.

### [MAJOR] Signature and recovery model contradict the hash-only first slice

**Dimension:** architecture / locked-decision  
**Location:** v2 §4 lines 81-99; §7.3 lines 153-160; D12-D13 lines 263-264  
**Evidence:** v2 says the first provider is `HashChainIntegrityProvider` and "currently-only" (§4.1 lines 85-88), while future signature support is not built now (§4.2 lines 89-99). But chain recovery is defined as two "signed events" (§7.3 lines 155-160), and D13 locks recovery as two signed events.  
**Issue:** The document uses "signed" as if an authorship signature layer exists in the first slice, while explicitly saying it does not. Adding nullable signature columns now does not make hash-only recovery events signed, and adding signatures later cannot retroactively make earlier France/Tunisia events TSE-grade.  
**Impact:** Specs can create recovery flows that appear to satisfy D13 but have no actual signing capability. Auditors will see "signed" in the architecture and hash-only incident events in the database.  
**Suggested fix:** Rename first-slice recovery events to "chained incident events" with operator authorization evidence. Reserve "signed" for jurisdictions/providers where `SignatureProviderInterface` is active. State that events created before a signature provider is active remain unsigned historical events; they are not retroactively upgraded.

### [MAJOR] Company-level manifests are not themselves made immutable

**Dimension:** architecture  
**Location:** v2 §9 lines 179-187  
**Evidence:** v2 adds terminal registry snapshots, per-day closure manifests, company grand-total rollups, and export verification (§9 lines 181-187). Strategy doc 1 requires isolated closures and archives and a session/Z-report closure flow (strategy doc 1 lines 474-506, 548-560).  
**Issue:** §9 defines the needed objects but not their integrity model. If terminal registry snapshots and daily manifests are mutable database reports, they do not prove fleet completeness. They need their own event type, chain, hash, signature, or archival rule.  
**Impact:** Downstream specs can satisfy §9 with plain tables or generated reports. An auditor can still not distinguish "terminal absent because it did not exist" from "terminal omitted after the fact."  
**Suggested fix:** Define `TERMINAL_REGISTRY_SNAPSHOT` and `COMPANY_DAY_CLOSURE_MANIFEST` as immutable fiscal/technical events or certified archive records. Each manifest should include expected terminals, chain heads, offline exceptions, preparer/approver, timestamp model, hash, and link to prior manifest.

### [MAJOR] "No live fiscal data" is an unverified operational assumption

**Dimension:** codebase-grounding  
**Location:** v2 §1 lines 23-27; §12 lines 222-232  
**Evidence:** v2 says there is no migration because "there is no live fiscal data" (§12 line 224). The codebase reality audit documents existing `pos_receipts`, `offline_receipts`, terminal chain state, Z reports, and JET export infrastructure, but does not establish that production/live fiscal data is absent (reality audit §1 lines 9-47, §3 lines 81-100).  
**Issue:** The clean rebuild may be an owner decision, but "no live fiscal data exists" is not a codebase fact. It is an operational/environment fact that must be verified before any destructive rebuild or migration-free reset.  
**Impact:** A downstream phase can discard or rewrite receipt-chain tables under the assumption that no tenant has fiscal data, and discover too late that staging, pilot, or imported tenant records exist.  
**Suggested fix:** Keep the clean-rebuild decision, but add a mandatory preflight gate: query production/staging tenant databases for `pos_receipts`, `offline_receipts`, `z_reports`, `grandtotal_events`, `receipt_prints`, and terminal chain state; require written owner sign-off before destructive reset; define a fallback archival/export path if any data exists.

### [MAJOR] Tunisia sufficiency is asserted without primary-source support

**Dimension:** compliance  
**Location:** v2 §4.1 line 87; §10 lines 194-198  
**Evidence:** v2 says chained SHA-256 is sufficient for France and Tunisia (§4.1 line 87). The compliance section cites France, Germany, Italy, Spain/Portugal/Austria categories, but provides no Tunisian primary-source basis for "sufficient." Owner strategy doc 1 mentions Tunisia TTN/e-invoicing as future extensibility, not a conclusion that hash chaining satisfies Tunisian fiscal requirements (strategy doc 1 lines 11-12, 244-247).  
**Issue:** Tunisia is not a side market in the broader project context; claiming sufficiency without citing Tunisian law or administration guidance is not acceptable in a source-of-truth document.  
**Impact:** Phase specs for Tunisia day-one may build on a false compliance assumption and defer TTN/e-invoicing, numbering, or receipt requirements that should be in scope.  
**Suggested fix:** Replace "sufficient for Tunisia" with "assumed acceptable for Tunisia pending primary-source validation" or add Tunisian primary-source citations and a Tunisia-specific requirement matrix.

### [MINOR] The RFC 7797 analogy is misleading without JWS

**Dimension:** consistency  
**Location:** v2 §1.2 lines 34-45  
**Evidence:** v2 invokes RFC 7797 as the formal expression of verifying bytes as received (§1.2 line 40), but the current design is a hash-chain/verbatim-byte storage model, not a JWS detached/unencoded-payload signature model.  
**Issue:** The analogy is directionally useful but can be overread as a claim that the design inherits JWS security properties. It does not, unless a JWS/signature provider is actually implemented.  
**Impact:** Specs may blur the distinction between byte-stable hashing and signed detached payloads.  
**Suggested fix:** Reword to "analogous to the detached/unencoded payload principle in RFC 7797" and explicitly say Phase 1 is not JWS and gains no authorship guarantees from the analogy.

### [MINOR] Closure-period assignment remains vague

**Dimension:** architecture  
**Location:** v2 §6 lines 124-128  
**Evidence:** v2 says closure-period assignment uses the business date derived under documented rules, cross-checked against `last_server_time_seen`, with anomalies flagged (§6 lines 124-128). Existing POS uses device `new Date().toISOString()` for receipt time (`apps/pos/src/lib/offline/receiptService.ts:396-399`; reality audit §3.3 line 106).  
**Issue:** The foundational document still does not define the actual documented rules: local timezone source, business-day cutoff, what happens when `event_time_device` and `server_received_at` fall on different fiscal days, or whether closure manifests use device date or sequence ranges.  
**Impact:** Z-report, company manifest, and export specs can pick inconsistent period assignment rules.  
**Suggested fix:** Add a short normative rule: business day is assigned by terminal-configured fiscal timezone and session boundary; server_received_at is only an audit upper bound; clock anomalies do not move an event between closure periods without an explicit correction event.

## Locked-decisions re-assessment

- **D1:** sound with corrections. Device authority is acceptable only if §§6-9 are tightened as above.
- **D2:** sound. The narrowed "no reserialize for hash verification/replacement" rule fixes v1.
- **D3:** sound. Canonical bytes authoritative plus server-derived strict parsing is the right rule.
- **D4:** sound. Append-only typed ledger matches owner strategy.
- **D5:** sound. The three-layer model is restored.
- **D6:** sound. `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` remain correctly distinct.
- **D7:** partially sound. Client-side reuse is sound; server export/verification reuse must be narrowed.
- **D8:** sound as an owner decision. Single pattern via clean rebuild resolves the divergence, subject to the no-live-data preflight.
- **D9:** sound. POS charge-to-account remains later phase.
- **D10:** partially sound. Including receipt-chain rebuild in the foundation is correct, but "No `SALE_RECEIPT_BRIDGE`" is only safe if receipt events themselves become first-class fiscal events in the rebuilt chain.
- **D11:** sound. Guardrails are appropriate.
- **D12:** partially sound. Hash vs signature naming is fixed, but nullable future signature columns and "signed" recovery wording need correction.
- **D13:** partially sound. Distinguishing exception classes is good; "never block" and "exclude from clean totals" are too broad.
- **D14:** sound. Option B is now locked as the owner strategy recommends.

