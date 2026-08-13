<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Processor;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use EcomEU\DbTool\Api\TableProcessorInterface;

/**
 * Resolves and caches optional per-table processors referenced by FQCN in the data map.
 *
 * Using the object manager here is intentional and idiomatic: the class name comes from
 * configuration and cannot be wired as a constructor dependency.
 */
class ProcessorProvider
{
    private ObjectManagerInterface $objectManager;

    /** @var array<string, TableProcessorInterface> */
    private array $instances = [];

    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * @param string|null $class
     * @return TableProcessorInterface|null
     * @throws LocalizedException
     */
    public function get(?string $class): ?TableProcessorInterface
    {
        if (empty($class)) {
            return null;
        }
        if (!isset($this->instances[$class])) {
            $instance = $this->objectManager->get($class);
            if (!$instance instanceof TableProcessorInterface) {
                throw new LocalizedException(new Phrase(
                    'Tool_DbTool: processor "%1" must implement %2.',
                    [$class, TableProcessorInterface::class]
                ));
            }
            $this->instances[$class] = $instance;
        }
        return $this->instances[$class];
    }
}
