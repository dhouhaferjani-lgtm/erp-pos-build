<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\Accounting\Domain\Account;
use App\Modules\Accounting\Domain\Enums\GlResidualRefusal;
use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\Accounting\Domain\Exceptions\UnpostableDocumentGlException;
use App\Modules\Company\Domain\Company;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use App\Modules\Document\Domain\Document;
use App\Modules\Document\Domain\DocumentLine;
use App\Modules\Document\Domain\Enums\DocumentStatus;
use App\Modules\Document\Domain\Enums\DocumentType;
use App\Modules\Document\Domain\Enums\FiscalStatus;
use App\Modules\Document\Domain\Services\DocumentPostingService;
use App\Modules\Partner\Domain\Enums\PartnerType;
use App\Modules\Partner\Domain\Partner;
use App\Modules\Tenant\Domain\Tenant;
use Database\Seeders\FranceChartOfAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\Support\Attributes\UsesFrozenSeederFixture;
use Tests\TestCase;

#[UsesFrozenSeederFixture]
final class ProvisioningRequiredPurposesV1ConformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_is_the_complete_exact_41_case_partition(): void
    {
        // Production break caught: a purpose disappears, appears twice, or changes operational classification.
        $expected = [
            'REQUIRED' => [
                'bank', 'cash', 'customer_receivable', 'inventory', 'supplier_payable',
                'vat_collected', 'vat_deductible', 'product_revenue', 'service_revenue',
                'cost_of_goods_sold', 'general_expense', 'opening_balance_equity',
                'purchase_price_variance_expense', 'purchase_price_variance_income',
                'goods_received_not_invoiced', 'purchase_stamp_duty', 'sales_discount',
                'customer_advance', 'supplier_advance', 'sales_returns_clearing',
                'voucher_liability', 'marketing_goodwill_expense', 'pos_tender_clearing',
                'rounding_loss_expense', 'payment_tolerance_expense',
                'payment_tolerance_income', 'purchase_expenses',
            ],
            'SCOPE_REQUIRED' => ['sales_stamp_duty_payable'],
            'CONDITIONAL' => [
                'sales_return', 'refund_write_off',
                'sales_rounding_difference_income', 'sales_rounding_difference_expense',
            ],
            'SOFT' => [
                'office_expense', 'travel_expense', 'meals_expense', 'utilities_expense',
                'retained_earnings', 'realized_fx_gain', 'realized_fx_loss',
                'voucher_breakage_income', 'uninvoiced_revenue',
            ],
        ];

        $actual = [];
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            $actual[$entry['classification']][] = $entry['purpose']->value;
            self::assertNotSame('', trim($entry['call_site']));
            self::assertNotSame('', trim($entry['evidence_citation']));
        }

        foreach ($actual as &$purposes) {
            sort($purposes);
        }
        unset($purposes);
        foreach ($expected as &$purposes) {
            sort($purposes);
        }
        unset($purposes);

        self::assertSame($expected, $actual);
        self::assertCount(41, ProvisioningRequiredPurposesV1::entries());
        self::assertCount(41, SystemAccountPurpose::cases());
        ProvisioningRequiredPurposesV1::assertConforms(ProvisioningRequiredPurposesV1::entries());
    }

    public function test_only_the_two_allowed_non_empty_gate_kinds_can_appear(): void
    {
        // Production break caught: an alert, permission check, or data condition is blessed as a publish gate.
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] === 'CONDITIONAL') {
                self::assertSame('DOMAIN_PRECHECK_4XX', $entry['gate_kind']);
            } else {
                self::assertNull($entry['gate_kind']);
            }
        }

        self::assertSame(['MODULE_GATE', 'DOMAIN_PRECHECK_4XX'], ProvisioningRequiredPurposesV1::allowedGateKinds());
    }

    public function test_deliberately_misclassified_throwing_fixture_is_rejected(): void
    {
        // Production break caught: the validator accepts a throwing lookup re-labelled SOFT.
        $fixture = ProvisioningRequiredPurposesV1::entries();
        foreach ($fixture as &$entry) {
            if ($entry['purpose'] === SystemAccountPurpose::SalesReturn) {
                $entry['classification'] = 'SOFT';
                $entry['gate_kind'] = null;
            }
        }
        unset($entry);

        $this->expectException(LogicException::class);
        ProvisioningRequiredPurposesV1::assertConforms($fixture);
    }

    public function test_rebalancing_required_and_soft_counts_cannot_hide_direction_misclassification(): void
    {
        $fixture = ProvisioningRequiredPurposesV1::entries();
        foreach ($fixture as &$entry) {
            if ($entry['purpose'] === SystemAccountPurpose::GeneralExpense) {
                $entry['classification'] = 'SOFT';
                $entry['call_site'] = 'NONE';
            }
            if ($entry['purpose'] === SystemAccountPurpose::OfficeExpense) {
                $entry['classification'] = 'REQUIRED';
                $entry['call_site'] = 'fabricated required path';
            }
        }
        unset($entry);

        $this->expectException(LogicException::class);
        ProvisioningRequiredPurposesV1::assertConforms($fixture);
    }

    public function test_each_dynamic_only_required_purpose_is_pinned_against_balanced_demotion(): void
    {
        $dynamicOnly = [
            SystemAccountPurpose::GeneralExpense,
            SystemAccountPurpose::SalesReturnsClearing,
            SystemAccountPurpose::VoucherLiability,
            SystemAccountPurpose::MarketingGoodwillExpense,
            SystemAccountPurpose::PosTenderClearing,
            SystemAccountPurpose::RoundingLossExpense,
        ];

        ProvisioningRequiredPurposesV1::assertDynamicRequiredPurposes(
            ProvisioningRequiredPurposesV1::entries(),
            $dynamicOnly,
        );

        foreach ($dynamicOnly as $demotedPurpose) {
            $fixture = ProvisioningRequiredPurposesV1::entries();
            foreach ($fixture as &$entry) {
                if ($entry['purpose'] === $demotedPurpose) {
                    $entry['classification'] = 'SOFT';
                    $entry['call_site'] = 'NONE';
                    $entry['evidence_citation'] = 'NONE:Mutation fixture.';
                }
                if ($entry['purpose'] === SystemAccountPurpose::OfficeExpense) {
                    $entry['classification'] = 'REQUIRED';
                    $entry['call_site'] = 'mutation fixture';
                    $entry['evidence_citation'] = 'DYNAMIC:mutation fixture';
                }
            }
            unset($entry);

            try {
                ProvisioningRequiredPurposesV1::assertDynamicRequiredPurposes($fixture, $dynamicOnly);
                self::fail("Demoting {$demotedPurpose->value} must fail the direct DYNAMIC-required guard.");
            } catch (LogicException $exception) {
                self::assertStringContainsString($demotedPurpose->value, $exception->getMessage());
            }
        }
    }

    public function test_every_evidence_citation_resolves_to_current_source_semantics(): void
    {
        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            [$kind, $citation] = explode(':', $entry['evidence_citation'], 2);

            if ($kind === 'DIRECT') {
                self::assertContains($citation, ProvisioningRequiredPurposesV1::registeredThrowingCallSites());
                $this->assertRegisteredEvidence($citation, $entry['purpose']);

                continue;
            }

            if ($kind === 'DYNAMIC') {
                [$registered, $sourceReference] = explode(' <- ', $citation, 2);
                self::assertContains($registered, ProvisioningRequiredPurposesV1::registeredThrowingCallSites());
                $dynamicMethod = $this->assertRegisteredEvidence($registered, null);
                $sourceMethod = $this->assertPurposeReference($sourceReference, $entry['purpose']);

                if ($dynamicMethod->name->toString() !== $sourceMethod->name->toString()) {
                    $sourceMethodName = $sourceMethod->name->toString();
                    $delegatesToSource = (new NodeFinder)->findFirst(
                        $dynamicMethod->stmts ?? [],
                        static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                            && $node->name instanceof Node\Identifier
                            && $node->name->toString() === $sourceMethodName,
                    );
                    self::assertNotNull($delegatesToSource, $entry['purpose']->name);
                }

                continue;
            }

            if ($kind === 'CONDITIONAL') {
                $this->assertPurposeReference($citation, $entry['purpose']);

                continue;
            }

            self::assertSame('NONE', $kind, $entry['purpose']->name);
            self::assertSame('SOFT', $entry['classification'], $entry['purpose']->name);
        }
    }

    public function test_conditional_entries_have_a_real_precheck_and_a_4xx_render_path(): void
    {
        // Production break caught: a CONDITIONAL lookup loses its dominating precheck or 422 mapping.
        $refund = $this->source('app/Modules/Fiscal/Application/Services/RefundCompensationService.php');
        self::assertStringContainsString('SystemAccountPurpose::RefundWriteOff', $refund);
        self::assertStringContainsString('SystemAccountPurpose::SalesReturn', $refund);
        self::assertStringContainsString('RefundCompensationRefusedException', $refund);
        self::assertStringContainsString('extends DomainException', $this->source('app/Modules/Fiscal/Domain/Exceptions/RefundCompensationRefusedException.php'));

        $accounting = $this->source('app/Modules/Accounting/Application/Services/AccountingService.php');
        self::assertStringContainsString('SystemAccountPurpose::SalesRoundingDifferenceIncome', $accounting);
        self::assertStringContainsString('SystemAccountPurpose::SalesRoundingDifferenceExpense', $accounting);
        self::assertStringContainsString('GlResidualRefusal::NoAbsorbingAccount', $accounting);
        self::assertStringContainsString('assertDocumentGlIsPostable', $this->source('app/Modules/Document/Domain/Services/DocumentPostingService.php'));
        self::assertStringContainsString('extends DomainException', $this->source('app/Modules/Accounting/Domain/Exceptions/UnpostableDocumentGlException.php'));

        $bootstrap = $this->source('bootstrap/app.php');
        self::assertMatchesRegularExpression('/render\(function \(DomainException .*?\}\);/s', $bootstrap);
        self::assertMatchesRegularExpression('/render\(function \(DomainException .*?\], 422\);/s', $bootstrap);
    }

    public function test_removing_each_conditional_absorber_breaks_the_structural_dominance_proof(): void
    {
        $refund = $this->source('app/Modules/Fiscal/Application/Services/RefundCompensationService.php');
        $accounting = $this->source('app/Modules/Accounting/Application/Services/AccountingService.php');
        $posting = $this->source('app/Modules/Document/Domain/Services/DocumentPostingService.php');

        $this->assertConditionalGateDominance($refund, $accounting, $posting);

        foreach (
            [
                ['refund', 'SystemAccountPurpose::RefundWriteOff'],
                ['refund', 'SystemAccountPurpose::SalesReturn'],
                ['accounting', 'SystemAccountPurpose::SalesRoundingDifferenceIncome'],
                ['accounting', 'SystemAccountPurpose::SalesRoundingDifferenceExpense'],
            ] as [$target, $absorber]
        ) {
            $mutatedRefund = $target === 'refund'
                ? str_replace($absorber, 'SystemAccountPurpose::GeneralExpense', $refund)
                : $refund;
            $mutatedAccounting = $target === 'accounting'
                ? str_replace($absorber, 'SystemAccountPurpose::GeneralExpense', $accounting)
                : $accounting;

            try {
                $this->assertConditionalGateDominance($mutatedRefund, $mutatedAccounting, $posting);
                self::fail("Removing {$absorber} must invalidate the conditional gate proof.");
            } catch (LogicException $exception) {
                self::assertNotSame('', $exception->getMessage());
            }
        }
    }

    public function test_each_missing_rounding_absorber_is_refused_live_before_fiscal_sealing(): void
    {
        $this->assertMissingRoundingAbsorberRefusesBeforeSeal(
            DocumentType::Invoice,
            SystemAccountPurpose::SalesRoundingDifferenceIncome,
        );
        $this->assertMissingRoundingAbsorberRefusesBeforeSeal(
            DocumentType::CreditNote,
            SystemAccountPurpose::SalesRoundingDifferenceExpense,
        );
    }

    public function test_soft_entries_have_no_registered_throwing_site(): void
    {
        // Production break caught: a SOFT purpose becomes reachable through a registered throwing resolver.
        $registered = implode("\n", ProvisioningRequiredPurposesV1::registeredThrowingCallSites());

        foreach (ProvisioningRequiredPurposesV1::entries() as $entry) {
            if ($entry['classification'] !== 'SOFT') {
                continue;
            }

            self::assertSame('NONE', $entry['call_site']);
            self::assertStringNotContainsString($entry['purpose']->name, $registered);
        }
    }

    public function test_uninvoiced_revenue_posting_surface_has_no_production_caller(): void
    {
        // Production break caught: dead UninvoicedRevenue POSTING code becomes reachable
        // without reclassification.
        //
        // Merge-seam reconciliation 2026-08-18 (parent orchestrator, country-defaults
        // Phase A x DPA wave-3): this tripwire originally refused ANY production
        // reference to UninvoicedDeliveryNoteService. The DPA-3E lane-separation
        // report legitimately consumes the READ surface (ReportsController::
        // generateYearEndReport, CheckCogsCoverageCommand::getUninvoicedDeliveryNotes)
        // without touching GL, so the guard now pins what the v1 catalog
        // classification actually depends on: the two methods that mint journal
        // entries against SystemAccountPurpose::UninvoicedRevenue. If this test
        // fails, the purpose must be reclassified in ProvisioningRequiredPurposesV1
        // (SOFT -> required, with a certified account mapping) BEFORE the caller
        // ships — do not simply extend an allowlist here.
        $postingMethods = ['generateYearEndAdjustment', 'generateReversalEntry'];
        $ownFile = $this->apiRoot().'/app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php';
        $references = [];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach ($this->productionPhpFiles() as $file) {
            if ($file === $ownFile) {
                continue;
            }

            $ast = $parser->parse((string) file_get_contents($file)) ?? [];
            $visitor = new class($postingMethods) extends NodeVisitorAbstract
            {
                /** @var list<int> */
                public array $lines = [];

                /** @param list<string> $postingMethods */
                public function __construct(private readonly array $postingMethods) {}

                public function enterNode(Node $node): null
                {
                    $isCall = $node instanceof Node\Expr\MethodCall
                        || $node instanceof Node\Expr\NullsafeMethodCall
                        || $node instanceof Node\Expr\StaticCall;

                    if (
                        $isCall
                        && $node->name instanceof Node\Identifier
                        && in_array($node->name->toString(), $this->postingMethods, true)
                    ) {
                        $this->lines[] = $node->getStartLine();
                    }

                    return null;
                }
            };
            $traverser = new NodeTraverser;
            $traverser->addVisitor(new NameResolver);
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);

            foreach ($visitor->lines as $line) {
                $references[] = $file.':'.$line;
            }
        }

        self::assertSame([], $references);
    }

    private function assertConditionalGateDominance(
        string $refundSource,
        string $accountingSource,
        string $postingSource,
    ): void {
        $finder = new NodeFinder;

        $refundMethod = $this->methodNode($refundSource, 'compensate');
        $refundPrecheck = null;
        foreach ($finder->findInstanceOf($refundMethod->stmts ?? [], Node\Stmt\Foreach_::class) as $foreach) {
            $purposes = $this->classConstantNames($foreach->expr);
            sort($purposes);
            if ($purposes === ['RefundWriteOff', 'SalesReturn']) {
                $refundPrecheck = $foreach;
                break;
            }
        }
        if (! $refundPrecheck instanceof Node\Stmt\Foreach_) {
            throw new LogicException('Refund compensation must precheck both conditional account purposes together.');
        }

        $hasExistenceCheck = $finder->findFirst(
            $refundPrecheck->stmts,
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'hasAccountForPurpose',
        ) !== null;
        $hasDomainRefusal = $finder->findFirst(
            $refundPrecheck->stmts,
            static fn (Node $node): bool => $node instanceof Node\Expr\New_
                && $node->class instanceof Node\Name
                && $node->class->getLast() === 'RefundCompensationRefusedException',
        ) !== null;
        $transaction = $finder->findFirst(
            $refundMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'transaction',
        );
        if (! $hasExistenceCheck || ! $hasDomainRefusal || ! $transaction instanceof Node
            || $refundPrecheck->getEndLine() >= $transaction->getStartLine()) {
            throw new LogicException('Refund purpose refusal must dominate the transaction and throwing GL path.');
        }

        $residualMethod = $this->methodNode($accountingSource, 'residualPlan');
        $roundingAssignment = $finder->findFirst(
            $residualMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\Assign
                && $node->var instanceof Node\Expr\Variable
                && $node->var->name === 'roundingPurpose'
                && $node->expr instanceof Node\Expr\Ternary,
        );
        if (! $roundingAssignment instanceof Node\Expr\Assign) {
            throw new LogicException('Residual planning must choose a document-type-specific rounding absorber.');
        }
        $roundingPurposes = $this->classConstantNames($roundingAssignment->expr);
        sort($roundingPurposes);
        if ($roundingPurposes !== ['SalesRoundingDifferenceExpense', 'SalesRoundingDifferenceIncome']) {
            throw new LogicException('Residual planning must cover both rounding absorber purposes.');
        }

        $roundingLookup = $finder->findFirst(
            $residualMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\StaticCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'findByPurpose'
                && ($node->args[1]->value ?? null) instanceof Node\Expr\Variable
                && $node->args[1]->value->name === 'roundingPurpose',
        );
        $noAbsorberRefusal = $finder->findFirst(
            $residualMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\ClassConstFetch
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'NoAbsorbingAccount',
        );
        if (! $roundingLookup instanceof Node || ! $noAbsorberRefusal instanceof Node
            || $roundingLookup->getStartLine() >= $noAbsorberRefusal->getStartLine()) {
            throw new LogicException('A missing rounding absorber must produce the preflight refusal.');
        }

        $preflightMethod = $this->methodNode($accountingSource, 'assertDocumentGlIsPostable');
        $plansResidual = $finder->findFirst(
            $preflightMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'residualPlan',
        );
        $throwsRefusal = $finder->findFirstInstanceOf(
            $preflightMethod->stmts ?? [],
            Node\Expr\Throw_::class,
        );
        if (! $plansResidual instanceof Node || ! $throwsRefusal instanceof Node
            || $plansResidual->getStartLine() >= $throwsRefusal->getStartLine()) {
            throw new LogicException('The public GL preflight must throw the residual-plan refusal.');
        }

        $postMethod = $this->methodNode($postingSource, 'post');
        $livePreflight = $finder->findFirst(
            $postMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'assertDocumentGlIsPostable',
        );
        $seal = $finder->findFirst(
            $postMethod->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === 'postWithFiscalChain',
        );
        if (! $livePreflight instanceof Node || ! $seal instanceof Node
            || $livePreflight->getStartLine() >= $seal->getStartLine()) {
            throw new LogicException('Document posting must execute GL preflight before fiscal sealing.');
        }
    }

    private function assertMissingRoundingAbsorberRefusesBeforeSeal(
        DocumentType $documentType,
        SystemAccountPurpose $missingPurpose,
    ): void {
        $tenant = Tenant::factory()->create();
        $company = Company::factory()->create([
            'tenant_id' => $tenant->id,
            'country_code' => 'FR',
            'currency' => 'EUR',
        ]);
        (new FranceChartOfAccountsSeeder)->run($company->id, $tenant->id);
        Account::query()
            ->where('company_id', $company->id)
            ->where('system_purpose', $missingPurpose->value)
            ->delete();

        $customer = Partner::factory()->create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'type' => PartnerType::Customer,
        ]);
        $document = Document::create([
            'tenant_id' => $tenant->id,
            'company_id' => $company->id,
            'partner_id' => $customer->id,
            'type' => $documentType,
            'status' => DocumentStatus::Confirmed,
            'document_number' => strtoupper($documentType->value).'-M1-'.uniqid(),
            'document_date' => now(),
            'currency' => 'EUR',
            'subtotal' => '24.26',
            'tax_amount' => '4.85',
            'total' => '29.11',
            'balance_due' => '29.11',
        ]);
        foreach ([1, 2] as $lineNumber) {
            DocumentLine::create([
                'id' => Str::uuid()->toString(),
                'document_id' => $document->id,
                'line_number' => $lineNumber,
                'description' => 'M1 rounding preflight probe',
                'quantity' => '1.00',
                'unit_price' => '12.13',
                'tax_rate' => '20.00',
                'line_total' => '12.13',
            ]);
        }

        $postableDocument = $document->fresh(['lines']);
        if ($postableDocument === null) {
            throw new LogicException('The preflight mutation document disappeared before posting.');
        }

        try {
            $this->app->make(DocumentPostingService::class)->post($postableDocument);
            self::fail("{$missingPurpose->value} must be required before fiscal sealing.");
        } catch (UnpostableDocumentGlException $exception) {
            self::assertSame(GlResidualRefusal::NoAbsorbingAccount, $exception->refusal);
        }

        $fresh = $document->fresh();
        self::assertNotNull($fresh);
        self::assertSame(DocumentStatus::Confirmed, $fresh->status);
        self::assertNull($fresh->fiscal_hash);
        self::assertNull($fresh->chain_sequence);
        self::assertNotSame(FiscalStatus::Sealed, $fresh->fiscal_status);
    }

    private function assertRegisteredEvidence(
        string $signature,
        ?SystemAccountPurpose $expectedPurpose,
    ): Node\Stmt\ClassMethod {
        self::assertMatchesRegularExpression(
            '/^(.+\.php):(\d+)\|([^|]+)::([^|]+)\|([^|]+)\|([^|]+)$/',
            $signature,
        );
        if (preg_match(
            '/^(.+\.php):(\d+)\|([^|]+)::([^|]+)\|([^|]+)\|([^|]+)$/',
            $signature,
            $parts,
        ) !== 1) {
            throw new LogicException("Malformed registered evidence: {$signature}");
        }
        [, $file, $line, $class, $method, $callee, $purpose] = $parts;
        $expectedName = $expectedPurpose === null ? 'DYNAMIC' : $expectedPurpose->name;
        self::assertSame($expectedName, $purpose);

        $methodNode = $this->methodNode($this->source($file), $method, $class);
        $call = (new NodeFinder)->findFirst(
            $methodNode->stmts ?? [],
            static fn (Node $node): bool => ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall)
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $callee
                && $node->getStartLine() === (int) $line,
        );
        self::assertNotNull($call, $signature);

        if ($expectedPurpose !== null) {
            $purposeArgument = $call->args[1]->value ?? null;
            self::assertInstanceOf(Node\Expr\ClassConstFetch::class, $purposeArgument, $signature);
            self::assertInstanceOf(Node\Identifier::class, $purposeArgument->name, $signature);
            self::assertSame($expectedPurpose->name, $purposeArgument->name->toString(), $signature);
        }

        return $methodNode;
    }

    private function assertPurposeReference(
        string $reference,
        SystemAccountPurpose $expectedPurpose,
    ): Node\Stmt\ClassMethod {
        self::assertMatchesRegularExpression(
            '/^(.+\.php):(\d+)\|([^|]+)::([^|]+)\|([^|]+)$/',
            $reference,
        );
        if (preg_match(
            '/^(.+\.php):(\d+)\|([^|]+)::([^|]+)\|([^|]+)$/',
            $reference,
            $parts,
        ) !== 1) {
            throw new LogicException("Malformed purpose reference: {$reference}");
        }
        [, $file, $line, $class, $method, $purpose] = $parts;
        self::assertSame($expectedPurpose->name, $purpose, $reference);

        $methodNode = $this->methodNode($this->source($file), $method, $class);
        $purposeReference = (new NodeFinder)->findFirst(
            $methodNode->stmts ?? [],
            static fn (Node $node): bool => $node instanceof Node\Expr\ClassConstFetch
                && $node->name instanceof Node\Identifier
                && $node->name->toString() === $expectedPurpose->name
                && $node->getStartLine() === (int) $line,
        );
        self::assertNotNull($purposeReference, $reference);

        return $methodNode;
    }

    private function methodNode(string $source, string $method, ?string $class = null): Node\Stmt\ClassMethod
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $finder = new NodeFinder;
        $scope = $ast;
        if ($class !== null) {
            $classNode = $finder->findFirst(
                $ast,
                static fn (Node $candidate): bool => $candidate instanceof Node\Stmt\Class_
                    && $candidate->name?->toString() === $class,
            );
            if (! $classNode instanceof Node\Stmt\Class_) {
                throw new LogicException("Required class {$class} is absent from the cited source.");
            }
            $scope = $classNode->getMethods();
        }
        $node = $finder->findFirst(
            $scope,
            static fn (Node $candidate): bool => $candidate instanceof Node\Stmt\ClassMethod
                && $candidate->name->toString() === $method,
        );
        if (! $node instanceof Node\Stmt\ClassMethod) {
            throw new LogicException("Required method {$method} is absent from the conditional gate.");
        }

        return $node;
    }

    /** @return list<string> */
    private function classConstantNames(Node $root): array
    {
        $names = [];
        foreach ((new NodeFinder)->findInstanceOf([$root], Node\Expr\ClassConstFetch::class) as $fetch) {
            if ($fetch->name instanceof Node\Identifier) {
                $names[] = $fetch->name->toString();
            }
        }

        return $names;
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents($this->apiRoot().'/'.$relativePath);
    }

    private function apiRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<string> */
    private function productionPhpFiles(): array
    {
        $files = [];
        foreach (['app', 'routes', 'config', 'database', 'bootstrap'] as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->apiRoot().'/'.$root));
            foreach ($iterator as $item) {
                if ($item instanceof SplFileInfo && $item->isFile() && $item->getExtension() === 'php') {
                    $files[] = $item->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
