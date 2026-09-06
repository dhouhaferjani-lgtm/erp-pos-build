# HANDBACK — lane `build-fingerprint` (2026-09-07)

Brief: `docs/superpowers/plans/2026-09-07-build-fingerprint-web.md` (rev 1).
Branch `lane/build-fingerprint`, worktree `.worktrees/build-fingerprint`, off local `dev`
(`0c7bd49fb2ef2138d30786927954b16418fcf99c`). **Not merged, not pushed.**

Commits (oldest first):

| sha | subject |
|---|---|
| `ec4882117` | `feat(web): emit dist/build-fingerprint.json after vite build` |
| `1dccdbef0` | `feat(web): pass BUILD_SHA into the image and serve /build-fingerprint.json no-store` |
| `1042530b2` | `docs(staging-manifest): close U-4, add the §3 fingerprint block, open U-9` |

Reviewer per the brief: `frontend-conventions-reviewer` (code gate) — **not yet run**.

---

## 1. What changed

### Scope 1 — generator (new)

`apps/web/tools/write-build-fingerprint.mjs` (249 lines, ESM, `// @ts-check`, no `__dirname`).

| what | where |
|---|---|
| `SCHEMA_VERSION = 1`, `UNKNOWN = 'unknown'` | `:49`, `:52` |
| `SHA_ENV_VARS` precedence list | `:55` |
| `ROUTES_MANIFEST_RELATIVE = '../../scripts/factory/manifests/routes-web.yaml'` | `:58` |
| `readGitSha()` — `git rev-parse HEAD`, `null` on any failure | `:67-79` |
| `resolveBuildSha(env, gitSha)` — env → git → `"unknown"`, trims, treats whitespace-only as absent | `:88-103` |
| `resolveProduct(env)` — `VITE_APP_PRODUCT`, default `izipos` | `:108-111` |
| `parseRoutePaths(yamlText)` — line scan of `- path: …` | `:122-131` |
| `computeFeatureFingerprint(yamlText)` — sha256 of sorted paths joined by `\n`, first 16 hex | `:141-149` |
| `buildFingerprintPayload({env, manifestText, now, gitSha})` | `:160-171` |
| `parseArgs` (`--print-fingerprint`, `--out <path>`) | `:177-193` |
| `main(argv, env, webRoot)` — reads manifest, CI guard, writes / prints | `:199-232` |
| `invokedDirectly()` guard | `:236-245` |

Design points worth the reviewer's attention:

- **Never imported by the app.** Written to `dist/` *after* `vite build` so the sha is outside
  the hashed asset graph — importing it would bake the sha into the bundle hash and destroy the
  very stale-bundle signal the file exists to provide (brief scope item 4).
- **`"unknown"` is explicit, never fabricated.** Both `build_sha` and `feature_fingerprint`
  degrade to the literal string, and a missing route manifest exits 1 when `CI` is set.
- **Fingerprint is order-independent** (paths are sorted before hashing), so it changes only when
  the route *set* changes — not when the generator reorders its output.
- **YAML is line-scanned, not parsed.** `routes-web.yaml` is machine-generated with a fixed
  `  - path: /x` shape (`scripts/factory/gen-route-manifest.mjs`); this keeps the build step
  dependency-free. Documented at `:117-121`.
- **`invokedDirectly()` is wrapped in try/catch** because vitest's `environment: 'jsdom'`
  (`apps/web/vitest.config.ts:10`) rewrites `import.meta.url` to a browser URL that
  `fileURLToPath` rejects. Same reason the test resolves paths from `process.cwd()`, matching the
  sibling `permission-map-drift-guard.test.mjs:7`.

### Scope 2 — build wiring

- `apps/web/package.json:8` — `"build": "tsc -b && vite build && node tools/write-build-fingerprint.mjs"`.
- `apps/web/Dockerfile:63-74` — `ARG BUILD_SHA` + `ENV BUILD_SHA=$BUILD_SHA` beside the existing
  Vite args, with the Dokploy comment naming U-9.
