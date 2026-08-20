<?php

declare(strict_types=1);

namespace App\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

/**
 * Restricts enumerated literal billing-write forms to the claim service.
 *
 * This is deliberately a bounded lint, not proof that every possible write is
 * blocked. PHPStan analyses app/ only, so migrations, backfills, seeders, and
 * tests are outside its view. It cannot reliably see non-literal or dynamic
 * table names, payload keys, merged/spread arrays, dynamic method dispatch,
 * generic unresolved builders, or raw PDO. Literal marker-table and
 * delivery-note payload SQL matching are substring based rather than a SQL
 * parser. Whole-payload assignments are only matched
 * when their right-hand side is a literal array containing an enumerated key,
 * including the supported literal payload nesting; dynamic values remain
 * outside this rule's boundary. A green build makes no claim beyond those
 * explicitly covered forms. DeliveryNoteClaimSet's private constructor blocks
 * direct `new`; PHP has no friend visibility that would restrict its public
 * issuance factory to one service. This rule therefore reports literal static
 * `DeliveryNoteClaimSet::fromReservation(...)` calls in analysed app/ code when
 * they occur outside the claim service. Dynamic/non-literal dispatch and calls
 * outside analysed app/ code remain uncovered; enforcing those at runtime would
 * require an undesirable backtrace/friend-style hack.
 *
 * @implements Rule<Expr>
 */
final class DeliveryNoteBillingWritesOnlyViaClaimService implements Rule
{
    private const ALLOWED_CLASS = 'App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteBillingClaimService';

    private const BILLING_KEYS = ['invoiced_at', 'invoice_id', 'invoiced_via'];

    private const DOCUMENT_FQCN = 'App\\Modules\\Document\\Domain\\Document';

    private const ELOQUENT_MODEL_FQCN = 'Illuminate\\Database\\Eloquent\\Model';

    private const MARKER_TABLE = 'delivery_note_billing_marks';

    private const MESSAGE = 'Delivery-note billing writes must go through '.self::ALLOWED_CLASS.'.';

    private const SET_FQCN = 'App\\Modules\\Document\\Domain\\Services\\Billing\\DeliveryNoteClaimSet';

    private const SET_MESSAGE = 'Literal DeliveryNoteClaimSet::fromReservation(...) calls in app/ must go through '.self::ALLOWED_CLASS.'.';

    public function getNodeType(): string
    {
        return Expr::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if ($scope->getClassReflection()?->getName() === self::ALLOWED_CLASS) {
            return [];
        }

        if ($node instanceof Assign && $this->isDocumentPayloadAssignment($node, $scope)) {
            return [$this->error()];
        }

        if ($node instanceof MethodCall && $this->isForbiddenMethodWrite($node, $scope)) {
            return [$this->error()];
        }

        if ($node instanceof StaticCall && $this->isClaimSetIssuance($node, $scope)) {
            return [$this->setError()];
        }

        if ($node instanceof StaticCall && $this->isForbiddenStaticWrite($node, $scope)) {
            return [$this->error()];
        }

        return [];
    }

    private function isDocumentPayloadAssignment(Assign $node, Scope $scope): bool
    {
        if ($node->var instanceof PropertyFetch) {
            return $this->identifierIs($node->var->name, 'payload')
                && $this->isExactDocumentType($node->var->var, $scope)
                && $node->expr instanceof Array_
                && $this->arrayContainsBillingKey($node->expr);
        }

        if (! $node->var instanceof ArrayDimFetch
            || ! $node->var->var instanceof PropertyFetch
            || ! $node->var->dim instanceof String_
            || ! in_array($node->var->dim->value, self::BILLING_KEYS, true)) {
            return false;
        }

        $payload = $node->var->var;

        return $this->identifierIs($payload->name, 'payload')
            && $this->isExactDocumentType($payload->var, $scope);
    }

    private function isForbiddenMethodWrite(MethodCall $node, Scope $scope): bool
    {
        if (! $node->name instanceof Identifier) {
            return false;
        }

        $method = $node->name->toString();
        if (in_array($method, ['update', 'create', 'fill', 'forceFill'], true)
            && $this->isExactDocumentType($node->var, $scope)
            && isset($node->args[0])
            && $node->args[0] instanceof Arg
            && $node->args[0]->value instanceof Array_
            && $this->arrayContainsBillingKey($node->args[0]->value)) {
            return true;
        }

        if (in_array($method, ['insert', 'insertOrIgnore', 'update', 'upsert', 'delete'], true)
            && $this->isLiteralMarkerTableCall($node->var, $scope)) {
            return true;
        }

        if (! in_array($method, ['save', 'update'], true)) {
            return false;
        }

        foreach ($scope->getType($node->var)->getObjectClassNames() as $className) {
            if ($this->modelUsesMarkerTable($className)) {
                return true;
            }
        }

        return false;
    }

