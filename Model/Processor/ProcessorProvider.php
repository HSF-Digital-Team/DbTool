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
    /** @var array<string,TableProcessorInterface> */
    private array $instances = [];

    /**
     * @param ObjectManagerInterface $objectManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * Get a processor instance by class name.
     *
     * @param string|null $class
     * @return TableProcessorInterface|null
     * @throws LocalizedException
     */
    public function get(?string $class): ?TableProcessorInterface
    {
        if ($class === null || trim($class) === '') {
            return null;
        }
        $class = ltrim(trim($class), '\\');
        if (!isset($this->instances[$class])) {
            if (!class_exists($class)) {
                throw new LocalizedException(new Phrase(
                    'EcomEU_DbTool: processor class "%1" does not exist (check the "processor" field of the map).',
                    [$class]
                ));
            }
            $instance = $this->objectManager->get($class);
            if (!$instance instanceof TableProcessorInterface) {
                throw new LocalizedException(new Phrase(
                    'EcomEU_DbTool: processor "%1" must implement %2.',
                    [$class, TableProcessorInterface::class]
                ));
            }
            $this->instances[$class] = $instance;
        }
        return $this->instances[$class];
    }
}
