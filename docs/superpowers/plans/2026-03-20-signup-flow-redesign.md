# Signup Flow Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the current emoji-based multi-step signup with a professional split-layout wizard using Lucide icons, IP-based country detection, and smart field defaults.

**Architecture:** The signup form is decomposed following atomic design: data configs (verticals, countries) → custom hooks (country detection) → molecules (password input, phone input, vertical card) → organism (step components) → page (RegisterPage with split layout). Backend gets one new public endpoint for email uniqueness checks.

**Tech Stack:** React 19, TypeScript strict, Tailwind CSS 4, Lucide React, TanStack Query 5, Vitest, Laravel 12/PHP 8.2

**Spec:** `docs/superpowers/specs/2026-03-20-signup-flow-redesign.md`

**Conventions:**
- Atomic design: atoms (`components/atoms/`), molecules (`components/molecules/`), organisms in features
- Hexagonal backend: Presentation (Controllers, Requests) → Application (Services, DTOs) → Domain
- All user-facing text via `t()` with `react-i18next`
- Form atoms: `FormField`, `Input`, `Select` from `components/atoms/`
- Design tokens via `cn()` utility
- TDD: write failing test → implement → verify → commit

---

## File Structure

### New Files
| File | Responsibility |
|------|---------------|
| `apps/web/src/features/auth/config/verticals.ts` | Vertical icon, color, description config per product |
| `apps/web/src/features/auth/config/countryData.ts` | Country defaults (currency, locale, timezone) + pinned list + comprehensive DIAL_CODES map (~200 countries) |
| `apps/web/src/features/auth/hooks/useCountryDetect.ts` | IP geolocation hook with browser locale fallback |
| `apps/web/src/features/auth/hooks/useRegisterForm.ts` | Form state, validation, step navigation logic |
| `apps/web/src/features/auth/components/RegisterBrandPanel.tsx` | Left panel — product branding, value props |
| `apps/web/src/features/auth/components/RegisterProgress.tsx` | Step progress bar molecule |
| `apps/web/src/features/auth/components/AccountStep.tsx` | Step 1 — name, email, password fields |
| `apps/web/src/features/auth/components/BusinessStep.tsx` | Step 2 — country + vertical grid |
| `apps/web/src/features/auth/components/CompanyStep.tsx` | Step 3 — company name + phone |
| `apps/web/src/features/auth/components/ReviewStep.tsx` | Step 4 — summary + terms + submit |
| `apps/web/src/features/auth/components/VerticalCard.tsx` | Selectable vertical card molecule |
| `apps/web/src/features/auth/components/PasswordStrength.tsx` | Password strength indicator bar |
| `apps/web/src/features/auth/components/PhoneInput.tsx` | Phone input with country prefix badge |
| `apps/web/src/features/auth/__tests__/useCountryDetect.test.ts` | Tests for country detection hook |
| `apps/web/src/features/auth/__tests__/useRegisterForm.test.ts` | Tests for form state/validation |
| `apps/web/src/features/auth/__tests__/verticals.test.ts` | Tests for vertical config |
| `apps/api/app/Modules/Identity/Presentation/Requests/CheckEmailRequest.php` | Validation for email check endpoint |

### Modified Files
| File | Change |
|------|--------|
| `apps/web/src/features/auth/RegisterPage.tsx` | Complete rewrite — split layout, delegates to step components |
| `apps/web/src/locales/en/auth.json` | Add new keys: step subtitles, password strength, Otospex verticals, brand panel |
| `apps/web/src/locales/fr/auth.json` | French translations for all new keys |
| `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php` | Add `checkEmail` method |
| `apps/api/app/Modules/Identity/routes.php` | Add `POST check-email` route |

---

## Task 1: Backend — Email Uniqueness Check Endpoint

**Files:**
- Create: `apps/api/app/Modules/Identity/Presentation/Requests/CheckEmailRequest.php`
- Modify: `apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php`
- Modify: `apps/api/app/Modules/Identity/routes.php`

- [ ] **Step 1: Create the form request**

```php
<?php

declare(strict_types=1);

namespace App\Modules\Identity\Presentation\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Public endpoint
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }
}
```

- [ ] **Step 2: Add controller method**

In `AuthController.php`, add after the `register` method:

```php
/**
 * Check if an email is available for registration.
 *
 * Public endpoint with rate limiting to prevent email enumeration.
 */
public function checkEmail(CheckEmailRequest $request): JsonResponse
{
    $validated = $request->validated();

    $exists = User::where('email', $validated['email'])->exists();

    return response()->json([
        'available' => ! $exists,
    ]);
}
```

Add `use App\Modules\Identity\Presentation\Requests\CheckEmailRequest;` to imports.

- [ ] **Step 3: Add route**

In `apps/api/app/Modules/Identity/routes.php`, inside the public auth group (after the `register` route at line 27):

```php
Route::post('check-email', [AuthController::class, 'checkEmail'])
    ->middleware('throttle:login')
    ->name('auth.check-email');
```

Uses the existing `throttle:login` rate limiter to prevent enumeration.

- [ ] **Step 4: Verify with PHP syntax check**

Run: `cd apps/api && php -l app/Modules/Identity/Presentation/Requests/CheckEmailRequest.php && php -l app/Modules/Identity/Presentation/Controllers/AuthController.php && php -l app/Modules/Identity/routes.php`

Expected: `No syntax errors detected` for all three files.

- [ ] **Step 5: Commit**

```bash
git add apps/api/app/Modules/Identity/Presentation/Requests/CheckEmailRequest.php \
  apps/api/app/Modules/Identity/Presentation/Controllers/AuthController.php \
  apps/api/app/Modules/Identity/routes.php
git commit -m "feat(identity): add POST /auth/check-email for signup email uniqueness check"
```

