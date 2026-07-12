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
]);

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
 * @param {ts.ObjectLiteralExpression} options
 * @param {string} factoryName
 * @param {ts.SourceFile} sourceFile
 * @param {string} relPath
 * @param {Array<{file: string, line: number, column: number, reason: string, factory: string, enclosing_symbol: string | null, resource: string | null, statement_fingerprint: string, ast_kind: string}>} out
 */
function checkOptionsObject(options, factoryName, sourceFile, relPath, out) {
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
    if (isCacheFilterFactory && isTenantScopedFactoryCall) {
      const startPos = queryKeyProp.getStart(sourceFile);
      const { line, character } = sourceFile.getLineAndCharacterOfPosition(startPos);
      const scopedArg = innerKey.arguments[0];
      out.push({
        file: relPath,
        line: line + 1,
        column: character + 1,
        reason:
          `${factoryName}({ queryKey: tenantScopedKey([...]) }) is a no-op filter: ` +
          'tenantScopedKey appends tenant/company as SUFFIXES but React Query matches ' +
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
 */
function checkUseQueriesOptions(options, sourceFile, relPath, out) {
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
  for (const element of arr.elements) {
    if (ts.isObjectLiteralExpression(element)) {
      checkOptionsObject(element, 'useQueries.queries[]', sourceFile, relPath, out);
    }
  }
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
        checkUseQueriesOptions(arg, sourceFile, relPath, violations);
      } else if (name && QUERY_FACTORY_NAMES.has(name) && arg && ts.isObjectLiteralExpression(arg)) {
        checkOptionsObject(arg, name, sourceFile, relPath, violations);
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
