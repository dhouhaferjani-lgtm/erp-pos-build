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

    // ----------------------------------------------------------------
    // (Round-3 T30-R2-P2) Parse the receiver expression from the
    // line_anchor text and pin the validation to the SPECIFIC
    // injected property whose type must match receiver_type.
    //
    // Old strategy (round-2): "does any constructor parameter have a
    // type matching receiver_type?" — too lax. A multi-dependency
    // class like ExchangeService (injects both ReceiptCreationService
    // AND ReceiptFinalizationService) would pass any manifest entry
    // claiming either type, regardless of the actual receiver on the
    // call line.
    //
    // New strategy: extract the receiver variable name from the
    // anchor (e.g. `$this->finalizationService` from
    // `$this->finalizationService->finalize(`), reflect THAT property
    // on the calling_class, and assert ITS declared type matches
    // receiver_type. Falls back to the round-2 "any-constructor-
    // parameter / any-property" check (with a WARNING) when the
    // receiver expression isn't a simple `$this->propertyName` —
    // method calls, locals, etc. ----------------------------------
    $receiverVar = extractReceiverPropertyName($anchor);

    $matched = false;
    $warning = null;

    if ($receiverVar !== null) {
        // Strict path: receiver is `$this->propertyName` — look up
        // that property's type and pin it.
        if (! $refl->hasProperty($receiverVar)) {
            fwrite(STDERR, sprintf(
                "MANIFEST_LIE: %s has no property \$%s (parsed from line_anchor %s) (file=%s)\n",
                $callingClass,
                $receiverVar,
                $anchor,
                $file,
            ));
            $failed = 1;

            continue;
        }
        $prop = $refl->getProperty($receiverVar);
        $propType = $prop->getType();
        if (! $propType instanceof ReflectionNamedType) {
            fwrite(STDERR, sprintf(
                "MANIFEST_LIE: %s::\$%s has no scalar/named type (got union/intersection/untyped) — cannot validate receiver_type (file=%s anchor=%s)\n",
                $callingClass,
                $receiverVar,
                $file,
                $anchor,
            ));
            $failed = 1;

            continue;
        }
        $propTypeName = $propType->getName();
        if (
            $propTypeName === $receiverType
            || ((class_exists($propTypeName) || interface_exists($propTypeName))
                && is_subclass_of($propTypeName, $receiverType))
        ) {
            $matched = true;
        } else {
            fwrite(STDERR, sprintf(
                "MANIFEST_LIE: %s::\$%s is declared as %s but the manifest claims receiver_type=%s (file=%s anchor=%s)\n",
                $callingClass,
                $receiverVar,
                $propTypeName,
                $receiverType,
                $file,
                $anchor,
            ));
            $failed = 1;

            continue;
        }
    } else {
        // Defensive fallback: receiver isn't `$this->propertyName`.
        // Walk the constructor + typed properties (round-2 strategy)
        // and emit a WARNING flagging the entry for human review —
        // the gate still PASSES if a matching dependency exists, but
        // a manifest entry living on this fallback path may have
        // hidden mismatches the strict path would catch.
        $warning = sprintf(
            'WARNING: receiver expression for %s (line_anchor=%s) is not a simple $this->propertyName — manifest entry needs human review',
            $callingClass,
            $anchor,
        );

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
    }

    if ($warning !== null) {
        fwrite(STDERR, $warning."\n");
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

/**
 * Extract the receiver property name from a `$this->propertyName->method(`
 * anchor. Returns null when the receiver isn't a simple
 * `$this->propertyName` expression (e.g. `$receipt = $this->createService->createReceipt(`
 * → 'createService'; `$saleDraft = $this->finalizationService->finalize($saleDraft);`
 * → 'finalizationService'). Greedy on the FIRST `$this->propertyName->`
 * substring so an assignment prefix like `$x = $this->foo->bar(` still
 * resolves to 'foo'.
 */
function extractReceiverPropertyName(string $anchor): ?string
{
    // Match the first occurrence of `$this->IDENT->` in the anchor.
    if (preg_match('/\$this->([A-Za-z_][A-Za-z0-9_]*)->/', $anchor, $m) === 1) {
        return $m[1];
    }

    return null;
}

if ($failed === 0) {
    fwrite(STDOUT, sprintf(
        "manifest receiver_type validator: PASS — %d entry/entries validated\n",
        $totalChecked,
    ));
}

exit($failed);
