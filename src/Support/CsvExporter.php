<?php

namespace ME\Abs\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams CSV without loading everything into memory. Cells that a spreadsheet would run as a formula
 * (starting with = + - @ tab or CR) are prefixed with a single quote.
 */
final class CsvExporter
{
    /**
     * @param  array<int, string>  $header
     * @param  callable(callable(array<int, scalar|null>): void): void  $rows  receives a writer
     */
    public static function download(string $filename, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_map([self::class, 'safe'], $header), escape: '');
            $rows(function (array $row) use ($out) {
                fputcsv($out, array_map([self::class, 'safe'], $row), escape: '');
            });
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }

    public static function safe(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
