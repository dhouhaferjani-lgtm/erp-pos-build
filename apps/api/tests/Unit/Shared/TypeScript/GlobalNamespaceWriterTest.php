<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\TypeScript;

use App\Shared\TypeScript\GlobalNamespaceWriter;
use PHPUnit\Framework\TestCase;
use Spatie\TypeScriptTransformer\Structures\TransformedType;
use Spatie\TypeScriptTransformer\Structures\TypesCollection;

final class GlobalNamespaceWriterTest extends TestCase
{
    public function test_it_wraps_output_in_declare_global_with_module_marker_even_when_collection_is_empty(): void
    {
        $collection = TypesCollection::create();
        $writer = new GlobalNamespaceWriter;

        $output = $writer->format($collection);

        self::assertStringStartsWith("declare global {\n", $output);
        self::assertStringEndsWith("}\n\nexport {};\n", $output);
    }

    public function test_it_preserves_namespaced_blocks_produced_by_parent_writer(): void
    {
        $collection = TypesCollection::create();
        $reflection = new \ReflectionClass(TransformedType::class);
        $collection[$reflection->getName()] = TransformedType::create(
            $reflection,
            'TransformedType',
            '{ foo: string }',
        );

        $writer = new GlobalNamespaceWriter;
        $output = $writer->format($collection);

        self::assertStringContainsString(
            'declare namespace Spatie.TypeScriptTransformer.Structures {',
            $output,
            'nested declare namespace block from parent writer must survive the wrap'
        );
        self::assertStringContainsString(
            'export type TransformedType = { foo: string };',
            $output
        );
        self::assertStringStartsWith("declare global {\n", $output);
        self::assertStringEndsWith("}\n\nexport {};\n", $output);
    }
}
