import { describe, it, expect, vi, beforeEach } from 'vitest';
import { verifyManagerPin } from '../managerPinApi';

vi.mock('@/lib/api', () => ({
  apiPost: vi.fn(),
}));
import { apiPost } from '@/lib/api';

describe('managerPinApi.verifyManagerPin', () => {
  beforeEach(() => {
    vi.mocked(apiPost).mockReset();
  });

  it('POSTs to /pos/verify-manager-pin and returns the unwrapped response', async () => {
    vi.mocked(apiPost).mockResolvedValueOnce({ valid: true });
    const result = await verifyManagerPin('user-1', '1234');
    expect(apiPost).toHaveBeenCalledWith('/pos/verify-manager-pin', {
      user_id: 'user-1',
      pin: '1234',
    });
    expect(result).toEqual({ valid: true });
  });

  it('forwards rejection on errors', async () => {
    vi.mocked(apiPost).mockRejectedValueOnce(new Error('429 Too Many Attempts'));
    await expect(verifyManagerPin('user-1', '0000')).rejects.toThrow(/Too Many/);
  });
});
