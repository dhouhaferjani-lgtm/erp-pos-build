export type FiscalEventCanonicalPrimitive = string | number | boolean | null;

export type FiscalEventCanonicalInput =
  | FiscalEventCanonicalPrimitive
  | FiscalEventCanonicalInput[]
  | { [key: string]: FiscalEventCanonicalInput };

// Transitional Phase 1 row shape; Task 13 splits the device SQLite row from
// the server mirror once both tables exist.
export interface FiscalEventRow {
  id: string;
  tenant_id: string;
  company_id: string;
  terminal_id: string;
  operator_id: string;
  event_type: string;
  event_version: number;
  signature_version: string;
  sequence_number: number;
  event_time_device: string;
  business_date: string;
  canonical_bytes: string;
  previous_hash: string;
  current_hash: string;
  reference_document_id: string | null;
  reference_event_id: string | null;
  payload: FiscalEventCanonicalInput | null;
  payload_parse_status: 'pending' | 'parsed' | 'failed';
  signature_status: 'not_required' | 'pending' | 'signed' | 'failed';
  integrity_status: 'verified' | 'quarantined';
}
