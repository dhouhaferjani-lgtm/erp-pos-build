#!/usr/bin/env node
// @ts-check
/**
 * Phase 2B.2 — POS local-cache tenant-isolation scanner.
 *
 * Master plan Section 5.4 (`pos_sqlite_cache` scanner). Walks the Tauri-side
 * POS code and emits CallsiteRow-shaped findings for two pattern families:
 *
 *   1. `create_table_without_tenant`
 *      A SQL `CREATE TABLE` statement on a guarded resource whose column
 *      list does not include both `tenant_id` and `company_id`. The
 *      master-plan expected_scope for the SQLite mirror tables is
 *      `tenant_and_company`, so we flag whenever EITHER column is absent
 *      (a stricter rule than a literal reading of the original prompt's
 *      "lacks tenant_id AND lacks company_id" wording — required to avoid
 *      letting a table with only one of the two columns slip past the gate
 *      and contradict the schema's expected_scope: tenant_and_company).
 *      Cluster: `tauri.sqlite-cache`.
 *
 *   2. `sync_envelope_without_tenant_check`
 *      A function whose first parameter is named `envelope` that reads the
 *      payload (via `envelope.payload` or destructuring) before validating
 *      `envelope.tenant_id` against the active auth context. The validation
 *      heuristic: any reference to `envelope.tenant_id` (in any form)
 *      anywhere in a statement that source-precedes the first payload
 *      access counts as validation. Cluster: `tauri.sync-envelope`.
 *
 * ## Guarded-resource list
 *
 * The canonical list of guarded tables lives in PHP at
 * `apps/api/app/Application/Sweep/Scanners/PhpPresentationExistsScanner.php`
 * (`DEFAULT_GUARDED_TABLES`) and is also mirrored at the architecture test
 * `apps/api/tests/Architecture/TenantScopedExistsRulesTest.php`
 * (`GUARDED_TABLES`). The original session prompt assumed the list was
 * exported from `audit-tanstack-keys.mjs` — it is not (that scanner pins
 * TanStack queryKey scopes, an orthogonal concern). To avoid touching
 * apps/api/ from this parallel session, the list is mirrored verbatim
 * below; the docblock above each entry is intentionally short so a future
 * regenerate-from-PHP pass can diff cleanly. Drift will be caught by code
 * review and (eventually) by an apps/api-side test that asserts the JS
 * mirror equals the PHP const at CI time.
 *
 * ## Stable-key algorithm
 *
 * Mirrors `App\Application\Sweep\Scanners\StableKey::fromScannerOutput`:
 * SHA-256 of nine string fields joined by NUL bytes, rendered as
 * `sha256:<64hex>`. Field order, coercion rules, and "use empty string
 * for null/missing" semantics are all preserved. Two callsites with the
 * same nine-tuple produce the same key across regenerations even if the
 * line number changes.
 *
 * ## Output modes
 *
 *   - Default (gate): one line per flagged callsite to stderr in the
 *     format `<file>:<line> <pattern_type> <reason>`. Exits 1 if any gap,
 *     0 if clean.
 *   - `--emit-inventory-rows`: one JSON line per flagged callsite to
 *     stdout. Always exits 0 (emit-and-exit semantics so the YAML
 *     generator can consume the output without the gate failing CI).
 *
 * Run via: `node apps/web/tools/audit-pos-local-cache.mjs [files...]`
 * With no positional file args, the scanner walks the three default POS
 * targets the master plan calls out (db.ts, db/migrations.ts,
 * sync/syncService.ts).
 */

import { createHash } from 'node:crypto';
import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import ts from 'typescript';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const WEB_ROOT = path.resolve(__dirname, '..');
const REPO_ROOT = path.resolve(WEB_ROOT, '..', '..');

/**
 * Mirror of `PhpPresentationExistsScanner::DEFAULT_GUARDED_TABLES`.
 * KEEP IN SYNC manually until an apps/api-side fixture export is wired up.
 */
const GUARDED_TABLES = new Set([
  'accounts',
  'batches',
  'cart_items',
  'carts',
  'categories',
  'contacts',
  'coupons',
  'documents',
  'expense_categories',
  'fraud_alerts',
  'invoices',
  'locations',
  'loyalty_members',
  'loyalty_programs',
  'loyalty_rewards',
  'modifier_groups',
  'modifiers',
  'partners',
  'payment_methods',
  'payment_repositories',
  'payments',
  'pos_locations',
  'pos_receipts',
  'pos_terminals',
  'pricing_rules',
  'product_variants',
  'products',
  'service_catalog_items',
  'services',
  'stock_levels',
  'stock_movements',
  'tax_configurations',
  'tax_rates',
  'voucher_ledger',
  'vouchers',
  'withholding_certificates',
  'work_orders',
]);

