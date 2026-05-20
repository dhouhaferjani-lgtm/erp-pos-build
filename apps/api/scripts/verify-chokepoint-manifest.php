<?php

declare(strict_types=1);

/**
 * Closes Codex T30-P1 (P1) — manifest receiver_type validator.
 *
 * Reads `apps/api/scripts/saleReceipt-chokepoint-manifest.json` and, for
 * each non-`unrelated` entry, validates via PHP reflection that the
 * declared `receiver_type` actually matches a constructor-injected
 * property on the declared `calling_class`. A typo / lie in the manifest
 * (e.g. `receiver_type: 'InventoryCountingService'` on a real
 * `ReceiptCreationService` caller) is rejected with a non-zero exit.
 *
 * Invocation:
 *   php apps/api/scripts/verify-chokepoint-manifest.php [--manifest PATH]
 *
 * Exit codes:
 *   0 — every entry's receiver_type matches an injected property on
 *       calling_class.
 *   1 — at least one entry is unverifiable (class missing, no matching
 *       property, etc.). Each offence is printed to stderr.
 *   2 — manifest missing / unreadable / malformed.
 *
 * Wired into the shell gate (`check-saleReceipt-chokepoints.sh`) and the
 * PHPUnit mirror
 * (`tests/Feature/Fiscal/ChokepointCompletenessTest::test_manifest_receiver_type_matches_calling_class_constructor`).
 */
set_error_handler(static function (int $errno, string $errstr, string $errfile, int $errline): bool {
    fwrite(STDERR, sprintf("PHP warning: %s in %s:%d\n", $errstr, $errfile, $errline));

    return true;
});

$scriptDir = __DIR__;
$apiDir = dirname($scriptDir);
$repoRoot = dirname($apiDir, 2);

$manifestPath = $scriptDir.'/saleReceipt-chokepoint-manifest.json';

$args = array_slice($argv, 1);
for ($i = 0; $i < count($args); $i++) {
    if ($args[$i] === '--manifest' && isset($args[$i + 1])) {
        $manifestPath = (string) $args[$i + 1];
        $i++;

        continue;
    }
    if ($args[$i] === '-h' || $args[$i] === '--help') {
        echo "Usage: php verify-chokepoint-manifest.php [--manifest PATH]\n";
        exit(0);
    }
    fwrite(STDERR, sprintf("Unknown arg: %s\n", $args[$i]));
    exit(2);
}

if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
    fwrite(STDERR, sprintf("Manifest not found or unreadable: %s\n", $manifestPath));
    exit(2);
}

$raw = (string) file_get_contents($manifestPath);
try {
    $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fwrite(STDERR, sprintf("Manifest JSON malformed: %s\n", $e->getMessage()));
    exit(2);
}

if (! is_array($decoded) || ! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
    fwrite(STDERR, "Manifest missing top-level 'entries' array.\n");
    exit(2);
}

// Load Laravel's autoloader so reflection can resolve App\Modules\... classes.
$autoload = $apiDir.'/vendor/autoload.php';
if (! is_file($autoload)) {
    fwrite(STDERR, sprintf("Cannot find composer autoload at: %s\n", $autoload));
    exit(2);
}
require $autoload;

$failed = 0;
$totalChecked = 0;

foreach ($decoded['entries'] as $entry) {
    if (! is_array($entry)) {
        fwrite(STDERR, "Manifest entry is not an object.\n");
        $failed = 1;

        continue;
    }

    $chokepoint = is_string($entry['chokepoint'] ?? null) ? $entry['chokepoint'] : '';
    if ($chokepoint === 'unrelated') {
        continue;
    }

    $callingClass = is_string($entry['calling_class'] ?? null) ? $entry['calling_class'] : '';
    $receiverType = is_string($entry['receiver_type'] ?? null) ? $entry['receiver_type'] : '';
    $file = is_string($entry['file'] ?? null) ? $entry['file'] : '?';
    $anchor = is_string($entry['line_anchor'] ?? null) ? $entry['line_anchor'] : '?';

    if ($callingClass === '' || $receiverType === '') {
        fwrite(STDERR, sprintf(
            "MANIFEST_LIE: entry missing calling_class or receiver_type (file=%s anchor=%s)\n",
            $file,
            $anchor,
        ));
        $failed = 1;

        continue;
    }

    $totalChecked++;

    // Reflect the calling_class. If it doesn't exist, the manifest is
    // stale or the file/class moved — fail loud.
    if (! class_exists($callingClass) && ! interface_exists($callingClass)) {
        fwrite(STDERR, sprintf(
            "MANIFEST_LIE: calling_class %s does not exist (file=%s anchor=%s)\n",
            $callingClass,
            $file,
            $anchor,
        ));
        $failed = 1;

        continue;
    }
    /** @var class-string $callingClass */
    $refl = new ReflectionClass($callingClass);

    $matched = false;

    // Strategy: walk the constructor parameters and look for one whose
    // declared type IS-A the manifest's receiver_type (covers both exact
    // class and superclass / interface). Controllers and services in
    // this codebase inject all dependencies via the constructor with
    // `private readonly`, per CLAUDE.md Rule 13.
    $ctor = $refl->getConstructor();
    if ($ctor !== null) {
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }
            $paramTypeName = $type->getName();
            if ($paramTypeName === $receiverType) {
                $matched = true;
                break;
            }
            if (class_exists($paramTypeName) || interface_exists($paramTypeName)) {
                if (is_subclass_of($paramTypeName, $receiverType) || $paramTypeName === $receiverType) {
                    $matched = true;
                    break;
                }
            }
        }
    }

    // Defensive fallback: if not on the constructor, walk typed properties
    // (covers any future setter-injection callsites — unused today but
    // cheap to support so this gate doesn't grow brittle).
    if (! $matched) {
        foreach ($refl->getProperties() as $prop) {
            $type = $prop->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }
            $propTypeName = $type->getName();
            if ($propTypeName === $receiverType) {
                $matched = true;
                break;
            }
        }
    }

    if (! $matched) {
        fwrite(STDERR, sprintf(
            "MANIFEST_LIE: %s does not have an injected/typed property of type %s (file=%s anchor=%s)\n",
            $callingClass,
            $receiverType,
            $file,
            $anchor,
        ));
        $failed = 1;
    }
}

if ($failed === 0) {
    fwrite(STDOUT, sprintf(
        "manifest receiver_type validator: PASS — %d entry/entries validated\n",
        $totalChecked,
    ));
}

exit($failed);
