/**
 * ESLint rule: no-parsefloat-on-money
 *
 * Phase-11 precision-guard (precision-drift remediation).
 *
 * Flags `parseFloat(x)` / `Number(x)` calls where the argument is an
 * identifier (or member expression) whose name suggests a monetary or
 * quantity value — amount, price, cost, total, quantity, qty, balance,
 * subtotal, discount, rate, sum. Coercing such canonical decimal strings into
 * IEEE-754 floats reintroduces the precision drift this workstream exists to
 * eliminate. The precision-safe path keeps the canonical string and uses the
 * decimal helpers (lib/decimal, big.js) for arithmetic.
 *
 * WARN level: legacy callsites still do this and are being burned down
 * incrementally; the lint-warning ratchet counts them.
 */

const MONEY_NAME =
  /(amount|price|cost|total|quantity|qty|balance|subtotal|discount|rate|sum)/i;

/**
 * Extract a comparable "name" from a call argument node:
 *  - identifier:      amount            -> "amount"
 *  - member access:   line.unitPrice    -> "unitPrice"
 *                     row.qty           -> "qty"
 * Returns null for anything else (literals, calls, etc.).
 * @param {import('estree').Node | undefined} arg
 */
function argName(arg) {
  if (!arg) return null;
  if (arg.type === 'Identifier') return arg.name;
  if (arg.type === 'MemberExpression') {
    const prop = arg.property;
    if (!arg.computed && prop.type === 'Identifier') return prop.name;
    if (arg.computed && prop.type === 'Literal' && typeof prop.value === 'string') {
      return prop.value;
    }
  }
  return null;
}

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'suggestion',
    docs: {
      description:
        'Discourage parseFloat()/Number() coercion of monetary or quantity-named values; keep the canonical decimal string and use decimal helpers.',
    },
    schema: [],
    messages: {
      floatCoercion:
        'Avoid {{ callee }}({{ arg }}) on a monetary/quantity value — float coercion reintroduces precision drift. Keep the canonical decimal string and use the decimal helpers (lib/decimal / big.js).',
    },
  },

  create(context) {
    return {
      CallExpression(node) {
        const callee = node.callee;
        if (callee.type !== 'Identifier') return;
        if (callee.name !== 'parseFloat' && callee.name !== 'Number') return;

        const firstArg = node.arguments[0];
        const name = argName(firstArg);
        if (name === null) return;

        if (MONEY_NAME.test(name)) {
          context.report({
            node,
            messageId: 'floatCoercion',
            data: { callee: callee.name, arg: name },
          });
        }
      },
    };
  },
};
