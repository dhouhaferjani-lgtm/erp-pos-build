<?php

declare(strict_types=1);

namespace App\Modules\POS\Presentation\Resources;

use App\Modules\Company\Application\Services\LocationStockPolicyResolver;
use App\Modules\Company\Domain\Enums\PosStockPolicy;
use App\Modules\Inventory\Application\Services\CountingBlockService;
use App\Modules\POS\Domain\Terminal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Terminal
 */
final class TerminalResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value ?? 'physical',
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'location_id' => $this->location_id,
            // Live inventory counting task A4: the resolved PER-LOCATION policy
            // (onboarding mode forces Off; else the location's override, else the
            // company's policy) — same payload key, old POS builds keep working.
            // Requires BOTH `location` and `company` eager-loaded (every real
            // controller call site loads both together via ->with(['location',
            // 'company'])); if either is missing we deliberately fall back to
            // Block rather than triggering a lazy per-terminal DB round trip.
            'pos_stock_policy' => $this->whenLoaded('location', function () {
                $location = $this->location;

                if ($this->relationLoaded('company') && ! $location->relationLoaded('company')) {
                    $location->setRelation('company', $this->company);
                }

                $needsCompany = ! $location->onboarding_mode
                    && ! (is_string($location->pos_stock_policy_override) && $location->pos_stock_policy_override !== '');

                if ($needsCompany && ! $location->relationLoaded('company')) {
                    return PosStockPolicy::Block->value;
                }

                return (new LocationStockPolicyResolver)->resolve($location)->value;
            }, PosStockPolicy::Block->value),
            // Live inventory counting task C2: device-enforced sales blocking +
            // soft zone advisories, resolved per the terminal's location. Gated
            // on `location` being eager-loaded (same discipline as
            // `pos_stock_policy` above) so list endpoints that don't hydrate the
            // relation don't trigger a per-terminal counting lookup; the POS
            // device terminal payload always loads it. `CountingBlockService`
            // is a pure domain function (no deps), instantiated directly like
            // `LocationStockPolicyResolver`. `company_id` is a plain column on
            // `Location` (not a relation), so it's already hydrated whenever
            // `location` is loaded — passing it in skips the redundant
            // per-terminal `Location` lookup `CountingBlockService` would
            // otherwise run once per closure (2x per terminal payload).
            'active_counting_block' => $this->whenLoaded('location', function () {
                $block = (new CountingBlockService)->activeBlockFor(
                    (string) $this->location->id,
                    (string) $this->location->company_id,
                );

                if ($block === null) {
                    return null;
                }

                return [
                    'counting_id' => $block->id,
                    'counting_number' => $block->counting_number,
                    'started_at' => $block->activated_at?->toISOString(),
                ];
            }, null),
            'counting_zone_advisories' => $this->whenLoaded('location', function () {
                return (new CountingBlockService)->zoneAdvisoriesFor(
                    (string) $this->location->id,
                    (string) $this->location->company_id,
                );
            }, []),
            'location' => $this->whenLoaded('location', function () {
                return [
                    'id' => $this->location->id,
                    'name' => $this->location->name,
                    'code' => $this->location->code,
                    'tax_id' => $this->location->tax_id,
                    'vat_number' => $this->location->vat_number,
                    'legal_identifiers' => $this->location->legal_identifiers,
                    'address_street' => $this->location->address_street,
                    'address_city' => $this->location->address_city,
                    'address_postal_code' => $this->location->address_postal_code,
                    'address_country' => $this->location->address_country,
                ];
            }),
            'hardware_identifier' => $this->hardware_identifier,
            'is_active' => $this->is_active,
            'activated_at' => $this->activated_at?->toISOString(),
            'deactivated_at' => $this->deactivated_at?->toISOString(),
            'deactivation_reason' => $this->deactivation_reason,
            'has_history' => ($this->receipts_count ?? $this->receipts()->count()) > 0
                || ($this->shifts_count ?? $this->shifts()->count()) > 0,
            // Offline-first shifts Phase 6.1: the device seeds its per-terminal
            // `shift_number` counter from this so a fresh install continues
            // numbering from the server's MAX rather than restarting at 1 (which
            // would collide with server-projected numbers from a prior install).
            // `withMax('shifts', 'shift_number')` populates the eager attribute
            // when the caller opts in; otherwise we fall back to a lazy MAX, the
            // same pattern `has_history` uses above. Null (no shifts) → 0.
            'max_shift_number' => (int) ($this->shifts_max_shift_number
                ?? $this->shifts()->max('shift_number')
                ?? 0),
            'genesis_seed' => $this->genesis_seed,
            'last_hash' => $this->last_hash,
            'hash_sequence' => max(0, $this->current_sequence - 1),
            'current_sequence' => $this->current_sequence,
            'current_year' => $this->current_year,
            // Codex review B1 (2026-04-30): expose the fiscal hash schema version
            // so the offline POS client can branch on it (v2 → legacy
            // computeFiscalHash, v3 → canonical-payload SHA-256 builder).
            'fiscal_schema_version' => (int) $this->fiscal_schema_version,
            // v3-refund-chain-integration spec §6.4/§9.1/§9.3 — the two-phase
            // enable/acknowledge capability flags. The device reads
            // `v4_refund_authoring_enabled` via `terminal_state` sync (§9.1's
            // dedicated setter) rather than this resource directly, but the
            // fields are exposed here for operator-facing terminal detail
            // views and the enablement command's own visibility.
            'v4_refund_authoring_enabled' => (bool) $this->v4_refund_authoring_enabled,
            'v4_refund_authoring_acknowledged_at' => $this->v4_refund_authoring_acknowledged_at?->toISOString(),
            'is_training_mode' => (bool) $this->is_training_mode,
            // Precision: rely on the model `decimal:2` cast so trailing zeros are
            // preserved (e.g. '15.00' not 15.0). A (float) cast would launder the
            // string back into a lossy float and drop the scale.
            'max_discount_percent' => $this->max_discount_percent,
            'allow_line_discounts' => (bool) $this->allow_line_discounts,
            'allow_transaction_discounts' => (bool) $this->allow_transaction_discounts,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
