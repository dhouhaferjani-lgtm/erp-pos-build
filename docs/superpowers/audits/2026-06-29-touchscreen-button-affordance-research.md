# Research: button affordance on a touchscreen POS (no hover) — 2026-06-29

**Question (owner):** are ghost buttons wrong for a touchscreen POS? "I can see the button hover effect, but that's on my laptop. On a touchscreen POS there is no clear visual signal."

**Verdict: confirmed.** Ghost/transparent-at-rest controls reveal their affordance only on hover, which does not exist on a touchscreen — so they give the cashier no persistent "this is tappable" signal. Interactive controls on a POS must carry a **persistent at-rest visual signifier** (fill, border, contrast), not a hover-only one.

## Sources & findings
- **NN/g — *Beyond Blue Links: Making Clickable Elements Recognizable*** ([nngroup.com](https://www.nngroup.com/articles/clickable-elements/)): clickability needs persistent signifiers — **fill/background, borders, rectangular shape (rounded corners), contrast**. Removing the filled/3‑D effect "eliminates one of the strongest clickability signifiers." Explicit: *"Never make users rely on scrubbing the screen [hover] to determine if [a] text is clickable… Make clickable elements obvious."*
- **NN/g — flat-design eyetracking** ([response-criticisms-flat-design](https://www.nngroup.com/articles/response-criticisms-flat-design/)): weak/absent signifiers (flat, ghost) measurably increase the effort to find what's interactive vs static.
- **Large-format touchscreen / kiosk UX** ([ICS](https://www.ics.com/blog/design-cues-navigating-large-format-touchscreens), [Kiosk Marketplace](https://www.kioskmarketplace.com/blogs/critical-user-experience-rules-for-designing-kiosk-interfaces/), [AVIXA](https://xchange.avixa.org/posts/kiosk-ux-ui-design-checklist)): hover states don't work on touch → affordance must come from **persistent at-rest cues** (contrast, fill, size); animation can *augment* but not replace them.
- **POS UI best practice** ([UXmatters](https://www.uxmatters.com/mt/archives/2013/08/designing-intuitive-point-of-interest-and-point-of-sale-touch-interfaces.php), [dev.pro](https://dev.pro/insights/designing-a-pos-system-ten-user-experience-tactics-that-improve-usability/), [Creative Navy](https://medium.com/uxjournal/the-design-principles-in-the-pos-system-pos-design-guide-part-2-57d1bcb30ac0)): high-contrast **filled/bordered** touch-friendly buttons; emphasise frequent actions; pair icons with labels.

## Decision applied
Redefine the shared `ghost` and (icon) `destructive` button variants so they carry a **persistent filled surface at rest** instead of being transparent-until-hover:
- `ghost` → `bg-surface-sunken text-ink` (filled-tonal) — distinct from `secondary` (`border + bg-surface-raised`, outlined). Both now read as buttons at rest.
- icon `destructive` → `bg-danger-surface text-danger-strong` (was transparent at rest).
- `designTokens.buttonGhost` likewise gets a persistent `bg-surface-sunken`.
This is the atom-level, app-wide fix (every Header/toolbar/cart ghost control inherits it), paired with the earlier `md → 48px` floor fix.

**Remaining (optional follow-up):** a few raw `<button>` custom controls still use hover-only `hover:bg-*` with a transparent rest; the high-traffic ones now route through the atoms, but a sweep could catch any stragglers.
