<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Integration;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManagerInterface;
use RuntimeException;

/**
 * Holds the object manager of the booted Magento for the test cases.
 */
class Magento
{
    /** @var ObjectManagerInterface|null */
    private static ?ObjectManagerInterface $objectManager = null;

    /**
     * Register the booted object manager (called once from bootstrap.php).
     *
     * @param ObjectManagerInterface $objectManager
     * @return void
     */
    public static function init(ObjectManagerInterface $objectManager): void
    {
        self::$objectManager = $objectManager;
        $objectManager->get(State::class)->setAreaCode(Area::AREA_ADMINHTML);
    }

    /**
     * The booted object manager.
     *
     * @return ObjectManagerInterface
     */
    public static function objectManager(): ObjectManagerInterface
    {
        if (self::$objectManager === null) {
            throw new RuntimeException('Magento is not booted: run PHPUnit with Test/Integration/phpunit.xml.');
        }
        return self::$objectManager;
    }
}
