# ForbidHardcodedBcmathScale: add a PHPStan RuleTester suite (incl. the trait-context exemption case)

The rule had NO test. Found 2026-08-20 while closing C-3: `Scope::getFile()` returns the CONSUMER
file for trait-in-context analysis, so the `precision-ok` exemption silently never fired for any
trait code (fixed via `getTraitReflection()?->getFileName()`, root-caused by the Codex second
reviewer). Regression evidence today = the live C-3 case (whole-app analyse exit 0 with the
comments; flags again without them). Owed: a proper RuleTester suite with (a) a trait consumed by
a class, exemption comment honored; (b) the same without the comment, flagged; (c) the non-trait
cases. Same gap-class as P2's ESLint RuleTester deliverable — PHPStan rules under app/PHPStan/Rules
have no completeness census; sweep them when this ticket is taken.
