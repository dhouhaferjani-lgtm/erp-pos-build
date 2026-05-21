import type Database from '@tauri-apps/plugin-sql';
import { getDatabase } from '@/lib/db';
import { FiscalEventCanonicalEncoder } from '@/lib/fiscal/FiscalEventCanonicalEncoder';
import { FiscalEventEngine } from '@/lib/fiscal/FiscalEventEngine';
import { FiscalEventPayloadRegistry } from '@/lib/fiscal/FiscalEventPayloadRegistry';
import { HashChainIntegrityProvider } from '@/lib/fiscal/HashChainIntegrityProvider';

const engines = new Map<string, Promise<FiscalEventEngine>>();

export async function getFiscalEventEngine(
  companyId: string,
  dbOverride?: Database,
): Promise<FiscalEventEngine> {
  const existing = engines.get(companyId);
  if (existing) return existing;

  const dbPromise = dbOverride ? Promise.resolve(dbOverride) : getDatabase(companyId);
  const created = dbPromise.then((db: Database) => new FiscalEventEngine(
    db,
    new FiscalEventCanonicalEncoder(),
    new HashChainIntegrityProvider(),
    new FiscalEventPayloadRegistry(),
  ));
  engines.set(companyId, created);
  return created;
}

export function __resetFiscalEventEngineForTesting(): void {
  engines.clear();
}
