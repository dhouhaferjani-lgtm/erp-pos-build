<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Task 30 — §14.3 chokepoint completeness gate (defense-in-depth).
 *
 * Spec v7 (`docs/superpowers/specs/2026-05-14-pos-phase1-foundation-spec-v7.md`)
 * §14.3 requires that every `->createReceipt(` and `->finalize(` call site
 * across `apps/api/app` + `apps/api/routes` be reconciled against the
 * checked-in disposition manifest at
 * `apps/api/scripts/saleReceipt-chokepoint-manifest.json`.
 *
 * The shell gate (`apps/api/scripts/check-saleReceipt-chokepoints.sh`) is
 * the primary enforcement point and is wired into `scripts/preflight.sh` +
 * the CI workflow. This PHPUnit suite mirrors the gate so a regression
 * that adds a chokepoint caller is rejected by `php artisan test` even
 * when the shell gate is bypassed — Task 20 discriminated-union
 * completeness pattern (every disposition × every live/!live combo
 * pinned, not just the happy path).
 *
 * Three contracts pinned:
 *   1. Every grep hit has a matching manifest entry (file + line_anchor
 *      substring of the source line).
 *   2. Every non-`unrelated` entry has a disposition in {a, b, c}.
 *   3. Every `live: true` non-`unrelated` entry is dispositioned (b) or
 *      (c) — Phase 1 has no admissible live SALE_RECEIPT chokepoint
 *      caller without an explicit §14.2/§14.3 disposition.
 *
 * NOTE: lives at apps/api/tests level. The shell gate also runs from the
 * repo root in CI; the test runs from `apps/api/` so paths are normalized
 * relative to the repo root via `base_path()`.
 */
#[Group('chokepoint-gate')]
final class ChokepointCompletenessTest extends TestCase
{
    private const MANIFEST_RELPATH = 'scripts/saleReceipt-chokepoint-manifest.json';

    public function test_every_create_receipt_callsite_is_reconciled_in_the_manifest(): void
    {
        $sites = $this->callSites('->createReceipt(');
        $this->assertNotEmpty(
            $sites,
            'Expected at least one ->createReceipt( call site; grep returned nothing — check working directory.',
        );

        foreach ($sites as $site) {
            $entry = $this->manifestEntryFor($site);
            $this->assertNotNull(
                $entry,
                sprintf('Unreconciled createReceipt call site: %s', $this->renderSite($site)),
            );
            if ($entry['chokepoint'] !== 'unrelated') {
                $this->assertContains(
                    $entry['disposition'] ?? null,
                    ['a', 'b', 'c'],
                    sprintf('Chokepoint caller %s has no disposition', $this->renderSite($site)),
                );
            }
        }
    }

    public function test_every_finalize_callsite_is_reconciled_with_receiver_type(): void
    {
        $sites = $this->callSites('->finalize(');
        $this->assertNotEmpty(
            $sites,
            'Expected at least one ->finalize( call site; grep returned nothing — check working directory.',
        );

        foreach ($sites as $site) {
            $entry = $this->manifestEntryFor($site);
            $this->assertNotNull(
                $entry,
                sprintf('Unreconciled finalize call site: %s', $this->renderSite($site)),
            );
            $this->assertArrayHasKey(
                'receiver_type',
                $entry,
                sprintf('Manifest entry for %s is missing receiver_type', $this->renderSite($site)),
            );
        }
    }

    public function test_after_phase1_only_void_return_carveout_or_retiring_callers_remain_live(): void
    {
        // Round-2 Opus P2-1 closure: rename + caveat. After Phase 1
        // settles, the spec wants ONLY (c) carve-outs (e.g.
        // ReceiptReturnService). Today the manifest still contains (b)
        // entries that are inert because their HTTP route is 410 Gone
        // — the body survives until the retirement task lands. The
        // companion test_disposition_b_entries_have_retirement_task
        // pins that every such (b) entry carries a retired_in_task
        // marker so this transitional state cannot drift into a
        // permanent allowlist.
        foreach ($this->manifestEntries() as $entry) {
            $isUnrelated = ($entry['chokepoint'] ?? null) === 'unrelated';
            $isLive = ($entry['live'] ?? false) === true;

            if ($isUnrelated || ! $isLive) {
                continue;
            }

            $this->assertContains(
                $entry['disposition'] ?? null,
                ['b', 'c'],
                sprintf(
                    'Non-carve-out chokepoint caller still live without (b)/(c) disposition: %s:%s',
                    $entry['file'] ?? '?',
                    $entry['line_anchor'] ?? '?',
                ),
            );
        }
    }