/**
 * Default-walk targets when the CLI is invoked with no positional file
 * arguments. The list is overridable at runtime via the
 * `AUDIT_POS_LOCAL_CACHE_DEFAULT_TARGETS` env variable (comma-separated
 * paths) — used by the CLI test suite to point the walk at synthetic
 * paths without touching the real POS files on disk.
 *
 * @returns {string[]}
 */
function defaultTargets() {
  const envOverride = process.env.AUDIT_POS_LOCAL_CACHE_DEFAULT_TARGETS;
  if (envOverride && envOverride.length > 0) {
    return envOverride.split(',').map((s) => s.trim()).filter(Boolean);
  }
  return [
    'apps/pos/src/lib/db.ts',
    'apps/pos/src/lib/db/migrations.ts',
    'apps/pos/src/lib/sync/syncService.ts',
  ];
}

/**
 * @typedef {{
 *   surface: 'tauri',
 *   scanner: 'pos_sqlite_cache',
 *   stable_key: string,
 *   cluster_id: string,
 *   file: string,
 *   line: number,
 *   symbol: string,
 *   pattern_type: 'create_table_without_tenant' | 'sync_envelope_without_tenant_check',
 *   resource: string | null,
 *   expected_scope: 'tenant_and_company',
 *   expected_fix: string,
 *   severity: 'high',
 *   fiscal_path: false,
 *   cross_module: false,
 *   stale_state: 'active',
 *   reason: string,
 * }} CallsiteRow
 */

// ---------------------------------------------------------------------------
// Stable key (mirror of StableKey.php)
// ---------------------------------------------------------------------------

/**
 * @param {{
 *   surface: string,
 *   scanner: string,
 *   normalized_relative_path: string,
 *   symbol_fqn: string,
 *   ast_node_kind: string,
 *   model_or_table?: string | null,
 *   field_or_method?: string | null,
 *   normalized_argument_name?: string | null,
 *   statement_fingerprint?: string | null,
 * }} parts
 * @returns {string}
 */
export function stableKeyFromScannerOutput(parts) {
  const ordered = [
    String(parts.surface ?? ''),
    String(parts.scanner ?? ''),
    String(parts.normalized_relative_path ?? ''),
    String(parts.symbol_fqn ?? ''),
    String(parts.ast_node_kind ?? ''),
    String(parts.model_or_table ?? ''),
    String(parts.field_or_method ?? ''),
    String(parts.normalized_argument_name ?? ''),
    String(parts.statement_fingerprint ?? ''),
  ];
  const hash = createHash('sha256').update(ordered.join('\0')).digest('hex');
  return `sha256:${hash}`;
}

// ---------------------------------------------------------------------------
// AST helpers
// ---------------------------------------------------------------------------

/**
 * Walk up to find the nearest named declaration enclosing `node`. Returns the
 * declaration's name or '<module>' if none found.
 *
 * @param {ts.Node} node
 * @returns {string}
 */
function findEnclosingSymbol(node) {
  let cur = node.parent;
  while (cur) {
    if (ts.isFunctionDeclaration(cur) && cur.name) return cur.name.text;
    if (ts.isMethodDeclaration(cur) && cur.name && ts.isIdentifier(cur.name)) return cur.name.text;
    if (ts.isClassDeclaration(cur) && cur.name) return cur.name.text;
    if (ts.isVariableDeclaration(cur) && ts.isIdentifier(cur.name)) return cur.name.text;
    cur = cur.parent;
  }
  return '<module>';
}

/**
 * @param {ts.Node} node
 * @param {ts.SourceFile} sourceFile
 * @returns {number}
 */
function lineOf(node, sourceFile) {
  const { line } = sourceFile.getLineAndCharacterOfPosition(node.getStart(sourceFile));
  return line + 1;
}

// ---------------------------------------------------------------------------
// SQL CREATE TABLE detection
// ---------------------------------------------------------------------------

/**
 * Returns each `CREATE TABLE <name> (<cols>)` block found in `sql`. Uses a
 * paren-balanced scan so default expressions like `DEFAULT (CURRENT_TIMESTAMP)`
 * inside a column list don't truncate the column extraction.
 *
 * @param {string} sql
 * @returns {Array<{ tableName: string, columns: string, matchOffset: number }>}
 */
