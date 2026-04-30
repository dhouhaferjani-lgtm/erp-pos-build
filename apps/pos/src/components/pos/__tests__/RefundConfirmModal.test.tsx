import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import type { RefundSubmitPayload } from '../RefundConfirmModal';

// ─── Module mocks ─────────────────────────────────────────────────────────────

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      const map: Record<string, string> = {
        'refundFlow.confirm.title': 'Confirm refund',
        'refundFlow.confirm.amountLabel': 'Refund amount',
        'refundFlow.confirm.submit': `Refund ${String(opts?.amount ?? '')}`,
        'refundFlow.confirm.submitting': 'Processing…',
        'refundFlow.confirm.cancel': 'Cancel',
        'refundFlow.confirm.errorGeneric': 'An unexpected error occurred. Please try again.',
        'refundFlow.confirm.authorizedBy': 'Authorized by manager',
        'refundFlow.confirm.pinRequired.override': 'Manager authorization required',
        'refundFlow.confirm.pinRequired.dailyCap': 'Daily refund cap reached — manager authorization required',
        'refundFlow.confirm.pinRequired.windowClosed': 'Refund window closed — manager authorization required',
      };
      return map[key] ?? (opts?.defaultValue as string | undefined) ?? key;
    },
    i18n: { language: 'en' },
  }),
}));

// Mock Modal to render children directly (avoids Portal complexity in jsdom)
vi.mock('@/components/pos/Modal', () => ({
  Modal: ({ isOpen, children, title }: { isOpen: boolean; children: React.ReactNode; title: string }) =>
    isOpen ? (
      <div data-testid="modal">
        <h2>{title}</h2>
        {children}
      </div>
    ) : null,
}));

// Mock ManagerPinPanel — expose a simple "verify" button in tests
vi.mock('@/components/pos/molecules/ManagerPinPanel', () => ({
  ManagerPinPanel: ({
    onVerify,
    onSuccess,
    authorizedManagers,
    excludeUserId,
  }: {
    onVerify: (userId: string, pin: string) => Promise<{ valid: boolean }>;
    onSuccess: (userId: string, name: string) => void;
    authorizedManagers: Array<{ id: string; name: string }>;
    excludeUserId: string;
  }) => {
    const eligible = authorizedManagers.filter((m) => m.id !== excludeUserId);
    return (
      <div data-testid="manager-pin-panel">
        <button
          data-testid="mock-pin-verify-success"
          onClick={async () => {
            const manager = eligible[0];
            if (!manager) return;
            const result = await onVerify(manager.id, '1234');
            if (result.valid) onSuccess(manager.id, manager.name);
          }}
        >
          Verify PIN (mock success)
        </button>
        <button
          data-testid="mock-pin-verify-fail"
          onClick={async () => {
            const manager = eligible[0];
            if (!manager) return;
            await onVerify(manager.id, '0000');
          }}
        >
          Verify PIN (mock fail)
        </button>
      </div>
    );
  },
}));

// Mock ApiRequestError
vi.mock('@/lib/api', () => {
  class MockApiRequestError extends Error {
    status: number;
    code: string;
    apiMessage: string;
    constructor(status: number, apiMessage: string, code: string) {
      super(apiMessage);
      this.name = 'ApiRequestError';
      this.status = status;
      this.apiMessage = apiMessage;
      this.code = code;
    }
  }
  return { ApiRequestError: MockApiRequestError };
});

// Import after mocks
import { RefundConfirmModal } from '../RefundConfirmModal';
import { ApiRequestError } from '@/lib/api';

// ─── Test helpers ─────────────────────────────────────────────────────────────

const MANAGERS = [
  { id: 'mgr-1', name: 'Manager One' },
  { id: 'mgr-2', name: 'Manager Two' },
];

const CASHIER_ID = 'cashier-99';

function buildProps(overrides: Partial<Parameters<typeof RefundConfirmModal>[0]> = {}) {
  return {
    isOpen: true,
    onClose: vi.fn(),
    refundAmount: '25.00 EUR',
    cashierUserId: CASHIER_ID,
    authorizedManagers: MANAGERS,
    onSubmitRefund: vi.fn<[RefundSubmitPayload], Promise<void>>().mockResolvedValue(undefined),
    onSuccess: vi.fn(),
    onVerifyManagerPin: vi.fn<[string, string], Promise<{ valid: boolean }>>().mockResolvedValue({ valid: true }),
    ...overrides,
  };
}

// ─── Tests ────────────────────────────────────────────────────────────────────

