<?php

declare(strict_types=1);

namespace App\Billing\Export;

use App\Billing\Export\Contracts\ExportDataset;
use App\Billing\Export\Contracts\RowEncoder;
use App\Billing\Export\Encoders\RowEncoderFactory;
use App\Billing\Export\Enums\ExportFormat;
use App\Billing\Export\ValueObjects\ExportQuery;
use App\Billing\Export\ValueObjects\PumpResult;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single streaming seam every export flows through. {@see pump()} drives one dataset's rows
 * through an encoder to an arbitrary byte sink (an HTTP response, an object-store stream),
 * tallying rows, bytes and the cursor window as it goes — nothing is buffered, so the memory
 * ceiling is one chunk regardless of dataset size. {@see download()} wraps that in a
 * {@see StreamedResponse} for the console; the warehouse sink reuses {@see pump()} directly.
 */
class DataExporter
{
    public function __construct(
        private readonly DatasetRegistry $registry,
        private readonly RowEncoderFactory $encoders,
    ) {}

    /**
     * Stream a dataset through an encoder to `$write`, one chunk at a time. Emits the header (if
     * any) once, then each encoded row, returning the {@see PumpResult} tally (row/byte counts
     * and the min/max cursor seen — the watermark an incremental sync advances to).
     *
     * @param  callable(string): void  $write
     */
    public function pump(ExportDataset $dataset, RowEncoder $encoder, ExportQuery $query, callable $write): PumpResult
    {
        $schema = $dataset->schema();
        $cursor = $dataset->cursor();

        $rows = 0;
        $bytes = 0;
        $from = null;
        $to = null;

        $header = $encoder->header($schema);
        if ($header !== null) {
            $write($header);
            $bytes += strlen($header);
        }

        foreach ($dataset->rows($query) as $row) {
            $line = $encoder->encode($row->data, $schema);
            $write($line);

            $rows++;
            $bytes += strlen($line);

            if ($row->cursor !== null) {
                $from ??= $row->cursor;
                if ($cursor->kind->greater($row->cursor, $to)) {
                    $to = $row->cursor;
                }
            }
        }

        return new PumpResult($rows, $bytes, $from, $to);
    }

    /**
     * A streamed download of a dataset in a format, scoped by the query. The response streams
     * straight from the database cursor — the whole dataset is never assembled in memory.
     */
    public function download(string $datasetKey, ExportFormat $format, ExportQuery $query): StreamedResponse
    {
        $dataset = $this->registry->get($datasetKey);
        $encoder = $this->encoders->for($format);

        $response = new StreamedResponse(function () use ($dataset, $encoder, $query): void {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            $this->pump($dataset, $encoder, $query, static function (string $chunk) use ($out): void {
                fwrite($out, $chunk);
            });

            fclose($out);
        });

        $response->headers->set('Content-Type', $encoder->contentType());
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$this->filename($dataset, $format, $query).'"');
        $response->headers->set('X-Accel-Buffering', 'no');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');

        return $response;
    }

    /** A stable, descriptive download filename: `<dataset>_<plane>_<date>.<ext>`. */
    private function filename(ExportDataset $dataset, ExportFormat $format, ExportQuery $query): string
    {
        return sprintf(
            '%s_%s_%s.%s',
            $dataset->key(),
            $query->livemode ? 'live' : 'test',
            now()->format('Ymd_His'),
            $format->extension(),
        );
    }
}
