/**
 * Canonical FraudSettings type — re-exported from the generated backend DTO
 * `App.Modules.Compliance.Application.DTOs.CompanyFraudSettingsData`.
 *
 * Per Rule #7 (types flow from backend), do NOT hand-edit this shape.
 * To change keys or add fields, edit
 *   apps/api/app/Modules/Compliance/Application/DTOs/CompanyFraudSettingsData.php
 * and regenerate:
 *   cd apps/api && php artisan typescript:transform
 *
 * The hand-written `FraudSettings` interface that previously lived in
 * `./fraud.ts` was deleted as part of Q2 deferred-item M5. The two
 * shapes (snake_case admin DTO vs. POS `FraudSettingsDTO` camelCase
 * subset) were never the same type — the admin endpoint returns the
 * full CompanyFraudSettings shape, while the POS DTO is a derived
 * cash-variance view used by FraudSettingsResolver server-side only.
 */

export type FraudSettings = App.Modules.Compliance.Application.DTOs.CompanyFraudSettingsData
