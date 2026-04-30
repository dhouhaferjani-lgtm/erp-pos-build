import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';

vi.mock('react-i18next', () => ({
  useTranslation: () => ({
    t: (key: string, opts?: Record<string, unknown>) => {
      if (key === 'refundFlow.resumeBanner.title') {
        return `In-progress refund — Ticket ${String(opts?.number ?? '')}`;
      }
      const map: Record<string, string> = {
        'refundFlow.resumeBanner.resume': 'Resume',
        'refundFlow.resumeBanner.discard': 'Discard',
      };
      return map[key] ?? key;
    },
    i18n: { language: 'en' },
  }),
}));

import { ResumeRefundDraftBanner } from '../ResumeRefundDraftBanner';

describe('ResumeRefundDraftBanner', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('renders the banner with the receipt number', () => {
    render(
      <ResumeRefundDraftBanner
        receiptNumber="R-0042"
        onResume={vi.fn()}
        onDiscard={vi.fn()}
      />,
    );

    expect(screen.getByText('In-progress refund — Ticket R-0042')).toBeInTheDocument();
  });

  it('renders Resume and Discard buttons', () => {
    render(
      <ResumeRefundDraftBanner
        receiptNumber="R-0001"
        onResume={vi.fn()}
        onDiscard={vi.fn()}
      />,
    );

    expect(screen.getByRole('button', { name: 'Resume' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Discard' })).toBeInTheDocument();
  });

  it('calls onResume when Resume is clicked', () => {
    const onResume = vi.fn();
    render(
      <ResumeRefundDraftBanner receiptNumber="R-1" onResume={onResume} onDiscard={vi.fn()} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Resume' }));

    expect(onResume).toHaveBeenCalledOnce();
  });

  it('calls onDiscard when Discard is clicked', () => {
    const onDiscard = vi.fn();
    render(
      <ResumeRefundDraftBanner receiptNumber="R-1" onResume={vi.fn()} onDiscard={onDiscard} />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Discard' }));

    expect(onDiscard).toHaveBeenCalledOnce();
  });

  it('has role="alert" so screen readers announce it', () => {
    render(
      <ResumeRefundDraftBanner receiptNumber="R-99" onResume={vi.fn()} onDiscard={vi.fn()} />,
    );

    expect(screen.getByRole('alert')).toBeInTheDocument();
  });
});