- `apps/web/Dockerfile:47-53` — **`COPY scripts/factory/manifests/ ./scripts/factory/manifests/`.**
  Not in the brief; added because the builder stage copies `apps/web/` and `packages/` only, so
  the route manifest was **absent from the image** — the container would have shipped
  `"feature_fingerprint": "unknown"` while every local build looked correct. See §4.
- `apps/web/Dockerfile:78-83` — `RUN CI=true node tools/write-build-fingerprint.mjs` after
  `pnpm build`, so a missing manifest fails the image build instead of degrading silently.
  Scoped to that command; `CI` is deliberately not set for the vite build itself.

### Scope 3 — serving

`apps/web/docker/entrypoint.sh:171-180`, declared **before** `location /` (`:182-185`):

```nginx
    location = /build-fingerprint.json {
        add_header Cache-Control "no-store, no-cache, must-revalidate" always;
        default_type application/json;
        try_files \$uri =404;
    }
```

- The file is written inside an **unquoted** heredoc (`entrypoint.sh:38 … :199`), so `$` is
  escaped as `\$` per the house style at `:182-185`. **No backticks** — in an unquoted heredoc
  they would be command substitution.
- `try_files $uri =404` rather than the SPA fallback: a missing file must 404, not return a
  200 `index.html` body that would silently break `jq` on the caller side.

### Scope 4 — no app-side read

Nothing imports the file. Confirmed: `grep -rn "build-fingerprint" apps/web/src` → 0 hits.

### Scope 5 — manifest update

`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md`

- **§3** (`:221-240`): the sentence "There is **no** `/build-fingerprint.json` in this repo (§6
  U-4); do not plan around one." is replaced by the **Build fingerprint (preferred, two lines)**
  block with the `curl`/`jq` commands from the brief. Added, beyond the brief's wording: a note
  that `feature_fingerprint` is a hash of the route *set*, so a slice that adds no route
  legitimately leaves it unchanged — `build_sha` is the freshness signal, `feature_fingerprint`
  the shape signal. A reviewer treating an unchanged fingerprint as a failed deploy would
  otherwise block correct pushes. The asset-hash + grep pair is retained as the **mandatory**
  fallback while U-9 is open.
- **§6 U-4** (`:317`): struck through, closed, with implementing `file:line` citations.
- **§6 U-9** (`:322`): new — "Dokploy passes a `BUILD_SHA` build argument"; verification is
  `curl … | jq -r '.build_sha'` after one deploy, MUST be the deployed sha, not `"unknown"`;
  remedy names the web application id `mY6P_PHb4pw-2LdG1Y7Ml`.
- **§6 U-8** (`:321`): the healthcheck citation `apps/web/Dockerfile:80` → `:107-108`, since this
  lane's edits shifted it.

### Scope 6 — tests (red first)

`apps/web/tools/__tests__/write-build-fingerprint.test.mjs` — 21 tests.
Fixtures `apps/web/tools/__fixtures__/routes-web.sample.yaml` (deliberately unsorted) and
`routes-web.sorted.yaml` (same three routes, sorted).

The expected digest `dceb1e43945c5997` was computed **independently of the implementation**
before it existed, and is pinned in the test at `:27`:

```
printf '/\n/inventory/lots\n/pos' | shasum -a 256 | cut -c1-16
```

Coverage: env precedence incl. whitespace-only and trimming (6) · product default (2) ·
route parsing incl. the *real* checked-in manifest (3) · fingerprint determinism,
order-independence, change-on-new-route, null manifest (4) · payload shape and ISO-8601
`build_time` (2) · CLI as **real child processes** — `--print-fingerprint` prints only the hash
and writes nothing, `--out` writes the JSON, missing manifest is `unknown`+exit 0 locally, and
exit 1 under `CI=true` (4).

---

## 2. Commands and results

TDD red-first, before the implementation existed:

```
$ npx vitest run tools/__tests__/write-build-fingerprint.test.mjs
Error: Failed to resolve import "../write-build-fingerprint.mjs" ... Does the file exist?
 Test Files  1 failed (1)
      Tests  no tests
```

Green, and the brief's verification block:

