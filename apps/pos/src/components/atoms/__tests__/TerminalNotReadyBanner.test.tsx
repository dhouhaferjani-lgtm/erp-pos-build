import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { TerminalNotReadyBanner } from '@/components/atoms/TerminalNotReadyBanner';
import { useTerminalStore } from '@/stores/terminalStore';
import { useSyncStore } from '@/stores/syncStore';
import '@/lib/i18n';

describe('TerminalNotReadyBanner', () => {
  beforeEach(() => {
    useTerminalStore.setState({
      hashChainReady: true,
      terminal: { id: 't1', code: 'T001', name: 'Counter 1', type: 'fixed', is_active: true, hardware_identifier: null, location: { id: 'loc1', name: 'Main', code: 'MAIN' } },
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
    expect(screen.getByText(/activate|activation|connect/i)).toBeInTheDocument();
  });
});