    public function test_disposition_b_entries_have_retirement_task(): void
    {
        // Round-2 Opus P2-1 closure: every (b) entry MUST carry a
        // retired_in_task field so the transitional acceptance of
        // route-disposed-but-still-live callers cannot drift into a
        // permanent free pass. (c) entries are knowingly-retained
        // carve-outs and need no retirement task; (a) entries are
        // already removed; `unrelated` entries are excluded from the
        // §14 disposition codes by definition.
        foreach ($this->manifestEntries() as $entry) {
            if (($entry['disposition'] ?? null) !== 'b') {
                continue;
            }

            $retiredIn = $entry['retired_in_task'] ?? null;
            $this->assertIsString(
                $retiredIn,
                sprintf(
                    'Disposition (b) entry missing retired_in_task marker: %s:%s',
                    $entry['file'] ?? '?',
                    $entry['line_anchor'] ?? '?',
                ),
            );
            $this->assertNotSame(
                '',
                $retiredIn,
                sprintf(
                    'Disposition (b) entry has empty retired_in_task: %s:%s',
                    $entry['file'] ?? '?',
                    $entry['line_anchor'] ?? '?',
                ),
            );
        }
    }

    public function test_manifest_receiver_type_matches_calling_class_constructor(): void
    {
        // Round-3 (T30-R2-P1 / T30-R2-P2) closure: this test now
        // shells out to verify-chokepoint-manifest.php — the canonical
        // strict validator with receiver-expression parsing. Running
        // the CLI helper via Symfony Process is the same path the
        // shell gate uses (when SKIP_RECEIVER_TYPE_VALIDATOR is
        // unset), so the two layers share one source of truth.
        //
        // This is the PHPUnit-layer mirror that runs in
        // backend-test-pgsql — the CI workflow's PHP+composer-equipped
        // job. The lightweight chokepoint-gate job skips the validator
        // and relies on THIS test to catch manifest lies in the merge
        // gate (round-3 T30-R2-P1).
        $repoRoot = $this->repoRoot();
        $helper = $repoRoot.'/apps/api/scripts/verify-chokepoint-manifest.php';
        $this->assertFileExists($helper, 'Receiver-type validator helper is missing.');

        $process = new Process(['php', $helper], $repoRoot);
        $process->run();

        $this->assertSame(
            0,
            $process->getExitCode(),
            sprintf(
                "Receiver-type validator failed against the live manifest. stdout:\n%s\nstderr:\n%s",
                $process->getOutput(),
                $process->getErrorOutput(),
            ),
        );
        $this->assertStringContainsString(
            'manifest receiver_type validator: PASS',
            $process->getOutput(),
            'Validator must print PASS banner on success.',
        );
    }

