<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Export;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Api\MapProviderInterface;
use EcomEU\DbTool\Model\Processor\ProcessorProvider;
use EcomEU\DbTool\Model\Relation\RelationResolver;
use EcomEU\DbTool\Model\Storage\CsvStorage;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Export engine. Walks the customer data map in export-priority order, resolving each table's
 * customer-scoped rows by relationship, and writes one CSV per table. Parent primary keys are
 * collected as tables are processed so that child tables can be scoped without hardcoded logic.
 *
 * All traversal knowledge lives here; nothing about specific tables is hardcoded.
 */
class Exporter
{
    /**
     * @param ResourceConnection $resourceConnection
     * @param MapProviderInterface $mapProvider
     * @param RelationResolver $relationResolver
     * @param ProcessorProvider $processorProvider
     * @param CsvStorage $storage
     */
    public function __construct(
        private ResourceConnection   $resourceConnection,
        private MapProviderInterface $mapProvider,
        private RelationResolver     $relationResolver,
        private ProcessorProvider    $processorProvider,
        private CsvStorage           $storage
    ) {
    }

    /**
     * Export the full footprint of one customer.
     *
     * A mapped table that does not exist on this database (a store-specific table on another
     * install) is skipped and reported, not written. If no table yields a single row the customer
     * does not exist: the empty export directory is removed and an exception is thrown.
     *
     * @param int $customerId
     * @param callable|null $progress fn(string $table, int $rowCount, string $status): void
     * @return array{directory:string,tables:array<string,int>,absent:string[]} directory +
     *         per-table row counts + tables absent on this database
     * @throws LocalizedException
     */
    public function export(int $customerId, ?callable $progress = null): array
    {
        $connection = $this->resourceConnection->getConnection();
        $absoluteDir = $this->storage->prepareExportDir($customerId);

        $definitions = $this->mapProvider->getExportOrder();
        $neededColumns = $this->collectNeededColumns($definitions);

        // collected[table][column] => distinct values, feeding child WHERE ... IN (...) clauses.
        $collected = [];
        $results = [];
        $absent = [];

        foreach ($definitions as $definition) {
            $table = $definition->getTable();
            if (!$connection->isTableExists($this->resourceConnection->getTableName($table))) {
                $absent[] = $table;
                if ($progress !== null) {
                    $progress($table, 0, 'absent');
                }
                continue;
            }
            $select = $this->relationResolver->buildSelect($connection, $definition, $customerId, $collected);
            $rows = $select === null ? [] : $connection->fetchAll($select);

            $processor = $this->processorProvider->get($definition->getProcessor());
            if ($processor !== null) {
                $rows = $processor->processExportRows($table, $rows);
            }

            $header = $rows !== []
                ? array_map('strval', array_keys($rows[0]))
                : $this->describeColumns($connection, $table);
            $this->storage->writeTable($absoluteDir, $table, $header, $rows);

            if (isset($neededColumns[$table])) {
                foreach ($neededColumns[$table] as $column) {
                    $collected[$table][$column] = $this->distinctValues($rows, $column);
                }
            }

            $results[$table] = count($rows);
            if ($progress !== null) {
                $progress($table, count($rows), 'exported');
            }
        }

        if (array_sum($results) === 0) {
            $this->storage->removeExportDir($customerId);
            throw new LocalizedException(new Phrase(
                'Tool_DbTool: no data found for customer %1 (does the customer exist?).',
                [$customerId]
            ));
        }

        return ['directory' => $absoluteDir, 'tables' => $results, 'absent' => $absent];
    }

    /**
     * Bundle the customer's export directory into a single zip and return its absolute path.
     *
     * @param int $customerId
     * @return string
     * @throws LocalizedException
     */
    public function zip(int $customerId): string
    {
        return $this->storage->zipExport($customerId);
    }

    /**
     * Precompute, per parent table, which columns children reference as parent_key.
     *
     * @param TableDefinitionInterface[] $definitions
     * @return array<string,string[]>
     */
    private function collectNeededColumns(array $definitions): array
    {
        $needed = [];
        foreach ($definitions as $definition) {
            if ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_CHILD) {
                $needed[$definition->getParent()][$definition->getParentKey()] = $definition->getParentKey();
            }
            // Lookup tables pull their ids from source columns, which must therefore be collected
            // (e.g. product_id off quote_item / sales_order_item).
            foreach ($definition->getLookupSources() as $source) {
                $needed[$source['table']][$source['column']] = $source['column'];
            }
        }
        // Reindex the inner associative sets to plain lists.
        return array_map('array_values', $needed);
    }

    /**
     * Distinct, non-null values of one column across the given rows.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string $column
     * @return array<int,mixed>
     */
    private function distinctValues(array $rows, string $column): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $values[(string)$row[$column]] = $row[$column];
            }
        }
        return array_values($values);
    }

    /**
     * Column names for a table, used to write a header-only CSV when there are no rows.
     *
     * @param AdapterInterface $connection
     * @param string $table
     * @return string[]
     */
    private function describeColumns(AdapterInterface $connection, string $table): array
    {
        return array_keys($connection->describeTable($this->resourceConnection->getTableName($table)));
    }
}