function extractCreateTableBlocks(sql) {
  /** @type {Array<{ tableName: string, columns: string, matchOffset: number }>} */
  const out = [];
  const re = /CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?["'`]?(\w+)["'`]?\s*\(/gi;
  let match;
  while ((match = re.exec(sql)) !== null) {
    const tableName = match[1].toLowerCase();
    const startCol = match.index + match[0].length;
    let depth = 1;
    let i = startCol;
    while (i < sql.length && depth > 0) {
      const ch = sql[i];
      if (ch === '(') depth++;
      else if (ch === ')') depth--;
      i++;
    }
    if (depth === 0) {
      out.push({
        tableName,
        columns: sql.slice(startCol, i - 1),
        matchOffset: match.index,
      });
    }
  }
  return out;
}

/**
 * @param {string} columns
 * @returns {{ hasTenantId: boolean, hasCompanyId: boolean }}
 */
function tenantColumnsInBlock(columns) {
  return {
    hasTenantId: /\btenant_id\b/i.test(columns),
    hasCompanyId: /\bcompany_id\b/i.test(columns),
  };
}

/**
 * @param {ts.Node} node
 * @param {string} text
 * @param {ts.SourceFile} sourceFile
 * @param {string} filePath
 * @param {CallsiteRow[]} out
 */
function checkSqlNode(node, text, sourceFile, filePath, out) {
  const blocks = extractCreateTableBlocks(text);
  if (blocks.length === 0) return;
  const symbol = findEnclosingSymbol(node);
  for (const block of blocks) {
    if (!GUARDED_TABLES.has(block.tableName)) continue;
    const { hasTenantId, hasCompanyId } = tenantColumnsInBlock(block.columns);
    if (hasTenantId && hasCompanyId) continue;
    const line = lineOf(node, sourceFile);
    const relPath = path.relative(REPO_ROOT, filePath).split(path.sep).join('/');
    const stableKey = stableKeyFromScannerOutput({
      surface: 'tauri',
      scanner: 'pos_sqlite_cache',
      normalized_relative_path: relPath,
      symbol_fqn: symbol,
      ast_node_kind: 'sqlite_create_table',
      model_or_table: block.tableName,
      field_or_method: '',
      normalized_argument_name: '',
      statement_fingerprint: 'tenant_and_company',
    });
    out.push({
      surface: 'tauri',
      scanner: 'pos_sqlite_cache',
      stable_key: stableKey,
      cluster_id: 'tauri.sqlite-cache',
      file: relPath,
      line,
      symbol,
      pattern_type: 'create_table_without_tenant',
      resource: block.tableName,
      expected_scope: 'tenant_and_company',
      expected_fix:
        'Add tenant_id and company_id NOT NULL columns to the CREATE TABLE statement and a composite index on (tenant_id, company_id).',
      severity: 'high',
      fiscal_path: false,
      cross_module: false,
      stale_state: 'active',
      reason: `CREATE TABLE ${block.tableName} missing ${[
        !hasTenantId ? 'tenant_id' : null,
        !hasCompanyId ? 'company_id' : null,
      ]
        .filter(Boolean)
        .join(' and ')} column(s) for tenant_and_company scope`,
    });
  }
}

// ---------------------------------------------------------------------------
// Sync envelope handler detection
// ---------------------------------------------------------------------------
//
// Codex round-1 finding #1 closure: the recognizer no longer accepts ANY
// reference to `envelope.tenant_id` as validation. Three real false-negatives
// it used to wave through are now flagged:
//
//   1. setupValidator(() => envelope.tenant_id); const payload = envelope.payload;
//      A capture inside a deferred callback is not a synchronous guard.
//   2. const tenantId = envelope.tenant_id; const payload = envelope.payload;
//      A bare read does not compare against the active auth context.
//   3. const { tenant_id, payload } = envelope; if (tenant_id !== ...) throw ...
//      Same-statement destructure cannot retroactively guard the payload.
//
// New rule: a top-level statement counts as a tenant guard ONLY IF
//   (a) it is an `if` whose condition references `envelope.tenant_id` at the
//       top level (NOT inside a nested function/arrow); OR
//   (b) it is an expression statement that calls one of the explicit
//       allowlisted synchronous validator helpers and passes either
//       `envelope` itself or a top-level reference to `envelope.tenant_id`.
//
// Payload access on a statement is checked BEFORE the statement is evaluated
// for guard status, so a destructure picking both `tenant_id` and `payload`
// is flagged on its own statement.

/**
 * Allowlisted synchronous validator helpers — calls to these at the top
 * level of a handler body, with `envelope` (or `envelope.tenant_id`) in the
 * argument list, count as a guard. The list is intentionally narrow:
 * default-deny on unknown helpers. Extend only by name as real validators
 * are introduced in apps/pos/src/lib/sync/.
 *
 * @type {ReadonlySet<string>}
 */
const GUARD_HELPER_NAMES = new Set([
  'assertEnvelopeTenant',
  'validateEnvelopeTenant',
  'requireTenantContext',
  'throwIfTenantMismatch',
]);

/**
 * Top-level reference to `envelope.<propName>` — descends through the node
 * subtree but stops at any nested function-like or class-body boundary, so
 * neither a deferred callback capture (`() => envelope.tenant_id`) nor a
 * reference hidden inside a class constructor / class body
 * (`class Hidden { constructor() { void envelope.tenant_id; } }`) registers
 * as a top-level reference.
 *
 * Codex round-2 finding closure: the round-1 stop list missed
 * ConstructorDeclaration and ClassDeclaration/ClassExpression, so a guard
 * hidden inside a class body inside an `if` condition was incorrectly
 * credited as validation.
 *
 * @param {ts.Node} node
 * @param {string} propName
 * @returns {boolean}
 */
function subtreeReferencesEnvelopePropAtTopLevel(node, propName) {
  if (
    ts.isFunctionExpression(node) ||
    ts.isArrowFunction(node) ||
    ts.isFunctionDeclaration(node) ||
    ts.isMethodDeclaration(node) ||
    ts.isGetAccessor(node) ||
    ts.isSetAccessor(node) ||
    ts.isConstructorDeclaration(node) ||
    ts.isClassDeclaration(node) ||
    ts.isClassExpression(node)
  ) {
    return false;
  }
  if (
    ts.isPropertyAccessExpression(node) &&
    ts.isIdentifier(node.expression) &&
    node.expression.text === 'envelope' &&
    ts.isIdentifier(node.name) &&
    node.name.text === propName
  ) {
    return true;
  }
  let found = false;
  ts.forEachChild(node, (child) => {
    if (found) return;
    if (subtreeReferencesEnvelopePropAtTopLevel(child, propName)) found = true;
  });
  return found;
}

/**
 * Does this top-level statement READ `envelope.<propName>` (via property
 * access at the top level OR via a `const { <propName>, ... } = envelope`
 * destructure)? Mirrors the older subtree heuristic for payload-access
 * detection — payload access doesn't have the function-boundary concern
 * because reading `envelope.payload` inside a nested callback is still a
 * read off the live envelope.
 *
 * @param {ts.Node} node
 * @param {string} propName
 * @returns {boolean}
 */
function statementReadsEnvelopeProp(node, propName) {
  if (
    ts.isPropertyAccessExpression(node) &&
    ts.isIdentifier(node.expression) &&
    node.expression.text === 'envelope' &&
    ts.isIdentifier(node.name) &&
    node.name.text === propName
  ) {
    return true;
  }
  if (
    ts.isVariableDeclaration(node) &&
    ts.isObjectBindingPattern(node.name) &&
    node.initializer &&
    ts.isIdentifier(node.initializer) &&
    node.initializer.text === 'envelope'
  ) {
    for (const element of node.name.elements) {
      const pickedName =
        element.propertyName && ts.isIdentifier(element.propertyName)
          ? element.propertyName.text
          : ts.isIdentifier(element.name)
            ? element.name.text
            : '';
      if (pickedName === propName) return true;
    }
  }
  let found = false;
  ts.forEachChild(node, (child) => {
    if (found) return;
    if (statementReadsEnvelopeProp(child, propName)) found = true;
  });
  return found;
}

/**
 * Is this top-level statement a tenant guard? See the rule comment above
 * for the definition; in short: an `if` referencing `envelope.tenant_id`
 * at the top level, or a top-level call to an allowlisted helper invoked
 * as a BARE `Identifier` (i.e. imported, not a method on an arbitrary
 * object) with `envelope` or a top-level reference to `envelope.tenant_id`
 * in the args.
 *
 * Codex round-2 finding closure: the round-1 recognizer pulled the
 * terminal property name from a `PropertyAccessExpression` callee, so any
 * object exposing a method whose name happened to match an allowlisted
 * validator (`tenant.assertEnvelopeTenant(envelope)`) satisfied the gate.
 * The recognizer now requires the callee to be a bare `Identifier` so the
 * match resolves through scope to a real import, not an attacker-shaped
 * method on a local object.
 *
 * @param {ts.Statement} stmt
 * @returns {boolean}
 */
function isTenantGuardStatement(stmt) {
  if (ts.isIfStatement(stmt)) {
    return subtreeReferencesEnvelopePropAtTopLevel(stmt.expression, 'tenant_id');
  }
  if (ts.isExpressionStatement(stmt) && ts.isCallExpression(stmt.expression)) {
    const callee = stmt.expression.expression;
    if (!ts.isIdentifier(callee)) return false;
    if (!GUARD_HELPER_NAMES.has(callee.text)) return false;
    for (const arg of stmt.expression.arguments) {
      if (ts.isIdentifier(arg) && arg.text === 'envelope') return true;
      if (subtreeReferencesEnvelopePropAtTopLevel(arg, 'tenant_id')) return true;
    }
  }
  return false;
}

/**
 * @param {ts.SignatureDeclaration} fnNode
 * @param {ts.SourceFile} sourceFile
 * @param {string} filePath
 * @param {CallsiteRow[]} out
 */
function analyzeEnvelopeHandler(fnNode, sourceFile, filePath, out) {
  const body = 'body' in fnNode ? fnNode.body : undefined;
  if (!body || !ts.isBlock(body)) return;

  let tenantValidated = false;
  /** @type {ts.Node | null} */
  let firstUnvalidatedPayloadNode = null;

  for (const stmt of body.statements) {
    // Check payload access FIRST — same-statement destructures of both
    // `tenant_id` and `payload` cannot retroactively guard themselves.
    if (
      !tenantValidated &&
      firstUnvalidatedPayloadNode === null &&
      statementReadsEnvelopeProp(stmt, 'payload')
    ) {
      firstUnvalidatedPayloadNode = stmt;
    }
    // Then check whether this statement is a guard for downstream
    // statements (an early `if`/throw, an early-return, or an allowlisted
    // helper call).
    if (isTenantGuardStatement(stmt)) {
      tenantValidated = true;
    }
  }

  if (!firstUnvalidatedPayloadNode) return;

  const symbol = findEnclosingSymbol(firstUnvalidatedPayloadNode);
  const line = lineOf(firstUnvalidatedPayloadNode, sourceFile);
  const relPath = path.relative(REPO_ROOT, filePath).split(path.sep).join('/');
  const stableKey = stableKeyFromScannerOutput({
    surface: 'tauri',
    scanner: 'pos_sqlite_cache',
    normalized_relative_path: relPath,
    symbol_fqn: symbol,
    ast_node_kind: 'sync_envelope_handler',
    model_or_table: '',
    field_or_method: 'payload',
    normalized_argument_name: 'envelope',
    statement_fingerprint: 'tenant_and_company',
  });
  out.push({
    surface: 'tauri',
    scanner: 'pos_sqlite_cache',
    stable_key: stableKey,
    cluster_id: 'tauri.sync-envelope',
    file: relPath,
    line,
    symbol,
    pattern_type: 'sync_envelope_without_tenant_check',
    resource: null,
    expected_scope: 'tenant_and_company',
    expected_fix:
      'Validate envelope.tenant_id (and envelope.company_id) against the active auth context BEFORE accessing envelope.payload.',
    severity: 'high',
    fiscal_path: false,
    cross_module: false,
    stale_state: 'active',
    reason: `Sync envelope handler ${symbol} accesses envelope.payload before validating envelope.tenant_id`,
  });
}

// ---------------------------------------------------------------------------
// Source-level scan
// ---------------------------------------------------------------------------

/**
 * @param {ts.SourceFile} sourceFile
 * @param {string} filePath
 * @returns {CallsiteRow[]}
 */
export function scanSource(sourceFile, filePath) {
  /** @type {CallsiteRow[]} */
  const violations = [];

  /**
   * @param {ts.Node} node
   */
  function visit(node) {
    if (ts.isStringLiteral(node) || ts.isNoSubstitutionTemplateLiteral(node)) {
      checkSqlNode(node, node.text, sourceFile, filePath, violations);
    } else if (ts.isTemplateExpression(node)) {
      // Conservative: scan only the head; CREATE TABLE rarely interpolates
      // into the table name or column list. If a real-world target needs
      // span scanning we can expand this later.
      checkSqlNode(node, node.head.text, sourceFile, filePath, violations);
    }

    if (
      ts.isFunctionDeclaration(node) ||
      ts.isFunctionExpression(node) ||
      ts.isArrowFunction(node) ||
      ts.isMethodDeclaration(node)
    ) {
      const firstParam = node.parameters && node.parameters[0];
      if (firstParam && ts.isIdentifier(firstParam.name) && firstParam.name.text === 'envelope') {
        analyzeEnvelopeHandler(node, sourceFile, filePath, violations);
      }
    }

    ts.forEachChild(node, visit);
  }

  visit(sourceFile);
  return violations;
}

/**
 * Convenience wrapper for tests.
 *
 * @param {string} code
 * @param {string} [filename]
 * @returns {CallsiteRow[]}
 */
export function scanCode(code, filename = '<inline>') {
  const sourceFile = ts.createSourceFile(
    filename,
    code,
    ts.ScriptTarget.ES2022,
    /* setParentNodes */ true,
    /\.tsx$/.test(filename) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  return scanSource(sourceFile, filename);
}

// ---------------------------------------------------------------------------
// CLI entrypoint
// ---------------------------------------------------------------------------

/**
 * @param {string[]} argv
 * @returns {{ files: string[], emitInventoryRows: boolean, allowMissingDefaultTargets: boolean }}
 */
function parseArgs(argv) {
  const files = [];
  let emitInventoryRows = false;
  let allowMissingDefaultTargets = false;
  for (const arg of argv) {
    if (arg === '--emit-inventory-rows') {
      emitInventoryRows = true;
      continue;
    }
    if (arg === '--allow-missing-default-targets') {
      allowMissingDefaultTargets = true;
      continue;
    }
    if (arg.startsWith('--')) {
      throw new Error(`Unknown flag: ${arg}`);
    }
    files.push(arg);
  }
  return { files, emitInventoryRows, allowMissingDefaultTargets };
}

/**
 * @param {string} filePath
 * @returns {Promise<CallsiteRow[]>}
 */
async function scanFile(filePath) {
  const code = await fs.readFile(filePath, 'utf8');
  const sourceFile = ts.createSourceFile(
    filePath,
    code,
    ts.ScriptTarget.ES2022,
    /* setParentNodes */ true,
    /\.tsx$/.test(filePath) ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  return scanSource(sourceFile, filePath);
}

const isMain = (() => {
  if (typeof process === 'undefined' || !Array.isArray(process.argv) || process.argv.length < 2) {
    return false;
  }
  const invokedPath = path.resolve(process.argv[1] ?? '');
  const thisPath = fileURLToPath(import.meta.url);
  return invokedPath === thisPath;
})();

if (isMain) {
  const { files, emitInventoryRows, allowMissingDefaultTargets } = parseArgs(process.argv.slice(2));
  const usingDefaultTargets = files.length === 0;
  const targets = usingDefaultTargets
    ? defaultTargets().map((rel) => (path.isAbsolute(rel) ? rel : path.join(REPO_ROOT, rel)))
    : files;

  /** @type {CallsiteRow[]} */
  const allViolations = [];
  for (const target of targets) {
    try {
      const fileViolations = await scanFile(target);
      allViolations.push(...fileViolations);
    } catch (err) {
      // Codex round-1 finding #2: a silently-skipped missing default target
      // can hide a renamed POS surface from the gate. Default behavior now
      // ERRORS on ENOENT in any mode; the older silent-skip is opt-in via
      // --allow-missing-default-targets for branch-portability use cases.
      if (err && typeof err === 'object' && /** @type {NodeJS.ErrnoException} */ (err).code === 'ENOENT') {
        if (usingDefaultTargets && allowMissingDefaultTargets) {
          process.stderr.write(`audit-pos-local-cache: skipping missing default target ${target}\n`);
          continue;
        }
        process.stderr.write(`audit-pos-local-cache: cannot read ${target}\n`);
        process.exit(2);
      }
      throw err;
    }
  }

  if (emitInventoryRows) {
    for (const v of allViolations) {
      const { reason: _reason, ...row } = v;
      process.stdout.write(`${JSON.stringify(row)}\n`);
    }
    process.exit(0);
  }

  for (const v of allViolations) {
    process.stderr.write(`${v.file}:${v.line} ${v.pattern_type} ${v.reason}\n`);
  }
  process.stderr.write(
    `[sweep-progress] Phase 2B.2 — POS local-cache tenant gaps: ${allViolations.length}\n`,
  );
  process.exit(allViolations.length === 0 ? 0 : 1);
}
