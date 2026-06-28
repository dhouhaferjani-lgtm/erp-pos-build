export interface Migration {
  version: number;
  name: string;
  sql: string;
  run?: (db: { execute: (sql: string, params?: unknown[]) => Promise<unknown> }) => Promise<void>;
}

export function isDuplicateColumnError(error: unknown): boolean {
  // The Tauri SQL plugin (@tauri-apps/plugin-sql) rejects with a plain STRING,
  // not an Error instance (unlike the better-sqlite3 test harness). Coerce to a
  // string before matching, else `error instanceof Error` silently misses the
  // production case and the (harmless, expected) duplicate-column re-throws and
  // ABORTS the whole migration run. See migrations.tauri-string-errors.test.ts.
  const message = error instanceof Error ? error.message : String(error);
  return message.includes('duplicate column');
}

export const migrations: Migration[] = [
  {
    version: 1,
    name: 'create_products_table',
    sql: `
      CREATE TABLE IF NOT EXISTS products (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        sku TEXT NOT NULL,
        barcode TEXT,
        sale_price TEXT,
        stock_quantity INTEGER NOT NULL DEFAULT 0,
        category TEXT,
        image_url TEXT,
        tax_rate TEXT,
        sellable_type TEXT DEFAULT 'product',
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_products_sku ON products(sku);
      CREATE INDEX IF NOT EXISTS idx_products_barcode ON products(barcode);
      CREATE INDEX IF NOT EXISTS idx_products_category ON products(category);
    `,
  },
  {
    version: 2,
    name: 'create_payment_tables',
    sql: `
      CREATE TABLE IF NOT EXISTS payment_methods (
        id TEXT PRIMARY KEY,
        code TEXT NOT NULL,
        name TEXT NOT NULL,
        is_physical INTEGER NOT NULL DEFAULT 0,
        has_maturity INTEGER NOT NULL DEFAULT 0,
        requires_third_party INTEGER NOT NULL DEFAULT 0,
        is_push INTEGER NOT NULL DEFAULT 0,
        has_deducted_fees INTEGER NOT NULL DEFAULT 0,
        is_restricted INTEGER NOT NULL DEFAULT 0,
        fee_type TEXT,
        fee_fixed TEXT NOT NULL DEFAULT '0',
        fee_percent TEXT NOT NULL DEFAULT '0',
        restriction_type TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        position INTEGER NOT NULL DEFAULT 0,
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );

      CREATE TABLE IF NOT EXISTS payment_repositories (
        id TEXT PRIMARY KEY,
        code TEXT NOT NULL,
        name TEXT NOT NULL,
        type TEXT NOT NULL,
        bank_name TEXT,
        account_number TEXT,
        iban TEXT,
        bic TEXT,
        balance TEXT NOT NULL DEFAULT '0',
        is_active INTEGER NOT NULL DEFAULT 1,
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    version: 3,
    name: 'create_operator_pins_table',
    sql: `
      CREATE TABLE IF NOT EXISTS operator_pins (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        email TEXT NOT NULL,
        pin_hash TEXT NOT NULL,
        roles TEXT NOT NULL DEFAULT '[]',
        permissions TEXT NOT NULL DEFAULT '[]',
        can_discount INTEGER NOT NULL DEFAULT 0,
        max_discount_percent REAL,
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    version: 4,
    name: 'create_offline_receipts_table',
    sql: `
      CREATE TABLE IF NOT EXISTS offline_receipts (
        id TEXT PRIMARY KEY,
        idempotency_key TEXT NOT NULL UNIQUE,
        receipt_number TEXT NOT NULL,
        terminal_id TEXT NOT NULL,
        terminal_code TEXT NOT NULL,
        operator_id TEXT NOT NULL,
        operator_name TEXT NOT NULL,
        lines TEXT NOT NULL,
        subtotal TEXT NOT NULL,
        tax_amount TEXT NOT NULL,
        discount_amount TEXT NOT NULL DEFAULT '0',
        total TEXT NOT NULL,
        currency TEXT NOT NULL,
        fiscal_hash TEXT NOT NULL,
        previous_hash TEXT NOT NULL,
        hash_sequence INTEGER NOT NULL,
        transaction_discount_amount TEXT,
        transaction_discount_reason TEXT,
        tendered_amount TEXT,
        change_due TEXT,
        payment_method_id TEXT NOT NULL,
        payment_repository_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT,
        sync_error TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_offline_receipts_status ON offline_receipts(status);
      CREATE INDEX IF NOT EXISTS idx_offline_receipts_idempotency ON offline_receipts(idempotency_key);
    `,
  },
  {
    version: 5,
    name: 'create_terminal_state_table',
    sql: `
      CREATE TABLE IF NOT EXISTS terminal_state (
        terminal_id TEXT PRIMARY KEY,
        terminal_code TEXT NOT NULL,
        genesis_seed TEXT NOT NULL,
        last_hash TEXT NOT NULL,
        hash_sequence INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    version: 6,
    name: 'create_sync_tables',
    sql: `
      CREATE TABLE IF NOT EXISTS sync_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        operation TEXT NOT NULL,
        entity_type TEXT NOT NULL,
        entity_id TEXT,
        status TEXT NOT NULL DEFAULT 'success',
        details TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_sync_log_created ON sync_log(created_at);

      CREATE TABLE IF NOT EXISTS sync_metadata (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    version: 7,
    name: 'add_retry_count_to_offline_receipts',
    sql: `
      ALTER TABLE offline_receipts ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0;
    `,
  },
  {
    version: 8,
    name: 'create_z_reports_table',
    sql: `
      CREATE TABLE IF NOT EXISTS z_reports (
        id TEXT PRIMARY KEY,
        terminal_id TEXT NOT NULL,
        shift_id TEXT NOT NULL,
        z_number INTEGER NOT NULL,
        formatted_z_number TEXT NOT NULL,
        generated_at TEXT NOT NULL,
        fiscal_hash TEXT NOT NULL,
        previous_hash TEXT NOT NULL,
        hash_sequence INTEGER NOT NULL,
        report_data TEXT NOT NULL,
        opening_cash REAL NOT NULL DEFAULT 0,
        expected_cash REAL NOT NULL DEFAULT 0,
        receipt_snapshots TEXT NOT NULL,
        grand_totals TEXT NOT NULL,
        synced INTEGER NOT NULL DEFAULT 0,
        synced_at TEXT,
        UNIQUE(terminal_id, z_number)
      );
      CREATE INDEX IF NOT EXISTS idx_z_reports_sync ON z_reports(synced);
      CREATE INDEX IF NOT EXISTS idx_z_reports_terminal ON z_reports(terminal_id, z_number);
    `,
  },
  {
    version: 9,
    name: 'add_z_chain_columns_to_terminal_state',
    sql: '',
    async run(db) {
      const columns = [
        "ALTER TABLE terminal_state ADD COLUMN z_last_hash TEXT NOT NULL DEFAULT 'GENESIS'",
        'ALTER TABLE terminal_state ADD COLUMN z_hash_sequence INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE terminal_state ADD COLUMN z_number INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE terminal_state ADD COLUMN cumulative_sales REAL NOT NULL DEFAULT 0',
        'ALTER TABLE terminal_state ADD COLUMN cumulative_tax REAL NOT NULL DEFAULT 0',
        'ALTER TABLE terminal_state ADD COLUMN cumulative_refunds REAL NOT NULL DEFAULT 0',
        'ALTER TABLE terminal_state ADD COLUMN perpetual_grand_total REAL NOT NULL DEFAULT 0',
        'ALTER TABLE terminal_state ADD COLUMN receipt_count_lifetime INTEGER NOT NULL DEFAULT 0',
      ];
      for (const stmt of columns) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    version: 10,
    name: 'add_z_reports_shift_unique_index',
    sql: `
      CREATE UNIQUE INDEX IF NOT EXISTS idx_z_reports_shift_unique ON z_reports(shift_id);
    `,
  },
  {
    version: 11,
    name: 'add_modifier_groups_to_products',
    sql: `ALTER TABLE products ADD COLUMN modifier_groups TEXT DEFAULT NULL`,
  },
  {
    version: 12,
    name: 'create_product_images',
    sql: `CREATE TABLE IF NOT EXISTS product_images (
      product_id TEXT PRIMARY KEY,
      remote_url TEXT NOT NULL,
      local_path TEXT NOT NULL,
      etag TEXT,
      downloaded_at TEXT NOT NULL
    )`,
  },
  {
    version: 13,
    name: 'add_location_code_to_terminal_state',
    sql: '',
    async run(db) {
      try {
        await db.execute("ALTER TABLE terminal_state ADD COLUMN location_code TEXT NOT NULL DEFAULT 'MAIN'");
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    version: 14,
    name: 'create_offline_cash_drawer_ops',
    sql: `
      CREATE TABLE IF NOT EXISTS offline_cash_drawer_ops (
        id TEXT PRIMARY KEY,
        idempotency_key TEXT NOT NULL UNIQUE,
        type TEXT NOT NULL CHECK(type IN ('deposit', 'payout')),
        amount TEXT NOT NULL,
        reason TEXT NOT NULL,
        operator_id TEXT NOT NULL,
        operator_name TEXT NOT NULL,
        terminal_id TEXT NOT NULL,
        shift_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'syncing', 'synced', 'failed')),
        retry_count INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT,
        sync_error TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_cash_drawer_ops_status ON offline_cash_drawer_ops(status);
      CREATE INDEX IF NOT EXISTS idx_cash_drawer_ops_shift ON offline_cash_drawer_ops(shift_id);
    `,
  },
  {
    version: 15,
    name: 'add_voided_to_offline_receipts',
    sql: '',
    async run(db) {
      const columns = [
        'ALTER TABLE offline_receipts ADD COLUMN voided INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE offline_receipts ADD COLUMN void_reason TEXT',
      ];
      for (const stmt of columns) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    version: 16,
    name: 'add_offline_first_columns_to_offline_receipts',
    sql: '',
    async run(db) {
      const statements = [
        "ALTER TABLE offline_receipts ADD COLUMN server_receipt_id TEXT",
        "ALTER TABLE offline_receipts ADD COLUMN payments_json TEXT NOT NULL DEFAULT '[]'",
        "ALTER TABLE offline_receipts ADD COLUMN consumption_mode TEXT",
        "ALTER TABLE offline_receipts ADD COLUMN table_id TEXT",
      ];
      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    version: 17,
    name: 'create_held_transactions_table',
    sql: `
      CREATE TABLE IF NOT EXISTS held_transactions (
        id TEXT PRIMARY KEY,
        terminal_id TEXT NOT NULL,
        operator_id TEXT NOT NULL,
        label TEXT NOT NULL,
        items_json TEXT NOT NULL,
        transaction_discount_json TEXT,
        subtotal TEXT NOT NULL,
        total TEXT NOT NULL,
        item_count INTEGER NOT NULL,
        held_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_held_transactions_terminal ON held_transactions(terminal_id);
      CREATE INDEX IF NOT EXISTS idx_held_transactions_held_at ON held_transactions(held_at);
    `,
  },
  {
    version: 18,
    name: 'create_queued_pin_updates',
    sql: `
      CREATE TABLE IF NOT EXISTS queued_pin_updates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id TEXT NOT NULL,
        pin_hash TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'syncing', 'synced', 'failed')),
        retry_count INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT,
        sync_error TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_queued_pin_updates_status ON queued_pin_updates(status);
    `,
  },
  {
    version: 19,
    name: 'create_floors_and_tables',
    sql: `
      CREATE TABLE IF NOT EXISTS floors (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_floors_position ON floors(position);

      CREATE TABLE IF NOT EXISTS tables (
        id TEXT PRIMARY KEY,
        floor_id TEXT,
        table_number TEXT NOT NULL,
        label TEXT,
        seats INTEGER NOT NULL DEFAULT 4,
        status TEXT NOT NULL DEFAULT 'available',
        shape TEXT,
        position_x TEXT,
        position_y TEXT,
        width TEXT,
        height TEXT,
        current_order_id TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_tables_floor ON tables(floor_id);
      CREATE INDEX IF NOT EXISTS idx_tables_status ON tables(status);
    `,
  },
  {
    version: 20,
    name: 'create_menu_categories_and_items',
    sql: `
      CREATE TABLE IF NOT EXISTS menu_categories (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        position INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_menu_categories_position ON menu_categories(position);

      CREATE TABLE IF NOT EXISTS menu_category_items (
        id TEXT PRIMARY KEY,
        menu_category_id TEXT NOT NULL,
        sellable_id TEXT NOT NULL,
        sellable_type TEXT NOT NULL,
        name TEXT NOT NULL,
        code TEXT NOT NULL,
        barcode TEXT,
        base_price TEXT NOT NULL,
        effective_price TEXT NOT NULL,
        image_url TEXT,
        tax_rate TEXT,
        display_order INTEGER NOT NULL DEFAULT 0,
        is_available INTEGER NOT NULL DEFAULT 1,
        modifier_groups TEXT,
        updated_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_menu_items_category ON menu_category_items(menu_category_id);
      CREATE INDEX IF NOT EXISTS idx_menu_items_order ON menu_category_items(menu_category_id, display_order);
    `,
  },
  {
    // Widen REAL monetary columns to TEXT so multi-decimal currencies (TND
    // uses 3 decimals) survive storage without IEEE 754 coercion. Mirrors the
    // backend decimal(15,3) widening from 2026_03_11_200000; the PostgreSQL
    // migration skipped SQLite because SQLite has no ALTER COLUMN TYPE.
    // Requires SQLite 3.35+ for DROP/RENAME COLUMN (bundled with Tauri).
    //
    // BACKFILL SEMANTICS: the `CAST(col AS TEXT)` step uses SQLite's shortest
    // round-trip decimal representation. This means:
    //   - A cleanly-stored EUR value like 100.25 emerges as "100.25" (correct).
    //   - A TND value that had already drifted to 100.24999999998 emerges as
    //     "100.24999999998" and is *locked in* — this migration preserves the
    //     existing (possibly imprecise) state. It does NOT heal historical
    //     float drift. Only writes *after* this migration runs benefit from
    //     exact decimal persistence. For verticals that launched TND before
    //     this fix, reconcile existing terminal_state/z_reports rows manually
    //     against authoritative server values if needed.
    version: 21,
    name: 'widen_monetary_columns_to_text',
    sql: '',
    async run(db) {
      const columnMigrations: Array<{ table: string; column: string }> = [
        { table: 'z_reports', column: 'opening_cash' },
        { table: 'z_reports', column: 'expected_cash' },
        { table: 'terminal_state', column: 'cumulative_sales' },
        { table: 'terminal_state', column: 'cumulative_tax' },
        { table: 'terminal_state', column: 'cumulative_refunds' },
        { table: 'terminal_state', column: 'perpetual_grand_total' },
      ];

      await db.execute('BEGIN TRANSACTION');
      try {
        for (const { table, column } of columnMigrations) {
          const tmp = `${column}_new`;
          await db.execute(
            `ALTER TABLE ${table} ADD COLUMN ${tmp} TEXT NOT NULL DEFAULT '0'`,
          );
          await db.execute(
            `UPDATE ${table} SET ${tmp} = CAST(${column} AS TEXT)`,
          );
          await db.execute(`ALTER TABLE ${table} DROP COLUMN ${column}`);
          await db.execute(`ALTER TABLE ${table} RENAME COLUMN ${tmp} TO ${column}`);
        }
        await db.execute('COMMIT');
      } catch (error) {
        await db.execute('ROLLBACK');
        throw error;
      }
    },
  },
  {
    version: 22,
    name: 'cash_counting_feature',
    sql: `
      CREATE TABLE z_report_counts (
        id                  TEXT PRIMARY KEY,
        z_report_id         TEXT NOT NULL,
        payment_method_id   TEXT NOT NULL,
        currency_code       TEXT NOT NULL,
        expected_amount     TEXT NOT NULL,
        actual_amount       TEXT NOT NULL,
        variance_amount     TEXT NOT NULL,
        variance_direction  TEXT NOT NULL,
        transaction_count   INTEGER NOT NULL DEFAULT 0,
        created_at          TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE UNIQUE INDEX idx_z_report_counts_unique ON z_report_counts(z_report_id, payment_method_id);
      CREATE INDEX idx_z_report_counts_method ON z_report_counts(payment_method_id);

      ALTER TABLE z_reports ADD COLUMN blind_count_used    INTEGER NOT NULL DEFAULT 0;
      ALTER TABLE z_reports ADD COLUMN manager_override_by TEXT;
      ALTER TABLE z_reports ADD COLUMN variance_severity   TEXT;
      ALTER TABLE z_reports ADD COLUMN variance_reason     TEXT;

      CREATE TABLE company_fraud_settings_cache (
        company_id                          TEXT PRIMARY KEY,
        cash_variance_over_soft             TEXT NOT NULL,
        cash_variance_over_hard             TEXT NOT NULL,
        cash_variance_under_soft            TEXT NOT NULL,
        cash_variance_under_hard            TEXT NOT NULL,
        require_blind_cash_count            INTEGER NOT NULL DEFAULT 0,
        require_manager_pin_above_hard      INTEGER NOT NULL DEFAULT 1,
        cash_variance_email_severity        TEXT NOT NULL DEFAULT 'none',
        refreshed_at                        TEXT NOT NULL DEFAULT (datetime('now'))
      );

      ALTER TABLE terminal_state ADD COLUMN manager_pin_throttle_until   TEXT;
      ALTER TABLE terminal_state ADD COLUMN manager_pin_failed_attempts  INTEGER NOT NULL DEFAULT 0;
    `,
  },
  {
    // Local mirror of issued vouchers for the refund-flow phase. Phase 1 voucher
    // domain is single-terminal: a voucher issued at terminal A is redeemable
    // only at terminal A. Mirror only holds rows the server has scoped to this
    // terminal (or with a null `redeemable_at_terminal_id` for back-office
    // goodwill grants). Lookups (`findByCode`) read this table only — never
    // hit the API during cashier interaction.
    //
    // All monetary amounts are decimal strings stored at internal precision
    // (currency_scale + 2), mirroring the TEXT-decimal pattern established by
    // migration 21.
    version: 23,
    name: 'create_vouchers_mirror',
    sql: `
      CREATE TABLE IF NOT EXISTS vouchers (
        id TEXT PRIMARY KEY,
        code TEXT NOT NULL UNIQUE,
        initial_balance TEXT NOT NULL,
        current_balance TEXT NOT NULL,
        currency TEXT NOT NULL,
        status TEXT NOT NULL,
        redemption_mode TEXT NOT NULL,
        voucher_kind TEXT NOT NULL DEFAULT 'MPV',
        source TEXT NOT NULL,
        issued_at TEXT NOT NULL,
        expires_at TEXT,
        partner_id TEXT,
        issued_to_partner_id TEXT,
        redeemable_at_terminal_id TEXT,
        notes TEXT,
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_vouchers_terminal_status ON vouchers(redeemable_at_terminal_id, status);
    `,
  },
  {
    // Local mirror of voucher_ledger entries (issued, redeemed, expired, …).
    // Server-pulled rows arrive with sync_status = 'synced'. Locally written
    // rows (issued/redeemed at this terminal during a refund or exchange) start
    // as sync_status = 'pending' and are pushed by syncService.pushVoucherLedgerEntries.
    version: 24,
    name: 'create_voucher_ledger_mirror',
    sql: `
      CREATE TABLE IF NOT EXISTS voucher_ledger (
        id TEXT PRIMARY KEY,
        voucher_id TEXT NOT NULL,
        event TEXT NOT NULL,
        amount TEXT NOT NULL,
        currency TEXT NOT NULL,
        receipt_id TEXT,
        terminal_id TEXT,
        user_id TEXT NOT NULL,
        occurred_at TEXT NOT NULL,
        sync_status TEXT NOT NULL DEFAULT 'synced' CHECK(sync_status IN ('synced', 'pending', 'failed')),
        sync_error TEXT,
        synced_at TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_voucher_ledger_voucher ON voucher_ledger(voucher_id, occurred_at);
      CREATE INDEX IF NOT EXISTS idx_voucher_ledger_sync_status ON voucher_ledger(sync_status);
    `,
  },
  {
    // Local index of receipts whose server-signed QR token is known to this
    // terminal. Used by `findReceiptByQrToken` (scan dispatcher in Task 50)
    // and `findReceiptByNumber` (manual lookup) — both must answer from local
    // SQLite alone, with no API round-trip.
    //
    // qr_token is nullable: offline-issued receipts whose server-signed token
    // hasn't synced back yet have an entry keyed by `receipt_uuid` so they
    // can still be found by receipt number, but `findReceiptByQrToken` will
    // miss them until the QR token arrives via sync.
    version: 25,
    name: 'create_receipt_qr_index',
    sql: `
      CREATE TABLE IF NOT EXISTS receipt_qr_index (
        receipt_uuid TEXT PRIMARY KEY,
        qr_token TEXT,
        receipt_number TEXT NOT NULL,
        terminal_id TEXT NOT NULL,
        posted_at TEXT NOT NULL,
        total TEXT NOT NULL,
        currency TEXT NOT NULL,
        synced_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_receipt_qr_index_terminal ON receipt_qr_index(terminal_id);
      CREATE INDEX IF NOT EXISTS idx_receipt_qr_index_number ON receipt_qr_index(receipt_number);
      CREATE INDEX IF NOT EXISTS idx_receipt_qr_index_qr_token ON receipt_qr_index(qr_token) WHERE qr_token IS NOT NULL;
    `,
  },
  {
    // Adds partner_id to receipt_qr_index so the "Find by customer" tab in
    // ReceiptLocatorScreen can query receipts by the customer who made the
    // purchase — local-first, no API call needed.
    //
    // Sync-layer population is a follow-up: the backend must include partner_id
    // in the receipt-sync payload and syncService must pass it through
    // upsertReceiptQrIndexEntries. The column ships here so local SQLite is
    // schema-ready when that wiring lands.
    version: 26,
    name: 'add_partner_id_to_receipt_qr_index',
    sql: '',
    async run(db) {
      try {
        await db.execute('ALTER TABLE receipt_qr_index ADD COLUMN partner_id TEXT NULL');
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
      try {
        await db.execute(
          'CREATE INDEX IF NOT EXISTS idx_receipt_qr_index_partner ON receipt_qr_index(partner_id) WHERE partner_id IS NOT NULL',
        );
      } catch {
        // Index may already exist — safe to swallow
      }
    },
  },
  {
    // In-progress refund / exchange drafts persisted to SQLite so an app
    // close or crash can restore the cart on reopen. One row per active
    // refund session on this terminal (at most one at a time in practice).
    //
    // `exchange_request_id` is NULL until the cashier adds a positive
    // ("Buying new") line — the presence of a non-null value signals that
    // the session has become an exchange rather than a pure refund.
    //
    // All JSON columns store CartItem arrays or the transactionDiscount
    // shape — mirror the held_transactions pattern from migration 17.
    version: 27,
    name: 'create_refund_drafts',
    sql: `
      CREATE TABLE IF NOT EXISTS refund_drafts (
        id TEXT PRIMARY KEY,
        terminal_id TEXT NOT NULL,
        operator_id TEXT NOT NULL,
        receipt_uuid TEXT NOT NULL,
        receipt_number TEXT NOT NULL,
        return_items_json TEXT NOT NULL,
        buying_items_json TEXT NOT NULL,
        transaction_discount_json TEXT,
        exchange_request_id TEXT,
        started_at TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_refund_drafts_terminal ON refund_drafts(terminal_id);
    `,
  },
  {
    // Codex review B1 (2026-04-30) — wire offline v3 fiscal hashing.
    //
    // Adds `fiscal_schema_version` to two tables for the historical receipt-hash
    // cutover. Pass 2B retires the legacy receipt-sync authoring path, but the
    // migration remains part of the upgrade sequence for existing local DBs.
    //
    // Default of 2 mirrors the pre-cutover state: terminals start as v2 and
    // are flipped to v3 by `FiscalSchemaCutoverController`. We have no
    // production data, so a NOT NULL DEFAULT 2 backfill is safe — any
    // pre-existing rows reflect v2-era receipts that were sealed against the
    // legacy hash schema.
    version: 28,
    name: 'add_fiscal_schema_version',
    sql: '',
    async run(db) {
      const statements = [
        'ALTER TABLE terminal_state ADD COLUMN fiscal_schema_version INTEGER NOT NULL DEFAULT 2',
        'ALTER TABLE offline_receipts ADD COLUMN fiscal_schema_version INTEGER NOT NULL DEFAULT 2',
      ];
      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // T2.7 — track training-mode on each offline receipt row.
    //
    // Default 0 backfills existing rows as production receipts, which is the
    // correct interpretation: every pre-T2.7 row was sealed against a
    // production-mode terminal (the `is_training_mode` flag existed on the
    // server but the offline-first POS path did not honor it). Pass 2B keeps
    // the column for the local receipt mirror while fiscal-event ingestion
    // projects the canonical training flag on the server.
    version: 29,
    name: 'add_is_training_to_offline_receipts',
    sql: '',
    async run(db) {
      try {
        await db.execute(
          'ALTER TABLE offline_receipts ADD COLUMN is_training INTEGER NOT NULL DEFAULT 0',
        );
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    // C2 Day 1 — Menu-tenant catalog composite primary key.
    //
    // Adds two nullable TEXT columns to `products`:
    //   - `sellable_id`: the underlying sellable UUID (parsed from `id` for
    //     Menu-tenant rows; NULL for legacy / standard-retail rows).
    //   - `menu_category_id`: the menu category UUID this row belongs to;
    //     NULL for non-Menu rows.
    //
    // No backfill: pre-v30 rows already have `id` = bare sellable UUID and
    // continue to work. The flatten path emits both columns going forward
    // for Menu-tenant tenants, which together with the colon-delimited
    // composite `id` lets the same sellable cross-listed in two categories
    // surface as two distinct rows in the local SQLite catalog (closing
    // the C2 collapse-on-upsert pathology).
    //
    // The wire-payload boundary at `syncService.receiptToPayload` unpacks
    // composite `product_id` / `composite_item_id` to bare sellable IDs
    // before sending to the server, so this column change is purely
    // local — `pos_receipt_lines.product_id` continues to be a bare UUID
    // satisfying the server's existing FK + XOR constraints.
    version: 30,
    name: 'add_menu_composite_columns',
    sql: '',
    async run(db) {
      const statements = [
        'ALTER TABLE products ADD COLUMN sellable_id TEXT',
        'ALTER TABLE products ADD COLUMN menu_category_id TEXT',
      ];
      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // C2 post-deploy runtime migration state.
    //
    // The destructive held-cart dump cannot run here because this layer
    // has no company module context. Instead, v31 creates a tiny state
    // table; AppRouter runs the Menu-aware data migration after bootstrap
    // and records completion in this table so it is one-shot per terminal
    // database.
    version: 31,
    name: 'create_pos_migration_state',
    sql: `
      CREATE TABLE IF NOT EXISTS pos_migration_state (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL,
        updated_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    // Bug 5 — retroactive one-shot cleanup for offline_receipts that were
    // dead-lettered because of the SQLITE_BUSY cascade. The Tauri plugin-sql
    // layer throws the libsqlite failure as a raw string; the lock-specific
    // signature is `(code: 5) database is locked`. Before this PR, the sync
    // error serializer collapsed that string to the literal `'Unknown error'`,
    // so existing dead-lettered rows are NOT recoverable here by design —
    // they need PR C's recovery UX (or manual SQLite) since the
    // `'Unknown error'` signature is ambiguous. From this PR onward,
    // `coerceSyncError` preserves the lock signature, and the rerunnable
    // `runStuckReceiptRecovery` hook in `db.ts` (called on every boot AFTER
    // migrations) re-applies this same UPDATE so any new lock-signature
    // failure self-heals on the next launch. This migration row remains
    // as the audit-trail anchor for the initial retroactive sweep.
    //
    // The recovery is intentionally narrow:
    //   - status='failed' only (don't disturb in-flight or synced rows),
    //   - sync_error LIKE '%database is locked%' (the precise libsqlite
    //     wording — SQLite's default LIKE is ASCII-case-insensitive, so
    //     this also matches re-cased variants).
    //
    // The reset (status='pending', retry_count=0, sync_error=NULL) lets
    // the next sync tick re-push under WAL mode. The server's
    // idempotency_key dedup turns any accidental double-send into a
    // `duplicate` result, which the client treats as success.
    version: 32,
    name: 'recover_stuck_offline_receipts_from_db_lock',
    sql: `
      UPDATE offline_receipts
      SET status = 'pending',
          retry_count = 0,
          sync_error = NULL
      WHERE status = 'failed'
        AND sync_error LIKE '%database is locked%';
    `,
  },
  {
    version: 33,
    name: 'add_operator_discount_permission_cache_timestamp',
    sql: '',
    async run(db) {
      try {
        await db.execute('ALTER TABLE operator_pins ADD COLUMN discount_permissions_fetched_at TEXT');
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    version: 34,
    name: 'add_operator_discount_permission_cache_status',
    sql: '',
    async run(db) {
      try {
        await db.execute("ALTER TABLE operator_pins ADD COLUMN discount_permissions_status TEXT DEFAULT 'unavailable'");
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    version: 35,
    name: 'add_operator_discount_permission_terminal_code',
    sql: '',
    async run(db) {
      try {
        await db.execute('ALTER TABLE operator_pins ADD COLUMN discount_permissions_terminal_code TEXT');
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    version: 36,
    name: 'add_operator_discount_permission_cache_details',
    sql: '',
    async run(db) {
      const columns = [
        'ALTER TABLE operator_pins ADD COLUMN discount_permissions_user_can_discount INTEGER',
        'ALTER TABLE operator_pins ADD COLUMN discount_permissions_user_max_discount_percent REAL',
        'ALTER TABLE operator_pins ADD COLUMN discount_permissions_can_apply_line_discounts INTEGER',
        'ALTER TABLE operator_pins ADD COLUMN discount_permissions_can_apply_transaction_discounts INTEGER',
      ];

      for (const statement of columns) {
        try {
          await db.execute(statement);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // POS Fiscal Event Engine — Phase 1 (Task 13).
    //
    // Device SQLite is the authoritative fiscal source of truth. This
    // migration brings up the per-device `fiscal_events` chain table,
    // hardens it with append-only triggers matching the existing
    // `offline_receipts` discipline, and adds the per-terminal chain
    // head to `terminal_state` (legacy `last_hash`/`hash_sequence`
    // columns are retained as a mirror).
    //
    // `offline_receipts.canonical_bytes` is added as a nullable column
    // so projection-only callers continue to work until Task 15 wires
    // the assembler / fiscal-event seal end-to-end.
    //
    // Column shape mirrors Phase 1 spec v7 §3.1 (device); chain
    // invariants — UNIQUE(tenant_id, company_id, terminal_id, chain_context,
    // sequence_number) (chain-context scoped; Phase 4.2 foundation),
    // 64-char lowercase hex hashes — mirror the server-side §3.2.
    version: 37,
    name: 'create_fiscal_events_and_chain_head',
    sql: '',
    async run(db) {
      // ----- fiscal_events (chain) ----------------------------------------
      // CHECK constraints:
      //   - sequence_number > 0 — genesis seed is stored on terminal_state, not as a chain row.
      //   - length+hex-ish format guards for current_hash / previous_hash
      //     (lower hex 64 chars). SQLite has no native regex; we lean on
      //     length + GLOB pattern, which is sufficient as a defense-in-depth
      //     guard alongside the producer-side encoder.
      await db.execute(`
        CREATE TABLE IF NOT EXISTS fiscal_events (
          id                          TEXT PRIMARY KEY,
          tenant_id                   TEXT NOT NULL,
          company_id                  TEXT NOT NULL,
          terminal_id                 TEXT NOT NULL,
          operator_id                 TEXT NOT NULL,
          event_type                  TEXT NOT NULL,
          event_version               INTEGER NOT NULL DEFAULT 1,
          signature_version           TEXT NOT NULL,
          sequence_number             INTEGER NOT NULL,
          event_time_device           TEXT NOT NULL,
          business_date               TEXT NOT NULL,
          chain_context               TEXT NOT NULL DEFAULT 'operational',
          last_server_time_seen       TEXT,
          reference_event_id          TEXT,
          reference_document_id       TEXT,
          source_event_class          TEXT,
          source_event_id             TEXT,
          partner_id                  TEXT,
          partner_identity_snapshot   TEXT,
          canonical_bytes             TEXT NOT NULL,
          previous_hash               TEXT NOT NULL,
          current_hash                TEXT NOT NULL,
          signature_status            TEXT NOT NULL DEFAULT 'not_required',
          signature_algorithm         TEXT,
          signature_value             TEXT,
          signature_counter           INTEGER,
          signature_provider          TEXT,
          signing_device_id           TEXT,
          certificate_id              TEXT,
          signed_payload_ref          TEXT,
          time_source_value           TEXT,
          time_format                 TEXT,
          provider_transaction_id     TEXT,
          sync_status                 TEXT NOT NULL DEFAULT 'pending',
          sync_error                  TEXT,
          created_at                  TEXT NOT NULL,
          synced_at                   TEXT,
          CHECK (sequence_number > 0),
          -- Lowercase-hex 64-char invariant. NOT GLOB '*[^0-9a-f]*' reads as
          -- "no character anywhere in the string is outside [0-9a-f]" -- the
          -- canonical SQLite idiom for "every character matches a class"
          -- (GLOB lacks ^/$ anchors). The earlier GLOB '[0-9a-f]*' only
          -- validated the FIRST character because * matches any sequence of
          -- any characters in GLOB; that hole was caught and proven via
          -- node:sqlite probe in the Task 13 round-2 dual review.
          CHECK (length(current_hash)  = 64 AND current_hash  NOT GLOB '*[^0-9a-f]*'),
          CHECK (length(previous_hash) = 64 AND previous_hash NOT GLOB '*[^0-9a-f]*'),
          CHECK (chain_context IN ('operational', 'z_session', 'training_operational', 'training_z_session')),
          CHECK (sync_status IN ('pending', 'syncing', 'synced', 'failed')),
          CHECK (signature_status IN ('not_required', 'pending', 'signed', 'failed')),
          CHECK (
            (source_event_class IS NULL AND source_event_id IS NULL)
            OR (source_event_class IS NOT NULL AND source_event_id IS NOT NULL)
          )
        );
      `);

      // Chain-integrity UNIQUE — sequence slots are scoped by explicit
      // chain_context so the same terminal can maintain operational,
      // session/Z, and training streams independently.
      await db.execute(`
        CREATE UNIQUE INDEX IF NOT EXISTS idx_fiscal_events_chain_unique
          ON fiscal_events(tenant_id, company_id, terminal_id, chain_context, sequence_number);
      `);

      // Source-event idempotency — partial unique index excludes NULL pairs.
      await db.execute(`
        CREATE UNIQUE INDEX IF NOT EXISTS idx_fiscal_events_source_event_unique
          ON fiscal_events(source_event_class, source_event_id)
          WHERE source_event_id IS NOT NULL;
      `);

      // Sync-lifecycle index — the sync flusher scans pending rows
      // ordered by sequence.
      await db.execute(`
        CREATE INDEX IF NOT EXISTS idx_fiscal_events_sync_pending
          ON fiscal_events(sync_status, sequence_number)
          WHERE sync_status IN ('pending', 'syncing', 'failed');
      `);

      // Reference-event / reference-document lookups for chain-recovery
      // events and back-projection queries.
      await db.execute(`
        CREATE INDEX IF NOT EXISTS idx_fiscal_events_reference_event
          ON fiscal_events(reference_event_id)
          WHERE reference_event_id IS NOT NULL;
      `);
      await db.execute(`
        CREATE INDEX IF NOT EXISTS idx_fiscal_events_reference_document
          ON fiscal_events(reference_document_id)
          WHERE reference_document_id IS NOT NULL;
      `);

      // ----- Immutability triggers ---------------------------------------
      // BEFORE UPDATE OF <every non-sync column>: RAISE(ABORT).
      // Allowed columns (sync lifecycle only): sync_status, sync_error, synced_at.
      //
      // SQLite's column-level UPDATE OF trigger fires only when one of the
      // listed columns is in the UPDATE's SET list. By enumerating ALL
      // non-sync columns we block any attempted mutation of chain content
      // while still allowing the sync flusher to advance the lifecycle.
      await db.execute(`
        CREATE TRIGGER IF NOT EXISTS fiscal_events_block_update
        BEFORE UPDATE OF
          id, tenant_id, company_id, terminal_id, operator_id,
          event_type, event_version, signature_version, sequence_number,
          event_time_device, business_date, chain_context, last_server_time_seen,
          reference_event_id, reference_document_id,
          source_event_class, source_event_id,
          partner_id, partner_identity_snapshot,
          canonical_bytes, previous_hash, current_hash,
          signature_status, signature_algorithm, signature_value,
          signature_counter, signature_provider, signing_device_id,
          certificate_id, signed_payload_ref,
          time_source_value, time_format, provider_transaction_id,
          created_at
        ON fiscal_events
        BEGIN
          SELECT RAISE(ABORT, 'fiscal_events is append-only; only sync_status/sync_error/synced_at may be updated');
        END;
      `);

      // BEFORE DELETE: RAISE(ABORT) always.
      await db.execute(`
        CREATE TRIGGER IF NOT EXISTS fiscal_events_block_delete
        BEFORE DELETE ON fiscal_events
        BEGIN
          SELECT RAISE(ABORT, 'fiscal_events rows are append-only and may not be deleted');
        END;
      `);

      // ----- terminal_state chain head -----------------------------------
      // Three NOT NULL DEFAULT '' columns — populated at terminal init
      // by the device fiscal-event boot path (Task 15 wires this).
      // Legacy last_hash/hash_sequence stay; they mirror the receipt-V3
      // chain head for backward-compatible reads.
      const terminalStateColumns = [
        "ALTER TABLE terminal_state ADD COLUMN fiscal_event_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN fiscal_event_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN fiscal_event_sequence INTEGER NOT NULL DEFAULT 0',
        "ALTER TABLE terminal_state ADD COLUMN z_chain_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN z_chain_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN z_chain_sequence INTEGER NOT NULL DEFAULT 0',
        "ALTER TABLE terminal_state ADD COLUMN training_fiscal_event_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN training_fiscal_event_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN training_fiscal_event_sequence INTEGER NOT NULL DEFAULT 0',
        "ALTER TABLE terminal_state ADD COLUMN training_z_chain_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN training_z_chain_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN training_z_chain_sequence INTEGER NOT NULL DEFAULT 0',
      ];
      for (const stmt of terminalStateColumns) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
      await db.execute(`
        UPDATE terminal_state
           SET fiscal_event_genesis_seed = CASE
                 WHEN fiscal_event_genesis_seed = '' THEN genesis_seed
                 ELSE fiscal_event_genesis_seed
               END,
               z_chain_genesis_seed = CASE
                 WHEN z_chain_genesis_seed = '' THEN
                   CASE
                     WHEN fiscal_event_genesis_seed <> '' THEN fiscal_event_genesis_seed
                     ELSE genesis_seed
                   END
                 ELSE z_chain_genesis_seed
               END,
               training_fiscal_event_genesis_seed = CASE
                 WHEN training_fiscal_event_genesis_seed = '' THEN
                   CASE
                     WHEN fiscal_event_genesis_seed <> '' THEN fiscal_event_genesis_seed
                     ELSE genesis_seed
                   END
                 ELSE training_fiscal_event_genesis_seed
               END,
               training_z_chain_genesis_seed = CASE
                 WHEN training_z_chain_genesis_seed = '' THEN
                   CASE
                     WHEN z_chain_genesis_seed <> '' THEN z_chain_genesis_seed
                     WHEN fiscal_event_genesis_seed <> '' THEN fiscal_event_genesis_seed
                     ELSE genesis_seed
                   END
                 ELSE training_z_chain_genesis_seed
               END
         WHERE genesis_seed <> ''
      `);

      // ----- offline_receipts.canonical_bytes ----------------------------
      // Nullable — projection-only callers continue to function until
      // Task 15 wires fiscal-event sealing into the assembler path.
      try {
        await db.execute('ALTER TABLE offline_receipts ADD COLUMN canonical_bytes TEXT');
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    // POS Fiscal Event Engine — Phase 1 (Task 25 round-2).
    //
    // Spec v7 §9 requires: "On a local chain break, the terminal continues
    // operating in a recorded degraded mode." Task 25 round-1 shipped the
    // recovery emission (CHAIN_BREAK_DETECTED + CHAIN_RESTART) but did NOT
    // record the degraded-mode flag on `terminal_state`. Codex T25-P1
    // flagged this as a spec-compliance gap. Round-2 closes it by adding
    // an enum-shaped column the recovery service flips inside the same
    // transaction that emits the two recovery events — so a transactional
    // rollback also rolls back the degraded flag.
    //
    // Allowed values: 'healthy' | 'degraded'. Default 'healthy'. The
    // CHECK constraint enforces the allowed set; we use TEXT (not an
    // INTEGER enum) so the value reads as the enum tag in any SQLite
    // shell inspection — forensic legibility per the standing
    // enum-not-magic-string discipline (CLAUDE.md rule 9).
    version: 38,
    name: 'add_fiscal_chain_status_to_terminal_state',
    sql: '',
    async run(db) {
      try {
        await db.execute(
          "ALTER TABLE terminal_state ADD COLUMN fiscal_chain_status TEXT NOT NULL DEFAULT 'healthy' CHECK (fiscal_chain_status IN ('healthy', 'degraded'))",
        );
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    // Customer Accounts Phase 2 (Task 2) — local POS customer mirror.
    //
    // This is inbound reference data for cashier search/attach and account
    // balance display. It is intentionally scoped by tenant_id + company_id
    // on every key and lookup; account-payment fiscal authoring must never
    // resolve a customer from another company when the same customer UUID is
    // present in a different local tenant/company cache.
    version: 39,
    name: 'create_customers_mirror',
    sql: `
      CREATE TABLE IF NOT EXISTS customers (
        id TEXT NOT NULL,
        tenant_id TEXT NOT NULL,
        company_id TEXT NOT NULL,
        name TEXT NOT NULL,
        phone TEXT,
        email TEXT,
        tax_number TEXT,
        customer_category TEXT,
        receivable_balance TEXT NOT NULL DEFAULT '0.0000',
        credit_balance TEXT NOT NULL DEFAULT '0.0000',
        balance_updated_at TEXT,
        is_active INTEGER NOT NULL DEFAULT 1,
        sync_version TEXT,
        updated_at TEXT,
        synced_at TEXT NOT NULL,
        PRIMARY KEY (tenant_id, company_id, id)
      );
      CREATE INDEX IF NOT EXISTS idx_customers_name ON customers(tenant_id, company_id, name);
      CREATE INDEX IF NOT EXISTS idx_customers_phone ON customers(tenant_id, company_id, phone);
      CREATE INDEX IF NOT EXISTS idx_customers_tax_number ON customers(tenant_id, company_id, tax_number);
    `,
  },
  {
    // Customer Accounts Phase 2 (Task 5) — local pending customer create
    // outbox plus client→server alias mirror. Both tables are scoped by
    // tenant_id + company_id so fiscal authoring cannot resolve a local
    // pending UUID through another company's server Partner alias.
    version: 40,
    name: 'create_pending_customer_alias_tables',
    sql: `
      CREATE TABLE IF NOT EXISTS pending_customer_outbox (
        client_customer_uuid TEXT NOT NULL,
        tenant_id TEXT NOT NULL,
        company_id TEXT NOT NULL,
        name TEXT NOT NULL,
        phone TEXT,
        email TEXT,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending', 'resolved', 'failed')),
        sync_error TEXT,
        created_at TEXT NOT NULL,
        updated_at TEXT NOT NULL,
        PRIMARY KEY (tenant_id, company_id, client_customer_uuid)
      );
      CREATE INDEX IF NOT EXISTS idx_pending_customer_outbox_status
        ON pending_customer_outbox(tenant_id, company_id, status, updated_at);

      CREATE TABLE IF NOT EXISTS customer_aliases (
        tenant_id TEXT NOT NULL,
        company_id TEXT NOT NULL,
        client_customer_uuid TEXT NOT NULL,
        server_partner_id TEXT NOT NULL,
        resolved_at TEXT NOT NULL,
        PRIMARY KEY (tenant_id, company_id, client_customer_uuid)
      );
      CREATE INDEX IF NOT EXISTS idx_customer_aliases_server_partner
        ON customer_aliases(tenant_id, company_id, server_partner_id);
    `,
  },
  {
    // Phase 3 Task 3 — charge-to-account credit controls for offline
    // account-charge authoring. These remain inbound mirror fields only:
    // tenant_id + company_id scoping is still enforced by the customers
    // composite primary key and repository lookups.
    version: 41,
    name: 'add_account_charge_credit_controls_to_customers',
    sql: '',
    async run(db) {
      const statements = [
        'ALTER TABLE customers ADD COLUMN credit_limit TEXT',
        'ALTER TABLE customers ADD COLUMN payment_terms_days INTEGER',
        'ALTER TABLE customers ADD COLUMN charge_account_enabled INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE customers ADD COLUMN charge_policy_version TEXT',
      ];

      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // Phase 4 Task 6 — account-status mirror for account-charge fail-closed rules.
    version: 42,
    name: 'add_account_status_to_customers_mirror',
    sql: '',
    async run(db) {
      const statements = [
        "ALTER TABLE customers ADD COLUMN account_status TEXT NOT NULL DEFAULT 'active' CHECK (account_status IN ('active', 'suspended', 'closed', 'disputed'))",
        'ALTER TABLE customers ADD COLUMN account_status_changed_at TEXT',
        'ALTER TABLE customers ADD COLUMN account_status_reason TEXT',
        'ALTER TABLE customers ADD COLUMN account_status_version INTEGER NOT NULL DEFAULT 1',
      ];

      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // Phase 4 Task 7 — scoped approval metadata for offline supervisor PIN checks.
    version: 43,
    name: 'add_scoped_approval_metadata_to_operator_pins',
    sql: '',
    async run(db) {
      const statements = [
        "ALTER TABLE operator_pins ADD COLUMN tenant_id TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE operator_pins ADD COLUMN company_ids TEXT NOT NULL DEFAULT '[]'",
        "ALTER TABLE operator_pins ADD COLUMN terminal_ids TEXT NOT NULL DEFAULT '[]'",
        "ALTER TABLE operator_pins ADD COLUMN approval_scopes TEXT NOT NULL DEFAULT '[]'",
        'ALTER TABLE operator_pins ADD COLUMN approval_scope_permissions_fetched_at TEXT',
        "ALTER TABLE operator_pins ADD COLUMN approval_mirror_status TEXT NOT NULL DEFAULT 'fresh' CHECK (approval_mirror_status IN ('fresh', 'server_quarantined'))",
      ];

      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // Phase 4 Task 10 — approval evidence for legacy cash drawer DEPOSIT/PAYOUT queue rows.
    version: 44,
    name: 'add_cash_drawer_approval_evidence',
    sql: '',
    async run(db) {
      const statements = [
        'ALTER TABLE offline_cash_drawer_ops ADD COLUMN approval_id TEXT',
        'ALTER TABLE offline_cash_drawer_ops ADD COLUMN approval_fiscal_event_id TEXT',
        'ALTER TABLE offline_cash_drawer_ops ADD COLUMN approval_scope TEXT',
        'ALTER TABLE offline_cash_drawer_ops ADD COLUMN approval_supervisor_user_id TEXT',
        'ALTER TABLE offline_cash_drawer_ops ADD COLUMN approval_target_hash TEXT',
      ];

      for (const stmt of statements) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }
    },
  },
  {
    // Z-report rebuild — first-class fiscal chain contexts.
    version: 45,
    name: 'add_fiscal_event_chain_context',
    sql: '',
    async run(db) {
      const fiscalEventColumns = [
        "ALTER TABLE fiscal_events ADD COLUMN chain_context TEXT NOT NULL DEFAULT 'operational'",
      ];

      for (const stmt of fiscalEventColumns) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }

      const terminalStateColumns = [
        "ALTER TABLE terminal_state ADD COLUMN z_chain_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN z_chain_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN z_chain_sequence INTEGER NOT NULL DEFAULT 0',
        "ALTER TABLE terminal_state ADD COLUMN training_fiscal_event_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN training_fiscal_event_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN training_fiscal_event_sequence INTEGER NOT NULL DEFAULT 0',
        "ALTER TABLE terminal_state ADD COLUMN training_z_chain_genesis_seed TEXT NOT NULL DEFAULT ''",
        "ALTER TABLE terminal_state ADD COLUMN training_z_chain_last_hash TEXT NOT NULL DEFAULT ''",
        'ALTER TABLE terminal_state ADD COLUMN training_z_chain_sequence INTEGER NOT NULL DEFAULT 0',
      ];

      for (const stmt of terminalStateColumns) {
        try {
          await db.execute(stmt);
        } catch (error) {
          if (!isDuplicateColumnError(error)) {
            throw error;
          }
        }
      }

      await db.execute(`
        UPDATE terminal_state
           SET z_chain_genesis_seed = CASE
                 WHEN z_chain_genesis_seed = '' THEN
                   CASE
                     WHEN fiscal_event_genesis_seed <> '' THEN fiscal_event_genesis_seed
                     ELSE genesis_seed
                   END
                 ELSE z_chain_genesis_seed
               END,
               training_fiscal_event_genesis_seed = CASE
                 WHEN training_fiscal_event_genesis_seed = '' THEN
                   CASE
                     WHEN fiscal_event_genesis_seed <> '' THEN fiscal_event_genesis_seed
                     ELSE genesis_seed
                   END
                 ELSE training_fiscal_event_genesis_seed
               END,
               training_z_chain_genesis_seed = CASE
                 WHEN training_z_chain_genesis_seed = '' THEN
                   CASE
                     WHEN z_chain_genesis_seed <> '' THEN z_chain_genesis_seed
                     WHEN fiscal_event_genesis_seed <> '' THEN fiscal_event_genesis_seed
                     ELSE genesis_seed
                   END
                 ELSE training_z_chain_genesis_seed
               END
         WHERE genesis_seed <> ''
      `);

      await db.execute('DROP INDEX IF EXISTS idx_fiscal_events_chain_unique');
      await db.execute(`
        CREATE UNIQUE INDEX IF NOT EXISTS idx_fiscal_events_chain_unique
          ON fiscal_events(tenant_id, company_id, terminal_id, chain_context, sequence_number);
      `);

      await db.execute('DROP TRIGGER IF EXISTS fiscal_events_block_update');
      await db.execute(`
        CREATE TRIGGER IF NOT EXISTS fiscal_events_block_update
        BEFORE UPDATE OF
          id, tenant_id, company_id, terminal_id, operator_id,
          event_type, event_version, signature_version, sequence_number,
          event_time_device, business_date, chain_context, last_server_time_seen,
          reference_event_id, reference_document_id,
          source_event_class, source_event_id,
          partner_id, partner_identity_snapshot,
          canonical_bytes, previous_hash, current_hash,
          signature_status, signature_algorithm, signature_value,
          signature_counter, signature_provider, signing_device_id,
          certificate_id, signed_payload_ref,
          time_source_value, time_format, provider_transaction_id,
          created_at
        ON fiscal_events
        BEGIN
          SELECT RAISE(ABORT, 'fiscal_events is append-only; only sync_status/sync_error/synced_at may be updated');
        END;
      `);
    },
  },
  {
    // Sub-Spec C — POS audit / fraud-detection pipeline outbox.
    //
    // `queued_audit_events` is the client-side outbox for the offline-first
    // event-sourced audit pipeline. `recordAuditEvent` enqueues a `pending`
    // row from any POS action; the sync drain (`pushQueuedAuditEvents`) posts
    // batches to `POST /pos/audit-events/sync`. `event_id` is the client-
    // generated UUID that becomes the server-side `audit_events` PK — the
    // UNIQUE constraint protects against a local double-enqueue and the
    // server's per-event idempotent insert dedups any re-delivery.
    //
    // Retry / recover / prune mirror the fiscal-event + cash-drawer outboxes:
    // pending+failed rows under the retry cap are drained; `syncing` rows
    // stranded by a crash are demoted on boot; `synced` rows are pruned after
    // a retention window. Indexed on `status` for the hot getPending path.
    version: 46,
    name: 'create_queued_audit_events',
    sql: `
      CREATE TABLE IF NOT EXISTS queued_audit_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        event_id TEXT NOT NULL UNIQUE,
        event_type TEXT NOT NULL,
        aggregate_type TEXT NOT NULL,
        aggregate_id TEXT NOT NULL,
        tenant_id TEXT NOT NULL,
        company_id TEXT,
        operator_id TEXT,
        payload TEXT NOT NULL,
        metadata TEXT NOT NULL,
        occurred_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending'
          CHECK (status IN ('pending', 'syncing', 'synced', 'failed')),
        retry_count INTEGER NOT NULL DEFAULT 0,
        sync_error TEXT,
        created_at TEXT NOT NULL DEFAULT (datetime('now')),
        synced_at TEXT
      );
      CREATE INDEX IF NOT EXISTS idx_queued_audit_events_status
        ON queued_audit_events(status);
    `,
  },
  {
    // Phase 4 (fiscal audit B2) — record-at-settle refund mirror.
    //
    // The device performs refunds via POST /pos/receipts/{id}/return; the
    // settlement response (server return receipt) is mirrored here so the
    // device Z-report can fold REAL refunds_count / refunds_amount /
    // expected_cash into the SIGNED Z instead of hardcoded zeros.
    //
    // `id` is the SERVER return receipt id — the PK makes the settle-path
    // insert idempotent (INSERT OR IGNORE), so submit retries replaying the
    // same settlement can never double-count a refund.
    //
    // `total` is stored signed exactly as the server returns it (negative);
    // `cash_impact` is the POSITIVE amount that physically left this drawer:
    // abs(total) when destination='cash', '0' otherwise (store_voucher moves
    // no cash; original_payment is settled by server-side Treasury proration
    // and moves no physical cash at this terminal).
    //
    // Cross-terminal honesty: refunds processed at OTHER terminals never
    // appear here — they do not affect this device's drawer or its Z. Global
    // reconciliation is owned by the server Z/report side. Record-at-settle
    // also means a device crash between the server settle and this local
    // write undercounts the local Z; the server remains the source of truth
    // and reconciliation is the server's job (documented trade-off).
    version: 47,
    name: 'create_local_refund_records',
    sql: `
      CREATE TABLE IF NOT EXISTS local_refund_records (
        id TEXT PRIMARY KEY,
        receipt_number TEXT NOT NULL,
        original_receipt_number TEXT NOT NULL,
        shift_id TEXT NOT NULL,
        terminal_id TEXT NOT NULL,
        -- NOTE: server RefundDestination enum also has 'exchange_deferred' (apps/api/.../RefundDestination.php)
        -- which is NOT POS-reachable today (used only by ExchangeService). If an exchange flow ever
        -- reaches settle on this device, this CHECK would reject the row and the Z would silently
        -- undercount with only a toast. Extend the CHECK (and add a migration) before enabling exchanges.
        destination TEXT NOT NULL
          CHECK (destination IN ('original_payment', 'cash', 'store_voucher')),
        total TEXT NOT NULL,
        cash_impact TEXT NOT NULL,
        currency TEXT NOT NULL,
        settled_at TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_local_refund_records_shift
        ON local_refund_records(shift_id);
    `,
  },
  {
    // H2 (2026-06-11 NF525/DSFinV-K cash-reconciliation): mirror each customer
    // ACCOUNT_PAYMENT settled at this terminal so the device Z can fold CASH
    // account collections into expected_cash (money received into the drawer
    // against a customer credit account is drawer cash — NOT a sales payment).
    // Same record-at-author trade-off as local_refund_records: a crash between
    // the fiscal-event append and this mirror write undercounts the local Z;
    // the server remains the source of truth.
    version: 48,
    name: 'create_local_account_payment_records',
    sql: `
      CREATE TABLE IF NOT EXISTS local_account_payment_records (
        id TEXT PRIMARY KEY,
        shift_id TEXT NOT NULL,
        terminal_id TEXT NOT NULL,
        method_code TEXT NOT NULL,
        amount TEXT NOT NULL,
        -- POSITIVE cash that physically entered this drawer: amount when
        -- method_code='CASH', '0' otherwise (card/voucher account payments
        -- move no till cash at this terminal).
        cash_impact TEXT NOT NULL,
        currency TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE INDEX IF NOT EXISTS idx_local_account_payment_records_shift
        ON local_account_payment_records(shift_id);
    `,
  },
  {
    // M3 (2026-06-09 Z-report audit): the device Z selected its receipts by
    // wall-clock (created_at >= shift opened_at). A device clock rollback during
    // a shift could push a receipt's created_at before the shift open and
    // silently drop it from the SIGNED Z totals. hash_sequence is monotonic and
    // rollback-immune, so we anchor each shift to the terminal's receipt
    // hash_sequence at open time and bound the Z by `hash_sequence > anchor`.
    version: 49,
    name: 'create_shift_receipt_anchors',
    sql: `
      CREATE TABLE IF NOT EXISTS shift_receipt_anchors (
        shift_id TEXT PRIMARY KEY,
        -- Terminal receipt hash_sequence at shift open (the last receipt of the
        -- previous shift). The current shift's receipts all have a strictly
        -- greater hash_sequence.
        opening_hash_sequence INTEGER NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
    `,
  },
  {
    // Task 8 (2026-06-12 location-aware stock): cache the terminal's own
    // location stock pulled from GET /pos/stock-levels. All quantities are
    // TEXT decimal strings (scale-4) — never floats. `variant_id` uses ''
    // for product-grain rows because SQLite PKs reject NULL.
    //
    // Columns:
    //   product_id / variant_id — composite PK ('' = product-grain)
    //   quantity / reserved / available — stock figures (scale-4 strings)
    //   incoming_transfer / incoming_po — incoming figures (scale-4 strings)
    //   updated_at — server-supplied ISO-8601 timestamp (nullable)
    version: 50,
    name: 'create_location_stock',
    sql: `
      CREATE TABLE IF NOT EXISTS location_stock (
        product_id TEXT NOT NULL,
        variant_id TEXT NOT NULL DEFAULT '',
        quantity TEXT NOT NULL DEFAULT '0',
        reserved TEXT NOT NULL DEFAULT '0',
        available TEXT NOT NULL DEFAULT '0',
        incoming_transfer TEXT NOT NULL DEFAULT '0',
        incoming_po TEXT NOT NULL DEFAULT '0',
        updated_at TEXT,
        PRIMARY KEY (product_id, variant_id)
      );
      CREATE INDEX IF NOT EXISTS idx_location_stock_product ON location_stock(product_id);
    `,
  },
  {
    // Task 10 — persist `is_physical` from the server `/products` payload so
    // the availability selector can exempt service/non-physical products from
    // stock enforcement. Default 1 (= physical = stock-checked) is safe for
    // existing rows: they stay stock-enforced until a re-sync writes the
    // server-authoritative value.
    version: 51,
    name: 'add_is_physical_to_products',
    sql: `ALTER TABLE products ADD COLUMN is_physical INTEGER NOT NULL DEFAULT 1`,
  },
  {
    // Cross-location stock distribution cache (server-first, fetch-on-open).
    // payload = JSON of the /pos/products/{id}/stock-distribution `data` object;
    // quantities inside stay scale-4 decimal STRINGS. fetched_at = server time.
    // variant_id '' = product-grain (SQLite PKs reject NULL).
    version: 52,
    name: 'create_product_stock_distribution_cache',
    sql: `
      CREATE TABLE IF NOT EXISTS product_stock_distribution_cache (
        product_id TEXT NOT NULL,
        variant_id TEXT NOT NULL DEFAULT '',
        variant_label TEXT,
        payload TEXT NOT NULL,
        fetched_at TEXT NOT NULL,
        PRIMARY KEY (product_id, variant_id)
      );
      CREATE INDEX IF NOT EXISTS idx_xloc_cache_product ON product_stock_distribution_cache(product_id);
    `,
  },
  {
    // Offline-first shifts — Phase 0 (2026-06-14). Device-authoritative shift
    // lifecycle, mirroring the receipt model.
    //
    // `local_shifts` is the device's local source of truth for "the current
    // open shift": the device mints a UUIDv7 shift id (== fiscal_shift_id ==
    // pos_shifts.id) plus a per-terminal monotone `shift_number`, authors
    // SESSION_OPEN locally, and `pos_shifts` becomes a server-side projection
    // of those already-device-authored events. "Current open shift" is then
    // answered purely from SQLite — no network.
    //
    // The partial unique index mirrors the server `pos_shifts_one_open_per_
    // terminal` invariant: at most one OPEN row per terminal. A second open
    // attempt fails loud (constraint violation) rather than silently
    // double-opening. The `(terminal_id, shift_number)` index backs the
    // `nextShiftNumber` MAX lookup.
    //
    // `id` is the canonical shift UUID; `session_id` is the fiscal session id
    // (same value in the one-id model, kept as a distinct column so the
    // SESSION_OPEN payload round-trips). `fiscal_shift_id` is an alias of `id`
    // — derived in the repository, not stored. `opening_cash` is a TEXT
    // decimal string (currency-scaled), never a float, per the migration-21
    // TEXT-decimal discipline.
    //
    // Numbered v53 (after the dev v52 product_stock_distribution_cache it
    // merged alongside) — migration versions are a global UNIQUE key.
    version: 53,
    name: 'create_local_shifts',
    sql: `
      CREATE TABLE IF NOT EXISTS local_shifts (
        id TEXT PRIMARY KEY,
        terminal_id TEXT NOT NULL,
        session_id TEXT NOT NULL,
        shift_number INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'OPEN' CHECK (status IN ('OPEN', 'CLOSED')),
        opening_cash TEXT NOT NULL DEFAULT '0',
        opened_at TEXT NOT NULL,
        closed_at TEXT,
        cashier_id TEXT NOT NULL,
        cashier_name TEXT NOT NULL,
        created_at TEXT NOT NULL DEFAULT (datetime('now'))
      );
      CREATE UNIQUE INDEX IF NOT EXISTS idx_local_shifts_one_open_per_terminal
        ON local_shifts(terminal_id) WHERE status = 'OPEN';
      CREATE INDEX IF NOT EXISTS idx_local_shifts_terminal_number
        ON local_shifts(terminal_id, shift_number);
    `,
  },
  {
    // Offline-first shifts — Phase 6.1 (2026-06-14). Per-terminal
    // `shift_number` counter seed.
    //
    // A freshly-installed (or DB-reset) device starts with an empty
    // `local_shifts`, so `nextShiftNumber` would restart the per-terminal
    // counter at 1 and collide with shift numbers the server already projected
    // from a prior install. The device caches the server's current
    // `MAX(pos_shifts.shift_number)` for the terminal (carried on the terminal
    // payload as `max_shift_number`, see TerminalResource) into this column so
    // the counter continues monotonically.
    //
    // `nextShiftNumber` takes MAX(local row, seed); `setShiftNumberSeed` writes
    // it with a monotone guard so a stale server read can never rewind it below
    // a number the device has already used. Default 0 preserves the legacy
    // local-only behaviour for terminals with no server history.
    //
    // Numbered v54 — migration versions are a global UNIQUE key (v53 =
    // create_local_shifts). ALTER ADD COLUMN guarded with the duplicate-column
    // pattern so the migration is re-runnable.
    version: 54,
    name: 'add_shift_number_seed_to_terminal_state',
    sql: '',
    async run(db) {
      try {
        await db.execute(
          'ALTER TABLE terminal_state ADD COLUMN shift_number_seed INTEGER NOT NULL DEFAULT 0',
        );
      } catch (error) {
        if (!isDuplicateColumnError(error)) {
          throw error;
        }
      }
    },
  },
  {
    // Offline-first shifts — Phase 6.1 hardening (2026-06-14, Codex r1 MEDIUM).
    //
    // Promote the `(terminal_id, shift_number)` lookup index from v53 to a
    // UNIQUE index, mirroring the server's
    // `pos_shifts (terminal_id, shift_number)` unique constraint
    // (2026_06_14_110000_make_pos_shifts_terminal_shift_number_unique).
    //
    // The device mints shift numbers monotonically inside the fiscal write-gate
    // tx and the Phase-6.1 seed only ever RAISES the floor, so a duplicate is
    // impossible on the happy path. This is a fail-loud DB backstop: a regressed
    // caller, manual import, or restore edge case that reused a number for the
    // same terminal would otherwise silently break the per-register sequence
    // (NF525 "sans rupture de séquence"). The unique index also still backs the
    // `nextShiftNumber` MAX lookup, so the old plain index is dropped.
    //
    // Clean-slate / pre-live: no existing device has duplicate numbers, so the
    // unique index builds without remediation. Numbered v55 — migration
    // versions are a global UNIQUE key.
    version: 55,
    name: 'make_local_shifts_terminal_number_unique',
    sql: `
      DROP INDEX IF EXISTS idx_local_shifts_terminal_number;
      CREATE UNIQUE INDEX IF NOT EXISTS idx_local_shifts_terminal_number_unique
        ON local_shifts(terminal_id, shift_number);
    `,
  },
  {
    // Offline variant catalog (Spec A). Synced from GET /pos/variants. Only
    // active variants are stored; deactivated/soft-deleted come back as
    // deleted_ids and are removed. price_override is a decimal string.
    //
    // Renumbered from v53 → v56 during the feat/pos-offline-variants → dev
    // merge: dev independently shipped v53–v55 (offline-first shifts), and
    // migration versions are a global UNIQUE key, so Spec A's two migrations
    // were moved to sit after dev's max (v55).
    version: 56,
    name: 'create_product_variants',
    sql: `
      CREATE TABLE IF NOT EXISTS product_variants (
        id TEXT PRIMARY KEY,
        product_id TEXT NOT NULL,
        sku TEXT NOT NULL,
        barcode TEXT,
        name_suffix TEXT NOT NULL DEFAULT '',
        price_override TEXT,
        image_url TEXT,
        is_default INTEGER NOT NULL DEFAULT 0,
        display_order INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT,
        is_active INTEGER NOT NULL DEFAULT 1
      );
      CREATE INDEX IF NOT EXISTS idx_product_variants_product ON product_variants(product_id);
      CREATE INDEX IF NOT EXISTS idx_product_variants_barcode ON product_variants(barcode);
    `,
  },
  {
    // M4 adversarial-review: persist has_variants from the server /products
    // feed so the POS knows offline whether a product requires variant
    // selection before cart-add (opens VariantPickerModal instead of
    // directly calling addItemGated). Default 0 is safe for existing rows —
    // they stay on the non-variant path until the next catalog sync writes
    // the server-authoritative value. See ProductData.has_variants (backend).
    //
    // HIGH-1 fix: also clear the `products_last_sync` cursor from
    // sync_metadata so the next pullProductsCore executes a FULL re-fetch
    // and writes the server-authoritative has_variants value to every
    // existing product row. Without this reset, unchanged variant products
    // would never be re-fetched by the delta-keyed sync and would keep
    // has_variants=0 indefinitely.
    //
    // Renumbered from v54 → v57 during the feat/pos-offline-variants → dev
    // merge (see the v56 create_product_variants note above). Kept after v56
    // so the variant table exists before this column add.
    version: 57,
    name: 'add_has_variants_to_products',
    sql: `
      ALTER TABLE products ADD COLUMN has_variants INTEGER NOT NULL DEFAULT 0;
      DELETE FROM sync_metadata WHERE key = 'products_last_sync';
    `,
  },
  {
    // Parapharmacy Merchandising — Task 18.
    //
    // Adds three nullable TEXT columns to `products` so the parapharmacy
    // catalog fields synced from the server are persisted on the device:
    //   - `brand_id`: UUID of the product's brand (e.g. "Vichy", "Avène").
    //   - `brand_name`: Denormalised display label — avoids a join and lets
    //     the POS render brand badges fully offline.
    //   - `parapharmacy_metadata`: JSON blob (ParapharmacyMetadata shape)
    //     carrying category hierarchy, DCI code, age range, gender, and any
    //     future parapharmacy-specific fields. TEXT/JSON avoids schema churn
    //     when the payload evolves; the consumer parses + validates at read time.
    //
    // All three columns are nullable (no DEFAULT) — the existing product sync
    // writes only the fields the server sends; non-parapharmacy products never
    // receive these columns and correctly remain NULL.
    //
    // The `products_last_sync` cursor is deleted from `sync_metadata` so the
    // next `pullProductsCore` executes a full re-fetch. Without the reset,
    // existing product rows keep all three columns NULL indefinitely because the
    // delta-keyed sync never re-fetches already-seen products.
    //
    // Uses a `run` handler with idempotent `isDuplicateColumnError` guards
    // (matching the v30 / v54 pattern) so the migration survives re-application
    // or any schema drift without aborting the entire migration run.
    version: 58,
    name: 'add_brand_and_parapharmacy_metadata_to_products',
    sql: '',
    async run(db) {
      for (const col of ['brand_id', 'brand_name', 'parapharmacy_metadata']) {
        try {
          await db.execute(`ALTER TABLE products ADD COLUMN ${col} TEXT`);
        } catch (e) {
          if (!isDuplicateColumnError(e)) throw e;
        }
      }
      await db.execute(`DELETE FROM sync_metadata WHERE key = 'products_last_sync'`);
    },
  },
  {
    // v59: Add skin_type and skin_advice_note to the customers mirror table so
    // the POS can display (and optionally filter by) parapharmacy skin profile
    // data alongside the customer record.
    //
    // Both columns are nullable TEXT — non-parapharmacy installs never receive
    // these fields and correctly stay NULL; no DEFAULT is needed.
    //
    // The `customers.updated_since` cursor is deleted from `sync_metadata` so
    // the next `pullCustomers` executes a full re-fetch. Without the reset,
    // existing customer rows keep both skin columns NULL indefinitely because
    // the delta-keyed sync never re-fetches already-seen customers.
    //
    // Uses a `run` handler with idempotent `isDuplicateColumnError` guards
    // (matching the v58 pattern) so the migration survives re-application or
    // any schema drift without aborting the entire migration run.
    version: 59,
    name: 'add_skin_fields_to_customers',
    sql: '',
    async run(db) {
      for (const col of ['skin_type', 'skin_advice_note']) {
        try {
          await db.execute(`ALTER TABLE customers ADD COLUMN ${col} TEXT`);
        } catch (e) {
          if (!isDuplicateColumnError(e)) throw e;
        }
      }
      await db.execute(`DELETE FROM sync_metadata WHERE key = 'customers.updated_since'`);
    },
  },
];
