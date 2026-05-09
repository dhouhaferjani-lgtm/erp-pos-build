import { useEffect, useRef, type RefObject } from 'react';

/**
 * Focus trap for POS modals. Closes the T2.1 deferral noted in PR #94 —
 * the gap was pre-existing across every modal in the app, not specific
 * to BarcodeChooserModal. This hook is the shared primitive; the base
 * `Modal` component plus the standalone chooser consume it.
 *
 * Behaviour when `isActive` is true:
 *   1. Save the element that had focus when the modal opened.
 *   2. Focus the first focusable descendant of `containerRef.current`.
 *   3. Intercept Tab / Shift+Tab keydowns at the document level (capture
 *      phase) and cycle focus within the container's focusable set —
 *      Tab from the last focusable wraps to the first; Shift+Tab from
 *      the first wraps to the last.
 *
 * Behaviour when `isActive` flips to false (or the hook unmounts):
 *   - Restore focus to the saved element.
 *   - Detach the keydown listener.
 *
 * Out of scope: arrow-key navigation, custom roving tabindex, screen-
 * reader heuristics. Components that need richer keyboard semantics
 * layer their own behaviour on top.
 */
export interface UseFocusTrapOptions {
  isActive: boolean;
  containerRef: RefObject<HTMLElement | null>;
}

const FOCUSABLE_SELECTOR = [
  'a[href]',
  'button:not([disabled])',
  'input:not([disabled]):not([type="hidden"])',
  'select:not([disabled])',
  'textarea:not([disabled])',
  '[tabindex]:not([tabindex="-1"])',
].join(',');

function getFocusable(container: HTMLElement): HTMLElement[] {
  return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR)).filter(
    (el) => !el.hasAttribute('disabled') && el.tabIndex !== -1,
  );
}

export function useFocusTrap({ isActive, containerRef }: UseFocusTrapOptions): void {
  // Codex round-2 P2 — capture the opener during the render phase, BEFORE
  // React commits child autoFocus. By the time the post-commit useEffect
  // below runs, an autoFocus input has already pulled focus inside the
  // container and the opener identity is unrecoverable. During render,
  // however, the children haven't been committed yet, so
  // `document.activeElement` is still the button that triggered the
  // modal open.
  //
  // Strict-Mode safe: we only capture when `previouslyFocusedRef.current`
  // is null AND isActive is true. The double-render cycle in dev
  // therefore sets the ref once and leaves it alone on the second pass.
  // The cleanup branch resets the ref so the next activation captures
  // fresh.
  const previouslyFocusedRef = useRef<HTMLElement | null>(null);

  if (isActive && previouslyFocusedRef.current === null) {
    const ae = document.activeElement;
    if (ae instanceof HTMLElement && !(containerRef.current?.contains(ae))) {
      previouslyFocusedRef.current = ae;
    }
  }

  useEffect(() => {
    if (!isActive) return;

    const container = containerRef.current;
    if (!container) return;

    // Codex round-1 P2 — preserve `autoFocus` targets. CashTenderedModal,
    // VoucherTenderModal, and any other child input that uses
    // `autoFocus` will already have focus inside the container by the
    // time this effect runs. Overriding to the first focusable would
    // move focus to the header close button and force the cashier to
    // tab/click before typing or scanning. Only seed initial focus when
    // nothing inside the container currently has it.
    const activeAtMount = document.activeElement instanceof HTMLElement
      ? document.activeElement
      : null;
    const alreadyInsideContainer = activeAtMount !== null
      && container.contains(activeAtMount);

    if (!alreadyInsideContainer) {
      const focusables = getFocusable(container);
      if (focusables.length > 0) {
        focusables[0]!.focus();
      }
    }

    function handleKeyDown(event: KeyboardEvent): void {
      if (event.key !== 'Tab') return;
      if (!container) return;

      const current = getFocusable(container);
      if (current.length === 0) return;

      const first = current[0]!;
      const last = current[current.length - 1]!;
      const active = document.activeElement;

      if (event.shiftKey) {
        if (active === first || !container.contains(active)) {
          event.preventDefault();
          last.focus();
        }
      } else if (active === last || !container.contains(active)) {
        event.preventDefault();
        first.focus();
      }
    }

    document.addEventListener('keydown', handleKeyDown, true);

    return () => {
      document.removeEventListener('keydown', handleKeyDown, true);
      const opener = previouslyFocusedRef.current;
      if (opener && document.contains(opener)) {
        opener.focus();
      }
      // Reset so the next activation captures fresh.
      previouslyFocusedRef.current = null;
    };
  }, [isActive, containerRef]);
}
