<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use Tests\Architecture\Support\DocumentPerActionWriteScanner;
use Tests\TestCase;

/**
 * The document-per-action baseline RATCHET — three directions, all fail-closed.
 *
 *  (a) GROWTH   — a violation in the tree that is not in the baseline fails.
 *  (b) STALE    — a baseline entry with no matching violation fails ("remove it").
 *  (c) ANTI-GROWTH — the working baseline's key set is compared against the blob
 *      named by the OWNER-SET GitHub Actions repository variable
 *      `DPA_BASELINE_PROTECTED_BLOB`; any key present in the working baseline but
 *      absent from that blob fails. Without (c), (a)+(b) are defeatable: land a
 *      new violation and its freshly regenerated baseline entry in one change and
 *      both pass.
 *
 * The AUTHORITY for (c) is the repository variable, never this repository's
 * tracked files: the variable lives outside every candidate diff, which is the
 * whole point — a contributor who can edit the baseline cannot also edit the
 * ceiling it is measured against. The progress-YAML field
 * `dpa_baseline_protected_blob` is a NON-AUTHORITATIVE MIRROR kept for the paper
 * trail, and a mirror that disagrees with the variable is a TAMPER SIGNAL, not a
 * reason to skip: it fails too.
 *
 * Fails closed on: variable unset/empty (this deliberately forces the owner
 * bootstrap), a malformed hash, an unfetchable blob object, mirror drift, or any
 * added key.
 *
 * ⚠️ RE-PIN TRIGGER — READ BEFORE REMEDIATING A BASELINED VIOLATOR.
 * Baseline keys carry a positional ordinal within (file, class, function, table,
 * mechanism). Direction (c) makes a RENUMBERED key an ADDED key, and an added
 * key can only be authorised by the OWNER (a new repository-variable value plus
 * a new pin tag). So a remediation that inserts an earlier same-bucket write —
 * for example fixing `OpeningBalancePostingService::post` by adding a properly
 * linked `StockMovement::create` ABOVE the unlinked one, which renumbers the
 * surviving violation from `create#1` to `create#2` — turns CI red and NO
 * contributor-side edit can turn it green: removing the stale entry trips
 * direction (a) instead. That is this ratchet hard-blocking the remediation
 * program it exists to protect, on a change that strictly improves the tree.
 * It is an operational consequence, not a defect: the fix is an owner re-pin
 * (variable + freshly allocated tag together), and it is listed as a named
 * re-pin trigger in the handback's operational owes.
 *
 * ⚠️ THE DETECTOR ITSELF IS CANDIDATE-DELETABLE. Nothing asserts that this file
 * exists: deleting it (or it plus the baseline) leaves the Architecture suite
 * green with no ratchet at all. This is the same residual class the brief's §6
 * F-8 discloses for `ci.yml` (the checker's invocation), covered by the same
 * mitigations — the scope allowlist, the milestone gates reviewing every diff,
 * and the owner's pre-promotion dispatch run — and it is disclosed here rather
 * than left implicit.
 *
 * LOCAL runs: derive the reviewed seed blob and export it under the same name —
 *   seed_blob=$(git rev-parse <dpa_baseline_seed_commit>:apps/api/tests/Architecture/baselines/document-per-action-baseline.json)
 *   export DPA_BASELINE_PROTECTED_BLOB="$seed_blob"
 * CI maps ${{ vars.DPA_BASELINE_PROTECTED_BLOB }} into the job environment and
 * fetches the durable pin tag before the blob is resolved.
 */
final class DocumentPerActionBaselineRatchetTest extends TestCase
{
    private const BASELINE_RELATIVE = 'apps/api/tests/Architecture/baselines/document-per-action-baseline.json';

    private const PROTECTED_BLOB_ENV = 'DPA_BASELINE_PROTECTED_BLOB';

    private const MIRROR_YAML_RELATIVE = 'docs/handoff/progress/enforcement-p1.progress.yaml';

    private const MIRROR_FIELD = 'dpa_baseline_protected_blob';

    /**
     * Directions (a) and (b): the tree and the baseline agree exactly.
     */
    #[Test]
    public function repository_violations_match_the_baseline_exactly(): void
    {
        $violations = $this->scanRepositoryViolations();
        $baseline = $this->workingBaselineKeys();

        $seen = [];
        $new = [];
        foreach ($violations as $key => $site) {
            if (in_array($key, $baseline, true)) {
                $seen[$key] = true;

                continue;
            }
            $new[] = sprintf('%s  (%s:%d — %s)', $key, $site['file'], $site['line'], $site['reason']);
        }

        $stale = array_values(array_filter($baseline, static fn (string $key): bool => ! isset($seen[$key])));

        $message = '';
        if ($new !== []) {
            $message .= "\nNEW document-per-action violations (growth — the guard is a ratchet; give the write a justifying document reference instead of baselining it):\n  "
                .implode("\n  ", $new)."\n";
        }
        if ($stale !== []) {
            $message .= "\nSTALE baseline entries (the violation is gone — remove the entry, the baseline only shrinks):\n  "
                .implode("\n  ", $stale)."\n";
        }

        $this->assertSame([], [...$new, ...$stale], $message);
    }

