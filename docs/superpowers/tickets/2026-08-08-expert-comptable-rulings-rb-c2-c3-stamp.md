# Expert-comptable rulings batch — 2026-08-08 (owner-relayed, verbatim)

Answers R-b, R-c/c2, R-c/c3 in `2026-08-07-round2-rulings-record.md`, plus the CN-stamp
charge account for the `fix/cn-stamp-gl-alignment` lane. Grounded in the Tunisian market.

## ⚖️ STANDING OWNER DIRECTIVE (applies to every consumer lane below)

> Since we want to be able to get certified everywhere, anything that's country-related
> should be settings not hard-coded. This should be seeded settings rather than hard-coded.
> Think about how to accommodate this for now but still not block us when we want to serve
> France, which is going to be pretty soon.

Concretely: account codes ruled below (4375, 6654, 6588/7588) are **TN chart-of-accounts
values** and must resolve through seeded, country-specific account-mapping settings — never
literals in service code. Every consumer lane must (a) route through the existing account
mapping/seeder mechanism, (b) leave the mapping overridable per country, (c) not block a
future FR seed (e.g. FR 6354 vs TN 6654 for the same semantic slot).

---

## Q1 → R-b — timbre rounding residue — ✅ ANSWERED: branch (2), sub-decisions 2a=PROSPECTIVE, 2b=TN-DEDICATED SEEDED ACCOUNT

**Option retenue : Option (2) — Isoler les poussières d'arrondi dans un compte d'écarts dédié.**

Verbatim:
> * Le compte 4375 (État - Droit de timbre collecté) est un compte de passif/tiers à solde
>   créditeur représentant la dette exacte envers le Trésor Public (calculée à raison de
>   1,000 DT exigible par facture/ticket au comptant assujetti).
> * Pour garantir une piste d'audit conforme aux exigences des contrôles fiscaux et des
>   réconciliations de la Déclaration Mensuelle d'Impôt (DMI - rubrique Droit de timbre),
>   le compte 4375 ne doit porter que la dette fiscale réelle.
> * Les écarts d'arrondi de quelques millimes générés par les calculs de l'ERP doivent être
>   isolés au débit/crédit d'un compte de charges/produits d'exploitation dédié :
>   6588 / 7588 (Écarts d'arrondi sur facturation).
>
> Modalités d'application :
> * Correction PROSPECTIVE uniquement : Selon la NC 11 (Changements d'estimations) et le
>   principe de l'importance relative (NC 01, § 16), s'agissant de montants minimes
>   (de minimis), aucun retraitement rétrospectif n'est nécessaire.
> * Choix du compte : Utiliser un compte TN dédié (6588 / 7588) plutôt qu'un compte partagé,
>   afin de garder une traçabilité claire lors de la révision comptable.

**Branch selections:** (2) split dust out of 4375 · (2a) prospective-only — NO historical
restatement, NO quantification prerequisite · (2b) TN-specific dedicated accounts 6588
(charge) / 7588 (produit), seeded rows — NOT the shared SalesRoundingDifference account.
**Consumer: lane R2-M** (GL/accounting + release/data + treasury gates; seeder/migration-
bearing because 2b adds accounts). 4375 carries ONLY true stamp liability going forward.

## Q3 → R-c/c2 — purchase-doc cancel in CLOSED/FILED VAT period — ✅ ANSWERED: reverse-in-current-period (extourne)

**Option retenue : Extourne / Régularisation sur la période courante non clôturée.**

Verbatim:
> * En droit fiscal tunisien (Code de la TVA et CDPF), une déclaration mensuelle d'impôt
>   déposée et clôturée est intangible au niveau de la comptabilité. L'ERP ne doit en aucun
>   cas rouvrir ou altérer les écritures d'une période fiscale déposée.
> * L'annulation d'une facture d'achat (ou l'enregistrement d'un avoir fournisseur
>   rectificatif) se rapportant à un exercice ou un mois clos doit s'effectuer par une
>   écriture à la date de la période courante ouverte.
> * Impact fiscal DMI : La TVA déductible initialement déduite en trop est régularisée sur
>   la déclaration TVA du mois en cours (dans la case dédiée aux reversements /
>   régularisations de TVA déductible).

**Adoption constraint (unchanged, from the record):** reverse-in-current CANNOT be enabled
until F2's AP mirror exists — `AccountingService::reverseDocumentGl()` returns null for
supplier docs today, so permitting the cancel now would strand AP/GR-IR legs forever.
**F1's shipped default-refusal STAYS as the interim behavior** (it was built explicitly
reversible). Note the DMI mechanics: the correcting entry lands in the CURRENT period and
the over-deducted input VAT goes to the reversements/régularisations box — this shapes F3's
declaration model too. **Consumers: F2 (AP mirror + reversal entry dated current open
period), then F1 flips refusal → guided extourne.**

