<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Integration\Fixture;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * A small but complete customer footprint written straight into the database:
 *
 *   customer (+ one EAV varchar value when the install has such an attribute)
 *     └─ address (set as default billing & shipping)
 *   product (simple, unique sku)
 *   quote  ─ 2 items (parent + child line) ─ 1 option on the parent ─ 2 addresses
 *          ─ guest-cart mask ─ 1 shipping rate on the shipping address
 *   order  ─ 2 items (parent + child line) ─ billing & shipping address ─ 1 payment
 *          ─ invoice (+ item, grid) ─ shipment (+ item, track, grid) ─ status history
 *          ─ order tax (+ item) ─ payment transaction ─ gift message
 *   product custom option (+ title, price); family-set price when the install has the attribute
 *
 * Values are chosen to exercise the CSV round trip: a "+33" phone number, an empty-string
 * remote_ip, NULLs, and JSON with quotes in the option / product_options payloads.
 */
class CustomerFootprint
{
    public const string PHONE = '+33 1 23 45 67 89';
    public const string OPTION_VALUE = '{"qty":1,"super_attribute":{"93":"52"},"note":"5\" long"}';

    /** @var int */
    public int $customerId = 0;
    /** @var string */
    public string $email = '';
    /** @var int */
    public int $addressId = 0;
    /** @var int */
    public int $productId = 0;
    /** @var string */
    public string $sku = '';
    /** @var int */
    public int $quoteId = 0;
    /** @var int */
    public int $quoteParentItemId = 0;
    /** @var int */
    public int $quoteChildItemId = 0;
    /** @var int */
    public int $orderId = 0;
    /** @var string */
    public string $incrementId = '';
    /** @var int */
    public int $orderBillingAddressId = 0;
    /** @var int */
    public int $orderShippingAddressId = 0;
    /** @var int */
    public int $orderParentItemId = 0;
    /** @var int */
    public int $orderChildItemId = 0;
    /** @var int */
    public int $orderPaymentId = 0;
    /** @var int */
    public int $quoteShippingAddressId = 0;
    /** @var int */
    public int $invoiceId = 0;
    /** @var string */
    public string $invoiceIncrementId = '';
    /** @var int */
    public int $shipmentId = 0;
    /** @var int */
    public int $giftMessageId = 0;
    /** @var int */
    public int $optionId = 0;
    /** @var string|null the family-set code given to the product, null when the install lacks the attribute */
    public ?string $familySetCode = null;

    /** @var AdapterInterface */
    private readonly AdapterInterface $connection;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(private readonly ResourceConnection $resource)
    {
        $this->connection = $resource->getConnection();
    }

