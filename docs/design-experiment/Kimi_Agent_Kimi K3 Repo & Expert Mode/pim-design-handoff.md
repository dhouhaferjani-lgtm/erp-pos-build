# Pim — Design Handoff

This package describes a redesign direction for an agentic-first, vertically-scoped
ERP/CRM ("Pim"). It is written to be consumed by an AI coding agent (e.g. Kimi Code
CLI) and by humans. Pair it with `pim-tokens.css`.

**How to use:** drop both files into the repo (root or `docs/`), reference them from
`AGENTS.md`, and port in this order — tokens → atoms → molecules → organisms →
templates — one screen per pull request. Example prompts are in §7.

---

## 1. Product concept (context the agent needs)

Pim is an ERP/CRM that feels like a colleague, not a console. First vertical:
pharmacies; then automobile, repair, retail. Users are non-technical owners.

Four pillars — every screen must express all four:

1. **Speaks the trade.** Pharmacy-native language (expiry, batches, Rx, copay).
   No generic ERP vocabulary anywhere in the default UI.
2. **Does the work.** The agent drafts orders, files compliance, reconciles.
   Users approve; the agent never spends money without asking.
3. **Shows only what matters now.** Home is a briefing, not a dashboard.
   It changes with time of day.
4. **Compliance is invisible.** Tax, accounting, controlled logs happen behind a
   calm "Everything's in order." Finance reads as: Came in / Went out / Yours to keep.

Scaling doctrine — **a spectrum, not modes**:

- Density is a *setting* (Comfortable ↔ Compact), never a mode toggle.
- Depth lives *behind the answer*: every friendly summary has a "look underneath"
  drill into the expert artifact (journal entry, ledger, log).
- Complexity unlocks as the business grows (2nd branch → multi-location appears).
- Pro tools are per-module (dense grid in Stock), not a global skin.
- The agent is present at every altitude.

---

## 2. Design tokens

All values live in `pim-tokens.css`. Key rules:

| Token group | Rule |
|---|---|
| Canvas | Warm paper `#F7F4EE` background (optional grain), white cards, warm `#E7DFD0` borders. **Never** pure grey/blue ERP chrome. |
| Accent | One accent per vertical (pharmacy `#177A52`). Used for brand actions, active nav, and the "healthy" status only. |
| Status tones | Semantic: honey = warning, clay = urgent, sky = informational, accent = healthy. Never decorative. |
| Type | Fraunces (serif) for headings, greetings, and hero numbers; Instrument Sans for UI. Display headings use sentence case with a period: "Stock.", "Money." |
| Shape | 14px base radius, 22px panels, fully-rounded pills for buttons/chips. Soft warm shadows (`--pim-shadow-card`). |
| Motion | 200–300ms ease-out, elements rise 8–14px on enter, stagger ≤70ms. No bounce, no spinners where a typing/skeleton state reads better. |

Do not hardcode hex values in components — map tokens to the design system's
primitives (theme variables, styled-system scales, Tailwind theme — whatever the
repo uses) and reference those.

---

## 3. Voice & writing rules

The copy *is* the design. Non-negotiable:

**Say / don't say**

