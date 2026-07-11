/**
 * ESLint rule: no-dead-tailwind-token-interpolation
 *
 * Tailwind only emits variant/opacity utilities when the full class literal is
 * present in scanned source. Template pieces such as `hover:${token}` and
 * `${token}/75` hide the complete class from Tailwind and produce dead CSS.
 */

const VARIANT_PREFIX_AT_INTERPOLATION =
  /(?:hover|focus|focus-within|focus-visible|group-hover|disabled|placeholder|active|dark|file):$/;
const OPACITY_SUFFIX_AFTER_INTERPOLATION = /^\/\d/;

/** @type {import('eslint').Rule.RuleModule} */
export default {
  meta: {
    type: 'problem',
    docs: {
      description:
        'Disallow Tailwind variant prefixes or opacity suffixes glued to token interpolations.',
    },
    schema: [],
    messages: {
      variantInterpolation:
        'Do not compose Tailwind variants with token interpolation (`{{ raw }}${...}`). Add the complete class literal to designTokens.ts and reference that token.',
      opacityInterpolation:
        'Do not glue a Tailwind opacity modifier after a token interpolation (`${...}{{ raw }}`) — Tailwind never sees the full class and emits dead CSS. Compose the complete class with the opacity INSIDE the token value in designTokens.ts and reference that token.',
    },
  },

  create(context) {
    return {
      TemplateElement(node) {
        const raw = node.value.raw;

        // BL-1: a variant prefix glued to the END of a quasi that is immediately
        // followed by `${...}` (e.g. `hover:${token}`).
        if (VARIANT_PREFIX_AT_INTERPOLATION.test(raw)) {
          context.report({
            node,
            messageId: 'variantInterpolation',
            data: { raw },
          });
        }

        // BL-2: an opacity modifier glued to the START of a quasi that immediately
        // follows a `${...}` expression (e.g. `${token}/50`). Only quasis after an
        // interpolation qualify — the leading quasi is never preceded by an
        // expression, so a template that merely starts with `/50` is not a hit.
        const parent = node.parent;
        const isLeadingQuasi =
          parent && parent.type === 'TemplateLiteral' && parent.quasis[0] === node;
        if (!isLeadingQuasi && OPACITY_SUFFIX_AFTER_INTERPOLATION.test(raw)) {
          context.report({
            node,
            messageId: 'opacityInterpolation',
            data: { raw },
          });
        }
      },
    };
  },
};