    /**
     * Insert the whole footprint. Business keys are unique per call.
     *
     * @return $this
     */
    public function create(): self
    {
        $tag = uniqid('dbtool', true);
        $this->email = 'dbtool-' . $tag . '@example.test';
        $this->sku = 'DBTOOL-' . $tag;
        $this->incrementId = 'DBT' . substr(hash('sha256', $tag), 0, 12);

        $this->customerId = $this->insert('customer_entity', [
            'website_id' => 1,
            'email' => $this->email,
            'group_id' => 1,
            'store_id' => 1,
            'created_in' => 'DbTool test',
            'firstname' => 'DbTool',
            'lastname' => 'Fixture',
            'is_active' => 1,
        ]);
        $attributeId = $this->connection->fetchOne(
            $this->connection->select()
                ->from($this->table('eav_attribute'), ['attribute_id'])
                ->where('entity_type_id = ?', 1)
                ->where('backend_type = ?', 'varchar')
                ->limit(1)
        );
        if ($attributeId) {
            $this->insert('customer_entity_varchar', [
                'attribute_id' => (int)$attributeId,
                'entity_id' => $this->customerId,
                'value' => 'dbtool-eav-' . $tag,
            ]);
        }
        $this->addressId = $this->insert('customer_address_entity', $this->address([
            'parent_id' => $this->customerId,
            'is_active' => 1,
        ]));
        $this->connection->update(
            $this->table('customer_entity'),
            ['default_billing' => $this->addressId, 'default_shipping' => $this->addressId],
            ['entity_id = ?' => $this->customerId]
        );

        $this->productId = $this->insert('catalog_product_entity', [
            'attribute_set_id' => 4,
            'type_id' => 'simple',
            'sku' => $this->sku,
        ]);

        $this->quoteId = $this->insert('quote', [
            'store_id' => 1,
            'is_active' => 1,
            'customer_id' => $this->customerId,
            'customer_email' => $this->email,
            'customer_group_id' => 1,
            'customer_firstname' => 'DbTool',
            'customer_lastname' => 'Fixture',
            'customer_is_guest' => 0,
            'items_count' => 1,
            'items_qty' => 1,
        ]);
        $this->quoteParentItemId = $this->insert('quote_item', [
            'quote_id' => $this->quoteId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => 'DbTool parent line',
            'qty' => 1,
            'product_type' => 'configurable',
        ]);
        $this->quoteChildItemId = $this->insert('quote_item', [
            'quote_id' => $this->quoteId,
            'parent_item_id' => $this->quoteParentItemId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => 'DbTool child line',
            'qty' => 1,
            'product_type' => 'simple',
        ]);
        $this->insert('quote_item_option', [
            'item_id' => $this->quoteParentItemId,
            'product_id' => $this->productId,
            'code' => 'info_buyRequest',
            'value' => self::OPTION_VALUE,
        ]);
        foreach (['billing', 'shipping'] as $type) {
            $this->quoteShippingAddressId = $this->insert('quote_address', $this->address([
                'quote_id' => $this->quoteId,
                'customer_id' => $this->customerId,
                'customer_address_id' => $this->addressId,
                'address_type' => $type,
                'email' => $this->email,
            ]));
        }
        $this->insert('quote_shipping_rate', [
            'address_id' => $this->quoteShippingAddressId,
            'carrier' => 'flatrate',
            'carrier_title' => 'Flat Rate',
            'code' => 'flatrate_flatrate',
            'method' => 'flatrate',
            'method_title' => 'Fixed',
            'price' => 5,
        ]);
        $this->insert('quote_id_mask', [
            'quote_id' => $this->quoteId,
            'masked_id' => substr(hash('sha256', 'mask' . $tag), 0, 32),
        ]);

        $this->giftMessageId = $this->insert('gift_message', [
            'customer_id' => $this->customerId,
            'sender' => 'DbTool',
            'recipient' => 'Fixture',
            'message' => 'Happy testing',
        ]);

        $this->orderId = $this->insert('sales_order', [
            'state' => 'new',
            'status' => 'pending',
            'store_id' => 1,
            'customer_id' => $this->customerId,
            'customer_email' => $this->email,
            'customer_group_id' => 1,
            'customer_firstname' => 'DbTool',
            'customer_lastname' => 'Fixture',
            'customer_is_guest' => 0,
            'increment_id' => $this->incrementId,
            'quote_id' => $this->quoteId,
            'gift_message_id' => $this->giftMessageId,
            'grand_total' => 10,
            'base_grand_total' => 10,
            'remote_ip' => '',
            'base_currency_code' => 'EUR',
            'order_currency_code' => 'EUR',
            'store_currency_code' => 'EUR',
            'global_currency_code' => 'EUR',
        ]);
        $this->orderBillingAddressId = $this->insert('sales_order_address', $this->address([
            'parent_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'customer_address_id' => $this->addressId,
            'address_type' => 'billing',
            'email' => $this->email,
        ]));
        $this->orderShippingAddressId = $this->insert('sales_order_address', $this->address([
            'parent_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'customer_address_id' => $this->addressId,
            'address_type' => 'shipping',
            'email' => $this->email,
        ]));
        $this->connection->update(
            $this->table('sales_order'),
            [
                'billing_address_id' => $this->orderBillingAddressId,
                'shipping_address_id' => $this->orderShippingAddressId,
            ],
            ['entity_id = ?' => $this->orderId]
        );
        $this->orderParentItemId = $this->insert('sales_order_item', [
            'order_id' => $this->orderId,
            'quote_item_id' => $this->quoteParentItemId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => 'DbTool parent line',
            'qty_ordered' => 1,
            'product_type' => 'configurable',
            'product_options' => '{"info_buyRequest":' . self::OPTION_VALUE . '}',
        ]);
        $this->orderChildItemId = $this->insert('sales_order_item', [
            'order_id' => $this->orderId,
            'parent_item_id' => $this->orderParentItemId,
            'quote_item_id' => $this->quoteChildItemId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => 'DbTool child line',
            'qty_ordered' => 1,
            'product_type' => 'simple',
        ]);
        $this->orderPaymentId = $this->insert('sales_order_payment', [
            'parent_id' => $this->orderId,
            'method' => 'checkmo',
        ]);
        $this->insert('sales_payment_transaction', [
            'order_id' => $this->orderId,
            'payment_id' => $this->orderPaymentId,
            'txn_id' => 'dbtool-txn-' . $tag,
            'txn_type' => 'capture',
            'is_closed' => 1,
        ]);
        $this->insert('sales_order_status_history', [
            'parent_id' => $this->orderId,
            'status' => 'pending',
            'comment' => 'DbTool fixture history',
            'entity_name' => 'order',
        ]);
        $taxId = $this->insert('sales_order_tax', [
            'order_id' => $this->orderId,
            'code' => 'FR-TVA',
            'title' => 'TVA',
            'percent' => 20,
            'amount' => 2,
            'base_amount' => 2,
            'base_real_amount' => 2,
        ]);
        $this->insert('sales_order_tax_item', [
            'tax_id' => $taxId,
            'item_id' => $this->orderParentItemId,
            'tax_percent' => 20,
            'amount' => 2,
            'base_amount' => 2,
            'real_amount' => 2,
            'real_base_amount' => 2,
            'taxable_item_type' => 'product',
        ]);

        $this->invoiceIncrementId = 'INV' . substr(hash('sha256', 'inv' . $tag), 0, 12);
        $this->invoiceId = $this->insert('sales_invoice', [
            'store_id' => 1,
            'order_id' => $this->orderId,
            'increment_id' => $this->invoiceIncrementId,
            'state' => 2,
            'grand_total' => 10,
            'base_grand_total' => 10,
            'billing_address_id' => $this->orderBillingAddressId,
            'shipping_address_id' => $this->orderShippingAddressId,
            'order_currency_code' => 'EUR',
            'base_currency_code' => 'EUR',
            'store_currency_code' => 'EUR',
            'global_currency_code' => 'EUR',
        ]);
        $this->insert('sales_invoice_item', [
            'parent_id' => $this->invoiceId,
            'order_item_id' => $this->orderParentItemId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => 'DbTool parent line',
            'qty' => 1,
        ]);
        $this->insert('sales_invoice_grid', [
            'entity_id' => $this->invoiceId,
            'increment_id' => $this->invoiceIncrementId,
            'order_id' => $this->orderId,
            'order_increment_id' => $this->incrementId,
            'store_id' => 1,
            'customer_email' => $this->email,
        ]);

        $shipmentIncrementId = 'SHP' . substr(hash('sha256', 'shp' . $tag), 0, 12);
        $this->shipmentId = $this->insert('sales_shipment', [
            'store_id' => 1,
            'order_id' => $this->orderId,
            'customer_id' => $this->customerId,
            'increment_id' => $shipmentIncrementId,
            'total_qty' => 1,
            'billing_address_id' => $this->orderBillingAddressId,
            'shipping_address_id' => $this->orderShippingAddressId,
        ]);
        $this->insert('sales_shipment_item', [
            'parent_id' => $this->shipmentId,
            'order_item_id' => $this->orderParentItemId,
            'product_id' => $this->productId,
            'sku' => $this->sku,
            'name' => 'DbTool parent line',
            'qty' => 1,
        ]);
        $this->insert('sales_shipment_track', [
            'parent_id' => $this->shipmentId,
            'order_id' => $this->orderId,
            'track_number' => 'TRK' . $tag,
            'title' => 'Colissimo',
            'carrier_code' => 'custom',
        ]);
        $this->insert('sales_shipment_grid', [
            'entity_id' => $this->shipmentId,
            'increment_id' => $shipmentIncrementId,
            'order_id' => $this->orderId,
            'order_increment_id' => $this->incrementId,
            'store_id' => 1,
            'customer_email' => $this->email,
        ]);

        $this->optionId = $this->insert('catalog_product_option', [
            'product_id' => $this->productId,
            'type' => 'field',
            'is_require' => 0,
            'sort_order' => 0,
        ]);
        $this->insert('catalog_product_option_title', [
            'option_id' => $this->optionId,
            'store_id' => 0,
            'title' => 'Engraving',
        ]);
        $this->insert('catalog_product_option_price', [
            'option_id' => $this->optionId,
            'store_id' => 0,
            'price' => 1,
            'price_type' => 'fixed',
        ]);

        $familySetAttribute = $this->connection->fetchOne(
            $this->connection->select()
                ->from(['a' => $this->table('eav_attribute')], ['attribute_id'])
                ->join(['t' => $this->table('eav_entity_type')], 't.entity_type_id = a.entity_type_id', [])
                ->where('t.entity_type_code = ?', 'catalog_product')
                ->where('a.attribute_code = ?', 'family_set')
        );
        if ($familySetAttribute && $this->connection->isTableExists($this->table('ecomeu_familyset_tier_price'))) {
            $this->familySetCode = 'DBT-FS-' . $tag;
            $this->insert('catalog_product_entity_varchar', [
                'attribute_id' => (int)$familySetAttribute,
                'store_id' => 0,
                'entity_id' => $this->productId,
                'value' => $this->familySetCode,
            ]);
            $this->insert('ecomeu_familyset_tier_price', [
                'store_id' => 1,
                'familyset_code' => $this->familySetCode,
                'qty_from' => 1,
                'unit_price' => 9.5,
            ]);
        }

        return $this;
    }

