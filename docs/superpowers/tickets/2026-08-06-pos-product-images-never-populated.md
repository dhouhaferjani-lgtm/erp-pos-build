# POS product grid images have never worked — the sync payload has no `image_url` key

- **Filed:** 2026-08-06 · from the L6 media lane (BUG-005) authz merge gate
- **Severity:** Major (cosmetic, no fiscal/data impact) · **Surface:** Tauri POS (`apps/pos`)
- **Status:** OPEN — deliberately NOT fixed in the L6 lane; the fix belongs in a coordinated POS release
- **Confirmed by:** tenancy-authz-reviewer, `docs/superpowers/reviews/2026-08-06-l6-media-authz-gate.md`

## Symptom

Product cards, list rows and the product-detail drawer in the Tauri POS always
render the placeholder thumbnail. No image ever appears, on any tenant, and the
device's `product_images` table stays empty.

## Root cause — a silent key mismatch across the sync boundary

The POS pulls the *same* catalog endpoint the SPA uses:

- `apps/pos/src/lib/sync/syncService.ts:736` — `apiGet('/products', …)`
- rows are cast to `POSProduct` (`apps/pos/src/types/product.ts:57` → `image_url?: string`)
- persisted by `apps/pos/src/lib/db/repositories/productRepository.ts:182` — `p.image_url ?? null`

But the server payload is `ProductData`, whose image fields are
**`primary_image_url`** and **`media[]`** (`apps/api/app/Modules/Product/Application/DTOs/ProductData.php:57-58`).
There is **no `image_url` key**.

So `p.image_url` is always `undefined` → the `products.image_url` column is
always `NULL` → `useProductImage` (`apps/pos/src/lib/images/useProductImage.ts:20`)
sees a null `remoteUrl` and returns early → `enqueueDownload` is never called →
`product_images` is never populated. The whole image-cache pipeline
(`apps/pos/src/lib/images/imageCache.ts`) is dead code in practice.

Nothing errors and nothing is logged — a missing optional field simply reads as
"this product has no image".

## Secondary findings (record them before touching the code)

1. **The POS image cache is keyed on `product_id`, not the URL.**
   `imageCache.ts:56` and `:190-195` store/read `imageMap` by `productId`; the
   `product_images.remote_url` column is written but never used as a lookup key.
   The long-standing "the POS URL shape is FROZEN because it is the Tauri cache
   key" constraint is therefore **weaker than assumed** — changing the URL shape
   does not orphan the cache. (It would still change `getExtensionFromUrl`'s
   input; see 3.)
2. **`enqueueDownload` fetches with a bare `fetch(item.remoteUrl)`**
   (`imageCache.ts:175`) — no `Authorization` header. The `forPosSync` URL points
   at `products.images.download`, which sits behind `auth:sanctum` +
   `can:products.view` + `module:Inventory` (`apps/api/app/Modules/Product/routes.php:144,160`).
   Even if the key mismatch were fixed, that fetch would 401 and be dropped
   silently at `imageCache.ts:176-179`.
3. **A relative URL would also fail.** `getExtensionFromUrl` does `new URL(url)`
   (`imageCache.ts:275`), which throws on a relative path (caught → defaults to
   `jpg`, harmless), but `fetch('/api/v1/media/…')` from a Tauri webview resolves
   against `tauri://localhost` and will not reach the server. Any fix must mint
   an **absolute** URL for the POS, or resolve it against `serverUrl` client-side.

## Suggested fix (needs a POS release, not a server-only change)

Preferred: have the POS read the field the server already sends, and give it a
URL it can actually fetch.

1. POS: read `primary_image_url` (mapping it to the local `image_url` column) in
   `pullProductsCore` / `upsertProducts`, the way the web POS already does
   (`apps/web/src/features/pos/api/productApi.ts:39-42`).
2. Server: keep `media[].url` on `forPosSync` (absolute) OR add an explicitly
   POS-facing absolute signed URL. A signed `media.serve` URL works without a
   bearer token, which is what the unauthenticated `fetch` in the download queue
   needs — but it must be **absolute** for the Tauri webview, and its expiry
   must outlive a device that syncs infrequently.
3. Add a sync-level assertion/telemetry so a future key rename cannot fail
   silently again (e.g. count rows where the payload had an image field but the
   persisted column is null).

## Why it is not fixed here

The L6 lane changed `primary_image_url` from the auth-gated
`products.images.download` URL to a relative signed `media.serve` URL for the
SPA hero. That does not regress the POS (it consumes neither field today —
verified: `grep -rn primary_image_url apps/pos/src` returns no consumers), but
fixing the POS properly requires choosing a URL contract for offline devices and
shipping a coordinated Tauri release. Out of scope for a client-bug hotfix lane.
