/**
 * ESLint rule: no-literal-decimal-places
 *
 * UoM display-precision guard (2026-07-20).
 *
 * Flags a hardcoded numeric `decimalPlaces` literal on a `<QuantityInput>`
 * atom inside the product-quantity feature surfaces, e.g.
 * `<QuantityInput decimalPlaces={4} />`. A baked-in literal drifts out of sync
 * with the product unit's precision (units.decimal_places — pieces → 0,
 * weight → 3, …). The precision-safe path derives the value at runtime from the
 * product: `decimalPlaces={getQuantityDecimals(line.product)}`.
 *
 * WARN level: matches the precision-guard ratchet — new drift is caught while
 * documented standing-field exceptions carry an inline disable with rationale.
 *
 * Intentionally only matches a *numeric literal* value wrapped in a JSX
 * expression container (`decimalPlaces={4}`). It does NOT match a derived
 * expression (`decimalPlaces={getQuantityDecimals(...)}`), which is the
 * correct pattern.
 *
 * Scope: only the feature dirs in INCLUDED_DIRS render human-facing product
 * quantities. ProductInventorySection is deliberately EXCLUDED — its opening_qty
 * literal needs unit-selection-aware plumbing (ticketed follow-up).
 */

const INCLUDED_DIRS = [
  'features/replenishment',
  'features/purchases',
  'features/documents',
  'features/inventory',
  'features/stock-transfers',
  'features/batches',
  // ProductInventorySection deliberately EXCLUDED: its opening_qty literal
  // needs unit-selection-aware plumbing — 🎫 ticketed follow-up, not this feature.
];

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'suggestion',
    docs: {
      description:
        'Discourage hardcoded numeric decimalPlaces literals on <QuantityInput> in product-quantity surfaces; derive from the product unit (getQuantityDecimals).',
    },
    schema: [],
    messages: {
      literalDecimalPlaces:
        'Avoid a hardcoded decimalPlaces literal on <QuantityInput> — it bakes in a fixed precision that drifts from the product unit (units.decimal_places). Derive it, e.g. decimalPlaces={getQuantityDecimals(line.product)}. If a scale-4 standing field is intentional, add an eslint-disable-next-line with rationale.',
    },
  },

  create(context) {
    const filename = context.getFilename().replaceAll('\\', '/');
    // Trailing '/' anchors the dir boundary so `features/inventory` does NOT
    // also match the unrelated `features/inventory-counting` feature.
    if (!INCLUDED_DIRS.some((d) => filename.includes(`${d}/`))) return {};
    return {
      JSXOpeningElement(node) {
        if (node.name.type !== 'JSXIdentifier' || node.name.name !== 'QuantityInput') return;
        for (const attr of node.attributes) {
          if (attr.type !== 'JSXAttribute' || attr.name.name !== 'decimalPlaces') continue;
          const v = attr.value;
          if (
            v?.type === 'JSXExpressionContainer' &&
            v.expression.type === 'Literal' &&
            typeof v.expression.value === 'number'
          ) {
            context.report({ node: attr, messageId: 'literalDecimalPlaces' });
          }
        }
      },
    };
  },
};
