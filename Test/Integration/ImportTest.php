<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Integration;

use EcomEU\DbTool\Test\Integration\Fixture\CustomerFootprint;

/**
 * Import of an exported footprint into a database where the account is already taken (same
 * customer present; or just the ids taken by somebody else) and where it is free.
 */
class ImportTest extends AbstractDbToolTestCase
{
    /**
     * The same customer already exists: everything is matched and reused, nothing is duplicated.
     * Only the cart is re-inserted, because a quote has no natural key (documented limitation).
     *
     * @return void
     */
    public function testRestoreOntoTheSameAccountReusesIt(): void
    {
        $f = $this->fixture;
        $this->exportCustomer($f->customerId);
        $this->stageForImport($f->customerId);

        $result = $this->importer->import($f->customerId);

        self::assertSame([$f->customerId], array_map('intval', $result['new_customer_ids']));
        self::assertSame(1, $this->countRows('customer_entity', 'email', $f->email), 'customer not duplicated');
        self::assertSame(1, $this->countRows('customer_address_entity', 'parent_id', $f->customerId));
        self::assertSame(1, $this->countRows('sales_order', 'increment_id', $f->incrementId), 'order matched');
        self::assertSame(1, $this->countRows('catalog_product_entity', 'sku', $f->sku), 'product matched');
        self::assertSame(2, $this->countRows('quote', 'customer_id', $f->customerId), 'cart is duplicated');
        self::assertSame(
            ['quote' => 1, 'quote_address' => 2, 'quote_item' => 2, 'quote_item_option' => 1],
            array_filter($result['tables']),
            'only the cart rows are written'
        );
        self::assertSame(0, $result['deferred_updates']);
    }

    /**
     * The ids are taken but the business keys are new (a different customer with the same ids,
     * i.e. an export from another database): every row gets a fresh id and every link follows.
     *
     * @return void
     */
    public function testRestoreOntoTakenIdsAssignsNewIdsAndRelinksEverything(): void
    {
        $f = $this->fixture;
        $this->exportCustomer($f->customerId);
        $dir = $this->stageForImport($f->customerId);
        $newEmail = 'other-' . $f->email;
        $newIncrement = 'X' . $f->incrementId;
        $this->rewriteCsv($dir, 'customer_entity', fn (array $r) => ['email' => $newEmail] + $r);
        $this->rewriteCsv($dir, 'quote', fn (array $r) => ['customer_email' => $newEmail] + $r);
        $this->rewriteCsv($dir, 'sales_order', fn (array $r) => [
            'increment_id' => $newIncrement,
            'customer_email' => $newEmail,
        ] + $r);

        $result = $this->importer->import($f->customerId);

        // --- a new customer, with its own address as default and its EAV/grid data
        $newId = (int)$result['new_customer_ids'][0];
        self::assertNotSame($f->customerId, $newId, 'the taken id is not reused');
        $customer = $this->row('customer_entity', 'entity_id', $newId);
        self::assertNotNull($customer);
        self::assertSame($newEmail, $customer['email']);
        $addresses = $this->rows('customer_address_entity', 'parent_id', $newId);
        self::assertCount(1, $addresses);
        $address = $addresses[0];
        self::assertNotSame($f->addressId, (int)$address['entity_id'], 'address got a new id');
        self::assertSame((int)$address['entity_id'], (int)$customer['default_billing'], 'default billing rewritten');
        self::assertSame((int)$address['entity_id'], (int)$customer['default_shipping'], 'default shipping rewritten');
        self::assertSame(CustomerFootprint::PHONE, $address['telephone']);
        self::assertSame(
            $this->countRows('customer_entity_varchar', 'entity_id', $f->customerId),
            $this->countRows('customer_entity_varchar', 'entity_id', $newId),
            'EAV values follow the customer'
        );

        // --- the cart: items, their parent/child link, the option, the addresses
        $quotes = $this->rows('quote', 'customer_id', $newId);
        self::assertCount(1, $quotes);
        $quote = $quotes[0];
        self::assertSame($newEmail, $quote['customer_email']);
        $items = $this->rows('quote_item', 'quote_id', (int)$quote['entity_id']);
        self::assertCount(2, $items);
        [$parent, $child] = $this->splitParentChild($items, 'item_id');
        self::assertSame((int)$parent['item_id'], (int)$child['parent_item_id'], 'child line relinked to parent');
        self::assertSame($f->productId, (int)$parent['product_id'], 'product matched by sku');
        $options = $this->rows('quote_item_option', 'item_id', (int)$parent['item_id']);
        self::assertCount(1, $options);
        self::assertSame(CustomerFootprint::OPTION_VALUE, $options[0]['value']);
        foreach ($this->rows('quote_address', 'quote_id', (int)$quote['entity_id']) as $quoteAddress) {
            self::assertSame($newId, (int)$quoteAddress['customer_id']);
            self::assertSame((int)$address['entity_id'], (int)$quoteAddress['customer_address_id']);
        }

        // --- the order: addresses, items, payment, back-references, links to the cart
        $orders = $this->rows('sales_order', 'customer_id', $newId);
        self::assertCount(1, $orders);
        $order = $orders[0];
        self::assertNotSame($f->orderId, (int)$order['entity_id']);
        self::assertSame($newIncrement, $order['increment_id']);
        self::assertSame((int)$quote['entity_id'], (int)$order['quote_id'], 'order -> new cart');
        self::assertSame('', $order['remote_ip'], 'empty string preserved');
        $orderAddresses = $this->rows('sales_order_address', 'parent_id', (int)$order['entity_id']);
        self::assertCount(2, $orderAddresses);
        $byType = array_column($orderAddresses, null, 'address_type');
        self::assertSame((int)$byType['billing']['entity_id'], (int)$order['billing_address_id']);
        self::assertSame((int)$byType['shipping']['entity_id'], (int)$order['shipping_address_id']);
        self::assertSame((int)$address['entity_id'], (int)$byType['billing']['customer_address_id']);
        $orderItems = $this->rows('sales_order_item', 'order_id', (int)$order['entity_id']);
        self::assertCount(2, $orderItems);
        [$orderParent, $orderChild] = $this->splitParentChild($orderItems, 'item_id');
        self::assertSame((int)$orderParent['item_id'], (int)$orderChild['parent_item_id']);
        self::assertSame((int)$parent['item_id'], (int)$orderParent['quote_item_id'], 'order line -> new cart line');
        self::assertSame((int)$child['item_id'], (int)$orderChild['quote_item_id']);
        self::assertSame(1, $this->countRows('sales_order_payment', 'parent_id', (int)$order['entity_id']));
        self::assertSame(4, $result['deferred_updates'], '2 default addresses + 2 order address ids');

        // --- the original footprint is untouched
        $original = $this->row('customer_entity', 'entity_id', $f->customerId);
        self::assertSame($f->email, $original['email']);
        self::assertSame($f->addressId, (int)$original['default_billing']);
        self::assertSame(1, $this->countRows('sales_order', 'customer_id', $f->customerId));
        self::assertSame(1, $this->countRows('quote', 'customer_id', $f->customerId));
    }