| Say | Never (in default UI) |
|---|---|
| product | SKU |
| cost | COGS |
| matched / balanced | reconciled |
| the books | journal, ledger |
| order | purchase order (PO-#### only in expert surfaces) |
| runs out Thursday | low stock alert |
| expires in 18 days | expiry variance |
| Dr / Cr | *(allowed only in ledger/look-underneath views)* |

**Pim's speech**

- First person, acts then reports: "Done — PO-1042 placed with MedSource. I'll flag any delay."
- Always pairs a finding with an action: never "3 products low" without "draft the order?"
- Deferrals are graceful: "Okay — I'll raise it again at the evening check."
- Never blames the user; frames everything as taken care of or needing one tap.

**Numbers must carry meaning.** Not "8 units" but "8 packs · runs out Thursday at
your usual pace." Money always formatted, deltas always explained ("up 6% — allergy season").

---

## 4. Component inventory (atomic mapping)

Prototype element → suggested atomic level → behavior & states → port notes.

### Atoms
- **PimAvatar** — green squircle, Fraunces italic "p". Sizes 28/34/40/44. The agent's face; use wherever Pim speaks or acts.
- **ToneDot** — 8px status dot, optional pulse ring (pulse = live/working).
- **Chip** — pill label on soft tone background. Statuses, filters.
- **StockBar** — 6px track bar; turns honey ≤35%, clay ≤15%.
- **PillButton** — variants: `primary` (ink bg → accent-deep hover), `approve` (same, in agent contexts), `ghost` (white, line border), `danger-soft`. After success, buttons become an inline **ConfirmedChip** (accent-soft, check icon) — never just a toast.
- **SegmentedControl** — pill group (density, payment method, customer). Selected = ink fill.
- **QtyStepper**, **SearchInput** (rounded-full, leading icon), **IconButton**.

### Molecules
- **StatCard** — label / hero number (Fraunces) / plain-language note / tone icon.
- **BriefingRow** — ToneIcon + title + 1–2 sentence body + action row (primary, ghost). States: pending → approved (ConfirmedChip) → deferred (50% opacity, italic defer note).
- **FeedItem** — timeline dot + sentence + "time · tag" meta. Agent's autonomous work log.
- **ScheduleItem** — time (Fraunces) + title + note, accent left border.
- **ProductRow** (comfortable) — kind icon, name+detail+Rx badge, status Chip + plain note, StockBar, price, contextual action ("Ask Pim to reorder" → ConfirmedChip).
- **ProductRowCompact** — same data as a dense table row: ToneDot + short status, tabular-nums, inline text buttons. **Same truth, two densities.**
- **SuggestionCard** (order draft) — title, reason ("Runs out Thursday — you sell ~4/day"), qty/supplier/eta/total rows, Approve + dismiss ✕.
- **ChatUserBubble** (ink, rounded-br-sm), **ChatPimMessage** (avatar + open text, no bubble), **RichAnswerCard** (chips + sparkline + action strip inside chat).
- **JournalEntryCard** — "Entry n · title", Dr/Cr account lines (sky=Dr, honey=Cr), mono tabular amounts, "Balanced" footer.
- **TakenCareOfItem** — check/clock + title + note. Invisible-compliance list.

### Organisms
- **BriefingPanel** — gradient header (avatar + "N things need you this morning") + BriefingRows. The home screen's heart.
- **AgentFeed** ("While you were away") + **SchedulePanel** + **InsightCard** (honey tint, "Pim noticed…").
- **AskThread + Composer** — full-height chat, typing indicator (3 dots, ~1s), suggestion chips, sticky rounded composer. Rich answers render cards inline; approvals append a confirmation message.
- **DensityTable** — Stock list with persisted Comfortable/Compact toggle (localStorage).
- **CartPanel** — customer segmented control, line items with QtyStepper, **RxBanner** (unverified: honey, asks for customer; verified: accent, states copay + insurer split), payment segmented (Insurance disabled until Rx verified), big Fraunces total, Charge → **ReceiptPanel** (success check, amount, 3 confirmations: stock counted / books balanced / receipt sent, "Look underneath" link, New sale).
- **LedgerDrawer** — right slide-over: "What Pim wrote in the books", JournalEntryCards derived from the sale, compliance note, "Same truth, two altitudes" footer.
- **OrdersTimeline** — 3-step progress (Ordered/Packed/Arriving) + agent note strip when it self-heals ("I moved 20 packs… — Pim").
- **VerticalSwitcher** — topbar pill → popover listing businesses with per-vertical accent dots; others marked "Soon". Carries the multi-vertical story.
- **Sidebar** — logo (avatar + "Pim / minds the shop"), icon nav (active = accent-soft), bottom **ComplianceHeartbeat** ("Everything's in order…") + user.
- **FloatingAsk** — bottom-right ink pill w/ avatar → Ask. "One question away, everywhere."

---

## 5. Interaction rules (the agentic contract)

1. Routine work is autonomous and narrated in the feed. **Spending money always requires explicit approval.**
2. Approvals resolve inline where they were taken (ConfirmedChip) and ripple to other screens (shared store/events — a sale updates Today stats and Money).
3. Every agent action is reversible or explainable ("Show me", "Look underneath").
4. Latency is theatrical but honest: typing indicator before rich answers (~0.9–1.5s).
5. Empty/quiet states still speak: "All clear — the shop is minded."
6. Error copy follows the same voice; no raw exception text, ever.

---

## 6. Screen specs (summary)

- **Today** — greeting by daypart ("Good evening, Nadia."), BriefingPanel, 4 StatCards, AgentFeed + Schedule/Insight. All briefing actions functional.
- **Ask** — ChatThread + right rail ("Pim can" capabilities, "Handled this morning"). Answers return rich cards with real actions.
- **Stock** — plain summary header, standing agent suggestion ("Draft all three"), filter chips, density toggle, ProductRows. Footer: "Same data, tighter packing…" (compact).
- **Sell** — search/scan + product grid (Rx/OTC badges), CartPanel with Rx verification + insurance copay split, ReceiptPanel → LedgerDrawer.
- **Orders** — SuggestionCards (approve/dismiss), OrdersTimeline with agent self-healing note, received history.
- **Money** — Came in / Went out / Yours to keep hero cards, day-by-day bars, "Where it went" breakdown, TakenCareOf list, InsightCard.

---

## 7. Porting workflow (Kimi CLI + atomic design)

```bash
cd your-repo
kimi            # then: /login  and  /init   (generates AGENTS.md)
```

1. Copy `pim-design-handoff.md` and `pim-tokens.css` into the repo.
2. Append to `AGENTS.md`:
   > Design direction: follow pim-design-handoff.md and pim-tokens.css for all new UI.
   > Map tokens to our design-system primitives; never hardcode colors/radii.
   > Plain-language copy per §3. Port one screen per PR; do not change existing APIs.
3. Then prompt, in order:
   - *"Read pim-design-handoff.md and pim-tokens.css. Map the tokens into our theme/primitives (list the mapping before writing code)."*
   - *"Create the atoms from §4 (ToneDot, Chip, StockBar, PillButton, SegmentedControl) using our existing primitives. Storybook stories for each state."*
   - *"Build BriefingRow + BriefingPanel organisms per §4–5 with our Card atom, including the approved/deferred states."*
   - *"Port the Stock screen with the persisted density toggle — §6."*
   - *"Port Sell including the Rx verification flow and the LedgerDrawer — the journal entries must be derived from the real sale event, not hardcoded."*
4. Review rules: visual check against this handoff each PR; no hex outside the theme;
   copy review against §3; keep your repo's naming/folder conventions — adapt, don't transplant.

If the design system isn't Tailwind, that's fine — tokens are plain CSS variables;
have the agent translate them into whatever theming the repo uses.

---

## 8. Do not port

Mock data, demo names (Nadia, Kareem, suppliers), demo routes. Port *behaviors and
specs*, not fixture content. The journal drawer derives entries from real events;
the density toggle reads real row data; the briefing computes from real signals.
