/**
 * ESLint rule: no-untranslated-literal
 *
 * i18n guard (EN/FR translation sweep, 2026-06).
 *
 * Flags user-facing string literals that bypass react-i18next `t()`:
 *
 *   - JSX text nodes that contain a translatable word, e.g.
 *       <button>Save changes</button>           ← flagged
 *       <span>{t('common:save')}</span>          ← ok (no literal text)
 *       <span>{total} €</span>                   ← ok ("€" has no letters)
 *
 *   - String-literal values of a small set of user-facing attributes
 *     (placeholder / title / alt / aria-label / label), e.g.
 *       <input placeholder="Search products" />  ← flagged
 *       <input placeholder={t('...')} />          ← ok (expression, not literal)
 *
 * WARN level on the legacy surface: the codebase carries ~1k untranslated
 * literals being burned down cluster-by-cluster; the lint-warning ratchet
 * (scripts/lint-ratchet.mjs) counts them and forbids regression. Cleaned
 * feature dirs re-enable this as ERROR via an override block in
 * eslint.config.js so they can never backslide.
 *
 * Escape hatch for a genuinely non-translatable literal (brand name, code,
 * symbol the heuristic misses):
 *   {/* eslint-disable-next-line local/no-untranslated-literal *​/}
 *
 * Deliberately conservative — only plain JSX text and the five attributes
 * above. String literals inside `{...}` expression containers are NOT flagged
 * (too many false positives from className/key/testid expressions).
 */

const USER_FACING_ATTRS = new Set(['placeholder', 'title', 'alt', 'aria-label', 'label']);

// Skip JSX text inside these elements — their content is not display copy.
const NON_DISPLAY_PARENTS = new Set(['script', 'style', 'code', 'pre']);

/**
 * Is `raw` a translatable user-facing string (vs a code token / symbol)?
 * @param {string} raw
 */
function isTranslatable(raw) {
  const s = raw.trim();
  if (!s) return false;
  // Must contain a run of 2+ letters (incl. accented). Pure numbers, currency
  // symbols, punctuation, single chars → not translatable.
  if (!/[A-Za-zÀ-ÿ]{2,}/.test(s)) return false;
  // Single token (no whitespace) that looks like code rather than a word.
  if (!/\s/.test(s)) {
    if (/[._/]/.test(s)) return false; // path / i18n key / url fragment
    if (/^[a-z][a-zA-Z0-9]*$/.test(s) && /[A-Z]/.test(s)) return false; // camelCase identifier
    if (/^[a-z0-9]+(-[a-z0-9]+)+$/.test(s)) return false; // kebab-case (className / testid)
    if (/^[A-Z0-9_]+$/.test(s) && s.length <= 4) return false; // short ACRONYM / CONST
  }
  return true;
}

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'suggestion',
    docs: {
      description:
        'Flag user-facing string literals (JSX text and user-facing attributes) that bypass react-i18next t().',
    },
    schema: [],
    messages: {
      jsxText:
        'Untranslated user-facing text "{{ text }}". Wrap it in a translation key: {t(\'namespace:key\')}.',
      attr:
        'Untranslated {{ attr }}="{{ text }}". Use a translation key: {{ attr }}={t(\'namespace:key\')}.',
    },
  },

  create(context) {
    // Skip test/story files — their JSX string literals are render assertions
    // and fixtures, not user-facing copy.
    const filename = context.filename ?? context.getFilename();
    if (/(\.test\.|\.spec\.|\.stories\.|__tests__|\/test\/)/.test(filename)) {
      return {};
    }
    return {
      JSXText(node) {
        const parent = node.parent;
        if (
          parent &&
          parent.type === 'JSXElement' &&
          parent.openingElement.name.type === 'JSXIdentifier' &&
          NON_DISPLAY_PARENTS.has(parent.openingElement.name.name)
        ) {
          return;
        }
        if (!isTranslatable(node.value)) return;
        context.report({
          node,
          messageId: 'jsxText',
          data: { text: node.value.trim().replace(/\s+/g, ' ').slice(0, 40) },
        });
      },

      JSXAttribute(node) {
        if (node.name.type !== 'JSXIdentifier') return;
        const attr = node.name.name.toLowerCase();
        if (!USER_FACING_ATTRS.has(attr)) return;
        const value = node.value;
        if (!value || value.type !== 'Literal' || typeof value.value !== 'string') return;
        if (!isTranslatable(value.value)) return;
        context.report({
          node,
          messageId: 'attr',
          data: { attr, text: value.value.trim().slice(0, 40) },
        });
      },
    };
  },
};
