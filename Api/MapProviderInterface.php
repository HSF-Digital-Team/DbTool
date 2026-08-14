<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Api;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Supplies the customer data map (the single source of truth) to the export/import engine.
 */
interface MapProviderInterface
{
    /**
     * Table definitions sorted by ascending export priority.
     *
     * @return TableDefinitionInterface[]
     */
    public function getExportOrder(): array;

    /**
     * Table definitions sorted by ascending import priority.
     *
     * @return TableDefinitionInterface[]
     */
    public function getImportOrder(): array;
}
