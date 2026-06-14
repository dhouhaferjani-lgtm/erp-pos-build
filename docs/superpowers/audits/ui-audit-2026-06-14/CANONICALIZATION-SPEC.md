# UI Canonicalization Spec — List & Form Patterns (Phase 3)

> Concrete, file:line-grounded spec for the structural/look-and-feel drift the owner flagged
> ("inputs change, page feel changes between original modules and newer ones"). Drives Phase 3.
> Paths under `apps/web/src/`. Pairs with the visual evidence in `screens/drift-A/B-*.png`.

## The "feel" problem — root cause
There is **no shared page scaffold**. Every page re-declares its header, table, card, and input markup inline. The owner's "inputs change between modules" is literally true — the same logical input renders **four** different ways:

| Variant | Where | Exact classes |
|---|---|---|
| Raw `rounded-lg` (original) | `inventory/ProductForm.tsx:385`, `documents/DocumentForm.tsx:368,430,469,503` | `mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500` |
| `<Input>` atom (= `tokens.input.base`, **`rounded-md`**) | `catalog/CompositeItemFormPage.tsx:214`, `menu/MenuFormPage.tsx:136` | `designTokens.ts:285` |
| Raw `tokens.input.base` string | `catalog/ProductVariantMatrixEditor.tsx:238,258` | token string, no atom |
| Raw search `rounded-md` | `catalog/CompositeItemListPage.tsx:67` | `block w-full rounded-md border-gray-300 pl-10 … focus:ring-blue-500` |

The radius difference (`rounded-lg` original vs `rounded-md` atom) is the single most visible drift — moving from a Document form to a Composite-Item form, the field corners and border weight visibly change.

## Per-module characterization (file:line)
**Headers** — nobody uses a shared component; weight is inconsistent even within one feature:
- `font-bold`: `inventory/ProductListPage.tsx:123`, `documents/DocumentListPage.tsx:270`, `catalog/CategoryManagementPage.tsx:113`, `menu/MenuListPage.tsx:75`
- `font-semibold`: `catalog/CompositeItemListPage.tsx:43`, `CompositeItemFormPage.tsx:190`, `menu/MenuFormPage.tsx:125`

**Lists/tables** — five different treatments, no shared component:
- `inventory/ProductListPage.tsx:268` — raw table, `bg-gray-50` header, `text-end` numeric (294,322), `SortableTableHeader`, `OffsetPagination`, dashed empty state, plain-text loading.
- `documents/DocumentListPage.tsx:339` — raw table, `text-end` totals (357,429), **no pagination**, inline hardcoded status color maps (23-40), plain-text loading.
- `catalog/CompositeItemListPage.tsx:83` — raw table, `ring-1 ring-black ring-opacity-5` wrapper (82), **numeric columns left-aligned** (105, no `tabular-nums`), **hand-rolled `<button>` pagination** (147-154).
- `menu/MenuListPage.tsx:127` — **card grid, not a table**, `hover:border-blue-300` cards, shared `OffsetPagination` (207).
- `catalog/ProductVariantMatrixEditor.tsx:210` — raw table but **fully tokenized**.

**Forms** — a maturity gradient:
- Original (`ProductForm.tsx`, `DocumentForm.tsx`): raw `<label>`+`<input>`, `rounded-lg border-gray-200 bg-white p-6` cards. ProductForm has `<h2>` sections; DocumentForm is flat (no grouping). Both already use `StickyFormFooter` — good.
- Newer (`CompositeItemFormPage.tsx`, `MenuFormPage.tsx`): `tokens.card.base`, `FormField`+`Input`/`Select`/`MoneyInput`, `StickyFormFooter` with `<Button>` — most canonical, but keep raw `<h2>` headers and `font-semibold` page titles.

**Precision violations to fix during migration** (CLAUDE rule 19): `CompositeItemFormPage.tsx:262,487` `parseFloat(base_price)`; `ProductVariantMatrixEditor.tsx:258` raw decimal input (use `<MoneyInput>`); `MenuCategoryItemManager.tsx:212` `step="0.01"` + `:67` `Number(overridePrice)`.

## A. The canonical pattern (target)
**Closest existing exemplar:** `catalog/CompositeItemFormPage.tsx` for forms. No list page is canonical yet — all migrate to the new `DataTable`/`ListPageLayout`.

