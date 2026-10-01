<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Storage;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Phrase;
use Magento\Framework\Filesystem\Glob;
use ZipArchive;

/**
 * Owns the on-disk layout and CSV dialect for both export and import, so the two sides always
 * agree. One CSV per table under var/{export|import}/specialcustomer/<customer_id>/.
 *
 * The CSV is written and read directly (RFC 4180: comma, double-quote enclosure, no escape
 * character) rather than through Magento\Framework\File\Csv: the framework's writer prefixes any
 * value starting with "=", "+" or "-" with a space as spreadsheet-injection hardening, which would
 * corrupt phone numbers ("+33 6…") and other text on the round trip.
 */
class CsvStorage
{
    private const string EXPORT_BASE = 'export/specialcustomer';
    private const string IMPORT_BASE = 'import/specialcustomer';

    private const string DELIMITER = ',';
    private const string ENCLOSURE = '"';
    /** No escape character: enclosures are doubled, so any byte sequence survives the round trip. */
    private const string ESCAPE = '';

    /**
     * CSV representation of SQL NULL (MySQL's own convention), so that NULL and the empty string
     * survive the round trip as distinct values.
     */
    public const string NULL_MARKER = '\\N';

    /**
     * @var WriteInterface|null $varDirectory
     */
    private ?WriteInterface $varDirectory = null;

    /**
     * Constructor
     *
     * @param Filesystem $filesystem
     * @param FileDriver $fileDriver
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly FileDriver $fileDriver
    ) {
    }

    /**
     * PrepareExportDir
     *
     * (Re)create an empty export directory for the customer and return its absolute path.
     *
     * @param int $customerId
     * @return string
     * @throws FileSystemException
     */
    public function prepareExportDir(int $customerId): string
    {
        $relDir = self::EXPORT_BASE . '/' . $customerId;
        $var = $this->getVarDirectory();
        if ($var->isExist($relDir)) {
            $var->delete($relDir);
        }
        $var->create($relDir);
        return $var->getAbsolutePath($relDir);
    }

    /**
     * Remove the customer's export directory (used when an export turns out empty).
     *
     * @param int $customerId
     * @return void
     * @throws FileSystemException
     */
    public function removeExportDir(int $customerId): void
    {
        $relDir = self::EXPORT_BASE . '/' . $customerId;
        $var = $this->getVarDirectory();
        if ($var->isExist($relDir)) {
            $var->delete($relDir);
        }
    }

