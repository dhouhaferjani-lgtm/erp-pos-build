/**
 * `FiscalEventEngine.append()` — the single device chain authoring path.
 *
 * Authority: spec v7 §6.1 (`append()` pseudocode) + §6.2 (the single device
 * chain head on `terminal_state`) + §4 (the canonical serialization
 * contract). Plan §1075+ (Task 15).
 *
 * Invariants:
 *
 *   - `append()` runs **inside the CALLER's SQLite transaction**. It does
 *     not `BEGIN`, `COMMIT`, or `ROLLBACK`. The caller (the receipt
 *     assembler in Phase 1) wraps `append()` together with its projection
 *     writes — `offline_receipts` row insert, voucher updates — so the
 *     fiscal-event row + the chain-head advance + the business projection
 *     are atomic. If the caller rolls back, nothing persists: no
 *     `fiscal_events` row, no chain-head advance.
 *
 *   - There is **one chain head per terminal**, stored on `terminal_state`
 *     in `fiscal_event_genesis_seed` / `fiscal_event_last_hash` /
 *     `fiscal_event_sequence`. The seed is provisioned at terminal
 *     registration. Until then `fiscal_event_genesis_seed` is the v37
 *     migration default `''` (empty-string sentinel); appending against
 *     that sentinel is structurally invalid and throws
 *     `ChainHeadNotInitializedError`.
 *
 *   - Device-side idempotency on `(source_event_class, source_event_id)`:
 *     if both are set and a local row already exists for that pair, the
 *     pre-existing row is returned without inserting a duplicate and
 *     without advancing the chain head. This lets the assembler safely
 *     retry an emission whose preceding effect already landed (mirrors
 *     the partial UNIQUE index on `fiscal_events`).
 *
 *   - Reserved event types (every `FiscalEventType` not in the Phase-1
 *     implemented set) throw `FiscalEventTypeNotImplementedError` and do
 *     NOT mutate state.
 *
 *   - Canonical payload follows spec §4 verbatim: the fourteen named
 *     fields, sorted-key JCS encoding, NFC + U+2028/U+2029 stripped at
 *     the producer, lowercase-hex SHA-256 of the UTF-8 bytes. Storage
 *     mirrors the encoder output exactly (`canonical_bytes` is persisted
 *     verbatim so the server can re-hash without re-serializing).
 */

import type Database from '@tauri-apps/plugin-sql';

import { bcformat } from '@/lib/decimal';

import type { FiscalEventCanonicalEncoder } from './FiscalEventCanonicalEncoder';
import type { FiscalIntegrityProvider } from './HashChainIntegrityProvider';
import type {
  FiscalEventPayloadRegistry,
  FiscalEventTypeValue,
} from './FiscalEventPayloadRegistry';
import { ACCOUNT_CHARGE_PAYLOAD_KEYS } from './payloads/AccountChargePayload';
import { ACCOUNT_PAYMENT_PAYLOAD_KEYS } from './payloads/AccountPaymentPayload';

/** 64-char lowercase hex — the seed / hash invariant from spec §3.1 + v37. */
const LOWER_HEX_64 = /^[0-9a-f]{64}$/;

/** UTC ISO-8601 second precision — `YYYY-MM-DDTHH:MM:SSZ`. */
const ISO_8601_SECONDS_UTC = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/;

/** `YYYY-MM-DD` calendar date. */
const ISO_8601_DATE = /^\d{4}-\d{2}-\d{2}$/;

/**
 * Thrown when `append()` is called on a terminal whose
 * `fiscal_event_genesis_seed` is not a valid 64-char lowercase hex
 * value — i.e. it is still the v37 migration default `''`, or a
 * known-sentinel placeholder like `'GENESIS'` / `'0'.repeat(64)`, or
 * any non-hex / non-64-char string.
 *
 * The seed is provisioned at terminal registration (server-side
 * device-bootstrap flow); `append()` cannot run until that completes.
 */
export class ChainHeadNotInitializedError extends Error {
  constructor(
    public readonly terminalId: string,
    public readonly observedSeed: string,
  ) {
    super(
      `Fiscal-event chain head not initialized for terminal ${terminalId}: ` +
        `fiscal_event_genesis_seed = ${JSON.stringify(observedSeed)} is not a valid 64-char lowercase hex seed. ` +
        'The seed is provisioned at terminal registration; append() cannot run before then.',
    );
    this.name = 'ChainHeadNotInitializedError';
  }
}

/**
 * Thrown when the caller-supplied payload or envelope fails the
 * Phase 1 canonical-payload contract (spec v7 §4) at the device
 * boundary — BEFORE any canonical_bytes are produced and BEFORE any
 * chain advance.
 *
 * The encoder rejects floats (Task 5 contract), but the spec also
 * requires monetary fields to be **decimal strings**, not integers,
 * and timestamps to be UTC ISO-8601 second precision. The encoder
 * cannot enforce those without payload-type knowledge; the engine
 * does so for the four implemented Phase 1 event types via a thin
 * validation layer. Anything stricter (per-line monetary fields,
 * nested sub-array shapes) is intentionally left to Task 16's
 * server-side `StrictCanonicalParser` — matching the deferral
 * pattern from Task 14 Opus P2-2.
 */
export class FiscalEventPayloadValidationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'FiscalEventPayloadValidationError';
  }
}

/**
 * Thrown when `append()` is called with a server-authored event type
 * (spec v7 §11.0 carve-out). Company-integrity event types
 * (TERMINAL_REGISTRY_SNAPSHOT implemented; COMPANY_DAY_CLOSURE_MANIFEST
 * reserved) are server-authored by construction — they are operator-
 * /company-level facts with no per-device-business trigger. The device
 * MUST refuse to author them so cross-language drift is impossible
 * (Task 14 standing pattern + Task 26 round-2 T26-P3 closure).
 *
 * Distinct from `FiscalEventTypeNotImplementedError` (reserved-not-yet-
 * implemented) — server-only is a different boundary: the type may
 * already be implemented at the registry/DTO level, but the device is
 * not the authoring layer for it. Callers that catch this error should
 * route the authoring request to the server-side emitter
 * (`TerminalRegistrySnapshotService` for TRS) rather than the engine.
 */
export class ServerAuthoredEventTypeError extends Error {
  constructor(public readonly type: string) {
    super(
      `FiscalEventType ${type} is a server-authored company-integrity event (spec v7 §11.0); ` +
        'the device-side FiscalEventEngine.append() does not author it. ' +
        'Route the request to the server-side emitter (e.g. TerminalRegistrySnapshotService).',
    );
    this.name = 'ServerAuthoredEventTypeError';
  }
}

/**
 * Thrown when an implemented event type is submitted to the wrong fiscal
 * chain. Z/session events must never leak onto the operational receipt chain,
 * and training payloads must consume only training sequence numbers.
 */
export class FiscalEventChainContextError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'FiscalEventChainContextError';
  }
}

/**
 * Thrown when the chain INSERT trips the v37 partial UNIQUE on
 * `(tenant_id, terminal_id, sequence_number)` — i.e. a parallel
 * `append()` already advanced this chain head between our `readChainHead`
 * and our INSERT. The chain is unbroken; the caller should re-read the
 * head and retry, or escalate to a `CHAIN_BREAK_DETECTED` if the
 * race is structurally unexpected.
 */
export class ConcurrentChainAdvanceError extends Error {
  constructor(
    public readonly terminalId: string,
    public readonly attemptedSequence: number,
    public readonly cause: unknown,
  ) {
    super(
      `Concurrent chain advance on terminal ${terminalId} at sequence ${attemptedSequence}: ` +
        'another transaction already inserted this (terminal_id, sequence_number) pair. Re-read the head and retry.',
    );
    this.name = 'ConcurrentChainAdvanceError';
  }
}

/**
 * Caller-supplied authorship request.
 *
 * `payload` is opaque to `append()` — the caller assembles the business
 * document (lines, totals, payments, etc.) and supplies it as `payload`;
 * the encoder hashes whatever object is provided. Money values must be
 * decimal strings (the encoder rejects floats).
 */
export interface FiscalEventAppendRequest {
  event_type: FiscalEventTypeValue;
  chain_context?: FiscalChainContext;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  /** UTC ISO-8601 second precision (e.g. `'2026-05-16T10:00:00Z'`). */
  event_time_device: string;
  /** Local business date — `'YYYY-MM-DD'`. */
  business_date: string;
  /** The opaque business-document payload (hashed verbatim). */
  payload: unknown;
  reference_document_id?: string;
  reference_event_id?: string;
  /**
   * Device-side idempotency key. When both fields are set, a duplicate
   * call against the same `(tenant_id, terminal_id, source_event_class,
   * source_event_id)` 4-tuple returns the pre-existing row. The lookup
   * is scoped per-(tenant, terminal) so a generator that reuses ids
   * across terminals (e.g. per-terminal sequential receipt numbers)
   * cannot accidentally idempotent-deduplicate across terminals.
   */
  source_event_class?: string;
  source_event_id?: string;
}

export const FISCAL_CHAIN_CONTEXTS = [
  'operational',
  'z_session',
  'training_operational',
  'training_z_session',
] as const;

export type FiscalChainContext = (typeof FISCAL_CHAIN_CONTEXTS)[number];

const OPERATIONAL_CHAIN_EVENT_TYPES = new Set<FiscalEventTypeValue>([
  'SALE_RECEIPT',
  'ACCOUNT_PAYMENT',
  'ACCOUNT_CHARGE',
  'CHAIN_BREAK_DETECTED',
  'CHAIN_RESTART',
  'OPERATOR_APPROVAL_GRANTED',
  'OVERRIDE_CREDIT_LIMIT',
  'OVERRIDE_ACCOUNT_STATUS',
  'OVERRIDE_DISCOUNT_LIMIT',
  'OVERRIDE_TENDER_TOLERANCE',
  'OVERRIDE_VOID_OR_RETURN',
  // Phase 4 pre-cutover legacy drawer evidence events. Cutover Z-session
  // movements use the context-sensitive Z payload validator below.
  'CASH_OUT',
  'SAFE_DROP',
]);

const Z_SESSION_CHAIN_EVENT_TYPES = new Set<FiscalEventTypeValue>([
  'SESSION_OPEN',
  'OPENING_FLOAT',
  'CASH_IN',
  'CASH_OUT',
  'SAFE_DROP',
  'CASH_CORRECTION',
  'SESSION_CLOSE',
  'X_REPORT',
  'Z_REPORT',
]);

// -------------------------------------------------------------------
// Typed payload-input interfaces (Phase 1 implemented event types).
//
// Compile-time defense — TS strict callers that build a typed input
// object cannot pass `total: 10` (number) where `total: '10.000'`
// (decimal string) is required. The interfaces mirror the server-side
// PHP DTOs in `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/*.php`
// but at the device side every monetary field is `string` — never
// `number`.
//
// The engine's runtime validator (`validateSaleReceiptPayload` etc.)
// is the second defense: it enforces structural conformance (key set
// + types + regex + enums + nested-object shape) BEFORE encoding the
// canonical bytes. The VAT partition algorithm + invoice-total
// arithmetic cross-checks stay server-side per synthesis v5 §6.F.
// -------------------------------------------------------------------

// -------------------------------------------------------------------
// Task 27B Pass 2A.TS — 28-key canonical SALE_RECEIPT shape (Candidate
// C-v3) per synthesis v5 §3. Mirrors the PHP DTO triad in
// `apps/api/app/Modules/Fiscal/Domain/DTOs/Canonical/*.php`. Every
// monetary / quantity field is `string` (bcformat at the relevant
// scale). Nested blocks use typed interfaces (no anonymous `Record`
// shapes) so TS-strict callers get compile-time defense against
// dropping required keys or mistyping nested fields.
//
// Pass 2B wires `receiptService.ts` to construct this shape via the engine.
// `check-pass-2b-pending.sh` now stays quiet because the sequencing marker was
// removed atomically with that checkout wiring.
// -------------------------------------------------------------------

/** ISO 3166-1 alpha-2 country code (`"FR"`, `"TN"`, `"SA"`, `"DE"`, `"IT"`). */
export interface AddressInput {
  readonly city: string;
  readonly country_code: string;
  readonly postal_code: string;
  readonly street: string;
}

/** Sale-time buyer snapshot (synthesis v5 §5 / v3 §3 lines 113-120). */
export interface BuyerBlockInput {
  readonly address: AddressInput | null;
  /** IT codice fiscale (future). */
  readonly codice_fiscale: string | null;
  /** POS-local mirror reference; non-authoritative once the receipt is sealed. */
  readonly contact_id: string | null;
  /** POS-local mirror reference; non-authoritative once the receipt is sealed. */
  readonly customer_id: string | null;
  readonly name: string | null;
  /** Optional B2C tax number — universal baseline plus Phase 1.5.2 country regex table. */
  readonly tax_number: string | null;
}

/** Required seller block (NF525 / TN matricule / KSA VAT / IT P.IVA / DE USt-ID). */
export interface SellerBlockInput {
  readonly address: AddressInput;
  readonly name: string;
  /** ISO 3166-1 alpha-2. */
  readonly tax_jurisdiction_country_code: string;
  /** Universal baseline plus Phase 1.5.2 country regex table. */
  readonly tax_number: string;
}

/** One line item in `line_items[]`. */
export interface LineItemInput {
  /** DSFinV-K Bonpos GTIN (future). Non-empty string or null. */
  readonly gtin: string | null;
  /** Money at `currency_scale`; non-negative. */
  readonly line_discount_amount: string;
  /** Required iff `line_discount_amount > "0"`, null otherwise. */
  readonly line_discount_reason: string | null;
  /** Net (pre-VAT) line subtotal; money at `currency_scale`. */
  readonly line_subtotal: string;
  /** Line VAT amount; money at `currency_scale`. */
  readonly line_vat: string;
  readonly name: string;
  /** IT future — null in Phase 1 unless the IT axis is in play. */
  readonly non_collected_subtype: 'servizi' | 'beni' | 'omaggio' | 'successiva' | null;
  readonly product_id: string;
  /** Money at `quantity_scale` (fixed at 3 in Phase 1). */
  readonly quantity: string;
  readonly sku: string;
  /** Unified KSA BT-151 / IT Natura axis. `""` = standard taxable. */
  readonly tax_category_code: string;
  /** Money at `currency_scale`. */
  readonly unit_price: string;
  /** Percent at `vat_rate_scale` (fixed at 2 in Phase 1; `"20.00"`, `"5.50"`). */
  readonly vat_rate: string;
}

/** One row in `payments[]`. */
export interface PaymentInput {
  /** Money at `currency_scale` (in `currency_code`). */
  readonly amount: string;
  /** When non-null: money at the foreign currency's scale; pair with `foreign_currency_code`. */
  readonly foreign_currency_amount: string | null;
  /** ISO 4217 alpha-3 uppercase. Paired with `foreign_currency_amount`. */
  readonly foreign_currency_code: string | null;
  /** Voucher serial, card last-4, etc. */
  readonly instrument_serial: string | null;
  readonly instrument_type: string | null;
  /** UN/ECE 4461 mapped at the export adapter; opaque at the payload boundary. */
  readonly method_code: string;
}

/** One row in `vat_breakdown[]` — partition of `line_items[]` by `(vat_rate, tax_category_code)`. */
export interface VatBreakdownInput {
  /** Money at `currency_scale`. */
  readonly gross_amount: string;
  /** Money at `currency_scale`. */
  readonly net_amount: string;
  /** Percent at `vat_rate_scale`. */
  readonly rate: string;
  /** Unified axis — must match the line-level value for partition correctness. */
  readonly tax_category_code: string;
  /** Money at `currency_scale`. */
  readonly vat_amount: string;
}

/** Refund / void link — required iff `invoice_type_code in {REFUND, VOID}`. */
export interface OriginalReceiptReferenceInput {
  /** UUID of the original SALE_RECEIPT fiscal event. */
  readonly fiscal_event_id: string;
  /** `YYYY-MM-DD`. */
  readonly original_business_date: string;
  /** UUID of the original receipt. */
  readonly original_receipt_uuid: string;
  readonly refund_reason: string;
}

/** One row in `vouchers_redeemed[]`. */
export interface VoucherRedeemedInput {
  /** Money at `currency_scale`. */
  readonly redeemed_amount: string;
  readonly voucher_code: string;
}

/**
 * SALE_RECEIPT canonical payload — 27 top-level keys, sorted lex per
 * Codex P3. Synthesis v5 §3 + spec v8 §11.2. The TS engine's runtime
 * validator (`validateSaleReceiptPayload`) enforces STRUCTURAL
 * conformance (key set + types + regex + enums). The PARTITION
 * algorithm + total arithmetic checks stay server-side per v5 §6.F
 * (the PHP `FiscalPayloadConstraintValidator` is authoritative there).
 */
export interface SaleReceiptPayloadInput {
  readonly approval_references: ReadonlyArray<SaleReceiptApprovalReferenceInput>;
  /** `YYYY-MM-DD`. */
  readonly business_date: string;
  readonly buyer: BuyerBlockInput | null;
  /** UUID. */
  readonly cashier_id: string;
  readonly cashier_name: string;
  readonly consumption_mode: 'dine_in' | 'takeaway' | null;
  /** ISO 4217 alpha-3 uppercase (`"EUR"`, `"TND"`, `"SAR"`, `"USD"`, `"GBP"`). */
  readonly currency_code: string;
  /** Allowlist `{0, 2, 3}`. */
  readonly currency_scale: number;
  /** ISO 8601 with ms + tz offset (`...T10:00:00.000Z` or `...+02:00`). */
  readonly event_time_device: string;
  readonly invoice_type_code: 'SALE' | 'REFUND' | 'VOID' | 'TRAINING';
  readonly line_items: ReadonlyArray<LineItemInput>;
  /** IT codice lotteria (future). */
  readonly lottery_code: string | null;
  readonly notes: string | null;
  readonly original_receipt_reference: OriginalReceiptReferenceInput | null;
  readonly payments: ReadonlyArray<PaymentInput>;
  /** UUID — device-authored. */
  readonly receipt_uuid: string;
  readonly seller: SellerBlockInput;
  /** UUID. */
  readonly shift_id: string;
  /** Money at `currency_scale`; net of VAT. */
  readonly subtotal: string;
  /** DSFinV-K ABRECHNUNGSKREIS (future). */
  readonly table_id: string | null;
  /** UUID. */
  readonly terminal_id: string;
  /** Money at `currency_scale`; gross (incl. VAT). At v3 this is the ROUNDED total. */
  readonly total: string;
  /** v3: signed `rounded_total - exact_total` at `currency_scale`. */
  readonly cash_rounding_adjustment?: string;
  /** v3: non-negative rounding step at `currency_scale`. */
  readonly cash_rounding_denomination?: string;
  /** Must equal `invoice_type_code === 'TRAINING'`. */
  readonly training_flag: boolean;
  /** Money at `currency_scale`; non-negative (no surcharge case). */
  readonly transaction_discount_amount: string;
  /** Required iff `transaction_discount_amount > "0"`, null otherwise. */
  readonly transaction_discount_reason: string | null;
  readonly vat_breakdown: ReadonlyArray<VatBreakdownInput>;
  /** Money at `currency_scale`. */
  readonly vat_total: string;
  readonly vouchers_redeemed: ReadonlyArray<VoucherRedeemedInput>;
}

export interface SaleReceiptApprovalReferenceInput {
  readonly approval_event_id: string;
  readonly approval_id: string;
  readonly approval_scope: 'discount_limit_override' | 'tender_tolerance_override' | 'void_or_return_override';
  readonly override_event_id: string;
  readonly policy_version: string;
  readonly supervisor_user_id: string;
  readonly target_reference_id: string;
}

export interface ChainBreakDetectedPayloadInput {
  readonly reason: string;
  readonly last_good_sequence: number;
  readonly last_good_hash: string;
  readonly offending_record_reference: Record<string, unknown>;
}

export interface ChainRestartPayloadInput {
  readonly new_genesis_reference: string;
  readonly last_good_anchor: Record<string, unknown>;
  readonly operator_authorization_evidence: Record<string, unknown>;
  readonly provenance_link: Record<string, unknown>;
}

