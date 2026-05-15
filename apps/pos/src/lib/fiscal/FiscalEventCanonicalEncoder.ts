export class CanonicalEncodingError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'CanonicalEncodingError';
  }
}

export class FiscalEventCanonicalEncoder {
  encode(input: unknown): string {
    return encodeValue(input);
  }

  sha256Hex(input: string): string {
    return sha256Hex(input);
  }
}

function encodeValue(value: unknown): string {
  if (value === null) {
    return 'null';
  }

  if (typeof value === 'boolean') {
    return value ? 'true' : 'false';
  }

  if (typeof value === 'number') {
    if (!Number.isInteger(value)) {
      throw new CanonicalEncodingError(
        `Non-integer number ${value} is not allowed in fiscal canonical JSON. ` +
          'Decimal monetary values must be pre-formatted as strings.',
      );
    }

    return String(value);
  }

  if (typeof value === 'string') {
    return encodeString(value);
  }

  if (Array.isArray(value)) {
    return '[' + value.map((item) => encodeValue(item)).join(',') + ']';
  }

  if (typeof value === 'object') {
    return encodeObject(value as Record<string, unknown>);
  }

  throw new CanonicalEncodingError(`Unsupported value type: ${typeof value}`);
}

function encodeObject(value: Record<string, unknown>): string {
  const keys = Object.keys(value).sort((a, b) => (a < b ? -1 : a > b ? 1 : 0));

  return (
    '{' +
    keys
      .map((key) => encodeString(key) + ':' + encodeValue(value[key]))
      .join(',') +
    '}'
  );
}

function encodeString(value: string): string {
  return JSON.stringify(normalizeFiscalString(value));
}

function normalizeFiscalString(value: string): string {
  return value.normalize('NFC').replace(/[\u2028\u2029]/g, '');
}

function sha256Hex(input: string): string {
  return sha256Bytes(new TextEncoder().encode(input))
    .map((byte) => byte.toString(16).padStart(2, '0'))
    .join('');
}

function sha256Bytes(input: Uint8Array): number[] {
  const hash = [
    0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c,
    0x1f83d9ab, 0x5be0cd19,
  ];
  const roundConstants = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1,
    0x923f82a4, 0xab1c5ed5, 0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3,
    0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174, 0xe49b69c1, 0xefbe4786,
    0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147,
    0x06ca6351, 0x14292967, 0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13,
    0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85, 0xa2bfe8a1, 0xa81a664b,
    0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a,
    0x5b9cca4f, 0x682e6ff3, 0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208,
    0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2,
  ];
  const bytes = paddedSha256Input(input);
  const words = new Array<number>(64);

  for (let offset = 0; offset < bytes.length; offset += 64) {
    for (let index = 0; index < 16; index += 1) {
      const cursor = offset + index * 4;
      words[index] =
        ((bytes[cursor] ?? 0) << 24) |
        ((bytes[cursor + 1] ?? 0) << 16) |
        ((bytes[cursor + 2] ?? 0) << 8) |
        (bytes[cursor + 3] ?? 0);
    }

    for (let index = 16; index < 64; index += 1) {
      const s0 =
        rotateRight(words[index - 15] ?? 0, 7) ^
        rotateRight(words[index - 15] ?? 0, 18) ^
        ((words[index - 15] ?? 0) >>> 3);
      const s1 =
        rotateRight(words[index - 2] ?? 0, 17) ^
        rotateRight(words[index - 2] ?? 0, 19) ^
        ((words[index - 2] ?? 0) >>> 10);
      words[index] = add32(words[index - 16] ?? 0, s0, words[index - 7] ?? 0, s1);
    }

    let a = hash[0] ?? 0;
    let b = hash[1] ?? 0;
    let c = hash[2] ?? 0;
    let d = hash[3] ?? 0;
    let e = hash[4] ?? 0;
    let f = hash[5] ?? 0;
    let g = hash[6] ?? 0;
    let h = hash[7] ?? 0;

    for (let index = 0; index < 64; index += 1) {
      const s1 = rotateRight(e, 6) ^ rotateRight(e, 11) ^ rotateRight(e, 25);
      const choice = (e & f) ^ (~e & g);
      const temp1 = add32(h, s1, choice, roundConstants[index] ?? 0, words[index] ?? 0);
      const s0 = rotateRight(a, 2) ^ rotateRight(a, 13) ^ rotateRight(a, 22);
      const majority = (a & b) ^ (a & c) ^ (b & c);
      const temp2 = add32(s0, majority);

      h = g;
      g = f;
      f = e;
      e = add32(d, temp1);
      d = c;
      c = b;
      b = a;
      a = add32(temp1, temp2);
    }

    hash[0] = add32(hash[0] ?? 0, a);
    hash[1] = add32(hash[1] ?? 0, b);
    hash[2] = add32(hash[2] ?? 0, c);
    hash[3] = add32(hash[3] ?? 0, d);
    hash[4] = add32(hash[4] ?? 0, e);
    hash[5] = add32(hash[5] ?? 0, f);
    hash[6] = add32(hash[6] ?? 0, g);
    hash[7] = add32(hash[7] ?? 0, h);
  }

  return hash.flatMap((word) => [
    (word >>> 24) & 0xff,
    (word >>> 16) & 0xff,
    (word >>> 8) & 0xff,
    word & 0xff,
  ]);
}

function paddedSha256Input(input: Uint8Array): Uint8Array {
  const bitLength = BigInt(input.length) * 8n;
  const paddedLength = Math.ceil((input.length + 9) / 64) * 64;
  const bytes = new Uint8Array(paddedLength);
  bytes.set(input);
  bytes[input.length] = 0x80;

  for (let index = 0; index < 8; index += 1) {
    bytes[paddedLength - 1 - index] = Number((bitLength >> BigInt(index * 8)) & 0xffn);
  }

  return bytes;
}

function rotateRight(value: number, bits: number): number {
  return (value >>> bits) | (value << (32 - bits));
}

function add32(...values: number[]): number {
  return values.reduce((sum, value) => (sum + value) >>> 0, 0);
}
