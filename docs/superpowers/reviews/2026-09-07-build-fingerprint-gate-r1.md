# Gate verdict: MERGE-WITH-FIXES

Lane `build-fingerprint` · branch `lane/build-fingerprint` · worktree `.worktrees/build-fingerprint`
Reviewed 2026-09-07 by `frontend-conventions-reviewer` (code gate, r1).
Range `dev..HEAD` = 4 commits (`ec4882117`, `1dccdbef0`, `1042530b2`, `f1facb630`); base
`0c7bd49fb2ef2138d30786927954b16418fcf99c`; local `dev` has since advanced 4 commits with **no
overlap** on any lane-touched file (verified below) — merge is conflict-free.

Brief: `docs/superpowers/plans/2026-09-07-build-fingerprint-web.md` (rev 1).
Handback: `docs/handoff/HANDBACK-build-fingerprint-2026-09-07.md` (worktree).

The handback's claims were re-derived from code, not accepted. Everything it asserts that I could
check independently held, including the two things most likely to be wrong in a lane whose docker
build was never run: the manifest COPY path and the fingerprint's reproducibility.

---

## Findings

### 1. MAJOR — the repo's own compose files build `web` and do **not** forward `BUILD_SHA`; U-9's remedy names only the Dokploy UI

`apps/web/Dockerfile:73-74` declares `ARG BUILD_SHA` / `ENV BUILD_SHA=$BUILD_SHA`, and the builder
stage has no `.git`, so the sha can only arrive as a build argument. But both compose files that
build this image pass an **explicit** `args:` map, and docker compose forwards *only* the args listed
there:

- `docker-compose.staging.yml:265-269`
  ```yaml
      build:
        context: .
        dockerfile: apps/web/Dockerfile
        args:
          VITE_API_URL: ${APP_URL:-https://api.erp.otospex.dev}
          VITE_APP_PRODUCT: ${VITE_APP_PRODUCT:-izipos}
  ```
- `docker-compose.dokploy.yml:251-255` — same shape, `VITE_API_URL` only.

So on the compose-shaped deploy path `build_sha` will read `"unknown"` **no matter what is
configured in the Dokploy UI**. This matters precisely because the manifest's own U-1
(`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:312`) records that whether
staging is compose-shaped or application-shaped is itself UNVERIFIED. The new U-9 row
(`…manifest.md:322`) frames the entire remedy as "add `BUILD_SHA` to the web application's Build Args
in Dokploy (application id `mY6P_PHb4pw-2LdG1Y7Ml`)" — that instruction is insufficient on one of the
two candidate paths, and an operator following it would see `"unknown"` persist and have no next step.

This is not a correctness bug (the file degrades honestly and §3 keeps the asset-hash + grep pair as
the mandatory fallback), which is why it is MAJOR rather than BLOCKER. It is a two-line, in-repo fix
that turns the lane's headline signal from "hopefully configured out of band" to "wired".

**Fix:** add `BUILD_SHA: ${BUILD_SHA:-}` to the `args:` map at `docker-compose.staging.yml:267` and
`docker-compose.dokploy.yml:254`, and extend the U-9 remedy at `…manifest.md:322` to: "if the deploy is
compose-shaped, the arg must also be listed in the compose `args:` map (now present) and `BUILD_SHA`
exported in the deploy environment; if application-shaped, add it to the Dokploy Build Args."

### 2. MINOR — the `CI=true` hard-fail catches a *missing* manifest, not a *degraded* one

`apps/web/tools/write-build-fingerprint.mjs:205-214`: the CI guard lives inside the `catch` around
`readFileSync`. If the manifest exists but parses to zero routes — e.g. a future
`scripts/factory/gen-route-manifest.mjs` change that quotes paths or reindents — `parseRoutePaths`
(`:122-131`) returns `[]`, `computeFeatureFingerprint` (`:141-149`) returns `UNKNOWN`, and both
`pnpm build` and the Dockerfile's `RUN CI=true node tools/write-build-fingerprint.mjs`
(`apps/web/Dockerfile:83`) exit **0**. The image ships `"feature_fingerprint": "unknown"` silently —
the exact degradation the `RUN` line exists to prevent.

Partially mitigated: the real-manifest assertion at
`apps/web/tools/__tests__/write-build-fingerprint.test.mjs:115-120` (`length > 50`) goes red on a
format change, and `test:tools` is inside `pnpm lint` (`apps/web/package.json:10,13`). Hence MINOR.