export interface TerminalRegistrySnapshotPayloadInput {
  readonly terminals: ReadonlyArray<Record<string, unknown>>;
  readonly snapshot_hash: string;
  readonly prior_snapshot_link: string | null;
}

export interface FiscalEventAppendResult {
  id: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  event_type: FiscalEventTypeValue;
  chain_context: FiscalChainContext;
  event_version: number;
  signature_version: string;
  sequence_number: number;
  event_time_device: string;
  business_date: string;
  reference_event_id: string | null;
  reference_document_id: string | null;
  source_event_class: string | null;
  source_event_id: string | null;
  canonical_bytes: string;
  previous_hash: string;
  current_hash: string;
  sync_status: 'pending';
  signature_status: 'not_required';
  created_at: string;
}

/**
 * Minimal subset of the `@tauri-apps/plugin-sql` Database surface
 * `append()` needs. Production callers pass a `Database`; tests pass the
 * `SqliteTestAdapter` which structurally satisfies this surface. The
 * caller controls the surrounding transaction with explicit `BEGIN` /
 * `COMMIT` / `ROLLBACK` `execute()` calls.
 *
 * Exported (Task 25) so sibling services that delegate to
 * `engine.append()` — like `ChainRecoveryService` — share the same
 * relaxed-but-typed handle shape.
 */
export interface SqlSurface {
  execute(sql: string, params?: unknown[]): Promise<{ rowsAffected: number; lastInsertId?: number }>;
  select<T>(sql: string, params?: unknown[]): Promise<T>;
}

/** Narrow `Database` / adapter to the structural surface we need. */
function asSql(tx: Database | SqlSurface): SqlSurface {
  // Database from `@tauri-apps/plugin-sql` exposes the same execute/select
  // shape; runtime type is whatever the caller threads in.
  return tx as unknown as SqlSurface;
}

export class FiscalEventEngine {
  constructor(
    private readonly defaultDb: Database | SqlSurface,
    private readonly encoder: FiscalEventCanonicalEncoder,
    private readonly integrityProvider: FiscalIntegrityProvider,
    private readonly registry: FiscalEventPayloadRegistry,
  ) {}

  /**
   * Author one fiscal event. Runs inside the caller's SQLite transaction
   * — never commits. See spec §6.1.
   *
   * @param tx The transactional handle the caller has already opened. In
   *           tests the adapter doubles as `tx`; in production this is
   *           the same `Database` reference around which the caller has
   *           issued `BEGIN`.
   */
  async append(
    tx: Database | SqlSurface,
    request: FiscalEventAppendRequest,
  ): Promise<FiscalEventAppendResult> {
    const sql = asSql(tx);

    // Step -1 (spec v7 §11.0 server-authoring carve-out enforcement) —
    // refuse to author company-integrity event types. The device is not
    // the authoring layer for `TERMINAL_REGISTRY_SNAPSHOT` /
    // `COMPANY_DAY_CLOSURE_MANIFEST`; they are server-authored facts
    // (operator-level, no per-device trigger). This rejection runs
    // BEFORE the registry's not-implemented check so the boundary is
    // locked uniformly for both implemented (TRS) and reserved
    // (COMPANY_DAY_CLOSURE_MANIFEST) server-only types — cross-language
    // drift gate per Task 14 standing pattern, Task 26 round-2 T26-P3
    // closure.
    if (this.registry.isServerOnly(request.event_type)) {
      throw new ServerAuthoredEventTypeError(request.event_type);
    }

    // Step 0 (defense-in-depth) — validate the envelope + payload BEFORE
    // any state read or mutation. Closes Task 15 round-2 Codex BLOCKER
    // (opaque payload lets non-string money into immutable canonical
    // bytes) + P1-1 (timestamp / business_date format not enforced).
    // Throws FiscalEventPayloadValidationError on contract violation.
    this.validateRequestEnvelope(request);
    const chainContext = request.chain_context ?? 'operational';

    // Step 1 — resolve event_version via the registry. Reserved types
    // throw before any state mutation. v3-refund-chain-integration spec
    // §2 — the payload is threaded through so SALE_RECEIPT resolves 3
    // (sale/training) vs 4 (refund) vs a hard VOID refusal; every other
    // type ignores the payload argument and keeps its fixed version.
    const eventVersion = this.registry.eventVersionFor(request.event_type, request.payload);
    this.validateChainContext(request, chainContext);
    this.validateRequestPayload(request, chainContext, eventVersion);

    // Step 2 — device-side idempotency on (tenant_id, terminal_id,
    // source_event_class, source_event_id). The lookup is scoped
    // per-(tenant, terminal) so a generator that reuses source ids
    // across terminals cannot accidentally cross-terminal-pollute the
    // idempotent path (Task 15 round-2 Codex P2-1).
    if (request.source_event_class != null && request.source_event_id != null) {
      const existing = await this.findBySource(
        sql,
        request.tenant_id,
        request.terminal_id,
        request.source_event_class,
        request.source_event_id,
      );
      if (existing) {
        return existing;
      }
    }

    // Step 3 — read the single chain head from terminal_state. Refuse to
    // append unless `fiscal_event_genesis_seed` is a valid 64-char
    // lowercase hex value (Task 13 Codex P3 forward-looking input +
    // Task 15 round-2 Codex P3 hardening — also rejects 'GENESIS',
    // '0'.repeat(64), and any non-hex sentinel).
    const head = await this.readChainHead(
      sql,
      request.tenant_id,
      request.terminal_id,
      chainContext,
    );
    if (!LOWER_HEX_64.test(head.fiscal_event_genesis_seed)) {
      throw new ChainHeadNotInitializedError(
        request.terminal_id,
        head.fiscal_event_genesis_seed,
      );
    }
    const previousHash =
      head.fiscal_event_sequence > 0
        ? head.fiscal_event_last_hash
        : head.fiscal_event_genesis_seed;
    const sequenceNumber = head.fiscal_event_sequence + 1;

    // Step 4 — build canonical_bytes per spec §4 and hash via the
    // integrity provider (HashChainIntegrityProvider in Phase 1).
    const signatureVersion = this.integrityProvider.version();
    const canonicalPayload = {
      business_date: request.business_date,
      chain_context: chainContext,
      company_id: request.company_id,
      event_time_device: request.event_time_device,
      event_type: request.event_type,
      event_version: eventVersion,
      operator_id: request.operator_id,
      payload: request.payload,
      previous_hash: previousHash,
      reference_document_id: request.reference_document_id ?? null,
      reference_event_id: request.reference_event_id ?? null,
      sequence_number: sequenceNumber,
      signature_version: signatureVersion,
      tenant_id: request.tenant_id,
      terminal_id: request.terminal_id,
    };
    const canonicalBytes = this.encoder.encode(canonicalPayload);
    const currentHash = this.integrityProvider.computeHash(canonicalBytes);

    const id = generateUuidV4();
    const createdAt = isoSecondsUtc(new Date());

    // Step 5 — INSERT the fiscal_events row. sync_status='pending',
    // signature_status='not_required'. Parallel `append()`s on the same
    // terminal that race past `readChainHead` and re-INSERT the same
    // sequence_number trip the v37 chain UNIQUE; surface that as a
    // typed `ConcurrentChainAdvanceError` rather than a raw SQLITE
    // constraint message (Task 15 round-2 Codex P2-2).
    try {
      await sql.execute(
        `INSERT INTO fiscal_events (
           id, tenant_id, company_id, terminal_id, operator_id,
           event_type, event_version, signature_version,
           sequence_number, event_time_device, business_date, chain_context,
           reference_event_id, reference_document_id,
           source_event_class, source_event_id,
           canonical_bytes, previous_hash, current_hash,
           signature_status, sync_status, created_at
         ) VALUES (
           $1, $2, $3, $4, $5,
           $6, $7, $8,
           $9, $10, $11, $12,
           $13, $14,
           $15, $16,
           $17, $18, $19,
           'not_required', 'pending', $20
         )`,
        [
          id,
          request.tenant_id,
          request.company_id,
          request.terminal_id,
          request.operator_id,
          request.event_type,
          eventVersion,
          signatureVersion,
          sequenceNumber,
          request.event_time_device,
          request.business_date,
          chainContext,
          request.reference_event_id ?? null,
          request.reference_document_id ?? null,
          request.source_event_class ?? null,
          request.source_event_id ?? null,
          canonicalBytes,
          previousHash,
          currentHash,
          createdAt,
        ],
      );
    } catch (error) {
      if (isChainSequenceUniqueViolation(error)) {
        throw new ConcurrentChainAdvanceError(request.terminal_id, sequenceNumber, error);
      }
      throw error;
    }

    // Step 6 — advance the chain head. Same `tx` so the advance is
    // atomic with the insert.
    await sql.execute(
      this.chainHeadUpdateSql(chainContext),
      [currentHash, sequenceNumber, request.terminal_id],
    );

    return {
      id,
      tenant_id: request.tenant_id,
      company_id: request.company_id,
      terminal_id: request.terminal_id,
      operator_id: request.operator_id,
      event_type: request.event_type,
      chain_context: chainContext,
      event_version: eventVersion,
      signature_version: signatureVersion,
      sequence_number: sequenceNumber,
      event_time_device: request.event_time_device,
      business_date: request.business_date,
      reference_event_id: request.reference_event_id ?? null,
      reference_document_id: request.reference_document_id ?? null,
      source_event_class: request.source_event_class ?? null,
      source_event_id: request.source_event_id ?? null,
      canonical_bytes: canonicalBytes,
      previous_hash: previousHash,
      current_hash: currentHash,
      sync_status: 'pending',
      signature_status: 'not_required',
      created_at: createdAt,
    };
  }

  // -------------------------------------------------------------------
  // Internals
  // -------------------------------------------------------------------

  /**
   * Re-fetch an existing fiscal-event row for an idempotent source-backed
   * emission. The lookup is scoped to `(tenant_id, terminal_id,
   * source_event_class, source_event_id)` — Task 15 round-2 Codex P2-1
   * closure. A source id of `'r-1'` on terminal A is a DIFFERENT idempotency
   * key from `'r-1'` on terminal B even though the v37 partial UNIQUE
   * index does not include `terminal_id`; cross-terminal collisions
   * (which should not happen in practice) fall through to the INSERT
   * which then fails the partial UNIQUE and surfaces as a recoverable
   * chain incident rather than a silent cross-terminal idempotent match.
   *
   * Returns the same shape as a fresh append so the caller does not
   * need to branch on which path produced the result.
   */
  private async findBySource(
    sql: SqlSurface,
    tenantId: string,
    terminalId: string,
    sourceClass: string,
    sourceId: string,
  ): Promise<FiscalEventAppendResult | null> {
    const rows = await sql.select<FiscalEventRowShape[]>(
      `SELECT id, tenant_id, company_id, terminal_id, operator_id,
              event_type, event_version, signature_version,
              sequence_number, event_time_device, business_date, chain_context,
              reference_event_id, reference_document_id,
              source_event_class, source_event_id,
              canonical_bytes, previous_hash, current_hash,
              signature_status, sync_status, created_at
         FROM fiscal_events
        WHERE tenant_id = $1
          AND terminal_id = $2
          AND source_event_class = $3
          AND source_event_id = $4
        LIMIT 1`,
      [tenantId, terminalId, sourceClass, sourceId],
    );
    const row = rows[0];
    if (!row) return null;
    return rowToResult(row);
  }

  /**
   * Envelope-level format validation — UTC ISO-8601 second precision on
   * `event_time_device`, `YYYY-MM-DD` on `business_date`. Closes Task 15
   * round-2 Codex P1-1 (caller could supply millisecond precision or a
   * non-UTC offset; the engine would copy the malformed value into
   * canonical_bytes verbatim, producing non-spec hashes the server
   * would later quarantine).
   */
  private validateRequestEnvelope(request: FiscalEventAppendRequest): void {
    if (!ISO_8601_SECONDS_UTC.test(request.event_time_device)) {
      throw new FiscalEventPayloadValidationError(
        `event_time_device must be UTC ISO-8601 second precision (YYYY-MM-DDTHH:MM:SSZ); got ${JSON.stringify(request.event_time_device)}.`,
      );
    }
    if (!ISO_8601_DATE.test(request.business_date)) {
      throw new FiscalEventPayloadValidationError(
        `business_date must be YYYY-MM-DD; got ${JSON.stringify(request.business_date)}.`,
      );
    }
  }

  private validateChainContext(
    request: FiscalEventAppendRequest,
    chainContext: FiscalChainContext,
  ): void {
    const isZSessionContext = chainContext === 'z_session' || chainContext === 'training_z_session';
    const allowedTypes = isZSessionContext ? Z_SESSION_CHAIN_EVENT_TYPES : OPERATIONAL_CHAIN_EVENT_TYPES;
    if (!allowedTypes.has(request.event_type)) {
      throw new FiscalEventChainContextError(
        `Fiscal event type ${request.event_type} is not allowed on ${chainContext} chain_context.`,
      );
    }

    const trainingFlag = payloadTrainingFlag(request.payload);
    if (trainingFlag === null) return;

    const isTrainingContext =
      chainContext === 'training_operational' || chainContext === 'training_z_session';
    if (trainingFlag && !isTrainingContext) {
      throw new FiscalEventChainContextError(
        `payload training_flag=true requires training_* chain_context; got ${chainContext}.`,
      );
    }
    if (!trainingFlag && isTrainingContext) {
      throw new FiscalEventChainContextError(
        `payload training_flag=false requires production chain_context; got ${chainContext}.`,
      );
    }
  }

  /**
   * Per-event-type payload validation — STRUCTURAL conformance only:
   * key set + types + regex + enums + nested-object shape. The encoder
   * already rejects floats; this layer additionally rejects
   * non-bcformat-conformant money values, malformed UUIDs / ISO 8601
   * datetimes, out-of-domain enum values, etc. Closes Task 15 round-2
   * Codex BLOCKER + P1-1.
   *
   * **Task 27B Pass 2A.TS:** SALE_RECEIPT now validates the full 28-key
   * Candidate C-v3 shape (synthesis v5 §3) — including nested seller /
   * buyer / line_items / payments / vat_breakdown blocks, the
   * training-flag invariant, discount-reason consistency, and
   * foreign-currency pairing. The VAT partition algorithm + invoice-
   * total arithmetic stay server-side per v5 §6.F (the PHP
   * `FiscalPayloadConstraintValidator::validateVatPartition()` +
   * total-arithmetic cross-check are the authoritative enforcement
   * points; the TS validator deliberately stops at structural
   * conformance to avoid duplicating BCMath behavior in JS).
   *
   * **Round-2 (Task 25 Codex T25-P2 closure):** CHAIN_BREAK_DETECTED +
   * CHAIN_RESTART get runtime validation that MIRRORS the PHP
   * `FiscalPayloadConstraintValidator` constraint set EXACTLY — 64-char
   * lowercase hex on hash fields, non-empty assoc on the four
   * non-empty-object fields. Cross-language drift gate (Task 14 standing
   * pattern): TS validation must reject what PHP rejects.
   *
   * **Task 26 round-2 (T26-P3 closure):** `TERMINAL_REGISTRY_SNAPSHOT`
   * + `COMPANY_DAY_CLOSURE_MANIFEST` are spec §11.0 server-only and
   * rejected at the Step -1 boundary BEFORE this validator runs — so
   * those branches never execute here.
   */
  private validateRequestPayload(
    request: FiscalEventAppendRequest,
    chainContext: FiscalChainContext,
    eventVersion: number,
  ): void {
    switch (request.event_type) {
      case 'SALE_RECEIPT':
        validateSaleReceiptPayload(request.payload, eventVersion);
        return;
      case 'ACCOUNT_PAYMENT':
        validateAccountPaymentPayload(request.payload);
        return;
      case 'ACCOUNT_CHARGE':
        validateAccountChargePayload(request.payload);
        return;
      case 'CHAIN_BREAK_DETECTED':
        validateChainBreakDetectedPayload(request.payload);
        return;
      case 'CHAIN_RESTART':
        validateChainRestartPayload(request.payload);
        return;
      case 'ACCOUNT_STATUS_CHANGED':
        validateAccountStatusChangedPayload(request.payload);
        return;
      case 'OPERATOR_APPROVAL_GRANTED':
        validateOperatorApprovalGrantedPayload(request.payload);
        return;
      case 'OVERRIDE_CREDIT_LIMIT':
      case 'OVERRIDE_ACCOUNT_STATUS':
      case 'OVERRIDE_DISCOUNT_LIMIT':
      case 'OVERRIDE_TENDER_TOLERANCE':
      case 'OVERRIDE_VOID_OR_RETURN':
        validatePhase4OverridePayload(request.payload);
        return;
      case 'CASH_OUT':
      case 'SAFE_DROP':
        if (chainContext === 'z_session' || chainContext === 'training_z_session') {
          validateZCashDrawerMovementPayload(request.payload);
        } else {
          validateCashDrawerMovementPayload(request.payload);
        }
        return;
      case 'OPENING_FLOAT':
      case 'CASH_IN':
      case 'CASH_CORRECTION':
        validateZCashDrawerMovementPayload(request.payload);
        return;
      case 'SESSION_OPEN':
        validateSessionOpenPayload(request.payload);
        return;
      case 'SESSION_CLOSE':
        validateZReportFamilyPayload(request.payload, SESSION_CLOSE_PAYLOAD_KEYS, 'SESSION_CLOSE');
        return;
      case 'X_REPORT':
        validateZReportFamilyPayload(request.payload, X_REPORT_PAYLOAD_KEYS, 'X_REPORT');
        return;
      case 'Z_REPORT':
        validateZReportFamilyPayload(request.payload, Z_REPORT_PAYLOAD_KEYS, 'Z_REPORT');
        return;
      default:
        // Server-only types (§11.0) are rejected at Step -1.
        // Reserved-not-implemented types are rejected by the registry
        // in Step 1. This branch is a defensive fallthrough that should
        // be unreachable in practice.
        return;
    }
  }

  private async readChainHead(
    sql: SqlSurface,
    tenantId: string,
    terminalId: string,
    chainContext: FiscalChainContext,
  ): Promise<{
    fiscal_event_genesis_seed: string;
    fiscal_event_last_hash: string;
    fiscal_event_sequence: number;
  }> {
    const columns = this.chainHeadColumns(chainContext);
    const rows = await sql.select<
      Array<{
        fiscal_event_genesis_seed: unknown;
        fiscal_event_last_hash: unknown;
        fiscal_event_sequence: unknown;
      }>
    >(
      `SELECT ${columns.genesis} AS fiscal_event_genesis_seed,
              ${columns.lastHash} AS fiscal_event_last_hash,
              ${columns.sequence} AS fiscal_event_sequence
         FROM terminal_state
        WHERE terminal_id = $1`,
      [terminalId],
    );
    const row = rows[0];
    if (!row) {
      // Tenant scope is enforced by the caller / Spatie teams middleware in
      // production; this guard catches the truly-missing-terminal_state
      // case (e.g. an unprovisioned device).
      throw new Error(
        `terminal_state row not found for terminal ${terminalId} (tenant ${tenantId})`,
      );
    }
    if (
      typeof row.fiscal_event_genesis_seed !== 'string' ||
      typeof row.fiscal_event_last_hash !== 'string' ||
      typeof row.fiscal_event_sequence !== 'number'
    ) {
      throw new Error(
        `terminal_state chain-head columns have unexpected types for terminal ${terminalId}`,
      );
    }
    return {
      fiscal_event_genesis_seed: row.fiscal_event_genesis_seed,
      fiscal_event_last_hash: row.fiscal_event_last_hash,
      fiscal_event_sequence: row.fiscal_event_sequence,
    };
  }

