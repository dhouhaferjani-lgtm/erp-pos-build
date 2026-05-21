# Adversarial assessment — Offline-First Fiscal Source-of-Truth document

**Reviewer:** Codex  
**Assessment date:** 2026-05-14  
**Document assessed:** apps/erp/docs/superpowers/research/2026-05-14-offline-first-fiscal-source-of-truth.md  
**Verdict:** UNSOUND  
**Total findings:** 4 CRITICAL, 8 MAJOR, 3 MINOR

## Executive summary

- The French compliance posture is already stale: the document says accredited NF525 certification becomes mandatory on 2026-09-01, but the 2026 Finance Act restored publisher attestations, and Service-Public explicitly says the planned removal of self-certification was cancelled.
- The core "device is source of truth" model may be defensible, but the document overstates primary-source support. BOFiP is architecture-agnostic; it does not endorse "server never recomputes" as the dominant compliant pattern, and comparable regimes such as Germany require certified TSE signatures, counters, keys, and time sources.
- The canonical-bytes-verbatim rule is not safe as written. JSON parsing is not categorically safe, and the document does not define which representation is authoritative when canonical bytes and structured payload diverge.
- The document is unfaithful to the printable-documents strategy by dropping the Business Document layer and weakening the owner's Option B recommendation for partial payments.
- It leaves foundational gaps that downstream specs cannot safely fill ad hoc: device loss before sync, chain recovery, clock trust, multi-terminal company-level integrity, and the relationship between the existing receipt chain and the new fiscal-event chain.

## Fidelity to the two strategy documents

`fiscal_chain_architecture_strategy.md` is carried faithfully on the append-only ledger principle, typed fiscal events, compensating-event corrections, per-tenant/per-terminal chain scope, session/Z-report concepts, and POS ledger as the long-term source of truth. Evidence: strategy doc lines 24-45, 127-150, 187-232, 236-249, 474-506, 510-560; source-of-truth lines 17-45, 68-96, 228-242.

Material drops/distortions from `fiscal_chain_architecture_strategy.md`:

- The source doc weakens the strategy's broad event taxonomy. Store-credit/wallet event types (`ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`) and some cash-drawer/session/audit event implications are only implied by "etc." rather than carried as locked taxonomy inputs. Strategy lines 384-399, 455-483, 564-582; source lines 72-76.
- The source doc turns "SignatureProviderInterface allows future NF525/Tunisia/ZATCA/HSM/certificate rotation" into "first implementation is chained-SHA256" without a threat-model boundary for authorship proof. Strategy lines 236-249; source lines 90-92.
- The source doc adds "continuous across fiscal years" sequencing. This is an extension not present in the strategy. It is plausible, but the document does not reconcile it with BOFiP's closure/grand-total periods or the existing receipt chain's year-scoped numbering. Source lines 80-85; reality lines 18-22.

`pos_printable_documents_architecture.md` is carried faithfully on event/document/printing separation, reprints not creating new fiscal events, card slip and kitchen ticket exclusions, and typed printable names. Evidence: printable strategy lines 16-30, 34-87, 139-160, 240-290, 460-499, 538-597; source lines 100-114.

Material drops/distortions from `pos_printable_documents_architecture.md`:

- The source doc drops the three-layer model: Business Document, Fiscal Event, Printable Representation. It collapses the grounding section to Fiscal Event vs Printable Document. Printable strategy lines 34-87; source lines 100-114.
- The source doc does not carry the strategy's partial-payment Option B recommendation as a foundational decision. Printable strategy lines 332-381 recommend separate `SALE_RECEIPT` + `ACCOUNT_PAYMENT_RECEIPT` for ERP-oriented systems; source lines 109-114 and D10 lines 240-241 only imply the first-slice account-payment printout.
- The source doc says "subset, full list in strategy doc 2" for printable types instead of locking the minimum set from the owner strategy. Printable strategy lines 621-638; source line 111.

Extensions added by the source doc:

- Canonical-bytes-verbatim is an author extension, not in either owner strategy. It is directionally useful but under-specified and overclaims that parsing is safe. Source lines 53-60.
- Deliberate divergence from the existing receipt chain is an author extension. It is disclosed, but the rationale has internal tension: the document says canonical bytes eliminate cross-language risk, then uses cross-language risk to justify not realigning the receipt chain. Source lines 162-170.
- Cross-cutting guardrails §7 are sensible but partly exceed the owner docs. They are acceptable as guardrails if they are not used to override explicit owner strategy gaps without justification.

