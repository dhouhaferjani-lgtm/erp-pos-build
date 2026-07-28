import { act, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

type CustomerDisplayListener = (event: {
  payload: {
    type: 'cart';
    items: Array<{ name: string; quantity: number; quantity_decimals?: number | null; line_total: string }>;
    total: string;
    currency: string;
  };
}) => void;

let listener: CustomerDisplayListener | undefined;

vi.mock('@tauri-apps/api/event', () => ({
  listen: vi.fn((_event: string, handler: CustomerDisplayListener) => {
    listener = handler;
    return Promise.resolve(vi.fn());
  }),
}));

vi.mock('react-i18next', async (importOriginal) => ({
  ...(await importOriginal()),
  useTranslation: () => ({ t: (key: string) => key }),
}));

import { CustomerDisplayPage } from './CustomerDisplayPage';

describe('CustomerDisplayPage', () => {
  beforeEach(() => {
    listener = undefined;
  });

  it('formats cart quantities at the supplied product unit precision', async () => {
    render(<CustomerDisplayPage />);

    await vi.waitFor(() => expect(listener).toBeDefined());
    act(() => {
      listener?.({
        payload: {
          type: 'cart',
          items: [{ name: 'Olive oil', quantity: 1.5, quantity_decimals: 2, line_total: '12.00' }],
          total: '12.00',
          currency: 'EUR',
        },
      });
    });

    expect(screen.getByText('1.50x')).toBeTruthy();
  });
});
