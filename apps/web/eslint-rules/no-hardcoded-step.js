/**
 * ESLint rule: no-hardcoded-step
 *
 * Phase-11 precision-guard (precision-drift remediation).
 *
 * Flags a hardcoded fractional `step` attribute on a `<input type="number">`
 * JSX element, e.g. `step="0.01"` or `step="0.001"`. Such literals bake a
 * fixed decimal granularity into the markup, which drifts out of sync with
 * the per-currency / per-quantity decimal count (EUR → 0.01, TND → 0.001,
 * quantity → 0.0001). The precision-safe path is the <MoneyInput> /
 * <QuantityInput> atoms, which derive `step` from the currency / decimalPlaces
 * at runtime.
 *
 * WARN level: legacy markup still carries these literals and is being burned
 * down incrementally; the lint-warning ratchet counts them.
 *
 * Intentionally only matches *string-literal* step values with a fractional
 * part (step="0.0\d+" family). It does NOT match `step={expr}` (the atoms use
 * a derived expression) or integer steps like step="1".
 */

const FRACTIONAL_STEP = /^0?\.0*[1-9]\d*$/;

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'suggestion',
    docs: {
      description:
        'Discourage hardcoded fractional step attributes on number inputs; use <MoneyInput>/<QuantityInput> instead.',
    },
    schema: [],
    messages: {
      hardcodedStep:
        'Avoid hardcoded step="{{ step }}" on a number input — it bakes in a fixed decimal granularity that drifts from per-currency/per-quantity precision. Use <MoneyInput> (currency-derived step) or <QuantityInput> (decimalPlaces-derived step) instead.',
    },
  },

  create(context) {
    /**
     * Find a JSX attribute by (lowercased) name on an opening element.
     * @param {import('estree-jsx').JSXOpeningElement} node
     * @param {string} name
     */
    function getAttr(node, name) {
      return node.attributes.find(
        (attr) =>
          attr.type === 'JSXAttribute' &&
          attr.name.type === 'JSXIdentifier' &&
          attr.name.name.toLowerCase() === name,
      );
    }

    /**
     * Read a string-literal attribute value, or null if it is not a plain
     * string literal (e.g. an expression container).
     * @param {import('estree-jsx').JSXAttribute | undefined} attr
     */
    function literalValue(attr) {
      if (!attr || !attr.value) return null;
      // value: "0.01"
      if (attr.value.type === 'Literal' && typeof attr.value.value === 'string') {
        return attr.value.value;
      }
      // value: {"0.01"} — expression container wrapping a string literal
      if (
        attr.value.type === 'JSXExpressionContainer' &&
        attr.value.expression.type === 'Literal' &&
        typeof attr.value.expression.value === 'string'
      ) {
        return attr.value.expression.value;
      }
      return null;
    }

    return {
      JSXOpeningElement(node) {
        // Only consider <input ...> (lowercase native element).
        if (node.name.type !== 'JSXIdentifier' || node.name.name !== 'input') {
          return;
        }

        const typeAttr = getAttr(node, 'type');
        const typeValue = literalValue(typeAttr);
        if (typeValue !== 'number') return;

        const stepAttr = getAttr(node, 'step');
        const stepValue = literalValue(stepAttr);
        if (stepValue === null) return;

        if (FRACTIONAL_STEP.test(stepValue.trim())) {
          context.report({
            node: stepAttr,
            messageId: 'hardcodedStep',
            data: { step: stepValue },
          });
        }
      },
    };
  },
};