    /**
     * Remove the customer, their cart and their order (children go by FK cascade). The product is
     * kept: on a target database the catalog normally already holds it.
     *
     * @return void
     */
    public function deleteCustomerFootprint(): void
    {
        // Grids and order taxes have no foreign key; everything else cascades from the order, the
        // cart and the customer.
        $grids = ['sales_invoice_grid' => $this->invoiceId, 'sales_shipment_grid' => $this->shipmentId];
        foreach ($grids as $grid => $id) {
            $this->connection->delete($this->table($grid), ['entity_id = ?' => $id]);
        }
        $this->connection->delete($this->table('sales_order_tax'), ['order_id = ?' => $this->orderId]);
        $this->connection->delete($this->table('sales_order'), ['entity_id = ?' => $this->orderId]);
        $this->connection->delete($this->table('quote'), ['entity_id = ?' => $this->quoteId]);
        $this->connection->delete($this->table('gift_message'), ['gift_message_id = ?' => $this->giftMessageId]);
        $this->connection->delete($this->table('customer_entity'), ['entity_id = ?' => $this->customerId]);
    }

    /**
     * Remove the product (its option, EAV values and links cascade by FK) and the family-set price.
     * Only needed by a test that runs outside the per-test transaction.
     *
     * @return void
     */
    public function deleteProduct(): void
    {
        if ($this->familySetCode !== null) {
            $this->connection->delete(
                $this->table('ecomeu_familyset_tier_price'),
                ['familyset_code = ?' => $this->familySetCode]
            );
        }
        $this->connection->delete($this->table('catalog_product_entity'), ['entity_id = ?' => $this->productId]);
    }

    /**
     * Address columns shared by the customer, quote and order address tables.
     *
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function address(array $extra): array
    {
        return $extra + [
            'firstname' => 'DbTool',
            'lastname' => 'Fixture',
            'street' => "1 rue du Test\nBâtiment B",
            'city' => 'Paris',
            'country_id' => 'FR',
            'postcode' => '75001',
            'telephone' => self::PHONE,
        ];
    }

    /**
     * Insert one row and return its auto-increment id.
     *
     * @param string $table
     * @param array<string,mixed> $row
     * @return int
     */
    private function insert(string $table, array $row): int
    {
        $this->connection->insert($this->table($table), $row);
        return (int)$this->connection->fetchOne('SELECT LAST_INSERT_ID()');
    }

    /**
     * Prefixed table name.
     *
     * @param string $table
     * @return string
     */
    private function table(string $table): string
    {
        return $this->resource->getTableName($table);
    }
}
