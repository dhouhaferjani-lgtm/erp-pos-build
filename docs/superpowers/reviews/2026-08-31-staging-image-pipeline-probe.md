# Staging image-pipeline probe — 2026-08-31 (scripted Playwright against erp.otospex.dev) — **PASS**

Harness: `apps/web/e2e-local/staging-images.spec.ts` + `pw.staging.config.ts` (git-excluded). Fresh tenant per run (rule: never test on a tenant that already worked). Web bundle `index-BFTVQxGg.js`.

| Step | Result | Timing |
|---|---|---|
| `GET /api/v1/health` | 200 | 1.6 s |
| `POST /auth/register` (parapharmacy/TN) | 201, tenant `01a05760-74bf-…` | **15.5 s** (under the 60 s cap; P0-2 300 s seam not needed) |
| `POST /products` | 201 | 0.9 s |
| `POST /products/{id}/images` (real 240×240 PNG, multipart `image`) | 201, attachment `01a05760-b61d-…`, role PRIMARY | 0.5 s |
| Renditions READY (`url` non-null on `GET /products/{id}/images`) | READY on first poll | 0.3 s after upload |
| Signed serve `GET /api/v1/media/{tenant}/{attachment}/serve?expires&signature` | **200 image/png, 8076 B** | 0.3 s |
| UI: login → `/inventory/products/{id}` → `img[src*="/media/"]` | rendered **240×240, complete=true**, served via the web origin proxy `https://erp.otospex.dev/api/v1/media/…` | 8.4 s |

Reading: MinIO reachable + bucket present + `images` queue consumed (renditions produced within a second — Horizon staging supervisor plan from `9538179e8` is alive) + signed route valid + browser decode OK. No regression since the 2026-07-03 verification. Probe tenant left in place (no teardown exists; db-per-tenant).
