import { SqliteTestAdapter } from './sqliteTestAdapter';

/**
 * Simulates the `@tauri-apps/plugin-sql` failure surface: a POOL of physical
 * SQLite connections where every execute/select borrows the NEXT connection
 * (round-robin — sqlx's idle queue under concurrency), and every error is
 * rejected as a plain STRING (the Tauri invoke boundary).
 *
 * `busy_timeout` is 0 so lock collisions fail immediately and the test is
 * deterministic (production waits 5s then fails the same way).
 */
export class PoolSimAdapter {
  private readonly conns: SqliteTestAdapter[];
  private next = 0;

  constructor(filename: string, poolSize: number) {
    this.conns = Array.from({ length: poolSize }, () => {
      const c = new SqliteTestAdapter(filename);
      c.inner.pragma('journal_mode = WAL');
      c.inner.pragma('busy_timeout = 0');
      return c;
    });
  }

  private acquire(): SqliteTestAdapter {
    const c = this.conns[this.next % this.conns.length]!;
    this.next++;
    return c;
  }

  async execute(sql: string, params?: unknown[]): Promise<{ rowsAffected: number; lastInsertId?: number }> {
    try {
      return await this.acquire().execute(sql, params);
    } catch (e) {
      throw `error returned from database: (code: 5) ${(e as Error).message}`;
    }
  }

  async select<T>(sql: string, params?: unknown[]): Promise<T> {
    try {
      return await this.acquire().select<T>(sql, params);
    } catch (e) {
      throw `error returned from database: (code: 5) ${(e as Error).message}`;
    }
  }

  close(): void {
    for (const c of this.conns) c.close();
  }
}
