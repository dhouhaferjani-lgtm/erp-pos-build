import { describe, it, expect } from 'vitest';
import i18n from '@/lib/i18n';

/**
 * GATE r1 addendum (B-3 lane finding) — VERIFIED STRUCTURALLY, CONSEQUENCE
 * PARTLY REFUTED. Read this before trusting the original report.
 *
 * TRUE: the `pos` namespace's AR entry is built by SPREADING `arPos` over
 * `enPos`, which is SHALLOW. `arPos.zReports` (4 keys) wholesale-replaces
 * `enPos.zReports` (30 keys) in the AR bundle, and one level deeper
 * `arPos.zReports.detail` (5 keys) replaces `enPos.zReports.detail` (25 keys).
 *
 * NOT TRUE: that AR therefore "renders raw keys for 27 entries". `i18n.ts` sets
 * `fallbackLng: 'en'`, and i18next resolves fallback PER KEY at lookup time —
 * so a key absent from the AR bundle is served from the English one, which is
 * exactly what a deep merge would produce. The first two cases below pass
 * BEFORE the merge as well as after; they are here to pin that fallback, since
 * it is the thing actually protecting these screens.
 *
 * The deep merge is still worth having: it is parity with the `transactions`
 * line beside it, and it becomes load-bearing the moment any caller reads the
 * object wholesale with `returnObjects: true` (a pattern this codebase already
 * uses at `RegisterBrandPanel.tsx:10`), where per-key fallback does not apply.
 * The last case is the one that discriminates.
 */
describe('i18n pos.zReports AR deep-merge (shallow-shadow trap)', () => {
  it('serves English for zReports keys AR has not translated', async () => {
    await i18n.changeLanguage('ar');

    // Untranslated in AR — must fall through to the English value, not the key.
    expect(i18n.t('pos:zReports.title')).not.toBe('zReports.title');
    expect(i18n.t('pos:zReports.verifyChain')).not.toBe('zReports.verifyChain');
    expect(i18n.t('pos:zReports.detailTitle')).not.toBe('zReports.detailTitle');
  });

  it('serves English for nested zReports.detail keys AR has not translated', async () => {
    await i18n.changeLanguage('ar');

    expect(i18n.t('pos:zReports.detail.taxAmount')).not.toBe('zReports.detail.taxAmount');
    expect(i18n.t('pos:zReports.detail.salesSummary')).not.toBe('zReports.detail.salesSummary');
    expect(i18n.t('pos:zReports.detail.refundsCount')).not.toBe('zReports.detail.refundsCount');
  });

  it('still prefers the AR translation where one exists', async () => {
    await i18n.changeLanguage('ar');

    // Translated in AR at both levels — the merge must not lose these.
    expect(i18n.t('pos:zReports.terminalColumn')).toBe('المحطة');
    expect(i18n.t('pos:zReports.detail.netVat')).toBe('صافي ضريبة القيمة المضافة');
  });

  /**
   * The discriminating case: a wholesale object read bypasses per-key fallback,
   * so the shadow DOES bite here. Fails on a shallow spread, passes on the merge.
   */
  it('exposes the full English key set when the object is read wholesale', async () => {
    await i18n.changeLanguage('ar');

    const zReports = i18n.t('pos:zReports', { returnObjects: true }) as Record<string, unknown>;

    // Present only in English — a shallow spread drops them from the AR bundle.
    expect(zReports).toHaveProperty('verifyChain');
    expect(zReports).toHaveProperty('detailTitle');

    const detail = zReports['detail'] as Record<string, unknown>;
    expect(detail).toHaveProperty('taxAmount');
    expect(detail).toHaveProperty('salesSummary');
    // …without losing the AR values that do exist.
    expect(detail['netVat']).toBe('صافي ضريبة القيمة المضافة');
  });
});
