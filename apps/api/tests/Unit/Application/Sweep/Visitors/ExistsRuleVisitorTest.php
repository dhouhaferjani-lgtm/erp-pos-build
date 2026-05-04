<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Sweep\Visitors;

use App\Application\Sweep\Visitors\ExistsRuleVisitor;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see ExistsRuleVisitor::checkInlineString()} pipe-form
 * detection.
 *
 * Opus Treasury Finding 1 (2026-05-04): the visitor previously matched only
 * inline strings whose value started with `exists:`. Pipe-form Laravel rules
 * such as `'required|exists:payment_methods,id'` start with `required|...` so
 * they were invisible to the scanner — and to the inventory generator that
 * shares the visitor — even though they validate against guarded tables.
 *
 * The fix: split the inline string on `|`, iterate fragments, and apply the
 * `str_starts_with($fragment, 'exists:')` check per fragment. Already-passing
 * single-fragment inputs and the `Rule::exists(...)` builder branch must keep
 * their existing behavior.
 */
final class ExistsRuleVisitorTest extends TestCase
{
    /**
     * @param  list<string>  $guardedTables
     * @return list<array{int, string, string}> list of [line, table, form]
     */
    private function runVisitor(string $code, array $guardedTables): array
    {
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $stmts = $parser->parse($code);
        if ($stmts === null) {
            return [];
        }

        $traverser = new NodeTraverser;
        $traverser->addVisitor(new ParentConnectingVisitor);
        $visitor = new ExistsRuleVisitor($guardedTables);
        $traverser->addVisitor($visitor);
        $traverser->traverse($stmts);

        return array_map(
            static fn (array $v): array => [$v['line'], $v['table'], $v['form']],
            $visitor->violations,
        );
    }

    public function test_pipe_form_required_then_exists_is_flagged(): void
    {
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'payment_method_id' => 'required|exists:payment_methods,id',
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['payment_methods']);

        $this->assertCount(1, $violations);
        $this->assertSame('payment_methods', $violations[0][1]);
        $this->assertSame('inline_string', $violations[0][2]);
    }

    public function test_pipe_form_exists_then_nullable_is_flagged(): void
    {
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'payment_method_id' => 'exists:payment_methods,id|nullable',
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['payment_methods']);

        $this->assertCount(1, $violations);
        $this->assertSame('payment_methods', $violations[0][1]);
        $this->assertSame('inline_string', $violations[0][2]);
    }

    public function test_pipe_form_three_segments_with_exists_in_middle_is_flagged(): void
    {
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'partner_id' => 'sometimes|nullable|exists:partners,id',
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['partners']);

        $this->assertCount(1, $violations);
        $this->assertSame('partners', $violations[0][1]);
    }

    public function test_pipe_form_exists_on_non_guarded_table_is_not_flagged(): void
    {
        // The visitor must only flag GUARDED tables. Pipe-form on a non-guarded
        // table is admissible (e.g. cross-module FK that the master plan
        // exempts from tenant-scoping requirements at this layer).
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'currency' => 'required|exists:currencies,code',
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['payment_methods', 'partners']);

        $this->assertSame([], $violations);
    }

    public function test_already_passing_bare_exists_string_still_flagged(): void
    {
        // Regression guard for the original surface — single-fragment inline
        // strings (no leading `required|`) must keep being detected.
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'payment_method_id' => ['required', 'exists:payment_methods,id'],
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['payment_methods']);

        $this->assertCount(1, $violations);
        $this->assertSame('payment_methods', $violations[0][1]);
    }

    public function test_array_form_on_non_guarded_table_is_not_flagged(): void
    {
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'currency_id' => ['required', 'exists:currencies,id'],
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['payment_methods', 'partners']);

        $this->assertSame([], $violations);
    }

    public function test_pipe_form_table_segment_with_columns_after_id_is_flagged(): void
    {
        // Defensive — Laravel allows `exists:table,column,additional_where_clause`.
        // Splitting on `,` and taking the 0-th part still yields the table name.
        $code = <<<'PHP'
        <?php
        class Foo {
            public function rules(): array {
                return [
                    'payment_method_id' => 'required|exists:payment_methods,id,is_active,1',
                ];
            }
        }
        PHP;

        $violations = $this->runVisitor($code, ['payment_methods']);

        $this->assertCount(1, $violations);
        $this->assertSame('payment_methods', $violations[0][1]);
    }
}
