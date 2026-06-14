/**
 * UUIDv7 generator (RFC 9562) — a 128-bit identifier whose leading 48 bits are
 * the big-endian Unix-millisecond timestamp, so ids are lexicographically
 * time-ordered. The POS device mints one per shift (== fiscal_shift_id ==
 * pos_shifts.id); time-ordering keeps shift ids naturally sortable alongside
 * the per-terminal monotone `shift_number`.
 *
 * `crypto.randomUUID()` only emits v4 (no time component); the project has no
 * uuid dependency, so this small generator fills the gap. It uses the Web
 * Crypto `getRandomValues` (available in Tauri's webview and in the Vitest
 * environment).
 */

function randomBytes(length: number): Uint8Array {
  const bytes = new Uint8Array(length);
  const c = globalThis.crypto;
  if (!c || typeof c.getRandomValues !== 'function') {
    throw new Error('uuidv7: secure crypto.getRandomValues is unavailable');
  }
  c.getRandomValues(bytes);
  return bytes;
}

const HEX = Array.from({ length: 256 }, (_, i) => i.toString(16).padStart(2, '0'));

export function uuidv7(): string {
  const bytes = randomBytes(16);

  // 48-bit big-endian millisecond timestamp in bytes 0..5.
  const ms = Date.now();
  bytes[0] = Math.floor(ms / 0x10000000000) & 0xff;
  bytes[1] = Math.floor(ms / 0x100000000) & 0xff;
  bytes[2] = Math.floor(ms / 0x1000000) & 0xff;
  bytes[3] = Math.floor(ms / 0x10000) & 0xff;
  bytes[4] = Math.floor(ms / 0x100) & 0xff;
  bytes[5] = ms & 0xff;

  // Version 7 in the high nibble of byte 6; RFC variant (10xx) in byte 8.
  bytes[6] = (bytes[6]! & 0x0f) | 0x70;
  bytes[8] = (bytes[8]! & 0x3f) | 0x80;

  const h = HEX;
  return (
    h[bytes[0]!]! + h[bytes[1]!]! + h[bytes[2]!]! + h[bytes[3]!]! +
    '-' + h[bytes[4]!]! + h[bytes[5]!]! +
    '-' + h[bytes[6]!]! + h[bytes[7]!]! +
    '-' + h[bytes[8]!]! + h[bytes[9]!]! +
    '-' + h[bytes[10]!]! + h[bytes[11]!]! + h[bytes[12]!]! + h[bytes[13]!]! + h[bytes[14]!]! + h[bytes[15]!]!
  );
}
