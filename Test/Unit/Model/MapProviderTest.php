<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Unit\Model;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Model\Config\CustomerMap;
use EcomEU\DbTool\Model\Config\TableDefinitionFactory;
use EcomEU\DbTool\Model\MapProvider;
use EcomEU\DbTool\Test\Unit\Stub\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The merged map: the seeded defaults are consistent, di.xml overrides apply, and every
 * cross-table inconsistency is rejected with a descriptive error.
 */
class MapProviderTest extends TestCase
{
    /**
     * The shipped map validates, covers the core tables and is ordered both ways.
     *
     * @return void
     */
    public function testDefaultMapIsConsistentAndOrdered(): void
    {
        $provider = $this->provider();

        $export = $provider->getExportOrder();
        $import = $provider->getImportOrder();
        self::assertNotEmpty($export);
        self::assertCount(count($export), $import);

        $tables = array_map(static fn (TableDefinitionInterface $d): string => $d->getTable(), $export);
        foreach (['customer_entity', 'customer_address_entity', 'quote', 'sales_order', 'catalog_product_entity',
                     'customer_group'] as $table) {
            self::assertContains($table, $tables);
        }
        self::assertSame('customer_entity', $tables[0], 'the customer exports first');

        $previous = PHP_INT_MIN;
        foreach ($export as $definition) {
            self::assertGreaterThanOrEqual($previous, $definition->getExportPriority());
            $previous = $definition->getExportPriority();
        }
        $previous = PHP_INT_MIN;
        foreach ($import as $definition) {
            self::assertGreaterThanOrEqual($previous, $definition->getImportPriority());
            $previous = $definition->getImportPriority();
        }
        self::assertSame('customer_group', $import[0]->getTable(), 'the group imports first (remap target)');
    }

    /**
     * Sorting one order does not disturb the other (the cache is not sorted in place).
     *
     * @return void
     */
    public function testOrdersAreIndependent(): void
    {
        $provider = $this->provider();
        $tables = static fn (TableDefinitionInterface $d): string => $d->getTable();
        $before = array_map($tables, $provider->getExportOrder());
        $provider->getImportOrder();
        $after = array_map($tables, $provider->getExportOrder());
        self::assertSame($before, $after);
    }

    /**
     * A di.xml entry with a default's key replaces it; a new key is added.
     *
     * @return void
     */
    public function testInjectedEntriesOverrideAndExtend(): void
    {
        $provider = $this->provider([
            'customer_grid_flat' => [
                'table' => 'customer_grid_flat',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'export_priority' => 99,
            ],
            'my_table' => [
                'table' => 'my_table',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'customer_id',
                'export_priority' => 100,
            ],
        ]);

        $byTable = [];
        foreach ($provider->getExportOrder() as $definition) {
            $byTable[$definition->getTable()] = $definition;
        }
        self::assertSame(99, $byTable['customer_grid_flat']->getExportPriority(), 'default replaced');
        self::assertFalse($byTable['customer_grid_flat']->isSkipIfParentReused(), 'whole entry replaced');
        self::assertArrayHasKey('my_table', $byTable);
    }

    /**
     * Each inconsistent injected entry fails at load time with the table named.
     *
     * @param array<string,array<string,mixed>|mixed> $tables
     * @param string $expectedMessagePart
     * @return void
     */
    #[DataProvider('inconsistentMaps')]
    public function testInconsistentMapIsRejected(array $tables, string $expectedMessagePart): void
    {
        $provider = $this->provider($tables);
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessagePart);
        $provider->getExportOrder();
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function inconsistentMaps(): array
    {
        $child = static fn (array $extra): array => $extra + [
            'table' => 'my_table',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'customer_entity',
            'parent_key' => 'entity_id',
            'foreign_key' => 'customer_id',
            'export_priority' => 100,
        ];
        return [
            'not an array' => [['my_table' => 'customer_entity'], 'must be an array'],
            'mapped twice' => [
                ['dup' => $child(['table' => 'customer_grid_flat'])],
                'mapped twice',
            ],
            'unmapped parent' => [['t' => $child(['parent' => 'nowhere'])], 'unmapped parent "nowhere"'],
            'own parent' => [['t' => $child(['parent' => 'my_table'])], 'cannot be its own parent'],
            'child before parent (export)' => [['t' => $child(['export_priority' => 10])], 'higher export_priority'],
            'child before parent (import)' => [
                ['t' => $child(['import_priority' => 10])],
                'higher import_priority',
            ],
            'unmapped reference' => [
                ['t' => $child(['references' => [['column' => 'x', 'table' => 'nowhere']]])],
                'references an unmapped table "nowhere"',
            ],
            'requires a mapped table' => [
                ['t' => $child(['requires' => [['column' => 'x', 'table' => 'quote', 'key' => 'entity_id']]])],
                '"requires" the mapped table "quote"',
            ],
            'unmapped lookup source' => [
                ['t' => [
                    'table' => 'my_lookup',
                    'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
                    'lookup_sources' => [['table' => 'nowhere', 'column' => 'x']],
                    'export_priority' => 100,
                ]],
                'unmapped lookup source "nowhere"',
            ],
            'lookup before its source' => [
                ['t' => [
                    'table' => 'my_lookup',
                    'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
                    'lookup_sources' => [['table' => 'quote_item', 'column' => 'product_id']],
                    'export_priority' => 1,
                ]],
                'higher export_priority than its source "quote_item"',
            ],
        ];
    }

    /**
     * @param array<string,mixed> $tables
     * @return MapProvider
     */
    private function provider(array $tables = []): MapProvider
    {
        // Works with Magento's generated factory (when the module sits in a Magento install) and
        // with the Test/Unit/Stub stand-in (standalone repo / CI): both take an object manager.
        return new MapProvider(new CustomerMap(), new TableDefinitionFactory(new ObjectManager()), $tables);
    }
}
