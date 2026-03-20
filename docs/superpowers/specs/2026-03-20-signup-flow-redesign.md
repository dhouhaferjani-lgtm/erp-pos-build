# Signup Flow Redesign — Design Spec

> Sub-project A of the Onboarding Overhaul. Covers the registration form only — post-signup onboarding wizard is a separate spec.

## Goals

- Replace emoji icons with professional Lucide icon cards
- Reduce required fields from ~8 to 6 (name, email, password, country, vertical, company name)
- Auto-detect country via IP for smarter defaults
- Modern split layout matching SaaS industry standards
- One minor backend addition: email uniqueness check endpoint

---

## Layout

**Split layout** — left 40% brand panel, right 60% form panel.

### Left Panel (Fixed Across Steps)

- Product logo (IziPOS or Otospex, driven by `VITE_APP_PRODUCT`)
- Product tagline
- 3–4 value propositions with Lucide `Check` icons in green circles
- "14-day free trial, no credit card required" at bottom
- Dark background (`slate-900`) with product accent color
- Content is static — does not change between steps

**Product mapping:** `VITE_APP_PRODUCT=izipos` → IziPOS branding (copper accent), `VITE_APP_PRODUCT=otospex` → Otospex branding (pink accent). See `apps/web/src/contexts/ProductConfigContext.tsx` for the existing product config.

### Right Panel

- Step title + subtitle at top
- Progress bar: thin horizontal bar, 4 equal visual segments (purely visual, each step fills one segment completely when active — no partial fill within a segment)
- Form fields for current step
- Back / Next buttons at bottom right
- "Already have an account? Sign in" link below buttons

### Responsive

- Below 768px: left panel collapses to a small header strip (logo + tagline only) above the form
- Form takes full width on mobile

---

## Step Flow

### Step 1 — Account (3 required fields)

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Full name | text | yes | max 255 |
| Email | email | yes | Debounced uniqueness check (500ms after typing stops). Calls `POST /auth/check-email` (new endpoint — see Backend Changes). Inline error "Email already taken" if duplicate. |
| Password | password | yes | min 8 chars. Show/hide toggle button (Lucide `Eye`/`EyeOff`). Strength indicator bar below: red (<8 chars), yellow (8–11), green (12+). No separate confirm field — `password_confirmation` is silently duplicated from `password` on submit. |

### Step 2 — Your Business (2 required fields)

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Country | dropdown | yes | Auto-detected via IP on mount (see Country Detection). Popular countries pinned at top (see list below) with a separator, then all countries alphabetically. Selecting a country sets `currency`, `locale`, `timezone` defaults per the Country Defaults table. |
| Business type | card grid | yes | 2-column grid of selectable cards. Each card: colored icon (44×44 rounded-square) + name + short description. Single-select with blue border + light blue background on selected card. Shows only verticals for current product per `VITE_APP_PRODUCT`. |

**Pinned countries (in order):** France (FR), Tunisia (TN), United Kingdom (GB), Italy (IT), Morocco (MA), Algeria (DZ), United States (US).

### Step 3 — Company (1 required, 1 optional)

| Field | Type | Required | Notes |
|-------|------|----------|-------|
| Company name | text | yes | min 2, max 255 |
| Phone | tel | no | Non-editable gray pill badge showing dial code prefix (e.g., `+33`) auto-populated from country. User types the local number only. Full E.164 number (prefix + local) stored on submit. |

### Step 4 — Review & Go

- Read-only summary card with 3 sections: **Account** (name, email), **Business** (country flag + name, vertical icon + name), **Company** (company name, phone if provided)
- Each section has a pencil icon (Lucide `Pencil`, top-right of section). Clicking it navigates back to that section's step (step 1, 2, or 3). After editing, user clicks Next to advance back through subsequent steps to Step 4.
- Terms checkbox: "I agree to the [Terms of Service](/terms) and [Privacy Policy](/privacy)"
- "Create Account" primary button (disabled until checkbox checked)
- Loading state on button during submission

---

## Country Detection

**On RegisterPage mount:**

