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
];