  private chainHeadColumns(chainContext: FiscalChainContext): {
    genesis: string;
    lastHash: string;
    sequence: string;
  } {
    switch (chainContext) {
      case 'operational':
        return {
          genesis: 'fiscal_event_genesis_seed',
          lastHash: 'fiscal_event_last_hash',
          sequence: 'fiscal_event_sequence',
        };
      case 'z_session':
        return {
          genesis: 'z_chain_genesis_seed',
          lastHash: 'z_chain_last_hash',
          sequence: 'z_chain_sequence',
        };
      case 'training_operational':
        return {
          genesis: 'training_fiscal_event_genesis_seed',
          lastHash: 'training_fiscal_event_last_hash',
          sequence: 'training_fiscal_event_sequence',
        };
      case 'training_z_session':
        return {
          genesis: 'training_z_chain_genesis_seed',
          lastHash: 'training_z_chain_last_hash',
          sequence: 'training_z_chain_sequence',
        };
    }
  }

  private chainHeadUpdateSql(chainContext: FiscalChainContext): string {
    const columns = this.chainHeadColumns(chainContext);
    return `UPDATE terminal_state
               SET ${columns.lastHash} = $1,
                   ${columns.sequence} = $2
             WHERE terminal_id = $3`;
  }

  /** Expose the default DB handle for callers that want a non-tx convenience. */
  protected get db(): SqlSurface {
    return asSql(this.defaultDb);
  }
}

// -------------------------------------------------------------------
// Payload-shape validators for the four Phase 1 implemented event types.
// Top-level invariants only; per-line and nested sub-array shape stays
// the server-side StrictCanonicalParser's job (Task 16, Task 14 Opus P2-2).
// -------------------------------------------------------------------

/**
 * Allowed top-level payload keys per event type. Mirrors PHP
 * `FiscalPayloadConstraintValidator::PAYLOAD_KEYS` byte-for-byte —
 * Task 14 cross-language drift gate (Task 25 round-3 Codex P1 closure,
 * Task 27B Pass 2A.TS for SALE_RECEIPT).
 *
 * The PHP authority lives at
 * `apps/api/app/Modules/Fiscal/Application/Services/FiscalPayloadConstraintValidator.php`
 * (`PAYLOAD_KEYS` const). Any change there MUST land here in the same commit.
 *
 * Exported so the cross-language drift gate test can assert byte-mirror
 * equality without re-implementing the key list.
 */
export const SALE_RECEIPT_PAYLOAD_KEYS = [
  'approval_references',
  'business_date',
  'buyer',
  'cashier_id',
  'cashier_name',
  'consumption_mode',
  'currency_code',
  'currency_scale',
  'event_time_device',
  'invoice_type_code',
  'line_items',
  'lottery_code',
  'notes',
  'original_receipt_reference',
  'payments',
  'receipt_uuid',
  'seller',
  'shift_id',
  'subtotal',
  'table_id',
  'terminal_id',
  'total',
  'training_flag',
  'transaction_discount_amount',
  'transaction_discount_reason',
  'vat_breakdown',
  'vat_total',
  'vouchers_redeemed',
] as const;

/**
 * SALE_RECEIPT v3 top-level key set (cash rounding, spec §4.4). 30 keys =
 * the 28 v1/v2 keys plus `cash_rounding_adjustment` +
 * `cash_rounding_denomination`, which sort between `buyer` and `cashier_id`.
 *
 * The PHP authority is
 * `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V3`; the
 * FiscalPayloadKeyDrift gate pins the two lists (and this list's
 * sortedness) against each other.
 *
 * A NAMED constant, never a mutation of `SALE_RECEIPT_PAYLOAD_KEYS` — the
 * v1/v2 record stays frozen at 28 keys forever.
 */
export const SALE_RECEIPT_PAYLOAD_KEYS_V3 = [
  'approval_references',
  'business_date',
  'buyer',
  'cash_rounding_adjustment',
  'cash_rounding_denomination',
  'cashier_id',
  'cashier_name',
  'consumption_mode',
  'currency_code',
  'currency_scale',
  'event_time_device',
  'invoice_type_code',
  'line_items',
  'lottery_code',
  'notes',
  'original_receipt_reference',
  'payments',
  'receipt_uuid',
  'seller',
  'shift_id',
  'subtotal',
  'table_id',
  'terminal_id',
  'total',
  'training_flag',
  'transaction_discount_amount',
  'transaction_discount_reason',
  'vat_breakdown',
  'vat_total',
  'vouchers_redeemed',
] as const;

/**
 * SALE_RECEIPT v5 top-level key set (D-1, owner ruling 2026-08-25).
 *
 * 30 keys — the SAME top-level set as v3. What changes at v5 is the
 * SEMANTICS of three of them plus one nested key:
 *   - `subtotal` / `vat_total` / `vat_breakdown[].net_amount` /
 *     `vat_breakdown[].vat_amount` are now the POST-remise taxable base and
 *     VAT (v3 sealed them on the PRE-discount base);
 *   - `vat_breakdown[]` rows gain `discount_allocated` — this group's
 *     pro-rata share of `transaction_discount_amount`
 *     (`SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5`).
 *
 * The aggregate identity flips with it: v3 asserts
 * `subtotal + vat_total == total + transaction_discount_amount` (the discount
 * added BACK to reach the taxed base); v5 asserts
 * `subtotal + vat_total == total` (minus the v3 rounding adjustment), because
 * the base is already net of the remise.
 *
 * A NAMED constant, never a mutation of `SALE_RECEIPT_PAYLOAD_KEYS_V3` — the
 * v3 record stays frozen forever and old receipts keep re-verifying against
 * the v3 rules (rule 8: Events are Immutable Forever).
 */
export const SALE_RECEIPT_PAYLOAD_KEYS_V5 = [
  'approval_references',
  'business_date',
  'buyer',
  'cash_rounding_adjustment',
  'cash_rounding_denomination',
  'cashier_id',
  'cashier_name',
  'consumption_mode',
  'currency_code',
  'currency_scale',
  'event_time_device',
  'invoice_type_code',
  'line_items',
  'lottery_code',
  'notes',
  'original_receipt_reference',
  'payments',
  'receipt_uuid',
  'seller',
  'shift_id',
  'subtotal',
  'table_id',
  'terminal_id',
  'total',
  'training_flag',
  'transaction_discount_amount',
  'transaction_discount_reason',
  'vat_breakdown',
  'vat_total',
  'vouchers_redeemed',
] as const;

/**
 * SALE_RECEIPT v4 top-level key set (v3-refund-chain-integration spec §2/
 * §3.3/§3.4). 33 keys = the 30 v3 keys plus `original_line_references`,
 * `refund_destination`, `settlement_allocation`, which sort in
 * alphabetically per this file's established convention.
 *
 * The PHP authority is
 * `FiscalPayloadConstraintValidator::SALE_RECEIPT_PAYLOAD_KEYS_V4`; the
 * FiscalPayloadKeyDrift gate pins the two lists (and this list's
 * sortedness, and its strict-superset-of-V3 relationship) against each
 * other.
 *
 * A NAMED constant, never a mutation of `SALE_RECEIPT_PAYLOAD_KEYS_V3` —
 * the v3 record stays frozen at 30 keys forever; the device never
 * authors v4 for a SALE/TRAINING receipt (only REFUND, §2's resolution
 * table).
 */
export const SALE_RECEIPT_PAYLOAD_KEYS_V4 = [
  'approval_references',
  'business_date',
  'buyer',
  'cash_rounding_adjustment',
  'cash_rounding_denomination',
  'cashier_id',
  'cashier_name',
  'consumption_mode',
  'currency_code',
  'currency_scale',
  'event_time_device',
  'invoice_type_code',
  'line_items',
  'lottery_code',
  'notes',
  'original_line_references',
  'original_receipt_reference',
  'payments',
  'receipt_uuid',
  'refund_destination',
  'seller',
  'settlement_allocation',
  'shift_id',
  'subtotal',
  'table_id',
  'terminal_id',
  'total',
  'training_flag',
  'transaction_discount_amount',
  'transaction_discount_reason',
  'vat_breakdown',
  'vat_total',
  'vouchers_redeemed',
] as const;

/** v4 `original_line_references[].disposition` domain — verbatim values of
 *  the existing PHP `ReturnLineDisposition` enum (spec §3.3). */
const RETURN_LINE_DISPOSITIONS = ['restock', 'scrap', 'not_received'] as const;

/** v4 `refund_destination` domain — single literal for launch (spec §3.4). */
const REFUND_DESTINATIONS = ['cash'] as const;

export const CHAIN_BREAK_DETECTED_PAYLOAD_KEYS = [
  'last_good_hash',
  'last_good_sequence',
  'offending_record_reference',
  'reason',
] as const;

export const CHAIN_RESTART_PAYLOAD_KEYS = [
  'last_good_anchor',
  'new_genesis_reference',
  'operator_authorization_evidence',
  'provenance_link',
] as const;

const ACCOUNT_STATUS_CHANGED_PAYLOAD_KEYS = [
  'actor_user_id',
  'company_id',
  'event_time_device',
  'new_status',
  'old_status',
  'partner_id',
  'partner_snapshot',
  'reason',
  'status_version',
  'tenant_id',
  'terminal_id',
  'training_flag',
] as const;

const OPERATOR_APPROVAL_GRANTED_PAYLOAD_KEYS = [
  'approval_id',
  'approval_scope',
  'cashier_user_id',
  'company_id',
  'event_time_device',
  'policy_version',
  'reason_code',
  'reason_text',
  'regime_extensions',
  'requested_at_device',
  'resolved_at_device',
  'supervisor_user_id',
  'supervisor_user_snapshot',
  'target',
  'tenant_id',
  'terminal_id',
  'training_flag',
] as const;

const PHASE4_OVERRIDE_PAYLOAD_KEYS = [
  'approval_event_id',
  'approval_id',
  'approval_scope',
  'company_id',
  'event_time_device',
  'override_context',
  'policy_version',
  'reason_code',
  'reason_text',
  'supervisor_user_id',
  'target',
  'tenant_id',
  'terminal_id',
  'training_flag',
] as const;

const CASH_DRAWER_MOVEMENT_PAYLOAD_KEYS = [
  'approval_event_id',
  'approval_id',
  'approval_scope',
  'amount',
  'company_id',
  'event_time_device',
  'operation_type',
  'reason',
  'shift_id',
  'supervisor_user_id',
  'target_reference_id',
  'tenant_id',
  'terminal_id',
  'training_flag',
] as const;

const SESSION_OPEN_PAYLOAD_KEYS = [
  'business_date',
  'currency_code',
  'currency_scale',
  'opened_at_device',
  'opening_float_amount',
  'operator_id',
  'operator_name',
  'session_id',
  'shift_id',
  'shift_number',
  'terminal_id',
  'terminal_label',
  'training_flag',
] as const;

const Z_CASH_DRAWER_MOVEMENT_PAYLOAD_KEYS = [
  'amount',
  'approval',
  'business_date',
  'cash_drawer_operation_id',
  'currency_code',
  'currency_scale',
  'event_time_device',
  'movement_id',
  'movement_type',
  'operator_id',
  'operator_name',
  'reason_code',
  'reason_text',
  'session_id',
  'shift_id',
  'training_flag',
] as const;

const X_REPORT_PAYLOAD_KEYS = [
  'business_date',
  'cash_drawer_totals',
  'generated_at_device',
  'operational_event_range',
  'operator_id',
  'operator_name',
  'payment_method_totals',
  'period_end',
  'period_start',
  'receipt_count',
  'refunds_totals',
  'sales_totals',
  'session_id',
  'shift_id',
  'terminal_id',
  'training_flag',
  'vat_breakdown',
  'voids_totals',
  'x_report_uuid',
] as const;

const SESSION_CLOSE_PAYLOAD_KEYS = [
  'business_date',
  'cash_count_lines',
  'cash_drawer_totals',
  'closure_status',
  'counted_cash',
  'expected_cash',
  'generated_at_device',
  'manager_approval',
  'operational_event_range',
  'operator_id',
  'operator_name',
  'payment_method_totals',
  'period_end',
  'period_start',
  'receipt_count',
  'refunds_totals',
  'sales_totals',
  'session_close_uuid',
  'session_id',
  'shift_id',
  'terminal_id',
  'training_flag',
  'variance_amount',
  'variance_direction',
  'variance_reason',
  'variance_severity',
  'vat_breakdown',
  'voids_totals',
] as const;

const Z_REPORT_PAYLOAD_KEYS = [
  'business_date',
  'cash_count',
  'cash_drawer_totals',
  'closed_at_device',
  'company_snapshot',
  'currency_code',
  'currency_scale',
  'formatted_z_number',
  'grand_totals_after',
  'grand_totals_before',
  'legacy_report_reference',
  'operational_event_range',
  'operator_id',
  'operator_name',
  'payment_method_totals',
  'period_end',
  'period_start',
  'period_type',
  'receipt_totals',
  'refunds_totals',
  'seller',
  'session_event_range',
  'session_id',
  'shift_id',
  'terminal_id',
  'terminal_label',
  'tolerance_summary',
  'training_flag',
  'vat_breakdown',
  'voids_totals',
  'z_number',
  'z_report_uuid',
] as const;

// -------------------------------------------------------------------
// Format / domain constants — mirror PHP
// `FiscalPayloadConstraintValidator` `private const` block exactly.
// Cross-language drift gate (Task 14 standing pattern).
// -------------------------------------------------------------------

/** Lowercase-hex UUID (RFC 4122 — version-agnostic at this layer). */
const LOWER_HEX_UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;

/** ISO 4217 alpha-3 uppercase currency code. */
const ISO_4217 = /^[A-Z]{3}$/;

/** ISO 3166-1 alpha-2 uppercase country code. */
const ISO_3166_ALPHA_2 = /^[A-Z]{2}$/;

/**
 * Universal tax-number baseline per synthesis v5 §7. Length 4-40 over
 * `[A-Za-z0-9 \-/.]`. Country-specific Phase 1.5.2 regexes run after
 * this baseline.
 */
const TAX_NUMBER_UNIVERSAL = /^[A-Za-z0-9 \-/.]{4,40}$/;

const TAX_NUMBER_PATTERNS: Readonly<Record<string, RegExp>> = {
  FR: /^([0-9]{9}|[0-9]{14})$/,
  /**
   * Tunisian matricule fiscale, compact form: 7-8 digits + 2 OR 3 letters +
   * 3-digit establishment. The 3-letter arm is the canonical 13-character MF;
   * the 2-letter arm is the 12-character form already present in sealed bytes.
   *
   * MIRROR: byte-identical to the server's
   * `CountryTaxNumberRules::PATTERNS['TN']` (modulo the PHP-only `D` modifier,
   * which JS `$` implies without the `m` flag). Guarded on the server by
   * `tests/Unit/Shared/TunisianMatriculeConvergenceTest.php` and here by
   * `__tests__/taxNumberPatterns.test.ts`. Do not edit one side alone.
   */
  TN: /^[0-9]{7,8}[A-Z]{2,3}[0-9]{3}$/,
  SA: /^3[0-9]{12}03$/,
  DE: /^DE[0-9]{9}$/,
  IT: /^[0-9]{11}$/,
};

const FR_BUYER_TVA_INTRACOM = /^FR[0-9]{11}$/;

const IT_BUYER_CODICE_FISCALE = /^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/;

/**
 * ISO 8601 with millisecond precision + tz (`Z` or `±HH:MM`).
 * Synthesis v5 §3 line 51. Note: stricter than the envelope-level
 * `event_time_device` which uses second precision UTC; the SALE_RECEIPT
 * payload field is millisecond precision per the canonical contract.
 */
const ISO_8601_DATETIME_MS = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}(Z|[+-]\d{2}:\d{2})$/;

/** `YYYY-MM-DD` calendar date. */
const ISO_8601_CALENDAR_DATE = /^\d{4}-\d{2}-\d{2}$/;

/** `tax_category_code` shape — empty string OR `^[A-Z0-9_-]+$`. */
const TAX_CATEGORY_CODE = /^[A-Z0-9_-]+$/;

/** Phase-1 fixed quantity scale per synthesis v5 §6.B. */
const QUANTITY_SCALE = 3;

/** Phase-1 fixed VAT-rate scale per synthesis v5 §6.B. */
const VAT_RATE_SCALE = 2;

/** Supported `currency_scale` allowlist per synthesis v5 (§3 / §6.B). */
const SUPPORTED_CURRENCY_SCALES: ReadonlyArray<number> = [0, 2, 3];

/** Foreign-currency scale lookup (synthesis v5 §6.B). */
const CURRENCY_SCALES: Readonly<Record<string, number>> = {
  EUR: 2,
  USD: 2,
  GBP: 2,
  SAR: 2,
  TND: 3,
  JPY: 0,
};

const INVOICE_TYPE_CODES = ['SALE', 'REFUND', 'VOID', 'TRAINING'] as const;
type InvoiceTypeCode = (typeof INVOICE_TYPE_CODES)[number];

const CONSUMPTION_MODES = ['dine_in', 'takeaway'] as const;

const NON_COLLECTED_SUBTYPES = ['servizi', 'beni', 'omaggio', 'successiva'] as const;

const ACCOUNT_PAYMENT_CUSTOMER_SYNC_STATUSES = ['synced', 'pending_create'] as const;

const ACCOUNT_PAYMENT_ALLOCATION_POLICIES = ['FIFO'] as const;

const ACCOUNT_PAYMENT_STALENESS_REASONS = [
  'never_synced',
  'older_than_threshold',
  'server_conflict_pending',
] as const;

/**
 * Money regex at the given scale. Non-negative (no `^-?` prefix);
 * refunds are modeled via `invoice_type_code='REFUND'` per v5 §6.A.
 * Mirrors PHP `FiscalPayloadConstraintValidator::moneyRegex`: for
 * non-zero scales the fractional part is mandatory because payload
 * money strings are `bcformat` output.
 */
function moneyRegex(scale: number): RegExp {
  if (scale === 0) {
    return /^(0|[1-9]\d*)$/;
  }
  return new RegExp(`^(0|[1-9]\\d*)\\.\\d{${scale}}$`);
}

/**
 * Signed money at `scale` — the non-negative `moneyRegex` shape plus an
 * optional leading '-'. `-0` is rejected: canonical zero is unsigned.
 */
function signedMoneyRegex(scale: number): RegExp {
  if (scale === 0) {
    return /^-?(0|[1-9]\d*)$/;
  }
  return new RegExp(`^-?(0|[1-9]\\d*)\\.\\d{${scale}}$`);
}

/**
 * True when `value` is BCMath-equivalent zero at the given scale — i.e.
 * `"0"`, `"0.00"`, `"0.000"`, etc. Used for the discount-reason
 * consistency invariant (§6.A) where `bcformat()` at scale=2 emits
 * `"0.00"` not `"0"`. Pre-filtered by `moneyRegex` so we can rely on
 * the input being scale-conformant.
 */
function isZeroMoney(value: string): boolean {
  return /^0(\.0+)?$/.test(value);
}

// -------------------------------------------------------------------
// SALE_RECEIPT validator — 30-key canonical set at event_version 3
// (Candidate C-v3 per synthesis v5 §3, plus the two cash-rounding
// fields of spec 2026-07-27 §4.4). STRUCTURAL conformance only: key set + types +
// regex + enums + foreign-currency pairing + training-flag invariant
// + discount-reason consistency. The VAT partition algorithm +
// invoice-total arithmetic stay server-side per v5 §6.F (the PHP
// validator is authoritative there).
// -------------------------------------------------------------------

/**
 * EXPORTED for the v4 refund flow's original-event resolution
 * (`resolveOriginalFiscalEventLocally()`, wave-2 fix-wave finding 2 /
 * codex C-1). That resolver makes §3.5/§3.7 REFUSAL decisions from the
 * original's signed bytes, so it must validate those bytes with THIS
 * canonical validator — the same one `append()` ran when the original was
 * authored — rather than re-implementing a weaker set of shape checks
 * that could drift. Exported deliberately, not made public API: the only
 * non-engine caller is the refund resolver.
 */