1. Fetch `https://ipapi.co/country_code/` (free tier, 1000 req/day, HTTPS, no API key)
2. If response arrives within 2 seconds, pre-select that country code
3. If fetch fails or times out, parse `navigator.language` (e.g., `fr-FR` → `FR`)
4. If both fail, default to `FR` (primary market)
5. User can always change manually

**Implementation:** A `useCountryDetect()` hook that returns `{ detectedCountry: string | null, isDetecting: boolean }`. Called once on mount, result cached in component state.

---

## Country Defaults

When a country is selected, these values are auto-derived and sent as hidden fields on submit:

| Country | Code | Currency | Locale | Timezone | Dial Code |
|---------|------|----------|--------|----------|-----------|
| France | FR | EUR | fr | Europe/Paris | +33 |
| Tunisia | TN | TND | fr | Africa/Tunis | +216 |
| United Kingdom | GB | GBP | en | Europe/London | +44 |
| Italy | IT | EUR | it | Europe/Rome | +39 |
| Morocco | MA | MAD | fr | Africa/Casablanca | +212 |
| Algeria | DZ | DZD | fr | Africa/Algiers | +213 |
| United States | US | USD | en | America/New_York | +1 |

For countries not in this list, defaults: currency `EUR`, locale `en`, timezone `UTC`, dial code looked up from the full country dial code map.

---

## Phone Prefix

**Static mapping** in a new config file `apps/web/src/features/auth/countryDialCodes.ts`:

Contains a `Record<string, string>` mapping all ISO 3166-1 alpha-2 codes to dial codes. The 7 priority countries above plus ~200 others for completeness.

- Prefix updates automatically when country changes on step 2
- Displayed as a non-editable gray badge (`bg-gray-100 text-gray-600 rounded-l-lg px-3`) to the left of the phone input
- On submit, concatenate prefix + local number into full E.164 string

**Note:** The existing `apps/web/src/lib/countries.ts` has a static country list with `code`, `name`, `nameEn`, `nameFr` fields. We do NOT modify this file — the dial codes and defaults are in a separate auth-specific config to keep concerns separate. The existing `RegisterPage.tsx` currently fetches countries from a `/countries` API endpoint — the new implementation replaces this with the static list for faster load and no API dependency during signup.

---

## Vertical Icon Mapping

### IziPOS Verticals (`VITE_APP_PRODUCT=izipos`)

| Key | Lucide Icon | Color (bg / stroke) | Description (en) | Description (fr) |
|-----|-------------|---------------------|-------------------|-------------------|
| `retail` | `Store` | sky-50 / sky-600 | General store | Commerce général |
| `pharmacy` | `Pill` | green-50 / green-600 | Drug store | Pharmacie |
| `coffee_shop` | `Coffee` | amber-50 / amber-600 | Café & bakery | Café & boulangerie |
| `restaurant` | `UtensilsCrossed` | pink-50 / pink-600 | Full-service dining | Restauration |
| `fashion` | `Shirt` | violet-50 / violet-600 | Fashion boutique | Boutique de mode |
| `parapharmacy` | `Heart` | orange-50 / orange-600 | Health & wellness | Santé & bien-être |

### Otospex Verticals (`VITE_APP_PRODUCT=otospex`)

| Key | Lucide Icon | Color (bg / stroke) | Description (en) | Description (fr) |
|-----|-------------|---------------------|-------------------|-------------------|
| `mechanic` | `Wrench` | blue-50 / blue-600 | Automotive repair | Mécanique auto |
| `body_shop` | `PaintBucket` | red-50 / red-600 | Body repair & paint | Carrosserie & peinture |
| `parts_retailer` | `Package` | slate-50 / slate-600 | Parts retail | Vente de pièces |
| `car_glass` | `Car` | cyan-50 / cyan-600 | Glass replacement | Remplacement vitrage |
| `tire_shop` | `Circle` | amber-50 / amber-600 | Tire sales & service | Pneumatiques |
| `service_station` | `Fuel` | green-50 / green-600 | Fuel station | Station-service |

