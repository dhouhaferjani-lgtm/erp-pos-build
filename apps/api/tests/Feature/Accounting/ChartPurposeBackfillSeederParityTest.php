<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Console\Commands\BackfillChartPurposesCommand;
use App\Console\Commands\BackfillTolerancePurposesCommand;
use App\Modules\Accounting\Application\Services\ChartOfAccountsService;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Database\Seeders\GenericChartOfAccountsSeeder;
use Database\Seeders\TunisiaChartOfAccountsSeeder;
use ReflectionMethod;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

/**
 * The backfill commands invent NO account codes (O-27 / F-4).
 *
 * `BackfillChartPurposesCommand` carries a per-country table of
 * (code, name, type, parent_code) tuples it creates missing system accounts
 * from. Those tuples are hand-mirrored from the frozen country seeders — the
 * country-defaults authority for the legacy provisioning arm that built every
 * brownfield chart — because a console command cannot reach into a private
 * seeder method at runtime without turning a data-writing repair path into a
 * reflection trick.
 *
 * This class is what makes that mirror safe: it re-derives every tuple from the
 * seeders' own definition tables and fails on any divergence, so
 *
 *  - a code that does not exist in the chart the tenant was provisioned with
 *    can never be created by the backfill, and
 *  - a seeder edit that moves a purpose to a different code/parent cannot leave
 *    the backfill silently creating the old one alongside it.
 *
 * No database is touched: the seeders' definition tables are pure data.
 * Reflection into a private method is the house pattern for binding a hand
 * mirror to its source here — see
 * `SeededChartManifestRequiredPurposeCompletenessTest::test_the_country_code_list_still_mirrors_the_provisioning_dispatch()`.
 */
#[UsesFrozenSeederFixture]
final class ChartPurposeBackfillSeederParityTest extends TestCase
{
    /**
     * Same three arms as `ChartOfAccountsService::getSeederForCountry()`; `XX`
     * is an unassigned code that exercises the `default` (generic) arm.
     *
     * @var array<string, class-string>
     */
    private const SEEDER_BY_COUNTRY = [
        'TN' => TunisiaChartOfAccountsSeeder::class,
        'FR' => FranceChartOfAccountsSeeder::class,
        'XX' => GenericChartOfAccountsSeeder::class,
    ];

    /**
     * The seeded chart for a country, indexed by system purpose.
     *
     * @return array<string, array{code: string, name: string, type: string, parent_code: string|null}>
     */
    private function seededChartByPurpose(string $countryCode): array
    {
        $class = self::SEEDER_BY_COUNTRY[$countryCode];
        $method = new ReflectionMethod($class, 'getAccountsDefinition');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $rows */
        $rows = $method->invoke((new \ReflectionClass($class))->newInstanceWithoutConstructor());

        $byPurpose = [];
        foreach ($rows as $row) {
            $purpose = $row['system_purpose'] ?? null;
            if (! is_string($purpose)) {
                continue;
            }

            $parentCode = $row['parent_code'] ?? null;

            $byPurpose[$purpose] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'type' => (string) $row['type'],
                'parent_code' => is_string($parentCode) ? $parentCode : null,
            ];
        }