---

## Task 2: Vertical & Country Config Data

**Files:**
- Create: `apps/web/src/features/auth/config/verticals.ts`
- Create: `apps/web/src/features/auth/config/countryData.ts`
- Create: `apps/web/src/features/auth/__tests__/verticals.test.ts`

- [ ] **Step 1: Write tests for vertical config**

Create `apps/web/src/features/auth/__tests__/verticals.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { getVerticalsForProduct, type VerticalConfig } from '../config/verticals'

describe('getVerticalsForProduct', () => {
  it('returns 6 verticals for izipos', () => {
    const verticals = getVerticalsForProduct('izipos')
    expect(verticals).toHaveLength(6)
    expect(verticals.map((v) => v.key)).toEqual([
      'retail', 'pharmacy', 'coffee_shop', 'restaurant', 'fashion', 'parapharmacy',
    ])
  })

  it('returns 6 verticals for otospex', () => {
    const verticals = getVerticalsForProduct('otospex')
    expect(verticals).toHaveLength(6)
    expect(verticals.map((v) => v.key)).toEqual([
      'mechanic', 'body_shop', 'parts_retailer', 'car_glass', 'tire_shop', 'service_station',
    ])
  })

  it('each vertical has required fields', () => {
    const verticals = getVerticalsForProduct('izipos')
    for (const v of verticals) {
      expect(v.key).toBeTruthy()
      expect(v.icon).toBeTruthy()
      expect(v.bgColor).toBeTruthy()
      expect(v.strokeColor).toBeTruthy()
      expect(v.labelKey).toBeTruthy()
      expect(v.descriptionKey).toBeTruthy()
    }
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/verticals.test.ts`

Expected: FAIL — module not found.

- [ ] **Step 3: Implement vertical config**

Create `apps/web/src/features/auth/config/verticals.ts`:

```ts
import {
  Store, Pill, Coffee, UtensilsCrossed, Shirt, Heart,
  Wrench, PaintBucket, Package, Car, Circle, Fuel,
} from 'lucide-react'
import type { LucideIcon } from 'lucide-react'
import type { Product } from '@/contexts/ProductConfigContext'

export interface VerticalConfig {
  key: string
  icon: LucideIcon
  bgColor: string
  strokeColor: string
  labelKey: string
  descriptionKey: string
}

const iziposVerticals: VerticalConfig[] = [
  { key: 'retail', icon: Store, bgColor: 'bg-sky-50', strokeColor: 'text-sky-600', labelKey: 'auth:verticals.retail.label', descriptionKey: 'auth:verticals.retail.description' },
  { key: 'pharmacy', icon: Pill, bgColor: 'bg-green-50', strokeColor: 'text-green-600', labelKey: 'auth:verticals.pharmacy.label', descriptionKey: 'auth:verticals.pharmacy.description' },
  { key: 'coffee_shop', icon: Coffee, bgColor: 'bg-amber-50', strokeColor: 'text-amber-600', labelKey: 'auth:verticals.coffee_shop.label', descriptionKey: 'auth:verticals.coffee_shop.description' },
  { key: 'restaurant', icon: UtensilsCrossed, bgColor: 'bg-pink-50', strokeColor: 'text-pink-600', labelKey: 'auth:verticals.restaurant.label', descriptionKey: 'auth:verticals.restaurant.description' },
  { key: 'fashion', icon: Shirt, bgColor: 'bg-violet-50', strokeColor: 'text-violet-600', labelKey: 'auth:verticals.fashion.label', descriptionKey: 'auth:verticals.fashion.description' },
  { key: 'parapharmacy', icon: Heart, bgColor: 'bg-orange-50', strokeColor: 'text-orange-600', labelKey: 'auth:verticals.parapharmacy.label', descriptionKey: 'auth:verticals.parapharmacy.description' },
]

const otospexVerticals: VerticalConfig[] = [
  { key: 'mechanic', icon: Wrench, bgColor: 'bg-blue-50', strokeColor: 'text-blue-600', labelKey: 'auth:verticals.mechanic.label', descriptionKey: 'auth:verticals.mechanic.description' },
  { key: 'body_shop', icon: PaintBucket, bgColor: 'bg-red-50', strokeColor: 'text-red-600', labelKey: 'auth:verticals.body_shop.label', descriptionKey: 'auth:verticals.body_shop.description' },
  { key: 'parts_retailer', icon: Package, bgColor: 'bg-slate-50', strokeColor: 'text-slate-600', labelKey: 'auth:verticals.parts_retailer.label', descriptionKey: 'auth:verticals.parts_retailer.description' },
  { key: 'car_glass', icon: Car, bgColor: 'bg-cyan-50', strokeColor: 'text-cyan-600', labelKey: 'auth:verticals.car_glass.label', descriptionKey: 'auth:verticals.car_glass.description' },
  { key: 'tire_shop', icon: Circle, bgColor: 'bg-amber-50', strokeColor: 'text-amber-600', labelKey: 'auth:verticals.tire_shop.label', descriptionKey: 'auth:verticals.tire_shop.description' },
  { key: 'service_station', icon: Fuel, bgColor: 'bg-green-50', strokeColor: 'text-green-600', labelKey: 'auth:verticals.service_station.label', descriptionKey: 'auth:verticals.service_station.description' },
]

export function getVerticalsForProduct(product: Product): VerticalConfig[] {
  return product === 'otospex' ? otospexVerticals : iziposVerticals
}
```

- [ ] **Step 4: Implement country data config**

Create `apps/web/src/features/auth/config/countryData.ts`:

