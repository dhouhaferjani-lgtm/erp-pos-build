<?php

declare(strict_types=1);
use App\Shared\TypeScript\GlobalNamespaceWriter;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Support\TypeScriptTransformer\DataTypeScriptTransformer;
use Spatie\TypeScriptTransformer\Collectors\DefaultCollector;
use Spatie\TypeScriptTransformer\Collectors\EnumCollector;
use Spatie\TypeScriptTransformer\Transformers\DtoTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;

return [
    /*
     * The paths where the typescript-transformer will look for classes to transform.
     * By default, this is the app/Modules path where all DTOs live.
     */
    'auto_discover_transformers' => [
        app_path('Modules'),
        app_path('Shared'),
    ],

    /*
     * Transformers will transform PHP classes to TypeScript types.
     *
     * DataTypeScriptTransformer (from spatie/laravel-data) reads the
     * #[DataCollectionOf(Foo::class)] attribute and the spatie/laravel-data
     * DataConfig metadata, so `DataCollection<T>` properties emit `Array<T>`
     * instead of the generic DtoTransformer's `Array<any>`. This keeps
     * emitted types in lock-step with the actual JSON wire format.
     */
    'transformers' => [
        EnumTransformer::class,
        DataTypeScriptTransformer::class,
        DtoTransformer::class,
    ],

    /*
     * The collector will search for classes in the auto_discover_transformers paths.
     */
    'collectors' => [
        DefaultCollector::class,
        EnumCollector::class,
    ],

    /*
     * The path where the generated TypeScript file will be saved.
     * This goes to the shared package for frontend consumption.
     *
     * .d.ts extension + GlobalNamespaceWriter wrap in `declare global { … }`
     * makes every `App.Modules.*` / `App.Enums.*` namespace globally
     * available in apps/web without any explicit import.
     */
    'output_file' => base_path('../../packages/shared/types/generated.d.ts'),

    /*
     * Custom writer that wraps the default TypeDefinitionWriter output in
     * `declare global { … } export {};` so the nested namespace hierarchy
     * survives `moduleDetection: "force"` in apps/web/tsconfig.json.
     */
    'writer' => GlobalNamespaceWriter::class,

    /*
     * The default TypeScript type for PHP types that cannot be transformed.
     */
    'default_type_replacements' => [
        DateTime::class => 'string',
        DateTimeImmutable::class => 'string',
        Carbon\Carbon::class => 'string',
        CarbonImmutable::class => 'string',
        Illuminate\Support\Carbon::class => 'string',
    ],
];