**Fix:** in `main()` after `:216`, add
`if ((env.CI ?? '').trim() !== '' && payload.feature_fingerprint === UNKNOWN) { process.stderr.write('write-build-fingerprint: feature_fingerprint is "unknown" (manifest parsed to 0 routes). Failing because CI is set.\n'); return 1 }`.

### 3. MINOR — the nginx comment states a mechanism that is not how nginx works

`apps/web/docker/entrypoint.sh:173-175`: "Declared BEFORE `location /` so the SPA try_files fallback
can never turn a missing file into index.html". nginx selects an exact-match location
(`location = /build-fingerprint.json`, `:176`) before any prefix location regardless of declaration
order, so the ordering is cosmetic; the protection comes from `=` plus `try_files \$uri =404` (`:179`).
The behaviour is right, the stated reason is wrong — and a wrong mechanism in a comment propagates
into the next person's config edit.

**Fix:** reword `:173-175` to "an exact-match (`=`) location wins over the `location /` prefix
regardless of order; `try_files \$uri =404` (not the SPA fallback) is what keeps a missing file a 404
instead of a 200 index.html body."

### 4. MINOR — the new location silently drops the four server-level security headers

`apps/web/docker/entrypoint.sh:64-67` sets `X-Frame-Options`, `X-Content-Type-Options "nosniff"`,
`X-XSS-Protection` and `Referrer-Policy` at server level. nginx `add_header` inheritance is
all-or-nothing per level: because the new block declares its own `add_header` (`:177`), none of the
four are emitted for `/build-fingerprint.json`. This is the same pre-existing pattern as the `.mjs`,
static-asset, image and `.html` blocks (`:74-99`), so it is house style rather than new drift — but for
a JSON endpoint that a script will `jq`, losing `nosniff` is worth one line.

**Fix:** add `add_header X-Content-Type-Options "nosniff" always;` inside the block at
`apps/web/docker/entrypoint.sh:177`.

### 5. INFO — `product` accepts any string while the contract promises an enum

`apps/web/tools/write-build-fingerprint.mjs:22` documents `"product": "izipos" | "otospex"`, but
`resolveProduct` (`:108-111`) returns any non-empty trimmed value. A typo'd `VITE_APP_PRODUCT` ships
into the JSON unremarked. The field is informational only, so INFO. Optional fix: whitelist
`['izipos','otospex']`, else fall back to `izipos` with a stderr warning.

### 6. INFO — `// @ts-check` on the new file is enforced by no gate

`apps/web/tsconfig.json` ends with `"include": ["src"]` and sets neither `allowJs` nor `checkJs`, so
`pnpm typecheck` (`= tsc --noEmit`, `apps/web/package.json:20`) does not see `tools/*.mjs`; and every
`files:` glob in `apps/web/eslint.config.js` (`:70`, `:214`, `:250`, `:295`, `:338`, `:364`, `:386`,
`:416`, `:422`, `:437`) targets `.ts/.tsx`, so `eslint .` matches no rule set for `.mjs`. The handback
declares this honestly. It is the existing house pattern — `apps/web/tools/audit-tanstack-keys.mjs:2`
and `audit-design-system.mjs:2` carry the same decorative pragma — so it is not this lane's debt to
repay. Recorded so nobody reads the clean eslint/typecheck exits as evidence about this file.

### 7. INFO — `docker build` genuinely not run; residual risk correctly scoped

Declared in the handback §3; the brief gated it on swap < 9 GB and swap was 9.10 GB. I re-derived the
two things that build would have proven and both hold independently (see Commands): the manifest lands
at exactly the path the generator resolves, and the fingerprint is byte-identical when computed by an
unrelated shell pipeline. What remains unproven is only that the two new layers (`Dockerfile:53`,
`:83`) execute — worth one `docker build -f apps/web/Dockerfile --build-arg BUILD_SHA=$(git rev-parse HEAD) -t web-fp .`
on a rebooted laptop before the staging deploy, not before the merge.

---

## Verified good (checked against code, not accepted from the handback)

- **Manifest COPY path is correct.** `apps/web/Dockerfile:53` `COPY scripts/factory/manifests/ ./scripts/factory/manifests/`
  executes with `WORKDIR /app` (set `:28`, not changed until `:56`) → `/app/scripts/factory/manifests/`.
  The generator resolves `/app/apps/web` + `../../scripts/factory/manifests/routes-web.yaml`
  (`write-build-fingerprint.mjs:58,201,247`) → the same path. Source exists at repo root
  (`scripts/factory/manifests/routes-web.yaml`, 271 route lines). Build context is the repo root per
  `Dockerfile:6-13` and both compose files (`context: .`). There is **no root `.dockerignore`**;
  `apps/web/.dockerignore` is inert for a root-context build (BuildKit's per-Dockerfile form would be
  `apps/web/Dockerfile.dockerignore`) and in any case does not exclude `scripts/`.
