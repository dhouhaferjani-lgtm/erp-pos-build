<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The O-29 first-execution quarantine (LEDGER O-29, 2026-08-21).
 *
 * The eight Feature lanes run ~1 130 classes that no CI lane has ever executed.
 * Their first green is not going to be free, and a red wall that blocks every
 * merge is not a gate — it is a gate everyone turns off. So the lanes triage the
 * way the rest of this repo triages inherited red: an ENUMERATED, SHRINK-ONLY
 * ratchet, with a ticket per entry, rather than a blanket "known failures allowed".
 *
 * Three properties make this a ratchet and not a waiver:
 *
 *   1. OFF BY DEFAULT. Nothing is skipped unless AUTOERP_QUARANTINE=1 — set on the
 *      lane jobs and by scripts/run-feature-lane-local.sh, nowhere else. Every
 *      other suite (backend-test, the PG jobs, a developer's local phpunit) reads
 *      this file never and behaves exactly as before.
 *   2. EXACT TARGETS ONLY. An entry is a fully-qualified class, or a class plus one
 *      method. No globs, no directories, no regex — a quarantine cannot widen by
 *      accident, and a NEW failure in a quarantined class's other methods still
 *      fails the lane.
 *   3. SHRINK-ONLY AND NON-FICTIONAL. tools/feature-lane-manifest-check.php pins the
 *      entry count to a ceiling, requires a declared lane + reason + open date on
 *      each, and hard-fails on an entry whose class file no longer exists.
 *
 * The lane step itself stays exactly `./vendor/bin/phpunit tests/Feature/<Group>/`
 * with no flags — deliberately. The manifest checker rejects a whole-directory lane
 * whose run line carries anything it cannot prove neutral (`--filter`,
 * `--exclude-group`, `-c`, a narrower path), because each of those can silently
 * empty the lane while the manifest still certifies the directory. Quarantining
 * therefore has to happen INSIDE the run, where it is visible in the output as a
 * skip and countable in this file, instead of as an invisible argument in YAML.
 */
final class QuarantinedTests
{
    /** @var array<string,array{lane:string,reason:string,opened:string}>|null */
    private static ?array $entries = null;

    /**
     * The reason this target is quarantined, or null if it is not.
     *
     * @param  string  $class  fully-qualified test class name
     * @param  string  $method  test method name
     */
    public static function reasonFor(string $class, string $method): ?string
    {
        if (getenv('AUTOERP_QUARANTINE') !== '1') {
            return null;
        }

        $entries = self::entries();
        foreach ([$class.'::'.$method, $class] as $key) {
            if (isset($entries[$key])) {
                return sprintf(
                    'QUARANTINED (%s, opened %s): %s',
                    $entries[$key]['lane'],
                    $entries[$key]['opened'],
                    $entries[$key]['reason'],
                );
            }
        }

        return null;
    }

    /** @return array<string,array{lane:string,reason:string,opened:string}> */
    private static function entries(): array
    {
        if (self::$entries !== null) {
            return self::$entries;
        }

        $path = dirname(__DIR__).'/quarantine.json';
        if (! is_file($path)) {
            // A missing list means NOTHING is skipped. Failing open here would be
            // the one direction that could hide a real regression.
            return self::$entries = [];
        }

        /** @var array{entries?: array<string,array{lane:string,reason:string,opened:string}>} $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return self::$entries = $decoded['entries'] ?? [];
    }
}