```ts
/**
 * Country defaults for signup — currency, locale, timezone, dial code.
 * Used to auto-derive hidden fields from country selection.
 */

export interface CountryDefaults {
  currency: string
  locale: string
  timezone: string
  dialCode: string
}

/**
 * Priority countries with full defaults — pinned at top of dropdown.
 */
export const PINNED_COUNTRIES = ['FR', 'TN', 'GB', 'IT', 'MA', 'DZ', 'US'] as const

export const COUNTRY_DEFAULTS: Record<string, CountryDefaults> = {
  FR: { currency: 'EUR', locale: 'fr', timezone: 'Europe/Paris', dialCode: '+33' },
  TN: { currency: 'TND', locale: 'fr', timezone: 'Africa/Tunis', dialCode: '+216' },
  GB: { currency: 'GBP', locale: 'en', timezone: 'Europe/London', dialCode: '+44' },
  IT: { currency: 'EUR', locale: 'it', timezone: 'Europe/Rome', dialCode: '+39' },
  MA: { currency: 'MAD', locale: 'fr', timezone: 'Africa/Casablanca', dialCode: '+212' },
  DZ: { currency: 'DZD', locale: 'fr', timezone: 'Africa/Algiers', dialCode: '+213' },
  US: { currency: 'USD', locale: 'en', timezone: 'America/New_York', dialCode: '+1' },
  // Extended list — add as needed
  DE: { currency: 'EUR', locale: 'de', timezone: 'Europe/Berlin', dialCode: '+49' },
  ES: { currency: 'EUR', locale: 'es', timezone: 'Europe/Madrid', dialCode: '+34' },
  BE: { currency: 'EUR', locale: 'fr', timezone: 'Europe/Brussels', dialCode: '+32' },
  NL: { currency: 'EUR', locale: 'nl', timezone: 'Europe/Amsterdam', dialCode: '+31' },
  CH: { currency: 'CHF', locale: 'fr', timezone: 'Europe/Zurich', dialCode: '+41' },
  PT: { currency: 'EUR', locale: 'pt', timezone: 'Europe/Lisbon', dialCode: '+351' },
  SA: { currency: 'SAR', locale: 'ar', timezone: 'Asia/Riyadh', dialCode: '+966' },
  AE: { currency: 'AED', locale: 'ar', timezone: 'Asia/Dubai', dialCode: '+971' },
  LY: { currency: 'LYD', locale: 'ar', timezone: 'Africa/Tripoli', dialCode: '+218' },
  EG: { currency: 'EGP', locale: 'ar', timezone: 'Africa/Cairo', dialCode: '+20' },
  TR: { currency: 'TRY', locale: 'tr', timezone: 'Europe/Istanbul', dialCode: '+90' },
  CA: { currency: 'CAD', locale: 'en', timezone: 'America/Toronto', dialCode: '+1' },
}

const FALLBACK_DEFAULTS: CountryDefaults = {
  currency: 'EUR',
  locale: 'en',
  timezone: 'UTC',
  dialCode: '',
}

/**
 * Comprehensive dial code map for all ISO 3166-1 alpha-2 countries.
 * Used for phone prefix display. ~200 entries.
 * Generate the full map at implementation time — include all countries
 * from the existing `apps/web/src/lib/countries.ts` list plus standard
 * ISO dial codes for global coverage.
 */
export const DIAL_CODES: Record<string, string> = {
  AF: '+93', AL: '+355', DZ: '+213', AD: '+376', AO: '+244',
  AR: '+54', AM: '+374', AU: '+61', AT: '+43', AZ: '+994',
  BH: '+973', BD: '+880', BE: '+32', BJ: '+229', BO: '+591',
  BA: '+387', BR: '+55', BG: '+359', CA: '+1', CL: '+56',
  CN: '+86', CO: '+57', HR: '+385', CY: '+357', CZ: '+420',
  DK: '+45', EG: '+20', EE: '+372', FI: '+358', FR: '+33',
  DE: '+49', GH: '+233', GR: '+30', HK: '+852', HU: '+36',
  IN: '+91', ID: '+62', IR: '+98', IQ: '+964', IE: '+353',
  IL: '+972', IT: '+39', JP: '+81', JO: '+962', KE: '+254',
  KR: '+82', KW: '+965', LB: '+961', LY: '+218', LU: '+352',
  MY: '+60', MX: '+52', MA: '+212', NL: '+31', NZ: '+64',
  NG: '+234', NO: '+47', OM: '+968', PK: '+92', PE: '+51',
  PH: '+63', PL: '+48', PT: '+351', QA: '+974', RO: '+40',
  RU: '+7', SA: '+966', SN: '+221', RS: '+381', SG: '+65',
  SK: '+421', SI: '+386', ZA: '+27', ES: '+34', SE: '+46',
  CH: '+41', TW: '+886', TH: '+66', TN: '+216', TR: '+90',
  UA: '+380', AE: '+971', GB: '+44', US: '+1', UY: '+598',
  VN: '+84',
  // ... extend with remaining countries at implementation time
}

/**
 * Get country defaults for a given country code.
 * Returns fallback values for unknown countries.
 */
export function getCountryDefaults(countryCode: string): CountryDefaults {
  return COUNTRY_DEFAULTS[countryCode] ?? FALLBACK_DEFAULTS
}

/**
 * Get dial code for a given country code.
 * Uses the comprehensive DIAL_CODES map first, falls back to COUNTRY_DEFAULTS.
 */
export function getDialCode(countryCode: string): string {
  return DIAL_CODES[countryCode] ?? COUNTRY_DEFAULTS[countryCode]?.dialCode ?? ''
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/verticals.test.ts`

Expected: PASS — all 3 tests green.

