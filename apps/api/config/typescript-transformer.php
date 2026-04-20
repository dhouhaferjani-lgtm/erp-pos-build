<?php

declare(strict_types=1);
use Carbon\CarbonImmutable;
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
     */
    'transformers' => [
        EnumTransformer::class,
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
     */
    'output_file' => base_path('../../packages/shared/types/generated.ts'),

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
