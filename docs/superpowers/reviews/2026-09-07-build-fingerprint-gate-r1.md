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

---

# Gate verdict r2: MERGE

Re-gate after fix round 1 (2026-09-07). Range now 7 commits over `dev`; new since r1:
`ea4dccaa2` (compose args), `b5f9e2ee4` (CI value guard + nginx comment + nosniff),
`ab760c842` (handback "Fix round 1"). Scope of the r1→r2 delta: 7 files, 201 insertions.
Each r1 finding re-checked against the new code, not against the handback's account of it.

## Finding 1 (was MAJOR) — CLOSED

`BUILD_SHA: ${BUILD_SHA:-}` is now in the web `args:` map of both compose files:
`docker-compose.staging.yml:274` and `docker-compose.dokploy.yml:261`, each with a four-line comment
stating the actual mechanism (compose forwards only enumerated args; the builder stage has no `.git`).
Both files still parse and the key lands in the right node — `yaml.safe_load(...)['services']['web']['build']['args']`
returns `{'VITE_API_URL': …, 'VITE_APP_PRODUCT': …, 'BUILD_SHA': '${BUILD_SHA:-}'}` (staging) and
`{'VITE_API_URL': …, 'BUILD_SHA': '${BUILD_SHA:-}'}` (dokploy). The `:-` default is the right choice:
an unset `BUILD_SHA` interpolates to `""`, which `resolveBuildSha` (`apps/web/tools/write-build-fingerprint.mjs:90-91`)
treats as absent → `"unknown"`, so a deploy environment that has not exported it degrades honestly
instead of failing the build or fabricating a sha.

