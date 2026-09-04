# Audit read-only — travaux ouverts de Dhouha (`dhouhaferjani-lgtm`)
Date : 2026-09-04 · Repo : `otospexsolutions/erp` · `origin/dev` = `4d5b8812e` (fetch 2026-09-04)
Aucun merge, aucun push, aucun commentaire PR, aucune modification du checkout principal.
Worktree de lecture `.worktrees/review-pr` créé sur `205b889b2` puis supprimé.

---

## 1. Inventaire

### 1.1 PR ouvertes (toutes de `dhouhaferjani-lgtm`)

| PR | Titre (abrégé) | base ← head | updatedAt | draft | mergeable | CI (`gh pr checks`) |
|---|---|---|---|---|---|---|
| #200 | fix(topbar): profile menu above page actions | `dev` ← `fix/topbar-profile-menu` | 2026-07-18 | **oui** | CONFLICTING | CodeRabbit `pass` (« review skipped: draft ») — **aucun job de CI n'a tourné** |
| #201 | Plan de salle — éditeur complet (CDC-6/7/8) | `dev` ← `feat/floor-plan-view` | 2026-07-23 | non | CONFLICTING | **7 jobs FAIL** (Deptrac, Pint, ESLint, tsc, POS Vitest, T6 PG, Treasury PG) · PHPStan `pass` |
| #202 | Décor du plan de salle (DC-002) | `feat/floor-plan-view` ← `feat/decor-items` | 2026-07-24 | non | MERGEABLE | react-doctor `pass` uniquement |
| #203 | Barista workstation (DC-003 a) | `feat/decor-items` ← `feat/barista-workstation` | 2026-07-24 | non | MERGEABLE | react-doctor `pass` uniquement |
| #204 | Pending order slips (barista → caisse) | `feat/barista-workstation` ← `feat/pending-orders` | 2026-07-27 | non | MERGEABLE | react-doctor `pass` uniquement |
| #205 | CDC-12 plan de salle dans la caisse | `feat/pending-orders` ← `feat/register-floor-plan` | 2026-07-29 | non | MERGEABLE | react-doctor `pass` uniquement |
| #206 | Chrono d'occupation + note par table | `feat/register-floor-plan` ← `feat/table-occupancy-timer` | 2026-08-03 | non | MERGEABLE | react-doctor `pass` uniquement |
| #207 | NACEF PR1 — couture « régimes de certification » | `dev` ← `feat/nacef-regime-seam` | 2026-07-31 | non | CONFLICTING | **8 jobs FAIL** (Deptrac, Pint, ESLint, PG tests, Types-drift, POS Vitest, Treasury PG, §14.3 chokepoint) |

> **Constat structurel majeur (nouveau, pas dans la revue du 2026-08-02).**
> Le `MERGEABLE / CLEAN` affiché sur #202→#206 est un **artefact de base branch** : ces PR ciblent ses propres branches, donc GitHub ne lance que `react-doctor` + un CodeRabbit désactivé. **La suite complète (`preflight`) n'a jamais tourné sur 5 des 7 PR de la chaîne.** Seules #201 et #207, qui ciblent `dev`, sont réellement testées — et les deux sont rouges.

### 1.2 PR fusionnées / fermées depuis 2026-08-01
`gh pr list --state merged` et `--state closed` filtrés sur son login : **aucune**. Ses trois branches de correctifs (§4) ont été intégrées à `dev` **sans passer par une PR**.

### 1.3 Branches `origin/*` de sa main

```
2026-08-05 Dhouha origin/docs/client-bugs-2026-08-05
2026-08-04 Dhouha origin/fix/import-cp1252-encoding
2026-08-04 Dhouha origin/fix/api-nginx-client-body-perms
2026-08-03 Dhouha origin/feat/table-occupancy-timer
2026-07-31 Dhouha origin/feat/nacef-regime-seam
2026-07-28 Dhouha origin/feat/register-floor-plan
2026-07-27 Dhouha origin/feat/pending-orders
2026-07-24 Dhouha origin/feat/barista-workstation
2026-07-24 Dhouha origin/feat/decor-items
2026-07-23 Dhouha origin/feat/floor-plan-view
2026-07-20 Dhouha origin/fix/topbar-dropdown-stacking   (aucune PR ouverte)
2026-07-18 Dhouha origin/fix/topbar-profile-menu
```

### 1.4 A-t-elle poussé depuis la revue du 2026-08-02 ?

`git log --all --author=Dhouha --since=2026-08-01 --oneline` → **7 commits, tous entre le 2026-08-03 et le 2026-08-05, puis plus rien depuis 30 jours** :