## Independent compliance research

Primary-source conclusions:

- France NF525 / Article 286 CGI is architecture-agnostic at the legal-text level. BOFiP says the law does not define a technical specification or imposed solution; private reference frameworks/solutions must satisfy inalterability, security, conservation, and archiving. Source: BOFiP BOI-TVA-DECLA-30-10-30, lines 198-204, `https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant%3DBOI-TVA-DECLA-30-10-30-20250416`.
- BOFiP permits centralizer conservation, not centralizer authoring. It says secured line-by-line data and cumulative data can be conserved at a centralizer when data is raised from points of sale, but this is an archiving/conservation rule, not proof that the server must or must not author the fiscal chain. Source: BOFiP lines 279-283.
- France does not require one specific cryptographic primitive. BOFiP explicitly gives reliable technical methods including private-key digest, chaining, and electronic signature. Chained SHA-256 can be part of a French NF525 inalterability proof, but the document must not pretend it proves authorship. Source: BOFiP lines 224-240 and 245-250.
- Germany is not "plain hash-chain compatible" in the same way. BSI TR-03153 requires a certified technical security system; BSI describes integrity, authenticity, completeness, immediate recording, standardized export, a security module, transaction counter, signature counter, time source, and protected signature keys. Source: BSI TR-03153 page, `https://www.bsi.bund.de/EN/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/Technische-Richtlinien/TR-nach-Thema-sortiert/tr03153/tr03153_node.html`; BSI TR-03153-1 PDF search result lines state the security module must have a tamper-resistant transaction counter, time source, signature counter, and must prevent access to private signing key material.
- Italy supports offline/deferred transmission, but the primary-source framing is "electronic memorization and telematic transmission" by a telematic register or web procedure, with an emergency 12-day transmission path for sealed RT files. Source: Agenzia Entrate guide PDF search result `Guida_ScontrinoElettronico.pdf`, `https://www.agenziaentrate.gov.it/portale/documents/20143/233439/Guida_ScontrinoElettronico.pdf`.
- The 2026-09-01 mandatory-certification claim is false as of this assessment date. Service-Public says the planned 2026-09-01 suppression of self-certification was cancelled, and Légifrance Article 125 of Law 2026-103 amends CGI article 286 to allow an individual publisher attestation. Sources: Service-Public lines 107-120, `https://entreprendre.service-public.gouv.fr/actualites/A18087`; Légifrance Article 125 lines 505-509, `https://www.legifrance.gouv.fr/jorf/article_jo/JORFARTI000053508893`.
- NF525 seal-on-capture is a safe interpretation, but the document overstates it as established from the cited text. BOFiP requires original data to be rendered inalterable and corrections to be traced; it does not use the phrase "seal-on-capture" or define a maximum offline duration. Source: BOFiP lines 211-229, 255-258, 262-273.

Signature-vs-hash-chain answer: a plain hash chain can help satisfy French NF525 inalterability because BOFiP names chaining as an acceptable reliable technique, but it does not prove device/operator authorship. For Germany, a plain chained SHA-256 scheme is not equivalent to the TSE model because BSI requires certified security-module behavior, signature counters, time source, and protected private signing keys. The document's §2.5 is therefore under-specified: acceptable as a first France-oriented inalterability primitive only if paired with a threat model, key/counter/clock plan, and explicit "not Germany-ready" boundary.

## Findings

### [CRITICAL] French mandatory-certification claim is false as of 2026-05-14

**Dimension:** compliance  
**Document location:** §4 lines 126-129; §8 lines 220-224; §10 line 249  
**Evidence:** Source doc says accredited-body NF525 certification becomes mandatory in France from 2026-09-01. Service-Public, published 2026-02-24, says the planned 2026-09-01 removal of self-certification was cancelled and publisher self-attestation restored (`https://entreprendre.service-public.gouv.fr/actualites/A18087`, lines 107-120). Légifrance Law 2026-103 Article 125 adds back "attestation individuelle de l'éditeur" to CGI article 286 (`https://www.legifrance.gouv.fr/jorf/article_jo/JORFARTI000053508893`, lines 505-509).  
**Issue:** The document's most concrete French compliance date is stale and wrong. The author appears to have relied on the October 2025 BOFiP update without checking the later February 2026 law.  
**Impact:** Every downstream roadmap and certification workstream will plan around a non-current legal obligation. That can misprioritize engineering, procurement, and customer commitments.  
**Suggested fix:** Replace the mandatory-certification statement with current-law wording: as of 2026-05-14, conformity may be justified by accredited certificate or publisher attestation under CGI article 286 as amended by Law 2026-103 Article 125. Keep a tracked compliance watch item for future BOFiP updates.

