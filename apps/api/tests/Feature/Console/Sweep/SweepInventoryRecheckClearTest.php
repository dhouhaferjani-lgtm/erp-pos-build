<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Sweep;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Feature test for `php artisan sweep:inventory:recheck-clear`.
 *
 * The command closes the narrow `needs_recheck` path created by inventory
 * regeneration when the row's relevant scanner no longer emits its stable key.
 */
class SweepInventoryRecheckClearTest extends TestCase
{
    use SweepInventoryTestSeed;

    private const FIX_COMMIT = 'abcdef0123456789abcdef0123456789abcdef01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootSweepSeed();
    }

    protected function tearDown(): void
    {
        $this->destroySweepSeed();
        parent::tearDown();
    }

    private function writeReviewFile(string $verdict = 'APPROVE', string $stem = 'recheck-review'): string
    {
        $path = $this->tempDir.'/'.$stem.'.md';
        file_put_contents($path, "# Recheck review\n\nVerdict: {$verdict}\n");

        return $path;
    }

    private function writeManualStub(bool $emitStableKey): string
    {
        $path = $this->tempDir.'/manual-callsites.yml';
        $body = "manual_callsites:\n";
        if ($emitStableKey) {
            $body .= <<<'YAML'
  - cluster_id: api.document
    slug: recheck-target
    file: apps/api/app/Modules/Document/Domain/Services/RecheckTarget.php
    symbol: App\Modules\Document\Domain\Services\RecheckTarget::handle
    pattern_type: manual_recheck_fixture
    resource: documents
    expected_scope: tenant_and_company
    severity: high
    expected_fix: Fixture row must disappear before recheck-clear can close it.
YAML;
        }
        file_put_contents($path, $body);

        return $path;
    }

    private function seedRecheckCallsite(string $status = 'needs_recheck', ?string $owner = 'claude'): void
    {
        $this->reseedInventory(function (array $doc) use ($status, $owner): array {
            /** @var list<array<string, mixed>> $callsites */
            $callsites = $doc['callsites'];
            foreach ($callsites as $idx => $cs) {
                if (($cs['id'] ?? null) !== 'api.document.001') {
                    continue;
                }

                $cs['stable_key'] = 'manual:api.document:recheck-target';
                $cs['scanner'] = 'manual';
                $cs['file'] = 'apps/api/app/Modules/Document/Domain/Services/RecheckTarget.php';
                $cs['symbol'] = 'App\\Modules\\Document\\Domain\\Services\\RecheckTarget::handle';
                $cs['pattern_type'] = 'manual_recheck_fixture';
                $cs['resource'] = 'documents';
                $cs['status'] = $status;
                $cs['owner'] = $owner;
                $cs['claimed_at'] = '2026-05-12T00:00:00Z';
                $cs['fix_commit'] = self::FIX_COMMIT;
                $cs['regression_test'] = 'tests/Feature/Document/RecheckClearTest.php::test_fixture';
                $callsites[$idx] = $cs;
            }
            $doc['callsites'] = $callsites;

            /** @var list<array<string, mixed>> $clusters */
            $clusters = $doc['clusters'];
            foreach ($clusters as $idx => $cluster) {
                if (($cluster['id'] ?? null) === 'api.document') {
                    $cluster['status'] = 'in_progress';
                    $cluster['owner'] = $owner;
                    $clusters[$idx] = $cluster;
                }
            }
            $doc['clusters'] = $clusters;

            return $doc;
        });
    }

    public function test_recheck_clear_transitions_needs_recheck_to_fixed_when_scanner_is_silent(): void
    {
        $this->seedRecheckCallsite();
        $reviewFile = $this->writeReviewFile('**CONDITIONAL APPROVE** (pending scanner recheck)', 'conditional-review');
        $manualStub = $this->writeManualStub(emitStableKey: false);

        $exit = Artisan::call('sweep:inventory:recheck-clear', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.document.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
            '--reason' => 'Scanner no longer emits this stable key after the conservative visitor enhancement.',
            '--manual-stub' => $manualStub,
        ]);

        $this->assertSame(0, $exit, 'recheck-clear must succeed once the relevant scanner is silent.');

        $callsite = $this->callsiteById('api.document.001');
        $this->assertSame('fixed', $callsite['status']);
        $this->assertSame('codex', $callsite['review']['reviewer']);
        $this->assertSame('APPROVE', $callsite['review']['verdict']);
        $this->assertNotNull($callsite['review']['reviewed_at']);
        $this->assertSame($reviewFile, $callsite['review']['review_file']);
        $this->assertSame(self::FIX_COMMIT, $callsite['review']['review_commit']);

        $history = $callsite['history'];
        $latest = $history[count($history) - 1];
        $this->assertSame('review', $latest['action']);
        $this->assertSame('sweep:inventory:recheck-clear', $latest['command']);
        $this->assertSame('codex', $latest['actor']);
        $this->assertSame('needs_recheck', $latest['from_status']);
        $this->assertSame('fixed', $latest['to_status']);
        $this->assertSame($reviewFile, $latest['review_file']);
        $this->assertSame(self::FIX_COMMIT, $latest['review_commit']);
        $this->assertSame(
            'Scanner no longer emits this stable key after the conservative visitor enhancement.',
            $latest['note'],
        );
    }

    public function test_recheck_clear_rejects_rows_not_currently_in_needs_recheck(): void
    {
        $this->seedRecheckCallsite(status: 'pending');
        $reviewFile = $this->writeReviewFile();
        $manualStub = $this->writeManualStub(emitStableKey: false);

        $exit = Artisan::call('sweep:inventory:recheck-clear', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.document.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
            '--reason' => 'Scanner no longer emits this stable key.',
            '--manual-stub' => $manualStub,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString("has status 'pending'; only needs_recheck callsites can be cleared", Artisan::output());
        $this->assertSame('pending', $this->callsiteById('api.document.001')['status']);
    }

    public function test_recheck_clear_rejects_when_relevant_scanner_still_emits_stable_key(): void
    {
        $this->seedRecheckCallsite();
        $reviewFile = $this->writeReviewFile();
        $manualStub = $this->writeManualStub(emitStableKey: true);

        $exit = Artisan::call('sweep:inventory:recheck-clear', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.document.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
            '--reason' => 'Scanner no longer emits this stable key.',
            '--manual-stub' => $manualStub,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Relevant scanner manual still emits stable_key manual:api.document:recheck-target', Artisan::output());
        $this->assertSame('needs_recheck', $this->callsiteById('api.document.001')['status']);
    }

    public function test_recheck_clear_rejects_missing_review_file_after_scanner_is_silent(): void
    {
        $this->seedRecheckCallsite();
        $manualStub = $this->writeManualStub(emitStableKey: false);

        $exit = Artisan::call('sweep:inventory:recheck-clear', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.document.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $this->tempDir.'/missing-review.md',
            '--review-commit' => self::FIX_COMMIT,
            '--reason' => 'Scanner no longer emits this stable key.',
            '--manual-stub' => $manualStub,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('Review file does not exist on disk', Artisan::output());
        $this->assertSame('needs_recheck', $this->callsiteById('api.document.001')['status']);
    }

    public function test_recheck_clear_rejects_when_actor_matches_owner(): void
    {
        $this->seedRecheckCallsite(owner: 'codex');
        $reviewFile = $this->writeReviewFile();
        $manualStub = $this->writeManualStub(emitStableKey: false);

        $exit = Artisan::call('sweep:inventory:recheck-clear', [
            '--inventory-path' => $this->inventoryPath,
            '--schema-path' => $this->schemaPath,
            '--callsite-id' => 'api.document.001',
            '--actor' => 'codex',
            '--verdict' => 'APPROVE',
            '--review-file' => $reviewFile,
            '--review-commit' => self::FIX_COMMIT,
            '--reason' => 'Scanner no longer emits this stable key.',
            '--manual-stub' => $manualStub,
        ]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString("Review refused: --actor 'codex' equals callsite owner 'codex'", Artisan::output());
        $this->assertSame('needs_recheck', $this->callsiteById('api.document.001')['status']);
    }
}
