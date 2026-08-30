<?php

declare(strict_types=1);

namespace Tests\Architecture\Support;

final class TenantOnlyUniqueRatchetChecker
{
    /**
     * @param  list<TenantOnlyUniqueIndex>  $liveIndexes
     * @param  list<TenantOnlyUniqueBaselineEntry>  $baseline
     */
    public function check(array $liveIndexes, array $baseline): TenantOnlyUniqueRatchetReport
    {
        $liveByKey = [];
        foreach ($liveIndexes as $index) {
            $liveByKey[$index->key()] = $index;
        }

        $baselineByKey = [];
        foreach ($baseline as $entry) {
            $baselineByKey[$entry->key] = $entry;
        }

        $growth = [];
        foreach ($liveByKey as $key => $index) {
            if (isset($baselineByKey[$key])) {
                continue;
            }

            $growth[] = sprintf(
                'new tenant-only unique on catalogue table %s (index %s) — add company_id to the key, or add it to the baseline with a `waiver` reason field',
                $index->tableName,
                $index->indexName,
            );
        }

        $stale = [];
        foreach ($baselineByKey as $key => $entry) {
            if (! isset($liveByKey[$key])) {
                $stale[] = 'baseline entry no longer in schema — remove it (a lane fixed it): '.$entry->key;
            }
        }

        return new TenantOnlyUniqueRatchetReport($growth, $stale);
    }
}