### [CRITICAL] Device loss before sync is omitted even though conservation is mandatory

**Dimension:** gap  
**Document location:** §1.1 line 33; §4 lines 121-129; §8 lines 220-224  
**Evidence:** The source doc says the device retains data locally until safely synced and archived, but gives no device-loss path (line 33). BOFiP requires all relevant line-by-line data and inalterability/traceability proofs to be conserved for six years, and says line data, not only Z totals, must be kept (`https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant%3DBOI-TVA-DECLA-30-10-30-20250416`, lines 255-258, 275-277).  
**Issue:** In a device-authority architecture, a lost/stolen/destroyed terminal before sync means the only authoritative fiscal records may be gone. The document never states whether this is an unacceptable risk, an operational incident with declaration procedures, or a technical requirement for local backups/removable archives/peer replication.  
**Impact:** Downstream specs can build a compliant-looking local chain that loses legally required records in the first hardware theft or disk failure. The server mirror cannot verify data it never received.  
**Suggested fix:** Add a mandatory "unsynced fiscal data durability" section: encrypted local backup policy, operator-visible unsynced-risk indicator, max unsynced threshold, forced archive/export when offline for N hours/days, incident register, recovery/declaration process, and certification evidence showing how conservation is maintained when a terminal fails before sync.

### [CRITICAL] Canonical bytes and structured payload can diverge with no authority rule

**Dimension:** canonical-bytes-rule  
**Document location:** §1.2 lines 39-45; §1.4 lines 53-60; §5 lines 143-147  
**Evidence:** The document says the server stores canonical string, hashes, and structured payload, and may parse payloads for query/render/export (lines 39-44, 143-147). It does not say whether structured payload is derived server-side from the canonical bytes, provided by the device, validated against a schema, or rejected on mismatch. RFC 8259 says JSON object names should be unique and receiver behavior is unpredictable when they are not (`https://www.rfc-editor.org/rfc/rfc8259.html`).  
**Issue:** "Canonical string + structured payload" is data duplication. If they diverge, the document does not define the authoritative representation. Its claim that "JSON parsing is safe and standardized" is too broad; duplicate keys, large numbers, and Unicode edge cases can produce different parsed structures while the byte hash still validates.  
**Impact:** Reports, printouts, exports, balances, and audit displays can be generated from a structured payload that is not what was sealed in the canonical bytes. The hash chain remains green while business-facing data is wrong.  
**Suggested fix:** Lock the rule: canonical bytes are authoritative. The server must parse only with a strict parser that rejects duplicate keys, numbers outside the approved grammar, invalid Unicode, and schema violations. The structured payload must be derived from the verified canonical bytes, never accepted independently from the device. Store parse status, schema version, and parse error details.

### [CRITICAL] Business Document layer from owner printable strategy was dropped

**Dimension:** fidelity  
**Document location:** §3 lines 100-114; D5 lines 236-237  
**Evidence:** The printable strategy explicitly separates Business Document, Fiscal Event, and Printable Representation (strategy doc 2 lines 34-87). The source doc's §3 only defines Fiscal Event and Printable Document, and D5 locks only fiscal event vs printable document (source lines 100-114, 236-237).  
**Issue:** The consolidation is unfaithful to a load-bearing owner strategy. Business documents are where products/services, VAT, sale obligations, partial-payment state, and future B2B/B2C differences live. Removing that layer makes downstream designs choose between stuffing business state into fiscal events or print projections.  
**Impact:** Future specs for sale receipts, deposits, partial payments, charge-to-account, and B2B invoices will drift again because the grounding doc omits the domain object that should connect workflows to fiscal events and printables.  
**Suggested fix:** Restore the three-layer model exactly: Business Document / Fiscal Event / Printable Representation. Add examples for sale, account payment, deposit, refund, and partial payment showing which fields belong in each layer.

### [MAJOR] "Device-authority is dominant across every comparable regime" is overstated

