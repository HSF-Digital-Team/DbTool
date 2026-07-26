<?php
declare(strict_types=1);

namespace HenrySchein\DbTool\Api;

/**
 * Optional per-table hook for special handling that cannot be expressed declaratively in the map
 * (e.g. scrubbing password hashes, dropping generated columns, remapping values).
 *
 * A definition references an implementation by FQCN in its "processor" field; the engine invokes
 * the matching hook around each table's rows. Implementations must be side-effect free with respect
 * to the DB — they only transform the row arrays.
 */
interface TableProcessorInterface
{
    /**
     * Transform rows read from the DB before they are written to CSV.
     *
     * @param string $table
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function processExportRows(string $table, array $rows): array;

    /**
     * Transform rows read from CSV before they are written to the DB.
     *
     * @param string $table
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    public function processImportRows(string $table, array $rows): array;
}
