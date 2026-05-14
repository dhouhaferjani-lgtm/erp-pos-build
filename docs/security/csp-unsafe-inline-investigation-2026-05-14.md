# CSP `style-src 'unsafe-inline'` Investigation — 2026-05-14

**Task:** T5 (dev deferred-backlog session)
**Subject:** Whether the web SPA genuinely needs `style-src 'unsafe-inline'` in
the Content-Security-Policy added by `dev-remediation/D`.
**Verdict:** **Keep `'unsafe-inline'` for `style-src`.** It is genuinely
required. The code comment's *reasoning* was wrong, but its *conclusion* holds.

## The current policy

`apps/api/app/Http/Middleware/SecurityHeaders.php` ships a route-aware CSP. The
web-SPA branch:

```
default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline';
img-src 'self' data: blob: https:; font-src 'self' data: https:;
connect-src 'self' https: wss:; frame-ancestors 'none'; base-uri 'self';
object-src 'none'; form-action 'self'
```

`script-src 'self'` is already strict — **no `'unsafe-inline'` for scripts**,
which is the XSS-critical directive. This investigation is only about
`style-src`.

## What the comment claimed

The middleware comment said `'unsafe-inline'` is needed because *"Tailwind
utility classes inject inline styles."* **That is factually wrong.** Tailwind
(including v4, used here via `@tailwindcss/vite`) generates a **static
stylesheet at build time**; Vite extracts it to a `.css` file. `class="bg-..."`
is just a class name — the rules live in a bundled file served same-origin and
covered by `style-src 'self'`. Tailwind utility classes need nothing from
`'unsafe-inline'`.

## What actually needs `'unsafe-inline'`

CSP Level 2+ `style-src` governs **both** `<style>` elements **and**
`style="..."` attributes. The SPA produces inline `style` *attributes* from
three sources, all of which require `'unsafe-inline'`:

| Source | Evidence | Removable? |
|---|---|---|
| React inline styles (`style={{ ... }}`) | 41 occurrences across 36 files in `apps/web/src` | Possible but large — each must move to a class |
| ECharts (`echarts` + `echarts-for-react`) | ECharts positions its chart container / canvas via JS-set inline `style` attributes at runtime | No — intrinsic to the library |
| Headless UI v2 (`@headlessui/react`) | Uses Floating UI to position popovers/menus/dropdowns via dynamically-computed inline `style` (transform/position) | No — intrinsic to the library |

`index.html` itself carries **no** inline `<style>` or inline `<script>` — good.

## Why the usual escape hatches don't apply

- **Nonces** (`'nonce-...'`) authorise `<style>` *elements*, not `style=""`
  *attributes*. All three sources above are attributes, so nonces cannot
  replace `'unsafe-inline'` here.
- **`'unsafe-hashes'`** (CSP3) can authorise *specific, static* inline style
  attributes by hash. ECharts and Floating UI set styles to **runtime-computed
  values** (chart dimensions, popover coordinates) — there is no fixed string
  to hash. Not viable.
- **Static extraction** only solves Tailwind, which was never the problem.

## Recommendation

1. **Keep `style-src 'self' 'unsafe-inline'`.** Dropping it would break every
   ECharts chart, every Headless UI popover/menu position, and 36 components
   with inline styles. This is not a "clearly safe" tightening, so per the task
   it stays.
2. **Severity is low.** `style-src 'unsafe-inline'` enables CSS-based UI
   redressing and limited data exfiltration via crafted selectors — not code
   execution. The XSS-critical `script-src` is already `'self'` with no
   `'unsafe-inline'`. Track this as a **P3** hardening item, not a launch
   blocker.
3. **Fixed the misleading comment** in `SecurityHeaders.php` as part of this
   task (comment-only change — no policy change, no test impact) so a future
   developer doesn't try to drop `'unsafe-inline'` on the false premise that
   only Tailwind needs it.

## What would let us drop it later

`'unsafe-inline'` for `style-src` can only be dropped once **all three**
sources are gone:

1. Migrate the 41 React `style={{}}` usages to classes (overlaps with the
   CLAUDE.md rule 18 design-token migration already in progress).
2. Replace or wrap ECharts so chart styling is class-driven — realistically
   means dropping `echarts` for a CSP-friendly charting approach. Large.
3. Replace Headless UI / Floating UI positioning — also large.

Given (2) and (3), a strict-CSP-compatible `style-src` is a long-horizon goal,
not an incremental cleanup. Realistically it stays as long as the app uses a
JS charting library and a JS positioning library. Recommend revisiting only if
those dependencies are replaced for other reasons.
