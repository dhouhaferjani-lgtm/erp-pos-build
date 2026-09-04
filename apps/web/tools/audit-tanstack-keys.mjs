#!/usr/bin/env node
// @ts-check
/**
 * Architecture Gate C: TanStack Query keys must include a tenant scope.
 *
 * Code is the source of truth. This scanner walks every TS/TSX file under
 * apps/web/src/, parses it with the TypeScript compiler API, and flags any
 * `useQuery({ ... })` / `useQueries({ queries: [{ ... }] })` /
 * queryClient.invalidateQueries({ ... }) (and friends) call whose
 * `queryKey` does not include an approved tenant scope.
 *
 * Default-deny per Codex round-1 review T2 + N5: any unknown queryKey
 * factory triggers a flag. Approved scopes:
 *   - `tenantScopedKey([...])` helper call.
 *   - Bare identifier `tenantId`, `currentCompanyId`, or `companyId`.
 *   - Member access `companyStore.<currentCompanyId|companyId|tenantId>`
 *     (Codex 2026-05-03 review C4: tightened from any property access ending
 *     in those names).
 *   - Array starting with the literal string `'admin'` or `'super-admin'`
 *     for the super-admin namespace.
 *
 * Gate mode uses the baseline below only to keep today's known offenders
 * visible while failing on any NEW unscoped query key. Do not add entries for
 * new code; remove entries when the underlying query key is fixed.
 *
 * Codex 2026-05-03 review C4 fixes vs the prior version:
 *   - useQueries({ queries: [...] }) now descends into the queries array
 *     and audits each element's queryKey individually.
 *   - companyStore.<x> member access requires the explicit `companyStore`
 *     LHS; bare property access ending in `companyId` etc. no longer
 *     over-approves.
 *
 * ---------------------------------------------------------------------------
 * Rule 2 — `placeholderData` pairing (Gate C, hardened 2026-09-04,
 * gate fix round 1 2026-09-04)
 * ---------------------------------------------------------------------------
 * A read that carries `placeholderData` on a TENANT-SCOPED key must be paired
 * with a `usePlaceholderScopeGuard(...)` call at the SAME call site. Exactly:
 *
 *   TRIGGER — all three must hold:
 *     a. the factory is a read that can serve a placeholder:
 *        {@link PLACEHOLDER_BEARING_FACTORIES} (useQuery, useInfiniteQuery,
 *        useSuspenseQuery, and each `useQueries({ queries: [...] })` entry);
 *     b. the options object declares `placeholderData` (any value —
 *        `keepPreviousData` or an inline `(prev) => prev`) — inline, OR
 *        through a SPREAD whose payload resolves, inside this file, to an
 *        object that declares it (see {@link spreadPayloadPlaceholderVerdict});
 *     c. the `queryKey` CARRIES A TENANT SCOPE, i.e. it is a
 *        {@link APPROVED_FACTORY_CALLS} call (`tenantScopedKey(...)` /
 *        `locationScopedKey(...)`) OR an array literal containing an approved
 *        scope expression ({@link APPROVED_SCOPE_IDENTIFIERS} bare identifier,
 *        or `companyStore.<id>`). The `'admin'`/`'super-admin'` namespace
 *        prefix is NOT a tenant scope and does not trigger the rule.
 *
 *   PAIRING (what clears the trigger) — a real CALL EXPRESSION of the guard
 *   (its canonical name or a named-import ALIAS of it,
 *   {@link guardLocalNames}), not a mention of the name:
 *     1. the read's result must be BOUND — by a `const`/`let` declaration or by
 *        an assignment to an identifier (`let r; r = useQuery(…)`):
 *        `const { …, isPlaceholderData } = …` (renames honoured:
 *        `isPlaceholderData: isFooPending`), or `const r = …` (whole result),
 *        or, for `useQueries`, the array-destructured element AT THIS ENTRY'S
 *        INDEX. An unbound read can never be paired and is always reported.
 *     2. somewhere inside the read's nearest enclosing function (the component
 *        or hook body — nested callbacks and render/`enabled` gates included;
 *        the whole file when the read is at module scope) there must be a guard
 *        call ONE OF WHOSE ARGUMENTS references one of those bound names.
 *     2b. PER ENTRY for an identifier-bound `useQueries`
 *        (`const results = useQueries({ queries: [A, B] })`): the guard argument
 *        must contain an element access AT THIS ENTRY'S INDEX — `results[1]` or
 *        `results.at(1)`. The bare array handle does not pair, because
 *        accepting it let one `usePlaceholderScopeGuard(results[0]…)` clear the
 *        unguarded sibling entry (gate fix round 1, MAJOR-1).
 *
 *   Consequences, each pinned by a fixture in
 *   `tools/__fixtures__/audit-tanstack-keys/placeholder-pairing/`:
 *     - a comment, string or dead import naming the guard does NOT pair
 *       (the pre-hardening check was `sourceFile.text.includes(...)`);
 *     - two scoped placeholder reads in one file with one guard yields exactly
 *       one finding — pairing is per call site, not per file;
 *     - a guard wired to a DIFFERENT read in the same function does not clear
 *       this one, because the name linkage fails;
 *     - inside ONE identifier-bound `useQueries`, a guard on `results[0]` does
 *       not clear entry 1 — pairing is per ENTRY, not per call;
 *     - `placeholderData` arriving through `...localOptions` is reported like an
 *       inline one.
 *
 *   KNOWN LIMITATIONS (deliberate, all fail in the SAFE direction except where
 *   noted; each is pinned by a test in
 *   `tools/__tests__/audit-tanstack-keys.test.mjs`):
 *     - a read inside a CUSTOM HOOK must be guarded INSIDE that hook. The
 *       scanner cannot follow `isPlaceholderData` across a module boundary, so
 *       a consumer-side guard is not seen and the hook is reported. Fails
 *       CLOSED: return blanked rows, or the verdict, from the hook.
 *     - a spread payload that cannot be resolved in this file — a
 *       caller-supplied `options` parameter, an imported options object — is
 *       NOT reported (fails OPEN). Declare `placeholderData` inline, or guard
 *       inside the hook, when a pass-through hook may receive it. Open sites on
 *       `dev` at the time of writing: `src/features/catalog/api/queries.ts`
 *       `useCategories` / `useCategoryTree` / `useCategory` (gate fix round 1,
 *       MAJOR-2 residual — closing it needs a `src/` change, out of this lane's
 *       scope).
 *     - a guard CALL whose verdict is DISCARDED still pairs (fails OPEN): the
 *       scanner checks the linkage, not the consumption. Reviewer duty: the
 *       verdict must actually blank the rows and every derived value.
 *     - a same-named LOCAL helper does not pair — only the canonical guard name
 *       or a named-import alias of it counts.
 *
 * Run via: pnpm audit:keys (also chained from lint/preflight/CI).
 * Use --json only for inventory generation; JSON mode emits raw findings and
 * preserves the historical exit-0 behavior for scanner consumers.
 */

