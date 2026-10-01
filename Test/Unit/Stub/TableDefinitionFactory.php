<?php
declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace,Magento2.PHP.FinalImplementation

namespace EcomEU\DbTool\Model\Config;

use Magento\Framework\ObjectManagerInterface;

/**
 * Stand-in for the factory Magento generates at runtime, for the unit tests only (see
 * Test/Unit/bootstrap.php). Same constructor and contract as the generated class, so a test can
 * build either one the same way: new TableDefinitionFactory($objectManager)->create(['data' => $raw]).
 */
class TableDefinitionFactory
{
    /**
     * @param ObjectManagerInterface|null $objectManager
     * @param string $instanceName
     */
    public function __construct(
        private readonly ?ObjectManagerInterface $objectManager = null,
        private readonly string $instanceName = TableDefinition::class
    ) {
    }

    /**
     * @param array{data?:array<string,mixed>} $data
     * @return TableDefinition
     */
    public function create(array $data = []): TableDefinition
    {
        if ($this->objectManager !== null) {
            return $this->objectManager->create($this->instanceName, $data);
        }
        return new TableDefinition($data['data'] ?? []);
    }
}
