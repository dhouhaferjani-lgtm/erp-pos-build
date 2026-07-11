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
        'Do not compose Tailwind opacity modifiers after token interpolation (`${...}{{ raw }}`). Add the complete class literal to designTokens.ts and reference that token.',
    },
  },

  create(context) {
    return {
      TemplateElement(node) {
        const raw = node.value.raw;
        if (VARIANT_PREFIX_AT_INTERPOLATION.test(raw)) {
          context.report({
            node,
            messageId: 'variantInterpolation',
            data: { raw },
          });
        }

        if (OPACITY_SUFFIX_AFTER_INTERPOLATION.test(raw)) {
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