import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import ts from 'typescript';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEB_ROOT = path.resolve(__dirname, '..');
const SRC_ROOT = path.join(WEB_ROOT, 'src');

const QUERY_FACTORY_NAMES = new Set([
  'useQuery',
  'useMutation',
  'useInfiniteQuery',
  'useSuspenseQuery',
  'invalidateQueries',
  'removeQueries',
  'resetQueries',
  'refetchQueries',
  'cancelQueries',
  'fetchQuery',
  'prefetchQuery',
]);

/**
 * queryClient cache-FILTER methods. Their `queryKey` option is a match
 * filter, not a storage key: React Query compares filter keys as positional
 * PREFIXES of stored query keys. Since `tenantScopedKey([...])` appends
 * tenant/company as SUFFIXES, wrapping a filter in it is a proven no-op for
 * every namespace-prefix intent (it only matches when it happens to equal a
 * FULL stored key) — memory: project_tanstack_invalidation_suffix_noop,
 * fixed repo-wide by the 2026-07 chore/tanstack-invalidation-sweep.
 * Filters must use bare literal prefixes (e.g. `['stock-transfers']`);
 * bare array literals are therefore APPROVED for these factories, and
 * `tenantScopedKey(...)` filters are FLAGGED.
 */
const CACHE_FILTER_FACTORIES = new Set([
  'invalidateQueries',
  'removeQueries',
  'resetQueries',
  'refetchQueries',
  'cancelQueries',
]);

const APPROVED_SCOPE_IDENTIFIERS = new Set([
  'tenantId',
  'currentCompanyId',
  'companyId',
]);

const APPROVED_NAMESPACE_PREFIXES = new Set([
  'admin',
  'super-admin',
]);

const APPROVED_STORE_OBJECTS = new Set([
  'companyStore',
]);

const APPROVED_FACTORY_CALLS = new Set([
  'tenantScopedKey',
  'locationScopedKey',
]);

/**
 * Read factories whose `placeholderData` survives a key change.
 *
 * TanStack v5 picks the placeholder from the OBSERVER's last query that had
 * data, with no key-lineage check (`queryObserver.js` #lastQueryWithDefinedData),
 * so `placeholderData: keepPreviousData` on a key built by
 * {@link APPROVED_FACTORY_CALLS} hands the PREVIOUS company's payload back across
 * the tenant/company suffix — and a company switch only invalidates the cache,
 * it never unmounts the page. The operator then reads, links into and acts on
 * another company's records for the whole in-flight window.
 *
 * It stays legitimate WITHIN one scope (paging 1 -> 2 must not flash an empty
 * table), so this is a PAIRING rule, not a ban: the reader must gate its rows on
 * `usePlaceholderScopeGuard`, which blanks them when the placeholder predates a
 * scope change. A lane that legitimately has no same-scope win should drop
 * `placeholderData` instead (see `features/pos/hooks/useDiscountPreview.ts`).
 *
 * See {@link readHasPairedScopeGuard} for the exact, per-call-site pairing rule.
 */
const PLACEHOLDER_BEARING_FACTORIES = new Set([
  'useQuery',
  'useInfiniteQuery',
  'useSuspenseQuery',
  // A `useQueries({ queries: [...] })` entry is a read like any other and its
  // options object reaches checkOptionsObject under this synthetic factory
  // name. Gate-C-hardening lane (independent gate 2026-09-04, MAJOR-A class 2):
  // its absence here silently exempted every multi-read page.
  'useQueries.queries[]',
]);

/** The guard that makes `placeholderData` safe on a scoped key. */
const PLACEHOLDER_SCOPE_GUARD = 'usePlaceholderScopeGuard';

const BASELINED_VIOLATION_KEYS = new Set([]);

/**
 * @param {{file: string, factory?: string, enclosing_symbol?: string | null, statement_fingerprint?: string}} violation
 * @returns {string}
 */
export function violationBaselineKey(violation) {
  return [
    violation.file,
    violation.factory ?? '<unknown-factory>',
    violation.enclosing_symbol ?? '<top-level>',
    violation.statement_fingerprint ?? '<unknown-fingerprint>',
  ].join('|');
}

/**
 * @param {Array<{file: string, line: number, column: number, reason: string, factory?: string, enclosing_symbol?: string | null, resource?: string | null, statement_fingerprint?: string, ast_kind?: string}>} violations
 * @param {Set<string>} [baseline]
 * @returns {{baselined: typeof violations, newViolations: typeof violations, staleBaselineEntries: string[]}}
 */
export function partitionViolationsByBaseline(violations, baseline = BASELINED_VIOLATION_KEYS) {
  const seen = new Set();
  const baselined = [];
  const newViolations = [];

  for (const violation of violations) {
    const key = violationBaselineKey(violation);
    if (baseline.has(key)) {
      seen.add(key);
      baselined.push(violation);
    } else {
      newViolations.push(violation);
    }
  }

  const staleBaselineEntries = [...baseline].filter((entry) => !seen.has(entry));
  return { baselined, newViolations, staleBaselineEntries };
}

/**
 * @param {ts.Node} expr
 * @returns {boolean}
 */
function isApprovedScopeExpression(expr) {
  if (ts.isCallExpression(expr) && ts.isIdentifier(expr.expression)) {
    if (APPROVED_FACTORY_CALLS.has(expr.expression.text)) return true;
  }
  if (ts.isIdentifier(expr) && APPROVED_SCOPE_IDENTIFIERS.has(expr.text)) return true;
  if (ts.isPropertyAccessExpression(expr)) {
    // Codex C4: require explicit approved store as the LHS, e.g.
    // `companyStore.currentCompanyId`. Bare `props.companyId`, `payload.tenantId`,
    // etc. no longer auto-approve.
    if (
      ts.isIdentifier(expr.expression) &&
      APPROVED_STORE_OBJECTS.has(expr.expression.text) &&
      APPROVED_SCOPE_IDENTIFIERS.has(expr.name.text)
    ) {
      return true;
    }
  }
  return false;
}

/**
 * @param {ts.ArrayLiteralExpression} arr
 * @returns {boolean}
 */
function arrayHasApprovedScope(arr) {
  if (arr.elements.length === 0) return false;

  const first = arr.elements[0];
  if (first && ts.isStringLiteralLike(first) && APPROVED_NAMESPACE_PREFIXES.has(first.text)) {
    return true;
  }
  for (const element of arr.elements) {
    if (isApprovedScopeExpression(element)) return true;
  }
  return false;
}

