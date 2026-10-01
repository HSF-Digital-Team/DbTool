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
 *     ├─ customer_entity_{datetime,decimal,int,text,varchar}.entity_id
 *     ├─ customer_grid_flat.entity_id
 *     ├─ customer_address_entity.parent_id  (customer_entity.default_billing/shipping point back)
 *     │    └─ customer_address_entity_{datetime,decimal,int,text,varchar}.entity_id
 *     ├─ contrat_aladin_clients.customer_id
 *     └─ gift_message.customer_id  (referenced by quote/quote_item/sales_order/sales_order_item)
 *   quote.customer_id -> customer_entity.entity_id  (a child: skipped when the customer is reused)
 *     ├─ quote_address.quote_id
 *     │    ├─ quote_address_item.quote_address_id  (multishipping lines)
 *     │    └─ quote_shipping_rate.address_id
 *     ├─ quote_item.quote_id  (parent_item_id self-link)
 *     │    └─ quote_item_option.item_id
 *     ├─ quote_payment.quote_id
 *     └─ quote_id_mask.quote_id
 *   sales_order.customer_id -> customer_entity.entity_id
 *     ├─ sales_order_address.parent_id  (sales_order.billing/shipping_address_id point back)
 *     ├─ sales_order_item.order_id  (parent_item_id self-link, quote_item_id -> quote_item)
 *     ├─ sales_order_payment.parent_id
 *     │    └─ sales_payment_transaction.payment_id  (parent_id self-link)
 *     ├─ sales_order_grid.entity_id
 *     ├─ sales_order_status_history.parent_id
 *     ├─ sales_order_tax.order_id ─ sales_order_tax_item.tax_id
 *     ├─ sales_invoice.order_id ─ _item / _comment / _grid
 *     ├─ sales_shipment.order_id ─ _item / _track / _comment / _grid
 *     └─ sales_creditmemo.order_id ─ _item / _comment / _grid   (see orderDocuments())
 *   Lookups (exported late, imported first):
 *     customer_group        <- customer_entity.group_id, quote/sales_order.customer_group_id,
 *                              catalog_product_entity_tier_price.customer_group_id
 *     catalog_product_entity <- quote_item/sales_order_item.product_id
 *       ├─ EAV values, website, stock item/status, tier price (see productChildren())
 *       ├─ custom options, bundle options/selections, links, super attributes/links, relations,
 *       │  category placement (see productComposition())
 *       └─ media gallery rows (see mediaGallery())
 *     catalog_product_entity_media_gallery <- catalog_product_entity_media_gallery_value_to_entity.value_id
 *     ecomeu_familyset_tier_price <- catalog_product_entity_varchar.value (family_set attribute)
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
            // A child of the customer (not a direct table): a cart has no natural key, so when the
            // customer already exists on the target the cart is skipped like the addresses are,
            // instead of being duplicated on every restore.
            'quote' => [
                'table' => 'quote',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'customer_id',
                'skip_if_parent_reused' => true,
                'references' => [
                    ['column' => 'customer_group_id', 'table' => 'customer_group'],
                    ['column' => 'gift_message_id', 'table' => 'gift_message'],
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
                    ['column' => 'gift_message_id', 'table' => 'gift_message'],
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
            // Guest-cart token (masked id is not unique-indexed), multishipping lines, cached rates.
            'quote_id_mask' => [
                'table' => 'quote_id_mask',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote',
                'parent_key' => 'entity_id',
                'foreign_key' => 'quote_id',
                'export_priority' => 41,
            ],
            'quote_address_item' => [
                'table' => 'quote_address_item',
                'primary_key' => 'address_item_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote_address',
                'parent_key' => 'address_id',
                'foreign_key' => 'quote_address_id',
                'references' => [
                    ['column' => 'quote_item_id', 'table' => 'quote_item'],
                    ['column' => 'parent_item_id', 'table' => 'quote_address_item'],
                    ['column' => 'product_id', 'table' => 'catalog_product_entity'],
                    ['column' => 'gift_message_id', 'table' => 'gift_message'],
                ],
                'export_priority' => 41,
            ],
            'quote_shipping_rate' => [
                'table' => 'quote_shipping_rate',
                'primary_key' => 'rate_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'quote_address',
                'parent_key' => 'address_id',
                'foreign_key' => 'address_id',
                'export_priority' => 41,
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
                    ['column' => 'gift_message_id', 'table' => 'gift_message'],
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
                    ['column' => 'gift_message_id', 'table' => 'gift_message'],
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
            // Looked up from every group id referenced in the footprint (customer, carts, orders and
            // the tier prices of the exported products). Exported LAST (72, after the tier prices) so
            // all those ids are collected, but imported FIRST (4) so each customer_group_id can be
            // remapped to the target group matched by code.
            'customer_group' => [
                'table' => 'customer_group',
                'primary_key' => 'customer_group_id',
                'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
                'lookup_sources' => [
                    ['table' => 'customer_entity', 'column' => 'group_id'],
                    ['table' => 'quote', 'column' => 'customer_group_id'],
                    ['table' => 'sales_order', 'column' => 'customer_group_id'],
                    ['table' => 'catalog_product_entity_tier_price', 'column' => 'customer_group_id'],
                ],
                'unique_key' => ['customer_group_code'],
                'export_priority' => 72,
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
            // --- Gift messages (referenced by carts, orders and their lines) ---
            // They carry the customer id, so they are a child of the customer: skipped when the
            // customer is reused (the order/cart rows that reference them are skipped too), imported
            // before the carts and orders so their gift_message_id can be remapped.
            'gift_message' => [
                'table' => 'gift_message',
                'primary_key' => 'gift_message_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'customer_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'customer_id',
                'skip_if_parent_reused' => true,
                'export_priority' => 15,
                'import_priority' => 12,
            ],
        ]
            + $this->orderDocuments()
            + $this->productChildren()
            + $this->productComposition()
            + $this->mediaGallery()
            + $this->familySetPrices()
            // customer_entity is EAV (entity_type_id 1): custom attributes live in these value
            // tables. Skipped on import when the customer is matched by email (target owns them).
            + $this->eavValueTables('customer_entity', 15, 15, true)
            // Address custom attributes. An address is never matched by unique key, so no skip flag
            // is needed here; when the address itself is skipped (customer reused) these rows are
            // dropped by cascade.
            + $this->eavValueTables('customer_address_entity', 25, 25, false);
    }

    /**
     * Order documents and extras: invoices, shipments, credit memos (+ their items, comments,
     * tracks and grids), status history, taxes and payment transactions. All children of the order
     * (skipped when the order was matched on the target). A document has its own increment_id,
     * unique per store, so it is matched like an order and, in clone mode, force-created with a
     * regenerated number that its grid mirrors.
     *
     * @return array<string,array<string,mixed>>
     */
    private function orderDocuments(): array
    {
        $tables = [];
        $product = ['column' => 'product_id', 'table' => 'catalog_product_entity'];
        $orderItem = ['column' => 'order_item_id', 'table' => 'sales_order_item'];
        $addresses = [
            ['column' => 'billing_address_id', 'table' => 'sales_order_address'],
            ['column' => 'shipping_address_id', 'table' => 'sales_order_address'],
        ];
        $gridMirror = [
            'increment_id' => TableDefinitionInterface::STRATEGY_SUFFIX,
            'order_increment_id' => TableDefinitionInterface::STRATEGY_SUFFIX,
            'customer_email' => TableDefinitionInterface::STRATEGY_EMAIL_SUFFIX,
        ];

        foreach (['sales_invoice' => 61, 'sales_shipment' => 61, 'sales_creditmemo' => 62] as $document => $priority) {
            $references = $addresses;
            if ($document === 'sales_shipment') {
                $references[] = ['column' => 'customer_id', 'table' => 'customer_entity'];
            }
            if ($document === 'sales_creditmemo') {
                $references[] = ['column' => 'invoice_id', 'table' => 'sales_invoice'];
            }
            $tables[$document] = [
                'table' => $document,
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'sales_order',
                'parent_key' => 'entity_id',
                'foreign_key' => 'order_id',
                'unique_key' => ['increment_id', 'store_id'],
                'references' => $references,
                'skip_if_parent_reused' => true,
                'clone_new' => true,
                'clone_regenerate' => ['increment_id' => TableDefinitionInterface::STRATEGY_SUFFIX],
                'export_priority' => $priority,
            ];
            $tables[$document . '_item'] = [
                'table' => $document . '_item',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => $document,
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'references' => [$orderItem, $product],
                'export_priority' => $priority + 1,
            ];
            $tables[$document . '_comment'] = [
                'table' => $document . '_comment',
                'primary_key' => 'entity_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => $document,
                'parent_key' => 'entity_id',
                'foreign_key' => 'parent_id',
                'export_priority' => $priority + 1,
            ];
            $tables[$document . '_grid'] = [
                'table' => $document . '_grid',
                'primary_key' => 'entity_id', // = the document id, non-auto
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => $document,
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'references' => [
                    ['column' => 'order_id', 'table' => 'sales_order'],
                ],
                'skip_if_parent_reused' => true,
                'clone_mirror' => $gridMirror,
                'export_priority' => $priority + 1,
            ];
        }
        $tables['sales_shipment_track'] = [
            'table' => 'sales_shipment_track',
            'primary_key' => 'entity_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'sales_shipment',
            'parent_key' => 'entity_id',
            'foreign_key' => 'parent_id',
            'references' => [
                ['column' => 'order_id', 'table' => 'sales_order'],
            ],
            'export_priority' => 62,
        ];

        $tables['sales_order_status_history'] = [
            'table' => 'sales_order_status_history',
            'primary_key' => 'entity_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'sales_order',
            'parent_key' => 'entity_id',
            'foreign_key' => 'parent_id',
            'skip_if_parent_reused' => true,
            'export_priority' => 61,
        ];
        $tables['sales_order_tax'] = [
            'table' => 'sales_order_tax',
            'primary_key' => 'tax_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'sales_order',
            'parent_key' => 'entity_id',
            'foreign_key' => 'order_id',
            'skip_if_parent_reused' => true,
            'export_priority' => 61,
        ];
        $tables['sales_order_tax_item'] = [
            'table' => 'sales_order_tax_item',
            'primary_key' => 'tax_item_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'sales_order_tax',
            'parent_key' => 'tax_id',
            'foreign_key' => 'tax_id',
            'references' => [
                ['column' => 'item_id', 'table' => 'sales_order_item'],
                ['column' => 'associated_item_id', 'table' => 'sales_order_item'],
            ],
            'export_priority' => 62,
        ];
        $tables['sales_payment_transaction'] = [
            'table' => 'sales_payment_transaction',
            'primary_key' => 'transaction_id',
            'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
            'parent' => 'sales_order',
            'parent_key' => 'entity_id',
            'foreign_key' => 'order_id',
            'references' => [
                ['column' => 'payment_id', 'table' => 'sales_order_payment'],
                ['column' => 'parent_id', 'table' => 'sales_payment_transaction'],
            ],
            'skip_if_parent_reused' => true,
            'export_priority' => 61,
        ];

        return $tables;
    }

    /**
     * Product composition and placement: custom options (with per-store prices/titles), bundle
     * options/selections, product links, configurable super attributes/links, relations and
     * category placement. All hang off catalog_product_entity and are skipped when the product was
     * matched on the target; only a stub-inserted (missing-sku) product receives them. A link to a
     * product or category that exists nowhere on the target drops that row (not the import).
     *
     * @return array<string,array<string,mixed>>
     */
    private function productComposition(): array
    {
        $product = ['column' => 'product_id', 'table' => 'catalog_product_entity'];
        $parentProduct = ['column' => 'parent_product_id', 'table' => 'catalog_product_entity'];
        $linked = ['column' => 'linked_product_id', 'table' => 'catalog_product_entity'];
        $childProduct = ['column' => 'child_id', 'table' => 'catalog_product_entity'];
        $category = ['column' => 'category_id', 'table' => 'catalog_category_entity', 'key' => 'entity_id'];

        // [table, primary key, parent table, parent key, foreign key, level below the product, extra]
        $specs = [
            // Custom options: option -> price/title/type_value -> type_price/type_title.
            ['catalog_product_option', 'option_id', 'catalog_product_entity', 'entity_id', 'product_id', 1],
            ['catalog_product_option_price', 'option_price_id', 'catalog_product_option', 'option_id', 'option_id', 2],
            ['catalog_product_option_title', 'option_title_id', 'catalog_product_option', 'option_id', 'option_id', 2],
            [
                'catalog_product_option_type_value', 'option_type_id', 'catalog_product_option', 'option_id',
                'option_id', 2,
            ],
            [
                'catalog_product_option_type_price', 'option_type_price_id',
                'catalog_product_option_type_value', 'option_type_id', 'option_type_id', 3,
            ],
            [
                'catalog_product_option_type_title', 'option_type_title_id',
                'catalog_product_option_type_value', 'option_type_id', 'option_type_id', 3,
            ],
            // Bundle: option -> option_value / selection -> selection_price.
            ['catalog_product_bundle_option', 'option_id', 'catalog_product_entity', 'entity_id', 'parent_id', 1],
            [
                'catalog_product_bundle_option_value', 'value_id', 'catalog_product_bundle_option', 'option_id',
                'option_id', 2, ['references' => [$parentProduct]],
            ],
            [
                'catalog_product_bundle_selection', 'selection_id', 'catalog_product_bundle_option', 'option_id',
                'option_id', 2, ['references' => [$parentProduct, $product]],
            ],
            [
                'catalog_product_bundle_selection_price', 'selection_id', 'catalog_product_bundle_selection',
                'selection_id', 'selection_id', 3, ['references' => [$parentProduct]],
            ],
            // Related/up-sell/cross-sell links (+ their position attributes).
            [
                'catalog_product_link', 'link_id', 'catalog_product_entity', 'entity_id', 'product_id', 1,
                ['references' => [$linked]],
            ],
            ['catalog_product_link_attribute_decimal', 'value_id', 'catalog_product_link', 'link_id', 'link_id', 2],
            ['catalog_product_link_attribute_int', 'value_id', 'catalog_product_link', 'link_id', 'link_id', 2],
            ['catalog_product_link_attribute_varchar', 'value_id', 'catalog_product_link', 'link_id', 'link_id', 2],
            // Configurable data, parent/child relations, category placement.
            [
                'catalog_product_super_attribute', 'product_super_attribute_id', 'catalog_product_entity',
                'entity_id', 'product_id', 1,
            ],
            [
                'catalog_product_super_attribute_label', 'value_id', 'catalog_product_super_attribute',
                'product_super_attribute_id', 'product_super_attribute_id', 2,
            ],
            [
                'catalog_product_super_link', 'link_id', 'catalog_product_entity', 'entity_id', 'parent_id', 1,
                ['references' => [$product]],
            ],
            [
                'catalog_product_relation', 'parent_id', 'catalog_product_entity', 'entity_id', 'parent_id', 1,
                ['references' => [$childProduct]],
            ],
            [
                'catalog_category_product', 'entity_id', 'catalog_product_entity', 'entity_id', 'product_id', 1,
                ['requires' => [$category]],
            ],
        ];

        $tables = [];
        foreach ($specs as $spec) {
            [$table, $pk, $parent, $parentKey, $fk, $level] = $spec;
            $tables[$table] = ($spec[6] ?? []) + [
                'table' => $table,
                'primary_key' => $pk,
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => $parent,
                'parent_key' => $parentKey,
                'foreign_key' => $fk,
                // Direct children of the product are skipped when it was matched; deeper levels
                // follow by cascade.
                'skip_if_parent_reused' => $level === 1,
                'export_priority' => 70 + $level,
                'import_priority' => 5 + $level,
            ];
        }
        return $tables;
    }

    /**
     * Media gallery rows (the image files on disk are not copied). The gallery entry itself is a
     * lookup gathered from the product's value_to_entity links and matched on the target by
     * (attribute_id, value), i.e. the image path; the per-product link, label and video rows hang
     * off the product and are skipped when it was matched.
     *
     * @return array<string,array<string,mixed>>
     */
    private function mediaGallery(): array
    {
        $gallery = ['column' => 'value_id', 'table' => 'catalog_product_entity_media_gallery'];
        return [
            'catalog_product_entity_media_gallery_value_to_entity' => [
                'table' => 'catalog_product_entity_media_gallery_value_to_entity',
                'primary_key' => 'value_id', // composite (value_id, entity_id); non-auto
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'catalog_product_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'references' => [$gallery],
                'skip_if_parent_reused' => true,
                'export_priority' => 71,
                'import_priority' => 8,
            ],
            'catalog_product_entity_media_gallery_value' => [
                'table' => 'catalog_product_entity_media_gallery_value',
                'primary_key' => 'record_id',
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'catalog_product_entity',
                'parent_key' => 'entity_id',
                'foreign_key' => 'entity_id',
                'references' => [$gallery],
                'skip_if_parent_reused' => true,
                'export_priority' => 71,
                'import_priority' => 8,
            ],
            'catalog_product_entity_media_gallery' => [
                'table' => 'catalog_product_entity_media_gallery',
                'primary_key' => 'value_id',
                'relationship_type' => TableDefinitionInterface::RELATION_LOOKUP,
                'lookup_sources' => [
                    ['table' => 'catalog_product_entity_media_gallery_value_to_entity', 'column' => 'value_id'],
                ],
                'unique_key' => ['attribute_id', 'value'],
                'export_priority' => 72,
                'import_priority' => 7,
            ],
            'catalog_product_entity_media_gallery_value_video' => [
                'table' => 'catalog_product_entity_media_gallery_value_video',
                'primary_key' => 'value_id', // composite (value_id, store_id); non-auto
                'relationship_type' => TableDefinitionInterface::RELATION_CHILD,
                'parent' => 'catalog_product_entity_media_gallery',
                'parent_key' => 'value_id',
                'foreign_key' => 'value_id',
                'skip_if_parent_reused' => true,
                'export_priority' => 73,
                'import_priority' => 8,
            ],
        ];
    }

    /**
     * This store's family-set quantity-break prices. The table is keyed by the family-set code,
     * which is the product's `family_set` attribute value, so it is a lookup on `familyset_code`
     * gathered from the varchar value rows of that one attribute. Rows are matched on the target by
     * their unique (code, store, qty, date) tuple.
     *
     * @return array<string,array<string,mixed>>
     */
    private function familySetPrices(): array
    {
        return [
            'ecomeu_familyset_tier_price' => [
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
                ],
                'unique_key' => ['familyset_code', 'store_id', 'qty_from', 'date_from'],
                'export_priority' => 72,
                'import_priority' => 7,
            ],
        ];
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