**Dimension:** compliance  
**Document location:** §1 lines 19-21; §4 lines 121-125; §8 lines 220-224  
**Evidence:** BOFiP is explicitly technology-neutral: it says no technical specification or imposed solution is defined (`https://bofip.impots.gouv.fr/bofip/10691-PGP.html/identifiant%3DBOI-TVA-DECLA-30-10-30-20250416`, lines 198-204). Germany BSI requires a certified Technical Security System that ensures integrity, authenticity, completeness, immediate recording, standardized export, and security-module functions; it is not merely "device app authors a hash chain" (`https://www.bsi.bund.de/EN/Themen/Unternehmen-und-Organisationen/Standards-und-Zertifizierung/Technische-Richtlinien/TR-nach-Thema-sortiert/tr03153/tr03153_node.html`).  
**Issue:** The document converts "compatible with several regimes if completed with country-specific providers" into "dominant compliant pattern across NF525 and every comparable EU regime." That is too strong and will be misread as regulatory proof.  
**Impact:** Downstream specs can defer country-specific security modules, certified exports, time sources, and signatures while believing the base chained-SHA256 model is already broadly compliant.  
**Suggested fix:** Reword the posture: "Device/local authoring is compatible with offline fiscalization patterns, but each jurisdiction imposes additional controls. France: ISCA proof; Germany: certified TSE; Italy: RT/procedure-web transmission; Spain/Portugal/Austria: jurisdiction-specific record/signature/export rules." Add a provider-readiness matrix.

### [MAJOR] Chained-SHA256 is under-specified as a signature provider

**Dimension:** compliance  
**Document location:** §2.5 lines 90-92; §8 line 224  
**Evidence:** The fiscal-chain strategy calls for `SignatureProviderInterface` to support NF525 signatures, Tunisia TTN, ZATCA, HSM integration, and certificate rotation (strategy doc 1 lines 236-249). BOFiP allows chaining or electronic signature as reliable techniques but also discusses private-key digital fingerprints and proof systems (`BOI-TVA-DECLA-30-10-30`, lines 224-250). BSI TR-03153 requires signature counters and protected private signing key material for Germany (BSI TR-03153-1 PDF search result).  
**Issue:** The document calls the first implementation a "signature provider" but defines only a hash chain. That detects sequence tampering; it does not establish authorship, key custody, operator/device identity, or non-repudiation.  
**Impact:** Specs may build a provider interface that cannot absorb real signature schemes without breaking event payloads, key lifecycle, certificate rotation, and verifier output.  
**Suggested fix:** Rename the first provider `HashChainIntegrityProvider` or define the missing signature fields now: algorithm, key id, signature value, certificate id, signer device id, signature counter, verification material, and provider-specific evidence. State explicitly that chained-SHA256 alone is not Germany/TSE-ready.

### [MAJOR] Existing JET export does not support the "server never serializes" absolutism

**Dimension:** codebase-grounding  
**Document location:** §1.3 lines 47-51; §5 lines 145-147; §6.3 line 181  
**Evidence:** The document says PHP never has a canonical encoder for fiscal events and the server provides JET export from the mirror (lines 47-51, 145-147). Existing JET export is server-produced XML: `ExportNf525JetCommand` calls `Nf525JetExportService::exportJetToFile()` (`apps/api/app/Modules/Compliance/Commands/ExportNf525JetCommand.php:91-97`), the service builds XML from a snapshot (`apps/api/app/Modules/Compliance/Services/Nf525/Nf525JetExportService.php:35-66`), and `Nf525XmlBuilder` constructs byte-stable XML in PHP (`apps/api/app/Modules/Compliance/Services/Nf525/Nf525XmlBuilder.php:20-49`).  
**Issue:** The source doc conflates "server must not produce fiscal-event canonical hash bytes" with "server never serializes." Server-side serialization is already necessary for audit artifacts such as JET XML.  
**Impact:** Downstream specs can ban PHP serialization too broadly and accidentally block required export/rendering code, or under-specify how JET exports prove correspondence to stored canonical fiscal events.  
**Suggested fix:** Narrow D2: "PHP must not reserialize fiscal-event payloads for hash verification or replacement. PHP may serialize derived audit/export artifacts, including JET XML, provided they reference verified event ids/hashes and never become the source of chain truth."

### [MAJOR] "Directly reusable" verification/export patterns are not directly reusable