| SHA | Date | Objet |
|---|---|---|
| `f140851d7` | 08-03 | fix(pos): gate the register floor plan on Tables, not Menu — **répond au P1 (4)** |
| `0aa7e42ec` | 08-03 | fix(pos): stop the sync from wiping the table seat stamp (bug PO terrain) |
| `8a60a863f` | 08-04 | fix(api): nginx client-body temp dir writable |
| `230074b55`, `dd516461a`, `bca8dfbe6` | 08-04 | fix(import): conversion CP1252 → UTF-8 |
| `fe0df479e` | 08-05 | docs(bugs): bugs clients 2026-08-05 |

**Elle a donc traité exactement 1 des 7 P1 (le n°4), puis s'est arrêtée.** Aucun rebase, aucune réponse aux 6 autres P1, aucune activité depuis le 2026-08-05.

### 1.5 Doc de méthodologie
`docs/sessions/2026-08-02-retour-methodologie-dhouha.md` **existe en local** (12 077 octets, 2026-08-02 12:51) mais **n'est dans aucun commit** (`git log --all -- <path>` vide) : `docs/sessions/` est gitignoré (règle CLAUDE.md n°15). **Rien ne prouve qu'elle l'ait reçu.** À lui transmettre explicitement (hors repo) avant toute nouvelle attente de conformité.

---

## 2. État par PR ouverte

### 2.1 Distance à `dev` et conflits (simulation `git merge-tree --write-tree`, sans checkout)