export function validateSaleReceiptPayload(payload: unknown, eventVersion: number): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'SALE_RECEIPT payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  // -- 1. key-set: required + extras -- mirrors PHP validatePayloadKeySet.
  //       v3-refund-chain-integration spec §2 — version-threaded: v4
  //       (REFUND only, §2's resolution table) carries the 33-key v4 set;
  //       every other version (the device authors only 3) keeps the
  //       30-key v3 set unchanged. The ROUNDING feature gate itself still
  //       lives on `fiscal_schema_version`, not on the payload version —
  //       unaffected by this threading.
  //       D-1: v4 is the REFUND-only fan-out, so it is matched EXACTLY.
  //       v5 (post-remise VAT base) carries the same 30 top-level keys as
  //       v3 under `SALE_RECEIPT_PAYLOAD_KEYS_V5`; a `>= 4` test would have
  //       handed v5 the 33-key refund set and refused every new sale.
  assertExactKeySet(
    p,
    eventVersion === 4
      ? SALE_RECEIPT_PAYLOAD_KEYS_V4
      : (eventVersion >= 5 ? SALE_RECEIPT_PAYLOAD_KEYS_V5 : SALE_RECEIPT_PAYLOAD_KEYS_V3),
    'SALE_RECEIPT',
  );

  // -- 2. currency_scale + currency_code first; every subsequent money
  //       check depends on the scale.
  const scale = p['currency_scale'];
  if (typeof scale !== 'number' || !Number.isInteger(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_invalid:must be int; got ${typeofTag(scale)}`,
    );
  }
  if (scale < 0 || scale > 8) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_invalid:must be int 0-8; got ${scale}`,
    );
  }
  if (!SUPPORTED_CURRENCY_SCALES.includes(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_unsupported:value=${scale}:allowed=${SUPPORTED_CURRENCY_SCALES.join(',')}`,
    );
  }
  const money = moneyRegex(scale);

  const currencyCode = p['currency_code'];
  if (typeof currencyCode !== 'string' || !ISO_4217.test(currencyCode)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got ${jsonOrType(currencyCode)}`,
    );
  }

  // -- 3. enum + format invariants on simple top-level fields --
  const invoiceTypeCode = assertEnum(p, 'invoice_type_code', INVOICE_TYPE_CODES) as InvoiceTypeCode;

  // -- 3z. v4 (v3-refund-chain-integration spec §2 table) — the registry's
  //        eventVersionFor() never resolves 4 for anything but a REFUND, so
  //        by construction this branch is unreachable for VOID/SALE/
  //        TRAINING when eventVersion is genuinely derived from THIS SAME
  //        payload. It is asserted here anyway as an independent,
  //        defense-in-depth boundary check (mirrors the PHP validator's own
  //        §2z, which is a genuinely separate parse path server-side) —
  //        never trusted to be redundant.
  if (eventVersion === 4) {
    if (invoiceTypeCode === 'VOID') {
      throw new FiscalEventPayloadValidationError(
        'payload_void_authoring_prohibited:event_version=4 payloads with invoice_type_code=VOID have no legitimate producer (spec §2/§8)',
      );
    }
    if (invoiceTypeCode !== 'REFUND') {
      throw new FiscalEventPayloadValidationError(
        `payload_invoice_type_invalid:event_version=4 requires invoice_type_code=REFUND; got ${jsonOrType(invoiceTypeCode)}`,
      );
    }
  }
  assertOptionalEnum(p, 'consumption_mode', CONSUMPTION_MODES);
  const trainingFlag = assertBool(p, 'training_flag');
  assertCalendarDate(p, 'business_date');
  assertUuid(p, 'cashier_id');
  assertNonEmptyString(p, 'cashier_name');
  assertIsoDateTimeMs(p, 'event_time_device');
  assertUuid(p, 'receipt_uuid');
  assertUuid(p, 'shift_id');
  assertUuid(p, 'terminal_id');
  assertOptionalNonEmptyString(p, 'notes');
  assertOptionalNonEmptyString(p, 'table_id');
  assertOptionalNonEmptyString(p, 'lottery_code');

  // -- 3a. TRAINING-flag coupling invariant (§3 / v5 §6) --
  const invoiceTypeIsTraining = invoiceTypeCode === 'TRAINING';
  if (invoiceTypeIsTraining !== trainingFlag) {
    const flagLabel = trainingFlag ? 'true' : 'false';
    throw new FiscalEventPayloadValidationError(
      `payload_training_flag_mismatch:invoice_type_code=${invoiceTypeCode}:training_flag=${flagLabel}`,
    );
  }

  // -- 4. top-level money fields (non-negative bcformat at currency_scale) --
  for (const field of ['subtotal', 'vat_total', 'total', 'transaction_discount_amount'] as const) {
    assertMoneyString(p, field, money, scale);
  }

  // -- 4b. v3 cash-rounding fields (spec §4.4) --
  //        The adjustment is the ONLY signed money in the payload. Canonical
  //        zero is unsigned: `signedMoneyRegex` pins the scale exactly, so
  //        `-0`-at-scale is the single negative-zero form still reachable and
  //        is rejected explicitly (the server's `payload_money_negative_zero`).
  const signedMoney = signedMoneyRegex(scale);
  const adjustment = p['cash_rounding_adjustment'];
  if (
    typeof adjustment !== 'string'
    || !signedMoney.test(adjustment)
    || adjustment === `-${bcformat('0', scale)}`
  ) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:cash_rounding_adjustment must be signed money at scale ${scale}; got ${jsonOrType(adjustment)}`,
    );
  }
  assertMoneyString(p, 'cash_rounding_denomination', money, scale);

  // -- 5. discount-reason consistency (§6.A; BCMath-equivalent zero check) --
  const discountAmount = p['transaction_discount_amount'] as string;
  const discountReason = p['transaction_discount_reason'];
  if (discountReason !== null && (typeof discountReason !== 'string' || discountReason === '')) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:transaction_discount_reason must be non-empty string or null; got ${jsonOrType(discountReason)}`,
    );
  }
  const isZeroDiscount = isZeroMoney(discountAmount);
  const reasonPresent = discountReason !== null;
  if (isZeroDiscount && reasonPresent) {
    throw new FiscalEventPayloadValidationError(
      `payload_discount_reason_mismatch:amount=${discountAmount}:reason_present=true`,
    );
  }
  if (!isZeroDiscount && !reasonPresent) {
    throw new FiscalEventPayloadValidationError(
      `payload_discount_reason_mismatch:amount=${discountAmount}:reason_present=false`,
    );
  }

  // -- 5a. v4 REFUND: transaction_discount_amount is ALWAYS canonical zero
  //        (spec §3.5's ⚖️ orchestrator ruling — RefundReceiptV4Payload.ts's
  //        own pre-authoring refusal at resolveOriginalFiscalEventLocally()
  //        already makes a non-zero-original-discount refund unreachable;
  //        this is the device-side defense-in-depth mirror of the PHP
  //        validator's identical §4a check, not a new runtime branch). --
  if (eventVersion === 4 && !isZeroDiscount) {
    throw new FiscalEventPayloadValidationError(
      `payload_v4_refund_transaction_discount_must_be_zero:transaction_discount_amount=${discountAmount}`,
    );
  }

  // -- 6. nested objects --
  validateSeller(p);
  validateBuyer(p);
  validateOriginalReceiptReference(p, invoiceTypeCode);

  // -- 6z. v4-only nested contract (spec §3.3/§3.4) -- reached only when
  //        invoice_type_code === 'REFUND' (§3z above already fail-closed on
  //        every other value at v4). original_line_references[] is
  //        cross-checked against line_items[] BEFORE line_items' own
  //        per-row validation below, mirroring the PHP validator's
  //        placement exactly (both lists are validated by their own
  //        natural shape check, requireList, first).
  if (eventVersion === 4) {
    validateOriginalLineReferences(p);
    validateRefundDestinationAndSettlementAllocation(p);
  }

  // -- 7. list containers --
  const approvalReferences = requireList(p, 'approval_references');
  approvalReferences.forEach((row, idx) => validateSaleReceiptApprovalReference(idx, row));

  const lineItems = requireList(p, 'line_items');
  if (lineItems.length === 0) {
    throw new FiscalEventPayloadValidationError(
      'payload_line_items_empty:line_items must have >= 1 row',
    );
  }
  lineItems.forEach((row, idx) => validateLineItem(idx, row, money, scale));

  const payments = requireList(p, 'payments');
  if (payments.length === 0) {
    // D-1 / G3-A fold-in: a 100 %-comp receipt tenders nothing, and
    // `pos_receipt_payments CHECK (amount > 0)` refuses the 0.000 leg the
    // device used to emit — so at v5 the device emits NO tender row at all
    // and the ticket's story is carried by the discount line. Allowed ONLY
    // when the ticket is fully comped: `total` is canonical zero AND the
    // remise equals the whole gross. Every other version, and every
    // non-zero-total v5 ticket, still requires >= 1 row.
    if (eventVersion < 5 || !isFullyCompedTicket(p, scale)) {
      throw new FiscalEventPayloadValidationError(
        'payload_payments_empty:payments must have >= 1 row',
      );
    }
  }
  payments.forEach((row, idx) => validatePaymentRow(idx, row, money, scale));

  // -- 7a. v4 single-cash-leg contract (spec §3.7). --
  if (eventVersion === 4) {
    validateSingleCashLegPayment(payments);
  }

  const vatBreakdown = requireList(p, 'vat_breakdown');
  if (vatBreakdown.length === 0) {
    throw new FiscalEventPayloadValidationError(
      'payload_vat_breakdown_empty:vat_breakdown must have >= 1 row',
    );
  }
  vatBreakdown.forEach((row, idx) => validateVatBreakdownRow(idx, row, money, scale, eventVersion));

  const vouchers = requireList(p, 'vouchers_redeemed');
  vouchers.forEach((row, idx) => validateVoucherRow(idx, row, money, scale));

  // Note: VAT partition algorithm + invoice-total arithmetic stay
  // server-side per synthesis v5 §6.F. The PHP
  // `FiscalPayloadConstraintValidator::validateVatPartition()` +
  // total-arithmetic cross-check are the authoritative enforcement
  // points; the TS validator deliberately stops at structural
  // conformance to avoid duplicating BCMath behavior in JS.
}

/**
 * v4 `original_line_references[]` — spec §3.3's exact frozen shape,
 * strict parallel-array to `line_items[]` (same length, `product_id`
 * equal, `quantity` equal at every index `i`). Mirrors PHP
 * `FiscalPayloadConstraintValidator::validateOriginalLineReferences()`.
 * Reached only when `invoice_type_code === 'REFUND'` (§3z above already
 * fail-closed on every other value at v4).
 */
function validateOriginalLineReferences(p: Record<string, unknown>): void {
  const lineItems = requireList(p, 'line_items');
  const refs = requireList(p, 'original_line_references');

  if (refs.length !== lineItems.length) {
    throw new FiscalEventPayloadValidationError(
      `payload_original_line_references_length_mismatch:line_items=${lineItems.length}:original_line_references=${refs.length}`,
    );
  }
  if (refs.length === 0) {
    throw new FiscalEventPayloadValidationError(
      'payload_original_line_references_empty:original_line_references must have >= 1 row on a v4 REFUND',
    );
  }

  refs.forEach((row, index) => {
    if (!isPlainObject(row)) {
      throw new FiscalEventPayloadValidationError(
        `payload_original_line_reference_invalid:original_line_references[${index}] must be object; got ${typeofTag(row)}`,
      );
    }
    const path = `original_line_references[${index}]`;
    assertExactKeySetWithPath(
      row,
      ['disposition', 'original_line_index', 'product_id', 'quantity'],
      path,
    );

    const originalLineIndex = row['original_line_index'];
    if (typeof originalLineIndex !== 'number' || !Number.isInteger(originalLineIndex) || originalLineIndex < 0) {
      throw new FiscalEventPayloadValidationError(
        `payload_integer_format_mismatch:${path}.original_line_index must be an integer >= 0; got ${jsonOrType(originalLineIndex)}`,
      );
    }
    assertNonEmptyStringAt(row, 'product_id', `${path}.product_id`);
    assertNonEmptyStringAt(row, 'quantity', `${path}.quantity`);
    assertEnum(row, 'disposition', RETURN_LINE_DISPOSITIONS);

    // Strict parallel-array invariants against line_items[index] -- NOT
    // original_line_index (that indexes into the ORIGINAL sale's own
    // line_items[], an entirely different, server-side-resolved list this
    // validator has no access to; the cross-reference is resolved and
    // verified server-side by the projector, §17).
    const lineItem = lineItems[index];
    if (!isPlainObject(lineItem)) {
      throw new FiscalEventPayloadValidationError(
        `payload_original_line_reference_invalid:${path} has no matching line_items[${index}]`,
      );
    }
    if (lineItem['product_id'] !== row['product_id']) {
      throw new FiscalEventPayloadValidationError(
        `payload_original_line_reference_product_id_mismatch:${path}.product_id must equal line_items[${index}].product_id`,
      );
    }
    if (lineItem['quantity'] !== row['quantity']) {
      throw new FiscalEventPayloadValidationError(
        `payload_original_line_reference_quantity_mismatch:${path}.quantity must equal line_items[${index}].quantity`,
      );
    }
  });
}

/**
 * v4 `refund_destination` / `settlement_allocation` (spec §3.4):
 * `refund_destination` is the single `'cash'` literal for launch;
 * `settlement_allocation` is required-present-and-null on every launch
 * v4 payload. Mirrors PHP
 * `FiscalPayloadConstraintValidator::validateRefundDestinationAndSettlementAllocation()`.
 */
function validateRefundDestinationAndSettlementAllocation(p: Record<string, unknown>): void {
  assertEnum(p, 'refund_destination', REFUND_DESTINATIONS);

  if (!Object.prototype.hasOwnProperty.call(p, 'settlement_allocation')) {
    throw new FiscalEventPayloadValidationError(
      'payload_missing_required:settlement_allocation',
    );
  }
  if (p['settlement_allocation'] !== null) {
    throw new FiscalEventPayloadValidationError(
      `payload_settlement_allocation_not_null:settlement_allocation must be null on every launch v4 payload; got ${jsonOrType(p['settlement_allocation'])}`,
    );
  }
}

/**
 * v4 single-cash-leg contract (spec §3.7): `payments[]` is exactly one
 * row, `method_code === 'CASH'`, `instrument_type === null`. Mirrors PHP
 * `FiscalPayloadConstraintValidator::validateSingleCashLegPayment()`.
 */
function validateSingleCashLegPayment(payments: readonly unknown[]): void {
  if (payments.length !== 1) {
    throw new FiscalEventPayloadValidationError(
      `payload_v4_refund_payments_not_single_leg:payments must have exactly 1 row; got ${payments.length}`,
    );
  }
  const row = payments[0];
  if (!isPlainObject(row)) {
    throw new FiscalEventPayloadValidationError(
      'payload_v4_refund_payment_invalid:payments[0] must be object',
    );
  }
  if (row['method_code'] !== 'CASH') {
    throw new FiscalEventPayloadValidationError(
      `payload_v4_refund_payment_not_cash:payments[0].method_code must be CASH; got ${jsonOrType(row['method_code'])}`,
    );
  }
  if (row['instrument_type'] !== null) {
    throw new FiscalEventPayloadValidationError(
      `payload_v4_refund_payment_instrument_type_must_be_null:payments[0].instrument_type must be null; got ${jsonOrType(row['instrument_type'])}`,
    );
  }
}

function validateSaleReceiptApprovalReference(index: number, row: unknown): void {
  if (typeof row !== 'object' || row === null || Array.isArray(row)) {
    throw new FiscalEventPayloadValidationError(
      `payload_approval_references_${String(index)}_invalid:must be object`,
    );
  }
  const r = row as Record<string, unknown>;
  const path = `approval_references.${String(index)}`;
  assertExactKeySetWithPath(r, [
    'approval_event_id',
    'approval_id',
    'approval_scope',
    'override_event_id',
    'policy_version',
    'supervisor_user_id',
    'target_reference_id',
  ], path);
  assertUuidAt(r, 'approval_event_id', `${path}.approval_event_id`);
  assertUuidAt(r, 'approval_id', `${path}.approval_id`);
  assertEnum(r, 'approval_scope', [
    'discount_limit_override',
    'tender_tolerance_override',
    'void_or_return_override',
  ]);
  assertUuidAt(r, 'override_event_id', `${path}.override_event_id`);
  assertNonEmptyStringAt(r, 'policy_version', `${path}.policy_version`);
  assertUuidAt(r, 'supervisor_user_id', `${path}.supervisor_user_id`);
  assertNonEmptyStringAt(r, 'target_reference_id', `${path}.target_reference_id`);
}

function validateAccountPaymentPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'ACCOUNT_PAYMENT payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  assertExactKeySet(p, ACCOUNT_PAYMENT_PAYLOAD_KEYS, 'ACCOUNT_PAYMENT');

  const scale = p['currency_scale'];
  if (typeof scale !== 'number' || !Number.isInteger(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_invalid:must be int; got ${typeofTag(scale)}`,
    );
  }
  if (!SUPPORTED_CURRENCY_SCALES.includes(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_unsupported:value=${scale}:allowed=${SUPPORTED_CURRENCY_SCALES.join(',')}`,
    );
  }
  const money = moneyRegex(scale);

  const currencyCode = p['currency_code'];
  if (typeof currencyCode !== 'string' || !ISO_4217.test(currencyCode)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got ${jsonOrType(currencyCode)}`,
    );
  }

  assertUuid(p, 'account_payment_uuid');
  assertCalendarDate(p, 'business_date');
  assertUuid(p, 'cashier_id');
  assertNonEmptyString(p, 'cashier_name');
  assertIsoDateTimeMs(p, 'event_time_device');
  assertOptionalNonEmptyString(p, 'notes');
  assertEnum(p, 'receipt_type_code', ['ACCOUNT_PAYMENT'] as const);
  assertUuid(p, 'shift_id');
  assertUuid(p, 'terminal_id');
  const trainingFlag = assertBool(p, 'training_flag');
  assertEnum(p, 'treasury_allocation_policy', ACCOUNT_PAYMENT_ALLOCATION_POLICIES);

  const sellerCountryCode = validateSeller(p);
  validateAccountPaymentCustomer(p['customer'], sellerCountryCode);
  validateAccountPaymentPayment(p['payment'], money, scale, trainingFlag);
  validateAccountPaymentBalanceSnapshot(p['local_balance_snapshot'], money, scale);
  validateAccountPaymentStaleness(p['staleness']);
  validateAccountPaymentReferences(p['references']);
  validateNullableAssoc(p['regime_extensions'], 'regime_extensions');
}

function validateAccountChargePayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_invalid:ACCOUNT_CHARGE payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  assertExactKeySet(p, ACCOUNT_CHARGE_PAYLOAD_KEYS, 'ACCOUNT_CHARGE');
  rejectAccountChargePaymentsKeyRecursively(p);

  const scale = p['currency_scale'];
  if (typeof scale !== 'number' || !Number.isInteger(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_invalid:must be int; got ${typeofTag(scale)}`,
    );
  }
  if (!SUPPORTED_CURRENCY_SCALES.includes(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_unsupported:value=${scale}:allowed=${SUPPORTED_CURRENCY_SCALES.join(',')}`,
    );
  }
  const money = moneyRegex(scale);

  const currencyCode = p['currency_code'];
  if (typeof currencyCode !== 'string' || !ISO_4217.test(currencyCode)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got ${jsonOrType(currencyCode)}`,
    );
  }

  assertUuid(p, 'account_charge_uuid');
  assertCalendarDate(p, 'business_date');
  assertUuid(p, 'cashier_id');
  assertNonEmptyString(p, 'cashier_name');
  const trainingFlag = assertBool(p, 'training_flag');
  validateAccountChargeTerms(p['charge_terms']);
  validateAccountChargeCreditDecision(p['credit_decision'], money, scale, trainingFlag);
  const sellerCountryCode = validateSeller(p);
  const customerCategory = validateAccountChargeCustomer(p['customer'], sellerCountryCode);
  validateBuyer(p);
  assertIsoDateTimeMs(p, 'event_time_device');
  const invoiceClassification = assertEnum(
    p,
    'invoice_classification',
    ['b2c_charge_receipt', 'b2b_facture_draft_requested'] as const,
  );
  validateAccountChargeLineItems(p, money, scale);
  validateAccountChargeBalanceSnapshot(p['local_balance_snapshot'], money, scale);
  assertOptionalNonEmptyString(p, 'notes');
  assertEnum(p, 'print_profile', ['ACCOUNT_CHARGE_RECEIPT'] as const);
  assertEnum(p, 'receipt_type_code', ['ACCOUNT_CHARGE'] as const);
  validateAccountChargeReferences(p['references']);
  validateNullableAssoc(p['regime_extensions'], 'regime_extensions');
  assertUuid(p, 'shift_id');
  validateAccountChargeStaleness(p['staleness']);
  assertUuid(p, 'terminal_id');
  validateAccountChargeTotals(p['totals'], money, scale);
  assertMoneyString(p, 'transaction_discount_amount', money, scale);
  assertOptionalNonEmptyString(p, 'transaction_discount_reason');
  validateAccountChargeVatBreakdown(p, money, scale);
  validateAccountChargeArithmetic(p, scale);
  if (invoiceClassification === 'b2b_facture_draft_requested' && customerCategory !== 'business') {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_invoice_classification_mismatch:b2b_facture_draft_requested requires customer.customer_category=business',
    );
  }
}

