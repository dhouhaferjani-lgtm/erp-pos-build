<?php

declare(strict_types=1);

namespace App\Modules\Import\Services;

use App\Modules\Import\Domain\Data\ImportJobOptionsData;
use App\Modules\Import\Domain\Data\ImportRowSourceData;
use App\Modules\Import\Domain\Enums\ImportErrorCode;
use App\Modules\Import\Domain\Enums\ImportRowOutcome;
use App\Modules\Import\Domain\ImportJob;
use App\Modules\Import\Domain\ImportRow;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;

/** One row selection and cell contract for both correction-file formats. */
final class ImportRowExportService
{
    /** @var list<string> */
    private const FORMATS = ['csv', 'xlsx'];

    public function generate(ImportJob $job, string $format = 'csv'): ?string
    {
        if (! in_array($format, self::FORMATS, true)) {
            throw new InvalidArgumentException('Unsupported import row export format.');
        }

        $rows = $job->rows()->where(static function (Builder $query): void {
            $query->where('is_valid', false)
                ->orWhereIn('outcome', [ImportRowOutcome::Failed, ImportRowOutcome::OpeningLocked])
                ->orWhere(static fn (Builder $warnings): Builder => $warnings->hasWarnings());
        })->orderBy('row_number')->get();
        if ($rows->isEmpty()) {
            return null;
        }

        $columns = [];
        $options = ImportJobOptionsData::fromStorage($job->options ?? []);
        foreach ($options->sourceHeaders ?? [] as $source) {
            $target = ($job->column_mapping ?? [])[$source] ?? null;
            if ($target !== null && ! in_array($target, $columns, true)) {
                $columns[] = $target;
            }
        }
        foreach ($job->rows()->orderBy('row_number')->cursor() as $row) {
            $source = ImportRowSourceData::fromStorage($row->data);
            foreach ([...$source->provided, ...array_keys($source->values)] as $column) {
                if (! in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }
        $reverse = array_flip($job->column_mapping ?? []);
        if ($reverse !== []) {
            $columns = array_values(array_filter($columns, static fn (string $column): bool => isset($reverse[$column])));
        }
        $headers = array_map(static fn (string $column): string => $reverse[$column] ?? $column, $columns);
        $cells = [[...$headers, '_status', '_code', '_message']];
        foreach ($rows as $row) {
            $source = ImportRowSourceData::fromStorage($row->data);
            $values = array_map(static fn (string $column): string => $source->values[$column] ?? '', $columns);
            $cells[] = [...$values, ...$this->diagnostic($row)];
        }

        // Per REQUEST, not per job: a deterministic per-job name let one finished
        // download delete an artefact another request was still writing, which
        // shipped an empty 200 (gate r2 M3-R). The token makes every artefact
        // private to the request that wrote it, and the bytes land at the final
        // name in one rename so no reader can observe a half-written workbook.
        $path = self::artifactPath($job, $format, (string) Str::uuid());
        $staging = $path.'.part';
        if ($format === 'csv') {
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new RuntimeException('Unable to create import row export.');
            }
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                foreach ($cells as $values) {
                    fputcsv($stream, $values, ',', '"', '', "\r\n");
                }
                rewind($stream);
                $content = stream_get_contents($stream);
                if ($content === false) {
                    throw new RuntimeException('Unable to read import row export.');
                }
                Storage::disk('local')->put($staging, $content);
            } finally {
                fclose($stream);
            }
        } else {
            $spreadsheet = new Spreadsheet;
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Rows to fix');
            foreach ($cells as $rowIndex => $values) {
                foreach ($values as $columnIndex => $value) {
                    $sheet->setCellValueExplicit([$columnIndex + 1, $rowIndex + 1], $value, DataType::TYPE_STRING);
                }
            }
            Storage::disk('local')->makeDirectory('imports/rows');
            try {
                (new Xlsx($spreadsheet))->save(Storage::disk('local')->path($staging));
            } finally {
                $spreadsheet->disconnectWorksheets();
            }
        }

        Storage::disk('local')->move($staging, $path);

        return $path;
    }

    /**
     * Remove every correction artefact a job may have left on disk.
     *
     * Spec 4.10 rules generated exports ephemeral: the file holds the operator's
     * raw rows (partner names and codes, tax ids, balances), so it must not
     * survive the download that produced it, the job's discard, or the retention
     * window. A download deletes its OWN artefact (`deleteFileAfterSend`); this
     * is the prefix sweep for the job as a whole — it covers orphans left by a
     * request that died between generate() and the stream, and it must stay a
     * prefix sweep because artefact names now carry a per-request token.
     */
    public function deleteArtifacts(ImportJob $job): void
    {
        $disk = Storage::disk('local');
        $prefix = self::artifactPrefix($job);
        foreach ($disk->files('imports/rows') as $file) {
            if (str_starts_with($file, $prefix)) {
                $disk->delete($file);
            }
        }
    }

    private static function artifactPrefix(ImportJob $job): string
    {
        return 'imports/rows/'.$job->id.'.';
    }

    private static function artifactPath(ImportJob $job, string $format, string $token): string
    {
        return self::artifactPrefix($job).$token.'.'.$format;
    }

    public function getDownloadUrl(ImportJob $job): string
    {
        return '/imports/'.$job->id.'/failed-rows.csv';
    }

    /** @return array{string, string, string} */
    private function diagnostic(ImportRow $row): array
    {
        if ($row->import_error_code !== null || ! $row->is_valid
            || in_array($row->outcome, [ImportRowOutcome::Failed, ImportRowOutcome::OpeningLocked], true)) {
            $code = $row->import_error_code ?? ($row->is_valid ? ImportErrorCode::InternalError : ImportErrorCode::ValidationFailed);
            $messages = array_values($row->errors ?? []);
            $message = $row->import_error ?? ($messages[0][0] ?? $code->value);

            $accepted = ($row->import_error_detail ?? [])['accepted'] ?? [];
            if (in_array($code, [ImportErrorCode::UnitUnknown, ImportErrorCode::UnitAmbiguous, ImportErrorCode::UnitDefaultMissing], true) && is_array($accepted)) {
                $codes = array_values(array_filter($accepted, 'is_string'));
                if ($codes !== []) {
                    $message .= ' '.__('import.accepted_units', ['codes' => implode(', ', $codes)]);
                }
            }

            return ['error', $code->value, $message];
        }
        $warning = ($row->warnings ?? [])[0] ?? null;
        $code = $warning['code'] ?? ImportErrorCode::InternalError->value;

        return ['warning', $code, $this->warningMessage($code, $warning['detail'] ?? '')];
    }

    /**
     * Operator copy for a warning row.
     *
     * Section 4.10 asks for the translated message for the code, so the coded
     * channel is what is consulted first. The stored per-row detail is the
     * fallback and is deliberately still what most codes resolve to: today's
     * details name the actual values ("provided 12.00 vs derived 11.90"), which a
     * generic per-code sentence would throw away in a file whose whole purpose is
     * telling the operator what to change on THAT row. Seed
     * lang/{en,fr,ar}/import.php `warnings` one code at a time, only with copy
     * that carries at least as much row context.
     */
    private function warningMessage(string $code, string $detail): string
    {
        $key = 'import.warnings.'.$code;
        $translated = __($key);

        return is_string($translated) && $translated !== $key ? $translated : $detail;
    }
}
