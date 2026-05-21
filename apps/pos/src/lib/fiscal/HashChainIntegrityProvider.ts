import { FiscalEventCanonicalEncoder } from './FiscalEventCanonicalEncoder';

export interface FiscalIntegrityProvider {
  version(): string;
  computeHash(canonicalBytes: string): string;
  verify(canonicalBytes: string, currentHash: string): boolean;
}

export class HashChainIntegrityProvider implements FiscalIntegrityProvider {
  private readonly encoder = new FiscalEventCanonicalEncoder();

  version(): string {
    return 'hash-chain-integrity-v1';
  }

  computeHash(canonicalBytes: string): string {
    return this.encoder.sha256Hex(canonicalBytes);
  }

  verify(canonicalBytes: string, currentHash: string): boolean {
    return this.computeHash(canonicalBytes) === currentHash;
  }
}