**List pages:**
```
ListPageLayout (title/subtitle/actions/filters/pagination slots)
  filters={<SearchInput/> + <FilterTabs/>}
  <DataTable columns={[{numeric:true …}]} data isLoading emptyTitle onRowClick/>
  pagination={<OffsetPagination/>}
```
`DataTable` gives canonical header, `tokens.table.rowHover`, `numeric → text-right tabular-nums`, skeleton loading, `EmptyState`. Status cells → `<StatusBadge tone={statusTone(...)}>`, replacing every inline `bg-x-100 text-x-800` map.

**Form pages:**
```
PageHeader title actions={<Button/>}          // replaces raw text-2xl h1 + raw back link
<form>
  <div className={tokens.card.base}>           // one card per section
    <h2 …>section</h2>                          // standardize a heading token
    grid gap-6 sm:grid-cols-2
      <FormField label error><Input/></FormField>   // never raw <input>
      money → <MoneyInput/>, qty → <QuantityInput/>
  <StickyFormFooter><Button secondary/><Button primary/></StickyFormFooter>
```
**Rule: never raw `<input className="rounded-lg…">` — always `<Input>` atom** (settles the radius drift to `rounded-md` globally).

## B. Per-module migration delta (ordered by impact)
- **`documents/DocumentForm.tsx` (L)** — 4 raw input blocks (368,430,469,503) → `FormField`+`Input`/`Select`/`Textarea`; add `<h2>` sectioning; `tokens.card.base`; header (347)+back → `PageHeader`; raw buttons (550,557) → `<Button>`.
- **`documents/DocumentListPage.tsx` (L)** — raw table (339) → `DataTable`; status maps (23-40) → `StatusBadge`/`statusTone`; wrap in `ListPageLayout`; **add pagination**.
- **`inventory/ProductForm.tsx` (L)** — raw fields (356-490) → `FormField`+atoms; price (507) → `<MoneyInput>`; `rounded-lg` cards (349,496,553) → `tokens.card.base`; header (331) → `PageHeader`.
- **`inventory/ProductListPage.tsx` (M)** — table (268) → `DataTable` (keep sort via columns); status pill (327) → `StatusBadge`; header → `PageHeader` (StatCards + grid view stay as extra content).
- **`catalog/CompositeItemListPage.tsx` (M)** — table (83) → `DataTable` (fixes left-aligned numerics, no tabular-nums); pagination (147-154) → `OffsetPagination`; search (67) → `SearchInput`; `bg-blue-600` links → `<Button>`; header → `PageHeader`.
- **`menu/MenuListPage.tsx` (M)** — header (75) → `PageHeader`; status badge (143) → `StatusBadge`; card grid intentional (keep).
- **`catalog/CompositeItemFormPage.tsx` (S)** — header (190) → `PageHeader`; `<h3>` → heading token; fix `parseFloat` (262,487).
- **`menu/MenuFormPage.tsx` (S)** — header (125) → `PageHeader`; raw checkboxes (162,171) + day pills (223 `bg-blue-600`) → token/atom; `<h2>` (133,185,245) → heading token.
- **`catalog/ProductVariantMatrixEditor.tsx` (S)** — section header (150) → heading token; raw price (258) → `<MoneyInput>`; token-string buttons → `<Button>`.
- **`catalog/CategoryManagementPage.tsx` (S)** — header (113) → `PageHeader`; raw textarea (235) → `Textarea`; keep modal footer.

## C. Effort & sequence
L: `DocumentForm`, `DocumentListPage`, `ProductForm` · M: `ProductListPage`, `CompositeItemListPage`, `MenuListPage` · S: the rest.

**Suggested sequence:** canonicalize the two `documents/*` L-files **first** (they are the reference "originals" — fixing them sets the visible standard), then `ProductForm`/`ProductListPage`, then the catalog/menu drift modules. Fold rule-19 precision fixes into each form's pass.

**Two small shared additions this spec implies (do before/early in Phase 3):**
1. A standard **heading token** (e.g. `tokens.heading.section`) so `<h2>`/`<h3>` section titles stop varying — or fold into `PageHeader`/a `SectionCard` molecule.
2. Consider a `SectionCard` molecule (`tokens.card.base` + standardized `<h2>`) since every form repeats it.