describe('RefundConfirmModal — basic rendering', () => {
  beforeEach(() => vi.clearAllMocks());

  it('renders the modal with amount when isOpen is true', () => {
    render(<RefundConfirmModal {...buildProps()} />);

    expect(screen.getByTestId('refund-confirm-modal')).toBeInTheDocument();
    expect(screen.getByTestId('refund-amount-value')).toHaveTextContent('25.00 EUR');
  });

  it('renders nothing when isOpen is false', () => {
    render(<RefundConfirmModal {...buildProps({ isOpen: false })} />);
    expect(screen.queryByTestId('refund-confirm-modal')).not.toBeInTheDocument();
  });

  it('calls onClose when Cancel is clicked', () => {
    const onClose = vi.fn();
    render(<RefundConfirmModal {...buildProps({ onClose })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-cancel'));
    expect(onClose).toHaveBeenCalledOnce();
  });
});

describe('RefundConfirmModal — successful submit', () => {
  beforeEach(() => vi.clearAllMocks());

  it('calls onSubmitRefund without authorized_by_user_id on first submit', async () => {
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>().mockResolvedValue(undefined);
    const onSuccess = vi.fn();
    render(<RefundConfirmModal {...buildProps({ onSubmitRefund, onSuccess })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());

    expect(onSubmitRefund).toHaveBeenCalledWith({});
  });
});

describe('RefundConfirmModal — MANAGER_OVERRIDE_REQUIRED flow', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows ManagerPinPanel when MANAGER_OVERRIDE_REQUIRED 422 is returned', async () => {
    const err = new ApiRequestError(422, 'Manager override required', 'MANAGER_OVERRIDE_REQUIRED');
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>().mockRejectedValue(err);

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('manager-pin-panel')).toBeInTheDocument());

    expect(screen.getByTestId('manager-pin-override-section')).toBeInTheDocument();
  });

  it('re-submits with authorized_by_user_id after manager PIN success', async () => {
    const err = new ApiRequestError(422, 'Manager override required', 'MANAGER_OVERRIDE_REQUIRED');
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>()
      .mockRejectedValueOnce(err)
      .mockResolvedValueOnce(undefined);
    const onVerifyManagerPin = vi.fn<[string, string], Promise<{ valid: boolean }>>()
      .mockResolvedValue({ valid: true });
    const onSuccess = vi.fn();

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund, onVerifyManagerPin, onSuccess })} />);

    // First submit — gets rejected
    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('manager-pin-panel')).toBeInTheDocument());

    // Manager verifies PIN
    await act(async () => {
      fireEvent.click(screen.getByTestId('mock-pin-verify-success'));
    });

    // Second submit should include authorized_by_user_id
    await waitFor(() => expect(onSuccess).toHaveBeenCalledOnce());

    expect(onSubmitRefund).toHaveBeenCalledTimes(2);
    const secondCall = onSubmitRefund.mock.calls[1]![0] as RefundSubmitPayload;
    expect(secondCall.authorized_by_user_id).toBe('mgr-1');
  });
});

describe('RefundConfirmModal — DAILY_REFUND_CAP_EXCEEDED', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows ManagerPinPanel for DAILY_REFUND_CAP_EXCEEDED', async () => {
    const err = new ApiRequestError(422, 'Daily cap exceeded', 'DAILY_REFUND_CAP_EXCEEDED');
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>().mockRejectedValue(err);

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('manager-pin-panel')).toBeInTheDocument());
  });
});

describe('RefundConfirmModal — REFUND_WINDOW_CLOSED', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows ManagerPinPanel for REFUND_WINDOW_CLOSED', async () => {
    const err = new ApiRequestError(422, 'Window closed', 'REFUND_WINDOW_CLOSED');
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>().mockRejectedValue(err);

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('manager-pin-panel')).toBeInTheDocument());
  });
});

describe('RefundConfirmModal — BUSINESS_ERROR generic fallback', () => {
  beforeEach(() => vi.clearAllMocks());

  it('shows inline error for BUSINESS_ERROR (no PIN prompt)', async () => {
    const err = new ApiRequestError(422, 'Something went wrong', 'BUSINESS_ERROR');
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>().mockRejectedValue(err);

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('refund-confirm-error')).toBeInTheDocument());

    expect(screen.queryByTestId('manager-pin-panel')).not.toBeInTheDocument();
    expect(screen.getByTestId('refund-confirm-error')).toHaveTextContent('Something went wrong');
  });

  it('shows inline error for unknown error codes', async () => {
    const err = new ApiRequestError(422, 'Unknown failure', 'UNKNOWN_CODE');
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>().mockRejectedValue(err);

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('refund-confirm-error')).toBeInTheDocument());
  });

  it('shows generic error for non-ApiRequestError exceptions', async () => {
    const onSubmitRefund = vi.fn<[RefundSubmitPayload], Promise<void>>()
      .mockRejectedValue(new Error('Network error'));

    render(<RefundConfirmModal {...buildProps({ onSubmitRefund })} />);

    fireEvent.click(screen.getByTestId('refund-confirm-submit'));
    await waitFor(() => expect(screen.getByTestId('refund-confirm-error')).toBeInTheDocument());

    expect(screen.getByTestId('refund-confirm-error')).toHaveTextContent(
      'An unexpected error occurred. Please try again.',
    );
  });
});
