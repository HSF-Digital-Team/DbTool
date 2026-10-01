<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Relation;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use EcomEU\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Turns a table definition and the ids already collected from parent tables into bound SELECTs.
 *
 * This is where the map's relationship metadata becomes a query. It is the only translation step;
 * neither the Exporter nor the Importer needs to understand relationship types.
 */
class RelationResolver
{
    /**
     * Maximum number of values bound into one "IN (...)" list. A big footprint (thousands of
     * products, hundreds of thousands of EAV rows) is queried in several statements of at most this
     * many ids, instead of one statement that could exceed max_allowed_packet or the parser limits.
     */
    public const int IN_CHUNK_SIZE = 5000;

    /**
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        private readonly ResourceConnection $resourceConnection
    ) {
    }

    /**
     * Build the SELECTs that together fetch this table's customer-scoped rows.
     *
     * Returns an empty list when the relationship can be proven empty up front (a child whose parent
     * yielded no rows) — the caller then writes a header-only CSV instead of running a pointless
     * query. A direct table is always one statement; a child or lookup table is one statement per
     * chunk of IN_CHUNK_SIZE ids.
     *
     * @param AdapterInterface $connection
     * @param TableDefinitionInterface $definition
     * @param int $customerId
     * @param array<string,array<string,array<int,mixed>>> $collected
     * @return Select[]
     */
    public function buildSelects(
        AdapterInterface $connection,
        TableDefinitionInterface $definition,
        int $customerId,
        array $collected
    ): array {
        if ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_DIRECT) {
            $select = $this->baseSelect($connection, $definition);
            $select->where($connection->quoteIdentifier($definition->getCustomerColumn()) . ' = ?', $customerId);
            return [$select];
        }

        if ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_LOOKUP) {
            $column = $definition->getLookupColumn();
            $ids = $this->gatherLookupIds($definition, $collected);
        } else {
            $column = (string)$definition->getForeignKey();
            $ids = $collected[$definition->getParent()][$definition->getParentKey()] ?? [];
        }
        if ($ids === []) {
            return [];
        }

        $selects = [];
        foreach (array_chunk(array_values($ids), self::IN_CHUNK_SIZE) as $chunk) {
            $select = $this->baseSelect($connection, $definition);
            $select->where($connection->quoteIdentifier($column) . ' IN (?)', $chunk);
            $selects[] = $select;
        }
        return $selects;
    }

    /**
     * SELECT * FROM <prefixed table> plus the definition's static filter conditions.
     *
     * @param AdapterInterface $connection
     * @param TableDefinitionInterface $definition
     * @return Select
     */
    private function baseSelect(AdapterInterface $connection, TableDefinitionInterface $definition): Select
    {
        // ResourceConnection::getTableName() applies the configured table prefix; the adapter's
        // own getTableName() only shortens over-long names and would miss the prefix.
        $select = $connection->select()->from(
            $this->resourceConnection->getTableName($definition->getTable())
        );
        foreach ($definition->getFilter() as $condition => $value) {
            $select->where($condition, $value);
        }
        return $select;
    }

    /**
     * Gather
     *
     * Union of the distinct id values this lookup table should be scoped to, gathered from the
     * columns already collected off its source tables.
     *
     * @param TableDefinitionInterface $definition
     * @param array<string,array<string,array<int,mixed>>> $collected
     * @return array<int,mixed>
     */
    private function gatherLookupIds(TableDefinitionInterface $definition, array $collected): array
    {
        $ids = [];
        foreach ($definition->getLookupSources() as $source) {
            foreach ($collected[$source['table']][$this->collectKey($source)] ?? [] as $value) {
                if ($value !== null && $value !== '') {
                    $ids[(string)$value] = $value;
                }
            }
        }
        return array_values($ids);
    }

    /**
     * Key under which the exporter collects a lookup source's values.
     *
     * The column, suffixed by the EAV attribute when the source is restricted to one attribute's rows.
     *
     * @param array{table:string,column:string,eav_attribute?:string} $source
     * @return string
     */
    public function collectKey(array $source): string
    {
        return isset($source['eav_attribute'])
            ? $source['column'] . '@' . $source['eav_attribute']
            : $source['column'];
    }
}
