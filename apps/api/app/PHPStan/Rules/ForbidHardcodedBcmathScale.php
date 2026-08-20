<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Int_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids bcmath calls with a hardcoded LITERAL integer scale argument inside
 * the service layer (`app/Modules/<Module>/Application/Services/` and
 * `app/Modules/<Module>/Domain/Services/`).
 *
 * Monetary scale is currency-dependent (TND=3, EUR=2, JPY=0, …) and quantity
 * scale is a separate domain constant. Services must resolve the scale at
 * runtime — via the injected scale resolver, `$this->scale()`, a
 * `CurrencyScale` value object, or a derived intermediate such as
 * `$this->scale() + 1` — never bake a literal like `bcadd($a, $b, 3)`. A baked
 * literal silently truncates or over-pads other currencies and is invisible to
 * the precision-resolution machinery.
 *
 * Allowed (NOT flagged):
 *   - `bcmul($a, $b, $this->scale())`              — runtime scale
 *   - `bcdiv($a, $b, $scale + 1)`                  — derived intermediate (not a literal)
 *   - `bcadd($a, '0', CurrencyScale::for($cur))`   — resolver call
 *   - `bcadd($a, $b, 3); // precision-ok: <why>`   — explicit, justified exemption
 *
 * Flagged:
 *   - `bcadd($a, $b, 3)`                           — bare literal scale
 *
 * @implements Rule<FuncCall>
 */
final class ForbidHardcodedBcmathScale implements Rule
{
    private const BCMATH_FUNCTIONS = [
        'bcadd' => 2,
        'bcsub' => 2,
        'bcmul' => 2,
        'bcdiv' => 2,
        'bccomp' => 2,
    ];

    private const EXEMPTION_MARKER = 'precision-ok';

    /**
     * Per-file cache of source lines, keyed by absolute path.
     *
     * @var array<string, list<string>>
     */
    private array $fileLineCache = [];

    public function getNodeType(): string
    {
        return FuncCall::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->isInServiceLayer($scope)) {
            return [];
        }

        if (! $node->name instanceof Name) {
            return [];
        }

        $functionName = strtolower($node->name->toString());

        if (! array_key_exists($functionName, self::BCMATH_FUNCTIONS)) {
            return [];
        }

        $scaleArgIndex = self::BCMATH_FUNCTIONS[$functionName];

        $args = $node->getArgs();

        // No explicit scale argument => bcmath defaults; not our concern here.
        if (! isset($args[$scaleArgIndex])) {
            return [];
        }

        $scaleArg = $args[$scaleArgIndex];

        if (! $this->isLiteralIntScale($scaleArg)) {
            return [];
        }

        if ($this->hasExemptionComment($node, $scaleArg, $scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s() called with a hardcoded literal scale in the service layer. '
                .'Monetary/quantity scale is currency- and domain-dependent — resolve it '
                .'at runtime (scale resolver, $this->scale(), CurrencyScale) or, if a '
                .'literal is genuinely correct, append a "// %s: <reason>" comment.',
                $functionName,
                self::EXEMPTION_MARKER,
            ))
                ->identifier('precision.hardcodedBcmathScale')
                ->build(),
        ];
    }

    private function isInServiceLayer(Scope $scope): bool
    {
        $file = str_replace('\\', '/', $scope->getFile());

        return str_contains($file, '/app/Modules/')
            && (
                preg_match('#/app/Modules/[^/]+/Application/Services/#', $file) === 1
                || preg_match('#/app/Modules/[^/]+/Domain/Services/#', $file) === 1
            );
    }

    private function isLiteralIntScale(Arg $arg): bool
    {
        // Only a bare integer literal counts. Anything computed
        // (e.g. $scale + 1, $this->scale(), a const) is allowed.
        return $arg->value instanceof Int_;
    }

    /**
     * Honour a `// precision-ok` exemption comment.
     *
     * PHP-Parser only attaches *leading* comments to a node, so a trailing
     * same-line comment (the natural place to justify a literal scale) is not
     * available via getComments(). We therefore also scan the raw source on the
     * call's start line, end line, and the line immediately above it.
     */
    private function hasExemptionComment(FuncCall $node, Arg $scaleArg, Scope $scope): bool
    {
        // 1. Leading doc/line comments attached to the AST nodes.
        foreach ([$scaleArg, $node] as $candidate) {
            foreach ($candidate->getComments() as $comment) {
                if (str_contains($comment->getText(), self::EXEMPTION_MARKER)) {
                    return true;
                }
            }
        }

        // 2. Raw-source scan for a trailing or preceding same-call comment.
        // For TRAIT code analyzed in a consuming class's context, getFile() returns the
        // CONSUMER file — so this scan was silently reading the wrong file's lines and the
        // exemption never fired for any trait. Resolve the trait's own file when present.
        // (Root-caused 2026-08-20 by the Codex second-reviewer probe.)
        $sourceFile = $scope->getTraitReflection()?->getFileName() ?? $scope->getFile();
        $lines = $this->fileLines($sourceFile);

        if ($lines === []) {
            return false;
        }

        $startLine = $node->getStartLine();
        $endLine = $node->getEndLine();

        // 1-indexed lines -> 0-indexed array. Include the line above the call
        // so a comment placed on its own line directly before also exempts.
        foreach ([$startLine - 1, $endLine, $startLine] as $lineNumber) {
            $index = $lineNumber - 1;

            if ($index >= 0 && isset($lines[$index])
                && str_contains($lines[$index], self::EXEMPTION_MARKER)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function fileLines(string $file): array
    {
        if (array_key_exists($file, $this->fileLineCache)) {
            return $this->fileLineCache[$file];
        }

        $contents = is_file($file) ? file_get_contents($file) : false;

        $lines = $contents === false ? [] : explode("\n", $contents);

        return $this->fileLineCache[$file] = $lines;
    }
}
