/**
 * Go-live audit Finding #6 — stored XSS in the terminal "pending activation"
 * banner. The banner rendered a server-controlled terminal `name` through
 * dangerouslySetInnerHTML with i18next `escapeValue: false`, so a name like
 * `<img src=x onerror=...>` executed in the (CSP-less) Tauri webview.
 *
 * The fix renders the string via <Trans>, which escapes interpolated values
 * and builds <strong> from the component tree — no unsafe HTML sink.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { render } from '@testing-library/react';
import { I18nextProvider, Trans } from 'react-i18next';
import i18n from '@/lib/i18n';

const XSS = '<img src=x onerror="globalThis.__pwned=1"><script>globalThis.__pwned=1</script>';

describe('Finding #6 — terminal name cannot inject executable markup', () => {
  it('renders a malicious terminal name with no live element or event handler', () => {
    const { container } = render(
      <I18nextProvider i18n={i18n}>
        <Trans
          i18nKey="terminal.terminalRequested"
          ns="pos"
          values={{ name: XSS, code: 'POS-1' }}
          components={{ strong: <strong /> }}
        />
      </I18nextProvider>,
    );

    // No live element and no inline handler may survive into the DOM.
    expect(container.querySelector('img')).toBeNull();
    expect(container.querySelector('script')).toBeNull();
    expect(container.innerHTML).not.toMatch(/onerror/i);
    expect((globalThis as Record<string, unknown>).__pwned).toBeUndefined();
  });

  it('the component no longer uses an unsafe HTML sink for the terminal name', () => {
    const src = readFileSync(
      resolve(process.cwd(), 'src/pages/TerminalSetupPage.tsx'),
      'utf8',
    );

    expect(src).not.toContain('dangerouslySetInnerHTML');
    expect(src).not.toContain('escapeValue: false');
  });
});
