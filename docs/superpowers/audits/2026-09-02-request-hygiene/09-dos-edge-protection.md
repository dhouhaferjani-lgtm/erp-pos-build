# DoS/DDoS edge protection — decision brief (2026-09-03)

**Question:** should we put Cloudflare in front of the SPA and/or the live API before production launch, and what else is needed? **Scope:** infra/edge decision + the minimum app-layer work it depends on. No fixes made; this is a decision + rollout brief. Builds on `05-synthesis.md` (S-9, S-23, RH-16, RH-17) and the Phase A plan's Phase B roster (`docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md`).

---

## 0. Current exposure (repo facts)

- **No DDoS/edge layer exists today.** `infra/` has no Traefik/firewall/Cloudflare config; the deploy runbook (`/Users/houssamr/Projects/syneriva/claude/deploy-runbook.md`) documents Dokploy app/DB provisioning only, nothing at the network edge.
- **Two hosts, two exposure profiles:** ERP staging runs on a separate Hetzner "Development" VPS `157.180.71.252` (Dokploy-managed, no local SSH key) with Postgres exposed on external port 5434 (`reference_erp_staging_db_access.md`); production is described as "a dedicated AX42 box" (the platform's AX42 at `176.9.139.218` is Dokploy-managed per the runbook — confirm the ERP-production host/IP separately before applying any origin-lockdown step below, since it is not this audit's scope to have verified it).
- **Laravel trusts every proxy hop.** `apps/api/bootstrap/app.php:108-118` calls `$middleware->trustProxies(at: '*', headers: HEADER_X_FORWARDED_FOR|HEADER_X_FORWARDED_PROTO|HEADER_X_FORWARDED_PORT)`. The comment at `bootstrap/app.php:99-107` already flags the risk: safety today rests entirely on "only the proxy can reach the container" (no published `ports:` on the api service), not on the trusted-IP list. **This means every IP-keyed rate limiter in the app (`login`, `register`, `password-reset`, the dead `api` limiter) reads `$request->ip()` off an X-Forwarded-For chain that is trusted unconditionally** — `AppServiceProvider.php:305-318` (`login`, `register`). If any future misconfiguration exposes the origin directly (or a co-resident container on the same Docker host reaches it), an attacker controls X-Forwarded-For and defeats every IP-based limiter at once, not just the general one.
- **The general `api` limiter is defined but never attached** (`AppServiceProvider.php:358-364`; `bootstrap/app.php` route groups never reference `throttle:api`) — S-9 in `05-synthesis.md`. Reports, exports, imports, search, bulk ops, and fiscal ingest have zero throttle. 14 narrow limiters exist and work correctly for what they cover: `admin-login`, `admin-sensitive`, `login`, `register`, `password-reset`, `email-verification`, `document-email`, `pos-terminal-activation`, `api` (unattached), `image-upload`, `public-product-images`, `signed-media`, `channel-webhook`, `storefront-booking-ip`, `storefront-booking-company-phone` (`AppServiceProvider.php` — grep `RateLimiter::for(`).
- **The unauthenticated enrichment webhook has no throttle.** `api/v1/webhooks/syneriva` (`apps/api/app/Modules/PlatformIntegration/Presentation/routes.php:16-21`) is gated only by `VerifySynerivaWebhookSignature` (HMAC) — no `throttle:` middleware in that route group, and `EnrichmentWebhookController.php:47-69` dispatches one job per unbounded item (RH-17).
- **64 MB request bodies accepted at the proxy** (`apps/api/docker/nginx/nginx.conf:47-49` `client_max_body_size 64M`) with no server-side cap on line/array counts in several FormRequests (RH-16: `AutoSaveDraftRequest.php:194-218`, `StoreStockTransferRequest.php:83-107`, `IngestFiscalEventsRequest.php:45-55`).
- **Sanctum tokens are long-lived:** back-office `expiration = 43200` minutes = 30 days with `['*']` abilities (`config/sanctum.php:47`, `AuthController.php:498-504`); POS-tauri tokens get a 12-month per-row TTL, scoped `['pos:*']` (`AuthController.php:55,94-116,151,296-345`) — S-23. A stolen long-lived token is not stopped by any edge rate limit; it is an app-layer/rotation problem (see §3).
- **Reverb (websockets)** runs as its own container on `0.0.0.0:8080` (`apps/api/docker/entrypoint-websocket.sh:34-37`), config at `apps/api/config/reverb.php`, `max_request_size` env-tunable (default 10,000 bytes per frame), no `scaling` enabled by default. No connection-count or handshake-rate limiting is configured anywhere in the repo.

