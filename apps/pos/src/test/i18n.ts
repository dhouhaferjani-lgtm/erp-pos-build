// Minimal i18next instance for use in unit tests that need a real I18nextProvider.
// Prefer vi.mock('react-i18next', ...) for simple component tests; use this only when
// the test explicitly wraps with <I18nextProvider>.
import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

if (!i18n.isInitialized) {
  void i18n.use(initReactI18next).init({
    resources: {
      en: {
        pos: {},
        common: {},
      },
    },
    lng: 'en',
    fallbackLng: 'en',
    ns: ['pos', 'common'],
    defaultNS: 'pos',
    interpolation: { escapeValue: false },
  });
}

export default i18n;
