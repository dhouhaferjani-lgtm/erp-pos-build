<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\TypeScript;

use App\Shared\Application\DTOs\PaginationData;
use ReflectionClass;
use Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer;
use Spatie\TypeScriptTransformer\Transformers\DtoTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Tests\Fixtures\TypeScript\ParentData;
use Tests\TestCase;

/**
 * Guards that Spatie Laravel Data's DataTypeScriptTransformer correctly
 * resolves `DataCollection<T>` / `#[DataCollectionOf]` properties to
 * `Array<T>` instead of the generic transformer's `Array<any>`.
 *
 * The configured transformer in config/typescript-transformer.php must be
 * the Data-aware one, otherwise DataCollection<T> fields silently lose
 * their element type — a compliance-material drift surface across 40+
 * production DTOs.
 */
final class DataCollectionLoweringTest extends TestCase
{
    public function test_data_aware_transformer_precedes_the_generic_fallback(): void
    {
        $configured = config('typescript-transformer.transformers');
        self::assertIsArray($configured);

        $dataIdx = array_search(DataTypeScriptTransformer::class, $configured, true);
        $genericIdx = array_search(DtoTransformer::class, $configured, true);

        self::assertNotFalse(
            $dataIdx,
            'DataTypeScriptTransformer must be registered — without it, '
                .'DataCollection<T> properties lower to Array<any>.'
        );

        // If the generic DtoTransformer is present as a fallback, it MUST come
        // after the Data-aware one — its canTransform() is unconditional and
        // would otherwise swallow every Data-subclass before the Data-aware
        // transformer ever runs. That silently reintroduces the bug.
        if ($genericIdx !== false) {
            self::assertLessThan(
                $genericIdx,
                $dataIdx,
                'DataTypeScriptTransformer must precede the generic DtoTransformer '
                    .'in config/typescript-transformer.php → transformers.'
            );
        }
    }

    public function test_data_aware_transformer_resolves_data_collection_of_element_type(): void
    {
        $config = TypeScriptTransformerConfig::create();
        $transformer = new DataTypeScriptTransformer($config);

        $transformed = $transformer->transform(
            new ReflectionClass(ParentData::class),
            'ParentData'
        );

        self::assertNotNull(
            $transformed,
            'ParentData must be transformable by DataTypeScriptTransformer'
        );

        $ts = $transformed->transformed;

        self::assertStringContainsString('label: string', $ts);
        self::assertStringContainsString(
            'ChildData',
            $ts,
            'children property must reference the element type ChildData,'
                .' not lower to Array<any>'
        );
        self::assertStringNotContainsString(
            'Array<any>',
            $ts,
            'children property must not emit Array<any> — element type is'
                .' declared via #[DataCollectionOf(ChildData::class)]'
        );
    }

    public function test_non_data_tagged_classes_still_transform_via_generic_fallback(): void
    {
        // PaginationData is tagged with #[TypeScript] but does NOT extend
        // Spatie\LaravelData\Data — DataTypeScriptTransformer::canTransform()
        // rejects it, so the pipeline MUST fall through to the generic
        // DtoTransformer. Without the fallback, the whole transform command
        // fails with TransformerNotFound.
        $config = TypeScriptTransformerConfig::create();
        $transformer = new DtoTransformer($config);

        $transformed = $transformer->transform(
            new ReflectionClass(PaginationData::class),
            'PaginationData'
        );

        self::assertNotNull(
            $transformed,
            'Generic DtoTransformer must handle non-Data #[TypeScript]-tagged classes'
        );

        $ts = $transformed->transformed;
        self::assertStringContainsString('page: number', $ts);
        self::assertStringContainsString('perPage: number', $ts);
        self::assertStringContainsString('hasNextPage: boolean', $ts);
    }
}
