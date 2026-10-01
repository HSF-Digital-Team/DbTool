<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Integration;

use EcomEU\DbTool\Api\MapProviderInterface;
use EcomEU\DbTool\Model\Export\Exporter;
use EcomEU\DbTool\Model\Import\Importer;
use EcomEU\DbTool\Model\Storage\CsvStorage;
use EcomEU\DbTool\Test\Integration\Fixture\CustomerFootprint;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use PHPUnit\Framework\TestCase;

/**
 * Base test case: every test runs inside a database transaction that is rolled back afterwards
 * (the engine's own transaction nests inside it), and the export/import directories it touched
 * are removed.
 */
abstract class AbstractDbToolTestCase extends TestCase
{
    /** @var ResourceConnection */
    protected ResourceConnection $resource;
    /** @var AdapterInterface */
    protected AdapterInterface $connection;
    /** @var Exporter */
    protected Exporter $exporter;
    /** @var Importer */
    protected Importer $importer;
    /** @var CsvStorage */
    protected CsvStorage $storage;
    /** @var MapProviderInterface */
    protected MapProviderInterface $map;
    /** @var CustomerFootprint the footprint every test starts with */
    protected CustomerFootprint $fixture;
    /** @var WriteInterface the var/ directory */
    private WriteInterface $var;

    /** @var int[] customer ids whose var/{export,import}/specialcustomer/<id> dirs must be removed */
    private array $touchedIds = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $om = Magento::objectManager();
        $this->resource = $om->get(ResourceConnection::class);
        $this->connection = $this->resource->getConnection();
        $this->exporter = $om->get(Exporter::class);
        $this->importer = $om->get(Importer::class);
        $this->storage = $om->get(CsvStorage::class);
        $this->map = $om->get(MapProviderInterface::class);
        $this->var = $om->get(Filesystem::class)->getDirectoryWrite(DirectoryList::VAR_DIR);

        $this->connection->beginTransaction();
        $this->fixture = (new CustomerFootprint($this->resource))->create();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->connection->rollBack();
        foreach (array_unique($this->touchedIds) as $id) {
            foreach (['export/specialcustomer/' . $id, 'export/specialcustomer/' . $id . '.zip',
                         'import/specialcustomer/' . $id] as $path) {
                if ($this->var->isExist($path)) {
                    $this->var->delete($path);
                }
            }
        }
    }

    /**
     * Export one customer, remembering the directory for cleanup.
     *
     * @param int $customerId
     * @return array{directory:string,tables:array<string,int>,absent:string[]}
     */
    protected function exportCustomer(int $customerId): array
    {
        $this->touchedIds[] = $customerId;
        return $this->exporter->export($customerId);
    }

    /**
     * Copy an export directory to the import location and return the import directory.
     *
     * @param int $customerId
     * @return string absolute import directory
     */
    protected function stageForImport(int $customerId): string
    {
        $this->touchedIds[] = $customerId;
        $source = 'export/specialcustomer/' . $customerId;
        $target = 'import/specialcustomer/' . $customerId;
        if ($this->var->isExist($target)) {
            $this->var->delete($target);
        }
        $this->var->create($target);
        foreach ($this->var->read($source) as $file) {
            if (str_ends_with($file, '.csv')) {
                $this->var->copyFile($file, $target . '/' . basename($file));
            }
        }
        return $this->var->getAbsolutePath($target);
    }

    /**
     * Rewrite one staged CSV through a row callback (to change business keys before an import).
     *
     * @param string $importDir
     * @param string $table
     * @param callable $rewrite fn(array $row): array
     * @return void
     */
    protected function rewriteCsv(string $importDir, string $table, callable $rewrite): void
    {
        $rows = $this->storage->readTable($importDir, $table) ?? [];
        self::assertNotEmpty($rows, "$table.csv should have rows to rewrite");
        $rows = array_map($rewrite, $rows);
        $this->storage->writeTable($importDir, $table, array_map('strval', array_keys($rows[0])), $rows);
    }

    /**
     * Fetch one row by a single column value, or null.
     *
     * @param string $table
     * @param string $column
     * @param mixed $value
     * @return array<string,mixed>|null
     */
    protected function row(string $table, string $column, mixed $value): ?array
    {
        $select = $this->connection->select()
            ->from($this->resource->getTableName($table))
            ->where($this->connection->quoteIdentifier($column) . ' = ?', $value)
            ->limit(1);
        $row = $this->connection->fetchRow($select);
        return $row === false ? null : $row;
    }

    /**
     * Rows matching a single column value.
     *
     * @param string $table
     * @param string $column
     * @param mixed $value
     * @return array<int,array<string,mixed>>
     */
    protected function rows(string $table, string $column, mixed $value): array
    {
        $select = $this->connection->select()
            ->from($this->resource->getTableName($table))
            ->where($this->connection->quoteIdentifier($column) . ' = ?', $value);
        return $this->connection->fetchAll($select);
    }

    /**
     * Number of rows matching a single column value.
     *
     * @param string $table
     * @param string $column
     * @param mixed $value
     * @return int
     */
    protected function countRows(string $table, string $column, mixed $value): int
    {
        return count($this->rows($table, $column, $value));
    }
}