```
$ cd apps/web && npx vitest run tools/__tests__/write-build-fingerprint.test.mjs
 Test Files  1 passed (1)
      Tests  21 passed (21)

$ npx vitest run tools/__tests__            # == pnpm test:tools
 Test Files  9 passed (9)
      Tests  210 passed (210)

$ npx tsc --noEmit                          # == pnpm typecheck
typecheck exit=0                            # no output

$ npx eslint tools/write-build-fingerprint.mjs tools/__tests__/write-build-fingerprint.test.mjs
eslint exit=0                               # no output

$ npx pnpm build
✓ built in 7.28s
write-build-fingerprint: wrote .../apps/web/dist/build-fingerprint.json
  (build_sha=0c7bd49fb2ef2138d30786927954b16418fcf99c, feature_fingerprint=42eb6b3a2089326d, route_count=271)
```

Extra verification beyond the brief:

```
$ sh -n apps/web/docker/entrypoint.sh                       # SYNTAX OK

# heredoc rendered with dummy env, then validated in the real image:
$ docker run --rm -v .../docker/nginx.conf:/etc/nginx/nginx.conf:ro \
      -v /tmp/fp-nginx/conf.d/default.conf:/etc/nginx/conf.d/default.conf:ro \
      nginx:alpine nginx -t
nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
nginx: configuration file /etc/nginx/nginx.conf test is successful
# and the rendered block has $uri UNESCAPED, as required:
    location = /build-fingerprint.json {
        add_header Cache-Control "no-store, no-cache, must-revalidate" always;
        default_type application/json;
        try_files $uri =404;
    }

# container layout simulated (/app/apps/web + /app/scripts/factory/manifests, NO .git):
$ env -i PATH=$PATH BUILD_SHA=deadbeefcafe1234 CI=true node tools/write-build-fingerprint.mjs
write-build-fingerprint: wrote /private/tmp/fp-layout/app/apps/web/dist/build-fingerprint.json
  (build_sha=deadbeefcafe1234, feature_fingerprint=42eb6b3a2089326d, route_count=271)
# negative case — manifest NOT copied into the image:
write-build-fingerprint: route manifest not found at .../routes-web.yaml ... Failing because CI is set.
exit=1

$ npx react-doctor --no-supply-chain --blocking warning <the two new files>
Score: 100 / 100 Great — ✔ No issues found!
```

Cross-checks that matter for manifest §3:

```
$ cat apps/web/dist/build-fingerprint.json
{
  "schema": 1,
  "build_sha": "0c7bd49fb2ef2138d30786927954b16418fcf99c",
  "build_time": "2026-09-06T20:58:38.798Z",
  "product": "izipos",
  "feature_fingerprint": "42eb6b3a2089326d",
  "route_count": 271
}

$ git rev-parse HEAD                                       # at build time
0c7bd49fb2ef2138d30786927954b16418fcf99c                   # == build_sha ✓ (git fallback works locally)

$ node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint
42eb6b3a2089326d                                           # == feature_fingerprint ✓, and cwd-independent

$ git status --porcelain dist                              # empty — dist/ is gitignored ✓
```

The simulated-image run produced the **same** `42eb6b3a2089326d` as the host build, which is the
property manifest §3 depends on: the fingerprint is reproducible from the checked-in manifest
regardless of environment.

---

## 3. Not done, with reasons

- **Full `docker build -f apps/web/Dockerfile --build-arg BUILD_SHA=… -t web-fp .`** — NOT RUN.
  The brief gates it on laptop swap < 9 GB; swap was **9.10 GB used of 10.24 GB** at that point
  (it had grown from 8.34 GB during the session, and the `pnpm build` alone pushed it to 9.38 GB).
  Substituted: `sh -n`, a real `nginx -t` in `nginx:alpine` on the rendered config, and the
  simulated container layout above (both the success and the CI=true failure path). **What
  remains genuinely unproven is only that the two new `COPY`/`RUN` layers build** — the path
  resolution they depend on is proven. Worth one `docker build` on a rebooted laptop before
  promotion.
