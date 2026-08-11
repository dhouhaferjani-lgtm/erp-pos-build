<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ProvisioningRequiredPurposesRegistrationRatchetTest extends TestCase
{
    public function test_every_production_throwing_purpose_resolution_site_is_registered(): void
    {
        // Production break caught: a new throwing resolver call is added without manifest registration.
        self::assertSame(ProvisioningRequiredPurposesV1::registeredThrowingCallSites(), $this->scanThrowingSites());
    }

    /** @return list<string> */
    private function scanThrowingSites(): array
    {
        $sites = [];
        $root = dirname(__DIR__, 3).'/app';
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $parser = (new ParserFactory)->createForNewestSupportedVersion();

        foreach ($iterator as $item) {
            if (! $item instanceof SplFileInfo || ! $item->isFile() || $item->getExtension() !== 'php') {
                continue;
            }

            $file = $item->getPathname();
            if (str_ends_with($file, '/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php')) {
                continue;
            }

            $ast = $parser->parse((string) file_get_contents($file)) ?? [];
            $relative = substr($file, strlen(dirname(__DIR__, 3)) + 1);
            $visitor = new class($relative) extends NodeVisitorAbstract
            {
                /** @var list<string> */
                public array $sites = [];

                private string $class = '';

                private string $method = '';

                public function __construct(private readonly string $file) {}

                public function enterNode(Node $node): null
                {
                    if ($node instanceof Node\Stmt\Class_ && $node->name !== null) {
                        $this->class = $node->name->toString();
                    }
                    if ($node instanceof Node\Stmt\ClassMethod) {
                        $this->method = $node->name->toString();
                    }

                    $callee = null;
                    $purposeArgument = null;
                    if ($node instanceof Node\Expr\StaticCall
                        && $node->name instanceof Node\Identifier
                        && $node->name->toString() === 'findByPurposeOrFail') {
                        $callee = 'findByPurposeOrFail';
                        $purposeArgument = $node->args[1]->value ?? null;
                    }
                    if ($node instanceof Node\Expr\MethodCall
                        && $node->name instanceof Node\Identifier
                        && in_array($node->name->toString(), ['getAccountByPurpose', 'findAccountByPurpose'], true)) {
                        $callee = $node->name->toString();
                        $purposeArgument = $node->args[1]->value ?? null;
                    }

                    if ($callee === null) {
                        return null;
                    }

                    $purpose = 'DYNAMIC';
                    if ($purposeArgument instanceof Node\Expr\ClassConstFetch
                        && $purposeArgument->name instanceof Node\Identifier) {
                        $purpose = $purposeArgument->name->toString();
                    }

                    $this->sites[] = sprintf(
                        '%s:%d|%s::%s|%s|%s',
                        $this->file,
                        $node->getStartLine(),
                        $this->class,
                        $this->method,
                        $callee,
                        $purpose,
                    );

                    return null;
                }
            };
            $traverser = new NodeTraverser;
            $traverser->addVisitor($visitor);
            $traverser->traverse($ast);
            array_push($sites, ...$visitor->sites);
        }

        sort($sites);

        return $sites;
    }
}
