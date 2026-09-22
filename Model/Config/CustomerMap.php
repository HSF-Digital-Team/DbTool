<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Model\Config;

use EcomEU\DbTool\Api\Data\TableDefinitionInterface;

/**
 * Default, seeded customer data map (the "single source of truth" starting point).
 *
 * This is the ONE place that knows the shape of Magento's stock customer footprint. The engine
 * never hardcodes any of it. To cover more tables, add entries here — or, from another module,
 * inject them into EcomEU\DbTool\Model\MapProvider via etc/di.xml (no change to this file required).
 *
 * Relationship recap (parent -> child column that references it):
 *   customer_entity.entity_id
 *     ├─ customer_grid_flat.entity_id
 *     ├─ customer_address_entity.parent_id  (customer_entity.default_billing/shipping point back)
 *     └─ contrat_aladin_clients.customer_id
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
     * Get
     *
     * @return array<string,array<string,mixed>>
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
                // group_id follows the customer group matched by code on the target. The default
                // address pointers reference a table imported LATER, so the engine rewrites them by
                // UPDATE at the end of the run once the address ids are known.
                'references' => [
                    ['column' => 'group_id', 'table' => 'customer_group'],
                    ['column' => 'default_billing', 'table' => 'customer_address_entity'],
                    ['column' => 'default_shipping', 'table' => 'customer_address_entity'],
                ],
                // Clone mode: force a brand-new customer (skip the email match) and give it a unique
                // email so the (email, website_id) unique index is not violated.
                'clone_new' => true,
                'clone_regenerate' => ['email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX],
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
                // Clone mode: keep the grid email consistent with the cloned customer's new email.
                'clone_mirror' => ['email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX],
                'export_priority' => 20,
            ],
            'customer_address_entity' => [
                'table' => 'customer_address_entity',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                // A customer matched by email on the target already owns its addresses: skip them
                // (and, by cascade, their EAV value rows) instead of inserting duplicates each run.
                'skip_if_parent_reused' => true,
                'export_priority' => 20,
            ],
            // --- Contract ---
            // contrat_aladin_clients has NO surrogate PK/auto-increment; its identity is the
            // globally-unique int `contrat_client` (the unique index CONTRAT_ALADIN_CLIENTS_
            // CONTRAT_CLIENT_CUSTOMER_ID covers contrat_client ALONE). Declare it so an existing
            // contract is matched & reused (avoids the 1062 collision). Never clone_new: a contract
            // can belong to one customer only, so in clone mode it is matched, not duplicated.
            // contract_code / contrat_client_type_id reference contrat_aladin (outside the map),
            // which must already exist on the target.
            'contract' => [
                'table' => 'contrat_aladin_clients',
                'primary_key' => 'contrat_client',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'customer_id',
                'unique_key' => ['contrat_client'],
                'export_priority' => 25,
            ],
            // --- Quotes (carts) ---
            'quote' => [
                'table' => 'quote',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_DIRECT,
                'customer_column' => 'customer_id',
                // customer_id / customer_group_id must follow the customer and group on import.
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                    ['column' => 'customer_group_id', 'table' => 'customer_group'],
                ],
                // Clone mode: the denormalised email follows the cloned customer's email.
                'clone_mirror' => ['customer_email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX],
                'export_priority' => 30,
            ],
            'quote_address' => [
                'table' => 'quote_address',
                'primary_key' => 'address_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote',
                'parent_key' => 'entity_id',
                'foreign_key' => 'quote_id',
                // The address also links the customer and the customer address it was copied from.
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                    ['column' => 'customer_address_id', 'table' => 'customer_address_entity'],
                ],
                'clone_mirror' => ['email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX],
                'export_priority' => 40,
            ],
            'quote_item' => [
                'table' => 'quote_item',
                'primary_key' => 'item_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote',
                'parent_key' => 'entity_id',
                'foreign_key' => 'quote_id',
                // product_id -> target product (by sku); parent_item_id is a self-link (configurable/
                // bundle parent line) remapped to the parent item's new id.
                'references' => [
                    ['column' => 'product_id', 'table' => 'catalog_product_entity'],
                    ['column' => 'parent_item_id', 'table' => 'quote_item'],
                ],
                'export_priority' => 40,
            ],
            // Per-item option payload (info_buyRequest, attributes, simple_product, bundle/custom
            // options) — required for configurable/bundle cart lines to keep their selection.
            'quote_item_option' => [
                'table' => 'quote_item_option',
                'primary_key' => 'option_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote_item',
                'parent_key' => 'item_id',
                'foreign_key' => 'item_id',
                'references' => [
                    ['column' => 'product_id', 'table' => 'catalog_product_entity'],
                ],
                'export_priority' => 41,
                'import_priority' => 41,
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
                // An order number is a business identity (unique per store). If the target already
                // has it, match & reuse the existing order instead of inserting a duplicate — this
                // avoids the SALES_ORDER_INCREMENT_ID_STORE_ID (1062) collision on re-import.
                'unique_key' => ['increment_id', 'store_id'],
                // An order references its customer, its customer group, and the quote it came from.
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                    ['column' => 'customer_group_id', 'table' => 'customer_group'],
                    ['column' => 'quote_id', 'table' => 'quote'],
                    ['column' => 'quote_address_id', 'table' => 'quote_address'],
                    // sales_order_address imports later: rewritten by UPDATE at the end of the run.
                    ['column' => 'billing_address_id', 'table' => 'sales_order_address'],
                    ['column' => 'shipping_address_id', 'table' => 'sales_order_address'],
                ],
                // Clone mode: force a new order (skip the increment_id match) with a unique
                // increment_id so (increment_id, store_id) is not violated; the denormalised
                // customer email follows the cloned customer's email.
                'clone_new' => true,
                'clone_regenerate' => ['increment_id' => TableDefinitionInterface::STRATEGY_SUFFIX],
                'clone_mirror' => ['customer_email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX],
                'export_priority' => 50,
            ],
            'sales_order_address' => [
                'table' => 'sales_order_address',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'references' => [
                    ['column' => 'customer_id', 'table' => 'customer_entity'],
                    ['column' => 'customer_address_id', 'table' => 'customer_address_entity'],
                ],
                'clone_mirror' => ['email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX],
                // Skip when the order was matched to an existing one on the target (it already has
                // its addresses/items/payments/grid).
                'skip_if_parent_reused' => true,
                'export_priority' => 60,
            ],
            'sales_order_item' => [
                'table' => 'sales_order_item',
                'primary_key' => 'item_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'order_id',
                // product_id -> target product (by sku); quote_item_id -> the remapped quote item;
                // parent_item_id is a self-link (configurable/bundle parent line).
                'references' => [
                    ['column' => 'product_id', 'table' => 'catalog_product_entity'],
                    ['column' => 'quote_item_id', 'table' => 'quote_item'],
                    ['column' => 'parent_item_id', 'table' => 'sales_order_item'],
                ],
                'skip_if_parent_reused' => true,
                'export_priority' => 60,
            ],
            'sales_order_payment' => [
                'table' => 'sales_order_payment',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'skip_if_parent_reused' => true,
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
                'skip_if_parent_reused' => true,
                // Clone mode: mirror the cloned order's new increment_id (it has its own composite
                // unique on (increment_id, store_id)) and the cloned customer's email.
                'clone_mirror' => [
                    'increment_id' => TableDefinitionInterface::STRATEGY_SUFFIX,
                    'customer_email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX,
                ],
                'export_priority' => 60,
            ],

            // --- Customer group the customer/quotes/orders belong to ---
            // Looked up from the group ids referenced above. Exported LATE (65, after orders) so all
            // those ids are collected, but imported FIRST (4) so group_id / customer_group_id can be
            // remapped to the target group matched by code.
            'customer_group' => [
                'table' => 'customer_group',
                'primary_key' => 'customer_group_id',
                'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
                'lookup_sources' => [
                    ['table' => 'customer_entity', 'column' => 'group_id'],
                    ['table' => 'quote', 'column' => 'customer_group_id'],
                    ['table' => 'sales_order', 'column' => 'customer_group_id'],
                ],
                'unique_key' => ['customer_group_code'],
                'export_priority' => 65,
                'import_priority' => 4,
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
        ]
            + $this->productChildren()
            // customer_entity is EAV (entity_type_id 1): custom attributes live in these value
            // tables. Skipped on import when the customer is matched by email (target owns them).
            + $this->eavValueTables('customer_entity', 15, 15, true)
            // Address custom attributes. An address is never matched by unique key, so no skip flag
            // is needed here; when the address itself is skipped (customer reused) these rows are
            // dropped by cascade.
            + $this->eavValueTables('customer_address_entity', 25, 25, false);
    }

    /**
     * The five EAV backend-type value tables (`<parent>_datetime|decimal|int|text|varchar`) for an
     * EAV entity. All share the shape: PK `value_id` (auto-increment), FK `entity_id` -> parent,
     * unique (entity_id, attribute_id). The import engine remaps `entity_id` to the parent's target
     * id and lets `value_id` collisions allocate a fresh id.
     *
     * @param string $parent
     * @param int $exportPriority
     * @param int $importPriority
     * @param bool $skipIfParentReused
     * @return array<string,array<string,mixed>>
     */
    private function eavValueTables(
        string $parent,
        int $exportPriority,
        int $importPriority,
        bool $skipIfParentReused
    ): array {
        $tables = [];
        foreach (['datetime', 'decimal', 'int', 'text', 'varchar'] as $backendType) {
            $table = $parent . '_' . $backendType;
            $tables[$table] = [
                'table' => $table,
                'primary_key' => 'value_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => $parent,
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'skip_if_parent_reused' => $skipIfParentReused,
                'export_priority' => $exportPriority,
                'import_priority' => $importPriority,
            ];
        }
        return $tables;
    }

    /**
     * Product sub-tables (EAV values, website links, stock). All children of catalog_product_entity
     * and all skipped on import when the product already exists on target (matched by sku) — the
     * target already owns this data. Only stub-inserted (missing-sku) products receive it.
     *
     * @return array<string,array<string,mixed>>
     */
    private function productChildren(): array
    {
        $children = [];

        // EAV value tables link via entity_id (community edition, no content-staging row_id).
        // Includes 'gallery' so the full product EAV backend set is captured; each is store_id
        // scoped, so per-store attribute/price values come across as separate rows automatically.
        foreach (['datetime', 'decimal', 'int', 'text', 'varchar', 'gallery'] as $backendType) {
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

        // Tier (group/qty) prices. entity_id -> product (auto-derived); customer_group_id -> the
        // group matched by code. value_id is auto-increment; a fresh product id keeps the unique
        // (entity_id, all_groups, customer_group_id, qty, website_id) tuple collision-free.
        // website_id is NOT remapped (store_website is outside the map — must align on target).
        $children['catalog_product_entity_tier_price'] = [
            'table' => 'catalog_product_entity_tier_price',
            'primary_key' => 'value_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'catalog_product_entity',
            'parent_key' => 'entity_id',
            'foreign_key' => 'entity_id',
            'references' => [
                ['column' => 'customer_group_id', 'table' => 'customer_group'],
            ],
            'skip_if_parent_reused' => true,
            'export_priority' => 71,
            'import_priority' => 6,
        ];

        return $children;
    }
}
