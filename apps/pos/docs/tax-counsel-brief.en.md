# Legal Opinion Brief — POS Fiscal + Data-Protection Architecture

**Prepared for:** Local tax and data-protection counsel, **[Country]**
**Prepared by:** Syneriva / Otospex engineering team
**Date:** 2026-04-19
**Subject:** Request for written legal opinion on the acceptability of our current POS architecture under **[Country]'s** fiscal certification and personal-data-protection laws
**Classification:** Confidential — subject to attorney/client privilege where recognized in [Country]

> **Usage note.** This is a template brief. Before sending to counsel in a specific jurisdiction, replace every occurrence of **[Country]** with the target country's name and fill in the **[bracketed]** fields at the end (contact point, server location, deadline). An French-language equivalent is maintained as a parallel document in `tax-counsel-brief.fr.md`.

---

## 1. Executive summary

We operate a point-of-sale (POS) system deployed across France, the United Kingdom, Italy, Tunisia, Morocco, and Côte d'Ivoire, under two brand names: **IziPOS** for retail and **Otospex** for the automotive vertical. The application is offline-first on the merchant's terminal, with asynchronous synchronization to a central server.

**Fiscal integrity** is ensured by a SHA-256 hash chain that cryptographically links each receipt to the previous one; any alteration of a past receipt invalidates the entire subsequent chain and is automatically detected at the next synchronization.

**Confidentiality** of the local database is currently ensured by a mandatory full-disk-encryption (FDE) policy enforced on every deployed terminal (FileVault on macOS, BitLocker on Windows, LUKS on Linux), together with a documented operator-onboarding checklist.

We seek your **written opinion** on whether this posture satisfies the fiscal certification and personal-data-protection requirements applicable in **[Country]** as of the date above.

---

## 2. Questions on which we seek your opinion

Please answer each question with yes / no / qualified, and cite the statutory or regulatory basis where applicable.

1. **Fiscal certification.** Does [Country]'s fiscal-law regime require that the POS terminal database be *encrypted at rest at the application layer*, or is full-disk encryption on the host machine, combined with a tamper-evident hash chain applied to every fiscal record, sufficient to meet the local "inalterability" or equivalent requirement?

2. **Personal-data protection.** Under [Country]'s personal-data-protection law (the local equivalent of GDPR Article 32), is encryption of the local database a *mandatory* technical measure, or is full-disk encryption an acceptable equivalent when deployed with the access controls described in Section 6?

3. **Retention.** What is the mandatory retention period for POS fiscal records in [Country]? Does a parallel, shorter retention period apply to *personal data* linked to those records (loyalty identifiers, customer names) — and if so, what pseudonymization or deletion step is expected?

4. **Breach notification.** If a POS terminal is lost or stolen while FDE is enabled and properly configured (see Section 6.1), does this constitute a reportable data breach in [Country]? What trigger and notification timeline apply?

5. **Ongoing obligations.** Are there annual certifications, audits, or fiscal-body filings we must make to maintain conformity with [Country]'s POS regime? Any registration or declaration to a data-protection authority?

6. **Data residency.** Does [Country]'s law require fiscal records or personal data to be *physically stored* within the country's borders? Our central server is currently hosted in **[European data centre location — TBC]**.

---

## 3. Business context

- **Otospex** serves B2B automotive-service businesses; **IziPOS** serves generic retail verticals (coffee shops, small grocers, specialty stores).
- Year-one deployment target: 50–200 terminals across the six countries named above.
- Each terminal runs on dedicated hardware (Mac mini, Windows mini-PC, or Linux Intel NUC) physically located at the merchant's counter — not on a shared personal laptop.
- Every sale, refund, and end-of-day Z-report is a fiscal record.
- Customer data captured per sale is limited to: optional loyalty-member identifier (opt-in), optional customer name printed on the receipt, and payment-method metadata (see Section 7).

---

## 4. System architecture

### 4.1 Two-tier design

Each terminal runs a desktop application (Tauri framework, Rust + web) that maintains its own local SQLite database of receipts, stock, and operator sessions. A central Laravel (PHP) API server stores the authoritative copy of all records after synchronization.

**At the terminal (offline-first):**
- Operator logs in via numeric PIN.
- Cashier enters the cart and collects payment.
- The receipt is written immediately to the local SQLite database (median latency 5–50 milliseconds).
- A background scheduler pushes new receipts to the central server whenever a network connection is available.

