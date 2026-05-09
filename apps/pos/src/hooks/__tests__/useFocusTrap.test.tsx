/**
 * Focus-trap hook for POS modals.
 *
 * Closes the T2.1 deferral noted in PR #94 — `BarcodeChooserModal focus
 * trap` was filed as a follow-up "accessibility pass on all POS modals"
 * because the gap was pre-existing across every modal in the app, not
 * specific to the chooser. This hook is the shared primitive; the base
 * `Modal` component plus the standalone `BarcodeChooserModal` consume it.
 *
 * Contract:
 *   - On activation: save the element that had focus when the modal
 *     opened (so we can restore on close). Focus the first focusable
 *     descendant of the modal container.
 *   - While active: Tab cycles forward; Shift+Tab cycles backward;
 *     focus never escapes the container even if the user keeps tabbing.
 *   - On deactivation (isActive flips false OR the hook unmounts):
 *     restore focus to the saved element.
 *
 * Out of scope: arrow-key navigation, custom roving tabindex, screen-
 * reader heuristics. Those are component-specific.
 */
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup, fireEvent, act } from '@testing-library/react';
import { useRef, useState } from 'react';
import { useFocusTrap } from '../useFocusTrap';

function TrapHarness({ initiallyActive = true }: { initiallyActive?: boolean }) {
  const [active, setActive] = useState(initiallyActive);
  const ref = useRef<HTMLDivElement>(null);

  useFocusTrap({ isActive: active, containerRef: ref });

  return (
    <>
      <button data-testid="outside-before" onClick={() => setActive(true)}>open</button>
      <div ref={ref} data-testid="modal">
        <button data-testid="first">first</button>
        <input data-testid="middle" />
        <button data-testid="last">last</button>
        <button data-testid="close" onClick={() => setActive(false)}>close</button>
      </div>
      <button data-testid="outside-after">after</button>
    </>
  );
}

function dispatchTab(target: Element, shift = false) {
  const event = new KeyboardEvent('keydown', {
    key: 'Tab',
    bubbles: true,
    cancelable: true,
    shiftKey: shift,
  });
  target.dispatchEvent(event);
  return event;
}