/**
 * Strip the wrappers a developer can add around a queryKey value without
 * changing its semantic shape: parentheses, `as` casts, `<T>` type
 * assertions, and `satisfies` clauses. Codex 2026-05-09 review F1: the
 * prior version unwrapped casts but not parentheses, so
 * `(['users', currentCompanyId] as const)` was misclassified as
 * `ast_kind: 'other'` and falsely flagged.
 *
 * Apply this helper everywhere we inspect a queryKey expression's shape
 * (approval check, resource extraction, AST-kind classification) so the
 * three sites stay in sync.
 *
 * @param {ts.Expression} expr
 * @returns {ts.Expression}
 */
function unwrapKeyExpression(expr) {
  let inner = expr;
  while (
    ts.isParenthesizedExpression(inner) ||
    ts.isAsExpression(inner) ||
    ts.isTypeAssertionExpression(inner) ||
    ts.isSatisfiesExpression(inner)
  ) {
    inner = inner.expression;
  }
  return inner;
}

/**
 * @param {ts.Expression} expr
 * @returns {boolean}
 */
function queryKeyExpressionIsApproved(expr) {
  const inner = unwrapKeyExpression(expr);
  if (ts.isCallExpression(inner) && ts.isIdentifier(inner.expression)) {
    if (APPROVED_FACTORY_CALLS.has(inner.expression.text)) return true;
  }
  if (ts.isArrayLiteralExpression(inner)) return arrayHasApprovedScope(inner);
  return false;
}

/**
 * Walk up from a node to find the nearest enclosing function-like
 * declaration and return its identifier. Mirrors the symbol-resolution
 * the PHP scanners use (enclosing class+method) so violation metadata
 * carries enough context to populate `CallsiteRow.symbol`.
 *
 * Recognized parents:
 *   - FunctionDeclaration / FunctionExpression with a name
 *   - MethodDeclaration with an identifier name
 *   - VariableDeclaration whose initializer is an arrow function or
 *     function expression (covers `const useFoo = () => {...}`)
 *   - PropertyAssignment whose initializer is an arrow function (covers
 *     object-literal hooks like `{ useFoo: () => {...} }`)
 *
 * Returns null when no named parent is found (e.g., a top-level call
 * outside any function body).
 *
 * @param {ts.Node} node
 * @returns {string | null}
 */
function findEnclosingSymbol(node) {
  let current = node.parent;
  while (current) {
    if (ts.isFunctionDeclaration(current) && current.name) {
      return current.name.text;
    }
    if (ts.isMethodDeclaration(current) && ts.isIdentifier(current.name)) {
      return current.name.text;
    }
    if (
      ts.isVariableDeclaration(current) &&
      ts.isIdentifier(current.name) &&
      current.initializer &&
      (ts.isArrowFunction(current.initializer) || ts.isFunctionExpression(current.initializer))
    ) {
      return current.name.text;
    }
    if (
      ts.isPropertyAssignment(current) &&
      ts.isIdentifier(current.name) &&
      (ts.isArrowFunction(current.initializer) || ts.isFunctionExpression(current.initializer))
    ) {
      return current.name.text;
    }
    if (ts.isFunctionExpression(current) && current.name) {
      return current.name.text;
    }
    current = current.parent;
  }
  return null;
}

/**
 * Extract the first string-literal element from a queryKey array, if any.
 * Used as the `resource` field on the callsite row — for keys like
 * `['payment-methods', companyId]`, this returns 'payment-methods', which
 * makes the inventory rows greppable by domain area without forcing the
 * scanner to know about every web feature.
 *
 * @param {ts.Expression} queryKey
 * @returns {string | null}
 */
function extractQueryKeyResource(queryKey) {
  const expr = unwrapKeyExpression(queryKey);
  if (!ts.isArrayLiteralExpression(expr)) {
    return null;
  }
  for (const element of expr.elements) {
    if (ts.isStringLiteralLike(element)) {
      return element.text;
    }
  }
  return null;
}

/**
 * Normalize a queryKey expression's source text into a stable fingerprint.
 * Whitespace is collapsed to single spaces so cosmetic edits to the
 * surrounding code don't shift the hash; the resulting string is what
 * `TanstackKeysScanner` feeds into `StableKey::statement_fingerprint`
 * to discriminate between multiple useQuery calls in the same enclosing
 * symbol.
 *
 * @param {ts.SourceFile} sourceFile
 * @param {ts.Expression} expr
 * @returns {string}
 */
function fingerprintExpression(sourceFile, expr) {
  const raw = expr.getText(sourceFile);
  return raw.replace(/\s+/g, ' ').trim();
}

/**
 * @param {ts.Node} node
 * @returns {boolean}
 */
function isLexicalScope(node) {
  return (
    ts.isSourceFile(node) ||
    ts.isBlock(node) ||
    ts.isModuleBlock(node) ||
    ts.isFunctionLike(node)
  );
}

/**
 * @param {ts.Node} node
 * @returns {Set<ts.Node>}
 */
function ancestorLexicalScopes(node) {
  const scopes = new Set();
  let current = node.parent;
  while (current !== undefined) {
    if (isLexicalScope(current)) {
      scopes.add(current);
    }
    current = current.parent;
  }
  return scopes;
}

/**
 * @param {ts.Node} node
 * @returns {ts.Node | null}
 */
function nearestLexicalScope(node) {
  let current = node.parent;
  while (current !== undefined) {
    if (isLexicalScope(current)) {
      return current;
    }
    current = current.parent;
  }
  return null;
}

/**
 * Resolve a shorthand `queryKey` property back to the nearest preceding
 * variable declaration whose lexical scope is an ancestor of the shorthand
 * usage. This intentionally stays syntax-only like the rest of the scanner;
 * unresolved shorthand remains default-deny by returning null.
 *
 * @param {ts.SourceFile} sourceFile
 * @param {ts.ShorthandPropertyAssignment} shorthand
 * @returns {ts.Expression | null}
 */
