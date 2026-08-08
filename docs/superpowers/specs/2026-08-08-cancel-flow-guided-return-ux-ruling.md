# Owner UX ruling — guided cancel flow with explicit return decision (2026-08-08)

Refines ruling R-c c1 (`2026-08-07-round2-rulings-record.md`) into the F2 FE contract.
Owner ruling, near-verbatim substance. Applies the document-per-action principle
(`docs/superpowers/audits/2026-08-08-document-per-action-violation-sweep.md`) from the
UX side: **explicit, never silent — but never verbose. One-go completion.**

## The principle (owner's words, condensed)

Inventory movements are separate from cash and accounting. There are interactions between
them and the UI must GUIDE the user so they cannot inadvertently miss an action they
should be making. Whether a return note needs to be created or not, **the decision is
explicit, never silent — whatever it may be.** But not too verbose: if a cancellation is
happening and products are being returned, the user should be able to initiate and
possibly close everything in one go.

## The cancel-invoice contract (canonical example, binding for F2)

On the SAME screen where the user cancels an invoice for delivered products, a
modal/pop-up asks about the goods:

1. **"Products are going to be returned"** → a return note is CREATED pre-linked to the
   cancelled invoice, in its open state (draft / confirmed-not-closed — use the return
   note's existing lifecycle states), for the physical return to complete later.
2. **"Products have already been returned, on date X"** → date field PRE-POPULATED with
   today, editable. The return note is created, confirmed, and closed BEHIND THE SCENES,
   dated as stated, linked to the invoice. The user finds it under return notes but never
   has to create it manually.
3. **(No return — goods genuinely gone)** → per ruling c1: no stock comes back, COGS
   stays; the explicit "no return" choice is itself recorded.

Confirmation messages surface where they prevent misclicks/unintentional actions —
protection against mishaps, not ceremony.

## Constraints carried over

- The return note remains its OWN document with its own lifecycle and GL (lane model
  intact); the modal is orchestration sugar, not a bypass. Behind-the-scenes
  create+confirm+close in option 2 must run the SAME domain transitions as manual
  operation (no side-channel writers).
- Backdating (option 2's date) follows the same period rules as a manually-dated return
  note — closed/filed-period refusals apply (R-c/c2 model).
- Generalize the pattern: any lifecycle action whose lane-companion is commonly needed
  (e.g. supplier-doc cancel once F2's AP mirror lands) gets the same guided-explicit
  treatment.

## Status

- Owner priority 2026-08-08: the document-per-action violation fixes (sweep register) are
  to be fixed BEFORE other work, as a parallel track, in a DEDICATED session with research
  subagents. Complexity sizing in flight; handover doc to follow.