| Branche | tip | derrière `dev` | devant | fichiers en conflit |
|---|---|---|---|---|
| `fix/topbar-profile-menu` (#200) | `659aadace` | **3223** | 2 | 2 (`TopBar.tsx`, `TopBar.test.tsx`) |
| `feat/floor-plan-view` (#201) | `3d8a7d255` | **3223** | 49 | 3 (`Sidebar.tsx`, `designTokens.ts`, `routes.test.tsx`) |
| `feat/decor-items` (#202) | `492bbec14` | 3223 | 64 | 3 |
| `feat/barista-workstation` (#203) | `d1bcf105a` | 3223 | 87 | 4 (+ `features/pos/api/orderApi.ts`) |
| `feat/pending-orders` (#204) | `45e1e4df3` | 3223 | 95 | **12** (dont `apps/pos/src/lib/db/migrations.ts`, add/add `migrations.v61.test.ts`, `apps/pos/eslint.config.js`) |
| `feat/register-floor-plan` (#205) | `00b8b4163` | 3223 | 113 | **17** |
| `feat/table-occupancy-timer` (#206, tip de chaîne) | `0aa7e42ec` | **3223** | 121 | **17** (dont `PaymentRepositoryController.php`, `TerminalController.php`, `refundCheckoutStore.ts`, `Header.tsx`) |
| `feat/nacef-regime-seam` (#207) | `205b889b2` | **2980** | 45 | 3 (`TerminalController.php`, `bootstrap/app.php`, `packages/shared/types/generated.d.ts`) |

Base commune de toute la chaîne : `de5983c81` (merge-base inchangée depuis la revue). **Aucun rebase n'a eu lieu.** L'écart est passé de **526** commits (2026-08-02) à **3223** — il a été **multiplié par 6**. Les 10 fichiers en conflit signalés en août sont devenus **17**.

Volumétrie par PR (diff vs sa base directe) :
`#200` 2 fichiers / +66 · `#201` 50 / +7675 · `#202` 37 / +5274 · `#203` 30 / +3062 · `#204` 66 / +5542 · `#205` 66 / +7727 · `#206` 23 / +853 · `#207` 35 / +2069.

### 2.2 Statut des 7 P1 de la revue 2026-08-02, au tip de chaîne `0aa7e42ec`

| # | P1 | Statut | Preuve |
|---|---|---|---|
| 1 | Collisions de numéros de migration SQLite POS v61/62/63 | ❌ **TOUJOURS PRÉSENT — aggravé** | Sa branche : `apps/pos/src/lib/db/migrations.ts:1900` `v61 create_pending_drafts`, `:1920` `v62 add_table_id_to_held_transactions`, `:1929` `v63 add_seated_at_to_tables_cache`. `origin/dev` a désormais `:1900` `v61 add_suggested_qty_to_replenishment_cache`, `:1914` `v62 add_quantity_decimals_to_products`, `:1941` `v63 cash_rounding_policy_cache_and_receipt_columns` **et va jusqu'à v67** (`:2164 add_v4_refund_authoring_ack_error_to_terminal_state`). Renumérotation obligatoire → **v68/69/70**. Le conflit `add/add` sur `apps/pos/src/lib/db/__tests__/migrations.v61.test.ts` le confirme mécaniquement. Sur un appareil déjà migré en v63 « dev », la migration « Dhouha » v63 ne sera **jamais rejouée** → schéma silencieusement faux. |
| 2 | Chaîne très en retard, fichiers en conflit | ❌ **TOUJOURS PRÉSENT — aggravé** | 526 → **3223** commits de retard ; 10 → **17** fichiers en conflit (tableau §2.1). `PaymentRepositoryController.php` et les migrations `apps/pos` sont bien parmi eux, comme annoncé. |
| 3 | Exposition des coordonnées bancaires au caissier | ❌ **TOUJOURS PRÉSENT** | `apps/api/app/Modules/Treasury/Presentation/routes.php:63-64` (sa branche) : le middleware `->middleware('can:repositories.view')` est **supprimé** de `payment-repositories.index` (idem `payment-methods.index`, ligne 37). Remplacé par un `Gate::any(['pos.operate_terminal','treasury.view','repositories.view'])` **dans le contrôleur** (`PaymentRepositoryController.php:33-35`). Or `formatRepository()` (même fichier, ~l.325-344) sérialise `account_number`, `iban`, `bic` **pour chaque dépôt de la société**. Conséquence : tout caissier porteur de `pos.operate_terminal` peut lire les IBAN/RIB de tous les comptes bancaires. Deux violations de règles en prime : middleware d'autorisation déplacé dans le contrôleur (règle 12) et facade `Gate::` au lieu d'injection (règle 13). `origin/dev` conserve `can:repositories.view` (`routes.php:66-68`). **C'est le blocant n°1.** |
| 4 | Gating du plan de salle sur `Menu` au lieu de `Tables` | ✅ **CORRIGÉ** | `f140851d7`, `apps/pos/src/pages/HomePage.tsx:185` `const hasTablesModule = hasModule(companyConfig,'Tables')`, appliqué l.1436, 1491, 1833. Test rouge-d'abord ajouté dans `HomePage.paneInvariant.test.tsx` (venue Menu-seul → pas de plan mais toggle conservé). **Correctif propre et bien argumenté.** |
| 5 | Le rappel de note de table force `consumption_mode` (champ fiscal signé) | ❌ **TOUJOURS PRÉSENT** | `apps/pos/src/pages/HomePage.tsx:1134` `useSaleContextStore.getState().setConsumptionMode('SUR_PLACE')` sur `confirmTableNotePrompt`, écrasant le mode d'origine de la transaction mise en attente. Même forçage en `apps/pos/src/lib/pendingDrafts/pendingDraftActions.ts:104` et `apps/pos/src/lib/tableSeating.ts:99` et `:148`. `consumption_mode` finit dans le `SALE_RECEIPT` signé. Défendable sémantiquement (une table = sur place) mais **non documenté, non testé au niveau du reçu, non validé par le propriétaire**. |
| 6 | `acceptPendingDraft` non idempotent | 🟡 **PARTIELLEMENT ADRESSÉ** | `pendingDraftActions.ts:106-113` : un `ApiRequestError` 409 déclenche `refreshInbox()` + retour `{kind:'conflict'}` — la double-acceptation côté serveur est désormais absorbée. **Reste ouvert** : si `acceptPendingDraftOnServer()` (l.96) réussit et que `markAccepted()` (l.97) échoue, le catch fait un rollback du panier et **re-throw**, laissant la ligne locale en `pending` alors que le serveur a accepté — état divergent device/serveur, rattrapé seulement au prochain 409. Aucun test ne couvre ce chemin. |
| 7 | Le passage de l'atome `Button` à `cn()`/`twMerge` change 22+ appelants | ❌ **TOUJOURS PRÉSENT — sous-estimé** | `apps/web/src/components/atoms/Button/Button.tsx:53-60` (sa branche) remplace le template-literal par `cn(...)` = `twMerge(clsx(...))` (`apps/web/src/lib/utils.ts:1-6`). `origin/dev` a **703 usages `<Button`** dans `apps/web/src/**/*.tsx`. `twMerge` **supprime** les classes Tailwind jugées conflictuelles avec les classes de variante ; toute surcharge locale change de comportement. Aucune preuve visuelle fournie. Modification d'un atome partagé hors périmètre CDC (règle 4). |

**Bilan : 1 P1 corrigé, 1 partiellement, 5 intacts — dont 2 aggravés par le seul écoulement du temps.**

### 2.3 Autres constats sur la chaîne (non couverts par la revue d'août)

- **Débordement de périmètre sur des fichiers partagés** (règle 4) :
  - `AGENTS.md` (racine, convention de commits de tout le repo) modifié dans `45e1e4df3`.
  - `apps/web/vite.config.ts` réécrit en `defineConfig(({mode}) => …)` + `loadEnv` dans `8ceacaf49` (« per-worktree dev port ») — config de dev partagée par toute l'équipe.
  - `apps/web/package.json` : ajout d'un script `test:e2e:cdc10`, plus deux configs Playwright ad hoc `playwright.cdc9.config.ts` / `playwright.cdc10.config.ts` (prolifération de surfaces, règle 22 « one surface per concept »).
  - `apps/pos/eslint.config.js` : `75eb7e626` ré-étend le garde `cartMutatorSelectors`. **`origin/dev` a restructuré exactement le même bloc depuis** (commentaire `B4 (Lane B, 2026-07-31)`, `eslint.config.js:346`) → conflit certain **et** travail redondant. Le self-test `src/lib/stock/__tests__/cartMutatorGuard.eslint.test.ts` échoue déjà dans la CI de #201 (`expected 0 to be greater than or equal to 2`) : le garde ne se déclenche plus.
- **Artefacts de session commités** : 23 fichiers `.superpowers/sdd/*-report.md` sont dans l'arbre de la chaîne. La règle 15 impose `docs/sessions/` (gitignoré). À nettoyer avant tout cherry-pick.
- **Attribution des rouges CI de #201** : le `tsc` échoue sur **son** fichier — `apps/web/src/features/pos/hooks/useLiveFloor.test.ts:102` `TS2322 … not assignable to 'OffsetPaginationMeta & { timestamp: string }'` → **régression à elle**. Les échecs POS Vitest (`syncService.test.ts`, `pendingCustomerSyncService.test.ts`, `ReportsMenu.test.tsx`, `offlineFirstFlow.test.ts`) portent sur des specs de `dev` cassées par le merge de sa chaîne : **majoritairement dette de base / conflit, à ré-attribuer après rebase**.

### 2.4 Son travail est-il devenu redondant ?
Non. `origin/dev` possède déjà le back-end Tables (`apps/api/app/Modules/POS/Domain/Floor.php`, `Presentation/Controllers/TableController.php`, `Application/Services/TableManagementService.php`, `routes_tables.php`) et un `apps/pos/src/lib/db/repositories/tableRepository.ts`, mais **`seated_at` n'existe nulle part sur `dev`**, et aucun `pending_draft` POS non plus. L'éditeur de plan de salle web et le chrono d'occupation restent **du net-nouveau**. Le plan de cherry-pick du propriétaire (garder #201 config back-office, #205 pont d'occupation, #206 chrono ; parquer #203/#204) **reste valide sur le fond**.

---

## 3. PR #207 — `feat/nacef-regime-seam` (jamais revue)

**Périmètre** : 35 fichiers, +2069/−19, 45 commits (2026-07-27 → 07-31), dont ~10 commits de spec/plan/gate en français. Dark launch d'une couture « régimes de certification » : éligibilité dérivée (pays + verticale) + activation enregistrée, exposée en lecture seule sur le bootstrap terminal POS. Aucun écran, aucune route gatée.

### 3.1 Ce qui est bon (et il y en a beaucoup)

| Critère | Verdict | Preuve |
|---|---|---|
| Enums pour statut/type (règle 9) | ✅ | `apps/api/app/Enums/CertificationRegime.php:7-10`, `apps/api/app/Enums/CertificationStatus.php:7-11`. Placement cohérent avec l'existant (`app/Enums/{ModuleName,Vertical,Product}.php`). Castés sur le modèle : `CertificationActivation.php:45-52`. |
| Migration tenant vs central | ✅ | `apps/api/database/migrations/tenant/2026_07_27_100000_create_certification_activations_table.php` — bon répertoire (`tenant/`), FK `company_id` cascade (`:16-18`), `timestampTz` (`:21-22`). |
| Règle 22 « second-of-everything » / unicité | ✅ | `:28-32` — index unique **partiel** `(company_id, regime_code) WHERE deactivated_at IS NULL`. Porte bien `company_id` : ne déclenchera pas `TenantOnlyUniqueOnCatalogueTablesRatchetTest`. Le mono-régime actif est garanti en base, pas en PHP. |
| Injection par constructeur (règle 13) | ✅ | `CertificationActivationResolver.php:25-29`, `CertificationActivationObserver.php:12-14`, `RequireCertification.php:18-21`, `TerminalController.php:43-46`. Aucun `app()`. Binding en `CompanyServiceProvider::register()`. |
| Typage strict (règle 3) | ✅ | `declare(strict_types=1)` partout ; `final readonly` sur services/middleware ; annotations `@return list<…>` ; aucun `mixed`, aucun `any` TS. |
| Types depuis le back (règle 7) | ✅ côté web | `RegimeActivation.php:12` porte `#[TypeScript]`, la DTO générée est bien commitée (`packages/shared/types/generated.d.ts` → `App.Shared.Contracts.Certification.RegimeActivation`). |
| Tests présents et significatifs | ✅ (majorité) | `tests/Feature/Certification/DarkLaunchTest.php:123-167` : `assertExactJson` du payload terminal complet — prouve l'additivité **exacte** du champ `certifications: []`. `:180-222` : cas éligible avec `Http::fake()` + `Http::assertNothingSent()` + `assertDatabaseCount('fiscal_events', 0)` — prouve l'absence d'appel externe et de tout effet fiscal. C'est de la preuve de comportement, pas de forme. |
| i18n | n/a | Aucune chaîne visible utilisateur introduite (dark launch, pas d'écran). |
| Règle 19 précision monétaire | n/a | Aucun champ monétaire ni quantité touché. |
| `tenantScopedKey` | n/a | Aucune requête TanStack ajoutée. |

### 3.2 Constats — BLOQUANTS

**B-1 — Type FE dupliqué à la main à côté d'une DTO générée (règles 7 et 22).**
`apps/pos/src/stores/terminalStore.ts:28-33` déclare :
```ts
export interface CertificationActivation {
  regime_code: string;              // ← perd l'union 'TN-NACEF'
  status: 'eligible' | 'enforcing';
  plugin_version: string | null;
  enforced_at: string | null;
}
```
alors que `packages/shared/types/generated.d.ts` porte déjà `App.Shared.Contracts.Certification.RegimeActivation` avec `regime_code: App.Enums.CertificationRegime`. Circonstance atténuante réelle : **`apps/pos/tsconfig.json` n'expose pas `@autoerp/shared`** (`paths` ne contient que `"@/*"`, pas de `typeRoots` vers `packages/shared/types` — contrairement à `apps/web/tsconfig.json:47-49`). Le vrai correctif est de câbler `packages/shared` dans `apps/pos`, pas de recopier le type. **À trancher par le propriétaire ; en l'état c'est une divergence de contrat qui ne sera signalée par aucun garde.**

**B-2 — Deux interfaces mortes, purement spéculatives, typées `Data` de bout en bout (règles 1 et 3).**
`apps/api/app/Shared/Contracts/Certification/CertificationRegimeContract.php:16-33` (8 méthodes) et `SecurityElementClient.php:15-22` (3 méthodes) **n'ont aucune implémentation et aucun appelant** (grep sur tout `apps/` : zéro hit hors du répertoire lui-même). Toutes leurs signatures prennent et rendent `Spatie\LaravelData\Data` — la classe de base, c'est-à-dire `mixed` déguisé : PHPStan niveau 8 ne peut rien vérifier. Commentaire assumé : « Concrete wire DTOs and the S-MDF adapter arrive with PR2 ». **À sortir de PR1** : ces deux fichiers n'ajoutent aucune garantie et figent une API non éprouvée.

**B-3 — CI intégralement rouge, jamais reprise depuis le 2026-07-31.**
8 jobs en échec. Attribution faite depuis les logs :
- `Generated Types Drift Guard` → le diff manquant est `ReplayPreviewMode` et `TerminalSyncHealthState`, **des types de `dev`**, pas d'elle : **dérive de base**, pas sa régression. Ses propres types sont bien régénérés.
- `Backend Lint (Pint)` → ~23 fichiers fautifs, **tous hors de son diff** (`tests/Feature/{Product,Treasury,Inventory}/…`, `StockThresholdService.php`) : **dette de base**.
- `§14.3 Chokepoint Completeness Gate` → `UNRECONCILED: apps/api/app/Modules/Inventory/Presentation/Controllers/InventoryCountingController.php:135` : **dette de base**.
- `Deptrac ratchet` → total 61 → 97, `ModuleDomain on ModuleApplication +12 BLOCKER`, `SharedContracts on ModuleDomain +20`. **Non attribuable en l'état** : aucun de ses fichiers `Domain/` ne dépend d'`Application/`, et la baseline elle-même a bougé sur `dev` depuis. Exige un re-run après rebase avant d'accuser qui que ce soit.
En clair : **le rouge de #207 est dominé par la dérive de base, pas par son code.** Mais il ne pourra pas être levé sans rebase.

### 3.3 Constats — MAJEURS (non bloquants)

- **M-1 · Tests de forme de code plutôt que de sens (règle 22).** `tests/Architecture/CertificationEnforcedAtCompletenessTest.php:12-29` fait un `preg_match` sur 12 fichiers pour vérifier que la chaîne `activated_at` n'y apparaît pas. Zéro garantie comportementale, et fragile : les chemins sont en dur, dont `base_path('../../apps/pos/src/stores/terminalStore.ts')` — casse au moindre déplacement. Idem l'allowlist de 17 chemins en dur de `DarkLaunchTest.php:43-61` + `dirname(base_path(), 2)` (`:236`) : intention louable (confiner le dark launch), implémentation qui deviendra rouge à chaque renommage.
- **M-2 · Service-locator déguisé.** `CertificationActivationResolver.php:28` injecte `Illuminate\Contracts\Container\Container` pour faire `$this->container->make($definitionClass)` (`:148`). Ce n'est pas `app()`, mais c'est le même couplage. Un tagged-service Laravel ou un tableau d'instances injecté respecterait mieux la règle 13.
- **M-3 · Résolution d'éligibilité mise en cache 60 s, invalidation partielle.** `:36-56` : le cache d'**éligibilité** (`certification-eligibility:<companyId>`) n'a **aucun** invalidateur — seul le cache d'**enforcement** est purgé par `CertificationActivationObserver` (`:26-31`, via `enforcementCacheKey`). Le commentaire (`:31-35`) l'assume (« self-heal at TTL »), mais un changement de `companies.country_code` ou de `tenants.vertical` reste faux jusqu'à 60 s. Acceptable en dark launch, **à revoir avant PR2 quand le régime gatera des routes**.
- **M-4 · `RegimeActivation` mis en cache tel quel.** `remember()` sérialise une `Spatie\LaravelData\Data` et un enum. Fonctionne, mais rend le cache sensible à toute évolution de forme de la DTO (déserialisation d'un objet obsolète). Un cache de tableau scalaire serait plus sûr.
- **M-5 · Mutation d'état sur une `JsonResource`.** `TerminalResource.php:20-30` : champ privé `$certifications` + `withCertifications()`. Conséquence : le champ n'est peuplé **que** dans `TerminalController::show()` (`:93-108`) ; tous les autres endpoints terminal (`claim`, `request`, `by-device`, `available`) renvoient `certifications: []`, ce que le store POS masque via `normalizeTerminal()` (`terminalStore.ts:243-248`). Inoffensif tant que c'est inerte ; **piège dès que le régime deviendra `enforcing`** (un terminal fraîchement réclamé se croira non certifié).
- **M-6 · Middleware `certification` enregistré, appliqué nulle part.** Alias déclaré `bootstrap/app.php:53`, `RequireCertification.php` complet et testé, mais **aucune route ne l'utilise**. Intentionnel (dark launch) et couvert par l'allowlist — à noter simplement comme code non exercé en production.

### 3.4 Verdict #207

> **CHANGES — proche du merge, meilleur travail de la série.**

C'est de loin sa PR la plus propre : TDD réel (rouge→vert commit par commit, 45 commits « Phase 1.0.x »), preuves d'inertie exactes (`assertExactJson` + `Http::assertNothingSent` + `assertDatabaseCount('fiscal_events',0)`), enums, injection, migration tenant bien placée avec index unique portant `company_id`, DTO générée commitée. Rien de fiscalement dangereux, rien de monétaire, aucune surface utilisateur.

Conditions de merge, dans l'ordre :
1. **Rebase sur `origin/dev`** (2980 commits ; 3 conflits seulement : `TerminalController.php`, `bootstrap/app.php`, `generated.d.ts`), puis régénérer `generated.d.ts` et relancer la CI. C'est le préalable à toute ré-attribution des rouges.
2. **B-2** : retirer `CertificationRegimeContract.php` et `SecurityElementClient.php` de PR1.
3. **B-1** : soit câbler `packages/shared/types` dans `apps/pos/tsconfig.json` et consommer `App.Shared.Contracts.Certification.RegimeActivation`, soit obtenir un accord explicite du propriétaire pour le type recopié.
4. **M-5** : décider maintenant si `certifications` doit être peuplé sur **tous** les endpoints terminal (recommandé) avant que PR2 ne s'appuie dessus.
5. M-1/M-2/M-3/M-4 : à traiter en suivi, pas bloquants pour un dark launch.

---

## 4. Branches sans PR

| Branche | Ancêtre de `origin/dev` ? | Contenu | Action |
|---|---|---|---|
| `origin/fix/import-cp1252-encoding` (`bca8dfbe6`) | ✅ **fusionnée** (`merge-base --is-ancestor` OK ; merge-base = son propre tip) | Conversion Windows-1252 → UTF-8 champ par champ à l'import CSV, + test sur les en-têtes accentués | Le code est bien sur `dev` (`apps/api/app/Modules/Import/Services/SpreadsheetParserService.php`). **Supprimer la branche distante.** |
| `origin/fix/api-nginx-client-body-perms` (`8a60a863f`) | ✅ **fusionnée** | `apps/api/Dockerfile` : répertoire temporaire client-body nginx accessible en écriture au worker `www` | Sur `dev`. **Supprimer la branche distante.** |
| `origin/docs/client-bugs-2026-08-05` (`fe0df479e`) | ✅ **fusionnée** | Devenu `docs/bug-reports/2026-08-05-client-bugs.md` sur `dev` | **Supprimer la branche distante.** |
| `origin/fix/topbar-dropdown-stacking` (`f2ac83606`) | ❌ non fusionnée, **aucune PR** | 4 commits, superset apparent de `fix/topbar-profile-menu` (#200) | Doublon orphelin. Décider : fermer #200 et ouvrir une PR à jour depuis cette branche, ou tout supprimer (voir §5). |

Aucune de ces trois branches fusionnées n'est passée par une PR — cohérent avec l'absence totale de PR fusionnées à son nom.

---

## 5. Verdict de réalignement

| PR / branche | Mergeable maintenant ? | Portes qualité | Ce qu'elle doit faire | Action propriétaire recommandée |
|---|---|---|---|---|
| **#200** `fix/topbar-profile-menu` | Non — CONFLICTING, 3223 derrière, draft | Aucune CI n'a jamais tourné (draft) | Rien : `TopBar.tsx` a été refait sur `dev` depuis. Le doublon `fix/topbar-dropdown-stacking` est plus complet | **Fermer #200**, supprimer les deux branches topbar. Si le bug persiste, re-signaler et refaire sur `dev` à jour (2 fichiers, ~1 h) |
| **#201** `feat/floor-plan-view` | Non — CONFLICTING (3 fichiers), 3223 derrière | **7 jobs rouges** ; `tsc` casse sur **son** fichier (`useLiveFloor.test.ts:102`, TS2322) ; PHPStan vert | Rebaser sur `dev` ; corriger le typage `OffsetPaginationMeta` ; annuler le changement de l'atome `Button` (P1-7) ou fournir la preuve visuelle sur 703 appelants ; sortir `.superpowers/sdd/*` | **Cherry-pick** conforme au plan d'août : garder la config back-office plan de salle, **sans** `Button.tsx`, **sans** `vite.config.ts`/`AGENTS.md`. 50 fichiers / +7675 : à découper |
| **#202** `feat/decor-items` | Techniquement oui — mais vers **sa propre branche**, pas `dev` | Aucune CI réelle (react-doctor seul) | — | **Parquer** (décision d'août maintenue). Décor = confort, hors périmètre client #1 |
| **#203** `feat/barista-workstation` | Idem #202 | Aucune CI réelle | — | **Parquer** (décision d'août maintenue) |
| **#204** `feat/pending-orders` | Idem — 12 conflits contre `dev` | Aucune CI réelle ; introduit les **collisions v61/62/63** et le remaniement d'`apps/pos/eslint.config.js` déjà refait sur `dev` | Renuméroter v61/62/63 → v68/69/70 ; abandonner la modif `eslint.config.js` (superseded) | **Parquer** (décision d'août maintenue) |
| **#205** `feat/register-floor-plan` | Non — **17 conflits**, dont `PaymentRepositoryController.php` | Aucune CI réelle ; **porte le P1-3 (fuite IBAN)** | **Restaurer `can:repositories.view` sur `payment-repositories.index` et `can:treasury.view` sur `payment-methods.index`** ; si le POS a besoin de la liste des dépôts, créer un endpoint POS dédié renvoyant `{id, code, name, type}` **sans** `account_number`/`iban`/`bic` ; supprimer le `Gate::any` dans le contrôleur | **Cherry-pick le noyau du pont d'occupation uniquement**, une fois le P1-3 réparé. Ne rien reprendre du diff Treasury |
| **#206** `feat/table-occupancy-timer` | Non — hérite des 17 conflits de #205 | Aucune CI réelle | Rebaser après #205 ; documenter/valider le forçage `SUR_PLACE` (P1-5) ; couvrir l'échec `markAccepted` après acceptation serveur (P1-6) | **Cherry-pick** (chrono + note par table) : 23 fichiers / +853, le plus petit et le plus propre de la chaîne. Cible prioritaire |
| **#207** `feat/nacef-regime-seam` | Non — 3 conflits seulement, 2980 derrière | 8 rouges **majoritairement dette de base** ; PHPStan/typage/tests solides | Rebaser ; retirer les 2 interfaces mortes ; trancher le type FE dupliqué ; peupler `certifications` sur tous les endpoints terminal | **CHANGES** puis merge. Le meilleur candidat de tout le lot. Le rebase seul est de faible risque (3 fichiers) |
| `origin/fix/import-cp1252-encoding` · `fix/api-nginx-client-body-perms` · `docs/client-bugs-2026-08-05` | Déjà sur `dev` | — | — | **Supprimer les branches distantes** |
| `origin/fix/topbar-dropdown-stacking` | Non fusionnée, aucune PR | — | — | Supprimer, ou en faire la PR unique si #200 est fermée |

### 5.1 Dette pré-existante du repo vs régressions à elle

**Dette pré-existante (pas de sa faute)** — à ne pas lui opposer :
- Les rouges `Pint`, `§14.3 Chokepoint`, `Generated Types Drift` de #207 portent exclusivement sur des fichiers de `dev`.
- Le compteur Deptrac est lui-même en dérive sur `dev` (baseline mouvante ; le mémo interne évoque 174 ailleurs) — non attribuable sans re-run après rebase.
- La majorité des échecs POS Vitest de #201 (`syncService`, `pendingCustomerSync`, `ReportsMenu`, `offlineFirstFlow`) sont des specs de `dev` cassées par le merge d'une base vieille de 3223 commits.
- `apps/pos` ne consomme pas `packages/shared/types` : **contrainte d'outillage du repo**, qui explique (sans l'excuser) le type recopié de #207.

**Régressions à elle** — non ambiguës :
- P1-3, fuite des coordonnées bancaires (`routes.php:63-64` + `PaymentRepositoryController.php:33-35`) — **sécurité, blocant absolu**.
- P1-1, collisions de numéros de migration SQLite — **corruption silencieuse de schéma sur les appareils déjà migrés**.
- P1-7, changement de sémantique de l'atome `Button` sur 703 appelants sans preuve.
- `useLiveFloor.test.ts:102` TS2322 (#201).
- Débordements de périmètre : `AGENTS.md`, `vite.config.ts`, `apps/pos/eslint.config.js`, 2 configs Playwright, 23 rapports `.superpowers/sdd/`.
- P1-2, l'absence de rebase pendant un mois — c'est le **multiplicateur** de tous les autres.

### 5.2 Recommandation de séquencement pour demain

1. **Aujourd'hui, sans elle** : supprimer les 3 branches fusionnées + les 2 branches topbar ; fermer #200.
2. **#207 d'abord** — plus petit conflit (3 fichiers), meilleure qualité, aucun risque fiscal. Rebase + 2 suppressions de fichiers + décision sur le type FE = mergeable rapidement. Cela lui donne aussi une première victoire réelle.
3. **P1-3 en hotfix indépendant** — ne pas attendre la chaîne. Vérifier que `dev` n'a jamais reçu ce diff (**confirmé : `origin/dev:routes.php:66-68` conserve `can:repositories.view`**) ; aucune fuite en production.
4. **Chaîne #201→#206 : abandonner le rebase de la chaîne complète.** 3223 commits de retard, 17 conflits, 121 commits ; le coût du rebase dépasse celui d'un re-portage. Rejouer les 3 morceaux retenus (config back-office #201, pont d'occupation #205 sans le diff Treasury, chrono #206) en **branches courtes issues d'`origin/dev` à jour**, une PR par morceau, chacune ciblant `dev` pour que la CI complète tourne.
5. **Lui transmettre la doc méthodologie** (`docs/sessions/2026-08-02-retour-methodologie-dhouha.md`, gitignorée donc jamais poussée) **hors du repo**, et acter deux règles non négociables : **(a)** toute PR cible `dev`, jamais une branche à soi — sinon la CI ment ; **(b)** rebase hebdomadaire obligatoire, une chaîne ne dépasse jamais 3 PR.

### 5.3 Lecture d'ensemble, à charge et à décharge

À décharge : la qualité **intrinsèque** du code est réelle et en progression. #207 est du TDD authentique avec des tests qui prouvent un comportement, pas une forme. Les deux commits du 2026-08-03 sont exemplaires — messages qui expliquent la cause racine de la cause racine, test rouge d'abord, et le passage de `seated_at?` à `seated_at` obligatoire pour que le compilateur désigne le fautif est exactement le bon réflexe. Le correctif du P1-4 est propre et bien raisonné.

À charge : ce sont des problèmes de **processus**, pas de compétence. Chaîne de 7 PR empilées jamais rebasée (× 6 en un mois) ; PR ciblant ses propres branches, ce qui a masqué l'absence totale de CI sur 5 d'entre elles ; débordement répété sur des fichiers partagés ; et un P1 de sécurité laissé intact un mois après signalement. **Le point unique le plus rentable à corriger est (a) : une PR cible `dev`.** Il aurait rendu visibles, dès juillet, la quasi-totalité des sept P1.

Enfin : **silence total depuis le 2026-08-05, soit 30 jours.** Avant tout plan de réalignement technique, vérifier sa disponibilité — le plan de re-portage du §5.2 suppose qu'elle est active.
