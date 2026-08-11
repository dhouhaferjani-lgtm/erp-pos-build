<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\Accounting\Domain\Enums\SystemAccountPurpose;
use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProvisioningRequiredPurposesV1ConformanceTest extends TestCase
{
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

    public function test_uninvoiced_delivery_note_service_has_no_production_caller(): void
    {
        // Production break caught: dead UninvoicedRevenue code becomes reachable without reclassification.
        $target = 'App\\Modules\\Compliance\\Services\\UninvoicedDeliveryNoteService';
        $ownFile = $this->apiRoot().'/app/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php';
        $references = [];
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach ($this->productionPhpFiles() as $file) {
            if ($file === $ownFile) {
                continue;
            }

            $ast = $parser->parse((string) file_get_contents($file)) ?? [];
            $visitor = new class($target) extends NodeVisitorAbstract
            {
                /** @var list<int> */
                public array $lines = [];

                public function __construct(private readonly string $target) {}

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Name && ltrim($node->toString(), '\\') === $this->target) {
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
