<?php

declare(strict_types=1);

namespace App\Modules\Workshop\Technician\Application\DTOs;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class WeeklyScheduleData extends Data
{
    /**
     * @param  DataCollection<int, ScheduleWindowData>  $mon
     * @param  DataCollection<int, ScheduleWindowData>  $tue
     * @param  DataCollection<int, ScheduleWindowData>  $wed
     * @param  DataCollection<int, ScheduleWindowData>  $thu
     * @param  DataCollection<int, ScheduleWindowData>  $fri
     * @param  DataCollection<int, ScheduleWindowData>  $sat
     * @param  DataCollection<int, ScheduleWindowData>  $sun
     */
    public function __construct(
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $mon,
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $tue,
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $wed,
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $thu,
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $fri,
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $sat,
        #[DataCollectionOf(ScheduleWindowData::class)]
        public DataCollection $sun,
    ) {}

    /**
     * @param  array<string, list<array{start: string, end: string}>>  $raw
     */
    public static function fromRaw(array $raw): self
    {
        $build = static function (string $day) use ($raw): DataCollection {
            /** @var list<array{start: string, end: string}> $windows */
            $windows = $raw[$day] ?? [];

            return ScheduleWindowData::collect(
                array_map(
                    static fn (array $w): ScheduleWindowData => new ScheduleWindowData($w['start'], $w['end']),
                    $windows,
                ),
                DataCollection::class,
            );
        };

        return new self(
            mon: $build('mon'),
            tue: $build('tue'),
            wed: $build('wed'),
            thu: $build('thu'),
            fri: $build('fri'),
            sat: $build('sat'),
            sun: $build('sun'),
        );
    }
}