    public function test_negative_manifest_with_wrong_receiver_for_multi_dependency_class_is_rejected(): void
    {
        // Round-3 (T30-R2-P2) closure: the receiver_type validator
        // must catch a MIS-LABELED receiver on a multi-dependency
        // class. ExchangeService injects BOTH ReceiptCreationService
        // AND ReceiptFinalizationService. A manifest entry that
        // claims `receiver_type: ReceiptCreationService` for a line
        // that is actually `$this->finalizationService->finalize(`
        // MUST FAIL — the strengthened validator parses the receiver
        // expression and looks up THAT specific property's type.
        // Without this check, the round-2 validator passes because
        // ReceiptCreationService IS a constructor parameter on
        // ExchangeService — just not the one being called.
        $repoRoot = $this->repoRoot();
        $helper = $repoRoot.'/apps/api/scripts/verify-chokepoint-manifest.php';
        $this->assertFileExists($helper);

        $tmpManifest = tempnam(sys_get_temp_dir(), 'manifest-multi-dep-');
        $this->assertIsString($tmpManifest);

        $bad = [
            'schema_version' => '1.1',
            'spec_anchor' => 'unit-test fabricated',
            'notes' => 'negative test — multi-dependency mis-label',
            'entries' => [
                [
                    'file' => 'apps/api/app/Modules/POS/Application/Services/ExchangeService.php',
                    // The real line text. The validator must parse
                    // `$this->finalizationService` from this anchor.
                    'line_anchor' => '$saleDraft = $this->finalizationService->finalize($saleDraft);',
                    'calling_class' => 'App\\Modules\\POS\\Application\\Services\\ExchangeService',
                    'calling_method' => 'processExchange',
                    // LIE: receiver_type claims ReceiptCreationService
                    // but the actual receiver is finalizationService
                    // (typed as ReceiptFinalizationService).
                    'receiver_type' => 'App\\Modules\\POS\\Application\\Services\\ReceiptCreationService',
                    'chokepoint' => 'finalize',
                    'disposition' => 'b',
                    'live' => false,
                    'retired_in_task' => 'fake',
                    'note' => 'fabricated multi-dep mis-label',
                ],
            ],
        ];
        file_put_contents($tmpManifest, json_encode($bad, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        try {
            $process = new Process(['php', $helper, '--manifest', $tmpManifest], $repoRoot);
            $process->run();
            $exit = $process->getExitCode();
            $this->assertSame(
                1,
                $exit,
                sprintf(
                    'Strict validator MUST reject a mis-labeled receiver_type on a multi-dependency class. '
                    .'Exit=%s; stderr: %s',
                    var_export($exit, true),
                    $process->getErrorOutput(),
                ),
            );
            $this->assertStringContainsString(
                'MANIFEST_LIE',
                $process->getErrorOutput(),
                'Validator must emit MANIFEST_LIE diagnostic for a mis-labeled multi-dep receiver.',
            );
            // The new strict path identifies the actual property in
            // the diagnostic — `finalizationService` declared as
            // ReceiptFinalizationService.
            $this->assertStringContainsString(
                'finalizationService',
                $process->getErrorOutput(),
                'Diagnostic must name the actual receiver property.',
            );
        } finally {
            @unlink($tmpManifest);
        }
    }

    public function test_negative_manifest_with_wrong_receiver_type_is_rejected_by_validator(): void
    {
        // Round-2 Codex T30-P1 closure (negative path): the bin/
        // verify-chokepoint-manifest.php script MUST exit non-zero when
        // an entry's receiver_type doesn't match any constructor
        // parameter on the calling_class. Fabricate a temporary
        // manifest in a tmp file and invoke the script via a Symfony
        // Process so the gate's exit code is the contract.
        $repoRoot = $this->repoRoot();
        $helper = $repoRoot.'/apps/api/scripts/verify-chokepoint-manifest.php';
        $this->assertFileExists($helper, 'Receiver-type validator helper is missing.');

        $tmpManifest = tempnam(sys_get_temp_dir(), 'manifest-lie-');
        $this->assertIsString($tmpManifest);

        $bad = [
            'schema_version' => '1.1',
            'spec_anchor' => 'unit-test fabricated',
            'notes' => 'negative test',
            'entries' => [
                [
                    'file' => 'apps/api/app/Modules/POS/Application/Services/OrderToReceiptService.php',
                    'line_anchor' => 'return $this->receiptCreationService->createReceipt(',
                    'calling_class' => 'App\\Modules\\POS\\Application\\Services\\OrderToReceiptService',
                    'calling_method' => 'convertToReceipt',
                    'receiver_type' => 'App\\Modules\\Inventory\\Application\\Services\\InventoryCountingService',
                    'chokepoint' => 'createReceipt',
                    'disposition' => 'b',
                    'live' => true,
                    'retired_in_task' => 'fake',
                    'note' => 'fabricated lie',
                ],
            ],
        ];
        file_put_contents($tmpManifest, json_encode($bad, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        try {
            $process = new Process(['php', $helper, '--manifest', $tmpManifest], $repoRoot);
            $process->run();
            $exit = $process->getExitCode();
            $this->assertSame(
                1,
                $exit,
                sprintf(
                    'Expected validator to exit 1 (MANIFEST_LIE) but got %s (stdout: %s; stderr: %s)',
                    var_export($exit, true),
                    $process->getOutput(),
                    $process->getErrorOutput(),
                ),
            );
            $this->assertStringContainsString(
                'MANIFEST_LIE',
                $process->getErrorOutput(),
                'Validator must print a MANIFEST_LIE diagnostic on receiver_type mismatch.',
            );
        } finally {
            @unlink($tmpManifest);
        }
    }

    /**
     * Run an rg sweep across `apps/api/app` + `apps/api/routes` for the
     * literal `$needle`. Returns one record per hit with file + lineno +
     * stripped source text.
     *
     * @return list<array{file: string, line: int, text: string}>
     */
    private function callSites(string $needle): array
    {
        $repoRoot = $this->repoRoot();

        // rg returns exit 1 when there are no matches. We tolerate 0/1 and
        // surface any other exit as a test infra failure.
        // The needle starts with `->` which rg treats as a flag if it
        // appears before `--`. Use `--` to separate the flag list from
        // positional args (per the rg(1) manual).
        $process = new Process([
            'rg',
            '-n',
            '--no-heading',
            '--fixed-strings',
            '--',
            $needle,
            'apps/api/app',
            'apps/api/routes',
        ], $repoRoot);
        $process->run();

        $exit = $process->getExitCode();
        if ($exit !== 0 && $exit !== 1) {
            $this->fail(sprintf(
                'rg failed with exit %d searching for %s (stderr: %s)',
                $exit,
                $needle,
                $process->getErrorOutput(),
            ));
        }

        $hits = [];
        foreach (preg_split('/\r?\n/', trim($process->getOutput())) ?: [] as $line) {
            if ($line === '') {
                continue;
            }
            // rg format: <file>:<lineno>:<text>
            $parts = explode(':', $line, 3);
            if (count($parts) !== 3) {
                continue;
            }
            $hits[] = [
                'file' => $parts[0],
                'line' => (int) $parts[1],
                'text' => ltrim($parts[2]),
            ];
        }

        return $hits;
    }

    /**
     * Read the manifest from disk fresh per call — the file is small and we
     * want the test to reflect on-disk truth, not a cached snapshot.
     *
     * @return list<array<string, mixed>>
     */
    private function manifestEntries(): array
    {
        $path = $this->repoRoot().'/apps/api/'.self::MANIFEST_RELPATH;
        $this->assertFileExists(
            $path,
            'saleReceipt-chokepoint-manifest.json is missing. The §14.3 gate requires it; create it before running this test.',
        );

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw, 'Failed to read '.$path);

        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'Manifest must be a JSON object.');
        $this->assertArrayHasKey('entries', $decoded, 'Manifest is missing top-level entries array.');
        $this->assertIsArray($decoded['entries'], 'Manifest entries must be an array.');

        /** @var list<array<string, mixed>> $entries */
        $entries = array_values($decoded['entries']);

        return $entries;
    }

    /**
     * Find a manifest entry whose `file` matches the call-site file and
     * whose `line_anchor` appears as a substring of the call-site source
     * text. Returns null if no entry matches.
     *
     * @param  array{file: string, line: int, text: string}  $site
     * @return array<string, mixed>|null
     */
    private function manifestEntryFor(array $site): ?array
    {
        foreach ($this->manifestEntries() as $entry) {
            $entryFile = is_string($entry['file'] ?? null) ? $entry['file'] : '';
            $entryAnchor = is_string($entry['line_anchor'] ?? null) ? $entry['line_anchor'] : '';

            if ($entryFile !== $site['file']) {
                continue;
            }
            if ($entryAnchor === '') {
                continue;
            }
            if (str_contains($site['text'], $entryAnchor)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array{file: string, line: int, text: string}  $site
     */
    private function renderSite(array $site): string
    {
        return sprintf('%s:%d — %s', $site['file'], $site['line'], $site['text']);
    }

    private function repoRoot(): string
    {
        // base_path() resolves to apps/api/. Walk up two levels to reach the
        // repo root where the grep prefixes (`apps/api/app`) are valid.
        $apiPath = base_path();
        $repoRoot = dirname($apiPath, 2);
        $this->assertDirectoryExists(
            $repoRoot.'/apps/api/app',
            'Could not resolve repo root from base_path(); expected apps/api/app under '.$repoRoot,
        );

        return $repoRoot;
    }
}
