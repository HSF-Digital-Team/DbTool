<?php
declare(strict_types=1);

namespace Tool\DbTool\Model\Config;

use Tool\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Default, seeded customer data map (the "single source of truth" starting point).
 *
 * This is the ONE place that knows the shape of Magento's stock customer footprint. The engine
 * never hardcodes any of it. To cover more tables, add entries here — or, from another module,
 * inject them into Tool\DbTool\Model\MapProvider via etc/di.xml (no change to this file required).
 *
 * Relationship recap (parent -> child column that references it):
 *   customer_entity.entity_id
 *     ├─ customer_grid_flat.entity_id
 *     └─ customer_address_entity.parent_id
 *   quote.customer_id -> customer_entity.entity_id
 *     ├─ quote_address.quote_id
 *     ├─ quote_item.quote_id
 *     └─ quote_payment.quote_id
 *   sales_order.customer_id -> customer_entity.entity_id
 *     ├─ sales_order_address.parent_id
 *     ├─ sales_order_item.order_id
 *     ├─ sales_order_payment.parent_id
 *     └─ sales_order_grid.entity_id
 */
class CustomerMap
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public function get(): array
    {
        return [
            // --- Customer core ---
            'customer_entity' => [
                'table' => 'customer_entity',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_DIRECT,
                'customer_column' => 'entity_id',
                // Natural key: a matching target customer is reused (avoids the unique-email index
                // failure and duplicate customers when importing into another DB).
                'unique_key' => ['email', 'website_id'],
                'export_priority' => 10,
            ],
            'customer_grid_flat' => [
                'table' => 'customer_grid_flat',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                // The grid row shares the customer's id (non-auto PK) and is rebuildable by reindex;
                // skip it when the customer already exists on target.
                'skip_if_parent_reused' => true,
                'export_priority' => 20,
            ],
            'customer_address_entity' => [
                'table' => 'customer_address_entity',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'export_priority' => 20,
            ],

            // --- Quotes (carts) ---
            'quote' => [
                'table' => 'quote',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_DIRECT,
                'customer_column' => 'customer_id',
                // customer_id must follow the customer's (possibly remapped) id on import.
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                ],
                'export_priority' => 30,
            ],
            'quote_address' => [
                'table' => 'quote_address',
                'primary_key' => 'address_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote',
                'parent_key' => 'entity_id',
                'foreign_key' => 'quote_id',
                'export_priority' => 40,
            ],
            'quote_item' => [
                'table' => 'quote_item',
                'primary_key' => 'item_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote',
                'parent_key' => 'entity_id',
                'foreign_key' => 'quote_id',
                // product_id is remapped to the target product matched by sku.
                'references' => [
                    ['column' => 'product_id', 'table' => 'catalog_product_entity'],
                ],
                'export_priority' => 40,
            ],
            'quote_payment' => [
                'table' => 'quote_payment',
                'primary_key' => 'payment_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote',
                'parent_key' => 'entity_id',
                'foreign_key' => 'quote_id',
                'export_priority' => 40,
            ],

            // --- Sales orders ---
            'sales_order' => [
                'table' => 'sales_order',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_DIRECT,
                'customer_column' => 'customer_id',
                // An order references both its customer and the quote it was placed from.
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                    ['column' => 'quote_id', 'table' => 'quote'],
                ],
                'export_priority' => 50,
            ],
            'sales_order_address' => [
                'table' => 'sales_order_address',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'export_priority' => 60,
            ],
            'sales_order_item' => [
                'table' => 'sales_order_item',
                'primary_key' => 'item_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'order_id',
                // product_id -> target product (by sku); quote_item_id -> the remapped quote item.
                'references' => [
                    ['column' => 'product_id', 'table' => 'catalog_product_entity'],
                    ['column' => 'quote_item_id', 'table' => 'quote_item'],
                ],
                'export_priority' => 60,
            ],
            'sales_order_payment' => [
                'table' => 'sales_order_payment',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'export_priority' => 60,
            ],
            'sales_order_grid' => [
                'table' => 'sales_order_grid',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                ],
                'export_priority' => 60,
            ],

            // --- Products referenced by cart/order items ---
            // Looked up from the product ids collected off the item tables. Exported AFTER the
            // items (so their product_ids are known) but imported BEFORE them (import_priority 5/6)
            // so item.product_id can be remapped to the target product matched by sku.
            'catalog_product_entity' => [
                'table' => 'catalog_product_entity',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
                'lookup_sources' => [
                    ['table' => 'quote_item', 'column' => 'product_id'],
                    ['table' => 'sales_order_item', 'column' => 'product_id'],
                ],
                'unique_key' => ['sku'],
                'export_priority' => 70,
                'import_priority' => 5,
            ],
        ] + $this->productChildren();
    }

    /**
     * Product sub-tables (EAV values, website links, stock). All children of catalog_product_entity
     * and all skipped on import when the product already exists on target (matched by sku) — the
     * target already owns this data. Only stub-inserted (missing-sku) products receive it.
     *
     * @return array<string, array<string, mixed>>
     */
    private function productChildren(): array
    {
        $children = [];

        // EAV value tables link via entity_id (community edition, no content-staging row_id).
        foreach (['datetime', 'decimal', 'int', 'text', 'varchar'] as $backendType) {
            $table = 'catalog_product_entity_' . $backendType;
            $children[$table] = [
                'table' => $table,
                'primary_key' => 'value_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'catalog_product_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'skip_if_parent_reused' => true,
                'export_priority' => 71,
                'import_priority' => 6,
            ];
        }

        $children['catalog_product_website'] = [
            'table' => 'catalog_product_website',
            'primary_key' => 'product_id', // composite (product_id, website_id); non-auto
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'catalog_product_entity',
            'parent_key' => 'entity_id',
            'foreign_key' => 'product_id',
            'skip_if_parent_reused' => true,
            'export_priority' => 71,
            'import_priority' => 6,
        ];

        $children['cataloginventory_stock_item'] = [
            'table' => 'cataloginventory_stock_item',
            'primary_key' => 'item_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'catalog_product_entity',
            'parent_key' => 'entity_id',
            'foreign_key' => 'product_id',
            'skip_if_parent_reused' => true,
            'export_priority' => 71,
            'import_priority' => 6,
        ];

        $children['cataloginventory_stock_status'] = [
            'table' => 'cataloginventory_stock_status',
            'primary_key' => 'product_id', // composite (product_id, website_id, stock_id); non-auto
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'catalog_product_entity',
            'parent_key' => 'entity_id',
            'foreign_key' => 'product_id',
            'skip_if_parent_reused' => true,
            'export_priority' => 71,
            'import_priority' => 6,
        ];

        return $children;
    }
}