    private function isForbiddenStaticWrite(StaticCall $node, Scope $scope): bool
    {
        if (! $node->class instanceof Name || ! $node->name instanceof Identifier) {
            return false;
        }

        $className = $scope->resolveName($node->class);
        $method = $node->name->toString();

        if ($className === self::DOCUMENT_FQCN
            && $method === 'create'
            && isset($node->args[0])
            && $node->args[0] instanceof Arg
            && $node->args[0]->value instanceof Array_
            && $this->arrayContainsBillingKey($node->args[0]->value)) {
            return true;
        }

        if ($className === 'Illuminate\\Support\\Facades\\DB'
            && in_array($method, ['statement', 'insert', 'update', 'delete'], true)
            && isset($node->args[0])
            && $node->args[0] instanceof Arg
            && $node->args[0]->value instanceof String_) {
            $sql = strtolower($node->args[0]->value->value);

            return str_contains($sql, self::MARKER_TABLE)
                || $this->isLiteralDeliveryNotePayloadBillingSql($sql);
        }

        return in_array($method, ['create', 'insert'], true)
            && $this->modelUsesMarkerTable($className);
    }

    private function isLiteralMarkerTableCall(Expr $expression, Scope $scope): bool
    {
        if (! $expression instanceof StaticCall
            || ! $expression->class instanceof Name
            || ! $this->identifierIs($expression->name, 'table')
            || $scope->resolveName($expression->class) !== 'Illuminate\\Support\\Facades\\DB'
            || ! isset($expression->args[0])
            || ! $expression->args[0] instanceof Arg
            || ! $expression->args[0]->value instanceof String_) {
            return false;
        }

        return $expression->args[0]->value->value === self::MARKER_TABLE;
    }

    private function isClaimSetIssuance(StaticCall $node, Scope $scope): bool
    {
        return $node->class instanceof Name
            && $scope->resolveName($node->class) === self::SET_FQCN
            && $this->identifierIs($node->name, 'fromReservation');
    }

    private function isExactDocumentType(Expr $expression, Scope $scope): bool
    {
        return $scope->getType($expression)->describe(VerbosityLevel::typeOnly()) === self::DOCUMENT_FQCN;
    }

    private function arrayContainsBillingKey(Array_ $array): bool
    {
        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            if (in_array($item->key->value, self::BILLING_KEYS, true)) {
                return true;
            }

            if ($item->key->value === 'payload'
                && $item->value instanceof Array_
                && $this->arrayContainsBillingKey($item->value)) {
                return true;
            }
        }

        return false;
    }

    private function modelUsesMarkerTable(string $className): bool
    {
        $modelType = new ObjectType($className);
        if (! (new ObjectType(self::ELOQUENT_MODEL_FQCN))->isSuperTypeOf($modelType)->yes()) {
            return false;
        }

        $classReflection = $modelType->getClassReflection();
        if ($classReflection === null) {
            return false;
        }

        $defaults = $classReflection->getNativeReflection()->getDefaultProperties();

        return ($defaults['table'] ?? null) === self::MARKER_TABLE;
    }

    private function isLiteralDeliveryNotePayloadBillingSql(string $sql): bool
    {
        return str_contains($sql, 'documents')
            && str_contains($sql, 'delivery_note')
            && str_contains($sql, 'payload')
            && $this->stringContainsBillingKey($sql);
    }

    private function stringContainsBillingKey(string $value): bool
    {
        foreach (self::BILLING_KEYS as $billingKey) {
            if (str_contains($value, $billingKey)) {
                return true;
            }
        }

        return false;
    }

    private function identifierIs(Node $node, string $expected): bool
    {
        return $node instanceof Identifier && $node->toString() === $expected;
    }

    private function error(): IdentifierRuleError
    {
        return RuleErrorBuilder::message(self::MESSAGE)
            ->identifier('document.deliveryNoteBilling.directWrite')
            ->build();
    }

    private function setError(): IdentifierRuleError
    {
        return RuleErrorBuilder::message(self::SET_MESSAGE)
            ->identifier('document.deliveryNoteBilling.claimSetConstruction')
            ->build();
    }
}
