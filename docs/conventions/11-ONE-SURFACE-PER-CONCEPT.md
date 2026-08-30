# 11. One Surface per Concept — vocabulary and duplicate-surface guardrails

> **The convention, in one sentence:**
> **Every domain concept has ONE canonical name (in `docs/glossary.md`), ONE table, ONE primary write path
> and ONE operator surface; a second name, tile, importer, form, or hand-rolled type for the same concept is a
> finding, not a feature — unless the glossary declares it a synonym and says which surface is canonical.**

Adopted 2026-08-29 (Session I) as the generalisation of the prevention items in
`docs/handoff/RETRO-partner-party-drift-2026-08-23.md` §5 (G1 single-writer invariant, G2 canonical-entity
registry, G4 no shadowed generated DTOs). Session H owns the party/partner *fix*; this convention owns the
*class*.

---

## The incident class

| Drift | What the user saw |
|---|---|
| `ImportType::Parties` and `ImportType::Partners` both wrote `partners`; only one carried balances | tile "Partenaires" silently dropped AR/AP openings (tutorial ⚠️) |
| seven hand-rolled FE `Partner` interfaces beside the generated `PartnerData` | a `tax_id` field that rendered blank for 267 days |
| `customer_category` nullable, one optional writer, gating the whole B2B affordance set | B2B fields invisible for every imported customer |
| `Contact` module: TDD'd, laned, 0 rows in 8 tenant DBs, reachable from nowhere | a healthy module nobody could use |

Diff-scoped review cannot see a duplicate surface: each half is internally consistent, and the reviewer only
sees the half in the diff. The only defence is a **name check against a registry** and a **review question
that asks about the other surface**.

---

## The rule

1. **Glossary first.** Before a spec, brief, migration, module, FE feature or import type introduces a noun,
   look it up in [`docs/glossary.md`](../glossary.md). If it exists → use *that* name everywhere (table, enum,
   route, i18n key stem, FE type, tile). If it does not → add it to the glossary in the same lane, with its
   table, owning module, canonical surface and permitted synonyms. The platform-level glossary
   (monorepo root `claude/glossary.md`, i.e. `../../../claude/glossary.md` from `docs/`) points here for ERP terms.
2. **One primary write path.** A concept has one service that creates/updates it; importers, POS, API and
   seeders call *that* service (`PartnerService::upsertWithTypeMerge`, `ProductService::upsert`). A second
   writer must be declared in the glossary row and justified in the spec.
3. **One operator surface.** One list/form/tile/import type per concept. Presets and filters over the same
   surface are fine (`/import/parties?preset=customers`); a second surface is not. Retire the old one in the same
   lane (`ImportType::deprecationMessage()` is the established mechanism — keep the enum case readable for
   history, hide it from selection).
4. **No shadow types.** FE code imports the generated DTO (`@autoerp/shared/types/generated`); a local
   `interface Partner {…}` beside a generated `PartnerData` is a MAJOR finding (RETRO G4; ESLint rule
   `local/no-shadowed-generated-dto` is the follow-up guard — until it lands, the reviewer question below is
   the guard).
5. **A nullable column may not gate a live affordance** unless something writes it on every creation path
   (RETRO G3). Say in the spec which path writes it.
6. **Synonyms are declared, not discovered.** "Partenaire / Partner / Party / Tiers" is legal only because the
   glossary row for **Party** lists them and names `partners` as the table and `Party` as the canonical term.

---

## Reviewer questions (added to every `.claude/agents/*-reviewer.md`)

> **One surface per concept:** for every noun the diff introduces or renames — is it in `docs/glossary.md`
> under that exact name? Does another table / import type / form / tile / FE type already express the same
> concept (grep the synonyms in the glossary row)? Is there a second writer? A hand-rolled FE type shadowing a
> generated DTO? An undeclared second surface → MAJOR; a second write path that can drop data the primary
> keeps (the Parties/Partners case) → BLOCKER.

## Spec/brief line

Every spec names its nouns in a "Vocabulary" line (`Concepts: Party (glossary ✅), Opening batch (glossary ✅),
Duplicate policy (NEW — glossary row added in this lane)`) — round-0 check 6 verifies the ✅s by grep.

---

## Mechanical guards (existing + owed)

| Guard | State |
|---|---|
| `tests/Architecture/ImportTypeSingleWriterTest.php` — no two selectable import types share a terminal writer (RETRO G1) | Session G lane (imports hardening) |
| Canonical-entity manifest + `tools/canonical-entity-manifest-check.php` (RETRO G2) | owed — glossary rows are the manual precursor; the manifest is generated from them when it lands |
| ESLint `local/no-shadowed-generated-dto` (RETRO G4) | owed |
| Gating-column audit (RETRO G3) | owed |

Until a guard lands, its reviewer question is the guard. Do not remove a question because "the lint will catch
it" until the lint exists and has a liveness test (convention 08).

Related: [09-SECOND-OF-EVERYTHING.md](./09-SECOND-OF-EVERYTHING.md), [10-BENCHMARK-FIRST-SPECS.md](./10-BENCHMARK-FIRST-SPECS.md),
[`docs/glossary.md`](../glossary.md), `docs/handoff/RETRO-partner-party-drift-2026-08-23.md`.
