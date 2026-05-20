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

    public function test_after_phase1_only_void_return_carveout_chokepoint_callers_remain_live(): void
    {
        // Phase 1 acceptable live entries:
        //   - disposition (b): route-disposed / deferred-retirement (Task 28,
        //     Task 29). Live: true means the service body is still production
        //     code but no live HTTP route can reach it.
        //   - disposition (c): knowingly-retained carve-outs (e.g.
        //     ReceiptReturnService — Phase 2+ SALE_VOID/REFUND_RECEIPT
        //     event types).
        // Any other (live: true) non-unrelated entry is an unaccounted-for
        // chokepoint caller and must fail the gate.
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
