/**
 * ESLint rule: no-raw-quantity-input
 *
 * UoM display-precision guard (2026-07-20).
 *
 * Flags a raw `<input inputMode="decimal">` JSX element. A hand-rolled decimal
 * input bypasses the <QuantityInput> atom, which derives its step/precision from
 * the product unit (units.decimal_places) and keeps quantities as decimal
 * strings. Route quantity entry through <QuantityInput> instead.
 *
 * WARN on the legacy surface (ratcheted by scripts/lint-ratchet.mjs), promoted
 * to ERROR for the precision-cleaned files in the strict override block. The
 * production surface is clean today, so this is a pure ratchet that blocks
 * re-introduction.
 *
 * Allowlist: the atoms that legitimately own the raw <input>
 * (MoneyInput/QuantityInput), plus surfaces whose decimal input is money or a
 * non-quantity decimal (ShiftClosurePage cash counting, CustomerAttachPanel).
 * Add a file here only with a justification comment.
 *
 * Intentionally only matches a string-literal `inputMode="decimal"` on a
 * lowercase native `<input>`. It does NOT match custom components or a derived
 * inputMode expression.
 */

const ALLOWLIST = [
  'components/atoms/MoneyInput.tsx',
  'components/atoms/QuantityInput.tsx',
  'pages/ShiftClosurePage.tsx', // cash counting (money)
  'components/customers/CustomerAttachPanel.tsx', // non-quantity decimal
];

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Forbid raw <input inputMode="decimal"> quantity inputs; use the <QuantityInput> atom so precision derives from the product unit.',
    },
    schema: [],
    messages: {
      rawQuantityInput:
        'Raw <input inputMode="decimal"> bypasses <QuantityInput> (which derives precision from the product unit). Use <QuantityInput>, or add this file to the rule allowlist with a justification if the value is money / a non-quantity decimal.',
    },
  },

  create(context) {
    const filename = context.getFilename().replaceAll('\\', '/');
    if (ALLOWLIST.some((f) => filename.endsWith(f))) return {};
    return {
      JSXOpeningElement(node) {
        if (node.name.type !== 'JSXIdentifier' || node.name.name !== 'input') return;
        const im = node.attributes.find(
          (a) => a.type === 'JSXAttribute' && a.name.name === 'inputMode',
        );
        if (im?.value?.type === 'Literal' && im.value.value === 'decimal') {
          context.report({ node, messageId: 'rawQuantityInput' });
        }
      },
    };
  },
};
