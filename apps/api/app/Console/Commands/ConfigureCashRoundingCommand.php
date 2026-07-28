<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Treasury\Domain\CountryPaymentSettings;
use App\Shared\Domain\CashRoundingCaps;
use App\Shared\Domain\CountryPaymentDefaults;
use App\Shared\Domain\CurrencyScale;
use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Tenant-DB-scoped cash-rounding configuration (spec §4.2).
 *
 * Run via `tenants:run`. There is deliberately NO `--tenant` flag: the tenancy
 * runner switches the default connection per tenant, and a flag would invite
 * half-applied state.
 *
 * INVOCATION — stancl/tenancy's runner takes the command NAME as its single
 * argument and forwards flags ONLY through repeatable `--option='k=v'` pairs
 * (`vendor/stancl/tenancy/src/Commands/Run.php:22-25,48-52`). There is no `--`
 * passthrough; Symfony rejects it. Boolean flags are passed as `=1`:
 *
 *   php artisan tenants:run pos:configure-cash-rounding --option='verify=1'
 *   php artisan tenants:run pos:configure-cash-rounding \
 *       --option='country=TN' --option='denomination=0.0500'
 *   php artisan tenants:run pos:configure-cash-rounding \
 *       --option='country=TN' --option='enable-rounding=1'
 *
 * THE EXIT CODE IS NOT A GATE under `tenants:run`: `Run::handle()` returns null
 * after `$this->call(...)`, so the child's status is swallowed and the runner
 * always exits 0. `--verify` therefore emits ONE stable summary token as its
 * last line — `CASH-ROUNDING VERIFY FAILURES: <n>` — which deploy checklists
 * gate on:
 *
 *   php artisan tenants:run pos:configure-cash-rounding --option='verify=1' \
 *     | tee /tmp/verify.log; grep -q 'CASH-ROUNDING VERIFY FAILURES: 0' /tmp/verify.log
 *
 * (grep for the token per tenant block; the ABSENCE of the token means the
 * command aborted before verifying and must be treated as a failure.)
 *
 * The two switches are INDEPENDENT (`--enable-rounding` / `--disable-rounding`
 * vs `--enable-tolerance` / `--disable-tolerance`) and neither touches the B2B
 * `payment_tolerance_enabled` column.
 *
 * Every denomination this command writes — and, under `--verify`, every one it
 * finds already stored behind an ENABLED rounding switch — is validated through
 * the exact checks `PosPaymentPolicyResolver::resolveRounding()` applies, using
 * the shared `CashRoundingCaps` table. A value the resolver would reject must
 * never be stored: the resolver fail-closes silently, so the operator would
 * believe rounding is on while the device never rounds.
 */
final class ConfigureCashRoundingCommand extends Command
{
    protected $signature = 'pos:configure-cash-rounding
                            {--country=TN : ISO 3166-1 alpha-2 country code of the settings row}
                            {--denomination= : Rounding denomination to store, e.g. 0.0500}
                            {--enable-rounding : Set cash_rounding_enabled = true}
                            {--disable-rounding : Set cash_rounding_enabled = false}
                            {--enable-tolerance : Set pos_tolerance_enabled = true}
                            {--disable-tolerance : Set pos_tolerance_enabled = false}
                            {--dry-run : Report changes without writing them}
                            {--verify : Report per-tenant state and assert every company has an is_cash_tender method}';

    protected $description = 'Configure country-level POS cash rounding and tender tolerance for the current tenant database.';

    /** Scale of the `country_payment_settings.cash_rounding_denomination` decimal(15,4) column. */
    private const DENOMINATION_STORAGE_SCALE = 4;

    /**
     * Machine-readable `--verify` result prefix, emitted as `<prefix> <n>`.
     *
     * Deploy checklists gate on this token because `tenants:run` swallows the
     * exit code. Pinned by ConfigureCashRoundingCommandTest — changing it
     * silently breaks every checklist that greps for it.
     */
    public const VERIFY_TOKEN_PREFIX = 'CASH-ROUNDING VERIFY FAILURES:';

