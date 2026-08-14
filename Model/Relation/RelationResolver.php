<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Relation;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use EcomEU\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Turns a table definition and the ids already collected from parent tables into a bound SELECT.
 *
 * This is where the map's relationship metadata becomes a query. It is the only translation step;
 * neither the Exporter nor the Importer needs to understand relationship types.
 */
class RelationResolver
{
    /**
     * Build the SELECT that fetches this table's customer-scoped rows.
     *
     * Returns null when the relationship can be proven empty up front (a child whose parent yielded
     * no rows) — the caller then writes a header-only CSV instead of running a pointless query.
     *
     * @param AdapterInterface $connection
     * @param TableDefinitionInterface $definition
     * @param int $customerId
     * @param array $collected
     * @return Select|null
     */
    public function buildSelect(
        AdapterInterface $connection,
        TableDefinitionInterface $definition,
        int $customerId,
        array $collected
    ): ?Select {
        $select = $connection->select()->from(
            $connection->getTableName($definition->getTable())
        );

        if ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_DIRECT) {
            $select->where($connection->quoteIdentifier($definition->getCustomerColumn()) . ' = ?', $customerId);
        } elseif ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_LOOKUP) {
            $ids = $this->gatherLookupIds($definition, $collected);
            if (empty($ids)) {
                return null;
            }
            $select->where(
                $connection->quoteIdentifier($definition->getPrimaryKey()) . ' IN (?)',
                $ids
            );
        } else {
            $parentValues = $collected[$definition->getParent()][$definition->getParentKey()] ?? [];
            if (empty($parentValues)) {
                return null;
            }
            $select->where(
                $connection->quoteIdentifier($definition->getForeignKey()) . ' IN (?)',
                $parentValues
            );
        }

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
     * @param array $collected
     * @return array
     */
    private function gatherLookupIds(TableDefinitionInterface $definition, array $collected): array
    {
        $ids = [];
        foreach ($definition->getLookupSources() as $source) {
            foreach ($collected[$source['table']][$source['column']] ?? [] as $value) {
                if ($value !== null && $value !== '') {
                    $ids[(string)$value] = $value;
                }
            }
        }
        return array_values($ids);
    }
}