**Dimension:** codebase-grounding  
**Document location:** §6.3 lines 172-181  
**Evidence:** The source says `verifyTerminalChain()` / `VerifyPosChainCommand` / `ExportNf525JetCommand` are reusable with correction (line 181). Existing `ReceiptHashService::verifyTerminalChain()` recomputes hashes from structured `Receipt` models (`apps/api/app/Modules/POS/Domain/Services/ReceiptHashService.php:138-175`), which is precisely what the new design forbids. Existing receipt sync recomputes server hash and compares to `offline_fiscal_hash` (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:612-634`; reality §1.4 lines 29-37).  
**Issue:** These are conceptual precedents, not directly reusable implementations. Calling them "directly reusable" hides the need for a new verifier over stored canonical bytes and a new export data provider over fiscal events.  
**Impact:** Future implementation specs may adapt the wrong receipt-chain code and reintroduce server-authority semantics.  
**Suggested fix:** Split §6.3 into "reusable concepts" and "not reusable code." Require a new `FiscalEventMirrorVerifier` that hashes stored canonical bytes, and a new JET/provider adapter that reads fiscal_events rather than Receipt models.

### [MAJOR] Clock trust is absent from the foundational threat model

**Dimension:** architecture  
**Document location:** §1.1 lines 27-32; §2.3 lines 80-85; §5 lines 135-148  
**Evidence:** Existing POS uses the device system clock for receipt timestamps (`apps/pos/src/lib/offline/receiptService.ts:396-399`; reality §3.3 line 106). BOFiP requires dated detail for operations/corrections and daily/monthly/yearly closures (`BOI-TVA-DECLA-30-10-30`, lines 229, 262-273). BSI TR-03153 requires a security-module time source (BSI TR-03153-1 PDF search result).  
**Issue:** The document makes the device authoritative for event timestamps but never defines whether timestamps are trusted, monotonic, server-observed, corrected on sync, or marked as device-claimed.  
**Impact:** A cashier can roll back the device clock and create internally valid chains with misleading business dates, closure periods, and offline-duration evidence. Audits and reports will trust timestamps that the architecture never secured.  
**Suggested fix:** Add a time model: device event time, monotonic sequence time, last server-seen trusted time, server_received_at, drift detection, clock rollback detection, closure-period assignment rules, and audit flags for untrusted/offline time.

### [MAJOR] Multi-terminal company-level integrity is not defined

**Dimension:** architecture  
**Document location:** §2.3 lines 80-85; §4 lines 121-125; §8 lines 220-224  
**Evidence:** The document scopes chains per `(tenant_id, terminal_id)` and says sequence is continuous across fiscal years (lines 80-85). BOFiP requires daily/monthly/annual closures and cumulative/perpetual totals for the cash system (`BOI-TVA-DECLA-30-10-30`, lines 262-273). Strategy doc 1 requires isolated tenant signatures/sequences/closures but also session/Z-report closures (lines 548-560, 474-506).  
**Issue:** A fleet of independent terminal chains needs a company-level integrity story: how to prove completeness across all terminals, locations, closures, decommissioned devices, and offline gaps. The document does not define fleet manifests, terminal registry sealing, cross-chain closure manifests, or company-level Z/grand totals.  
**Impact:** An auditor can verify each terminal chain independently and still not know whether a terminal/day/location is missing from the company export.  
**Suggested fix:** Add a company-level manifest model: terminal registry snapshots, per-day closure manifests listing every expected terminal chain head, missing-terminal/offline exceptions, company grand-total rollups, and export verification that checks both per-chain integrity and fleet completeness.

### [MAJOR] Deliberate divergence from the receipt chain is under-justified

**Dimension:** divergence  
**Document location:** §6.2 lines 162-170; D8 lines 238-239; §10 line 248  
**Evidence:** The document says canonical-bytes-verbatim eliminates cross-language risk (lines 53-60), then says server recomputation is rejected because it reintroduces cross-language risk (lines 166-168). Existing receipt chain already has both client-side and server-side V3 canonical implementations with parity tests (`apps/pos/src/lib/offline/receiptService.ts:385-388`; reality §1.4 lines 30-37).  
**Issue:** Keeping two permanent fiscal semantics is a major architecture decision, but the document only records it as "out of scope." It does not analyze maintenance cost, certification implications, audit explanation, or whether the receipt chain could be realigned once canonical bytes are stored.  
**Impact:** The platform will have two verification paths, two chain authorities, and two mental models for sales vs account payments. That is a long-term certification and maintenance trap.  
**Suggested fix:** Add a decision record for D8: alternatives considered, realignment cost, why not now, explicit migration trigger, and an audit-facing explanation of why sale receipts are server-authority while account payments are device-authority.

### [MAJOR] Chain recovery and broken-chain handling are missing

**Dimension:** gap  
**Document location:** §1.2 line 45; §2.1 lines 68-70; §7 lines 200-207  
**Evidence:** The document says mismatches are flagged and not fixed (line 45), but does not define recovery. Existing receipt sync explicitly propagates chain breaks after a failure (`apps/api/app/Modules/POS/Application/Services/ReceiptSyncService.php:46-52`, `106-119`).  
**Issue:** "Flag tamper/integrity event" is not enough for a source-of-truth architecture. It must define whether the terminal can continue after a broken local chain, how a new genesis is authorized, how the broken segment is archived, and how fiscal reports mark the break.  
**Impact:** The first sync mismatch or local DB corruption forces implementers to invent ad hoc recovery semantics, risking either illegal mutation or operational deadlock.  
**Suggested fix:** Add a chain incident state machine: detected locally vs detected on server, quarantine, operator lockout/continue policy, authorized chain restart, new genesis issuance, incident report, export annotation, and reconciliation with unsynced records.

### [MINOR] Partial-payment Option B is not locked despite owner recommendation

**Dimension:** fidelity  
**Document location:** §3 lines 109-114; D10 lines 240-241  
**Evidence:** Printable strategy doc 2 recommends Option B for ERP-oriented systems: separate `SALE_RECEIPT` + `ACCOUNT_PAYMENT_RECEIPT` for partial payments (lines 332-381). Source doc does not lock that decision.  
**Issue:** This is not as foundationally damaging as dropping Business Document, but it leaves a known downstream fork unresolved.  
**Impact:** A future partial-payment spec can reintroduce Option A without noticing it contradicts the owner strategy.  
**Suggested fix:** Add "Partial payments use Option B unless a later owner decision explicitly overrides it" to locked decisions.

### [MINOR] Event taxonomy is incomplete compared with the owner strategy

**Dimension:** fidelity  
**Document location:** §2.2 lines 72-76; §3 line 111  
**Evidence:** Owner strategy includes store credit/wallet events (`ACCOUNT_CREDIT_ISSUE`, `ACCOUNT_CREDIT_USAGE`) and cash drawer events (`OPENING_FLOAT`, `SAFE_DROP`, `CASH_CORRECTION`) (strategy doc 1 lines 384-399, 455-465). Source doc gives a shorter list with "etc." and the printable list says subset only.  
**Issue:** A foundational document should not rely on "etc." for fiscal event vocabulary.  
**Impact:** Future specs can invent incompatible names for store credit, safe drops, and cash corrections.  
**Suggested fix:** Add an appendix with canonical event types and printable types copied from both owner strategy docs, marking each as in-scope phase or future.

### [MINOR] Genesis seed lifecycle is only one sentence

**Dimension:** gap  
**Document location:** §2.3 line 82  
**Evidence:** The source doc says the server issues the terminal genesis seed once at provisioning, and says nothing about terminal replacement, decommissioning, compromise, or rotation. BOFiP says counters reset on hardware/software change but old counters must be archived and secured (`BOI-TVA-DECLA-30-10-30`, lines 271-273).  
**Issue:** Genesis seed handling is a fiscal lifecycle topic, not an implementation detail.  
**Impact:** Terminal replacement and reprovisioning specs will invent their own chain restart semantics.  
**Suggested fix:** Add seed lifecycle rules: generation entropy, storage, export, replacement, decommission, compromise, reset prohibition, archival of old chain head/counters, and audit annotations for new chain genesis.

## Locked-decisions assessment

- **D1:** sound but under-specified. Device-authority is defensible for offline-first, but only with explicit durability, clock, compromise, and recovery controls.
- **D2:** premature as worded. Ban PHP fiscal-event hash serialization, not all server serialization; JET/export serialization is required.
- **D3:** under-specified. Verbatim canonical bytes are useful, but authority and validation rules for structured payloads are missing.
- **D4:** sound. Append-only typed-event ledger faithfully carries the owner fiscal-chain strategy.
- **D5:** under-specified. Event vs printable separation is sound, but it omits the Business Document layer.
- **D6:** sound. `ACCOUNT_PAYMENT` and `ACCOUNT_CHARGE` must stay distinct.
- **D7:** sound if reworded. Reuse client-side patterns conceptually; do not imply direct reuse of receipt verification/export code.
- **D8:** premature. Divergence may be necessary short-term, but permanent divergence is not justified.
- **D9:** sound. POS charge-to-account is not present today and should remain later-phase.
- **D10:** premature. First go-live slice is a roadmap decision, but excluding `SALE_RECEIPT_BRIDGE` entrenches the two-chain divergence without mitigation.
- **D11:** sound. Guardrails are useful, provided they do not substitute for missing foundational lifecycle rules.
