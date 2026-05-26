import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { TerminalNotReadyBanner } from '@/components/atoms/TerminalNotReadyBanner';
import { useTerminalStore } from '@/stores/terminalStore';
import { useSyncStore } from '@/stores/syncStore';
import '@/lib/i18n';

describe('TerminalNotReadyBanner', () => {
  beforeEach(() => {
    useTerminalStore.setState({
      hashChainReady: true,
      terminal: { id: 't1', code: 'T001', name: 'Counter 1', type: 'fixed', is_active: true, fiscal_schema_version: 2, is_training_mode: false, hardware_identifier: null, location: { id: 'loc1', name: 'Main', code: 'MAIN' } },
      pendingTerminalId: null,
      shift: null,
      isLoading: false,
    });
    useSyncStore.setState({ scheduler: null });
  });

  it('renders nothing when hashChainReady is true', () => {
    const { container } = render(<TerminalNotReadyBanner />);
    expect(container.firstChild).toBeNull();
  });

  it('renders banner when hashChainReady is false', () => {
    useTerminalStore.setState({ hashChainReady: false });
    render(<TerminalNotReadyBanner />);
    expect(screen.getByRole('alert')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Try activation now/i })).toBeInTheDocument();
  });

  it('button click invokes scheduler.syncNow()', async () => {
    const syncNow = vi.fn().mockResolvedValue({ chainBreak: false });
    useSyncStore.setState({ scheduler: { syncNow } as any });
    useTerminalStore.setState({ hashChainReady: false });

    render(<TerminalNotReadyBanner />);
    fireEvent.click(screen.getByRole('button', { name: /Try activation now/i }));

    expect(syncNow).toHaveBeenCalledOnce();
  });
});
