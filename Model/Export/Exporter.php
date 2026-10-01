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
use Throwable;

/**
 * Export engine. Walks the customer data map in export-priority order, resolving each table's
 * customer-scoped rows by relationship, and writes one CSV per table. Parent primary keys are
 * collected as tables are processed so that child tables can be scoped without hardcoded logic.
 *
 * All traversal knowledge lives here; nothing about specific tables is hardcoded.
 */
class Exporter
{
    /** @var array<string,int|null> '<entity_type_code>/<attribute_code>' => attribute id (null: unknown) */
    private array $attributeIds = [];

    /**
     * @param ResourceConnection $resourceConnection
     * @param MapProviderInterface $mapProvider
     * @param RelationResolver $relationResolver
     * @param ProcessorProvider $processorProvider
     * @param CsvStorage $storage
     */
    public function __construct(
        private readonly ResourceConnection   $resourceConnection,
        private readonly MapProviderInterface $mapProvider,
        private readonly RelationResolver     $relationResolver,
        private readonly ProcessorProvider    $processorProvider,
        private readonly CsvStorage           $storage
    ) {
    }

    /**
     * Export the full footprint of one customer.
     *
     * A mapped table that does not exist on this database (a store-specific table on another
     * install) is skipped and reported, not written. If no table yields a single row the customer
     * does not exist: the empty export directory is removed and an exception is thrown. Any other
     * failure removes the half-written directory too, so a stale or partial export is never left
     * on disk looking like a good one.
     *
     * @param int $customerId
     * @param callable|null $progress fn(string $table, int $rowCount, string $status): void
     * @return array{directory:string,tables:array<string,int>,absent:string[]} directory +
     *         per-table row counts + tables absent on this database
     * @throws LocalizedException
     * @throws Throwable
     */
    public function export(int $customerId, ?callable $progress = null): array
    {
        // Resolve and validate the map before touching the disk.
        $definitions = $this->mapProvider->getExportOrder();
        $neededColumns = $this->collectNeededColumns($definitions);

        $connection = $this->resourceConnection->getConnection();
        $absoluteDir = $this->storage->prepareExportDir($customerId);

        try {
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
                $rows = $this->fetchRows($connection, $definition, $customerId, $collected);

                $processor = $this->processorProvider->get($definition->getProcessor());
                if ($processor !== null) {
                    $rows = array_values($processor->processExportRows($table, $rows));
                }

                $header = $rows !== []
                    ? array_map('strval', array_keys($rows[0]))
                    : $this->describeColumns($connection, $table);
                $this->storage->writeTable($absoluteDir, $table, $header, $rows);

                if (isset($neededColumns[$table])) {
                    foreach ($neededColumns[$table] as $key => $source) {
                        $attributeId = isset($source['eav_attribute'])
                            ? $this->resolveAttributeId($connection, $source['eav_attribute'])
                            : null;
                        $collected[$table][$key] = $this->distinctValues($rows, $source['column'], $attributeId);
                    }
                }

                $results[$table] = count($rows);
                if ($progress !== null) {
                    $progress($table, count($rows), 'exported');
                }
            }

            if (array_sum($results) === 0) {
                throw new LocalizedException(new Phrase(
                    'EcomEU_DbTool: no data found for customer %1 (does the customer exist?).',
                    [$customerId]
                ));
            }
        } catch (Throwable $e) {
            $this->storage->removeExportDir($customerId);
            throw $e;
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
     * All rows of one table for this customer, across the resolver's chunked statements.
     *
     * @param AdapterInterface $connection
     * @param TableDefinitionInterface $definition
     * @param int $customerId
     * @param array<string,array<string,array<int,mixed>>> $collected
     * @return array<int,array<string,mixed>>
     */
    private function fetchRows(
        AdapterInterface $connection,
        TableDefinitionInterface $definition,
        int $customerId,
        array $collected
    ): array {
        $rows = [];
        foreach ($this->relationResolver->buildSelects($connection, $definition, $customerId, $collected) as $select) {
            foreach ($connection->fetchAll($select) as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /**
     * Precompute, per source table, which column values must be collected.
     *
     * The parent_key columns children scope on, and the lookup source columns (keyed as
     * RelationResolver::collectKey()).
     *
     * @param TableDefinitionInterface[] $definitions
     * @return array<string,array<string,array{column:string,eav_attribute?:string}>>
     */
    private function collectNeededColumns(array $definitions): array
    {
        $needed = [];
        foreach ($definitions as $definition) {
            if ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_CHILD) {
                $parentKey = (string)$definition->getParentKey();
                $needed[(string)$definition->getParent()][$parentKey] = ['column' => $parentKey];
            }
            // Lookup tables pull their ids from source columns, which must therefore be collected
            // (e.g. product_id off quote_item / sales_order_item).
            foreach ($definition->getLookupSources() as $source) {
                $needed[$source['table']][$this->relationResolver->collectKey($source)] = $source;
            }
        }
        return $needed;
    }

    /**
     * Distinct, non-null values of one column across the given rows.
     *
     * Optionally only from the rows of one EAV attribute (rows whose attribute_id equals
     * $attributeId; none when it is unknown).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string $column
     * @param int|null $attributeId
     * @return array<int,mixed>
     */
    private function distinctValues(array $rows, string $column, ?int $attributeId = null): array
    {
        $values = [];
        foreach ($rows as $row) {
            if ($attributeId !== null && (string)($row['attribute_id'] ?? '') !== (string)$attributeId) {
                continue;
            }
            if (array_key_exists($column, $row) && $row[$column] !== null) {
                $values[(string)$row[$column]] = $row[$column];
            }
        }
        return array_values($values);
    }

    /**
     * Resolve '<entity_type_code>/<attribute_code>' to the attribute id on this database.
     *
     * @param AdapterInterface $connection
     * @param string $spec
     * @return int|null null when the attribute does not exist here
     */
    private function resolveAttributeId(AdapterInterface $connection, string $spec): ?int
    {
        if (!array_key_exists($spec, $this->attributeIds)) {
            [$entityType, $code] = array_pad(explode('/', $spec, 2), 2, '');
            $select = $connection->select()
                ->from(['a' => $this->resourceConnection->getTableName('eav_attribute')], ['attribute_id'])
                ->join(
                    ['t' => $this->resourceConnection->getTableName('eav_entity_type')],
                    't.entity_type_id = a.entity_type_id',
                    []
                )
                ->where('t.entity_type_code = ?', $entityType)
                ->where('a.attribute_code = ?', $code);
            $id = $connection->fetchOne($select);
            $this->attributeIds[$spec] = is_numeric($id) ? (int)$id : null;
        }
        return $this->attributeIds[$spec];
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