function resolveShorthandQueryKeyInitializer(sourceFile, shorthand) {
  const name = shorthand.name.text;
  const shorthandStart = shorthand.getStart(sourceFile);
  const inScopeAncestors = ancestorLexicalScopes(shorthand);
  /** @type {{start: number, initializer: ts.Expression} | null} */
  let best = null;

  /**
   * @param {ts.Node} node
   */
  function visit(node) {
    const nodeStart = node.getStart(sourceFile);
    if (nodeStart >= shorthandStart) return;

    if (
      ts.isVariableDeclaration(node) &&
      ts.isIdentifier(node.name) &&
      node.name.text === name &&
      node.initializer &&
      inScopeAncestors.has(nearestLexicalScope(node) ?? sourceFile)
    ) {
      if (best === null || nodeStart > best.start) {
        best = { start: nodeStart, initializer: node.initializer };
      }
    }

    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
  return best?.initializer ?? null;
}

/**
 * Does this queryKey carry a TENANT scope (as opposed to merely being an
 * approved key)?
 *
 * {@link queryKeyExpressionIsApproved} also green-lights the `'admin'` /
 * `'super-admin'` namespace prefix, which is a super-admin namespace and not a
 * tenant/company suffix — a placeholder cannot cross a company boundary there.
 * The `placeholderData` pairing rule keys off THIS predicate instead, so it
 * covers the two shapes the pre-hardening version missed: a bare approved
 * identifier (`['payments', page, currentCompanyId]`) and the approved store
 * object (`companyStore.currentCompanyId`) — both legal per
 * {@link APPROVED_SCOPE_IDENTIFIERS} / {@link APPROVED_STORE_OBJECTS}, both
 * carrying the company suffix that the placeholder survives across.
 *
 * @param {ts.Expression} expr
 * @returns {boolean}
 */
function queryKeyCarriesTenantScope(expr) {
  const inner = unwrapKeyExpression(expr);
  if (ts.isCallExpression(inner) && ts.isIdentifier(inner.expression)) {
    return APPROVED_FACTORY_CALLS.has(inner.expression.text);
  }
  if (ts.isArrayLiteralExpression(inner)) {
    return inner.elements.some((element) => isApprovedScopeExpression(element));
  }
  return false;
}

/**
 * Collect the local names introduced by an object binding pattern for the
 * `isPlaceholderData` result field, honouring renames
 * (`{ isPlaceholderData: isFooPending }`).
 *
 * @param {ts.ObjectBindingPattern} pattern
 * @param {Set<string>} out
 */
function collectPlaceholderFlagNames(pattern, out) {
  for (const element of pattern.elements) {
    const source = element.propertyName ?? element.name;
    if (ts.isIdentifier(source) && source.text === 'isPlaceholderData' && ts.isIdentifier(element.name)) {
      out.add(element.name.text);
    }
  }
}

/**
 * @param {ts.ArrayBindingElement} element
 * @param {Set<string>} out
 */
function collectFromBindingElement(element, out) {
  if (ts.isOmittedExpression(element)) return;
  if (ts.isIdentifier(element.name)) {
    out.add(element.name.text);
    return;
  }
  if (ts.isObjectBindingPattern(element.name)) {
    collectPlaceholderFlagNames(element.name, out);
  }
}

/**
 * Walk up from the factory call to the binding NAME that receives its result,
 * stopping at any function/block/statement boundary so an unbound call
 * (`useQuery({...});` as an expression statement) resolves to null.
 *
 * Two shapes bind a result (gate fix round 1, MINOR-4 — the second was a false
 * positive on a correctly guarded read):
 *   - a variable declaration: `const r = useQuery(...)`, including the object /
 *     array destructuring patterns;
 *   - an assignment expression to an already-declared identifier:
 *     `let r; r = useQuery(...)`.
 * A destructuring ASSIGNMENT (`({ data } = useQuery(...))`) is not a
 * `BindingName` and stays unbound — rare, and it fails CLOSED (reported).
 *
 * @param {ts.CallExpression} call
 * @returns {ts.BindingName | null}
 */
function findResultBindingName(call) {
  let current = call.parent;
  while (current) {
    if (ts.isVariableDeclaration(current)) return current.name;
    if (
      ts.isBinaryExpression(current) &&
      current.operatorToken.kind === ts.SyntaxKind.EqualsToken &&
      ts.isIdentifier(current.left)
    ) {
      return current.left;
    }
    if (
      ts.isFunctionLike(current) ||
      ts.isBlock(current) ||
      ts.isSourceFile(current) ||
      ts.isExpressionStatement(current) ||
      ts.isReturnStatement(current)
    ) {
      return null;
    }
    current = current.parent;
  }
  return null;
}

/**
 * How THIS read's result can be referenced by a paired guard call.
 *
 * `names` are handles that stand for the read on their own (a destructured
 * `isPlaceholderData`, a whole-result identifier, the `useQueries` element
 * destructured at this entry's index).
 *
 * `indexedHolder` is the gate fix round 1, MAJOR-1 case: a `useQueries` call
 * whose whole results ARRAY is bound to one identifier
 * (`const results = useQueries({ queries: [A, B] })`). That identifier is NOT a
 * per-entry handle — accepting it let a single `usePlaceholderScopeGuard(
 * results[0]…)` clear the unguarded sibling `results[1]`, i.e. the class-4
 * defect surviving inside one call. When it is set, pairing additionally
 * requires an element access AT THIS ENTRY'S INDEX (`results[1]`, `results.at(1)`).
 *
 * @param {ts.CallExpression} call the factory call (`useQuery` / `useQueries` / …)
 * @param {number | null} entryIndex index into `queries: [...]` for useQueries, else null
 * @returns {{names: Set<string>, indexedHolder: string | null}}
 */
function readResultBinding(call, entryIndex) {
  /** @type {Set<string>} */
  const names = new Set();
  const bound = findResultBindingName(call);
  if (!bound) return { names, indexedHolder: null };

  if (ts.isIdentifier(bound)) {
    if (entryIndex !== null) {
      // `const results = useQueries(...)` — per-entry access required.
      return { names, indexedHolder: bound.text };
    }
    // `const result = useQuery(...)` — the guard reads `result.isPlaceholderData`.
    names.add(bound.text);
    return { names, indexedHolder: null };
  }
  if (ts.isObjectBindingPattern(bound)) {
    collectPlaceholderFlagNames(bound, names);
    return { names, indexedHolder: null };
  }
  if (ts.isArrayBindingPattern(bound)) {
    if (entryIndex === null) {
      for (const element of bound.elements) collectFromBindingElement(element, names);
      return { names, indexedHolder: null };
    }
    const element = bound.elements[entryIndex];
    if (element) collectFromBindingElement(element, names);
    return { names, indexedHolder: null };
  }
  return { names, indexedHolder: null };
}

/**
 * Nearest enclosing function-like body, or the source file for a module-scope
 * read. This is the region searched for the paired guard call: a component or
 * hook body, including any nested callback (`useMemo`, a render gate, an
 * `enabled` expression) inside it.
 *
 * @param {ts.Node} node
 * @returns {ts.Node}
 */
function enclosingGuardSearchScope(node) {
  let current = node.parent;
  while (current) {
    if (ts.isFunctionLike(current)) return current;
    current = current.parent;
  }
  return node.getSourceFile();
}

/**
 * @param {ts.Node} node
 * @param {Set<string>} names
 * @returns {boolean}
 */
function referencesAnyName(node, names) {
  let found = false;
  /** @param {ts.Node} current */
  function visit(current) {
    if (found) return;
    if (ts.isIdentifier(current) && names.has(current.text)) {
      found = true;
      return;
    }
    ts.forEachChild(current, visit);
  }
  visit(node);
  return found;
}

/**
 * Does this expression read `<holder>[<index>]` or `<holder>.at(<index>)`
 * anywhere inside it? See {@link readResultBinding} `indexedHolder`.
 *
 * @param {ts.Node} node
 * @param {string} holder
 * @param {number} index
 * @returns {boolean}
 */
function referencesIndexedEntry(node, holder, index) {
  const wanted = String(index);
  let found = false;
  /** @param {ts.Node} current */
  function visit(current) {
    if (found) return;
    if (
      ts.isElementAccessExpression(current) &&
      ts.isIdentifier(current.expression) &&
      current.expression.text === holder &&
      ts.isNumericLiteral(current.argumentExpression) &&
      current.argumentExpression.text === wanted
    ) {
      found = true;
      return;
    }
    if (
      ts.isCallExpression(current) &&
      ts.isPropertyAccessExpression(current.expression) &&
      current.expression.name.text === 'at' &&
      ts.isIdentifier(current.expression.expression) &&
      current.expression.expression.text === holder &&
      current.arguments.length === 1 &&
      current.arguments[0] &&
      ts.isNumericLiteral(current.arguments[0]) &&
      current.arguments[0].text === wanted
    ) {
      found = true;
      return;
    }
    ts.forEachChild(current, visit);
  }
  visit(node);
  return found;
}

/** @type {WeakMap<ts.SourceFile, Set<string>>} */
const guardLocalNameCache = new WeakMap();

/**
 * The local names under which {@link PLACEHOLDER_SCOPE_GUARD} is callable in
 * this file: its canonical name, plus every alias introduced by a named import
 * (`import { usePlaceholderScopeGuard as useScopeGuard } from '…'`).
 *
 * Gate fix round 1, MINOR-2: matching the call name literally reported a
 * correctly guarded read whenever the import was aliased, and the message
 * ("a comment or import naming the guard does not count") actively misled.
 * Only an ALIAS OF THE REAL IMPORT counts — a same-named local helper does not
 * pair, so the check stays fail-closed.
 *
 * @param {ts.SourceFile} sourceFile
 * @returns {Set<string>}
 */
function guardLocalNames(sourceFile) {
  const cached = guardLocalNameCache.get(sourceFile);
  if (cached) return cached;
  const names = new Set([PLACEHOLDER_SCOPE_GUARD]);
  for (const statement of sourceFile.statements) {
    if (!ts.isImportDeclaration(statement)) continue;
    const bindings = statement.importClause?.namedBindings;
    if (!bindings || !ts.isNamedImports(bindings)) continue;
    for (const specifier of bindings.elements) {
      if (specifier.propertyName?.text === PLACEHOLDER_SCOPE_GUARD) {
        names.add(specifier.name.text);
      }
    }
  }
  guardLocalNameCache.set(sourceFile, names);
  return names;
}

/**
 * Is this placeholder-bearing read paired with a real
 * `usePlaceholderScopeGuard(...)` CALL at its own call site?
 *
 * AST check, deliberately not a text search: the pre-hardening version used
 * `sourceFile.text.includes('usePlaceholderScopeGuard')`, so a comment, a
 * string literal or a dead import bearing the name cleared the rule, and a
 * single guard cleared every read in the file. Both classes are now fixtured.
 *
 * Pairing requires BOTH:
 *   1. the read's result is bound (see {@link readResultBinding}) — as a name
 *      the guard can mention, or, for an identifier-bound `useQueries`, as an
 *      element access at this entry's index;
 *   2. a guard call (canonical name or an import alias of it, see
 *      {@link guardLocalNames}) inside the read's enclosing function passes one
 *      of those references as an argument.
 *
 * @param {ts.CallExpression} call
 * @param {number | null} entryIndex
 * @returns {boolean}
 */
function readHasPairedScopeGuard(call, entryIndex) {
  const { names, indexedHolder } = readResultBinding(call, entryIndex);
  if (names.size === 0 && indexedHolder === null) return false;

  const guardNames = guardLocalNames(call.getSourceFile());
  const scope = enclosingGuardSearchScope(call);
  let paired = false;
  /** @param {ts.Node} node */
  function visit(node) {
    if (paired) return;
    if (
      ts.isCallExpression(node) &&
      ts.isIdentifier(node.expression) &&
      guardNames.has(node.expression.text) &&
      node.arguments.some((argument) =>
        indexedHolder !== null
          ? referencesIndexedEntry(argument, indexedHolder, /** @type {number} */ (entryIndex))
          : referencesAnyName(argument, names),
      )
    ) {
      paired = true;
      return;
    }
    ts.forEachChild(node, visit);
  }
  visit(scope);
  return paired;
}

/**
 * Does an options SPREAD payload declare `placeholderData`?
 *
 * Gate fix round 1, MAJOR-2: `placeholderData` reaching the read through
 * `...spreadOptions` was invisible, because the property lookup matched on
 * `p.name.text` and a `SpreadAssignment` has no `name`. This resolves the
 * payload within the file — object literals, parenthesized / `as` wrappers,
 * conditional and `&&` / `||` / `??` combinations, an identifier bound to a
 * local object literal, and a call to a locally declared function whose
 * returns are such literals.
 *
 * @param {ts.Expression} expr
 * @param {ts.SourceFile} sourceFile
 * @param {number} [depth]
 * @returns {'yes' | 'no' | 'unknown'}
 */
function spreadPayloadPlaceholderVerdict(expr, sourceFile, depth = 0) {
  if (depth > 6) return 'unknown';
  /** @param {Array<'yes' | 'no' | 'unknown'>} verdicts */
  const combine = (verdicts) => {
    if (verdicts.includes('yes')) return 'yes';
    if (verdicts.includes('unknown')) return 'unknown';
    return verdicts.length > 0 ? 'no' : 'unknown';
  };
  const recurse = (/** @type {ts.Expression} */ next) =>
    spreadPayloadPlaceholderVerdict(next, sourceFile, depth + 1);

  if (ts.isParenthesizedExpression(expr) || ts.isAsExpression(expr) || ts.isSatisfiesExpression(expr)) {
    return recurse(expr.expression);
  }
  if (ts.isObjectLiteralExpression(expr)) {
    /** @type {Array<'yes' | 'no' | 'unknown'>} */
    const nested = [];
    for (const property of expr.properties) {
      if (
        (ts.isPropertyAssignment(property) || ts.isShorthandPropertyAssignment(property)) &&
        property.name &&
        ts.isIdentifier(property.name) &&
        property.name.text === 'placeholderData'
      ) {
        return 'yes';
      }
      if (ts.isSpreadAssignment(property)) nested.push(recurse(property.expression));
    }
    return nested.length > 0 ? combine(nested) : 'no';
  }
  if (ts.isConditionalExpression(expr)) {
    return combine([recurse(expr.whenTrue), recurse(expr.whenFalse)]);
  }
  if (ts.isBinaryExpression(expr)) {
    // `cond && { … }` — only the right operand can be the payload.
    if (expr.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken) return recurse(expr.right);
    if (
      expr.operatorToken.kind === ts.SyntaxKind.BarBarToken ||
      expr.operatorToken.kind === ts.SyntaxKind.QuestionQuestionToken
    ) {
      return combine([recurse(expr.left), recurse(expr.right)]);
    }
    return 'unknown';
  }
  if (ts.isIdentifier(expr)) {
    const initializer = findLocalConstInitializer(sourceFile, expr.text);
    return initializer ? recurse(initializer) : 'unknown';
  }
  if (ts.isCallExpression(expr) && ts.isIdentifier(expr.expression)) {
    const returns = findLocalFunctionReturnExpressions(sourceFile, expr.expression.text);
    if (returns === null) return 'unknown';
    return combine(returns.map((value) => recurse(value)));
  }
  return 'unknown';
}

/**
 * First initializer of a top-level-or-nested `const`/`let` declaration of
 * `name` in this file. Deliberately name-based, not scope-aware: a spread
 * payload that cannot be resolved returns 'unknown' and is left alone.
 *
 * @param {ts.SourceFile} sourceFile
 * @param {string} name
 * @returns {ts.Expression | null}
 */
function findLocalConstInitializer(sourceFile, name) {
  /** @type {ts.Expression | null} */
  let found = null;
  /** @param {ts.Node} node */
  function visit(node) {
    if (found) return;
    if (
      ts.isVariableDeclaration(node) &&
      ts.isIdentifier(node.name) &&
      node.name.text === name &&
      node.initializer
    ) {
      found = node.initializer;
      return;
    }
    ts.forEachChild(node, visit);
  }
  visit(sourceFile);
  return found;
}

/**
 * The expressions a locally declared `name` function can return — a concise
 * arrow body counts as one. `null` means the function is not declared in this
 * file (imported, a parameter, …), i.e. unresolvable.
 *
 * @param {ts.SourceFile} sourceFile
 * @param {string} name
 * @returns {ts.Expression[] | null}
 */
function findLocalFunctionReturnExpressions(sourceFile, name) {
  /** @type {ts.FunctionDeclaration | ts.FunctionExpression | ts.ArrowFunction | null} */
  let fn = null;
  /** @param {ts.Node} node */
  function findFn(node) {
    if (fn) return;
    if (ts.isFunctionDeclaration(node) && node.name?.text === name) {
      fn = node;
      return;
    }
    if (
      ts.isVariableDeclaration(node) &&
      ts.isIdentifier(node.name) &&
      node.name.text === name &&
      node.initializer &&
      (ts.isArrowFunction(node.initializer) || ts.isFunctionExpression(node.initializer))
    ) {
      fn = node.initializer;
      return;
    }
    ts.forEachChild(node, findFn);
  }
  findFn(sourceFile);
  if (!fn) return null;
  const body = /** @type {ts.FunctionDeclaration | ts.FunctionExpression | ts.ArrowFunction} */ (fn).body;
  if (!body) return null;
  if (!ts.isBlock(body)) return [body];
  /** @type {ts.Expression[]} */
  const returns = [];
  /** @param {ts.Node} node */
  function collect(node) {
    if (ts.isFunctionLike(node) && node !== fn) return;
    if (ts.isReturnStatement(node) && node.expression) returns.push(node.expression);
    ts.forEachChild(node, collect);
  }
  collect(body);
  return returns;
}

/**
 * @param {ts.ObjectLiteralExpression} options
 * @param {string} factoryName
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath
 * @param {Array<{file: string, line: number, column: number, reason: string, factory: string, enclosing_symbol: string | null, resource: string | null, statement_fingerprint: string, ast_kind: string}>} out
 * @param {ts.CallExpression | null} [callSite] the factory call, for per-call-site guard pairing
 * @param {number | null} [entryIndex] index into `queries: [...]` for a useQueries entry
 */
function checkOptionsObject(options, factoryName, sourceFile, relPath, out, callSite = null, entryIndex = null) {
  const queryKeyProp = options.properties.find(
    (p) =>
      (ts.isPropertyAssignment(p) || ts.isShorthandPropertyAssignment(p)) &&
      p.name &&
      ts.isIdentifier(p.name) &&
      p.name.text === 'queryKey',
  );
  if (queryKeyProp) {
    // Merged semantics (design-sweep x replenishment): shorthand `{ queryKey }`
    // resolves to its in-scope declaration (default-deny when unresolvable),
    // and invalidation factories may use bare array prefixes.
    const initializer = ts.isPropertyAssignment(queryKeyProp)
      ? queryKeyProp.initializer
      : resolveShorthandQueryKeyInitializer(sourceFile, queryKeyProp) ?? queryKeyProp.name;
    const innerKey = unwrapKeyExpression(initializer);
    const isCacheFilterFactory = CACHE_FILTER_FACTORIES.has(factoryName);

    // No-op filter rule: a cache-filter method whose queryKey is a
    // tenantScopedKey(...) call matches by suffix-vs-prefix mismatch —
    // see CACHE_FILTER_FACTORIES doc comment. Flag it as an error.
    const isTenantScopedFactoryCall =
      ts.isCallExpression(innerKey) &&
      ts.isIdentifier(innerKey.expression) &&
      APPROVED_FACTORY_CALLS.has(innerKey.expression.text);
    // Pairing rule (see the "Rule 2" header section for the exact contract):
    // a key that CARRIES A TENANT SCOPE + `placeholderData` + no guard call
    // paired with THIS call site. Checked before the approval verdict below
    // because a tenant-scoped key is, by construction, an APPROVED one — an
    // unscoped key is already reported by that verdict and must not be
    // reported twice.
    // `innerKey` (already unwrapped) rather than `initializer`: same verdict,
    // and it keeps the @ts-check narrowing clean.
    if (queryKeyCarriesTenantScope(innerKey) && PLACEHOLDER_BEARING_FACTORIES.has(factoryName)) {
      const placeholderProp = options.properties.find(
        (p) =>
          (ts.isPropertyAssignment(p) || ts.isShorthandPropertyAssignment(p)) &&
          p.name &&
          ts.isIdentifier(p.name) &&
          p.name.text === 'placeholderData',
      );
      // Gate fix round 1, MAJOR-2: `placeholderData` can also arrive through a
      // SPREAD, which the property lookup above cannot see (a SpreadAssignment
      // has no `name`). Resolve each spread payload inside this file; a payload
      // that carries `placeholderData` triggers the rule exactly like an inline
      // property does. A payload that cannot be resolved here — a caller-supplied
      // options parameter, an imported options object — is a KNOWN LIMITATION
      // recorded in the "Rule 2" header and in docs/conventions/05-REACT-QUERY.md.
      const spreadProp = options.properties.find(
        (p) => ts.isSpreadAssignment(p) && spreadPayloadPlaceholderVerdict(p.expression, sourceFile) === 'yes',
      );
      const trigger = placeholderProp ?? spreadProp ?? null;
      const paired = callSite !== null && readHasPairedScopeGuard(callSite, entryIndex);
      if (trigger && !paired) {
        const viaSpread = placeholderProp === undefined;
        const startPos = trigger.getStart(sourceFile);
        const { line, character } = sourceFile.getLineAndCharacterOfPosition(startPos);
        // The key label and the `resource` field must work for BOTH scoped
        // shapes: a `tenantScopedKey([...])` call and an array literal whose
        // scope is a bare identifier / `companyStore.<id>` member access.
        const isScopeFactoryCall = ts.isCallExpression(innerKey) && ts.isIdentifier(innerKey.expression);
        const keyLabel = isScopeFactoryCall
          ? `${innerKey.expression.text}([...])`
          : '[..., <tenant scope>]';
        const scopedResource = isScopeFactoryCall
          ? (innerKey.arguments[0] ? extractQueryKeyResource(innerKey.arguments[0]) : null)
          : extractQueryKeyResource(innerKey);
        const optionsLabel = viaSpread ? '...options' : 'placeholderData';
        const spreadNote = viaSpread
          ? 'unpaired-unknown (spread options — declare placeholderData inline or guard). '
          : '';
        out.push({
          file: relPath,
          line: line + 1,
          column: character + 1,
          reason:
            `${factoryName}({ queryKey: ${keyLabel}, ${optionsLabel} }) ` +
            'renders the PREVIOUS tenant/company payload after a scope switch: TanStack picks the ' +
            'placeholder from the observer\'s last query that had data with no key-lineage check. ' +
            spreadNote +
            `Gate the rows on ${PLACEHOLDER_SCOPE_GUARD}(isPlaceholderData, data !== undefined) ` +
            'at THIS call site (bind the read\'s result and pass its isPlaceholderData flag to the ' +
            'guard — a comment or import naming the guard does not count), ' +
            'or drop placeholderData when there is no same-scope win to keep.',
          factory: factoryName,
          enclosing_symbol: findEnclosingSymbol(trigger),
          resource: scopedResource,
          statement_fingerprint: `${fingerprintExpression(sourceFile, initializer)}@${startPos}`,
          ast_kind: classifyQueryKeyAstKind(initializer),
        });
      }
    }

    if (isCacheFilterFactory && isTenantScopedFactoryCall) {
      const startPos = queryKeyProp.getStart(sourceFile);
      const { line, character } = sourceFile.getLineAndCharacterOfPosition(startPos);
      const scopedArg = innerKey.arguments[0];
      out.push({
        file: relPath,
        line: line + 1,
        column: character + 1,
        reason:
          `${factoryName}({ queryKey: ${innerKey.expression.text}([...]) }) is a no-op filter: ` +
          `${innerKey.expression.text} appends tenant/company as SUFFIXES but React Query matches ` +
          "filter keys as positional PREFIXES — use a bare literal prefix (e.g. ['stock-transfers'])",
        factory: factoryName,
        enclosing_symbol: findEnclosingSymbol(queryKeyProp),
        resource: scopedArg ? extractQueryKeyResource(scopedArg) : null,
        statement_fingerprint: `${fingerprintExpression(sourceFile, initializer)}@${startPos}`,
        ast_kind: classifyQueryKeyAstKind(initializer),
      });
      return;
    }

    const isInvalidationBarePrefix =
      isCacheFilterFactory && ts.isArrayLiteralExpression(innerKey);
    if (!queryKeyExpressionIsApproved(initializer) && !isInvalidationBarePrefix) {
      const startPos = queryKeyProp.getStart(sourceFile);
      const { line, character } = sourceFile.getLineAndCharacterOfPosition(startPos);
      // Byte-offset-into-source disambiguator matches the PHP scanner pattern
      // (PhpAstFindScanner uses start_file_pos in its statement_fingerprint).
      // Distinct violations at the same fingerprint text — e.g., multiple
      // `invalidateQueries({queryKey: batchKeys.all})` calls inside several
      // `onSuccess` callbacks of the same file — stay distinct because their
      // byte offsets differ. Stable across edits to UNRELATED parts of the
      // file (anything after the violation's offset shifts, but THIS
      // violation's offset is anchored to its own start position).
      const fingerprintWithOffset = `${fingerprintExpression(sourceFile, initializer)}@${startPos}`;
      out.push({
        file: relPath,
        line: line + 1,
        column: character + 1,
        reason: `${factoryName}({ queryKey: ... }) lacks an approved tenant scope`,
        factory: factoryName,
        enclosing_symbol: findEnclosingSymbol(queryKeyProp),
        resource: extractQueryKeyResource(initializer),
        statement_fingerprint: fingerprintWithOffset,
        ast_kind: classifyQueryKeyAstKind(initializer),
      });
    }
  }
}

/**
 * Classify the queryKey expression into a coarse AST-kind label that lets
 * downstream consumers (the inventory generator, the architecture-test
 * gate) distinguish "bare array literal" from "unknown factory call"
 * without re-parsing.
 *
 * @param {ts.Expression} expr
 * @returns {string}
 */
function classifyQueryKeyAstKind(expr) {
  const inner = unwrapKeyExpression(expr);
  if (ts.isArrayLiteralExpression(inner)) return 'array_literal';
  if (ts.isCallExpression(inner)) return 'call_expression';
  if (ts.isIdentifier(inner)) return 'identifier';
  return 'other';
}

/**
 * Codex C4: `useQueries({ queries: [ { queryKey: ... }, ... ] })` stores its
 * options under a `queries` array, not a top-level queryKey. Descend into
 * that array and audit each entry independently.
 *
 * @param {ts.ObjectLiteralExpression} options
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath
 * @param {Array<{file: string, line: number, column: number, reason: string}>} out
 * @param {ts.CallExpression} callSite the `useQueries(...)` call itself
 */
function checkUseQueriesOptions(options, sourceFile, relPath, out, callSite) {
  const queriesProp = options.properties.find(
    (p) =>
      ts.isPropertyAssignment(p) &&
      p.name &&
      ts.isIdentifier(p.name) &&
      p.name.text === 'queries',
  );
  if (!queriesProp || !ts.isPropertyAssignment(queriesProp)) return;
  const arr = queriesProp.initializer;
  if (!ts.isArrayLiteralExpression(arr)) return;
  arr.elements.forEach((element, entryIndex) => {
    if (ts.isObjectLiteralExpression(element)) {
      checkOptionsObject(element, 'useQueries.queries[]', sourceFile, relPath, out, callSite, entryIndex);
    }
  });
}

/**
 * @param {ts.SourceFile} sourceFile
 * @param {string} filePath
 * @returns {Array<{file: string, line: number, column: number, reason: string}>}
 */
export function scanSource(sourceFile, filePath) {
  /** @type {Array<{file: string, line: number, column: number, reason: string}>} */
  const violations = [];
  const relPath = path.relative(WEB_ROOT, filePath);

  /**
   * @param {ts.Node} node
   */
  function inner(node) {
    if (ts.isCallExpression(node)) {
      const callee = node.expression;
      let name = null;
      if (ts.isIdentifier(callee)) name = callee.text;
      else if (ts.isPropertyAccessExpression(callee)) name = callee.name.text;

      const arg = node.arguments[0];
      if (name === 'useQueries' && arg && ts.isObjectLiteralExpression(arg)) {
        checkUseQueriesOptions(arg, sourceFile, relPath, violations, node);
      } else if (name && QUERY_FACTORY_NAMES.has(name) && arg && ts.isObjectLiteralExpression(arg)) {
        checkOptionsObject(arg, name, sourceFile, relPath, violations, node, null);
      }
    }
    ts.forEachChild(node, inner);
  }

  inner(sourceFile);
  return violations;
}

/**
 * Convenience wrapper for the unit test: parses a snippet of TS source and
 * returns the violations (with `filename` defaulting to '<inline>').
 *
 * @param {string} code
 * @param {string} [filename]
 * @returns {Array<{file: string, line: number, column: number, reason: string}>}
 */
export function scanCode(code, filename = '<inline>') {
  const sourceFile = ts.createSourceFile(
    filename,
    code,
    ts.ScriptTarget.ES2022,
    true,
    /\.tsx$/.test(filename) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  return scanSource(sourceFile, filename);
}

/**
 * @param {string} dir
 * @returns {Promise<string[]>}
 */
async function walk(dir) {
  /** @type {string[]} */
  const out = [];
  const entries = await fs.readdir(dir, { withFileTypes: true });
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === 'node_modules' || entry.name === '__tests__' || entry.name === '__mocks__') {
        continue;
      }
      out.push(...(await walk(full)));
      continue;
    }
    if (!entry.isFile()) continue;
    if (!/\.tsx?$/.test(entry.name)) continue;
    if (/\.(test|spec)\.tsx?$/.test(entry.name)) continue;
    out.push(full);
  }
  return out;
}