        return $byPurpose;
    }

    /**
     * @return list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>
     */
    private function backfillDefinitions(object $command, string $countryCode): array
    {
        $method = new ReflectionMethod($command, 'definitions');
        $method->setAccessible(true);

        /** @var list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}> $definitions */
        $definitions = $method->invoke($command, $countryCode);

        return $definitions;
    }

    private function chartCommand(): BackfillChartPurposesCommand
    {
        return app(BackfillChartPurposesCommand::class);
    }

    public function test_every_chart_backfill_definition_matches_the_seeded_chart_row_for_that_country(): void
    {
        $command = $this->chartCommand();

        foreach (array_keys(self::SEEDER_BY_COUNTRY) as $countryCode) {
            $seeded = $this->seededChartByPurpose($countryCode);

            foreach ($this->backfillDefinitions($command, $countryCode) as $definition) {
                $purpose = $definition['purpose'];

                $this->assertArrayHasKey($purpose, $seeded, sprintf(
                    'Country %s: the backfill would create an account for purpose %s, but the %s chart maps no '
                    .'account to that purpose at all. The backfill must never invent a mapping the country chart '
                    .'does not define.',
                    $countryCode,
                    $purpose,
                    $countryCode,
                ));

                $this->assertSame(
                    [
                        'code' => $seeded[$purpose]['code'],
                        'name' => $seeded[$purpose]['name'],
                        'type' => $seeded[$purpose]['type'],
                        'parent_code' => $seeded[$purpose]['parent_code'],
                    ],
                    [
                        'code' => $definition['code'],
                        'name' => $definition['name'],
                        'type' => $definition['type'],
                        'parent_code' => $definition['parent_code'],
                    ],
                    sprintf(
                        'Country %s, purpose %s: the backfill tuple has drifted from the seeded chart row. '
                        .'The seeders are the country-defaults authority; copy the row, never adjust it here.',
                        $countryCode,
                        $purpose,
                    ),
                );
            }
        }
    }

    public function test_no_country_lists_the_same_purpose_twice(): void
    {
        $command = $this->chartCommand();

        foreach (array_keys(self::SEEDER_BY_COUNTRY) as $countryCode) {
            $purposes = array_map(
                static fn (array $definition): string => $definition['purpose'],
                $this->backfillDefinitions($command, $countryCode),
            );

            $this->assertSame(
                array_values(array_unique($purposes)),
                $purposes,
                sprintf(
                    'Country %s lists a purpose twice. UNIQUE(company_id, system_purpose) means the second pass '
                    .'would re-probe the row the first pass just wrote.',
                    $countryCode,
                ),
            );
        }
    }

    /**
     * The O-27 deliverable, stated as a fact about the command rather than as
     * prose in a report: after this lane, every manifest-REQUIRED purpose that
     * `requiredPurposes()` did not previously check is fillable on every seeded
     * chart.
     */
    public function test_the_fourteen_previously_unchecked_required_purposes_are_covered_for_every_country(): void
    {
        $command = $this->chartCommand();

        // The fourteen, DERIVED: manifest REQUIRED minus the pre-O-27
        // validation set. The pre-O-27 set is not re-typed here — it is the
        // complement, so this stays correct however the enum is written.
        $manifestRequired = [];
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] === 'REQUIRED') {
                $manifestRequired[] = $entry['purpose']->value;
            }
        }

        $this->assertCount(28, $manifestRequired, 'The manifest REQUIRED partition is no longer 28 entries.');

        foreach (array_keys(self::SEEDER_BY_COUNTRY) as $countryCode) {
            $covered = array_map(
                static fn (array $definition): string => $definition['purpose'],
                $this->backfillDefinitions($command, $countryCode),
            );

            foreach (self::PREVIOUSLY_UNCHECKED as $purpose) {
                $this->assertContains($purpose, $covered, sprintf(
                    'Country %s: manifest-REQUIRED purpose %s is one of the fourteen the O-27 ruling requires the '
                    .'backfill to cover, but this country has no definition for it — a brownfield chart missing it '
                    .'would fail the widened validation with no repair path.',
                    $countryCode,
                    $purpose,
                ));
            }
        }
    }

    /**
     * The fourteen manifest-REQUIRED purposes that `SystemAccountPurpose::requiredPurposes()`
     * did NOT check before this lane (P3-M2 reconciliation finding D-2).
     *
     * Frozen as a constant on purpose: once `requiredPurposes()` is widened to
     * the full 28, the complement is empty, so the list can no longer be
     * recomputed from the code it describes. It is a historical fact about the
     * pre-O-27 state and the exact scope the owner ruled on.
     *
     * @var list<string>
     */
    private const PREVIOUSLY_UNCHECKED = [
        'goods_received_not_invoiced',
        'inventory',
        'marketing_goodwill_expense',
        'payment_tolerance_expense',
        'payment_tolerance_income',
        'pos_tender_clearing',
        'purchase_expenses',
        'purchase_price_variance_expense',
        'purchase_price_variance_income',
        'purchase_stamp_duty',
        'rounding_loss_expense',
        'sales_discount',
        'sales_returns_clearing',
        'voucher_liability',
    ];

    public function test_the_previously_unchecked_list_is_a_subset_of_the_manifest_required_set(): void
    {
        $manifestRequired = [];
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] === 'REQUIRED') {
                $manifestRequired[] = $entry['purpose']->value;
            }
        }

        $this->assertCount(14, self::PREVIOUSLY_UNCHECKED);
        $this->assertEmpty(
            array_diff(self::PREVIOUSLY_UNCHECKED, $manifestRequired),
            'The O-27 scope list names a purpose the manifest does not classify REQUIRED.',
        );
    }

    /**
     * `BackfillTolerancePurposesCommand` owns `6580`/`7580` too — the O-27 arm
     * duplicates them deliberately, so that a deploy which runs only the chart
     * backfill still satisfies the widened validation. Duplication is only safe
     * while the two tables agree; this pins that.
     */
    public function test_the_tolerance_tuples_agree_with_the_dedicated_tolerance_backfill(): void
    {
        $chartCommand = $this->chartCommand();
        $toleranceCommand = app(BackfillTolerancePurposesCommand::class);

        $tolerancePurposes = [
            SystemAccountPurpose::PaymentToleranceExpense->value,
            SystemAccountPurpose::PaymentToleranceIncome->value,
        ];

        foreach (array_keys(self::SEEDER_BY_COUNTRY) as $countryCode) {
            $fromChart = $this->tuplesByPurpose($this->backfillDefinitions($chartCommand, $countryCode), $tolerancePurposes);
            $fromTolerance = $this->tuplesByPurpose($this->backfillDefinitions($toleranceCommand, $countryCode), $tolerancePurposes);

            $this->assertSame($fromTolerance, $fromChart, sprintf(
                'Country %s: the chart backfill and the tolerance backfill disagree on the tolerance accounts. '
                .'Two writers with two answers is how a chart ends up with the purpose on the wrong code.',
                $countryCode,
            ));
        }
    }

    /**
     * The OTHER `purchase_stamp_duty` writer.
     *
     * Unlike the tolerance pair, this second writer is not a command with a
     * `definitions()` method to reflect: it is the 2026-08-07 tenant migration,
     * and its mapping lives in an inline `match` inside a private method with
     * side effects, so it cannot be invoked. The binding is therefore a SOURCE
     * SCAN of exactly that match — the same technique
     * `SeededChartManifestRequiredPurposeCompletenessTest` uses to bind its
     * country list to `getSeederForCountry()`.
     *
     * Its third element is a LIST of parent-code candidates tried in order
     * (`['63', '6000']` for the French plan), not a single parent. That is a
     * fallback chain, not a disagreement: the PREFERRED parent is its first
     * entry, and that is what must match the backfill's `parent_code`.
     *
     * @return array<string, array{code: string, name: string, parent_code: string}>
     */
    private function stampDutyMigrationArms(): array
    {
        $path = database_path('migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php');
        $source = file_get_contents($path);

        $this->assertIsString($source, 'Could not read the 2026-08-07 purchase stamp duty migration.');

        $pattern = "/%s\\s*=>\\s*\\[\\s*'(?<code>[^']+)'\\s*,\\s*(?<name>\"[^\"]*\"|'[^']*')\\s*,\\s*\\[\\s*'(?<parent>[^']+)'/";

        $arms = [];
        foreach ([
            'FR' => "'TN',\\s*'FR'",
            'XX' => 'default',
        ] as $countryCode => $armPattern) {
            $matched = preg_match(sprintf($pattern, $armPattern), $source, $captures);

            $this->assertSame(1, $matched, sprintf(
                'The %s arm of the 2026-08-07 stamp-duty migration no longer matches the scan pattern, so this '
                .'dual-writer pin has gone vacuous. Re-read the migration and fix the pattern.',
                $countryCode,
            ));

            $arms[$countryCode] = [
                'code' => $captures['code'],
                // Both quoting styles appear in the source (the French name
                // carries an apostrophe); compare the VALUE, not the literal.
                'name' => substr($captures['name'], 1, -1),
                'parent_code' => $captures['parent'],
            ];
        }

        // TN and FR share the arm; the generic chart is the default arm.
        $arms['TN'] = $arms['FR'];

        return $arms;
    }

    /**
     * `purchase_stamp_duty` has TWO writers — this backfill and the 2026-08-07
     * tenant migration — and O-27 added it to the first. Two writers with two
     * answers is how a chart ends up with the purpose on the wrong code, so
     * they are pinned byte-identical per country on code, name and preferred
     * parent.
     */
    public function test_the_purchase_stamp_duty_tuples_agree_with_the_dedicated_stamp_duty_migration(): void
    {
        $command = $this->chartCommand();
        $arms = $this->stampDutyMigrationArms();

        foreach (array_keys(self::SEEDER_BY_COUNTRY) as $countryCode) {
            $fromChart = $this->tuplesByPurpose(
                $this->backfillDefinitions($command, $countryCode),
                [SystemAccountPurpose::PurchaseStampDuty->value],
            );

            $this->assertArrayHasKey(
                SystemAccountPurpose::PurchaseStampDuty->value,
                $fromChart,
                sprintf('Country %s lost its purchase_stamp_duty definition.', $countryCode),
            );

            $tuple = $fromChart[SystemAccountPurpose::PurchaseStampDuty->value];

            $this->assertSame($arms[$countryCode]['code'], $tuple['code'], sprintf(
                'Country %s: the chart backfill and the 2026-08-07 stamp-duty migration disagree on the ACCOUNT CODE.',
                $countryCode,
            ));
            $this->assertSame($arms[$countryCode]['name'], $tuple['name'], sprintf(
                'Country %s: the two purchase_stamp_duty writers disagree on the account NAME.',
                $countryCode,
            ));
            $this->assertSame($arms[$countryCode]['parent_code'], $tuple['parent_code'], sprintf(
                'Country %s: the chart backfill\'s parent_code must be the migration\'s PREFERRED (first) parent '
                .'candidate, or the same purpose lands under two different parents depending on which writer ran.',
                $countryCode,
            ));
            $this->assertSame('expense', $tuple['type']);
        }
    }

    /**
     * @param  list<array{code: string, name: string, type: string, parent_code: string|null, purpose: string}>  $definitions
     * @param  list<string>  $purposes
     * @return array<string, array{code: string, name: string, type: string, parent_code: string|null}>
     */
    private function tuplesByPurpose(array $definitions, array $purposes): array
    {
        $result = [];
        foreach ($definitions as $definition) {
            if (! in_array($definition['purpose'], $purposes, true)) {
                continue;
            }
            $result[$definition['purpose']] = [
                'code' => $definition['code'],
                'name' => $definition['name'],
                'type' => $definition['type'],
                'parent_code' => $definition['parent_code'],
            ];
        }
        ksort($result);

        return $result;
    }

    /**
     * Guards the reflection above: a rename of `getAccountsDefinition` or
     * `definitions` must fail loudly here rather than make every assertion in
     * this class vacuous.
     */
    public function test_the_reflection_targets_still_exist(): void
    {
        foreach (self::SEEDER_BY_COUNTRY as $countryCode => $class) {
            $this->assertTrue(
                method_exists($class, 'getAccountsDefinition'),
                sprintf('%s (%s) no longer exposes getAccountsDefinition(); this parity gate is vacuous.', $class, $countryCode),
            );
        }

        $this->assertTrue(method_exists(BackfillChartPurposesCommand::class, 'definitions'));
        $this->assertTrue(method_exists(BackfillTolerancePurposesCommand::class, 'definitions'));
        $this->assertTrue(method_exists(ChartOfAccountsService::class, 'getSeederForCountry'));

        $this->assertFileExists(
            database_path('migrations/tenant/2026_08_07_100000_backfill_purchase_stamp_duty_account.php'),
            'The second purchase_stamp_duty writer is gone; the dual-writer pin below has nothing to compare against.',
        );
    }
}