U-9 (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:322`) is rewritten
correctly: the claim is now "a `BUILD_SHA` build argument actually reaches the web image build" rather
than a Dokploy-only assertion, it names the U-1 shape ambiguity as the reason both halves were wired,
cites `docker-compose.staging.yml:274` / `docker-compose.dokploy.yml:261` (both **accurate** — verified
by `grep -n`), and gives two branch-specific remedies (export `BUILD_SHA` for the compose shape, Build
Args for the application shape) with "Settle U-1 first if unsure". That is exactly the gap I flagged.

## Finding 2 (was MINOR) — CLOSED

`apps/web/tools/write-build-fingerprint.mjs:220-229` now guards the **value**, not just the file:
`if ((env.CI ?? '').trim() !== '' && payload.feature_fingerprint === UNKNOWN) { … return 1 }`.
Placement is right — after `buildFingerprintPayload` (`:218`) and **before** the `--print-fingerprint`
branch (`:231`), so the degraded value cannot escape by either exit path. The docblock was updated to
match (`:40-42`: "missing OR parses to zero routes"), so the contract and the code agree.

Three new tests, all genuinely adversarial rather than restatements of the implementation
(`apps/web/tools/__tests__/write-build-fingerprint.test.mjs:239-283`):
- `:239-255` present-but-unparseable manifest under `CI=true` → exit 1, stderr contains `0 routes`.
  The fixture is `'app: web\nroutes:\n  - "path": "/pos"\n'` — a *quoted key*, i.e. the realistic
  `gen-route-manifest.mjs` format drift I described, not a contrived empty file.
- `:256-268` same manifest without `CI` → exit 0 and `feature_fingerprint: "unknown"`, pinning that
  local builds are still permissive.
- `:270-281` `--print-fingerprint` with `routes: []` under `CI=true` → exit 1, covering the second
  exit path.

No false positive introduced: `CI=true node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint`
against the real 271-route manifest still prints `42eb6b3a2089326d` and exits 0.

## Finding 3 (was MINOR) — CLOSED

`apps/web/docker/entrypoint.sh:174-177` now reads "An exact-match (=) location wins over the
\"location /\" prefix regardless of declaration order, so the placement here is cosmetic. What keeps a
missing file a 404 … is \"try_files \$uri =404\" below, NOT the ordering." That is the correct nginx
mechanism. `sh -n apps/web/docker/entrypoint.sh` → OK (the reworded comment lives inside the unquoted
heredoc, and the `\$uri` inside it is escaped, so it cannot become an expansion at generation time).

## Finding 4 (was MINOR) — ACCEPT nosniff-only; the implementer's argument is correct and verified

I asked whether all four server-level headers should be restored. They should not, and the evidence is
in the file: **every** location block in this config already drops all four, because each declares its
own `add_header` —
`.mjs` `:74-79` (Cache-Control only), static assets `:82-86` (Cache-Control only), images `:89-93`
(Cache-Control only), `.html` `:96-99` (Cache-Control only), `/health` `:194-198` (Content-Type only).
The server-level set at `:64-67` therefore only ever applies to `location /`. Restoring all four inside
the fingerprint block alone would make it the single inconsistent block in the file while adding no real
protection: `X-Frame-Options` and `Referrer-Policy` are meaningless for a JSON document fetched by
`curl`/`jq`, and `X-XSS-Protection` is a retired header. `nosniff` is the one that matters here — it is
the only one with a live threat model for a parsed JSON response — and `:183` adds it. The block is now
strictly better than the house baseline, and `:178-180` documents the inheritance rule so the next
editor knows why. Accepted as-is; restoring the other three would be site-wide work, out of this lane.

## Refreshed citations and scope

- `docs/…-staging-push-manifest.md:223` (§3) and the U-4 row (`:317`) now both cite
  `apps/web/docker/entrypoint.sh:171-186`. Verified exact: the comment opens at `:171`, `location = /build-fingerprint.json`
  is `:181`, and the closing `}` is `:186`. The `(:64-67)` reference inside the new nginx comment
  (`:179`) also still resolves to the four security headers.
- U-8's `apps/web/Dockerfile:107-108` is unchanged and still correct (this round touched no Dockerfile line).
- No other manifest row altered in the r1→r2 delta (only §3's citation, U-4's citation, and U-9's body).
- **No scope creep.** Full `dev...HEAD` file list is 11 files, all in-lane: `apps/web/Dockerfile`,
  `apps/web/docker/entrypoint.sh`, `apps/web/package.json`, the two `tools/__fixtures__` YAMLs, the
  tool + its test, the two compose files (added by the fix I required), the handback, and the staging
  manifest. No `apps/web/src/**`, no `apps/api/**`, no `tsconfig.tsbuildinfo`, no `dist/` artefact;
  working tree clean.

## Commands run (r2)

```
$ git log --oneline dev..HEAD | head -3
ab760c842 / b5f9e2ee4 / ea4dccaa2                          # expected fix commits
$ git diff f1facb630..HEAD --stat
7 files changed, 201 insertions(+), 8 deletions(-)

$ cd apps/web && pnpm vitest run tools/__tests__/write-build-fingerprint.test.mjs
 Test Files  1 passed (1)
      Tests  24 passed (24)      Duration 1.27s             # 21 → 24, the 3 new guard tests

$ node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint
42eb6b3a2089326d
$ CI=true node apps/web/tools/write-build-fingerprint.mjs --print-fingerprint; echo $?
42eb6b3a2089326d
0                                                          # new guard does not false-positive

$ sh -n apps/web/docker/entrypoint.sh
entrypoint sh -n OK
$ python3 -c "import yaml; …['services']['web']['build']['args']"
staging  {'VITE_API_URL': …, 'VITE_APP_PRODUCT': …, 'BUILD_SHA': '${BUILD_SHA:-}'}
dokploy  {'VITE_API_URL': …, 'BUILD_SHA': '${BUILD_SHA:-}'}

$ grep -n "BUILD_SHA" docker-compose.staging.yml docker-compose.dokploy.yml
staging:274 · dokploy:261                                  # == the lines U-9 cites

$ git diff dev...HEAD --name-only | grep -Ei 'tsbuildinfo|dist/'   → no match
$ git status --porcelain                                            → empty
```

`pnpm typecheck` skipped again for the same provable reason (swap 8.97 GB / 10.24 GB; the delta contains
zero `.ts`/`.tsx` and `apps/web/tsconfig.json` includes only `src` with no `allowJs`/`checkJs`).
`docker build` still not run — unchanged residual from r1 finding 7, and this round added no new
Dockerfile layer, so the residual did not grow. Findings 5-7 from r1 were INFO and remain open by design.

## Merge recommendation (r2)

All four actionable findings are closed with real code, not with documentation. The two that mattered
are properly fixed rather than papered over: the compose arg is wired in both files with the mechanism
explained, and the CI guard now protects the fingerprint *value* on both exit paths with three tests
that would have caught the original hole. The fourth was closed by an argument I asked to be defended
and which the file itself supports — every sibling location block already drops the server-level
headers, so nosniff-only is the consistent and sufficient choice. Citations were refreshed to the new
line numbers and I re-verified each one rather than trusting the handback. Nothing left is a merge
blocker: the outstanding items are the unrun `docker build` (two trivial layers, path resolution proven
independently in r1) and U-9's out-of-repo half, which is now correctly framed as an environment/UI
verification with a branch-specific remedy and an honest `"unknown"` fallback in the meantime.
**MERGE.** Run one `docker build -f apps/web/Dockerfile --build-arg BUILD_SHA=$(git rev-parse HEAD) -t web-fp .`
on a rebooted laptop before the staging deploy, not before the merge.
