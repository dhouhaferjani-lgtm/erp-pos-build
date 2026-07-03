/**
 * ESLint rule: no-hardcoded-entity-route
 *
 * Flags hardcoded entity-detail route literals in JSX <Link to={...}> props.
 * Entity detail paths belong in src/lib/entityRoutes.ts so route fixes are
 * centralized and testable.
 */

const ENTITY_DETAIL_PREFIXES = [
  '/inventory/products/',
  '/sales/customers/',
  '/purchases/suppliers/',
  '/sales/quotes/',
  '/sales/orders/',
  '/sales/invoices/',
  '/sales/credit-notes/',
  '/sales/return-notes/',
  '/inventory/return-notes/',
  '/purchases/orders/',
  '/inventory/delivery-notes/',
  '/purchases/supplier-invoices/',
  '/treasury/payments/',
  '/expenses/',
  '/inventory/stock-transfers/',
  '/inventory/batches/',
  '/finance/journal-entries/',
];

const ALLOWED_SUFFIXES = new Set(['new', 'create']);

/**
 * @param {string} value
 */
function hardcodedEntityPrefix(value) {
  return ENTITY_DETAIL_PREFIXES.find((prefix) => {
    if (!value.startsWith(prefix)) return false;
    const suffix = value.slice(prefix.length).split(/[/?#]/)[0];
    return suffix.length === 0 || !ALLOWED_SUFFIXES.has(suffix);
  }) ?? null;
}

/**
 * @param {import('estree-jsx').JSXAttribute} node
 */
function jsxAttributeName(node) {
  return node.name.type === 'JSXIdentifier' ? node.name.name : null;
}

/**
 * @param {import('estree-jsx').JSXAttribute} node
 */
function jsxElementName(node) {
  const parent = node.parent;
  if (!parent || parent.type !== 'JSXOpeningElement') return null;
  return parent.name.type === 'JSXIdentifier' ? parent.name.name : null;
}

/**
 * @param {import('estree').Node | import('estree-jsx').JSXExpression | null | undefined} expression
 */
function routeLiteralFromExpression(expression) {
  if (!expression) return null;

  if (expression.type === 'Literal' && typeof expression.value === 'string') {
    return expression.value;
  }

  if (expression.type === 'TemplateLiteral') {
    return expression.quasis[0]?.value.cooked ?? null;
  }

  if (expression.type === 'BinaryExpression' && expression.operator === '+') {
    return routeLiteralFromExpression(expression.left);
  }

  return null;
}

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'suggestion',
    docs: {
      description:
        'Warn when JSX Link detail routes are hardcoded instead of using entityRoutes/EntityLink.',
    },
    schema: [],
    messages: {
      hardcodedEntityRoute:
        'Avoid hardcoded entity detail route "{{ prefix }}" in Link to props. Use entityRoutes or EntityLink so route conventions stay centralized.',
    },
  },

  create(context) {
    const filename = context.filename ?? context.getFilename();
    if (filename.endsWith('/src/lib/entityRoutes.ts')) {
      return {};
    }

    return {
      JSXAttribute(node) {
        if (jsxAttributeName(node) !== 'to') return;
        if (jsxElementName(node) !== 'Link' && jsxElementName(node) !== 'NavLink') return;
        if (!node.value) return;

        const literal =
          node.value.type === 'Literal' && typeof node.value.value === 'string'
            ? node.value.value
            : node.value.type === 'JSXExpressionContainer'
              ? routeLiteralFromExpression(node.value.expression)
              : null;

        if (literal === null) return;

        const prefix = hardcodedEntityPrefix(literal);
        if (prefix === null) return;

        context.report({
          node,
          messageId: 'hardcodedEntityRoute',
          data: { prefix },
        });
      },
    };
  },
};