- [ ] **Step 6: Commit**

```bash
git add apps/web/src/features/auth/config/ apps/web/src/features/auth/__tests__/verticals.test.ts
git commit -m "feat(auth): add vertical icon config and country defaults data"
```

---

## Task 3: Country Detection Hook

**Files:**
- Create: `apps/web/src/features/auth/hooks/useCountryDetect.ts`
- Create: `apps/web/src/features/auth/__tests__/useCountryDetect.test.ts`

- [ ] **Step 1: Write tests for country detection**

Create `apps/web/src/features/auth/__tests__/useCountryDetect.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { renderHook, waitFor } from '@testing-library/react'
import { useCountryDetect } from '../hooks/useCountryDetect'

describe('useCountryDetect', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
  })

  afterEach(() => {
    vi.restoreAllMocks()
  })

  it('returns detected country from IP API', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValueOnce({
      ok: true,
      text: () => Promise.resolve('FR'),
    } as Response)

    const { result } = renderHook(() => useCountryDetect())

    expect(result.current.isDetecting).toBe(true)

    await waitFor(() => {
      expect(result.current.isDetecting).toBe(false)
    })

    expect(result.current.detectedCountry).toBe('FR')
  })

  it('falls back to navigator.language on fetch failure', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('Network error'))
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('fr-FR')

    const { result } = renderHook(() => useCountryDetect())

    await waitFor(() => {
      expect(result.current.isDetecting).toBe(false)
    })

    expect(result.current.detectedCountry).toBe('FR')
  })

  it('defaults to FR when all detection fails', async () => {
    vi.spyOn(globalThis, 'fetch').mockRejectedValueOnce(new Error('Network error'))
    vi.spyOn(navigator, 'language', 'get').mockReturnValue('xx')

    const { result } = renderHook(() => useCountryDetect())

    await waitFor(() => {
      expect(result.current.isDetecting).toBe(false)
    })

    expect(result.current.detectedCountry).toBe('FR')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/useCountryDetect.test.ts`

Expected: FAIL — module not found.

- [ ] **Step 3: Implement the hook**

Create `apps/web/src/features/auth/hooks/useCountryDetect.ts`:

```ts
import { useState, useEffect } from 'react'

interface UseCountryDetectResult {
  detectedCountry: string | null
  isDetecting: boolean
}

const IP_API_URL = 'https://ipapi.co/country_code/'
const TIMEOUT_MS = 2000
const DEFAULT_COUNTRY = 'FR'

/**
 * Detect user's country via IP geolocation with browser locale fallback.
 *
 * 1. Fetch from ipapi.co (HTTPS, free tier, 1000 req/day)
 * 2. Fallback: parse navigator.language (e.g., fr-FR → FR)
 * 3. Final fallback: FR (primary market)
 */
export function useCountryDetect(): UseCountryDetectResult {
  const [detectedCountry, setDetectedCountry] = useState<string | null>(null)
  const [isDetecting, setIsDetecting] = useState(true)

  useEffect(() => {
    let cancelled = false
    const controller = new AbortController()

    const timeoutId = setTimeout(() => {
      controller.abort()
    }, TIMEOUT_MS)

    async function detect() {
      try {
        const response = await fetch(IP_API_URL, { signal: controller.signal })
        if (!cancelled && response.ok) {
          const code = (await response.text()).trim().toUpperCase()
          if (code.length === 2) {
            setDetectedCountry(code)
            setIsDetecting(false)
            return
          }
        }
      } catch {
        // Fetch failed or timed out — fall through to locale fallback
      } finally {
        clearTimeout(timeoutId)
      }

      if (cancelled) return

      // Fallback: navigator.language (e.g., fr-FR → FR)
      const locale = navigator.language
      if (locale && locale.includes('-')) {
        const countryFromLocale = locale.split('-')[1].toUpperCase()
        if (countryFromLocale.length === 2) {
          setDetectedCountry(countryFromLocale)
          setIsDetecting(false)
          return
        }
      }

      // Final fallback
      setDetectedCountry(DEFAULT_COUNTRY)
      setIsDetecting(false)
    }

    void detect()

    return () => {
      cancelled = true
      controller.abort()
    }
  }, [])

  return { detectedCountry, isDetecting }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/useCountryDetect.test.ts`

Expected: PASS — all 3 tests green.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/auth/hooks/useCountryDetect.ts \
  apps/web/src/features/auth/__tests__/useCountryDetect.test.ts
git commit -m "feat(auth): add useCountryDetect hook with IP geolocation + locale fallback"
```

---

## Task 4: Register Form State Hook

**Files:**
- Create: `apps/web/src/features/auth/hooks/useRegisterForm.ts`
- Create: `apps/web/src/features/auth/__tests__/useRegisterForm.test.ts`

- [ ] **Step 1: Write tests for form state**

Create `apps/web/src/features/auth/__tests__/useRegisterForm.test.ts`:

```ts
import { describe, it, expect } from 'vitest'
import { renderHook, act } from '@testing-library/react'
import { useRegisterForm } from '../hooks/useRegisterForm'

