import { beforeEach, describe, expect, it, vi } from 'vitest';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { C2MigrationBanner } from '../C2MigrationBanner';
import { useC2MigrationBannerStore } from '@/stores/c2MigrationBannerStore';
import { getStoredValue, setStoredValue, StorageKeys } from '@/lib/storage';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string) => key,
  }),
}));

vi.mock('@/lib/storage', () => ({
  getStoredValue: vi.fn(),
  setStoredValue: vi.fn().mockResolvedValue(undefined),
  StorageKeys: {
    C2_MIGRATION_BANNER: 'c2_migration_banner',
  },
}));

describe('C2MigrationBanner', () => {
  beforeEach(() => {
    cleanup();
    vi.clearAllMocks();
    // Reset the store to its initial state before each test so a leaked
    // `visible=true` from a previous test does not influence the next one.
    useC2MigrationBannerStore.setState({ visible: false });
  });

  it('renders nothing when no migration warning is pending', async () => {
    vi.mocked(getStoredValue).mockResolvedValue(false);

    const { queryByTestId } = render(<C2MigrationBanner />);

    await waitFor(() => {
      expect(getStoredValue).toHaveBeenCalledWith(StorageKeys.C2_MIGRATION_BANNER);
    });
    expect(queryByTestId('c2-migration-banner')).toBeNull();
  });

  it('renders the warning when the persisted flag is pending on mount', async () => {
    vi.mocked(getStoredValue).mockResolvedValue(true);

    render(<C2MigrationBanner />);

    const banner = await screen.findByTestId('c2-migration-banner');
    expect(banner.getAttribute('role')).toBe('status');
    expect(banner.getAttribute('aria-live')).toBe('polite');
    expect(banner.textContent ?? '').toContain('c2Migration.banner');
  });

  it('dismisses the warning and clears the persisted flag', async () => {
    vi.mocked(getStoredValue).mockResolvedValue(true);

    render(<C2MigrationBanner />);
    fireEvent.click(await screen.findByRole('button', { name: 'c2Migration.dismiss' }));

    await waitFor(() => {
      expect(setStoredValue).toHaveBeenCalledWith(StorageKeys.C2_MIGRATION_BANNER, false);
    });
    expect(screen.queryByTestId('c2-migration-banner')).toBeNull();
    expect(useC2MigrationBannerStore.getState().visible).toBe(false);
  });

  it('reactively renders when the store flips to visible AFTER mount (Codex PR #118 r5 P2 — deferred-retry dump)', async () => {
    // Initial mount with the persisted flag still false. This mirrors the
    // production trace: migration deferred on offline boot → AppShell
    // mounts → banner mounts and reads `false` from Tauri Store.
    vi.mocked(getStoredValue).mockResolvedValue(false);

    render(<C2MigrationBanner />);

    await waitFor(() => {
      expect(getStoredValue).toHaveBeenCalledWith(StorageKeys.C2_MIGRATION_BANNER);
    });
    expect(screen.queryByTestId('c2-migration-banner')).toBeNull();

    // Now the 30s deferred retry lands, dumps carts, and App.tsx fires
    // `useC2MigrationBannerStore.getState().show()`. The banner must
    // re-render — that's the property this PR introduces.
    act(() => {
      useC2MigrationBannerStore.getState().show();
    });

    const banner = await screen.findByTestId('c2-migration-banner');
    expect(banner.textContent ?? '').toContain('c2Migration.banner');
  });
});
