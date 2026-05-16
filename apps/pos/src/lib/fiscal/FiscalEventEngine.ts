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

import type { FiscalEventCanonicalEncoder } from './FiscalEventCanonicalEncoder';
import type { FiscalIntegrityProvider } from './HashChainIntegrityProvider';
import type {
  FiscalEventPayloadRegistry,
  FiscalEventTypeValue,
} from './FiscalEventPayloadRegistry';

/**
 * Thrown when `append()` is called on a terminal whose
 * `fiscal_event_genesis_seed` is still the v37 migration default `''`
 * (i.e. terminal registration has not yet provisioned the seed).
 */
export class ChainHeadNotInitializedError extends Error {
  constructor(public readonly terminalId: string) {
    super(
      `Fiscal-event chain head not initialized for terminal ${terminalId}: ` +
        'fiscal_event_genesis_seed is the empty-string sentinel. ' +
        'The seed is provisioned at terminal registration; append() cannot run before then.',
    );
    this.name = 'ChainHeadNotInitializedError';
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
   * call against the same pair returns the pre-existing row.
   */
  source_event_class?: string;
  source_event_id?: string;
}

export interface FiscalEventAppendResult {
  id: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  event_type: FiscalEventTypeValue;
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
 */
interface SqlSurface {
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

    // Step 1 — resolve event_version via the registry. Reserved types
    // throw before any state mutation.
    const eventVersion = this.registry.eventVersionFor(request.event_type);

    // Step 2 — device-side idempotency on (source_event_class, source_event_id).
    if (request.source_event_class != null && request.source_event_id != null) {
      const existing = await this.findBySource(
        sql,
        request.source_event_class,
        request.source_event_id,
      );
      if (existing) {
        return existing;
      }
    }

    // Step 3 — read the single chain head from terminal_state. Refuse to
    // append against the empty-string sentinel (Task 13 Codex P3
    // forward-looking input).
    const head = await this.readChainHead(sql, request.tenant_id, request.terminal_id);
    if (head.fiscal_event_sequence === 0 && head.fiscal_event_genesis_seed === '') {
      throw new ChainHeadNotInitializedError(request.terminal_id);
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
    // signature_status='not_required'.
    await sql.execute(
      `INSERT INTO fiscal_events (
         id, tenant_id, company_id, terminal_id, operator_id,
         event_type, event_version, signature_version,
         sequence_number, event_time_device, business_date,
         reference_event_id, reference_document_id,
         source_event_class, source_event_id,
         canonical_bytes, previous_hash, current_hash,
         signature_status, sync_status, created_at
       ) VALUES (
         $1, $2, $3, $4, $5,
         $6, $7, $8,
         $9, $10, $11,
         $12, $13,
         $14, $15,
         $16, $17, $18,
         'not_required', 'pending', $19
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

    // Step 6 — advance the chain head. Same `tx` so the advance is
    // atomic with the insert.
    await sql.execute(
      `UPDATE terminal_state
          SET fiscal_event_last_hash = $1,
              fiscal_event_sequence  = $2
        WHERE terminal_id = $3`,
      [currentHash, sequenceNumber, request.terminal_id],
    );

    return {
      id,
      tenant_id: request.tenant_id,
      company_id: request.company_id,
      terminal_id: request.terminal_id,
      operator_id: request.operator_id,
      event_type: request.event_type,
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
   * emission. Returns the same shape as a fresh append so the caller does
   * not need to branch on which path produced the result.
   */
  private async findBySource(
    sql: SqlSurface,
    sourceClass: string,
    sourceId: string,
  ): Promise<FiscalEventAppendResult | null> {
    const rows = await sql.select<FiscalEventRowShape[]>(
      `SELECT id, tenant_id, company_id, terminal_id, operator_id,
              event_type, event_version, signature_version,
              sequence_number, event_time_device, business_date,
              reference_event_id, reference_document_id,
              source_event_class, source_event_id,
              canonical_bytes, previous_hash, current_hash,
              signature_status, sync_status, created_at
         FROM fiscal_events
        WHERE source_event_class = $1 AND source_event_id = $2
        LIMIT 1`,
      [sourceClass, sourceId],
    );
    const row = rows[0];
    if (!row) return null;
    return rowToResult(row);
  }

  private async readChainHead(
    sql: SqlSurface,
    tenantId: string,
    terminalId: string,
  ): Promise<{
    fiscal_event_genesis_seed: string;
    fiscal_event_last_hash: string;
    fiscal_event_sequence: number;
  }> {
    const rows = await sql.select<
      Array<{
        fiscal_event_genesis_seed: unknown;
        fiscal_event_last_hash: unknown;
        fiscal_event_sequence: unknown;
      }>
    >(
      `SELECT fiscal_event_genesis_seed, fiscal_event_last_hash, fiscal_event_sequence
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

  /** Expose the default DB handle for callers that want a non-tx convenience. */
  protected get db(): SqlSurface {
    return asSql(this.defaultDb);
  }
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