// -------------------------------------------------------------------
// Nested-object validators
// -------------------------------------------------------------------

const SELLER_KEYS = ['address', 'name', 'tax_jurisdiction_country_code', 'tax_number'] as const;
const BUYER_KEYS = ['address', 'codice_fiscale', 'contact_id', 'customer_id', 'name', 'tax_number'] as const;
const ADDRESS_KEYS = ['city', 'country_code', 'postal_code', 'street'] as const;
const ORIGINAL_RECEIPT_REFERENCE_KEYS = [
  'fiscal_event_id', 'original_business_date', 'original_receipt_uuid', 'refund_reason',
] as const;
// SaleReceiptV2 (M4, event_version=2) line-item key set — V1 + the three
// variant-identity keys (null for non-variant lines). The device authors
// ONLY V2 after the cutover, so append-time validation enforces the V2
// shape; authoring a V1-shaped line now fails closed here. Mirrors the PHP
// FiscalPayloadConstraintValidator::SALE_RECEIPT_LINE_ITEM_KEYS_V2 (the
// FiscalPayloadKeyDrift gate pins the two lists against each other).
export const SALE_RECEIPT_LINE_ITEM_KEYS_V2 = [
  'gtin', 'line_discount_amount', 'line_discount_reason', 'line_subtotal',
  'line_vat', 'name', 'non_collected_subtype', 'product_id', 'quantity',
  'sku', 'tax_category_code', 'unit_price', 'variant_id', 'variant_name',
  'variant_sku', 'vat_rate',
] as const;
const LINE_ITEM_KEYS = SALE_RECEIPT_LINE_ITEM_KEYS_V2;
const PAYMENT_KEYS = [
  'amount', 'foreign_currency_amount', 'foreign_currency_code',
  'instrument_serial', 'instrument_type', 'method_code',
] as const;
const VAT_BREAKDOWN_KEYS = ['gross_amount', 'net_amount', 'rate', 'tax_category_code', 'vat_amount'] as const;
/**
 * v5 `vat_breakdown[]` row key set (D-1) — the five v3 keys plus
 * `discount_allocated`, this group's pro-rata share of the ticket-level
 * remise. Sorted, mirroring
 * `FiscalPayloadConstraintValidator::SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5`
 * (the FiscalPayloadKeyDrift gate pins the two lists against each other).
 */
export const SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5 = [
  'discount_allocated', 'gross_amount', 'net_amount', 'rate',
  'tax_category_code', 'vat_amount',
] as const;
const VOUCHER_KEYS = ['redeemed_amount', 'voucher_code'] as const;
const ACCOUNT_PAYMENT_CUSTOMER_KEYS = [
  'address',
  'customer_category',
  'customer_id',
  'customer_sync_status',
  'email',
  'name',
  'phone',
  'tax_number',
] as const;
const ACCOUNT_PAYMENT_PAYMENT_KEYS = [
  'amount',
  'foreign_currency_amount',
  'foreign_currency_code',
  'instrument_serial',
  'instrument_type',
  'method_code',
  'repository_id',
] as const;
const ACCOUNT_PAYMENT_BALANCE_SNAPSHOT_KEYS = [
  'balance_updated_at',
  'credit_balance_before',
  'net_balance_before',
  'payment_amount',
  'projected_credit_balance_after',
  'projected_net_balance_after',
  'projected_receivable_balance_after',
  'receivable_balance_before',
] as const;
const ACCOUNT_PAYMENT_STALENESS_KEYS = [
  'balance_snapshot_stale',
  'customer_snapshot_stale',
  'mirror_last_synced_at',
  'staleness_reason',
] as const;
const ACCOUNT_PAYMENT_REFERENCES_KEYS = [
  'external_reference',
  'related_sale_receipt_event_id',
  'server_customer_alias_id',
] as const;
const ACCOUNT_CHARGE_CUSTOMER_KEYS = [
  'account_identifier',
  'address',
  'customer_category',
  'customer_id',
  'customer_sync_status',
  'email',
  'name',
  'phone',
  'tax_number',
] as const;
const ACCOUNT_CHARGE_LINE_ITEM_KEYS = [
  'gtin',
  'line_discount_amount',
  'line_discount_reason',
  'line_subtotal',
  'line_uuid',
  'line_vat',
  'name',
  'non_collected_subtype',
  'product_id',
  'quantity',
  'sku',
  'tax_category_code',
  'unit_price',
  'vat_rate',
] as const;
const ACCOUNT_CHARGE_BALANCE_SNAPSHOT_KEYS = [
  'balance_updated_at',
  'charge_amount',
  'credit_balance_before',
  'net_balance_before',
  'projected_credit_balance_after',
  'projected_net_balance_after',
  'projected_receivable_balance_after',
  'receivable_balance_before',
] as const;
const ACCOUNT_CHARGE_CREDIT_DECISION_KEYS = [
  'credit_available_after',
  'credit_available_before',
  'credit_limit',
  'decision',
  'limit_exceeded',
  'mirror_stale_at_authoring',
  'override_evidence',
  'policy_version',
  'stale_policy_action',
  'warnings',
] as const;
const ACCOUNT_CHARGE_OVERRIDE_EVIDENCE_KEYS = [
  'approval_event_id',
  'approval_scope',
  'override_event_id',
  'policy_version',
  'target_account_status',
  'target_amount',
  'target_customer_id',
] as const;
const ACCOUNT_CHARGE_TERMS_KEYS = [
  'due_date',
  'payment_terms_days',
  'terms_label',
] as const;
const ACCOUNT_CHARGE_TOTALS_KEYS = [
  'amount_charged_to_account',
  'grand_total_before_charge',
  'subtotal',
  'total',
  'vat_total',
] as const;
const ACCOUNT_CHARGE_STALENESS_KEYS = ACCOUNT_PAYMENT_STALENESS_KEYS;
const ACCOUNT_CHARGE_REFERENCES_KEYS = ACCOUNT_PAYMENT_REFERENCES_KEYS;

function validateAccountChargeCustomer(customer: unknown, sellerCountryCode: string): string | null {
  if (!isPlainObject(customer)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:customer must be object; got ${typeofTag(customer)}`,
    );
  }
  assertExactKeySetWithPath(customer, ACCOUNT_CHARGE_CUSTOMER_KEYS, 'customer');
  assertOptionalNonEmptyStringAt(customer, 'account_identifier', 'customer.account_identifier');
  assertUuidAt(customer, 'customer_id', 'customer.customer_id');
  assertEnum(customer, 'customer_sync_status', ACCOUNT_PAYMENT_CUSTOMER_SYNC_STATUSES);
  assertNonEmptyStringAt(customer, 'name', 'customer.name');
  assertOptionalNonEmptyStringAt(customer, 'phone', 'customer.phone');
  assertOptionalNonEmptyStringAt(customer, 'email', 'customer.email');
  assertOptionalNonEmptyStringAt(customer, 'customer_category', 'customer.customer_category');
  validateAddress(customer['address'], 'customer.address', /* required */ false);
  if (customer['tax_number'] !== null && customer['tax_number'] !== undefined) {
    assertTaxNumberForCountry(
      customer['tax_number'],
      countryCodeFromAddress(customer['address']) ?? sellerCountryCode,
      'customer.tax_number',
      true,
    );
  }

  return typeof customer['customer_category'] === 'string' ? customer['customer_category'] : null;
}

function validateAccountChargeTerms(terms: unknown): void {
  if (!isPlainObject(terms)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:charge_terms must be object; got ${typeofTag(terms)}`,
    );
  }
  assertExactKeySetWithPath(terms, ACCOUNT_CHARGE_TERMS_KEYS, 'charge_terms');
  const days = terms['payment_terms_days'];
  if (days !== null && (typeof days !== 'number' || !Number.isInteger(days) || days < 0)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:charge_terms.payment_terms_days must be non-negative integer or null; got ${jsonOrType(days)}`,
    );
  }
  if (terms['due_date'] !== null) {
    assertCalendarDateAt(terms, 'due_date', 'charge_terms.due_date');
  }
  if (days !== null && terms['due_date'] === null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_terms_invalid:charge_terms.due_date required when payment_terms_days is non-null',
    );
  }
  assertOptionalNonEmptyStringAt(terms, 'terms_label', 'charge_terms.terms_label');
}

function validateAccountChargeCreditDecision(
  decision: unknown,
  money: RegExp,
  scale: number,
  training: boolean,
): void {
  if (!isPlainObject(decision)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:credit_decision must be object; got ${typeofTag(decision)}`,
    );
  }
  assertExactKeySetWithPath(decision, ACCOUNT_CHARGE_CREDIT_DECISION_KEYS, 'credit_decision');
  for (const field of ['credit_available_after', 'credit_available_before', 'credit_limit'] as const) {
    if (decision[field] !== null) {
      assertMoneyStringAt(decision, field, money, scale, `credit_decision.${field}`);
    }
  }
  const decisionValue = assertEnum(decision, 'decision', ['approved', 'approved_with_override'] as const);
  const limitExceeded = assertBoolAt(decision, 'limit_exceeded', 'credit_decision.limit_exceeded');
  assertBoolAt(
    decision,
    'mirror_stale_at_authoring',
    'credit_decision.mirror_stale_at_authoring',
  );
  assertNonEmptyStringAt(decision, 'policy_version', 'credit_decision.policy_version');
  assertEnum(decision, 'stale_policy_action', ['allow', 'warn', 'block'] as const);
  validateAccountChargeOverrideEvidence(decision['override_evidence'], decisionValue, money, scale);
  const warnings = decision['warnings'];
  if (!Array.isArray(warnings)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:credit_decision.warnings must be a JSON list; got ${typeofTag(warnings)}`,
    );
  }
  warnings.forEach((warning, index) => {
    if (typeof warning !== 'string' || warning === '') {
      throw new FiscalEventPayloadValidationError(
        `payload_field_invalid:credit_decision.warnings[${index}] must be non-empty string; got ${jsonOrType(warning)}`,
      );
    }
    if (!/^[a-z][a-z0-9_]*$/.test(warning)) {
      throw new FiscalEventPayloadValidationError(
        `payload_account_charge_credit_decision_invalid:warnings[${index}] must be a stable lower_snake_case code`,
      );
    }
  });
  const sortedWarnings = [...warnings].sort();
  if (warnings.some((warning, index) => warning !== sortedWarnings[index])) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_credit_decision_invalid:warnings must be sorted stable codes',
    );
  }
  if (!training && limitExceeded && decisionValue !== 'approved_with_override') {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_credit_decision_invalid:limit_exceeded requires training_flag=true',
    );
  }
}

function validateAccountChargeOverrideEvidence(
  evidence: unknown,
  decision: 'approved' | 'approved_with_override',
  money: RegExp,
  scale: number,
): void {
  if (decision === 'approved') {
    if (evidence !== null) {
      throw new FiscalEventPayloadValidationError(
        'payload_account_charge_credit_decision_invalid:override_evidence must be null for approved decisions',
      );
    }
    return;
  }

  if (!isPlainObject(evidence)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:credit_decision.override_evidence must be object; got ${typeofTag(evidence)}`,
    );
  }
  assertExactKeySetWithPath(
    evidence,
    ACCOUNT_CHARGE_OVERRIDE_EVIDENCE_KEYS,
    'credit_decision.override_evidence',
  );
  assertUuidAt(evidence, 'approval_event_id', 'credit_decision.override_evidence.approval_event_id');
  assertEnum(
    evidence,
    'approval_scope',
    ['credit_limit_override', 'account_status_override'] as const,
  );
  assertUuidAt(evidence, 'override_event_id', 'credit_decision.override_evidence.override_event_id');
  assertNonEmptyStringAt(evidence, 'policy_version', 'credit_decision.override_evidence.policy_version');
  assertEnum(
    evidence,
    'target_account_status',
    ['active', 'suspended', 'closed', 'disputed'] as const,
  );
  assertMoneyStringAt(evidence, 'target_amount', money, scale, 'credit_decision.override_evidence.target_amount');
  assertUuidAt(evidence, 'target_customer_id', 'credit_decision.override_evidence.target_customer_id');
}

function validateAccountChargeLineItems(
  p: Record<string, unknown>,
  money: RegExp,
  scale: number,
): void {
  const lineItems = requireList(p, 'line_items');
  if (lineItems.length === 0) {
    throw new FiscalEventPayloadValidationError(
      'payload_line_items_empty:line_items must have >= 1 row',
    );
  }
  lineItems.forEach((row, index) => validateAccountChargeLineItem(index, row, money, scale));
}

function validateAccountChargeLineItem(
  index: number,
  row: unknown,
  money: RegExp,
  scale: number,
): void {
  if (!isPlainObject(row)) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_invalid:line_items[${index}] must be object; got ${typeofTag(row)}`,
    );
  }
  const path = `line_items[${index}]`;
  assertExactKeySetWithPath(row, ACCOUNT_CHARGE_LINE_ITEM_KEYS, path);
  assertUuidAt(row, 'line_uuid', `${path}.line_uuid`);
  assertMoneyStringAt(row, 'unit_price', money, scale, `${path}.unit_price`);
  assertMoneyStringAt(row, 'line_subtotal', money, scale, `${path}.line_subtotal`);
  assertMoneyStringAt(row, 'line_vat', money, scale, `${path}.line_vat`);
  assertMoneyStringAt(row, 'line_discount_amount', money, scale, `${path}.line_discount_amount`);
  assertMoneyStringAt(row, 'quantity', moneyRegex(QUANTITY_SCALE), QUANTITY_SCALE, `${path}.quantity`);
  assertMoneyStringAt(row, 'vat_rate', moneyRegex(VAT_RATE_SCALE), VAT_RATE_SCALE, `${path}.vat_rate`);
  assertNonEmptyStringAt(row, 'name', `${path}.name`);
  assertNonEmptyStringAt(row, 'product_id', `${path}.product_id`);
  assertOptionalNonEmptyStringAt(row, 'sku', `${path}.sku`);
  assertOptionalNonEmptyStringAt(row, 'gtin', `${path}.gtin`);
  assertOptionalNonEmptyStringAt(row, 'line_discount_reason', `${path}.line_discount_reason`);
  assertOptionalEnumAt(row, 'non_collected_subtype', NON_COLLECTED_SUBTYPES, `${path}.non_collected_subtype`);
  assertOptionalTaxCategoryCode(row['tax_category_code'], `${path}.tax_category_code`);
}

function validateAccountChargeBalanceSnapshot(
  snapshot: unknown,
  money: RegExp,
  scale: number,
): void {
  if (!isPlainObject(snapshot)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:local_balance_snapshot must be object; got ${typeofTag(snapshot)}`,
    );
  }
  assertExactKeySetWithPath(
    snapshot,
    ACCOUNT_CHARGE_BALANCE_SNAPSHOT_KEYS,
    'local_balance_snapshot',
  );
  for (const field of [
    'charge_amount',
    'credit_balance_before',
    'net_balance_before',
    'projected_credit_balance_after',
    'projected_net_balance_after',
    'projected_receivable_balance_after',
    'receivable_balance_before',
  ] as const) {
    assertMoneyStringAt(snapshot, field, money, scale, `local_balance_snapshot.${field}`);
  }
  assertIsoDateTimeMsAt(
    snapshot,
    'balance_updated_at',
    'local_balance_snapshot.balance_updated_at',
  );
}

function validateAccountChargeReferences(references: unknown): void {
  if (references === null) {
    return;
  }
  if (!isPlainObject(references)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:references must be object; got ${typeofTag(references)}`,
    );
  }
  assertExactKeySetWithPath(references, ACCOUNT_CHARGE_REFERENCES_KEYS, 'references');
  assertOptionalNonEmptyStringAt(references, 'external_reference', 'references.external_reference');
  assertOptionalNonEmptyStringAt(
    references,
    'related_sale_receipt_event_id',
    'references.related_sale_receipt_event_id',
  );
  if (references['related_sale_receipt_event_id'] !== null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_reference_forbidden:references.related_sale_receipt_event_id is reserved for post-v1 split-sale links',
    );
  }
  if (references['server_customer_alias_id'] !== null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_server_customer_alias_forbidden:references.server_customer_alias_id is reserved for server reconciliation',
    );
  }
}

function validateAccountChargeStaleness(staleness: unknown): void {
  if (!isPlainObject(staleness)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:staleness must be object; got ${typeofTag(staleness)}`,
    );
  }
  assertExactKeySetWithPath(staleness, ACCOUNT_CHARGE_STALENESS_KEYS, 'staleness');
  const customerStale = assertBoolAt(
    staleness,
    'customer_snapshot_stale',
    'staleness.customer_snapshot_stale',
  );
  const balanceStale = assertBoolAt(
    staleness,
    'balance_snapshot_stale',
    'staleness.balance_snapshot_stale',
  );
  if (staleness['mirror_last_synced_at'] !== null) {
    assertIsoDateTimeMsAt(
      staleness,
      'mirror_last_synced_at',
      'staleness.mirror_last_synced_at',
    );
  }
  assertOptionalEnumAt(
    staleness,
    'staleness_reason',
    ACCOUNT_PAYMENT_STALENESS_REASONS,
    'staleness.staleness_reason',
  );
  const reason = staleness['staleness_reason'];
  const stale = customerStale || balanceStale;
  if (stale && reason === null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_staleness_reason_required:staleness_reason required when any stale flag is true',
    );
  }
  if (!stale && reason !== null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_staleness_reason_mismatch:staleness_reason must be null when stale flags are false',
    );
  }
}