    /**
     * Direction (c): the working baseline may only SHRINK relative to the
     * owner-pinned protected blob.
     */
    #[Test]
    public function the_working_baseline_never_grows_against_the_owner_pinned_blob(): void
    {
        $pinned = getenv(self::PROTECTED_BLOB_ENV);
        $pinned = is_string($pinned) ? trim($pinned) : '';

        $this->assertNotSame(
            '',
            $pinned,
            self::PROTECTED_BLOB_ENV." is unset or empty, so the anti-growth ceiling cannot be read and this gate FAILS CLOSED.\n"
            ."In CI: the owner sets the repository variable and the workflow maps it into the job environment.\n"
            .'Locally: export it from the reviewed seed commit — '
            .'export DPA_BASELINE_PROTECTED_BLOB=$(git rev-parse <dpa_baseline_seed_commit>:'.self::BASELINE_RELATIVE.')',
        );

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{40}$|^[0-9a-f]{64}$/',
            $pinned,
            self::PROTECTED_BLOB_ENV.' is not a git object hash: '.$pinned,
        );

        $mirror = $this->mirrorPin();
        $this->assertSame(
            $pinned,
            $mirror,
            'The progress-YAML mirror ('.self::MIRROR_FIELD.') disagrees with '.self::PROTECTED_BLOB_ENV.".\n"
            ."That is a TAMPER SIGNAL, not a skip condition: the mirror is the paper trail for the value the owner set.\n"
            .'mirror='.var_export($mirror, true).' variable='.$pinned,
        );

        [$exitCode, $blob] = $this->gitCatFile($pinned);
        $this->assertSame(
            0,
            $exitCode,
            'The protected baseline blob '.$pinned." could not be read (git cat-file exit {$exitCode}).\n"
            .'CI must fetch the durable pin tag before this gate runs; an unreachable object fails closed.',
        );

        /** @var mixed $decoded */
        $decoded = json_decode($blob, true);
        $this->assertIsArray($decoded, 'The protected baseline blob is not a JSON array of keys.');

        $protected = [];
        foreach ($decoded as $entry) {
            $this->assertIsString($entry, 'The protected baseline contains a non-string entry.');
            $protected[$entry] = true;
        }

        $added = array_values(array_filter(
            $this->workingBaselineKeys(),
            static fn (string $key): bool => ! isset($protected[$key]),
        ));

        $this->assertSame(
            [],
            $added,
            "\nThe baseline has GROWN relative to the owner-pinned protected blob {$pinned}.\n"
            ."Keys may only be REMOVED. Added:\n  ".implode("\n  ", $added)."\n"
            ."A new violation is fixed by giving the write a justifying document reference — never by extending the baseline.\n",
        );
    }

    /**
     * @return array<string, array{file: string, line: int, reason: string}>
     */
    private function scanRepositoryViolations(): array
    {
        $scanner = new DocumentPerActionWriteScanner;
        $root = base_path();

        $violations = [];
        foreach ($scanner->scan([$root.'/app'], $root.'/') as $site) {
            if ($site['classification'] === 'violation') {
                $violations[$site['key']] = $site;
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private function workingBaselineKeys(): array
    {
        $path = $this->repositoryRoot().'/'.self::BASELINE_RELATIVE;
        $this->assertFileExists($path, 'The document-per-action baseline is missing: '.self::BASELINE_RELATIVE);

        /** @var mixed $decoded */
        $decoded = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($decoded, 'The document-per-action baseline is not a JSON array of keys.');

        $keys = [];
        foreach ($decoded as $entry) {
            $this->assertIsString($entry, 'The document-per-action baseline contains a non-string entry.');
            $keys[] = $entry;
        }

        return $keys;
    }

    private function mirrorPin(): ?string
    {
        $path = $this->repositoryRoot().'/'.self::MIRROR_YAML_RELATIVE;
        if (! is_file($path)) {
            return null;
        }

        $contents = (string) file_get_contents($path);
        if (preg_match('/^'.preg_quote(self::MIRROR_FIELD, '/').':\s*([0-9a-f]{40,64})\s*$/m', $contents, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function gitCatFile(string $hash): array
    {
        $command = sprintf(
            'git -C %s cat-file blob %s 2>/dev/null',
            escapeshellarg($this->repositoryRoot()),
            escapeshellarg($hash),
        );

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (! is_resource($process)) {
            return [1, ''];
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout];
    }

    private function repositoryRoot(): string
    {
        return dirname(base_path(), 2);
    }
}
