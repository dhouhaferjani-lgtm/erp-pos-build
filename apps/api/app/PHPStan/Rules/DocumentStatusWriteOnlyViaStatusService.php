<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use App\Modules\Document\Domain\Services\DocumentStatusService;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * N-6 Phase 1 — the two document lifecycle-status edges that must not be
 * written by hand.
 *
 *  (a) **`DocumentStatus::Paid` may be written only inside
 *      {@see DocumentStatusService}.** Seven treasury writers used to set it on
 *      a pure TYPE test, producing `Confirmed → Paid` invoices that could never
 *      be posted, sealed, or booked (campaign INV-2026-0003). `Paid` is now
 *      reachable only from `Posted`, and only through the status service.
 *
 *  (b) **`DocumentStatus::Posted` may not be written from anywhere inside
 *      `App\Modules\Treasury`.** Treasury re-opens a settled document when a
 *      refund or an instrument cancellation restores its balance — but it must
 *      not decide that the document *was* posted. Two writers did exactly that
 *      unconditionally (`PaymentRefundService`, `OutboundInstrumentService`),
 *      which promotes a never-sealed invoice to a posted-looking one.
 *      {@see DocumentStatusService::reopenFromPaid()} checks `fiscal_hash`
 *      first.
 *
 * FIXES THE KNOWN GAP IN THE WORKSHOP PRECEDENT
 * ({@see WorkOrderStatusWriteOnlyViaTransitionService}, TRIAGE #27): that rule
 * matches ONLY `$model->status = …` property assignment, so `update([...])`,
 * `fill([...])` and `forceFill([...])` array writes sail past it. Both of the
 * `Posted` writers above use `forceFill()`, and two of the `Paid` writers use
 * `update()`. This rule covers all four forms.
 *
 * WHAT IS *NOT* COVERED, AND WHY THE DB CHECK IS NOT A BACKSTOP FOR IT
 * (treasury gate r1 I-7 corrected an earlier, false claim here).
 * `chk_documents_status_enum` pins the VALUE DOMAIN only — `'paid'` is a legal
 * value, so a raw `UPDATE documents SET status='paid' WHERE status='confirmed'`
 * satisfies the constraint and reproduces N-6. The CHECK backstops values, never
 * EDGES; there is no edge backstop below the application layer (widening
 * `trg_document_immutability` is the separate, owner-tracked C-8 lane). Also
 * uncovered, measured rather than assumed: a non-literal array
 * (`$attrs = [...]; $doc->update($attrs)`), `setAttribute('status', …)`, and
 * `DB::table('documents')->update(...)`.
 *
 * VALUE MATCHING IS TYPE-BASED, NOT SYNTAX-BASED: the assigned expression's
 * PHPStan type is compared against the enum case, so an indirection through a
 * local variable or a ternary (`$x ? DocumentStatus::Paid : $doc->status`) is
 * still caught. A value PHPStan cannot resolve to a single enum case is NOT
 * reported — this is a bounded lint, and the boundary is stated so a green run
 * is not read as proof. PHPStan analyses `app/` only: migrations, seeders,
 * console backfills outside `app/`, and tests are outside its view, and raw
 * SQL/DB::table() writes are outside this rule's shape entirely.
 *
 * @implements Rule<Expr>
 */
final class DocumentStatusWriteOnlyViaStatusService implements Rule
{
    private const ALLOWED_CLASS = 'App\\Modules\\Document\\Domain\\Services\\DocumentStatusService';

    private const DOCUMENT_FQCN = 'App\\Modules\\Document\\Domain\\Document';

    private const STATUS_ENUM_FQCN = 'App\\Modules\\Document\\Domain\\Enums\\DocumentStatus';

    private const PAID_CASE = self::STATUS_ENUM_FQCN.'::Paid';

    private const POSTED_CASE = self::STATUS_ENUM_FQCN.'::Posted';

    private const TREASURY_NAMESPACE_PREFIX = 'App\\Modules\\Treasury\\';

    /**
     * Eloquent mass-assignment entry points that write attributes from a
     * literal array.
     */
    private const ARRAY_WRITE_METHODS = ['update', 'fill', 'forceFill'];

    /**
     * Receivers whose generic parameter is the Document model — a mass-update
     * through any of these writes `documents.status` just as directly as the
     * model does (treasury gate r1 I-7).
     *
     * @var list<string>
     */
    private const BUILDER_FQCNS = [
        'Illuminate\\Database\\Eloquent\\Builder',
        'Illuminate\\Database\\Eloquent\\Relations\\Relation',
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Assign) {
            return $this->processPropertyAssign($node, $scope);
        }

        if ($node instanceof MethodCall) {
            return $this->processArrayWrite($node, $scope);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processPropertyAssign(Assign $node, Scope $scope): array
    {
        if (! $node->var instanceof PropertyFetch) {
            return [];
        }

        if (! $node->var->name instanceof Identifier || $node->var->name->toString() !== 'status') {
            return [];
        }

        if (! $this->isDocument($node->var->var, $scope)) {
            return [];
        }

        return $this->verdictFor($node->expr, $scope);
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function processArrayWrite(MethodCall $node, Scope $scope): array
    {
        if (! $node->name instanceof Identifier) {
            return [];
        }

        if (! in_array($node->name->toString(), self::ARRAY_WRITE_METHODS, true)) {
            return [];
        }

        if (! isset($node->args[0]) || ! $node->args[0] instanceof Node\Arg) {
            return [];
        }

        $argument = $node->args[0]->value;
        if (! $argument instanceof Array_) {
            return [];
        }

        if (! $this->isDocument($node->var, $scope)) {
            return [];
        }

        foreach ($argument->items as $item) {
            if (! $item->key instanceof String_ || $item->key->value !== 'status') {
                continue;
            }

            $errors = $this->verdictFor($item->value, $scope);
            if ($errors !== []) {
                return $errors;
            }
        }

        return [];
    }

    /**
     * Decide whether the value being written to `Document::$status` is one of
     * the two forbidden enum cases in the current scope.
     *
     * @return list<IdentifierRuleError>
     */
    private function verdictFor(Expr $value, Scope $scope): array
    {
        $valueType = $scope->getType($value)->describe(VerbosityLevel::precise());
        $className = $scope->getClassReflection()?->getName() ?? '';

        if ($valueType === self::PAID_CASE && $className !== self::ALLOWED_CLASS) {
            return [
                RuleErrorBuilder::message(
                    'DocumentStatus::Paid may only be written inside '.self::ALLOWED_CLASS.'.'
                    .' A payment on a CONFIRMED document is an advance (Cr 419), not a settlement:'
                    .' use DocumentStatusService::markPaid(), which refuses the confirmed → paid edge.'
                )
                    ->identifier('document.status.paidDirectWrite')
                    ->build(),
            ];
        }

        if (
            $valueType === self::POSTED_CASE
            && str_starts_with($className, self::TREASURY_NAMESPACE_PREFIX)
        ) {
            return [
                RuleErrorBuilder::message(
                    'Treasury may not write DocumentStatus::Posted directly.'
                    .' Use '.self::ALLOWED_CLASS.'::reopenFromPaid(), which refuses to promote a'
                    .' never-sealed document (fiscal_hash IS NULL) to Posted.'
                )
                    ->identifier('document.status.treasuryPostedWrite')
                    ->build(),
            ];
        }

        return [];
    }

    /**
     * Exact FQCN equality — a substring match would false-positive on
     * `DocumentLine`, `DocumentData`, `DocumentTemplate`, and every other class
     * sharing the `Document` prefix.
     */
    private function isDocument(Expr $expr, Scope $scope): bool
    {
        $described = $scope->getType($expr)->describe(VerbosityLevel::typeOnly());

        if ($described === self::DOCUMENT_FQCN) {
            return true;
        }

        // N-6 fix round r1 / treasury gate I-7 — BUILDER RECEIVERS.
        //
        // `Document::query()->whereKey($id)->update(['status' => Paid])` is
        // ordinary Laravel and reproduces N-6 with no guard firing: the receiver
        // is an Eloquent Builder/Relation, not the model, so exact-FQCN matching
        // never saw it. The generic parameter names the model, which is what
        // makes this decidable rather than a substring guess — and it keeps
        // `Builder<SomeOtherModel>` out.
        foreach (self::BUILDER_FQCNS as $builder) {
            if (str_starts_with($described, $builder.'<') && str_contains($described, self::DOCUMENT_FQCN)) {
                return true;
            }
        }

        return false;
    }
}