function validateAccountChargeTotals(
  totals: unknown,
  money: RegExp,
  scale: number,
): void {
  if (!isPlainObject(totals)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:totals must be object; got ${typeofTag(totals)}`,
    );
  }
  assertExactKeySetWithPath(totals, ACCOUNT_CHARGE_TOTALS_KEYS, 'totals');
  for (const field of ACCOUNT_CHARGE_TOTALS_KEYS) {
    assertMoneyStringAt(totals, field, money, scale, `totals.${field}`);
  }
}

function validateAccountChargeVatBreakdown(
  p: Record<string, unknown>,
  money: RegExp,
  scale: number,
): void {
  const vatBreakdown = requireList(p, 'vat_breakdown');
  if (vatBreakdown.length === 0) {
    throw new FiscalEventPayloadValidationError(
      'payload_vat_breakdown_empty:vat_breakdown must have >= 1 row',
    );
  }
  vatBreakdown.forEach((row, index) => validateVatBreakdownRow(index, row, money, scale));
}

function validateAccountChargeArithmetic(
  payload: Record<string, unknown>,
  scale: number,
): void {
  const totals = payload['totals'];
  const snapshot = payload['local_balance_snapshot'];
  if (!isPlainObject(totals) || !isPlainObject(snapshot)) {
    return;
  }

  const subtotal = accountChargeMinorUnits(totals['subtotal'] as string, scale);
  const vatTotal = accountChargeMinorUnits(totals['vat_total'] as string, scale);
  const total = accountChargeMinorUnits(totals['total'] as string, scale);
  const discount = accountChargeMinorUnits(payload['transaction_discount_amount'] as string, scale);
  const grandTotalBeforeCharge = accountChargeMinorUnits(
    totals['grand_total_before_charge'] as string,
    scale,
  );
  if (grandTotalBeforeCharge !== total) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_amount_mismatch:grand_total_before_charge must equal totals.total',
    );
  }
  if (subtotal + vatTotal !== total + discount) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_amount_mismatch:subtotal_plus_vat must equal total_plus_discount',
    );
  }

  const amountCharged = accountChargeMinorUnits(
    totals['amount_charged_to_account'] as string,
    scale,
  );
  if (amountCharged !== total) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_amount_mismatch:amount_charged_to_account must equal totals.total',
    );
  }
  const chargeAmount = accountChargeMinorUnits(snapshot['charge_amount'] as string, scale);
  if (chargeAmount !== amountCharged) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_charge_amount_mismatch:local_balance_snapshot.charge_amount must equal totals.amount_charged_to_account',
    );
  }

  const receivableBefore = accountChargeMinorUnits(
    snapshot['receivable_balance_before'] as string,
    scale,
  );
  const projectedReceivable = accountChargeMinorUnits(
    snapshot['projected_receivable_balance_after'] as string,
    scale,
  );
  const expectedReceivable = receivableBefore + chargeAmount;
  if (expectedReceivable !== projectedReceivable) {
    throw new FiscalEventPayloadValidationError(
      `payload_account_charge_balance_mismatch:projected_receivable_balance_after expected ${accountChargeFormatMinorUnits(expectedReceivable, scale)} got ${snapshot['projected_receivable_balance_after'] as string}`,
    );
  }

  const projectedCredit = accountChargeMinorUnits(
    snapshot['projected_credit_balance_after'] as string,
    scale,
  );
  const projectedNet = accountChargeMinorUnits(
    snapshot['projected_net_balance_after'] as string,
    scale,
  );
  const expectedNet = projectedReceivable > projectedCredit
    ? projectedReceivable - projectedCredit
    : 0n;
  if (expectedNet !== projectedNet) {
    throw new FiscalEventPayloadValidationError(
      `payload_account_charge_balance_mismatch:projected_net_balance_after expected ${accountChargeFormatMinorUnits(expectedNet, scale)} got ${snapshot['projected_net_balance_after'] as string}`,
    );
  }
}

function accountChargeMinorUnits(value: string, scale: number): bigint {
  if (scale === 0) {
    return BigInt(value);
  }

  const [whole = '0', fractional = ''] = value.split('.');
  return BigInt(`${whole}${fractional.padEnd(scale, '0')}`);
}

function accountChargeFormatMinorUnits(value: bigint, scale: number): string {
  if (scale === 0) {
    return value.toString();
  }

  const denominator = 10n ** BigInt(scale);
  const whole = value / denominator;
  const fractional = (value % denominator).toString().padStart(scale, '0');

  return `${whole}.${fractional}`;
}

function rejectAccountChargePaymentsKeyRecursively(value: unknown, path = 'payload'): void {
  if (!isPlainObject(value) && !Array.isArray(value)) {
    return;
  }
  const entries = Array.isArray(value) ? value.entries() : Object.entries(value);
  for (const [key, child] of entries) {
    const segment = typeof key === 'number' ? `[${key}]` : `.${key}`;
    if (key === 'payments') {
      throw new FiscalEventPayloadValidationError(
        `payload_account_charge_payments_forbidden:payments is not valid anywhere on ACCOUNT_CHARGE at ${path}${segment}`,
      );
    }
    rejectAccountChargePaymentsKeyRecursively(child, `${path}${segment}`);
  }
}

function assertOptionalTaxCategoryCode(value: unknown, path: string): void {
  if (value === null) return;
  if (typeof value !== 'string') {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_tax_category_invalid:${path} must be string or null; got ${typeofTag(value)}`,
    );
  }
  if (value !== '' && !TAX_CATEGORY_CODE.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_tax_category_invalid:${path} must match ^[A-Z0-9_-]+$ or null; got ${jsonOrType(value)}`,
    );
  }
}

function validateSeller(p: Record<string, unknown>): string {
  const seller = p['seller'];
  if (!isPlainObject(seller)) {
    throw new FiscalEventPayloadValidationError(
      `payload_seller_invalid:must be object; got ${typeofTag(seller)}`,
    );
  }
  assertExactKeySetWithPath(seller, SELLER_KEYS, 'seller');

  assertNonEmptyStringAt(seller, 'name', 'seller.name');

  const jurisdiction = seller['tax_jurisdiction_country_code'];
  if (typeof jurisdiction !== 'string' || !ISO_3166_ALPHA_2.test(jurisdiction)) {
    throw new FiscalEventPayloadValidationError(
      `payload_seller_tax_jurisdiction_invalid:must be ISO 3166-1 alpha-2; got ${jsonOrType(jurisdiction)}`,
    );
  }

  assertTaxNumberForCountry(seller['tax_number'], jurisdiction, 'seller.tax_number');
  validateAddress(seller['address'], 'seller.address', /* required */ true);

  return jurisdiction;
}

function validateBuyer(p: Record<string, unknown>): void {
  const buyer = p['buyer'];
  if (buyer === null) {
    return;
  }
  if (!isPlainObject(buyer)) {
    throw new FiscalEventPayloadValidationError(
      `payload_buyer_invalid:must be object or null; got ${typeofTag(buyer)}`,
    );
  }
  assertExactKeySetWithPath(buyer, BUYER_KEYS, 'buyer');

  for (const field of ['codice_fiscale', 'contact_id', 'customer_id', 'name'] as const) {
    const value = buyer[field];
    if (value !== null && (typeof value !== 'string' || value === '')) {
      throw new FiscalEventPayloadValidationError(
        `payload_buyer_${field}_invalid:must be non-empty string or null; got ${jsonOrType(value)}`,
      );
    }
  }

  validateAddress(buyer['address'], 'buyer.address', /* required */ false);
  const buyerCountryCode =
    countryCodeFromAddress(buyer['address']) ?? sellerCountryCodeFromPayload(p);

  if (buyer['codice_fiscale'] !== null && buyer['codice_fiscale'] !== undefined) {
    assertBuyerCodiceFiscale(buyer['codice_fiscale']);
  }

  if (buyer['tax_number'] !== null && buyer['tax_number'] !== undefined) {
    assertTaxNumberForCountry(buyer['tax_number'], buyerCountryCode, 'buyer.tax_number', true);
  }
}

function validateAddress(address: unknown, path: string, required: boolean): void {
  if (address === null) {
    if (required) {
      throw new FiscalEventPayloadValidationError(
        `payload_address_invalid:${path} is required; got null`,
      );
    }
    return;
  }
  if (!isPlainObject(address)) {
    throw new FiscalEventPayloadValidationError(
      `payload_address_invalid:${path} must be object; got ${typeofTag(address)}`,
    );
  }
  assertExactKeySetWithPath(address, ADDRESS_KEYS, path);
  assertNonEmptyStringAt(address, 'city', `${path}.city`);
  assertNonEmptyStringAt(address, 'postal_code', `${path}.postal_code`);
  assertNonEmptyStringAt(address, 'street', `${path}.street`);
  const cc = address['country_code'];
  if (typeof cc !== 'string' || !ISO_3166_ALPHA_2.test(cc)) {
    throw new FiscalEventPayloadValidationError(
      `payload_address_country_code_invalid:${path}.country_code must be ISO 3166-1 alpha-2; got ${jsonOrType(cc)}`,
    );
  }
}

function validateAccountPaymentCustomer(customer: unknown, sellerCountryCode: string): void {
  if (!isPlainObject(customer)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:customer must be object; got ${typeofTag(customer)}`,
    );
  }
  assertExactKeySetWithPath(customer, ACCOUNT_PAYMENT_CUSTOMER_KEYS, 'customer');
  assertUuidAt(customer, 'customer_id', 'customer.customer_id');
  assertEnum(
    customer,
    'customer_sync_status',
    ACCOUNT_PAYMENT_CUSTOMER_SYNC_STATUSES,
  );
  assertNonEmptyStringAt(customer, 'name', 'customer.name');
  assertOptionalNonEmptyStringAt(customer, 'phone', 'customer.phone');
  assertOptionalNonEmptyStringAt(customer, 'email', 'customer.email');
  assertOptionalNonEmptyStringAt(customer, 'customer_category', 'customer.customer_category');
  validateAddress(customer['address'], 'customer.address', /* required */ false);
  if (customer['tax_number'] !== null && customer['tax_number'] !== undefined) {
    assertTaxNumberForCountry(
      customer['tax_number'],
      countryCodeFromAddress(customer['address']) ?? sellerCountryCode,
      'customer.tax_number',
      true,
    );
  }
}

function validateAccountPaymentPayment(
  payment: unknown,
  money: RegExp,
  scale: number,
  training: boolean,
): void {
  if (!isPlainObject(payment)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:payment must be object; got ${typeofTag(payment)}`,
    );
  }
  assertExactKeySetWithPath(payment, ACCOUNT_PAYMENT_PAYMENT_KEYS, 'payment');
  assertMoneyStringAt(payment, 'amount', money, scale, 'payment.amount');
  if (!training && isZeroMoney(payment['amount'] as string)) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_payment_amount_zero:payment.amount must be greater than zero unless training_flag=true',
    );
  }
  assertNonEmptyStringAt(payment, 'method_code', 'payment.method_code');
  assertOptionalNonEmptyStringAt(payment, 'repository_id', 'payment.repository_id');
  assertOptionalNonEmptyStringAt(payment, 'instrument_serial', 'payment.instrument_serial');
  assertOptionalNonEmptyStringAt(payment, 'instrument_type', 'payment.instrument_type');

  const foreignAmount = payment['foreign_currency_amount'];
  const foreignCode = payment['foreign_currency_code'];
  if (foreignAmount === null && foreignCode === null) {
    return;
  }
  if (foreignAmount === null || foreignCode === null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_payment_foreign_currency_pair_invalid:payment foreign_currency_amount and foreign_currency_code must both be null or both non-null',
    );
  }
  if (typeof foreignCode !== 'string' || !ISO_4217.test(foreignCode)) {
    throw new FiscalEventPayloadValidationError(
      `payload_account_payment_foreign_currency_code_invalid:payment.foreign_currency_code must be ISO 4217 alpha-3 uppercase; got ${jsonOrType(foreignCode)}`,
    );
  }
  const foreignScale = CURRENCY_SCALES[foreignCode];
  if (foreignScale === undefined) {
    throw new FiscalEventPayloadValidationError(
      `payload_account_payment_foreign_currency_unknown:payment.foreign_currency_code=${foreignCode} has no registered scale`,
    );
  }
  assertMoneyStringAt(
    payment,
    'foreign_currency_amount',
    moneyRegex(foreignScale),
    foreignScale,
    'payment.foreign_currency_amount',
  );
}

function validateAccountPaymentBalanceSnapshot(
  snapshot: unknown,
  money: RegExp,
  scale: number,
): void {
  if (!isPlainObject(snapshot)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:local_balance_snapshot must be object; got ${typeofTag(snapshot)}`,
    );
  }
  assertExactKeySetWithPath(
    snapshot,
    ACCOUNT_PAYMENT_BALANCE_SNAPSHOT_KEYS,
    'local_balance_snapshot',
  );
  for (const field of [
    'credit_balance_before',
    'net_balance_before',
    'payment_amount',
    'projected_credit_balance_after',
    'projected_net_balance_after',
    'projected_receivable_balance_after',
    'receivable_balance_before',
  ] as const) {
    assertMoneyStringAt(snapshot, field, money, scale, `local_balance_snapshot.${field}`);
  }
  assertIsoDateTimeMsAt(
    snapshot,
    'balance_updated_at',
    'local_balance_snapshot.balance_updated_at',
  );
}

function validateAccountPaymentStaleness(staleness: unknown): void {
  if (!isPlainObject(staleness)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:staleness must be object; got ${typeofTag(staleness)}`,
    );
  }
  assertExactKeySetWithPath(staleness, ACCOUNT_PAYMENT_STALENESS_KEYS, 'staleness');
  const customerStale = assertBoolAt(
    staleness,
    'customer_snapshot_stale',
    'staleness.customer_snapshot_stale',
  );
  const balanceStale = assertBoolAt(
    staleness,
    'balance_snapshot_stale',
    'staleness.balance_snapshot_stale',
  );
  if (staleness['mirror_last_synced_at'] !== null) {
    assertIsoDateTimeMsAt(
      staleness,
      'mirror_last_synced_at',
      'staleness.mirror_last_synced_at',
    );
  }
  assertOptionalEnumAt(
    staleness,
    'staleness_reason',
    ACCOUNT_PAYMENT_STALENESS_REASONS,
    'staleness.staleness_reason',
  );
  const reason = staleness['staleness_reason'];
  const stale = customerStale || balanceStale;
  if (stale && reason === null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_payment_staleness_reason_required:staleness_reason required when any stale flag is true',
    );
  }
  if (!stale && reason !== null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_payment_staleness_reason_mismatch:staleness_reason must be null when stale flags are false',
    );
  }
}

function validateAccountPaymentReferences(references: unknown): void {
  if (references === null) {
    return;
  }
  if (!isPlainObject(references)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:references must be object; got ${typeofTag(references)}`,
    );
  }
  assertExactKeySetWithPath(references, ACCOUNT_PAYMENT_REFERENCES_KEYS, 'references');
  assertOptionalNonEmptyStringAt(references, 'external_reference', 'references.external_reference');
  assertOptionalNonEmptyStringAt(
    references,
    'related_sale_receipt_event_id',
    'references.related_sale_receipt_event_id',
  );
  if (references['server_customer_alias_id'] !== null) {
    throw new FiscalEventPayloadValidationError(
      'payload_account_payment_server_customer_alias_forbidden:references.server_customer_alias_id is reserved for ACCOUNT_PAYMENT_RECONCILED',
    );
  }
}

function validateOriginalReceiptReference(
  p: Record<string, unknown>,
  invoiceTypeCode: InvoiceTypeCode,
): void {
  const ref = p['original_receipt_reference'];
  const refundOrVoid = invoiceTypeCode === 'REFUND' || invoiceTypeCode === 'VOID';

  if (ref === null) {
    if (refundOrVoid) {
      throw new FiscalEventPayloadValidationError(
        `payload_invoice_type_invalid:original_receipt_reference required when invoice_type_code=${JSON.stringify(invoiceTypeCode)}`,
      );
    }
    return;
  }
  if (!isPlainObject(ref)) {
    throw new FiscalEventPayloadValidationError(
      `payload_original_receipt_reference_invalid:must be object or null; got ${typeofTag(ref)}`,
    );
  }
  assertExactKeySetWithPath(ref, ORIGINAL_RECEIPT_REFERENCE_KEYS, 'original_receipt_reference');
  assertUuidAt(ref, 'fiscal_event_id', 'original_receipt_reference.fiscal_event_id');
  assertCalendarDateAt(ref, 'original_business_date', 'original_receipt_reference.original_business_date');
  assertUuidAt(ref, 'original_receipt_uuid', 'original_receipt_reference.original_receipt_uuid');
  assertNonEmptyStringAt(ref, 'refund_reason', 'original_receipt_reference.refund_reason');

  if (!refundOrVoid) {
    throw new FiscalEventPayloadValidationError(
      `payload_invoice_type_invalid:original_receipt_reference present but invoice_type_code=${JSON.stringify(invoiceTypeCode)}`,
    );
  }
}

function validateLineItem(index: number, row: unknown, money: RegExp, scale: number): void {
  if (!isPlainObject(row)) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_invalid:line_items[${index}] must be object; got ${typeofTag(row)}`,
    );
  }
  const path = `line_items[${index}]`;
  assertExactKeySetWithPath(row, LINE_ITEM_KEYS, path);

  assertMoneyStringAt(row, 'unit_price', money, scale, `${path}.unit_price`);
  assertMoneyStringAt(row, 'line_subtotal', money, scale, `${path}.line_subtotal`);
  assertMoneyStringAt(row, 'line_vat', money, scale, `${path}.line_vat`);
  assertMoneyStringAt(row, 'line_discount_amount', money, scale, `${path}.line_discount_amount`);
  assertMoneyStringAt(row, 'quantity', moneyRegex(QUANTITY_SCALE), QUANTITY_SCALE, `${path}.quantity`);
  assertMoneyStringAt(row, 'vat_rate', moneyRegex(VAT_RATE_SCALE), VAT_RATE_SCALE, `${path}.vat_rate`);

  assertNonEmptyStringAt(row, 'name', `${path}.name`);
  assertNonEmptyStringAt(row, 'product_id', `${path}.product_id`);
  assertNonEmptyStringAt(row, 'sku', `${path}.sku`);

  const tcc = row['tax_category_code'];
  if (typeof tcc !== 'string') {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_tax_category_invalid:${path}.tax_category_code must be string; got ${typeofTag(tcc)}`,
    );
  }
  if (tcc !== '' && !TAX_CATEGORY_CODE.test(tcc)) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_tax_category_invalid:${path}.tax_category_code must match ^[A-Z0-9_-]+$; got ${jsonOrType(tcc)}`,
    );
  }

  for (const f of ['gtin', 'line_discount_reason'] as const) {
    const v = row[f];
    if (v !== null && (typeof v !== 'string' || v === '')) {
      throw new FiscalEventPayloadValidationError(
        `payload_line_item_${f}_invalid:${path}.${f} must be non-empty string or null; got ${jsonOrType(v)}`,
      );
    }
  }

  const ncs = row['non_collected_subtype'];
  if (ncs !== null && (typeof ncs !== 'string' || !(NON_COLLECTED_SUBTYPES as ReadonlyArray<string>).includes(ncs))) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_non_collected_subtype_invalid:${path}.non_collected_subtype must be one of ${NON_COLLECTED_SUBTYPES.join('|')} or null; got ${jsonOrType(ncs)}`,
    );
  }

  // SaleReceiptV2 (M4) variant identity: nullable strings; variant_id must
  // be a UUID when present; a variant_name/variant_sku without variant_id
  // is an orphan identity and must never be signed.
  const variantId = row['variant_id'];
  if (variantId !== null && (typeof variantId !== 'string' || !LOWER_HEX_UUID.test(variantId))) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_item_variant_id_invalid:${path}.variant_id must be UUID or null; got ${jsonOrType(variantId)}`,
    );
  }
  for (const f of ['variant_name', 'variant_sku'] as const) {
    const v = row[f];
    if (v !== null && (typeof v !== 'string' || v === '')) {
      throw new FiscalEventPayloadValidationError(
        `payload_line_item_${f}_invalid:${path}.${f} must be non-empty string or null; got ${jsonOrType(v)}`,
      );
    }
    if (v !== null && variantId === null) {
      throw new FiscalEventPayloadValidationError(
        `payload_line_item_variant_orphan:${path}.${f} present without variant_id`,
      );
    }
  }

  // Line-level discount-reason consistency (symmetric with invoice-level rule).
  const lda = row['line_discount_amount'] as string;
  const ldr = row['line_discount_reason'];
  const isZero = isZeroMoney(lda);
  if (isZero && ldr !== null) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_discount_reason_mismatch:${path}:amount=${lda}:reason_present=true`,
    );
  }
  if (!isZero && ldr === null) {
    throw new FiscalEventPayloadValidationError(
      `payload_line_discount_reason_mismatch:${path}:amount=${lda}:reason_present=false`,
    );
  }
}

