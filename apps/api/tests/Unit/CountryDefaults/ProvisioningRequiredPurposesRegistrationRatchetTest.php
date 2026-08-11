<?php

declare(strict_types=1);

namespace Tests\Unit\CountryDefaults;

use App\Modules\CountryDefaults\Domain\Services\ProvisioningRequiredPurposesV1;
use PhpParser\Node;
use PhpParser\NodeFinder;
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

    public function test_scanner_follows_a_renamed_throwing_wrapper_in_a_non_app_root(): void
    {
        $root = sys_get_temp_dir().'/country-defaults-throwing-'.bin2hex(random_bytes(8));
        $fixture = $root.'/database/seeders/RenamedPurposeLookup.php';
        mkdir(dirname($fixture), 0777, true);
        file_put_contents($fixture, <<<'PHP'
<?php

final class RenamedPurposeLookup
{
    private function resolveLedgerSlot(string $company, object $purpose): object
    {
        return Account::findByPurposeOrFail($company, $purpose);
    }

    public function run(string $company): object
    {
        return $this->resolveLedgerSlot($company, SystemAccountPurpose::Bank);
    }
}
PHP);

        try {
            $sites = $this->scanThrowingSites([$root]);
            self::assertTrue(
                array_any($sites, fn (string $site): bool => str_contains($site, '|RenamedPurposeLookup::run|resolveLedgerSlot|Bank')),
                implode("\n", $sites),
            );
        } finally {
            unlink($fixture);
            rmdir(dirname($fixture));
            rmdir(dirname(dirname($fixture)));
            rmdir($root);
        }
    }

    /**
     * @param  list<string>|null  $roots
     * @return list<string>
     */
    private function scanThrowingSites(?array $roots = null): array
    {
        $apiRoot = dirname(__DIR__, 3);
        $roots ??= array_map(
            static fn (string $root): string => $apiRoot.'/'.$root,
            ['app', 'routes', 'config', 'database', 'bootstrap'],
        );
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $calls = [];
        $methods = [];

        foreach ($roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
            foreach ($iterator as $item) {
                if (! $item instanceof SplFileInfo || ! $item->isFile() || $item->getExtension() !== 'php') {
                    continue;
                }

                $file = $item->getPathname();
                if (str_ends_with($file, '/Modules/Compliance/Services/UninvoicedDeliveryNoteService.php')) {
                    continue;
                }

                $ast = $parser->parse((string) file_get_contents($file)) ?? [];
                $relative = str_starts_with($file, $apiRoot.'/')
                    ? substr($file, strlen($apiRoot) + 1)
                    : substr($file, strlen($root) + 1);
                $visitor = new class($relative) extends NodeVisitorAbstract
                {
                    /** @var list<array{file: string, line: int, class: string, method: string, callee: string, purpose: string}> */
                    public array $calls = [];

                    /** @var array<string, array{direct_callee: ?string, semantic_throw: bool}> */
                    public array $methods = [];

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
                            $onlyStatement = count($node->stmts ?? []) === 1 ? $node->stmts[0] : null;
                            $returned = $onlyStatement instanceof Node\Stmt\Return_ ? $onlyStatement->expr : null;
                            $directCallee = null;
                            if (($returned instanceof Node\Expr\StaticCall || $returned instanceof Node\Expr\MethodCall)
                                && $returned->name instanceof Node\Identifier) {
                                $directCallee = $returned->name->toString();
                            }
                            $finder = new NodeFinder;
                            $hasThrow = $finder->findFirstInstanceOf($node->stmts ?? [], Node\Expr\Throw_::class) !== null;
                            $hasPurposeQuery = $finder->findFirst(
                                $node->stmts ?? [],
                                static fn (Node $candidate): bool => $candidate instanceof Node\Scalar\String_
                                    && $candidate->value === 'system_purpose',
                            ) !== null;
                            $returnsAccount = $node->returnType instanceof Node\Name
                                && $node->returnType->getLast() === 'Account';
                            $this->methods[$this->class.'::'.$this->method] = [
                                'direct_callee' => $directCallee,
                                'semantic_throw' => $hasThrow && $hasPurposeQuery && $returnsAccount,
                            ];
                        }

                        $callee = null;
                        $purposeArgument = null;
                        if (($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall)
                            && $node->name instanceof Node\Identifier) {
                            $callee = $node->name->toString();
                            $purposeArgument = $node->args[1]->value ?? null;
                        }

                        if ($callee === null || $this->method === '') {
                            return null;
                        }

                        $purpose = 'DYNAMIC';
                        if ($purposeArgument instanceof Node\Expr\ClassConstFetch
                            && $purposeArgument->name instanceof Node\Identifier) {
                            $purpose = $purposeArgument->name->toString();
                        }

                        $this->calls[] = [
                            'file' => $this->file,
                            'line' => $node->getStartLine(),
                            'class' => $this->class,
                            'method' => $this->method,
                            'callee' => $callee,
                            'purpose' => $purpose,
                        ];

                        return null;
                    }
                };
                $traverser = new NodeTraverser;
                $traverser->addVisitor($visitor);
                $traverser->traverse($ast);
                array_push($calls, ...$visitor->calls);
                $methods = array_merge($methods, $visitor->methods);
            }
        }

        // The model's primitive explicitly promises to throw. Thin wrappers are
        // discovered from their body, so a rename cannot hide a new call family.
        $throwingCallees = ['findByPurposeOrFail' => true];
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($methods as $signature => $method) {
                [, $methodName] = explode('::', $signature, 2);
                $wrapsKnownThrower = $method['direct_callee'] !== null
                    && isset($throwingCallees[$method['direct_callee']]);
                if (! $method['semantic_throw'] && ! $wrapsKnownThrower) {
                    continue;
                }
                if (! isset($throwingCallees[$methodName])) {
                    $throwingCallees[$methodName] = true;
                    $changed = true;
                }
            }
        }

        $sites = [];
        foreach ($calls as $call) {
            if (! isset($throwingCallees[$call['callee']])) {
                continue;
            }
            $sites[] = sprintf(
                '%s:%d|%s::%s|%s|%s',
                $call['file'],
                $call['line'],
                $call['class'],
                $call['method'],
                $call['callee'],
                $call['purpose'],
            );
        }

        sort($sites);

        return $sites;
    }
}
