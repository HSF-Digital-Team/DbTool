<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Storage;

use Exception;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\File\Csv;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Framework\Phrase;
use ZipArchive;

/**
 * Owns the on-disk layout and CSV dialect for both export and import, so the two sides always
 * agree. One CSV per table under var/{export|import}/specialcustomer/<customer_id>/.
 */
class CsvStorage
{
    private const string EXPORT_BASE = 'export/specialcustomer';
    private const string IMPORT_BASE = 'import/specialcustomer';

    /**
     * @var WriteInterface|null $varDirectory
     */
    private ?WriteInterface $varDirectory = null;

    /**
     * Constructor
     *
     * @param Filesystem $filesystem
     * @param Csv $csv
     */
    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly Csv $csv
    ) {
    }

    /**
     * PrepareExportDir
     *
     * (Re)create an empty export directory for the customer and return its var-relative path.
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
        foreach (glob($sourceDir . '/*.csv') ?: [] as $file) {
            $zip->addFile($file, basename($file));
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
     */
    public function dirExists(string $absoluteDir): bool
    {
        return is_dir($absoluteDir);
    }

    /**
     * WriteTable
     *
     * Write one table's rows to <table>.csv. Row 0 is the header. When $rows is empty a
     * header-only file is written so the table's presence (and columns) is still recorded.
     *
     * @param string $absoluteDir
     * @param string $table
     * @param string[] $header
     * @param array $rows
     * @throws FileSystemException
     */
    public function writeTable(string $absoluteDir, string $table, array $header, array $rows): void
    {
        $data = [$header];
        foreach ($rows as $row) {
            $line = [];
            foreach ($header as $column) {
                $line[] = $row[$column] ?? null;
            }
            $data[] = $line;
        }
        $this->csv->saveData($absoluteDir . '/' . $table . '.csv', $data);
    }

    /**
     * Read one table's CSV into associative rows, or null when the file is absent.
     *
     * @param string $absoluteDir
     * @param string $table
     * @return array|null
     * @throws Exception
     */
    public function readTable(string $absoluteDir, string $table): ?array
    {
        $file = $absoluteDir . '/' . $table . '.csv';
        if (!is_file($file)) {
            return null;
        }
        $data = $this->csv->getData($file);
        if (empty($data)) {
            return [];
        }
        $header = array_map('strval', array_shift($data));
        $rows = [];
        foreach ($data as $line) {
            $rows[] = array_combine($header, $line);
        }
        return $rows;
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