## Q4 → R-c/c3 — declaration reconciliation after extourne — ✅ ANSWERED: net aggregation on the declaration + distinct correction lines in the audit trail

**Option retenue : Agrégation nette sur la déclaration fiscale, tout en conservant
l'émission de lignes de correction dans la piste d'audit ERP.**

Verbatim:
> * Sur le plan fiscal (Déclaration DMI) : Le barème de la déclaration mensuelle d'impôt en
>   Tunisie retient le montant net de TVA exigible/déductible sur le mois. L'ERP calcule
>   les bases et la TVA collectée/déductible nette (Chiffre d'affaires / Achats bruts
>   diminués des avoirs et extournes de la période).
> * Sur le plan comptable et système (Piste d'audit NC 01) : Pour respecter la piste
>   d'audit obligatoire, l'ERP doit générer des lignes d'écritures de correction distinctes
>   (via l'émission d'un document correctif/avoir lié au document initial) et ne jamais
>   supprimer ou écraser les lignes d'origine.

**Branch selection:** BOTH halves — declaration aggregates NET per month; the ERP audit
trail emits DISTINCT correction lines carried by a correcting document/avoir LINKED to the
original (consistent with owner ruling c4: corrections are documents, always;
`source_document_id` mandatory). Never delete or overwrite original lines.
**Consumer: F3** (reversal-aware net aggregation + correction-row emission), composing with
c4's correcting-document type (F4).

## Q6 → CN-stamp charge account — ✅ CONFIRMED: 6654 (TN PCG), not 6354 (FR)

**Compte retenu : 6654 (Droits d'enregistrement et de timbre).**

Verbatim:
> * Vérification de la nomenclature officielle du PCG Tunisien (NC 01) :
>   * La classe 6 regroupe les charges d'exploitation.
>   * La sous-classe 66 désigne les Impôts, taxes et versements assimilés.
>   * Le compte 6654 est l'intitulé exact pour « Droits d'enregistrement et de timbre »
>     dans le plan comptable tunisien.
> * (Rappel : le compte 6354 appartenait à la nomenclature du PCG français, le compte
>   correct en normes tunisiennes est bien le 6654.)

**Consumer: `fix/cn-stamp-gl-alignment` lane** (worktree `../erp.fix-q1-cn-stamp-gl`): the
CN charge-fiscale leg is Dr 6654 / Cr 4375 (stamp payable), TN seed. The FR seed will map
the same semantic slot to 6354 — this pair is the canonical example of the seeded-settings
directive above.

---

## c1-bis / D1 — inventory accounting model — ✅ ANSWERED 2026-08-08 (expert-comptable, via owner)

**Recommandation Directe : Core Model en Inventaire Permanent, paramétrable par Société /
Pays (Country Setting).**

Verbatim:
> Adopter l'Inventaire Permanent comme Standard Core Model :
> * Obligatoire pour maximiser la valeur de votre ERP.
> * Totalement conforme à la NCT 04 en Tunisie et aux normes IFRS.
>
> Conserver le paramètre au niveau de la Société (Company Code / Valuation Area) :
> * Souplesse réglementaire : Permet de s'adapter si une filiale étrangère spécifique
>   impose des schémas comptables locaux particuliers.
> * Gestion des consommables : Permet de traiter certaines catégories secondaires
>   (fournitures de bureau, pièces de faible valeur) en mode "Intermittent/Expense-based"
>   sans impacter le stock principal.

**Consequences:** core model = PERPETUAL (COGS recognized at stock exit — delivery-note
confirm / POS receipt projection — consistent with ruling c1's lane model); valuation mode
is a COMPANY-LEVEL SEEDED SETTING (country-defaulted, per the seeded-settings directive);
secondary/consumable product categories may carry a per-category expense-based override
that bypasses the stock lane. Return-note confirm re-debits inventory / credits COGS.
Consumers: G1-family design in the DPA remediation track
(`docs/handoff/HANDOVER-document-per-action-remediation-2026-08-08.md` §3 — research
mandate re-scoped from "which model" to implementation validation + parameter/category
design), plus D1/D2 of `2026-08-07-cogs-lane-mismatch.md`.

## Still OPEN after this batch

- (none — c1-bis answered above.)
- Owner rows R-d, R-e, R-f, R-g, R-h (`fiscal:backfill`), R-i (`credit-notes.confirm`
  permission) — unchanged.
