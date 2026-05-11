export interface Migration {
  version: number;
  name: string;
  sql: string;
  run?: (db: { execute: (sql: string, params?: unknown[]) => Promise<unknown> }) => Promise<void>;
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
          const msg = error instanceof Error ? error.message : '';
          if (!msg.includes('duplicate column')) {
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
        const msg = error instanceof Error ? error.message : '';
        if (!msg.includes('duplicate column')) {
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
          const msg = error instanceof Error ? error.message : '';
          if (!msg.includes('duplicate column')) {
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
          const msg = error instanceof Error ? error.message : '';
          if (!msg.includes('duplicate column')) {
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
        const msg = error instanceof Error ? error.message : '';
        if (!msg.includes('duplicate column')) {
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
    // Adds `fiscal_schema_version` to two tables:
    //   - terminal_state: projection of the server-side Terminal column. The
    //     receipt-creation path branches on this value to choose v2 (legacy
    //     `computeFiscalHash`) vs v3 (`buildCanonicalPayload` + SHA-256). The
    //     value is refreshed every `pullTerminalState` cycle.
    //   - offline_receipts: stamped at insert time so the sync payload can
    //     declare the version each row was sealed against. The server's
    //     `ReceiptSyncService` rejects any payload whose declared version
    //     does not match the terminal's current version.
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
          const msg = error instanceof Error ? error.message : '';
          if (!msg.includes('duplicate column')) {
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
    // server but the offline-first POS path did not honor it). The wire-shape
    // builder (`receiptToPayload`) reads this column and emits a boolean
    // `is_training` field on the sync payload; the server-side T2.7 backend
    // (PR #103) branches on that flag in `ReceiptSyncService::syncSingleReceipt`.
    version: 29,
    name: 'add_is_training_to_offline_receipts',
    sql: '',
    async run(db) {
      try {
        await db.execute(
          'ALTER TABLE offline_receipts ADD COLUMN is_training INTEGER NOT NULL DEFAULT 0',
        );
      } catch (error) {
        const msg = error instanceof Error ? error.message : '';
        if (!msg.includes('duplicate column')) {
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
          const msg = error instanceof Error ? error.message : '';
          if (!msg.includes('duplicate column')) {
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
    // Bug 5 — one-shot recovery for offline_receipts that were dead-lettered
    // because of the SQLITE_BUSY cascade. The Tauri plugin-sql layer throws
    // the libsqlite failure as a raw string; the lock-specific signature is
    // `(code: 5) database is locked`. Before this PR, the sync error
    // serializer collapsed that string to the literal `'Unknown error'`,
    // so existing dead-lettered rows are NOT recoverable here by design —
    // they need PR C's recovery UX (or manual SQLite) since the
    // `'Unknown error'` signature is ambiguous. From this PR onward,
    // `coerceSyncError` preserves the lock signature, and any FUTURE
    // failure that re-enters the lock state can be recovered automatically
    // on the next app launch.
    //
    // The migration is intentionally narrow:
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
];