---

## 1. Threat model at launch

| Threat | Surface | Repo tie-in |
|---|---|---|
| **Volumetric L3/L4** (SYN floods, UDP reflection, saturate the NIC/uplink) | Both Hetzner VPS hosts | Nothing in this repo mitigates it; it must be absorbed before the packet reaches nginx/Traefik. |
| **HTTP floods on expensive endpoints** (reports, exports, imports, search, bulk ops, fiscal ingest) | `apps/api` | S-9: general limiter dead, so these have **zero** throttle today. Each is also a multi-query endpoint per `05-synthesis.md` §3 (S-11, S-12, S-15) — cheap for an attacker to send, expensive for Postgres/PHP-FPM to serve. |
| **Credential stuffing on `/auth/login`** | `apps/api/app/Modules/Identity` | Two buckets exist (`login:email:*` 5/min, `login:ip:*` 20/min — `AppServiceProvider.php:305-313`), but they are IP-keyed against a request whose IP is trust-everything (`trustProxies(at:'*')`). A botnet spread across many source IPs is not slowed by either bucket; a large distributed credential-stuffing run looks like normal per-IP traffic to this limiter. |
| **Abuse of unauthenticated routes** (register, password reset, storefront booking, the enrichment webhook) | `AuthController`, `Scheduling` module, `PlatformIntegration` | `register` (5/15min/IP) and `password-reset` (3/hr/email) exist; the enrichment webhook (RH-17) and `CatalogBrowseController` criteria-forward endpoint have none — an attacker who forges (or replays, since it's HMAC-verified but rate-unlimited) enough signed-looking traffic, or simply floods the endpoint before signature verification consumes CPU, has no ceiling. |
| **Websocket connection floods** | Reverb | No connection cap, no per-IP handshake throttle in `config/reverb.php` or the entrypoint script. A flood of WS connect attempts competes with legitimate POS/dashboard sockets for the same container's file descriptors/memory; nothing here isolates it from the rest of the stack (separate container, but same VPS/network). |
| **POS/mobile long-lived tokens as a slow-burn vector** | Sanctum | S-23: a leaked 12-month POS token or 30-day back-office token isn't rate-limited away at the edge — it authenticates normally. Edge WAF/rate-limiting reduces blast radius (per-IP ceilings still apply) but does not solve credential lifetime; that is an app-layer fix (rotation/shorter TTL/ability scoping), tracked separately from this brief. |
| **Large-body amplification** | Document/transfer/fiscal ingest endpoints | RH-16: 64 MB accepted, array item counts uncapped on several endpoints. A modest number of maximally-sized, maximally-nested valid-shaped requests can consume disproportionate CPU in validation/serialization before any rate limiter would even trigger (since the general limiter is unattached). |

---

## 2. Options and trade-offs

### (a) Cloudflare proxy in front of BOTH the SPA and the API (orange-cloud everything)

**What it buys:**
- **Volumetric L3/L4**: absorbed at Cloudflare's edge before it reaches Hetzner at all — this is the one mitigation nothing on the origin can substitute for.
- **WAF managed rules + rate limiting rules**: Free gives 1 rate-limiting rule, IP-keyed only, fixed windows (10s–1 day) ([Cloudflare rate limiting rules](https://developers.cloudflare.com/waf/rate-limiting-rules/), [rate limiting parameters](https://developers.cloudflare.com/waf/rate-limiting-rules/parameters/)). Pro gives ~10 rules, still IP-only counting. **Business is the first tier with custom characteristics** — cookie/header/query-param keys — which is what tenant-aware limiting (key by tenant slug in the path, or a session cookie) would need; **JWT-claim / API-key-header / body-field counting is Enterprise-only "Advanced Rate Limiting."** Concretely: a Free/Pro rate-limiting rule can throttle `POST /api/v1/auth/login` per source IP, but it **cannot** natively express "per tenant + per user" the way `AppServiceProvider.php:305-313` already does — the edge rule is a coarser, IP-only backstop layered *outside* the app's own keyed limiters, not a replacement for them.
- **Bot Fight Mode** (Free) / **Super Bot Fight Mode** (Pro+): heuristic bot classification, useful against scripted credential-stuffing and scraping — Free/Pro/Business availability confirmed ([stop malicious bots](https://developers.cloudflare.com/use-cases/solutions/stop-malicious-bots/), [Super Bot Fight Mode](https://developers.cloudflare.com/bots/get-started/super-bot-fight-mode/)).
- **Under Attack Mode**: a manual JS-challenge-everything toggle for active incidents (all plans).
- **Turnstile**: free, plan-independent CAPTCHA replacement, several maintained Laravel packages exist ([laravel-cloudflare-turnstile](https://github.com/ryangjchandler/laravel-cloudflare-turnstile), [laravel-turnstile](https://packagist.org/packages/njoguamos/laravel-turnstile)) — good fit for `register` and `login` forms, which are the two unauthenticated, high-value abuse targets.
- **Cache rules for SPA assets**: standard CDN caching of the Vite build's hashed JS/CSS bundles reduces both load and the blast radius of a flood aimed at static assets.
- **WebSockets**: supported without extra config on all plans; Cloudflare closes idle WS connections after a period of no traffic (Enterprise can request a custom idle timeout) — needs an app-level ping/pong heartbeat or the existing 24h-style proxy timeout pattern to survive ([WebSockets docs](https://developers.cloudflare.com/network/websockets/)). Reverb's `max_request_size` (`config/reverb.php`) already caps frame size; Cloudflare adds the flood/DDoS layer in front of the handshake.
- **Large uploads**: Cloudflare's proxied body limit is **100 MB on Free/Pro, 200 MB Business, 500 MB Enterprise-negotiable** — comfortably above the app's own 64 MB nginx cap (`nginx.conf:47-49`), so it is not a new bottleneck, just confirm it stays above whatever cap Task-level work (RH-16 remediation) lands on.
- **TLS Full (strict) + Authenticated Origin Pulls**: Full (strict) requires the origin hold a CA-trusted cert — a Let's Encrypt cert via Traefik/Dokploy satisfies this unchanged, since Cloudflare passes the HTTP-01 challenge through when the record is proxied ([Traefik + Cloudflare](https://www.benscobie.com/posts/traefik-cloudflare-keeping-the-origin-server-a-secret/)). Authenticated Origin Pulls (mTLS from edge to origin) is the strongest lock but adds cert-management overhead on the Traefik side; treat as a stretch goal after IP allowlisting lands.
- **Origin IP allowlisting**: Cloudflare publishes its ranges at `https://www.cloudflare.com/ips-v4` / `ips-v6` and via `https://api.cloudflare.com/client/v4/ips` ([IP ranges](https://www.cloudflare.com/ips/)) — Hetzner Cloud Firewall (or `ufw`/`nftables` on the box) should allow only those ranges plus SSH-from-office on 22, deny everything else on 80/443. This is the step that actually closes the "attacker connects directly, forges X-Forwarded-For" gap called out in §0 — **without it, trusting `CF-Connecting-IP`/XFF at the app layer is security theater**, because nothing stops a direct connection from bypassing Cloudflare and its headers entirely.
- **Real client IP → TrustProxies**: restrict `bootstrap/app.php:108` from `at: '*'` to Cloudflare's published CIDR list (refreshed periodically — several maintained packages do this, e.g. `monicahq/laravel-cloudflare`) and read `CF-Connecting-IP` (Cloudflare sets this to the true client IP and strips any client-supplied value with the same name) instead of trusting the full XFF chain blindly ([Getting real client IPs behind Cloudflare](https://dev.to/ozankozan/getting-real-client-ips-behind-cloudflare-or-other-proxy-in-laravel-1j6i)). This is required for every IP-keyed limiter in `AppServiceProvider.php` (`login`, `register`, `password-reset`, the dead `api`) to key on the real client rather than the edge or a spoofed header.

**Cost:** Cloudflare Free is $0 and covers volumetric absorption, Bot Fight Mode, Under Attack Mode, 1 rate-limiting rule, Turnstile, and origin lockdown — i.e. everything in this list except tenant-aware edge rate limiting. Pro is ~$25/mo/domain (WAF managed rules, ~10 rate-limiting rules, Super Bot Fight Mode). Business (custom rate-limiting characteristics) is materially more ($200+/mo/domain) and is not justified by this launch's traffic — the app-layer limiters already do tenant/user-aware keying; the edge only needs to be a coarse IP-based backstop.

### (b) Cloudflare Pages for the SPA + Cloudflare proxy for the API only

Splits the two: static assets served from Cloudflare's edge/CDN directly (fastest, cheapest, zero origin load for the SPA — Cloudflare Pages is free for this shape of app), while `api.<domain>` stays proxied through Cloudflare in front of the existing Dokploy/Traefik origin. Vite React SPAs deploy to Pages via `npm run build` → `dist` with a Git-connected build ([Cloudflare Pages deploy guide](https://kumarkalyan.medium.com/how-to-create-deploy-your-first-react-app-using-vite-and-cloudflare-pages-f362e60c898a)); custom domain via CNAME, production/preview split by branch. **Trade-off:** two deploy pipelines instead of one (Dokploy no longer owns the SPA container), and the Tauri POS app and React Native mobile app still call the API directly — Pages only helps the browser SPA, not those two clients. Given the SPA is currently deployed as a container through Dokploy already, moving it to Pages is a deploy-pipeline change, not just a DNS flip — worth doing for the CDN/edge-cache win, but it's an independent decision from the DoS question and should not block (a) or gate it.

### (c) Hetzner-only (firewall + Traefik rate limiting + fail2ban), no Cloudflare

Keeps everything on one vendor. Hetzner Cloud **includes basic DDoS mitigation on all cloud servers, no opt-in** ([Hetzner DDoS protection](https://www.hetzner.com/unternehmen/ddos-schutz/); reinforced 2026 with Nokia Deepfield Defender across their EU DCs) — this is a real floor, not nothing. But: it protects against volumetric floods reaching the network, not application-layer abuse; Traefik's own rate-limiting middleware is per-router and IP-keyed with no bot heuristics, no managed WAF ruleset, no CAPTCHA-replacement, and fail2ban only reacts after log lines exist (a flood already landed on nginx/Traefik before fail2ban can react, unlike Cloudflare which drops it before it reaches Hetzner). This option is strictly weaker than (a) for the same or higher engineering cost (hand-rolled WAF rules, hand-rolled bot detection) and is **not recommended** as the sole layer for a production multi-tenant fiscal system — it should be the *fallback floor* under Cloudflare, not the primary control.

### (d) Dokploy/Traefik-specific constraints

- **Let's Encrypt with a proxied (orange-cloud) DNS record**: works — Cloudflare passes HTTP-01 challenge traffic straight through when Full or Full (strict) mode is set, so Traefik's existing ACME resolver needs no change ([SSL + Traefik + Cloudflare](https://ithy.com/article/cloudflare-origin-certs-traefik-66o4lqpg)). Confirm Dokploy's managed Traefik instance isn't hardcoded to DNS-01 in a way that assumes a non-proxied record.
- **Traefik `forwardedHeaders.trustedIPs`**: Traefik itself needs the equivalent restriction — if Dokploy's Traefik config trusts `X-Forwarded-*` from `0.0.0.0/0` (mirroring the Laravel `at: '*'` pattern), it will forward a spoofable header chain into the api container even after Laravel's own TrustProxies is tightened, because Laravel would then be trusting Traefik's forwarded value as if it were Cloudflare's. Set Traefik's `trustedIPs` to Cloudflare's ranges too, or the origin-lockdown firewall step in (a) becomes the only thing actually enforcing "only Cloudflare talks to us," which is fragile if it's ever loosened for debugging.

---

## 3. Recommendation

**Option (a): Cloudflare proxy in front of both the SPA and the API, on Free initially, evaluate Pro after launch traffic is observed.** Rationale: it is free at the tier that matters for this launch, closes the one gap nothing on Hetzner alone can close (volumetric L3/L4), and every other capability (WAF, Bot Fight Mode, Turnstile, cache rules, origin lockdown) is available on Free or the cheap Pro tier. Do not pursue Cloudflare Pages (option b) as part of this DoS work — evaluate it separately as a CDN/perf optimization; it doesn't change the DoS posture for the Tauri POS or mobile clients, which is the harder half of this system's traffic.

**Rollout order (staging first, per CLAUDE.md rule 21's spirit — verify before touching the shared production edge):**

1. **App layer, independent of Cloudflare, land first regardless:**
   - Attach the `api` limiter to the base API middleware group in `bootstrap/app.php` (currently defined but unused, `AppServiceProvider.php:358-364`) — this is exactly Phase B lane **B-1** in `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3564` (`| B-1 rate limiting | S-9 | tenancy-authz | one week of staging POS traffic |`).
   - Add cost-classed per-surface limiters keyed `(tenant, user)` with an IP fallback for unauthenticated calls, mirroring the existing `login` limiter's dual-bucket pattern (`AppServiceProvider.php:305-313`) — for reports, exports, imports, sync, and the enrichment webhook (RH-17 currently has none).
   - Restrict `bootstrap/app.php:108` `trustProxies(at: '*', …)` to Cloudflare's published CIDR ranges (refresh via `https://api.cloudflare.com/client/v4/ips`, cached) and switch limiter keys to read `CF-Connecting-IP` where present, falling back to `$request->ip()` for the direct-to-origin staging path during cutover.
   - Add body-size/array-item caps to the RH-16 endpoints (`AutoSaveDraftRequest.php`, `StoreStockTransferRequest.php`, `IngestFiscalEventsRequest.php`) — independent of any edge change, this bounds worst-case validation cost.
   - Add Turnstile to `login` and `register` forms (frontend widget + backend validation rule via one of the maintained packages) — cheap, plan-independent, closes the credential-stuffing/mass-registration gap that IP-only edge rate limiting cannot (§2a).
2. **Staging Cloudflare cutover:**
   - Point staging's DNS at Cloudflare (proxied/orange-cloud) for both the SPA and API hostnames, TLS mode Full (strict) once Traefik's Let's Encrypt cert is confirmed valid.
   - Enable Bot Fight Mode + 1 Free-tier rate-limiting rule on `/api/v1/auth/login` and `/api/v1/auth/register` as an edge backstop.
   - Firewall the staging Hetzner box (157.180.71.252) to Cloudflare's ranges only on 80/443, keep SSH/DB ports scoped as they are today.
   - Run the verification checklist (§4) against staging.
3. **Production cutover** only after staging has run clean for a representative period (mirrors the plan's existing "one week of staging POS traffic" precondition already attached to B-1) — same steps, plus Authenticated Origin Pulls as a stretch hardening once the basic allowlist is proven stable.

**What must NOT be rate-limited at the edge — POS sync bursts and mobile counting sync:** the POS sync pattern already does ~12 sequential pulls at cold boot and 17–18 pulls/minute per terminal (S-20, `05-synthesis.md`); a coarse IP-keyed edge rule sized for "human browsing a dashboard" will 429 a POS terminal recovering from an offline period, or several terminals behind one shop's NAT sharing an IP. Two ways to exempt them, in order of preference:
   - **Path-scoped rule with a higher budget**: give POS/mobile sync endpoints (`/api/v1/pos/sync/*`, counting sync) their own Cloudflare rate-limiting rule with a budget sized to the measured worst case (12 cold-boot pulls + steady-state 17–18/min, multiplied by expected terminals-per-NAT), rather than relying on the general rule.
   - **Token-based bypass**: exclude authenticated POS-token traffic from the edge rule's scope (Cloudflare rate-limiting rules can match on a header's *presence*, e.g. `Authorization` starting with the POS token prefix, on Free/Pro — this is a coarser match than the Business-tier "count by token value," but suffices to route POS traffic to its own higher-budget rule or skip the general one).
   Either way, the edge rule must stay looser than the app-layer POS limiters (which already understand tenant/terminal identity per `pos-terminal-activation`, `AppServiceProvider.php:351-357`) — the edge is a coarse backstop, the app is the precise control.

---

## 4. Cutover checklist with rollback

**Pre-cutover:**
- [ ] Confirm production ERP host identity/IP (this brief assumed it mirrors the platform's AX42 pattern per the deploy runbook but did not verify a distinct ERP-production Dokploy project — do this before any firewall change).
- [ ] Snapshot current DNS records (A/AAAA/CNAME, TTLs) for both staging and production hostnames before changing anything.
- [ ] Confirm Traefik's ACME resolver and current cert are healthy (`docker logs` on the Traefik container, or Dokploy's domain status) — a mid-cutover cert failure with DNS already pointed at Cloudflare is the main rollback trigger.

**Cutover (staging, then production):**
- [ ] Add domain to Cloudflare, update nameservers or use CNAME setup per Dokploy's DNS delegation.
- [ ] Set SPA + API records to proxied (orange cloud); set TLS mode to Full (strict).
- [ ] Enable Bot Fight Mode; add the login/register rate-limiting rule; enable Turnstile widget (frontend + backend flag) behind a feature flag so it can be disabled without a redeploy.
- [ ] Firewall origin to Cloudflare ranges only (Hetzner Cloud Firewall or `ufw`), confirm SSH still reachable from the office/admin IP before applying.
- [ ] Restrict Laravel `TrustProxies` to Cloudflare ranges; deploy; confirm `$request->ip()` / `CF-Connecting-IP` resolves correctly (verification below) before the firewall step locks out direct access — **order matters: verify the app resolves real IPs correctly BEFORE closing the firewall to Cloudflare-only, or a misconfigured TrustProxies plus a closed firewall can make every request look like it comes from Cloudflare's edge IP, silently defeating every IP-keyed limiter.**

**Rollback (any stage):**
- [ ] DNS: flip records back to DNS-only (grey cloud) or restore the pre-cutover snapshot — propagation is bounded by TTL, so set TTLs low (300s) before the cutover window, not after.
- [ ] Firewall: keep a documented "open to 0.0.0.0/0 on 80/443" fallback rule, disabled but ready, in case the Cloudflare-range allowlist is wrong and locks out legitimate traffic.
- [ ] TrustProxies: keep the `at: '*'` value in a comment/git history for a fast revert if the CF-range restriction breaks IP resolution.

**Verification:**
- [ ] `curl -I https://<domain>` with and without spoofed `X-Forwarded-For`/`CF-Connecting-IP` headers directly against the **origin IP** (not through Cloudflare) — after the firewall step this should time out/refuse; before it, confirm the app trusts only the real proxy chain.
- [ ] `curl -s https://<domain>/api/v1/... -H "X-Forwarded-For: 1.2.3.4"` through Cloudflare — confirm the app-layer limiter keys on the real client IP (from `CF-Connecting-IP`), not the forged header, by checking `Retry-After`/429 behavior after N requests.
- [ ] Load test a throttled endpoint on staging with `hey` or `vegeta` (e.g. `hey -n 500 -c 50 https://staging.../api/v1/auth/login`) — confirm 429s appear at the configured threshold, both at the edge rule and the app-layer `login` limiter, and that legitimate traffic below the threshold is unaffected.
- [ ] Websocket connect test: `wscat -c wss://<domain>/app/<key>` (or the app's own Reverb client) through Cloudflare — confirm handshake succeeds, an idle connection survives at least a few minutes without the heartbeat, and reconnect-storm behavior (`WebSocketReconnectProvider.tsx:29`, S-7) doesn't compound with Cloudflare's own idle-timeout disconnects.
- [ ] Upload test: POST a body sized between the app's cap and 100 MB through Cloudflare to confirm Cloudflare isn't the binding constraint ahead of the app's own limit.

---

## 5. Effort estimate

- **App-layer lane** (ties to Phase B **B-1** `rate limiting`, `docs/superpowers/plans/2026-09-03-request-hygiene-phase-a.md:3564`, reviewer `tenancy-authz`, precondition "one week of staging POS traffic"): attach the `api` limiter, add 3–5 cost-classed per-surface limiters (reports/exports/imports/sync/webhook) keyed `(tenant,user)` with IP fallback, restrict `TrustProxies`, add Turnstile to login/register, add RH-16 body/array caps. **Estimated 2–3 days** of focused work plus the existing plan's TDD/reviewer-gate overhead (each is a "few lines" per the synthesis doc's own estimate for the frontend fan-out P0s, but the limiter design + tenant-aware keying + tests is the bulk of the time here). Sits behind the Phase B entry criterion (tenant #1 live one quiet week) already recorded in the plan.
- **Infra lane** (Cloudflare account setup, DNS cutover, firewall allowlist, Traefik `trustedIPs`, staging verification, production cutover): **Estimated 1–2 days** for staging (mostly config + the verification checklist above) plus a **half-day production cutover window** once staging has soaked. Not gated by the plan's PHPUnit/CI machinery — it's operational work, best scheduled outside a manual-test-day window per CLAUDE.md rule 21.
- **Sequencing:** the app-layer lane's `TrustProxies` restriction is a hard prerequisite for the infra lane's origin-firewall step (§4 ordering note) — do not close the firewall to Cloudflare-only before the app resolves real client IPs correctly, or every IP-keyed limiter silently keys on Cloudflare's edge IP instead of the attacker's.

---

**Sources:**
- [Cloudflare rate limiting rules](https://developers.cloudflare.com/waf/rate-limiting-rules/)
- [Cloudflare rate limiting parameters (characteristics by plan)](https://developers.cloudflare.com/waf/rate-limiting-rules/parameters/)
- [Cloudflare Advanced Rate Limiting blog](https://blog.cloudflare.com/advanced-rate-limiting/)
- [Cloudflare WebSockets docs](https://developers.cloudflare.com/network/websockets/)
- [Cloudflare IP Ranges](https://www.cloudflare.com/ips/)
- [Cloudflare bot solutions — stop malicious bots (plan availability)](https://developers.cloudflare.com/use-cases/solutions/stop-malicious-bots/)
- [Super Bot Fight Mode](https://developers.cloudflare.com/bots/get-started/super-bot-fight-mode/)
- [Cloudflare upload/body size limits by plan (GridPane)](https://gridpane.com/kb/cloudflares-cdn-and-upload-limitations/)
- [Hetzner DDoS protection](https://www.hetzner.com/unternehmen/ddos-schutz/)
- [Cloudflare Origin Certs + Traefik + Let's Encrypt](https://ithy.com/article/cloudflare-origin-certs-traefik-66o4lqpg)
- [Traefik + Cloudflare origin secrecy pattern](https://www.benscobie.com/posts/traefik-cloudflare-keeping-the-origin-server-a-secret/)
- [Getting real client IPs behind Cloudflare in Laravel](https://dev.to/ozankozan/getting-real-client-ips-behind-cloudflare-or-other-proxy-in-laravel-1j6i)
- [laravel-cloudflare-turnstile package](https://github.com/ryangjchandler/laravel-cloudflare-turnstile)
- [Cloudflare Pages React/Vite deploy guide](https://kumarkalyan.medium.com/how-to-create-deploy-your-first-react-app-using-vite-and-cloudflare-pages-f362e60c898a)