    /**
     * The account does not exist and its ids are free (a fresh database): ids are kept as exported
     * and the footprint comes back identical.
     *
     * @return void
     */
    public function testRestoreOntoFreeAccountKeepsTheIds(): void
    {
        $f = $this->fixture;
        $this->exportCustomer($f->customerId);
        $this->stageForImport($f->customerId);
        $f->deleteCustomerFootprint();
        self::assertNull($this->row('customer_entity', 'entity_id', $f->customerId), 'precondition: gone');

        $result = $this->importer->import($f->customerId);

        self::assertSame([$f->customerId], array_map('intval', $result['new_customer_ids']));
        self::assertSame(0, $result['remapped'], 'no id had to change');
        self::assertSame(0, $result['deferred_updates'], 'back-references already point at the kept ids');
        $customer = $this->row('customer_entity', 'entity_id', $f->customerId);
        self::assertSame($f->email, $customer['email']);
        self::assertSame($f->addressId, (int)$customer['default_billing']);
        self::assertNotNull($this->row('customer_address_entity', 'entity_id', $f->addressId));
        $quote = $this->row('quote', 'entity_id', $f->quoteId);
        self::assertSame($f->customerId, (int)$quote['customer_id']);
        self::assertSame(2, $this->countRows('quote_item', 'quote_id', $f->quoteId));
        $childLine = $this->row('quote_item', 'item_id', $f->quoteChildItemId);
        self::assertSame($f->quoteParentItemId, (int)$childLine['parent_item_id']);
        $order = $this->row('sales_order', 'entity_id', $f->orderId);
        self::assertSame($f->incrementId, $order['increment_id']);
        self::assertSame($f->orderBillingAddressId, (int)$order['billing_address_id']);
        self::assertSame($f->orderShippingAddressId, (int)$order['shipping_address_id']);
        self::assertSame($f->quoteId, (int)$order['quote_id']);
        self::assertSame(2, $this->countRows('sales_order_item', 'order_id', $f->orderId));
        self::assertSame(1, $this->countRows('sales_order_payment', 'parent_id', $f->orderId));
        self::assertSame(1, $this->countRows('catalog_product_entity', 'sku', $f->sku), 'product reused');
    }

    /**
     * Clone mode onto the same account: a brand-new customer with a suffixed email and order number.
     *
     * @return void
     */
    public function testCloneOntoTheSameAccountCreatesASecondCustomer(): void
    {
        $f = $this->fixture;
        $this->exportCustomer($f->customerId);
        $this->stageForImport($f->customerId);

        $result = $this->importer->import($f->customerId, null, true);

        $newId = (int)$result['new_customer_ids'][0];
        self::assertNotSame($f->customerId, $newId);
        $token = $result['clone_token'];
        self::assertMatchesRegularExpression('/^[a-z0-9]{6}$/', (string)$token);
        $customer = $this->row('customer_entity', 'entity_id', $newId);
        self::assertSame(str_replace('@', '+clone-' . $token . '@', $f->email), $customer['email']);
        $order = $this->rows('sales_order', 'customer_id', $newId)[0];
        self::assertSame($f->incrementId . '-' . $token, $order['increment_id']);
        self::assertSame($customer['email'], $order['customer_email'], 'denormalised email mirrored');
        self::assertSame(2, $this->countRows('customer_entity', 'firstname', 'DbTool'));
    }

    /**
     * Split two lines into [parent, child] by the self-referencing parent_item_id.
     *
     * @param array<int,array<string,mixed>> $items
     * @param string $pk
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    private function splitParentChild(array $items, string $pk): array
    {
        usort($items, static fn (array $a, array $b): int => ($a['parent_item_id'] === null ? 0 : 1)
            <=> ($b['parent_item_id'] === null ? 0 : 1));
        self::assertNull($items[0]['parent_item_id'], "first $pk is the parent line");
        self::assertNotNull($items[1]['parent_item_id'], "second $pk is the child line");
        return [$items[0], $items[1]];
    }
}
