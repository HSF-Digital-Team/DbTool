<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Unit\Model\Config;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;
use EcomEU\DbTool\Model\Config\TableDefinition;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One map entry: defaults, normalisation and fail-fast validation.
 */
class TableDefinitionTest extends TestCase
{
    /**
     * A direct entry with only the required fields gets the documented defaults.
     *
     * @return void
     */
    public function testDefaults(): void
    {
        $definition = new TableDefinition([
            'table' => 'customer_entity',
            'relationship_type' => TableDefinitionInterface::RELATION_DIRECT,
            'customer_column' => 'entity_id',
            'export_priority' => 10,
        ]);

        self::assertSame('entity_id', $definition->getPrimaryKey());
        self::assertSame('entity_id', $definition->getLookupColumn(), 'lookup column defaults to the pk');
        self::assertSame(10, $definition->getImportPriority(), 'import priority defaults to export priority');
        self::assertSame([], $definition->getFilter());
        self::assertSame([], $definition->getReferences());
        self::assertSame([], $definition->getRequires());
        self::assertSame([], $definition->getUniqueKey());
        self::assertSame([], $definition->getCloneRegenerate());
        self::assertSame([], $definition->getCloneMirror());
        self::assertNull($definition->getParent());
        self::assertNull($definition->getProcessor());
        self::assertFalse($definition->isSkipIfParentReused());
        self::assertFalse($definition->isCloneNew());
    }

    /**
     * A child derives its parent reference and keeps the declared cross-links after it.
     *
     * @return void
     */
    public function testChildDerivesTheParentReference(): void
    {
        $definition = new TableDefinition([
            'table' => 'quote_item',
            'primary_key' => 'item_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'quote',
            'parent_key' => 'entity_id',
            'foreign_key' => 'quote_id',
            'references' => [['column' => 'product_id', 'table' => 'catalog_product_entity']],
            'unique_key' => ['sku', 'store_id'],
            'export_priority' => 40,
            'import_priority' => 41,
        ]);

        self::assertSame([
            ['column' => 'quote_id', 'table' => 'quote'],
            ['column' => 'product_id', 'table' => 'catalog_product_entity'],
        ], $definition->getReferences());
        self::assertSame(['sku', 'store_id'], $definition->getUniqueKey());
        self::assertSame(41, $definition->getImportPriority());
    }

    /**
     * Lookup sources are normalised, keeping the optional EAV restriction.
     *
     * @return void
     */
    public function testLookupSourcesAreNormalised(): void
    {
        $definition = new TableDefinition([
            'table' => 'ecomeu_familyset_tier_price',
            'primary_key' => 'id',
            'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
            'lookup_column' => 'familyset_code',
            'lookup_sources' => [
                [
                    'table' => 'catalog_product_entity_varchar',
                    'column' => 'value',
                    'eav_attribute' => 'catalog_product/family_set',
                ],
                ['table' => 'quote_item', 'column' => 'product_id', 'ignored' => true],
            ],
            'clone_regenerate' => ['code' => TableDefinitionInterface::STRATEGY_SUFFIX],
        ]);

        self::assertSame('familyset_code', $definition->getLookupColumn());
        self::assertSame([
            [
                'table' => 'catalog_product_entity_varchar',
                'column' => 'value',
                'eav_attribute' => 'catalog_product/family_set',
            ],
            ['table' => 'quote_item', 'column' => 'product_id'],
        ], $definition->getLookupSources());
        self::assertSame(['code' => 'suffix'], $definition->getCloneRegenerate());
    }

    /**
     * Every malformed entry is rejected at construction with the table named.
     *
     * @param array<string,mixed> $data
     * @param string $expectedMessagePart
     * @return void
     */
    #[DataProvider('invalidEntries')]
    public function testInvalidEntryIsRejected(array $data, string $expectedMessagePart): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage($expectedMessagePart);
        new TableDefinition($data);
    }

    /**
     * @return array<string,array{0:array<string,mixed>,1:string}>
     */
    public static function invalidEntries(): array
    {
        $child = [
            'table' => 't',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'p',
            'parent_key' => 'id',
            'foreign_key' => 'p_id',
        ];
        return [
            'missing table' => [['relationship_type' => 'direct'], 'missing "table"'],
            'blank table' => [['table' => '  ', 'relationship_type' => 'direct'], 'missing "table"'],
            'missing type' => [['table' => 't'], 'invalid relationship_type'],
            'unknown type' => [
                ['table' => 't', 'relationship_type' => 'sibling'],
                'invalid relationship_type "sibling"',
            ],
            'direct without customer column' => [
                ['table' => 't', 'relationship_type' => 'direct'],
                '"customer_column"',
            ],
            'child without parent' => [['table' => 't', 'relationship_type' => 'child'], '"parent"'],
            'child without foreign key' => [
                ['table' => 't', 'relationship_type' => 'child', 'parent' => 'p', 'parent_key' => 'id'],
                '"foreign_key"',
            ],
            'lookup without sources' => [['table' => 't', 'relationship_type' => 'lookup'], '"lookup_sources"'],
            'lookup source without column' => [
                ['table' => 't', 'relationship_type' => 'lookup', 'lookup_sources' => [['table' => 's']]],
                '"lookup_sources" entry without table/column',
            ],
            'reference without table' => [$child + ['references' => [['column' => 'x']]], '"references" entry'],
            'requires without key' => [
                $child + ['requires' => [['column' => 'x', 'table' => 'u']]],
                '"requires" entry without column/table/key',
            ],
            'references not an array' => [$child + ['references' => 'x'], '"references" must be an array'],
            'unique key not an array' => [$child + ['unique_key' => 'sku'], '"unique_key" must be an array'],
            'unique key with a non column' => [$child + ['unique_key' => [[]]], '"unique_key" entry'],
            'primary key not a string' => [
                $child + ['primary_key' => 12],
                '"primary_key" must be a non-empty string',
            ],
            'unknown clone strategy' => [
                $child + ['clone_regenerate' => ['email' => 'random']],
                'unknown strategy "random"',
            ],
            'unknown mirror strategy' => [
                $child + ['clone_mirror' => ['email' => 'random']],
                'unknown strategy "random"',
            ],
        ];
    }
}
