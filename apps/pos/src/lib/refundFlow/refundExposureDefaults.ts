/**
 * Lane C M2/M3 — device-side FALLBACK constants for the refund-exposure
 * policies.
 *
 * ## Precedence (ruled, and the whole point of this module)
 *
 *   cached tenant setting  >  these device fallback constants
 *
 * The tenant value is authoritative whenever the device has one. These
 * constants exist for exactly one situation: the device has never received a
 * fraud-settings payload carrying the M2/M3 fields — a terminal activated
 * before the tenant DB ran the refund-exposure migration, or a server build
 * that predates it. The brief's fail-closed posture is deliberately NOT
 * applied there: an ABSENT setting on a not-yet-migrated tenant must behave
 * as a sane default, not as a hard refusal, or the rollout itself would take
 * refunds offline for every terminal until the last tenant migrated.
 *
 * A setting that is PRESENT but UNREADABLE (non-numeric, negative, NaN) is a
 * different fact — the device holds data it cannot trust — and still fails
 * closed with a refusal. See `readRefundExposurePolicy()` in
 * `refundCheckoutStore.ts`.
 *
 * These values are byte-identical to the seeded server defaults in
 * `App\Modules\Compliance\Domain\CompanyFraudSettings::DEFAULT_*` and to the
 * SQLite column defaults in migration 67. Change all three together.
 */

/** M2 — refunds per shift allowed while the device holds unsynced fiscal events. */
export const DEFAULT_OFFLINE_REFUND_COUNT_CEILING = 5;

/** M2 — cumulative refund payout per shift allowed while unsynced. */
export const DEFAULT_OFFLINE_REFUND_VALUE_CEILING = '300.0000';

/** M3 — the largest single refund that may be authored while offline. */
export const DEFAULT_ONLINE_REQUIRED_REFUND_THRESHOLD = '100.0000';
