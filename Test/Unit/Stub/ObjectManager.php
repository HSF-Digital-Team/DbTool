<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Unit\Stub;

use Magento\Framework\ObjectManagerInterface;

/**
 * Minimal object manager for the unit tests: instantiates the requested class with the given
 * named arguments, no DI configuration, no sharing. Enough for Magento's generated factories.
 */
class ObjectManager implements ObjectManagerInterface
{
    /**
     * @inheritDoc
     */
    public function create($type, array $arguments = [])
    {
        return new $type(...$arguments);
    }

    /**
     * @inheritDoc
     */
    public function get($type)
    {
        return new $type();
    }

    /**
     * @inheritDoc
     */
    public function configure(array $configuration)
    {
    }
}
