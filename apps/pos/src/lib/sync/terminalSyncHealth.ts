import { apiPost } from '@/lib/api';
import { getDeviceId } from '@/lib/device';

/**
 * Report device-local sync risk for the inventory counting finalize gate.
 * This is advisory telemetry only; failure must never fail or delay a receipt
 * sync result. The scheduler owns the non-fatal error boundary.
 */
export async function reportTerminalSyncHealth(
  terminalId: string,
  pendingReceiptCount: number,
  lastSyncAt: number,
): Promise<void> {
  await apiPost('/inventory/terminal-sync-health', {
    terminal_id: terminalId,
    hardware_identifier: getDeviceId(),
    pending_receipt_count: pendingReceiptCount,
    last_sync_at: new Date(lastSyncAt).toISOString(),
  });
}