describe('useRegisterForm', () => {
  it('initializes at step 1 with empty form data', () => {
    const { result } = renderHook(() => useRegisterForm())
    expect(result.current.currentStep).toBe(1)
    expect(result.current.formData.name).toBe('')
    expect(result.current.formData.email).toBe('')
  })

  it('validates step 1 — requires name, email, password', () => {
    const { result } = renderHook(() => useRegisterForm())
    const errors = result.current.validateStep(1)
    expect(errors.name).toBeTruthy()
    expect(errors.email).toBeTruthy()
    expect(errors.password).toBeTruthy()
  })

  it('advances to next step when validation passes', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.updateField('name', 'John Doe')
      result.current.updateField('email', 'john@example.com')
      result.current.updateField('password', 'strongpassword123')
    })

    act(() => {
      result.current.goToNext()
    })

    expect(result.current.currentStep).toBe(2)
  })

  it('does not advance when validation fails', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.goToNext()
    })

    expect(result.current.currentStep).toBe(1)
    expect(Object.keys(result.current.errors).length).toBeGreaterThan(0)
  })

  it('goes back preserving data', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.updateField('name', 'John Doe')
      result.current.updateField('email', 'john@example.com')
      result.current.updateField('password', 'strongpassword123')
    })
    act(() => { result.current.goToNext() })
    act(() => { result.current.goBack() })

    expect(result.current.currentStep).toBe(1)
    expect(result.current.formData.name).toBe('John Doe')
  })

  it('builds the correct submission payload', () => {
    const { result } = renderHook(() => useRegisterForm())

    act(() => {
      result.current.updateField('name', 'John Doe')
      result.current.updateField('email', 'john@example.com')
      result.current.updateField('password', 'strongpassword123')
      result.current.updateField('countryCode', 'FR')
      result.current.updateField('vertical', 'retail')
      result.current.updateField('companyName', 'Test Corp')
      result.current.updateField('phoneLocal', '612345678')
    })

    const payload = result.current.buildPayload()

    expect(payload.name).toBe('John Doe')
    expect(payload.password_confirmation).toBe('strongpassword123')
    expect(payload.phone).toBe('+33612345678')
    expect(payload.currency).toBe('EUR')
    expect(payload.locale).toBe('fr')
    expect(payload.timezone).toBe('Europe/Paris')
    expect(payload.platform).toBe('web')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/useRegisterForm.test.ts`

Expected: FAIL — module not found.

- [ ] **Step 3: Implement the form state hook**

Create `apps/web/src/features/auth/hooks/useRegisterForm.ts`:

```ts
import { useState, useCallback } from 'react'
import { getCountryDefaults, getDialCode } from '../config/countryData'

export interface RegisterFormData {
  // Step 1
  name: string
  email: string
  password: string
  // Step 2
  countryCode: string
  vertical: string
  // Step 3
  companyName: string
  phoneLocal: string
  // Step 4
  acceptedTerms: boolean
}

export interface RegisterPayload {
  name: string
  email: string
  password: string
  password_confirmation: string
  company_name: string
  country_code: string
  vertical: string
  phone: string
  currency: string
  locale: string
  timezone: string
  platform: string
}

export type FormErrors = Partial<Record<keyof RegisterFormData, string>>

const INITIAL_FORM_DATA: RegisterFormData = {
  name: '',
  email: '',
  password: '',
  countryCode: '',
  vertical: '',
  companyName: '',
  phoneLocal: '',
  acceptedTerms: false,
}

const TOTAL_STEPS = 4

export function useRegisterForm() {
  const [currentStep, setCurrentStep] = useState(1)
  const [formData, setFormData] = useState<RegisterFormData>(INITIAL_FORM_DATA)
  const [errors, setErrors] = useState<FormErrors>({})

  const updateField = useCallback(<K extends keyof RegisterFormData>(
    field: K,
    value: RegisterFormData[K],
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }))
    setErrors((prev) => {
      const next = { ...prev }
      delete next[field]
      return next
    })
  }, [])

  const validateStep = useCallback((step: number): FormErrors => {
    const errs: FormErrors = {}

    if (step === 1) {
      if (!formData.name.trim()) errs.name = 'required'
      if (!formData.email.trim()) errs.email = 'required'
      else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(formData.email)) errs.email = 'invalidEmail'
      if (!formData.password) errs.password = 'required'
      else if (formData.password.length < 8) errs.password = 'minLength'
    }

    if (step === 2) {
      if (!formData.countryCode) errs.countryCode = 'required'
      if (!formData.vertical) errs.vertical = 'required'
    }

    if (step === 3) {
      if (!formData.companyName.trim()) errs.companyName = 'required'
      else if (formData.companyName.trim().length < 2) errs.companyName = 'minLength'
    }

    if (step === 4) {
      if (!formData.acceptedTerms) errs.acceptedTerms = 'required'
    }

    return errs
  }, [formData])

  const goToNext = useCallback(() => {
    const stepErrors = validateStep(currentStep)
    if (Object.keys(stepErrors).length > 0) {
      setErrors(stepErrors)
      return false
    }
    setErrors({})
    if (currentStep < TOTAL_STEPS) {
      setCurrentStep((s) => s + 1)
    }
    return true
  }, [currentStep, validateStep])

  const goBack = useCallback(() => {
    if (currentStep > 1) {
      setCurrentStep((s) => s - 1)
      setErrors({})
    }
  }, [currentStep])

  const goToStep = useCallback((step: number) => {
    if (step >= 1 && step <= TOTAL_STEPS) {
      setCurrentStep(step)
      setErrors({})
    }
  }, [])

  const buildPayload = useCallback((): RegisterPayload => {
    const defaults = getCountryDefaults(formData.countryCode)
    const dialCode = getDialCode(formData.countryCode)
    const phone = formData.phoneLocal
      ? `${dialCode}${formData.phoneLocal}`
      : ''

    return {
      name: formData.name.trim(),
      email: formData.email.trim().toLowerCase(),
      password: formData.password,
      password_confirmation: formData.password,
      company_name: formData.companyName.trim(),
      country_code: formData.countryCode,
      vertical: formData.vertical,
      phone,
      currency: defaults.currency,
      locale: defaults.locale,
      timezone: defaults.timezone,
      platform: 'web',
    }
  }, [formData])

  return {
    currentStep,
    formData,
    errors,
    updateField,
    validateStep,
    goToNext,
    goBack,
    goToStep,
    buildPayload,
    totalSteps: TOTAL_STEPS,
  }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd apps/web && pnpm vitest run src/features/auth/__tests__/useRegisterForm.test.ts`

Expected: PASS — all 6 tests green.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/auth/hooks/useRegisterForm.ts \
  apps/web/src/features/auth/__tests__/useRegisterForm.test.ts
git commit -m "feat(auth): add useRegisterForm hook with step validation and payload builder"
```