- **Production stage still ships the JSON.** `Dockerfile:101` `COPY --from=builder /app/apps/web/dist /usr/share/nginx/html`,
  and `apps/web/vite.config.ts` has no `outDir` override → `/usr/share/nginx/html/build-fingerprint.json`,
  which is what `root /usr/share/nginx/html` (`entrypoint.sh:54`) + `try_files \$uri` resolves.
- **Heredoc escaping matches house style.** The block is inside the unquoted `<<EOF` opened at
  `entrypoint.sh:38` and closed at `:199`; `try_files \$uri =404` (`:179`) escapes `$` exactly like
  `location /` at `:184`, and contains no backticks.
- **Ordering is before `location /`** (`:176` vs `:183`) as the brief asked, and `no-store` is present (`:177`).
- **`unknown` is never a fabricated sha.** `resolveBuildSha:88-102` returns the literal only after env
  and git both fail; `ENV BUILD_SHA=$BUILD_SHA` with no arg yields `""`, treated as absent (`:90-91`).
  `execFileSync('git',…)` is wrapped (`:67-78`) — important because `node:22-alpine` has **no git**, so
  the fallback path throws ENOENT and is swallowed rather than failing the image build.
- **`--print-fingerprint` prints only the hash and writes nothing** (`:218-221`, asserted at test `:204-211`).
- **cwd independence is real, not asserted.** Ran the generator from `/` and from the repo root: both
  printed `42eb6b3a2089326d`.
- **Sorted-route determinism is real.** An independent pipeline
  (`grep -E '^\s*-\s+path:' … | sed … | sort | shasum -a 256 | cut -c1-16`) reproduces
  `42eb6b3a2089326d`, and the fixture digest pinned at test `:27` (`dceb1e43945c5997`) reproduces from
  `printf '/\n/inventory/lots\n/pos' | shasum -a 256 | cut -c1-16`. The test fixtures are a genuine
  unsorted/sorted pair (`tools/__fixtures__/routes-web.sample.yaml:8,12,16` vs `routes-web.sorted.yaml:7,11,15`),
  so the order-independence test at `:131-135` is not tautological.
- **The §3 fingerprint check is not overstated.** It claims equality with
  `--print-fingerprint` at CANDIDATE_SHA, which holds only if the checked-in manifest is fresh — and
  that is enforced by `scripts/factory/check-manifest-drift.sh`, wired into `scripts/preflight.sh:221`
  and `.github/workflows/ci.yml:2616`. The added caveat that an unchanged `feature_fingerprint` is
  legitimate for a route-less slice is correct and prevents a false "failed deploy" call.
- **Build wiring runs after vite and nothing imports the JSON.** `apps/web/package.json:8`
  `tsc -b && vite build && node tools/write-build-fingerprint.mjs`; `grep -rn "build-fingerprint"`
  over `apps/web/src`, `apps/web/index.html` and all `.ts/.tsx` under `apps/web` → 0 hits, so the sha
  stays outside the hashed asset graph (brief scope 4).
- **The new test is inside the lint gate**: `test:tools` = `vitest run tools/__tests__`
  (`apps/web/package.json:13`), invoked by `lint` (`:10`).
- **Manifest edits are accurate and surgical.** Every citation re-checked against the post-diff files:
  `package.json:8` ✓, `entrypoint.sh:171-180` ✓ (block spans exactly those lines), `Dockerfile:63-74` ✓,
  `:53` ✓, `:73-74` ✓, and the U-8 healthcheck correction to `:107-108` ✓. Only U-4, U-8 and U-9 rows
  are touched; U-1/U-2/U-3/U-5/U-6/U-7 are byte-identical.
- **Tracked-file hygiene clean.** `apps/web/tsconfig.tsbuildinfo` appears in no commit; no `dist/`
  artefact committed; working tree clean; commits are path-scoped and coherent (test+generator, then
  image+serving, then docs, then handback). No `src/` change → no i18n, PageHeader, design-token,
  `tenantScopedKey`, form-atom or quantity-precision surface is touched; no CLAUDE.md rule engaged
  beyond rule 10 (build artefacts), which is satisfied.
- **Owner-ruled UI principles**: not engaged — this lane ships no user-visible surface.

---

## Commands run (this review, in the lane worktree unless stated)