describe('useFocusTrap', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  afterEach(() => {
    cleanup();
  });

  it('focuses the first focusable element on activation', () => {
    const { getByTestId } = render(<TrapHarness />);

    const first = getByTestId('first');
    expect(document.activeElement).toBe(first);
  });

  it('Tab from the last focusable cycles back to the first', () => {
    const { getByTestId } = render(<TrapHarness />);

    const close = getByTestId('close');
    close.focus();
    expect(document.activeElement).toBe(close);

    dispatchTab(close);

    // Hook intercepted Tab and cycled focus to first.
    expect(document.activeElement).toBe(getByTestId('first'));
  });

  it('Shift+Tab from the first focusable cycles back to the last', () => {
    const { getByTestId } = render(<TrapHarness />);

    const first = getByTestId('first');
    expect(document.activeElement).toBe(first);

    dispatchTab(first, true);

    expect(document.activeElement).toBe(getByTestId('close'));
  });

  it('does not interfere with tab between focusables in the middle', () => {
    const { getByTestId } = render(<TrapHarness />);

    const middle = getByTestId('middle');
    middle.focus();

    const event = dispatchTab(middle);

    // Hook does NOT preventDefault for non-edge cases; the browser
    // handles default tab movement. We can only verify the event
    // wasn't cancelled.
    expect(event.defaultPrevented).toBe(false);
  });

  it('restores focus to the originating element when the trap deactivates', () => {
    // Modal body is always rendered; isActive controls the trap. This
    // matches the real `pos/Modal.tsx` pattern (the modal short-circuits
    // when isOpen=false, but the wrapper component's effect still fires
    // on isActive change). Avoids ref-timing flakes from conditional
    // mount/unmount.
    function Wrapper() {
      const [active, setActive] = useState(false);
      const ref = useRef<HTMLDivElement>(null);
      useFocusTrap({ isActive: active, containerRef: ref });
      return (
        <>
          <button data-testid="opener" onClick={() => setActive(true)}>open</button>
          <div ref={ref} data-testid="modal-body">
            <button
              data-testid="modal-close"
              onClick={() => setActive(false)}
            >
              close
            </button>
          </div>
        </>
      );
    }

    const { getByTestId } = render(<Wrapper />);

    // Focus the opener (simulating real activation flow).
    const opener = getByTestId('opener');
    act(() => {
      opener.focus();
    });
    expect(document.activeElement).toBe(opener);

    // Open the modal — focus jumps inside the trap.
    fireEvent.click(opener);
    expect(document.activeElement).toBe(getByTestId('modal-close'));

    // Close — focus restored to opener.
    fireEvent.click(getByTestId('modal-close'));
    expect(document.activeElement).toBe(opener);
  });

  it('does nothing when isActive is false', () => {
    const outside = document.createElement('button');
    document.body.appendChild(outside);
    outside.focus();

    render(<TrapHarness initiallyActive={false} />);

    // Focus stays on the outside button — the trap didn't activate.
    expect(document.activeElement).toBe(outside);
  });

  it('handles a modal with no focusable elements without crashing', () => {
    function EmptyModal() {
      const ref = useRef<HTMLDivElement>(null);
      useFocusTrap({ isActive: true, containerRef: ref });
      return <div ref={ref} data-testid="empty" />;
    }

    expect(() => render(<EmptyModal />)).not.toThrow();
  });

  it('Codex round-1 P2: preserves autoFocus targets — does not override an input already focused inside the container', () => {
    function HarnessWithAutoFocus() {
      const ref = useRef<HTMLDivElement>(null);
      useFocusTrap({ isActive: true, containerRef: ref });
      return (
        <div ref={ref} data-testid="modal">
          <button data-testid="close-x">×</button>
          {/* React's `autoFocus` prop focuses this input synchronously
              during the render commit. The trap effect runs AFTER that. */}
          <input data-testid="amount-input" autoFocus />
          <button data-testid="confirm">Confirm</button>
        </div>
      );
    }

    const { getByTestId } = render(<HarnessWithAutoFocus />);

    // Trap MUST preserve the autoFocus target rather than reseating
    // focus on the first focusable (which would be the close-x button).
    expect(document.activeElement).toBe(getByTestId('amount-input'));
  });

  it('Codex round-1 P2: preserves any element-inside-container focus, not just inputs', () => {
    function HarnessWithMidFocus() {
      const ref = useRef<HTMLDivElement>(null);
      useFocusTrap({ isActive: true, containerRef: ref });
      return (
        <div ref={ref} data-testid="modal">
          <button data-testid="first">first</button>
          <button data-testid="middle" autoFocus>middle</button>
          <button data-testid="last">last</button>
        </div>
      );
    }

    const { getByTestId } = render(<HarnessWithMidFocus />);
    expect(document.activeElement).toBe(getByTestId('middle'));
  });

  it('Codex round-2 P2: opener focus is restored even when modal has an autoFocus child', () => {
    // The classic VoucherTenderModal flow: voucher-code input has
    // autoFocus, so React focuses it during commit BEFORE the trap's
    // useEffect runs. The hook must still restore focus to the tile
    // that opened the modal — render-phase capture handles this.
    function VoucherFlow() {
      const [active, setActive] = useState(false);
      const ref = useRef<HTMLDivElement>(null);
      useFocusTrap({ isActive: active, containerRef: ref });
      return (
        <>
          <button data-testid="voucher-tile" onClick={() => setActive(true)}>
            voucher
          </button>
          {active && (
            <div ref={ref} data-testid="voucher-modal">
              <button data-testid="voucher-close-x">×</button>
              <input data-testid="voucher-code" autoFocus />
              <button
                data-testid="voucher-cancel"
                onClick={() => setActive(false)}
              >
                cancel
              </button>
            </div>
          )}
        </>
      );
    }

    const { getByTestId } = render(<VoucherFlow />);

    const tile = getByTestId('voucher-tile');
    act(() => {
      tile.focus();
    });
    expect(document.activeElement).toBe(tile);

    // Open modal — autoFocus input grabs focus, NOT close-x.
    fireEvent.click(tile);
    expect(document.activeElement).toBe(getByTestId('voucher-code'));

    // Close — focus restored to the tile that opened the modal.
    fireEvent.click(getByTestId('voucher-cancel'));
    expect(document.activeElement).toBe(tile);
  });

  it('cleans up listeners on unmount', () => {
    const removeSpy = vi.spyOn(document, 'removeEventListener');

    const { unmount } = render(<TrapHarness />);
    unmount();

    expect(removeSpy).toHaveBeenCalledWith('keydown', expect.any(Function), true);

    removeSpy.mockRestore();
  });
});