- **`pnpm lint` in full** — NOT RUN (heavy, per the lane instruction). `eslint` on the two touched
  files and the full `test:tools` suite were run instead. Note: `apps/web/eslint.config.js` has no
  `**/*.mjs` block, so `.mjs` files match no rule set — the clean eslint exit is real but weak;
  `react-doctor` was run on the two files as a stronger substitute.
- **Backend** — untouched. No PHPUnit run (correct: no backend change).
- **`frontend-conventions-reviewer` gate** — not run; the brief assigns it as the code gate and
  this lane does not self-gate.
- **Merge / push** — not done, per instruction.
- **CI wiring** — the generator is not added to `scripts/preflight.sh` or `.github/workflows/ci.yml`.
  Out of the brief's scope 1-6. Worth a follow-up: a CI step running
  `node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint` would catch a corrupted
  route manifest before a deploy does.
- **Stale `apps/web/Dockerfile:NN` citations in OTHER documents.** This lane's Dockerfile edits
  shifted line numbers, invalidating pre-existing citations in files outside lane scope (rule 4).
  I fixed only the one inside the manifest I was asked to edit (U-8). Still stale elsewhere:
  `docs/superpowers/plans/2026-08-05-production-environment-design.md:367,529,1846,2406,2757,2758`
  (`:47-55`, `:66`, `:51`), `docs/superpowers/plans/2026-09-06-w-lot-b-…-execution-plan.md:717`
  (`:47-55`), `docs/superpowers/audits/2026-08-30-otospex-readiness/{07-vertical-boundedness.md:33,148,
  06-synthesis-verification.md:62, 02-onboarding-journey.md:25}` (`:53`),
  `docs/superpowers/reviews/2026-09-06-w-lot-a-plan-codex-gate-r2.md:148` (`:50`),
  `docs/superpowers/reviews/2026-09-06-w-cash-plan-codex-gate-r5.md:104` (`:50`),
  `docs/superpowers/reviews/2026-08-05-production-env-design-review*.md` (`:47-55`).
  All are historical records; recommend leaving them.
- **Worktree `node_modules`** are symlinks to the main checkout
  (`node_modules`, `apps/web/node_modules`, `packages/shared/node_modules`). Untracked-ignored;
  delete them with the worktree.
- `apps/web/tsconfig.tsbuildinfo` is **tracked** and was dirtied by the build; restored with
  `git checkout --` and deliberately kept out of every commit (rule 10, build artifacts).

---

## 4. One decision that departs from the brief — please confirm

The brief's scope 2 said only "`ARG BUILD_SHA` + `ENV BUILD_SHA` next to the existing Vite args".
Implementing exactly that would have shipped a **broken** fingerprint: the builder stage copies
`apps/web/` and `packages/` only, so `scripts/factory/manifests/routes-web.yaml` — the sole input
to `feature_fingerprint` — is not in the image. Every local build would show a correct hash while
the deployed container served `"feature_fingerprint": "unknown"`, i.e. exactly the silent
degradation this lane exists to prevent, and manifest §3's second check would be permanently dead.

Two lines were therefore added to the Dockerfile: the manifest `COPY` (`:53`) and the
`RUN CI=true node tools/write-build-fingerprint.mjs` re-run (`:83`) that makes the manifest's
absence a hard build failure rather than a silent `"unknown"`. Both stay inside the brief's
documented contract (no new flags, `CI=true` is the brief's own guard). Flagging it because it is
scope the brief did not name.

---

## 5. Deployment note (web block only, manifest §3 — no API push)

1. Add **`BUILD_SHA`** to the Dokploy web application's Build Args (`mY6P_PHb4pw-2LdG1Y7Ml`),
   set to the deployed commit sha. **Until this is done U-9 is open** and the served
   `build_sha` will read `"unknown"` — the asset-hash + grep fallback stays mandatory.
2. Deploy, then:
   ```bash
   curl -s https://erp.otospex.dev/build-fingerprint.json | jq
   ```
   `build_sha` MUST equal the deployed sha; `feature_fingerprint` MUST equal
   `node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint` at that sha.
3. Also confirm the response carries `Cache-Control: no-store, no-cache, must-revalidate`
   (`curl -sI`) — a cached fingerprint is worse than none.