function validatePaymentRow(index: number, row: unknown, money: RegExp, scale: number): void {
  if (!isPlainObject(row)) {
    throw new FiscalEventPayloadValidationError(
      `payload_payment_invalid:payments[${index}] must be object; got ${typeofTag(row)}`,
    );
  }
  const path = `payments[${index}]`;
  assertExactKeySetWithPath(row, PAYMENT_KEYS, path);

  assertMoneyStringAt(row, 'amount', money, scale, `${path}.amount`);
  assertNonEmptyStringAt(row, 'method_code', `${path}.method_code`);

  for (const f of ['instrument_serial', 'instrument_type'] as const) {
    const v = row[f];
    if (v !== null && (typeof v !== 'string' || v === '')) {
      throw new FiscalEventPayloadValidationError(
        `payload_payment_${f}_invalid:${path}.${f} must be non-empty string or null; got ${jsonOrType(v)}`,
      );
    }
  }

  const fca = row['foreign_currency_amount'];
  const fcc = row['foreign_currency_code'];
  if (fca === null && fcc === null) {
    return;
  }
  if (fca === null || fcc === null) {
    throw new FiscalEventPayloadValidationError(
      `payload_payment_foreign_currency_pair_invalid:${path} — foreign_currency_amount and foreign_currency_code must both be null or both non-null`,
    );
  }
  if (typeof fcc !== 'string' || !ISO_4217.test(fcc)) {
    throw new FiscalEventPayloadValidationError(
      `payload_payment_foreign_currency_code_invalid:${path}.foreign_currency_code must be ISO 4217 alpha-3 uppercase; got ${jsonOrType(fcc)}`,
    );
  }
  const foreignScale = CURRENCY_SCALES[fcc];
  if (foreignScale === undefined) {
    throw new FiscalEventPayloadValidationError(
      `payload_payment_foreign_currency_unknown:${path}.foreign_currency_code=${fcc} has no registered scale (extend CURRENCY_SCALES)`,
    );
  }
  if (!SUPPORTED_CURRENCY_SCALES.includes(foreignScale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_unsupported:value=${foreignScale}:allowed=${SUPPORTED_CURRENCY_SCALES.join(',')}:source=${path}.foreign_currency_code=${fcc}`,
    );
  }
  assertMoneyStringAt(row, 'foreign_currency_amount', moneyRegex(foreignScale), foreignScale, `${path}.foreign_currency_amount`);
}

/**
 * True when the ticket was fully comped: `total` is BCMath-equivalent zero and
 * `transaction_discount_amount` equals `subtotal + vat_total` (v5 seals those
 * two POST-remise, so the sum is the residual gross — zero on a full comp).
 *
 * Structural only: both operands were already pinned to the currency scale by
 * the money checks above, so a string compare against the canonical zero is
 * exact. The authoritative arithmetic cross-check stays server-side
 * (synthesis v5 6.F).
 */
function isFullyCompedTicket(p: Record<string, unknown>, scale: number): boolean {
  const total = p['total'];
  const subtotal = p['subtotal'];
  const vatTotal = p['vat_total'];
  const discount = p['transaction_discount_amount'];
  if (typeof total !== 'string' || typeof subtotal !== 'string'
    || typeof vatTotal !== 'string' || typeof discount !== 'string') {
    return false;
  }

  return isZeroMoney(total)
    && isZeroMoney(subtotal)
    && isZeroMoney(vatTotal)
    && !isZeroMoney(discount)
    && bcformat(discount, scale) === discount;
}

function validateVatBreakdownRow(
  index: number,
  row: unknown,
  money: RegExp,
  scale: number,
  eventVersion: number = 1,
): void {
  if (!isPlainObject(row)) {
    throw new FiscalEventPayloadValidationError(
      `payload_vat_breakdown_invalid:vat_breakdown[${index}] must be object; got ${typeofTag(row)}`,
    );
  }
  const path = `vat_breakdown[${index}]`;
  // D-1: v5 rows carry the group's pro-rata share of the ticket remise.
  // `eventVersion` defaults to 1 so every non-SALE_RECEIPT caller (which
  // never threads a version) keeps the pre-D-1 five-key contract exactly.
  assertExactKeySetWithPath(
    row,
    eventVersion >= 5 ? SALE_RECEIPT_VAT_BREAKDOWN_KEYS_V5 : VAT_BREAKDOWN_KEYS,
    path,
  );
  if (eventVersion >= 5) {
    assertMoneyStringAt(row, 'discount_allocated', money, scale, `${path}.discount_allocated`);
  }

  assertMoneyStringAt(row, 'net_amount', money, scale, `${path}.net_amount`);
  assertMoneyStringAt(row, 'vat_amount', money, scale, `${path}.vat_amount`);
  assertMoneyStringAt(row, 'gross_amount', money, scale, `${path}.gross_amount`);
  assertMoneyStringAt(row, 'rate', moneyRegex(VAT_RATE_SCALE), VAT_RATE_SCALE, `${path}.rate`);

  const tcc = row['tax_category_code'];
  if (typeof tcc !== 'string') {
    throw new FiscalEventPayloadValidationError(
      `payload_vat_breakdown_tax_category_invalid:${path}.tax_category_code must be string; got ${typeofTag(tcc)}`,
    );
  }
  if (tcc !== '' && !TAX_CATEGORY_CODE.test(tcc)) {
    throw new FiscalEventPayloadValidationError(
      `payload_vat_breakdown_tax_category_invalid:${path}.tax_category_code must match ^[A-Z0-9_-]+$; got ${jsonOrType(tcc)}`,
    );
  }

  // Note: net + vat == gross arithmetic check stays server-side per
  // synthesis v5 §6.F. The PHP validator enforces it via BCMath; we
  // skip it here to avoid duplicating decimal arithmetic in JS.
}

function validateVoucherRow(index: number, row: unknown, money: RegExp, scale: number): void {
  if (!isPlainObject(row)) {
    throw new FiscalEventPayloadValidationError(
      `payload_voucher_invalid:vouchers_redeemed[${index}] must be object; got ${typeofTag(row)}`,
    );
  }
  const path = `vouchers_redeemed[${index}]`;
  assertExactKeySetWithPath(row, VOUCHER_KEYS, path);
  assertMoneyStringAt(row, 'redeemed_amount', money, scale, `${path}.redeemed_amount`);
  assertNonEmptyStringAt(row, 'voucher_code', `${path}.voucher_code`);
}

// -------------------------------------------------------------------
// Shared assertion helpers — return-typed where the caller needs the
// narrowed value back. Forensic-prefix conventions mirror PHP exactly.
// -------------------------------------------------------------------

function isPlainObject(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function requireList(bag: Record<string, unknown>, key: string): unknown[] {
  const items = bag[key];
  if (!Array.isArray(items)) {
    throw new FiscalEventPayloadValidationError(
      `payload_${key}_invalid:must be a JSON list; got ${typeofTag(items)}`,
    );
  }
  for (let index = 0; index < items.length; index += 1) {
    if (!Object.prototype.hasOwnProperty.call(items, index)) {
      throw new FiscalEventPayloadValidationError(
        `payload_${key}_invalid:must be a dense JSON list; missing index ${index}`,
      );
    }
  }
  return items;
}

function assertExactKeySet(
  payload: Record<string, unknown>,
  expected: ReadonlyArray<string>,
  eventTypeLabel: string,
): void {
  const expectedSet = new Set<string>(expected);
  const actual = Object.keys(payload);
  const missing: string[] = [];
  for (const key of expected) {
    if (!Object.prototype.hasOwnProperty.call(payload, key)) {
      missing.push(key);
    }
  }
  if (missing.length > 0) {
    missing.sort();
    throw new FiscalEventPayloadValidationError(
      `payload_missing_required:${missing.join(',')} — ${eventTypeLabel} payload missing required key(s).`,
    );
  }
  const extras: string[] = [];
  for (const key of actual) {
    if (!expectedSet.has(key)) {
      extras.push(key);
    }
  }
  if (extras.length > 0) {
    extras.sort();
    throw new FiscalEventPayloadValidationError(
      `payload_extra_field:${extras.join(',')} — ${eventTypeLabel} payload carries unknown top-level key(s) not in PHP FiscalPayloadConstraintValidator::PAYLOAD_KEYS. Allowed: ${[...expectedSet].sort().join(', ')}.`,
    );
  }
}

function assertExactKeySetWithPath(
  bag: Record<string, unknown>,
  expected: ReadonlyArray<string>,
  path: string,
): void {
  const expectedSet = new Set<string>(expected);
  const missing: string[] = [];
  for (const key of expected) {
    if (!Object.prototype.hasOwnProperty.call(bag, key)) {
      missing.push(key);
    }
  }
  if (missing.length > 0) {
    missing.sort();
    throw new FiscalEventPayloadValidationError(
      `payload_${path}_missing_keys:${missing.join(',')}`,
    );
  }
  const extras: string[] = [];
  for (const key of Object.keys(bag)) {
    if (!expectedSet.has(key)) {
      extras.push(key);
    }
  }
  if (extras.length > 0) {
    extras.sort();
    throw new FiscalEventPayloadValidationError(
      `payload_${path}_extra_keys:${extras.join(',')}`,
    );
  }
}

function assertEnum<T extends string>(
  bag: Record<string, unknown>,
  field: string,
  allowed: ReadonlyArray<T>,
): T {
  const value = bag[field];
  if (typeof value !== 'string' || !(allowed as ReadonlyArray<string>).includes(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${field} must be one of ${allowed.join('|')}; got ${jsonOrType(value)}`,
    );
  }
  return value as T;
}

function assertOptionalEnum<T extends string>(
  bag: Record<string, unknown>,
  field: string,
  allowed: ReadonlyArray<T>,
): void {
  const value = bag[field];
  if (value === null) return;
  if (typeof value !== 'string' || !(allowed as ReadonlyArray<string>).includes(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${field} must be one of ${allowed.join('|')} or null; got ${jsonOrType(value)}`,
    );
  }
}

function assertOptionalEnumAt<T extends string>(
  bag: Record<string, unknown>,
  field: string,
  allowed: ReadonlyArray<T>,
  path: string,
): void {
  const value = bag[field];
  if (value === null) return;
  if (typeof value !== 'string' || !(allowed as ReadonlyArray<string>).includes(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be null or one of ${allowed.join('|')}; got ${jsonOrType(value)}`,
    );
  }
}

function assertBool(bag: Record<string, unknown>, field: string): boolean {
  return assertBoolAt(bag, field, field);
}

function assertBoolAt(bag: Record<string, unknown>, field: string, path: string): boolean {
  const value = bag[field];
  if (typeof value !== 'boolean') {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be boolean; got ${typeofTag(value)}`,
    );
  }
  return value;
}

function assertPositiveInt(bag: Record<string, unknown>, field: string): void {
  const value = bag[field];
  if (typeof value !== 'number' || !Number.isInteger(value) || value < 1) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${field} must be a positive integer (>= 1); got ${typeofTag(value)}`,
    );
  }
}

function assertUuid(bag: Record<string, unknown>, field: string): void {
  assertUuidAt(bag, field, field);
}

function assertUuidAt(bag: Record<string, unknown>, field: string, path: string): void {
  const value = bag[field];
  if (typeof value !== 'string' || !LOWER_HEX_UUID.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be lowercase-hex UUID (^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$); got ${jsonOrType(value)}`,
    );
  }
}

function assertNonEmptyString(bag: Record<string, unknown>, field: string): void {
  assertNonEmptyStringAt(bag, field, field);
}

function assertNonEmptyStringAt(bag: Record<string, unknown>, field: string, path: string): void {
  const value = bag[field];
  if (typeof value !== 'string' || value === '') {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be non-empty string; got ${jsonOrType(value)}`,
    );
  }
}

function assertOptionalNonEmptyString(bag: Record<string, unknown>, field: string): void {
  assertOptionalNonEmptyStringAt(bag, field, field);
}

function assertOptionalNonEmptyStringAt(
  bag: Record<string, unknown>,
  field: string,
  path: string,
): void {
  const value = bag[field];
  if (value === null) return;
  if (typeof value !== 'string' || value === '') {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be non-empty string or null; got ${jsonOrType(value)}`,
    );
  }
}

function assertCalendarDate(bag: Record<string, unknown>, field: string): void {
  assertCalendarDateAt(bag, field, field);
}

function assertCalendarDateAt(bag: Record<string, unknown>, field: string, path: string): void {
  const value = bag[field];
  if (typeof value !== 'string' || !ISO_8601_CALENDAR_DATE.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be YYYY-MM-DD; got ${jsonOrType(value)}`,
    );
  }
}

function assertIsoDateTimeMs(bag: Record<string, unknown>, field: string): void {
  assertIsoDateTimeMsAt(bag, field, field);
}

function assertIsoDateTimeMsAt(
  bag: Record<string, unknown>,
  field: string,
  path: string,
): void {
  const value = bag[field];
  if (typeof value !== 'string' || !ISO_8601_DATETIME_MS.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_field_invalid:${path} must be ISO 8601 with ms + tz offset (e.g. 2026-05-16T10:00:00.000Z); got ${jsonOrType(value)}`,
    );
  }
}

function assertMoneyString(
  bag: Record<string, unknown>,
  field: string,
  regex: RegExp,
  scale: number,
): void {
  assertMoneyStringAt(bag, field, regex, scale, field);
}

function assertMoneyStringAt(
  bag: Record<string, unknown>,
  field: string,
  regex: RegExp,
  scale: number,
  path: string,
): void {
  const value = bag[field];
  if (typeof value !== 'string') {
    throw new FiscalEventPayloadValidationError(
      `payload_money_invalid:field=${path}:value must be bcformat decimal string at scale ${scale}; got ${typeofTag(value)}`,
    );
  }
  if (!regex.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_money_scale_mismatch:field=${path}:value=${value}:expected_scale=${scale}`,
    );
  }
}

function assertCurrency(bag: Record<string, unknown>): number {
  const currencyCode = bag['currency_code'];
  if (typeof currencyCode !== 'string' || !ISO_4217.test(currencyCode)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_code_invalid:must be ISO 4217 alpha-3 uppercase; got ${jsonOrType(currencyCode)}`,
    );
  }

  const scale = bag['currency_scale'];
  if (typeof scale !== 'number' || !Number.isInteger(scale) || !SUPPORTED_CURRENCY_SCALES.includes(scale)) {
    throw new FiscalEventPayloadValidationError(
      `payload_currency_scale_unsupported:value=${jsonOrType(scale)}:allowed=${SUPPORTED_CURRENCY_SCALES.join(',')}`,
    );
  }

  return scale;
}

function assertNullableObject(bag: Record<string, unknown>, field: string): void {
  const value = bag[field];
  if (value !== null && (typeof value !== 'object' || Array.isArray(value))) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_or_null_required:${field}`,
    );
  }
}

function assertTaxNumberForCountry(
  value: unknown,
  countryCode: string,
  path: string,
  buyer = false,
): void {
  const taxNumber = assertTaxNumberBaseline(value, path);
  const normalized = normalizeTaxNumberForCountry(taxNumber, countryCode);
  const patterns: RegExp[] = [];
  const countryPattern = TAX_NUMBER_PATTERNS[countryCode];
  if (countryPattern) patterns.push(countryPattern);
  if (buyer && countryCode === 'FR') patterns.push(FR_BUYER_TVA_INTRACOM);

  if (patterns.length === 0) return;

  if (patterns.some((pattern) => pattern.test(normalized))) return;

  throw new FiscalEventPayloadValidationError(
    `payload_tax_number_format_mismatch:field=${path}:country=${countryCode}:value=${taxNumber}`,
  );
}

function assertBuyerCodiceFiscale(value: unknown): void {
  if (typeof value !== 'string' || value === '') {
    throw new FiscalEventPayloadValidationError(
      `payload_buyer_codice_fiscale_invalid:must be non-empty string or null; got ${jsonOrType(value)}`,
    );
  }
  if (!IT_BUYER_CODICE_FISCALE.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_buyer_codice_fiscale_format_mismatch:field=buyer.codice_fiscale:value=${value}`,
    );
  }
}

function normalizeTaxNumberForCountry(value: string, countryCode: string): string {
  if (countryCode === 'TN') return value.replace(/\//g, '');
  return value;
}

function sellerCountryCodeFromPayload(payload: Record<string, unknown>): string {
  const seller = payload['seller'];
  if (isPlainObject(seller) && typeof seller['tax_jurisdiction_country_code'] === 'string') {
    return seller['tax_jurisdiction_country_code'];
  }
  return '';
}

function countryCodeFromAddress(address: unknown): string | null {
  if (isPlainObject(address) && typeof address['country_code'] === 'string') {
    return address['country_code'];
  }
  return null;
}

function assertTaxNumberBaseline(value: unknown, path: string): string {
  if (typeof value !== 'string') {
    throw new FiscalEventPayloadValidationError(
      `payload_tax_number_invalid:${path} must be string; got ${typeofTag(value)}`,
    );
  }
  if (value === '' || value !== value.trim()) {
    throw new FiscalEventPayloadValidationError(
      `payload_tax_number_invalid:${path} must be non-empty and not have leading/trailing whitespace; got ${jsonOrType(value)}`,
    );
  }
  // Reject ASCII control bytes (< 0x20) and DEL (0x7F) - mirrors PHP
  // assertTaxNumber control-byte loop. The universal regex below also
  // rejects them via the [A-Za-z0-9 \-/.] class, but the explicit
  // check produces a more forensic error prefix. Built via `new RegExp`
  // to avoid putting raw control bytes in the TS source file.
  // eslint-disable-next-line no-control-regex
  const CONTROL_BYTES = new RegExp('[\\x00-\\x1f\\x7f]');
  const controlMatch = value.match(CONTROL_BYTES);
  if (controlMatch) {
    const offset = controlMatch.index ?? 0;
    const byteHex = controlMatch[0].charCodeAt(0).toString(16).padStart(2, '0').toUpperCase();
    throw new FiscalEventPayloadValidationError(
      `payload_tax_number_invalid:${path} contains control byte 0x${byteHex} at offset ${offset}`,
    );
  }
  if (!TAX_NUMBER_UNIVERSAL.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_tax_number_invalid:${path} must match ^[A-Za-z0-9 \\-/.]{4,40}$; got ${jsonOrType(value)}`,
    );
  }

  return value;
}

function validateNullableAssoc(value: unknown, path: string): void {
  if (value === null) {
    return;
  }
  if (!isPlainObject(value)) {
    throw new FiscalEventPayloadValidationError(
      `payload_object_invalid:${path} must be object; got ${typeofTag(value)}`,
    );
  }
}

function jsonOrType(value: unknown): string {
  if (typeof value === 'string') {
    return JSON.stringify(value);
  }
  if (typeof value === 'number' || typeof value === 'boolean') {
    return String(value);
  }
  return typeofTag(value);
}

/**
 * Assert `payload` has no top-level keys outside `allowed`. Mirrors PHP
 * `FiscalPayloadConstraintValidator::validatePayloadKeySet()` exactly —
 * `payload_extra_field:<csv>` prefix included so the error surface is
 * cross-language symmetric (one verifier rule, two enforcement points).
 *
 * @throws FiscalEventPayloadValidationError when any unknown key is found.
 */
function assertNoExtraTopLevelKeys(
  payload: Record<string, unknown>,
  allowed: ReadonlyArray<string>,
  eventTypeLabel: string,
): void {
  const allowedSet = new Set<string>(allowed);
  const extras: string[] = [];
  for (const key of Object.keys(payload)) {
    if (!allowedSet.has(key)) {
      extras.push(key);
    }
  }
  if (extras.length > 0) {
    throw new FiscalEventPayloadValidationError(
      `payload_extra_field:${extras.join(',')} — ${eventTypeLabel} payload carries unknown top-level key(s) ` +
        `not in PHP FiscalPayloadConstraintValidator::PAYLOAD_KEYS. ` +
        `Allowed: ${[...allowedSet].sort().join(', ')}.`,
    );
  }
}

function assertPlainPayload(payload: unknown, label: string): Record<string, unknown> {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(`${label} payload must be an object.`);
  }
  return payload as Record<string, unknown>;
}

function payloadTrainingFlag(payload: unknown): boolean | null {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    return null;
  }
  const value = (payload as Record<string, unknown>)['training_flag'];
  return typeof value === 'boolean' ? value : null;
}

