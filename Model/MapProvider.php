<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Api\MapProviderInterface;
use EcomEU\DbTool\Model\Config\CustomerMap;
use EcomEU\DbTool\Model\Config\TableDefinitionFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * Assembles the customer data map: the seeded defaults from CustomerMap merged with any table
 * definitions contributed by other modules through etc/di.xml, then sorted by priority.
 *
 * A di-injected entry with the same key as a default REPLACES that default, so downstream modules
 * can also tweak or disable a stock table.
 *
 * The merged map is validated once: every parent / reference / lookup source must name a mapped
 * table, and the priorities must order parents before children (export AND import) and lookup
 * sources before the lookup table (export), otherwise the engine would silently produce dangling
 * links or empty CSVs.
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
     * @param array<string,array<string,mixed>> $tables Extra/override raw entries injected via di.xml.
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
            static fn (TableDefinitionInterface $a, TableDefinitionInterface $b): int =>
                $a->getExportPriority() <=> $b->getExportPriority()
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
            static fn (TableDefinitionInterface $a, TableDefinitionInterface $b): int =>
                $a->getImportPriority() <=> $b->getImportPriority()
        );
        return $definitions;
    }

    /**
     * Build (once) the unsorted list of definitions from the merged raw map.
     *
     * @return TableDefinitionInterface[]
     * @throws LocalizedException
     */
    private function build(): array
    {
        if ($this->definitions === null) {
            $merged = array_replace($this->customerMap->get(), $this->tables);
            $definitions = [];
            foreach ($merged as $raw) {
                $definitions[] = $this->definitionFactory->create(['data' => $raw]);
            }
            $this->validate($definitions);
            $this->definitions = $definitions;
        }
        // Return a copy so callers can sort in place without mutating the cache.
        return $this->definitions;
    }

    /**
     * Validate the cross-table consistency of the merged map.
     *
     * @param TableDefinitionInterface[] $definitions
     * @throws LocalizedException
     */
    private function validate(array $definitions): void
    {
        /** @var array<string,TableDefinitionInterface> $byTable */
        $byTable = [];
        foreach ($definitions as $definition) {
            if (isset($byTable[$definition->getTable()])) {
                throw new LocalizedException(new Phrase(
                    'Tool_DbTool: table "%1" is mapped twice.',
                    [$definition->getTable()]
                ));
            }
            $byTable[$definition->getTable()] = $definition;
        }

        foreach ($definitions as $definition) {
            $table = $definition->getTable();

            if ($definition->getRelationshipType() === TableDefinitionInterface::RELATION_CHILD) {
                $parent = $byTable[$definition->getParent()] ?? null;
                if ($parent === null) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: child table "%1" names an unmapped parent "%2".',
                        [$table, (string)$definition->getParent()]
                    ));
                }
                if ($definition->getExportPriority() <= $parent->getExportPriority()) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: child table "%1" must have a higher export_priority than its parent "%2".',
                        [$table, $parent->getTable()]
                    ));
                }
                if ($definition->getImportPriority() <= $parent->getImportPriority()) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: child table "%1" must have a higher import_priority than its parent "%2".',
                        [$table, $parent->getTable()]
                    ));
                }
            }

            foreach ($definition->getLookupSources() as $source) {
                $sourceDefinition = $byTable[$source['table']] ?? null;
                if ($sourceDefinition === null) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: lookup table "%1" names an unmapped lookup source "%2".',
                        [$table, $source['table']]
                    ));
                }
                if ($definition->getExportPriority() <= $sourceDefinition->getExportPriority()) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: lookup table "%1" must have a higher export_priority than its source "%2".',
                        [$table, $source['table']]
                    ));
                }
            }

            foreach ($definition->getReferences() as $reference) {
                if (!isset($byTable[$reference['table']])) {
                    throw new LocalizedException(new Phrase(
                        'Tool_DbTool: table "%1" column "%2" references an unmapped table "%3".',
                        [$table, $reference['column'], $reference['table']]
                    ));
                }
            }
        }
    }
}