    /**
     * Zip Export
     *
     * Bundle every CSV in the customer's export directory into a single sibling zip
     * (var/export/specialcustomer/<id>.zip) with flat entry names. Returns its absolute path.
     *
     * @param int $customerId
     * @return string
     * @throws LocalizedException
     */
    public function zipExport(int $customerId): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new LocalizedException(new Phrase(
                'Tool_DbTool: the PHP zip extension (ext-zip) is required to create the export archive.'
            ));
        }

        $var = $this->getVarDirectory();
        $sourceDir = $var->getAbsolutePath(self::EXPORT_BASE . '/' . $customerId);
        $zipPath = $var->getAbsolutePath(self::EXPORT_BASE . '/' . $customerId . '.zip');

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new LocalizedException(new Phrase('Tool_DbTool: cannot create zip archive: %1', [$zipPath]));
        }
        foreach (Glob::glob($sourceDir . '/*.csv') ?: [] as $file) {
            $zip->addFile($file, substr($file, strlen($sourceDir) + 1));
        }
        $zip->close();

        return $zipPath;
    }

    /**
     * Var-relative import directory path (may or may not exist).
     *
     * @param int $customerId
     * @return string
     * @throws FileSystemException
     */
    public function getImportDir(int $customerId): string
    {
        return $this->getVarDirectory()->getAbsolutePath(self::IMPORT_BASE . '/' . $customerId);
    }

    /**
     * True when the given absolute directory exists.
     *
     * @param string $absoluteDir
     * @return bool
     * @throws FileSystemException
     */
    public function dirExists(string $absoluteDir): bool
    {
        return $this->fileDriver->isDirectory($absoluteDir);
    }

    /**
     * WriteTable
     *
     * Write one table's rows to <table>.csv. Row 0 is the header. When $rows is empty a
     * header-only file is written so the table's presence (and columns) is still recorded.
     * NULL is written as the NULL_MARKER; a missing column is treated as NULL.
     *
     * @param string $absoluteDir
     * @param string $table
     * @param string[] $header
     * @param array<int,array<string,mixed>> $rows
     * @throws FileSystemException
     */
    public function writeTable(string $absoluteDir, string $table, array $header, array $rows): void
    {
        $file = $absoluteDir . '/' . $table . '.csv';
        $resource = $this->fileDriver->fileOpen($file, 'w');
        try {
            $this->putLine($resource, $header, $file);
            foreach ($rows as $row) {
                $line = [];
                foreach ($header as $column) {
                    $value = $row[$column] ?? null;
                    $line[] = $value === null ? self::NULL_MARKER : (string)$value;
                }
                $this->putLine($resource, $line, $file);
            }
        } finally {
            $this->fileDriver->fileClose($resource);
        }
    }

    /**
     * Write one CSV line.
     *
     * Deliberately not DriverInterface::filePutCsv(): see the class docblock.
     *
     * @param resource $resource
     * @param string[] $line
     * @param string $file
     * @throws FileSystemException
     */
    private function putLine($resource, array $line, string $file): void
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        if (fputcsv($resource, $line, self::DELIMITER, self::ENCLOSURE, self::ESCAPE) === false) {
            throw new FileSystemException(new Phrase('Tool_DbTool: cannot write to %1', [$file]));
        }
    }

    /**
     * Read one table's CSV into associative rows, or null when the file is absent.
     *
     * Tolerates hand-edited files: blank lines are ignored, a short line is padded with NULLs and
     * extra cells beyond the header are dropped. The NULL_MARKER reads back as NULL; a plain empty
     * cell stays an empty string (the importer turns it into NULL only for nullable columns, which
     * keeps files written before the marker existed importable).
     *
     * @param string $absoluteDir
     * @param string $table
     * @return array<int,array<string,mixed>>|null
     * @throws FileSystemException
     */
    public function readTable(string $absoluteDir, string $table): ?array
    {
        $file = $absoluteDir . '/' . $table . '.csv';
        if (!$this->fileDriver->isFile($file)) {
            return null;
        }
        $resource = $this->fileDriver->fileOpen($file, 'r');
        try {
            $header = null;
            $width = 0;
            $rows = [];
            while (($line = $this->getLine($resource)) !== false) {
                // fgetcsv() yields [null] for a blank line.
                if ($line === [null] || $line === [] || $line === ['']) {
                    continue;
                }
                if ($header === null) {
                    $header = array_map('strval', $line);
                    $width = count($header);
                    continue;
                }
                $line = array_slice(array_pad($line, $width, null), 0, $width);
                $row = [];
                foreach ($header as $index => $column) {
                    $value = $line[$index];
                    $row[$column] = ($value === self::NULL_MARKER) ? null : $value;
                }
                $rows[] = $row;
            }
        } finally {
            $this->fileDriver->fileClose($resource);
        }
        return $rows;
    }

    /**
     * Read one CSV line, or false at end of file.
     *
     * @param resource $resource
     * @return array<int,string|null>|false
     * @throws FileSystemException
     */
    private function getLine($resource): array|false
    {
        $line = $this->fileDriver->fileGetCsv($resource, 0, self::DELIMITER, self::ENCLOSURE, self::ESCAPE);
        return is_array($line) ? $line : false;
    }

    /**
     * Get the var directory for writing CSV files.
     *
     * @return WriteInterface
     * @throws FileSystemException
     */
    private function getVarDirectory(): WriteInterface
    {
        if ($this->varDirectory === null) {
            $this->varDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        }
        return $this->varDirectory;
    }
}