/**
 * Validate CHAIN_BREAK_DETECTED payload — mirrors the PHP
 * `FiscalPayloadConstraintValidator::validateChainBreakDetectedPayload()`
 * constraint set EXACTLY. Closes Task 25 round-2 Codex T25-P2
 * (cross-language drift gate — TS must reject what PHP rejects) and
 * Task 25 round-3 Codex P1 (extras rejection mirroring PHP
 * `validatePayloadKeySet`).
 *
 *   - No extra top-level keys beyond `CHAIN_BREAK_DETECTED_PAYLOAD_KEYS`.
 *   - `last_good_hash` must be 64-char lowercase hex.
 *   - `offending_record_reference` must be a non-empty object.
 *   - If `offending_record_reference.observed_previous_hash` is present,
 *     it must be 64-char lowercase hex.
 */
function validateChainBreakDetectedPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'CHAIN_BREAK_DETECTED payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  assertNoExtraTopLevelKeys(p, CHAIN_BREAK_DETECTED_PAYLOAD_KEYS, 'CHAIN_BREAK_DETECTED');

  assertHashField(p, 'last_good_hash', 'CHAIN_BREAK_DETECTED.last_good_hash');
  assertNonEmptyAssoc(p, 'offending_record_reference', 'CHAIN_BREAK_DETECTED.offending_record_reference');

  const ref = p['offending_record_reference'] as Record<string, unknown>;
  if (Object.prototype.hasOwnProperty.call(ref, 'observed_previous_hash')) {
    assertHashField(
      ref,
      'observed_previous_hash',
      'CHAIN_BREAK_DETECTED.offending_record_reference.observed_previous_hash',
    );
  }
}

/**
 * Validate CHAIN_RESTART payload — mirrors the PHP
 * `FiscalPayloadConstraintValidator::validateChainRestartPayload()`
 * constraint set EXACTLY. Closes Task 25 round-2 Codex T25-P2 and
 * Task 25 round-3 Codex P1 (extras rejection mirroring PHP
 * `validatePayloadKeySet`).
 *
 *   - No extra top-level keys beyond `CHAIN_RESTART_PAYLOAD_KEYS`.
 *   - `new_genesis_reference` must be 64-char lowercase hex.
 *   - `last_good_anchor`, `operator_authorization_evidence`,
 *     `provenance_link` must each be non-empty objects.
 *   - If `last_good_anchor.hash` is present, it must be 64-char lowercase hex.
 */
function validateChainRestartPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError(
      'CHAIN_RESTART payload must be an object.',
    );
  }
  const p = payload as Record<string, unknown>;

  assertNoExtraTopLevelKeys(p, CHAIN_RESTART_PAYLOAD_KEYS, 'CHAIN_RESTART');

  assertHashField(p, 'new_genesis_reference', 'CHAIN_RESTART.new_genesis_reference');
  assertNonEmptyAssoc(p, 'last_good_anchor', 'CHAIN_RESTART.last_good_anchor');
  assertNonEmptyAssoc(
    p,
    'operator_authorization_evidence',
    'CHAIN_RESTART.operator_authorization_evidence',
  );
  assertNonEmptyAssoc(p, 'provenance_link', 'CHAIN_RESTART.provenance_link');

  const anchor = p['last_good_anchor'] as Record<string, unknown>;
  if (Object.prototype.hasOwnProperty.call(anchor, 'hash')) {
    assertHashField(anchor, 'hash', 'CHAIN_RESTART.last_good_anchor.hash');
  }
}

function validatePhase4Common(p: Record<string, unknown>, label: string): void {
  assertUuid(p, 'tenant_id');
  assertUuid(p, 'company_id');
  assertUuid(p, 'terminal_id');
  assertIsoDateTimeMs(p, 'event_time_device');
  assertBoolAt(p, 'training_flag', `${label}.training_flag`);
}

function validateAccountStatusChangedPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError('ACCOUNT_STATUS_CHANGED payload must be an object.');
  }
  const p = payload as Record<string, unknown>;
  assertExactKeySet(p, ACCOUNT_STATUS_CHANGED_PAYLOAD_KEYS, 'ACCOUNT_STATUS_CHANGED');
  validatePhase4Common(p, 'ACCOUNT_STATUS_CHANGED');
  assertUuid(p, 'actor_user_id');
  assertUuid(p, 'partner_id');
  assertEnum(p, 'old_status', ['active', 'suspended', 'closed', 'disputed']);
  assertEnum(p, 'new_status', ['active', 'suspended', 'closed', 'disputed']);
  assertNonEmptyString(p, 'reason');
  if (typeof p['status_version'] !== 'number' || !Number.isInteger(p['status_version']) || p['status_version'] < 1) {
    throw new FiscalEventPayloadValidationError('payload_field_invalid:status_version must be positive integer');
  }
  assertNonEmptyAssoc(p, 'partner_snapshot', 'ACCOUNT_STATUS_CHANGED.partner_snapshot');
}

function validateOperatorApprovalGrantedPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError('OPERATOR_APPROVAL_GRANTED payload must be an object.');
  }
  const p = payload as Record<string, unknown>;
  assertExactKeySet(p, OPERATOR_APPROVAL_GRANTED_PAYLOAD_KEYS, 'OPERATOR_APPROVAL_GRANTED');
  validatePhase4Common(p, 'OPERATOR_APPROVAL_GRANTED');
  assertUuid(p, 'approval_id');
  assertEnum(p, 'approval_scope', [
    'close_shift_variance',
    'credit_limit_override',
    'account_status_override',
    'discount_limit_override',
    'tender_tolerance_override',
    'void_or_return_override',
    'cash_drawer_control',
    // v3-refund-chain-integration spec §4.5 errata T6 — the solo
    // payout-dispute audit event reuses OPERATOR_APPROVAL_GRANTED's
    // existing shape rather than authoring a sixth OVERRIDE_* event type.
    // A site the spec's own §17 manifest missed (it named only
    // posOverrideAuthoring.ts's two TS sites + the two PHP
    // FiscalPayloadConstraintValidator sites) — this device-side engine
    // validator is a fifth, independent gate that must accept the
    // literal too, or every dispute-evidence append is rejected locally
    // before it can ever reach the network.
    'payout_dispute_evidence',
  ]);
  assertUuid(p, 'cashier_user_id');
  assertUuid(p, 'supervisor_user_id');
  assertNonEmptyString(p, 'policy_version');
  assertNonEmptyString(p, 'reason_code');
  assertOptionalNonEmptyString(p, 'reason_text');
  assertIsoDateTimeMs(p, 'requested_at_device');
  assertIsoDateTimeMs(p, 'resolved_at_device');
  validateSupervisorUserSnapshot(p, 'OPERATOR_APPROVAL_GRANTED.supervisor_user_snapshot');
  validatePhase4TargetObject(p, 'OPERATOR_APPROVAL_GRANTED.target');
  const regimeExtensions = p['regime_extensions'];
  if (regimeExtensions !== null && (typeof regimeExtensions !== 'object' || Array.isArray(regimeExtensions))) {
    throw new FiscalEventPayloadValidationError('payload_field_invalid:regime_extensions must be object or null');
  }
}

function validatePhase4OverridePayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError('OVERRIDE payload must be an object.');
  }
  const p = payload as Record<string, unknown>;
  assertExactKeySet(p, PHASE4_OVERRIDE_PAYLOAD_KEYS, 'OVERRIDE');
  validatePhase4Common(p, 'OVERRIDE');
  assertUuid(p, 'approval_event_id');
  assertUuid(p, 'approval_id');
  assertEnum(p, 'approval_scope', [
    'credit_limit_override',
    'account_status_override',
    'discount_limit_override',
    'tender_tolerance_override',
    'void_or_return_override',
  ]);
  assertNonEmptyString(p, 'policy_version');
  assertNonEmptyString(p, 'reason_code');
  assertOptionalNonEmptyString(p, 'reason_text');
  assertUuid(p, 'supervisor_user_id');
  validateOverrideContext(p, 'OVERRIDE.override_context');
  validatePhase4TargetObject(p, 'OVERRIDE.target');
}

function validateCashDrawerMovementPayload(payload: unknown): void {
  if (typeof payload !== 'object' || payload === null || Array.isArray(payload)) {
    throw new FiscalEventPayloadValidationError('Cash drawer movement payload must be an object.');
  }
  const p = payload as Record<string, unknown>;
  assertExactKeySet(p, CASH_DRAWER_MOVEMENT_PAYLOAD_KEYS, 'CASH_DRAWER_MOVEMENT');
  validatePhase4Common(p, 'CASH_DRAWER_MOVEMENT');
  assertUuid(p, 'approval_event_id');
  assertUuid(p, 'approval_id');
  assertEnum(p, 'approval_scope', ['cash_drawer_control']);
  assertMoneyString(p, 'amount', moneyRegex(3), 3);
  assertEnum(p, 'operation_type', ['DEPOSIT', 'PAYOUT']);
  assertNonEmptyString(p, 'reason');
  assertUuid(p, 'shift_id');
  assertUuid(p, 'supervisor_user_id');
  assertUuid(p, 'target_reference_id');
}

function validateSessionOpenPayload(payload: unknown): void {
  const p = assertPlainPayload(payload, 'SESSION_OPEN');
  assertExactKeySet(p, SESSION_OPEN_PAYLOAD_KEYS, 'SESSION_OPEN');
  assertUuid(p, 'session_id');
  assertUuid(p, 'shift_id');
  assertCalendarDate(p, 'business_date');
  assertIsoDateTimeMs(p, 'opened_at_device');
  assertUuid(p, 'operator_id');
  assertNonEmptyString(p, 'operator_name');
  assertUuid(p, 'terminal_id');
  assertNonEmptyString(p, 'terminal_label');
  assertPositiveInt(p, 'shift_number');
  const scale = assertCurrency(p);
  assertMoneyString(p, 'opening_float_amount', moneyRegex(scale), scale);
  assertBool(p, 'training_flag');
}

function validateZCashDrawerMovementPayload(payload: unknown): void {
  const p = assertPlainPayload(payload, 'Z_CASH_DRAWER_MOVEMENT');
  assertExactKeySet(p, Z_CASH_DRAWER_MOVEMENT_PAYLOAD_KEYS, 'Z_CASH_DRAWER_MOVEMENT');
  assertUuid(p, 'movement_id');
  assertUuid(p, 'session_id');
  assertUuid(p, 'shift_id');
  assertEnum(p, 'movement_type', ['OPENING_FLOAT', 'CASH_IN', 'CASH_OUT', 'SAFE_DROP', 'CASH_CORRECTION']);
  assertCalendarDate(p, 'business_date');
  assertIsoDateTimeMs(p, 'event_time_device');
  assertUuid(p, 'operator_id');
  assertNonEmptyString(p, 'operator_name');
  const scale = assertCurrency(p);
  assertMoneyString(p, 'amount', moneyRegex(scale), scale);
  assertNonEmptyString(p, 'reason_code');
  assertOptionalNonEmptyString(p, 'reason_text');
  assertOptionalNonEmptyString(p, 'cash_drawer_operation_id');
  assertNullableObject(p, 'approval');
  assertBool(p, 'training_flag');
}

function validateZReportFamilyPayload(
  payload: unknown,
  keys: ReadonlyArray<string>,
  label: string,
): void {
  const p = assertPlainPayload(payload, label);
  assertExactKeySet(p, keys, label);
  for (const field of ['session_id', 'shift_id', 'operator_id', 'terminal_id']) {
    assertUuid(p, field);
  }
  assertCalendarDate(p, 'business_date');
  assertBool(p, 'training_flag');
}

function validateSupervisorUserSnapshot(payload: Record<string, unknown>, path: string): void {
  assertNonEmptyAssoc(payload, 'supervisor_user_snapshot', path);
  const snapshot = payload['supervisor_user_snapshot'] as Record<string, unknown>;
  assertNonEmptyStringAt(snapshot, 'name', `${path}.name`);
  const roles = snapshot['roles'];
  if (!Array.isArray(roles)) {
    throw new FiscalEventPayloadValidationError(`payload_field_invalid:${path}.roles must be array`);
  }
  roles.forEach((role, index) => {
    if (typeof role !== 'string' || role === '') {
      throw new FiscalEventPayloadValidationError(`payload_field_invalid:${path}.roles.${String(index)}`);
    }
  });
}

function validateOverrideContext(payload: Record<string, unknown>, path: string): void {
  assertNonEmptyAssoc(payload, 'override_context', path);
  const context = payload['override_context'] as Record<string, unknown>;
  assertNonEmptyStringAt(context, 'target_event_type', `${path}.target_event_type`);
  assertNonEmptyStringAt(context, 'target_reference_id', `${path}.target_reference_id`);
}

function validatePhase4TargetObject(payload: Record<string, unknown>, path: string): void {
  assertNonEmptyAssoc(payload, 'target', path);
  const target = payload['target'] as Record<string, unknown>;
  for (const scopeField of ['tenant_id', 'company_id', 'terminal_id'] as const) {
    if (scopeField in target && target[scopeField] !== payload[scopeField]) {
      throw new FiscalEventPayloadValidationError(`payload_scope_mismatch:${path}.${scopeField}`);
    }
  }
}

/**
 * Assert a field on `bag` is a 64-char lowercase hex string. Mirrors
 * `FiscalPayloadConstraintValidator::assertHashField()` exactly. Throws
 * `FiscalEventPayloadValidationError` on violation.
 */
function assertHashField(bag: Record<string, unknown>, field: string, label: string): void {
  const value = bag[field];
  if (typeof value !== 'string' || !LOWER_HEX_64.test(value)) {
    throw new FiscalEventPayloadValidationError(
      `${label} must be 64-char lowercase hex; got ${typeofTag(value)}${
        typeof value === 'string' ? ` (${JSON.stringify(value)})` : ''
      }.`,
    );
  }
}

/**
 * Assert a field on `bag` is a non-empty object (not null, not an array,
 * not a string-keyed empty object). Mirrors
 * `FiscalPayloadConstraintValidator::validateNonEmptyAssoc()` exactly.
 * Throws `FiscalEventPayloadValidationError` on violation.
 */
function assertNonEmptyAssoc(bag: Record<string, unknown>, field: string, label: string): void {
  const value = bag[field];
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw new FiscalEventPayloadValidationError(
      `${label} must be a non-empty object; got ${typeofTag(value)}.`,
    );
  }
  if (Object.keys(value as Record<string, unknown>).length === 0) {
    throw new FiscalEventPayloadValidationError(
      `${label} must be a non-empty object; got empty object.`,
    );
  }
}

function typeofTag(value: unknown): string {
  if (value === null) return 'null';
  if (Array.isArray(value)) return 'array';
  return typeof value;
}

/**
 * Detect a chain-sequence UNIQUE violation on `fiscal_events`. SQLite
 * surfaces it as a string containing `UNIQUE constraint failed:
 * fiscal_events.tenant_id, fiscal_events.terminal_id,
 * fiscal_events.sequence_number` (the index name varies by sqlite
 * version + plugin, so we match on column names which are stable).
 *
 * Only the (terminal_id, sequence_number) collision is treated as
 * concurrent-chain-advance. A collision on the
 * (source_event_class, source_event_id) partial UNIQUE is a different
 * class of incident (a cross-terminal source-id collision under the
 * engine-side scope from Task 15 round-2 P2-1) and falls through to
 * the generic `throw` so the caller / recovery flow can disambiguate.
 */
function isChainSequenceUniqueViolation(error: unknown): boolean {
  const message =
    error instanceof Error
      ? error.message
      : typeof error === 'string'
        ? error
        : '';
  return (
    /UNIQUE constraint failed/i.test(message) &&
    /fiscal_events\.terminal_id/i.test(message) &&
    /fiscal_events\.sequence_number/i.test(message)
  );
}

// -------------------------------------------------------------------
// Row shape helpers — narrow `unknown` SELECT rows into the typed result.
// -------------------------------------------------------------------

interface FiscalEventRowShape {
  id: unknown;
  tenant_id: unknown;
  company_id: unknown;
  terminal_id: unknown;
  operator_id: unknown;
  event_type: unknown;
  chain_context: unknown;
  event_version: unknown;
  signature_version: unknown;
  sequence_number: unknown;
  event_time_device: unknown;
  business_date: unknown;
  reference_event_id: unknown;
  reference_document_id: unknown;
  source_event_class: unknown;
  source_event_id: unknown;
  canonical_bytes: unknown;
  previous_hash: unknown;
  current_hash: unknown;
  signature_status: unknown;
  sync_status: unknown;
  created_at: unknown;
}

function rowToResult(row: FiscalEventRowShape): FiscalEventAppendResult {
  return {
    id: requireString(row.id, 'id'),
    tenant_id: requireString(row.tenant_id, 'tenant_id'),
    company_id: requireString(row.company_id, 'company_id'),
    terminal_id: requireString(row.terminal_id, 'terminal_id'),
    operator_id: requireString(row.operator_id, 'operator_id'),
    event_type: requireString(row.event_type, 'event_type') as FiscalEventTypeValue,
    chain_context: requireString(row.chain_context, 'chain_context') as FiscalChainContext,
    event_version: requireNumber(row.event_version, 'event_version'),
    signature_version: requireString(row.signature_version, 'signature_version'),
    sequence_number: requireNumber(row.sequence_number, 'sequence_number'),
    event_time_device: requireString(row.event_time_device, 'event_time_device'),
    business_date: requireString(row.business_date, 'business_date'),
    reference_event_id: nullableString(row.reference_event_id),
    reference_document_id: nullableString(row.reference_document_id),
    source_event_class: nullableString(row.source_event_class),
    source_event_id: nullableString(row.source_event_id),
    canonical_bytes: requireString(row.canonical_bytes, 'canonical_bytes'),
    previous_hash: requireString(row.previous_hash, 'previous_hash'),
    current_hash: requireString(row.current_hash, 'current_hash'),
    sync_status: requireExact(row.sync_status, 'pending', 'sync_status'),
    signature_status: requireExact(row.signature_status, 'not_required', 'signature_status'),
    created_at: requireString(row.created_at, 'created_at'),
  };
}

function requireString(value: unknown, field: string): string {
  if (typeof value !== 'string') {
    throw new Error(`fiscal_events.${field} expected string, got ${typeof value}`);
  }
  return value;
}

function requireNumber(value: unknown, field: string): number {
  if (typeof value !== 'number') {
    throw new Error(`fiscal_events.${field} expected number, got ${typeof value}`);
  }
  return value;
}

function requireExact<T extends string>(value: unknown, expected: T, field: string): T {
  if (value !== expected) {
    throw new Error(
      `fiscal_events.${field} expected ${expected}, got ${typeof value === 'string' ? value : typeof value}`,
    );
  }
  return expected;
}

function nullableString(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  if (typeof value !== 'string') {
    throw new Error(`expected string or null, got ${typeof value}`);
  }
  return value;
}

// -------------------------------------------------------------------
// Free-function helpers
// -------------------------------------------------------------------

/**
 * RFC 4122 UUID v4 generator. Prefers the platform `crypto.randomUUID()`
 * (available in Node 19+ and all evergreen browsers/Tauri 2). Falls back
 * to `crypto.getRandomValues()` so the engine doesn't bring a test-only
 * polyfill into runtime. Falling further back to `Math.random()` is
 * deliberately not done — a fiscal id is integrity-critical.
 */
function generateUuidV4(): string {
  const c = globalThis.crypto;
  if (c && typeof c.randomUUID === 'function') {
    return c.randomUUID();
  }
  if (c && typeof c.getRandomValues === 'function') {
    const bytes = new Uint8Array(16);
    c.getRandomValues(bytes);
    // Per RFC 4122 §4.4: version = 4 (top nibble of byte 6 == 0b0100),
    // variant = 10xx (top two bits of byte 8).
    bytes[6] = ((bytes[6] ?? 0) & 0x0f) | 0x40;
    bytes[8] = ((bytes[8] ?? 0) & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20, 32)}`;
  }
  throw new Error('No crypto source available for UUID v4 generation');
}

/** ISO-8601 UTC timestamp truncated to second precision (`...Z`). */
function isoSecondsUtc(now: Date): string {
  return now.toISOString().replace(/\.\d{3}Z$/, 'Z');
}
