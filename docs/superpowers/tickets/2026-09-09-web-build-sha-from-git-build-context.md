# Ticket — web image must stamp the real commit sha (manifest U-9)

**Symptom.** `https://erp.otospex.dev/build-fingerprint.json` reports `"build_sha": "unknown"` on every staging deploy, so the staging push manifest §3 freshness check cannot close and every slice falls back to the asset-hash + grep pair (`docs/superpowers/plans/2026-09-06-parapharmacy-staging-push-manifest.md:229-242`, U-9 at `:324`).

**Why.** `apps/web/Dockerfile:63-74` declares `ARG BUILD_SHA` and the fingerprint emitter `apps/web/tools/write-build-fingerprint.mjs:57` reads `BUILD_SHA | VITE_BUILD_SHA | SOURCE_COMMIT | GIT_SHA`, else git, else `unknown`. The builder stage has no `.git` (excluded by `.dockerignore`), so the sha can only arrive as a build arg. Dokploy build args are static strings on the application (verified 2026-09-09 via `application-one` on `mY6P_PHb4pw-2LdG1Y7Ml`: `buildArgs` holds three fixed `VITE_*` values) and Dokploy does not inject the commit hash — the upstream feature (`DOKPLOY_COMMIT_HASH`, Dokploy issue #4006 / PR #4007) is still OPEN and unmerged. A fixed `BUILD_SHA=<sha>` build arg would therefore stamp one stale sha into every later build: a fabricated freshness signal, worse than the honest `unknown`. **Do not set it in Dokploy.**

**Proposed fix (in-repo, small).** Let the build context carry the commit identity without the object store: narrow `.dockerignore` from `.git` to `.git/objects` (plus `.git/lfs`, `.git/logs`) so `.git/HEAD`, `.git/refs/` and `.git/packed-refs` enter the context (a few KB), then in the builder stage resolve the sha in shell before `pnpm build`:

```dockerfile
COPY .git/HEAD .git/packed-refs* /tmp/git/
COPY .git/refs /tmp/git/refs
ARG BUILD_SHA
RUN set -eu; if [ -z "${BUILD_SHA:-}" ]; then \
      ref="$(sed -n 's/^ref: //p' /tmp/git/HEAD)"; \
      if [ -n "$ref" ] && [ -f "/tmp/git/$ref" ]; then BUILD_SHA="$(cat /tmp/git/$ref)"; \
      elif [ -n "$ref" ]; then BUILD_SHA="$(awk -v r="$ref" '$2==r{print $1}' /tmp/git/packed-refs)"; \
      else BUILD_SHA="$(cat /tmp/git/HEAD)"; fi; fi; \
    echo "$BUILD_SHA" | grep -Eq '^[0-9a-f]{40}$' || { echo "BUILD_SHA unresolved" >&2; exit 1; }; \
    echo "BUILD_SHA=$BUILD_SHA" > /tmp/build-sha.env
```

and `. /tmp/build-sha.env && BUILD_SHA=$BUILD_SHA pnpm build`. The explicit `--build-arg BUILD_SHA=` path keeps precedence for local/CI builds. Fail the image build when no 40-hex sha resolves (same posture as the `feature_fingerprint` CI=true re-run at `apps/web/Dockerfile:76-80`). Verify with one staging deploy: `curl -s https://erp.otospex.dev/build-fingerprint.json | jq -r .build_sha` equals the Dokploy deployment record's `description: "Commit: <sha>"`.

**Acceptance.** Fingerprint `build_sha` == deployed commit on two consecutive staging deploys; local `docker build` without the arg still succeeds from a clean checkout; the manifest U-9 row is closed and §3 drops the fallback requirement. Lane size: ≤ 30 lines Dockerfile + `.dockerignore`; test = the existing `write-build-fingerprint.test.mjs` plus one Dockerfile smoke in CI if the web image is built there.

**Alternative rejected.** A webhook-side stamp (Dokploy pre-build command writing `BUILD_SHA` into the env) does not exist on the application build type; a fixed build arg fabricates freshness.