    public function __construct(private readonly DatabaseManager $database)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Schema::hasTable('country_payment_settings')
            || ! Schema::hasTable('companies')
            || ! Schema::hasTable('countries')
            || ! Schema::hasTable('payment_methods')
            || ! Schema::hasColumn('country_payment_settings', 'cash_rounding_denomination')
            || ! Schema::hasColumn('payment_methods', 'is_cash_tender')) {
            $this->error(
                'Cash-rounding tables/columns are unavailable. Run tenant migrations and execute this command inside each tenant context (tenants:run).',
            );

            return self::FAILURE;
        }

        if ((bool) $this->option('verify')) {
            return $this->verify();
        }

        $countryCode = strtoupper(trim((string) $this->option('country')));
        if ($countryCode === '') {
            $this->error('The --country code is required.');

            return self::FAILURE;
        }

        if ((bool) $this->option('enable-rounding') && (bool) $this->option('disable-rounding')) {
            $this->error('--enable-rounding and --disable-rounding are mutually exclusive.');

            return self::FAILURE;
        }
        if ((bool) $this->option('enable-tolerance') && (bool) $this->option('disable-tolerance')) {
            $this->error('--enable-tolerance and --disable-tolerance are mutually exclusive.');

            return self::FAILURE;
        }

        $existing = $this->database->table('country_payment_settings')
            ->where('country_code', $countryCode)
            ->first();

        /** @var array<string, string|bool> $updates */
        $updates = [];

        $denomination = trim((string) ($this->option('denomination') ?? ''));
        if ($denomination !== '') {
            $normalized = $this->normalizeDenomination($countryCode, $denomination);
            if ($normalized === null) {
                return self::FAILURE;
            }
            $updates['cash_rounding_denomination'] = $normalized;
        }

        if ((bool) $this->option('enable-rounding')) {
            $updates['cash_rounding_enabled'] = true;
        }
        if ((bool) $this->option('disable-rounding')) {
            $updates['cash_rounding_enabled'] = false;
        }
        if ((bool) $this->option('enable-tolerance')) {
            $updates['pos_tolerance_enabled'] = true;
        }
        if ((bool) $this->option('disable-tolerance')) {
            $updates['pos_tolerance_enabled'] = false;
        }

        if ($updates === []) {
            $this->error('Nothing to do: pass --denomination and/or one of the enable/disable flags, or use --verify.');

            return self::FAILURE;
        }

        // Turning the switch ON while the EFFECTIVE denomination is one the
        // resolver would reject is the silent-no-op configuration: the operator
        // sees "rounding enabled", the device caches `enabled: false`. Refuse it
        // (the denomination supplied in this same invocation, if any, has already
        // been validated above).
        if (($updates['cash_rounding_enabled'] ?? null) === true
            && ! array_key_exists('cash_rounding_denomination', $updates)) {
            $stored = $this->storedDenomination($countryCode);
            if ($stored === null || $stored === '') {
                $this->error(sprintf(
                    'Country %s has no stored cash_rounding_denomination; pass --denomination together with --enable-rounding.',
                    $countryCode,
                ));

                return self::FAILURE;
            }

            if ($this->normalizeDenomination($countryCode, $stored) === null) {
                $this->error(sprintf(
                    'Country %s: the stored denomination is unusable (see above); rounding was NOT enabled.',
                    $countryCode,
                ));

                return self::FAILURE;
            }
        }

        // A row this command CREATES must carry the same pinned tolerance
        // ceilings CountryPaymentSettingsSeeder would have written. Falling back
        // to the raw column defaults would ship max_payment_tolerance_amount =
        // 0.50 where TN sanctions 0.100 — the resolver hands that ceiling to the
        // device verbatim, so POS auto-accept would silently loosen 5x from a
        // command whose contract is that it never moves the tolerance numbers.
        $pinned = $existing === null ? CountryPaymentDefaults::forCountry($countryCode) : null;

        if ($existing === null && ($updates['pos_tolerance_enabled'] ?? null) === true && $pinned === null) {
            $this->error(sprintf(
                'Country %s has no country_payment_settings row and no pinned tolerance ceilings, so this command '
                .'cannot create one without inventing a tolerance amount. Run `db:seed CountryPaymentSettingsSeeder` '
                .'first (or add %s to App\Shared\Domain\CountryPaymentDefaults), then re-run with --enable-tolerance.',
                $countryCode,
                $countryCode,
            ));

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf(
                '[DRY-RUN] Country %s: would %s %s%s',
                $countryCode,
                $existing === null ? 'INSERT' : 'UPDATE',
                json_encode($updates, JSON_THROW_ON_ERROR),
                $pinned === null ? '' : sprintf(
                    ' (+ pinned ceilings pct=%s max=%s)',
                    $pinned['payment_tolerance_percentage'],
                    $pinned['max_payment_tolerance_amount'],
                ),
            ));

            return self::SUCCESS;
        }

        $now = now();

        if ($existing === null) {
            if (! $this->database->table('countries')->where('code', $countryCode)->exists()) {
                $this->error(sprintf('Country %s is not present in the countries lookup; cannot create the settings row.', $countryCode));

                return self::FAILURE;
            }

            $row = [
                'id' => (string) Str::uuid(),
                'country_code' => $countryCode,
                'cash_rounding_enabled' => false,
                'pos_tolerance_enabled' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if ($pinned !== null) {
                $row['payment_tolerance_percentage'] = $pinned['payment_tolerance_percentage'];
                $row['max_payment_tolerance_amount'] = $pinned['max_payment_tolerance_amount'];
            }

            $this->database->table('country_payment_settings')->insert(array_merge($row, $updates));

            $this->info(sprintf('Country %s: settings row created.', $countryCode));

            return self::SUCCESS;
        }

        $updates['updated_at'] = $now->toDateTimeString();
        $this->database->table('country_payment_settings')
            ->where('country_code', $countryCode)
            ->update($updates);

        $this->info(sprintf('Country %s: settings row updated.', $countryCode));

        return self::SUCCESS;
    }

    /**
     * Normalize the operator-supplied denomination to decimal(15,4) storage
     * form and assert it survives EVERY check the resolver applies, at EVERY
     * company currency scale in this tenant. A value the resolver would reject
     * must never be stored — the resolver would silently report rounding
     * disabled and the operator would have no signal.
     */
    private function normalizeDenomination(string $countryCode, string $denomination): ?string
    {
        $stored = $this->toNumeric($denomination, self::DENOMINATION_STORAGE_SCALE);
        if ($stored === null) {
            $this->error(sprintf(
                'Denomination "%s" is not a plain decimal number (scientific notation and blanks are rejected).',
                $denomination,
            ));

            return null;
        }

        // decimal(15,4) TRUNCATES: 0.00255 would land as 0.0025, a different
        // number from the one the operator typed. Reject rather than silently
        // store something else.
        $comparisonScale = max(self::DENOMINATION_STORAGE_SCALE, $this->fractionDigits($denomination));
        $exact = $this->toNumeric($denomination, $comparisonScale);
        if ($exact === null || bccomp($stored, $exact, $comparisonScale) !== 0) {
            $this->error(sprintf(
                'Denomination "%s" does not fit the decimal(15,4) column without losing precision.',
                $denomination,
            ));

            return null;
        }

        if (bccomp($stored, '0', self::DENOMINATION_STORAGE_SCALE) <= 0) {
            $this->error(sprintf('Denomination "%s" must be greater than zero.', $denomination));

            return null;
        }

        foreach ($this->scalesInScope($countryCode) as $label => $scale) {
            if (! $this->assertUsableAtScale($stored, $scale, $label)) {
                return null;
            }
        }

        return $stored;
    }

    /**
     * The currency scales a denomination stored against $countryCode will be
     * resolved at, keyed by a human label for the error message.
     *
     * Mirrors `PosPaymentPolicyResolver::resolveScale()`: the scale is a
     * COMPANY-bound resolution that reads `countries.currency_decimal_places`
     * first and only falls back to the static ISO 4217 map. When no company is
     * registered in the country yet, the lookup row alone is used — an operator
     * pre-configuring a country must still be refused an illegal value.
     *
     * @return array<string, int>
     */
    private function scalesInScope(string $countryCode): array
    {
        $countryScale = $this->countryScale($countryCode);

        $companies = $this->database->table('companies')
            ->where('country_code', $countryCode)
            ->select(['id', 'currency'])
            ->orderBy('id')
            ->get();

        if ($companies->isEmpty()) {
            return [sprintf('country %s', $countryCode) => $countryScale ?? CurrencyScale::for($this->countryCurrency($countryCode))];
        }

        $scales = [];
        foreach ($companies as $company) {
            $currency = (string) $company->currency;
            $scale = $countryScale ?? CurrencyScale::for($currency);
            $scales[sprintf('company %s (%s)', (string) $company->id, $currency)] = $scale;
        }

        return $scales;
    }

    /**
     * `countries.currency_decimal_places` for the country, or null when the
     * lookup row (or the column) is absent — the ISO map is the fallback.
     */
    private function countryScale(string $countryCode): ?int
    {
        if (! Schema::hasColumn('countries', 'currency_decimal_places')) {
            return null;
        }

        $row = $this->database->table('countries')
            ->where('code', $countryCode)
            ->first(['currency_decimal_places']);

        $value = $row?->currency_decimal_places;

        return is_numeric($value) ? (int) $value : null;
    }

    private function countryCurrency(string $countryCode): string
    {
        $row = $this->database->table('countries')
            ->where('code', $countryCode)
            ->first(['currency_code']);

        return is_string($row?->currency_code) ? $row->currency_code : '';
    }

    /**
     * The two resolver checks that depend on the currency scale: exact
     * representability, and the shared static §4.1 ceiling.
     *
     * @param  numeric-string  $stored
     */
    private function assertUsableAtScale(string $stored, int $scale, string $label): bool
    {
        $comparisonScale = max(self::DENOMINATION_STORAGE_SCALE, $scale);

        $scaled = $this->toNumeric($stored, $scale);
        if ($scaled === null || bccomp($scaled, $stored, $comparisonScale) !== 0) {
            $this->error(sprintf(
                'Denomination %s is not representable at scale %d for %s; refusing to store it.',
                $stored,
                $scale,
                $label,
            ));

            return false;
        }

        // Shared, history-stable ceiling — the SAME table the resolver and the
        // fiscal validator read (App\Shared\Domain\CashRoundingCaps). An
        // unlisted scale has no sanctioned cap and is refused.
        if (! CashRoundingCaps::isWithinCap($scaled, $scale)) {
            $cap = CashRoundingCaps::forScale($scale);

            $this->error(sprintf(
                'Denomination %s exceeds the maximum sanctioned denomination for scale %d (%s) for %s; refusing to store it.',
                $stored,
                $scale,
                $cap ?? 'no cap is sanctioned at this scale',
                $label,
            ));

            return false;
        }

        return true;
    }

    /**
     * Re-scale a decimal string, or null when it is unusable.
     *
     * Fail-closed by construction, exactly like
     * `PosPaymentPolicyResolver::normalize()`: anything that is not a PLAIN
     * decimal literal (in particular the scientific notation `is_numeric()`
     * accepts but bcmath throws a `ValueError` on) yields null.
     *
     * @return numeric-string|null
     */
    private function toNumeric(string $value, int $scale): ?string
    {
        $trimmed = trim($value);

        if (preg_match('/^[+-]?\d+(\.\d+)?$/', $trimmed) !== 1) {
            return null;
        }

        try {
            return CurrencyScale::bcformatStrict($trimmed, $scale);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The stored denomination as a STRING, through the model's documented
     * `'cash_rounding_denomination' => 'string'` cast.
     *
     * Deliberately not read off the raw query builder: PDO_SQLite returns a
     * `decimal(15,4)` column as a PHP float (numeric affinity), so a raw read
     * would hand this command a float on the test/CI driver while Postgres
     * hands it a fixed-scale string. The model cast is the project's single
     * sanctioned conversion point for this column (see the CountryPaymentSettings
     * docblock) and is a no-op on Postgres.
     */
    private function storedDenomination(string $countryCode): ?string
    {
        $value = CountryPaymentSettings::query()
            ->where('country_code', $countryCode)
            ->value('cash_rounding_denomination');

        return is_string($value) ? $value : null;
    }

    private function fractionDigits(string $value): int
    {
        $fraction = strrchr(trim($value), '.');

        return $fraction === false ? 0 : strlen($fraction) - 1;
    }

    private function verify(): int
    {
        $failures = 0;

        // Read through the model so the money-shaped columns arrive as strings
        // on every driver (see storedDenomination()).
        $rows = CountryPaymentSettings::query()->orderBy('country_code')->get();

        if ($rows->isEmpty()) {
            $this->warn('No country_payment_settings rows exist in this tenant database.');
        }

        foreach ($rows as $row) {
            $this->line(sprintf(
                'country=%s rounding=%s denomination=%s pos_tolerance=%s b2b_tolerance=%s pct=%s max=%s',
                (string) $row->country_code,
                ((bool) $row->cash_rounding_enabled) ? 'ON' : 'off',
                (string) ($row->cash_rounding_denomination ?? 'null'),
                ((bool) $row->pos_tolerance_enabled) ? 'ON' : 'off',
                ((bool) $row->payment_tolerance_enabled) ? 'ON' : 'off',
                (string) $row->payment_tolerance_percentage,
                (string) $row->max_payment_tolerance_amount,
            ));

            // A row whose switch is ON but whose denomination the resolver
            // rejects is a silent no-op: the device caches `enabled: false`
            // while the operator believes rounding is live. Report it as a
            // failure — --verify is the Phase-1 gate that must catch it.
            if (! (bool) $row->cash_rounding_enabled) {
                continue;
            }

            $stored = $this->storedDenomination((string) $row->country_code);
            if ($stored === null || $this->normalizeDenomination((string) $row->country_code, $stored) === null) {
                $this->error(sprintf(
                    'Country %s has cash_rounding_enabled = true with an unusable denomination; the POS would silently not round.',
                    (string) $row->country_code,
                ));
                $failures++;
            }
        }

        $companies = $this->database->table('companies')
            ->select(['id', 'tenant_id', 'name'])
            ->orderBy('id')
            ->get();

        foreach ($companies as $company) {
            $hasCashTender = $this->database->table('payment_methods')
                ->where('tenant_id', (string) $company->tenant_id)
                ->where('company_id', (string) $company->id)
                ->where('is_cash_tender', true)
                ->where('is_active', true)
                ->exists();

            if (! $hasCashTender) {
                $this->error(sprintf(
                    'Company %s (%s) has no active is_cash_tender payment method; the POS cash checkout would be dead once the device predicate ships.',
                    (string) $company->id,
                    (string) $company->name,
                ));
                $failures++;

                continue;
            }

            $this->line(sprintf('Company %s: is_cash_tender OK.', (string) $company->id));
        }

        // STABLE GATE TOKEN — the exit code is swallowed by tenants:run (see the
        // class docblock), so this line is the machine-readable result. Its exact
        // shape is pinned by a test; do not reword it.
        $this->line(sprintf('%s %d', self::VERIFY_TOKEN_PREFIX, $failures));

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