Card dimensions: icon container 44×44px with 10px border-radius, gradient background from `{color}-50` to `{color}-100`. Stroke width 1.75.

---

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Email already taken | Debounced check (500ms). Inline error below email field. User stays on step 1. |
| Password too short | Inline validation on blur. Strength bar stays red. Next button disabled. |
| Company name too short | Inline validation on blur. |
| Terms not accepted | Submit button disabled with tooltip "Accept terms to continue". |
| API failure on submit | Toast error ("Registration failed. Please try again."). User stays on step 4, button re-enabled. |
| IP detection failure | Silent fallback to `navigator.language` → `FR`. No user-facing error. |
| Network offline | Next/Submit buttons disabled. Tooltip "No internet connection". |
| Duplicate submission | Button shows spinner, disabled during request. Prevents double-submit. |
| Email check endpoint fails | Silent — no inline error shown, registration can still proceed (backend will catch duplicates on submit). |

---

## Navigation & State

- **Back button** on steps 2–4 preserves all entered data (React state)
- **Browser back** works via URL hash (`#step-1`, `#step-2`, etc.) — no route changes, hash-only
- **Page refresh** resets the form (acceptable — signup takes <2 minutes)
- **Step validation** — Next button runs client-side validation before advancing. Cannot skip steps.
- **Edit from review** — Pencil icon on Step 4 navigates back to the relevant step. User must click through Next from there to return to Step 4 (re-validates each step).

---

## Accessibility

- All form fields have associated `<label>` elements
- Vertical cards are keyboard-navigable: Tab to focus, Arrow keys to move between cards, Enter/Space to select
- Selected card announced via `aria-selected="true"`
- Progress bar uses `role="progressbar"` with `aria-valuenow` / `aria-valuemax`
- Focus moves to first field of new step on navigation
- Error messages linked to fields via `aria-describedby`

---

## Backend Changes

One new endpoint required:

### `POST /auth/check-email`

**Purpose:** Debounced email uniqueness check during signup (Step 1).

**Request:** `{ "email": "user@example.com" }`

**Response:** `{ "available": true }` or `{ "available": false }`

**Validation:** Must be a valid email format. No auth required (public endpoint).

**Rate limiting:** Apply existing auth rate limiter (prevent enumeration abuse).

**Implementation:** Add a `checkEmail` method to `AuthController` that queries `User::where('email', $email)->exists()`. Add route to `routes/api.php` in the public auth group.

---

## Files to Change

| File | Action |
|------|--------|
| `apps/web/src/features/auth/RegisterPage.tsx` | Rewrite — new split layout, 4 steps, Lucide icons |
| `apps/web/src/features/auth/verticals.ts` | New — vertical icon/color/description config per product |
| `apps/web/src/features/auth/countryDialCodes.ts` | New — country → dial code + defaults mapping |
| `apps/web/src/hooks/useCountryDetect.ts` | New — IP geolocation hook |
| `apps/web/src/locales/en/auth.json` | Update — step titles, descriptions, field labels, vertical descriptions (all 12) |
| `apps/web/src/locales/fr/auth.json` | Update — French translations for all of the above |
| `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php` | Add `checkEmail` method |
| `apps/api/routes/api.php` | Add `POST /auth/check-email` route |

---

## Submission Payload

On "Create Account" click, the frontend sends to `POST /auth/register`:

```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "password": "securepassword",
  "password_confirmation": "securepassword",
  "company_name": "Doe Retail",
  "country_code": "FR",
  "vertical": "retail",
  "phone": "+33612345678",
  "currency": "EUR",
  "locale": "fr",
  "timezone": "Europe/Paris",
  "platform": "web"
}
```

`password_confirmation` is duplicated from `password` client-side. `currency`, `locale`, `timezone` are derived from the country selection. `phone` is prefix + local number concatenated.

---

## Out of Scope

- Post-signup onboarding wizard (Sub-project B)
- Email verification page changes
- Social login / SSO
- Multi-step address input
- Modifying the existing `lib/countries.ts` static list
