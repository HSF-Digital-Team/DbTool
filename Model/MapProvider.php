<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Api\MapProviderInterface;
use EcomEU\DbTool\Model\Config\CustomerMap;
use EcomEU\DbTool\Model\Config\TableDefinitionFactory;

/**
 * Assembles the customer data map: the seeded defaults from CustomerMap merged with any table
 * definitions contributed by other modules through etc/di.xml, then sorted by priority.
 *
 * A di-injected entry with the same key as a default REPLACES that default, so downstream modules
 * can also tweak or disable a stock table.
 */
class MapProvider implements MapProviderInterface
{
    /** @var TableDefinitionInterface[]|null */
    private ?array $definitions = null;

    /**
     * Construct
     *
     * @param CustomerMap $customerMap
     * @param TableDefinitionFactory $definitionFactory
     * @param array $tables Extra/override raw entries injected via di.xml.
     */
    public function __construct(
        private readonly CustomerMap $customerMap,
        private readonly TableDefinitionFactory $definitionFactory,
        private readonly array $tables = []
    ) {
    }

    /**
     * Get the export order of table definitions.
     *
     * @return TableDefinitionInterface[]
     */
    public function getExportOrder(): array
    {
        $definitions = $this->build();
        usort(
            $definitions,
            static fn (TableDefinitionInterface $a, TableDefinitionInterface $b): int => $a->getExportPriority() <=> $b->getExportPriority()
        );
        return $definitions;
    }

    /**
     * Get the import order of table definitions.
     *
     * @return TableDefinitionInterface[]
     */
    public function getImportOrder(): array
    {
        $definitions = $this->build();
        usort(
            $definitions,
            static fn (TableDefinitionInterface $a, TableDefinitionInterface $b): int => $a->getImportPriority() <=> $b->getImportPriority()
        );
        return $definitions;
    }

    /**
     * Build (once) the unsorted list of definitions from the merged raw map.
     *
     * @return TableDefinitionInterface[]
     */
    private function build(): array
    {
        if ($this->definitions === null) {
            $merged = array_replace($this->customerMap->get(), $this->tables);
            $this->definitions = [];
            foreach ($merged as $raw) {
                $this->definitions[] = $this->definitionFactory->create(['data' => $raw]);
            }
        }
        // Return a copy so callers can sort in place without mutating the cache.
        return $this->definitions;
    }
}