---

## Task 5: i18n — Add All New Translation Keys

**Files:**
- Modify: `apps/web/src/locales/en/auth.json`
- Modify: `apps/web/src/locales/fr/auth.json`

- [ ] **Step 1: Update English translations**

Replace the full `register` section and add new keys. Key changes:
- Update step titles/subtitles for new step flow
- Add password strength labels
- Add brand panel content
- Add Otospex verticals (6 new entries)
- Add email check error
- Remove deprecated `confirmPassword`, `passwordMismatch`, `companyLegalName`, `taxId` keys

New/updated keys in `en/auth.json`:

```json
{
  "register": {
    "title": "Create your account",
    "step1Title": "Account Details",
    "step1Subtitle": "Enter your personal information",
    "step2Title": "Your Business",
    "step2Subtitle": "Tell us about your business",
    "step3Title": "Company",
    "step3Subtitle": "Set up your company profile",
    "step4Title": "Review & Create",
    "step4Subtitle": "Confirm your details",
    "name": "Full name",
    "email": "Email address",
    "emailTaken": "This email is already registered",
    "password": "Password",
    "passwordHint": "Minimum 8 characters",
    "country": "Country",
    "selectCountry": "Select your country",
    "companyName": "Company name",
    "phone": "Phone number (optional)",
    "acceptTerms": "I agree to the",
    "termsLink": "Terms of Service",
    "privacyLink": "Privacy Policy",
    "acceptTermsRequired": "You must accept the terms to continue",
    "createAccount": "Create Account",
    "creating": "Creating account...",
    "alreadyHaveAccount": "Already have an account?",
    "signIn": "Sign in",
    "next": "Continue",
    "back": "Back",
    "editSection": "Edit"
  },
  "passwordStrength": {
    "weak": "Weak",
    "fair": "Fair",
    "strong": "Strong"
  },
  "brandPanel": {
    "izipos": {
      "tagline": "The modern point-of-sale system for your business",
      "features": [
        "Inventory & stock management",
        "Invoicing & fiscal compliance",
        "Real-time sales analytics",
        "Multi-location support"
      ],
      "trial": "14-day free trial, no credit card required"
    },
    "otospex": {
      "tagline": "Complete automotive business management solution",
      "features": [
        "Workshop & service management",
        "Parts inventory tracking",
        "Customer vehicle history",
        "Invoicing & compliance"
      ],
      "trial": "14-day free trial, no credit card required"
    }
  },
  "verticals": {
    "retail": { "label": "Retail Store", "description": "General store" },
    "pharmacy": { "label": "Pharmacy", "description": "Drug store" },
    "restaurant": { "label": "Restaurant", "description": "Full-service dining" },
    "coffee_shop": { "label": "Coffee Shop", "description": "Café & bakery" },
    "fashion": { "label": "Fashion Boutique", "description": "Fashion boutique" },
    "parapharmacy": { "label": "Parapharmacy", "description": "Health & wellness" },
    "mechanic": { "label": "Mechanic", "description": "Automotive repair" },
    "body_shop": { "label": "Body Shop", "description": "Body repair & paint" },
    "parts_retailer": { "label": "Parts Retailer", "description": "Parts retail" },
    "car_glass": { "label": "Car Glass", "description": "Glass replacement" },
    "tire_shop": { "label": "Tire Shop", "description": "Tire sales & service" },
    "service_station": { "label": "Service Station", "description": "Fuel station" }
  }
}
```

The rest of the file (`login`, `verification`, `forgotPassword`, `resetPassword`, `logout`, `errors`, `user`) remains unchanged.

- [ ] **Step 2: Update French translations**

Same structure in `fr/auth.json`. Key French translations:

```json
{
  "register": {
    "title": "Créez votre compte",
    "step1Title": "Informations personnelles",
    "step1Subtitle": "Entrez vos informations personnelles",
    "step2Title": "Votre activité",
    "step2Subtitle": "Parlez-nous de votre entreprise",
    "step3Title": "Entreprise",
    "step3Subtitle": "Configurez votre profil entreprise",
    "step4Title": "Vérification & Création",
    "step4Subtitle": "Confirmez vos informations",
    "name": "Nom complet",
    "email": "Adresse e-mail",
    "emailTaken": "Cette adresse e-mail est déjà utilisée",
    "password": "Mot de passe",
    "passwordHint": "Minimum 8 caractères",
    "country": "Pays",
    "selectCountry": "Sélectionnez votre pays",
    "companyName": "Nom de l'entreprise",
    "phone": "Numéro de téléphone (optionnel)",
    "acceptTerms": "J'accepte les",
    "termsLink": "Conditions d'utilisation",
    "privacyLink": "Politique de confidentialité",
    "acceptTermsRequired": "Vous devez accepter les conditions pour continuer",
    "createAccount": "Créer le compte",
    "creating": "Création en cours...",
    "alreadyHaveAccount": "Vous avez déjà un compte ?",
    "signIn": "Se connecter",
    "next": "Continuer",
    "back": "Retour",
    "editSection": "Modifier"
  },
  "passwordStrength": {
    "weak": "Faible",
    "fair": "Moyen",
    "strong": "Fort"
  },
  "brandPanel": {
    "izipos": {
      "tagline": "Le système de point de vente moderne pour votre commerce",
      "features": [
        "Gestion des stocks et inventaire",
        "Facturation et conformité fiscale",
        "Analyses des ventes en temps réel",
        "Support multi-établissements"
      ],
      "trial": "Essai gratuit de 14 jours, sans carte bancaire"
    },
    "otospex": {
      "tagline": "Solution complète de gestion pour l'automobile",
      "features": [
        "Gestion d'atelier et services",
        "Suivi de stock pièces détachées",
        "Historique véhicules clients",
        "Facturation et conformité"
      ],
      "trial": "Essai gratuit de 14 jours, sans carte bancaire"
    }
  },
  "verticals": {
    "retail": { "label": "Commerce de détail", "description": "Commerce général" },
    "pharmacy": { "label": "Pharmacie", "description": "Pharmacie" },
    "restaurant": { "label": "Restaurant", "description": "Restauration" },
    "coffee_shop": { "label": "Café", "description": "Café & boulangerie" },
    "fashion": { "label": "Boutique de mode", "description": "Boutique de mode" },
    "parapharmacy": { "label": "Parapharmacie", "description": "Santé & bien-être" },
    "mechanic": { "label": "Mécanique", "description": "Mécanique auto" },
    "body_shop": { "label": "Carrosserie", "description": "Carrosserie & peinture" },
    "parts_retailer": { "label": "Vente de pièces", "description": "Vente de pièces" },
    "car_glass": { "label": "Vitrage auto", "description": "Remplacement vitrage" },
    "tire_shop": { "label": "Pneumatiques", "description": "Pneumatiques" },
    "service_station": { "label": "Station-service", "description": "Station-service" }
  }
}
```

- [ ] **Step 3: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit 2>&1 | head -20`

Expected: Clean output (no errors).

- [ ] **Step 4: Commit**

```bash
git add apps/web/src/locales/en/auth.json apps/web/src/locales/fr/auth.json
git commit -m "feat(auth): add signup redesign translation keys (en/fr) with Otospex verticals"
```

---

## Task 6: Reusable Molecules — VerticalCard, PasswordStrength, PhoneInput

**Files:**
- Create: `apps/web/src/features/auth/components/VerticalCard.tsx`
- Create: `apps/web/src/features/auth/components/PasswordStrength.tsx`
- Create: `apps/web/src/features/auth/components/PhoneInput.tsx`

- [ ] **Step 1: Create VerticalCard molecule**

Create `apps/web/src/features/auth/components/VerticalCard.tsx`:

A selectable card with icon, name, and description. Props: `vertical: VerticalConfig`, `selected: boolean`, `onSelect: () => void`. Uses `t()` for label/description. Icon rendered via the Lucide component from config. Card border/bg changes on selection.

Key structure:
- Outer `button` element (keyboard accessible, `role="option"`, `aria-selected`)
- 44×44 icon container with gradient bg
- Name (h3, semibold) + description (p, small gray text)
- Selected state: `border-blue-500 bg-blue-50`, unselected: `border-gray-200 bg-white`

- [ ] **Step 2: Create PasswordStrength molecule**

Create `apps/web/src/features/auth/components/PasswordStrength.tsx`:

Props: `password: string`. Renders a horizontal bar with 3 segments. Color/fill based on length: <8 → 1 red segment, 8-11 → 2 yellow segments, 12+ → 3 green segments. Label below from `t('auth:passwordStrength.*')`.

- [ ] **Step 3: Create PhoneInput molecule**

Create `apps/web/src/features/auth/components/PhoneInput.tsx`:

Props: `countryCode: string`, `value: string`, `onChange: (value: string) => void`, `error?: string`. Renders: non-editable gray badge with dial code prefix (from `getDialCode(countryCode)`) + standard `Input` atom for local number. Uses `FormField` wrapper with label from `t()`.

- [ ] **Step 4: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit 2>&1 | head -20`

Expected: Clean.

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/auth/components/VerticalCard.tsx \
  apps/web/src/features/auth/components/PasswordStrength.tsx \
  apps/web/src/features/auth/components/PhoneInput.tsx
