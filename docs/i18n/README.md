# i18n EN/FR translation sweep

Goal: a fully EN/FR-translated frontend (`apps/web`, `apps/pos`). No
user-facing string should bypass `react-i18next` `t()`, and every `en` key
must have a real `fr` counterpart.

## Source of truth

- **`docs/i18n/i18n-tracker.yaml`** — generated checklist. For each app it
  records FR parity gaps (missing keys, byte-identical = candidate
  untranslated, stray FR keys) and the backlog of hardcoded string literals
  grouped into **clusters** (one per feature dir). Regenerate any time:

  ```bash
  node scripts/i18n-audit.mjs          # refresh tracker + print summary
  node scripts/i18n-audit.mjs --check  # exit 1 if any active cluster still has candidates
  ```

  Only the `status:` field per cluster is hand-maintained
  (`pending` → `in_progress` → `done`; internal-only surfaces are `deferred`).
  The internal super-admin panel (`web:admin`) is deferred — it is staff-only,
  not customer-facing.

## Regression guard

`local/no-untranslated-literal` (ESLint, `apps/{web,pos}/eslint-rules/`) flags
user-facing literals (JSX text + `placeholder`/`title`/`alt`/`aria-label`/`label`)
that aren't wrapped in `t()`.

- **WARN** on the legacy surface — counted by the lint-warning ratchet
  (`scripts/lint-ratchet.mjs`, run in CI via `pnpm lint:ratchet`). The count
  may only shrink; a new hardcoded literal raises it and fails CI.
- **ERROR** for cleaned dirs — once a cluster is `done`, its glob is added to
  the i18n-clean override block in `eslint.config.js` so it can never regress.

After cleaning a cluster, lock the gain in:

```bash
node scripts/lint-ratchet.mjs --update-baseline
```

## Per-cluster workflow

1. Set the cluster `status: in_progress` in the tracker.
2. For each file, replace literals with `t('namespace:key')`; add the key to
   `en/<namespace>.json` **and** a real translation to `fr/<namespace>.json`
   (key naming: `{namespace}.{feature}.{element}` — see
   `apps/erp/.claude/context/i18n.md`).
3. Promote the cluster's dir to ERROR in `eslint.config.js`.
4. `pnpm --filter @autoerp/<app> typecheck` + `lint` + targeted tests green.
5. `node scripts/i18n-audit.mjs` → confirm the cluster drops to 0; set
   `status: done`; `--update-baseline`.