```
$ sysctl vm.swapusage
vm.swapusage: total = 10240.00M  used = 8734.81M → 8926.81M free = 1313.19M   # laptop loaded

$ git log --oneline dev..HEAD
f1facb630 / 1042530b2 / 1dccdbef0 / ec4882117            # 4 commits, expected set

$ git diff dev...HEAD --stat
9 files changed, 895 insertions(+), 4 deletions(-)        # no src/, no dist/, no tsbuildinfo

$ git rev-list --count dev..HEAD; git rev-list --count HEAD..dev
ahead=4 behind=4
$ git diff $(git merge-base dev HEAD) dev -- apps/web/Dockerfile apps/web/docker/entrypoint.sh \
      apps/web/package.json docs/superpowers/plans/2026-09-06-…-manifest.md scripts/factory/manifests/
(empty)                                                   # no conflict risk with local dev

$ cd apps/web && pnpm vitest run tools/__tests__/write-build-fingerprint.test.mjs
 Test Files  1 passed (1)
      Tests  21 passed (21)      Duration 1.16s            # re-run, not accepted from the handback

$ cd / && node <worktree>/apps/web/tools/write-build-fingerprint.mjs --print-fingerprint
42eb6b3a2089326d                                          # cwd-independent from filesystem root
$ node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint
42eb6b3a2089326d
$ grep -E '^\s*-\s+path:' scripts/factory/manifests/routes-web.yaml | sed -E 's/^[[:space:]]*-[[:space:]]+path:[[:space:]]*//' \
    | sort | awk 'BEGIN{ORS=""} NR>1{print "\n"} {print}' | shasum -a 256 | cut -c1-16
42eb6b3a2089326d                                          # independent reproduction of the digest

$ printf '/\n/inventory/lots\n/pos' | shasum -a 256 | cut -c1-16
dceb1e43945c5997                                          # == the pinned fixture digest (test :27)

$ grep -rn "build-fingerprint" apps/web/src apps/web/index.html
(no output)                                               # nothing imports the JSON
$ grep -rn "build-fingerprint" apps/web --include='*.ts' --include='*.tsx' --include='*.html'
(no output)

$ grep -cE '^\s*-\s+path:' scripts/factory/manifests/routes-web.yaml
271                                                       # route_count in the handback matches
$ ls scripts/factory/manifests/
routes-pos.yaml  routes-web.yaml                          # the COPY source exists at repo root
$ ls .dockerignore
No such file or directory                                 # nothing can exclude scripts/ from the context

$ grep -rn "check-manifest-drift" scripts/ .github/
scripts/preflight.sh:221 · .github/workflows/ci.yml:2616  # the manifest cannot silently go stale

$ git status --porcelain
(empty)
```

**`pnpm typecheck` — deliberately skipped, and why.** Swap sat at 8.7-8.9 GB of 10.24 GB throughout
this review, and the check is provably incapable of covering this diff: `apps/web/tsconfig.json`
declares `"include": ["src"]` with neither `allowJs` nor `checkJs`, and the diff contains **zero**
`.ts`/`.tsx` files. Running it could only have re-verified untouched `src/`. `pnpm lint` was not run
per the lane instruction; its `test:tools` leg — the only leg this diff extends — was run in full above
(the targeted file; the sibling 8 tool suites are untouched by this diff).

---

## Merge recommendation

Merge it, with finding 1 fixed first. This is an unusually honest small lane: every field degrades to
an explicit `"unknown"` rather than a fabricated value, the one departure from the brief (the manifest
`COPY` plus the `CI=true` re-run) is both correct and necessary — without it the image would have
shipped `"feature_fingerprint": "unknown"` while every local build looked green, i.e. the precise
silent degradation the lane exists to detect — and it is declared up front rather than buried. The
skipped `docker build` is the only unverified step and I re-derived its two load-bearing properties
independently, so the residual risk is confined to "do the two new layers execute", not "is the path
right". The one thing I would not merge as-is is the `BUILD_SHA` wiring gap: `ARG BUILD_SHA` is
declared but no file in the repo passes it, and both compose files that build this image enumerate
their build args explicitly — so on the compose-shaped path the lane's headline signal is inert and
U-9's remedy would send an operator to a Dokploy screen that cannot fix it. That is two lines in
`docker-compose.staging.yml` / `docker-compose.dokploy.yml` plus one sentence in U-9. Findings 2-4 are
small hardening/accuracy edits that can ride along in the same fix commit; 5-7 need no action. Nothing
here touches a user-visible surface, so no owner-ruled UI principle, design-token, i18n or precision
rule is engaged, and merging into local `dev` is conflict-free today.