git commit -m "feat(auth): add VerticalCard, PasswordStrength, PhoneInput molecules"
```

---

## Task 7: Step Components

**Files:**
- Create: `apps/web/src/features/auth/components/AccountStep.tsx`
- Create: `apps/web/src/features/auth/components/BusinessStep.tsx`
- Create: `apps/web/src/features/auth/components/CompanyStep.tsx`
- Create: `apps/web/src/features/auth/components/ReviewStep.tsx`
- Create: `apps/web/src/features/auth/components/RegisterProgress.tsx`

- [ ] **Step 1: Create RegisterProgress molecule**

Create `apps/web/src/features/auth/components/RegisterProgress.tsx`:

Props: `currentStep: number`, `totalSteps: number`. Renders 4 equal horizontal bar segments. Active/completed segments filled blue, upcoming segments gray. `role="progressbar"` with `aria-valuenow={currentStep}` and `aria-valuemax={totalSteps}`.

- [ ] **Step 2: Create AccountStep**

Create `apps/web/src/features/auth/components/AccountStep.tsx`:

Props: form data, errors, updateField, onEmailCheck callback. Uses `FormField` + `Input` atoms for name and email. Password field uses `Input` with Eye/EyeOff toggle + `PasswordStrength` below it. Debounced email check: calls `POST /auth/check-email` via `useMutation` 500ms after typing stops. Shows inline error from `t('auth:register.emailTaken')` if unavailable.

- [ ] **Step 3: Create BusinessStep**

Create `apps/web/src/features/auth/components/BusinessStep.tsx`:

Props: form data, errors, updateField, product (from `useProductConfig`). Country dropdown at top using `Select` atom — import the full country list from `apps/web/src/lib/countries.ts` (existing static list with `code`, `name`, `nameEn`, `nameFr`). Render pinned countries first (from `PINNED_COUNTRIES`), separator `<option disabled>───────</option>`, then remaining countries alphabetically. Use `getCountryName(code, locale)` for display. Below: 2-column grid of `VerticalCard` components from `getVerticalsForProduct(product)`. Vertical grid wrapped in `div` with `role="listbox"`, each `VerticalCard` has `role="option"` + `aria-selected`. Arrow key navigation: `onKeyDown` handler on listbox moves focus between cards with ArrowUp/Down/Left/Right.

- [ ] **Step 4: Create CompanyStep**

Create `apps/web/src/features/auth/components/CompanyStep.tsx`:

Props: form data, errors, updateField. `FormField` + `Input` for company name. `PhoneInput` for phone with `countryCode` from form data.

- [ ] **Step 5: Create ReviewStep**

Create `apps/web/src/features/auth/components/ReviewStep.tsx`:

Props: form data, errors, onGoToStep, onSubmit, isSubmitting. Three summary sections (Account, Business, Company) each with a Pencil icon button calling `onGoToStep(stepNumber)`. Terms checkbox using `FormField`. Submit button disabled until terms accepted + not submitting.

- [ ] **Step 6: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit 2>&1 | head -20`

Expected: Clean.

- [ ] **Step 7: Commit**

```bash
git add apps/web/src/features/auth/components/
git commit -m "feat(auth): add step components (Account, Business, Company, Review, Progress)"
```

---

## Task 8: Brand Panel & RegisterPage Assembly

**Files:**
- Create: `apps/web/src/features/auth/components/RegisterBrandPanel.tsx`
- Modify: `apps/web/src/features/auth/RegisterPage.tsx`

- [ ] **Step 1: Create RegisterBrandPanel**

Create `apps/web/src/features/auth/components/RegisterBrandPanel.tsx`:

Props: none (reads product from `useProductConfig`). Dark `slate-900` background. Product logo/name at top. Tagline from `t('auth:brandPanel.{product}.tagline')`. Feature list with Lucide `Check` icons in green circles. Trial message at bottom. Responsive: below `md` breakpoint, collapses to horizontal strip.

- [ ] **Step 2: Rewrite RegisterPage**

Replace the entire content of `apps/web/src/features/auth/RegisterPage.tsx`.

Key structure:
```
<div className="flex min-h-screen">
  <RegisterBrandPanel />                    {/* Left 40% */}
  <div className="flex-1 flex flex-col">    {/* Right 60% */}
    <RegisterProgress />
    <StepTitle + StepSubtitle />
    {currentStep === 1 && <AccountStep />}
    {currentStep === 2 && <BusinessStep />}
    {currentStep === 3 && <CompanyStep />}
    {currentStep === 4 && <ReviewStep />}
    <NavigationButtons />
    <SignInLink />
  </div>
</div>
```

Uses `useRegisterForm` for state, `useCountryDetect` to set initial country on step 2, `useProductConfig` for product context, `useMutation` for registration API call (set `isSubmitting` state during request, show toast on error via `t('auth:errors.registrationFailed')`). Hash-based step tracking: `useEffect` sets `window.location.hash = '#step-${currentStep}'` on step change. Add `popstate` listener to handle browser back. Focus management: `useEffect` on step change focuses the first input in the new step via `ref`.

Responsive: below `md`, brand panel is a strip at top, form takes full width.

- [ ] **Step 3: Verify TypeScript compiles**

Run: `cd apps/web && npx tsc --noEmit 2>&1 | head -20`

Expected: Clean.

- [ ] **Step 4: Visual smoke test**

Run: `cd apps/web && pnpm dev`

Open `http://localhost:5173/register` and verify:
- Split layout renders (brand panel left, form right)
- Step 1 shows name/email/password fields
- Password show/hide toggle works
- Step 2 shows country dropdown (auto-detected) + vertical card grid
- Step 3 shows company name + phone with prefix
- Step 4 shows review summary with edit icons
- Back/Next navigation works
- Form submits successfully

- [ ] **Step 5: Commit**

```bash
git add apps/web/src/features/auth/components/RegisterBrandPanel.tsx \
  apps/web/src/features/auth/RegisterPage.tsx
git commit -m "feat(auth): rewrite RegisterPage with split layout, step components, Lucide icons"
```

---

## Task 9: Final Verification & Cleanup

- [ ] **Step 1: Run all auth tests**

Run: `cd apps/web && pnpm vitest run src/features/auth/`

Expected: All tests pass.

- [ ] **Step 2: Run TypeScript check**

Run: `cd apps/web && npx tsc --noEmit`

Expected: Clean — no errors.

- [ ] **Step 3: Run ESLint**

Run: `cd apps/web && pnpm lint`

Expected: No new errors. Fix any that appear.

- [ ] **Step 4: Run PHP checks**

Run: `cd apps/api && ./vendor/bin/phpstan analyse app/Modules/Identity/ --level 8 2>&1 | tail -5`

Expected: No errors.

- [ ] **Step 5: Delete old test artifacts if any**

Check for any leftover files from the old RegisterPage that are no longer needed (e.g., unused imports, dead CSS).

- [ ] **Step 6: Final commit if any cleanup was needed**

Stage only the specific files that were cleaned up (do not use `git add -A`), then commit:

```bash
git commit -m "chore(auth): cleanup after signup flow redesign"
```
