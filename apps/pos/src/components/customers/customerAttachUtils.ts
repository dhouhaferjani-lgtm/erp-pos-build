export const CUSTOMER_ATTACH_SCOPE_ERROR = 'Tenant and company are required to attach a customer.';

function hash32(input: string, seed: number): number {
  let hash = seed >>> 0;
  for (let index = 0; index < input.length; index += 1) {
    hash ^= input.charCodeAt(index);
    hash = Math.imul(hash, 16777619);
  }
  hash ^= hash >>> 13;
  hash = Math.imul(hash, 2246822519);
  hash ^= hash >>> 16;
  return hash >>> 0;
}

function hex32(value: number): string {
  return value.toString(16).padStart(8, '0');
}

export function deterministicPendingCustomerUuid(input: {
  tenantId: string;
  companyId: string;
  name: string;
  phone: string | null;
  email: string | null;
}): string {
  const seed = [
    input.tenantId.trim().toLowerCase(),
    input.companyId.trim().toLowerCase(),
    input.name.trim().toLowerCase(),
    input.phone?.trim().toLowerCase() ?? '',
    input.email?.trim().toLowerCase() ?? '',
  ].join('|');
  const a = hex32(hash32(seed, 0x811c9dc5));
  const b = hex32(hash32(seed, 0x9e3779b9));
  const c = hex32(hash32(seed, 0x85ebca6b));
  const d = hex32(hash32(seed, 0xc2b2ae35));
  const uuid = `${a}${b}${c}${d}`;
  return `${uuid.slice(0, 8)}-${uuid.slice(8, 12)}-4${uuid.slice(13, 16)}-${((parseInt(uuid.slice(16, 18), 16) & 0x3f) | 0x80).toString(16)}${uuid.slice(18, 20)}-${uuid.slice(20, 32)}`;
}