**At the central server:**
- Idempotency-key deduplication (a given client-generated receipt is only accepted once, even if retransmitted).
- Server-side hash-chain verification.
- Any rejected receipt triggers a persistent red "chain-break" alert on the terminal's user interface.

### 4.2 Offline tolerance

The merchant's network is assumed to be unreliable. The system is designed to operate for hours (or days, in principle) without connectivity. Receipts accumulate locally; the sync queue flushes when connectivity returns; any conflict or chain break is immediately surfaced to the operator on screen.

---

## 5. Integrity measures — the hash chain

### 5.1 Construction

Each receipt carries a `fiscal_hash` field computed as follows:

```
fiscal_hash = SHA-256(
    previous_receipt_hash
  || receipt_number
  || posted_at
  || total
  || currency
  || VAT_breakdown
  || payment_breakdown
)
```

The first receipt on each terminal is seeded by the server from a genesis hash unique to the terminal's activation event. Every subsequent receipt's hash depends on the previous receipt's hash, forming an append-only chain.

### 5.2 Properties

- **Tamper evidence.** Any alteration of a past receipt's content — whether accidental or fraudulent — produces a different hash, which breaks the chain. The *next* receipt, whose hash depends on the tampered one, fails server-side verification automatically.
- **Non-repudiation.** A receipt, once written, cannot be silently modified or deleted. Voids and refunds are recorded as *new* fiscal records, never as modifications of previous ones.
- **Forward-only sequence.** Each terminal maintains a monotonic `hash_sequence` integer; receipts cannot be inserted retroactively, and gaps are detected on sync.

### 5.3 Operator-facing consequences of a chain break

If the server detects a chain break, the terminal:
- Displays a **persistent red alert banner** ("Fiscal receipt chain broken — do not take further payments. Contact support.").
- Preserves the offending record for audit (it is never deleted, even after the break is acknowledged).
- Logs the event with timestamp and the last known-good receipt number.

This mechanism is, to our understanding, the equivalent of the French NF525 "inalterability" requirement, which DGFiP accepts as satisfied by chained hashing. **Question 1 above asks whether an equivalent conclusion applies in [Country].**

---

## 6. Confidentiality measures — FDE today, SQLCipher planned

### 6.1 Current state (as of 2026-04-19)

- The local SQLite database is **not** encrypted at the application layer.
- Every deployed terminal has **full-disk encryption (FDE)** enabled: FileVault (macOS), BitLocker (Windows), or LUKS (Linux).
- Operator onboarding includes a hardware-level verification step; no terminal is allowed to enter production until FDE is confirmed enabled and the recovery key is escrowed in a company-managed key-management system.
- Screen lock timeout is ≤ 5 minutes; automatic login is disabled.
- Quarterly audits (by the deployment team) verify that FDE remains enabled on a random 10% sample of deployed terminals.

### 6.2 Planned state (P1)

We plan to add **SQLCipher** (AES-256 application-layer encryption of the SQLite database) as a second line of defence. The work is scoped at 3–5 engineering days and will be scheduled as a function of this opinion:
- If FDE is sufficient for [Country], SQLCipher is deferred.
- If FDE is **not** sufficient, SQLCipher becomes a deployment blocker for [Country].

### 6.3 Threat model

| Threat scenario | Covered by FDE today | SQLCipher would add |
|---|---|---|
| Stolen powered-off terminal | Yes | No marginal benefit |
| Stolen logged-out terminal | Yes | No marginal benefit |
| Stolen logged-in terminal | No | Yes (database file still encrypted at rest) |
| Physical insider access during operating hours | Partially (access controls) | Yes, when POS application is not running |
| Malware on a running terminal | No | No — encryption keys are available to the live process |

FDE addresses the overwhelming majority of real-world data-loss scenarios involving terminal theft. The residual gap is a terminal that is stolen while in active use — a scenario that most regulators treat as live-session compromise rather than data-at-rest failure.

---

## 7. Data inventory

Data stored on the terminal local database:

| Category | Contents | Regulatory sensitivity |
|---|---|---|
| Fiscal records | Receipt number, line items, VAT breakdown, totals, fiscal hash, payment breakdown | Fiscal law (retention + inalterability) |
| Operator credentials | PIN, stored only as a bcrypt hash — plaintext is never persisted | Data protection (identifier); PCI if classified as credential |
| Payment metadata | Payment method ID, last four digits of card, authorization reference | **Not** in PCI DSS scope: we do not store PAN, magnetic stripe, CVV, or PIN blocks. Full card data is handled by an attached, separately certified payment terminal. |
| Customer data | Optional customer name printed on receipt; loyalty-member identifier if applicable | Data protection |
| Banking data | Cash-register and bank-account metadata (internal references, IBAN) | Banking secrecy (national laws) |
| Product catalog | Product names, prices, categories | Commercial sensitivity |

No biometric, health, or other special-category personal data is collected.

---

## 8. Applicable laws we have preliminarily identified

Please confirm, correct, or extend the following for **[Country]**:

- **France:** NF525 cash-register certification; GDPR Articles 32, 33, 34 (security + breach notification).
- **United Kingdom:** HMRC Making Tax Digital record-keeping rules; UK GDPR.
- **Italy:** *Registratore Telematico* (RT) certification; GDPR; Agenzia delle Entrate telematic-transmission rules.
- **Tunisia:** Cash-register fiscal-certification regime (as applicable); Law No. 2004-63 on protection of personal data; INPDP framework.
- **Morocco:** Law 09-08 (protection of personal data), CNDP regulator; ongoing fiscal modernization (SIMPL and related initiatives).
- **Côte d'Ivoire:** Personal-data-protection framework; FNE electronic-invoicing rollout.
- **Kingdom of Saudi Arabia (prospective):** ZATCA (Zakat, Tax and Customs Authority) e-invoicing regulations (Fatoorah, Phase 1 & 2); PDPL (Personal Data Protection Law, 2021) and its implementing regulations; NCA Essential Cybersecurity Controls (ECC) where applicable.
- **United Arab Emirates (prospective):** FTA e-invoicing framework (scheduled); Federal Decree-Law No. 45/2021 on Personal Data Protection; DIFC or ADGM data-protection regulations where a free-zone establishment applies.
- **Egypt (prospective):** ETA (Egyptian Tax Authority) e-invoicing obligations; Law No. 151/2020 on Personal Data Protection.
- **Qatar (prospective):** Law No. 13/2016 on Personal Data Privacy Protection.

---

## 9. Specific concerns on which we particularly invite your view

1. **Recovery-key escrow.** We escrow FDE recovery keys in a company-managed key-management solution. Does this create any additional compliance obligation in [Country] — e.g., a declaration to the data-protection authority, an obligation to store the escrow within national borders, or retention constraints on the escrow itself?

2. **Cross-border sync.** If the terminal operates in [Country] but the central server is in the European Union, does [Country]'s law require fiscal records or personal data to remain physically within national borders (data residency)?

3. **Staff turnover.** When an operator leaves, the PIN is revoked but historical receipts remain attributable to that operator via a stable `operator_id`. Is this consistent with [Country]'s data-minimization requirements, or must the operator's name be pseudonymized after a defined period?

4. **Incident response.** Please confirm the notification timeline and the relevant authority in [Country] for each of:
   - A confirmed chain break suggesting tampering.
   - A lost or stolen terminal with FDE enabled.
   - A lost or stolen terminal with FDE disabled (policy violation on the merchant's part).

---

## 10. Annexes available on request

- **Annex A.** Full technical audit of the encryption-at-rest decision, including the sensitive-columns inventory and the full threat-model analysis. Internal reference: `apps/pos/docs/encryption-at-rest-audit.md`.
- **Annex B.** Manual QA runbook demonstrating the operator-facing chain-break alert and the cold-start terminal bootstrap. Internal reference: `apps/pos/docs/manual-qa-offline-first.md`.
- **Annex C.** Source code of the hash-chain computation and the server-side verification logic. Can be shared under an NDA if relevant to your analysis.

---

## 11. Requested timeline

We would be grateful to receive your written opinion within **[14 calendar days]** of receipt of this brief. If this timeline is not feasible, please indicate the earliest date you can provide a written answer, and whether a preliminary verbal opinion on Question 1 (the fiscal-certification point) is possible in the interim.

If you require additional technical material — a working database sample, a walkthrough of the terminal software, or access to a test server — we can arrange any of these within **[two business days]** of your request.

---

**Point of contact:**

- Name: **[Name]**
- Role: **[Role]**
- Email: **[email]**
- Phone: **[phone]**

**Document classification:** Confidential. Subject to attorney/client privilege or equivalent professional-secrecy regime where applicable in [Country].
