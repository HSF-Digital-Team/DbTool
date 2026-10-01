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
 *   order  ─ 2 items (parent + child line) ─ billing & shipping address ─ 1 payment
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
            $this->insert('quote_address', $this->address([
                'quote_id' => $this->quoteId,
                'customer_id' => $this->customerId,
                'customer_address_id' => $this->addressId,
                'address_type' => $type,
                'email' => $this->email,
            ]));
        }

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
        $this->insert('sales_order_payment', [
            'parent_id' => $this->orderId,
            'method' => 'checkmo',
        ]);

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
        $this->connection->delete($this->table('sales_order'), ['entity_id = ?' => $this->orderId]);
        $this->connection->delete($this->table('quote'), ['entity_id = ?' => $this->quoteId]);
        $this->connection->delete($this->table('customer_entity'), ['entity_id = ?' => $this->customerId]);
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