// ---------------------------------------------------------------------------
// CLI entrypoint — only runs when invoked directly (not when imported).
// ---------------------------------------------------------------------------

const isMain = (() => {
  if (typeof process === 'undefined' || !Array.isArray(process.argv) || process.argv.length < 2) {
    return false;
  }
  const invokedPath = path.resolve(process.argv[1] ?? '');
  const thisPath = fileURLToPath(import.meta.url);
  return invokedPath === thisPath;
})();

if (isMain) {
  const wantsJson = process.argv.includes('--json');
  const fileList = await walk(SRC_ROOT);
  /** @type {Array<{file: string, line: number, column: number, reason: string, factory: string, enclosing_symbol: string | null, resource: string | null, statement_fingerprint: string, ast_kind: string}>} */
  const allViolations = [];
  for (const file of fileList) {
    const code = await fs.readFile(file, 'utf8');
    const sourceFile = ts.createSourceFile(
      file,
      code,
      ts.ScriptTarget.ES2022,
      true,
      /\.tsx$/.test(file) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
    );
    allViolations.push(...scanSource(sourceFile, file));
  }
  process.stderr.write(
    `[sweep-progress] Gate C — useQuery/useQueries/queryClient queryKeys without an approved tenant scope: ${allViolations.length}\n`,
  );
  if (wantsJson) {
    // Stable shape consumed by TanstackKeysScanner.php (sweep:inventory:generate
    // wiring). Fields per violation:
    //   file                  — apps/web-relative path (e.g. src/features/.../foo.ts)
    //   line, column          — display-only metadata
    //   factory               — useQuery / useMutation / useQueries.queries[] / queryClient.<method>
    //   enclosing_symbol      — nearest enclosing function/component name, or null
    //   resource              — first string literal in queryKey array, or null
    //   statement_fingerprint — whitespace-normalized queryKey source text (stable_key discriminator)
    //   ast_kind              — array_literal / call_expression / identifier / other
    //   reason                — human-readable label (preserved for backward-compat)
    //
    // Stdout backpressure: large payloads (~849 violations × ~250 bytes each
    // ≈ 200 KB) exceed Node's default pipe buffer; write asynchronously and
    // wait for the drain before exiting so the consumer sees the whole
    // payload, not a 64 KB-truncated prefix.
    const payload = JSON.stringify({ violations: allViolations });
    const wrote = process.stdout.write(payload);
    if (!wrote) {
      await new Promise((resolve) => process.stdout.once('drain', resolve));
    }
    process.exit(0);
  }

  const { baselined, newViolations, staleBaselineEntries } = partitionViolationsByBaseline(allViolations);
  process.stderr.write(
    `[gate-summary] Gate C baseline: ${baselined.length} acknowledged, ${newViolations.length} new, ${staleBaselineEntries.length} stale baseline entries\n`,
  );

  if (newViolations.length > 0) {
    process.stderr.write('\nNew unscoped TanStack query key violations:\n');
    for (const violation of newViolations) {
      process.stderr.write(
        `  ${violation.file}:${violation.line}:${violation.column} ${violation.reason} ` +
          `(factory=${violation.factory}, symbol=${violation.enclosing_symbol ?? '<top-level>'}, key=${violation.statement_fingerprint})\n`,
      );
    }
  }

  if (staleBaselineEntries.length > 0) {
    process.stderr.write('\nStale TanStack query key baseline entries; remove or update these entries:\n');
    for (const entry of staleBaselineEntries) {
      process.stderr.write(`  ${entry}\n`);
    }
  }

  if (newViolations.length > 0 || staleBaselineEntries.length > 0) {
    process.exit(1);
  }

  process.exit(0);
}
