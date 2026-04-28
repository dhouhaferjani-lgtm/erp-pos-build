import { apiPost } from '@/lib/api';

export interface ManagerPinVerifyResult {
  valid: boolean;
}

export async function verifyManagerPin(
  userId: string,
  pin: string,
): Promise<ManagerPinVerifyResult> {
  return apiPost<ManagerPinVerifyResult>('/pos/verify-manager-pin', {
    user_id: userId,
    pin,
  });
}
