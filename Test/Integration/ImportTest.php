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
     * The same customer already exists: everything is matched and reused, nothing is written.
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
        self::assertSame([], array_filter($result['tables']), 'nothing is written');
        self::assertSame(1, $this->countRows('customer_entity', 'email', $f->email), 'customer not duplicated');
        self::assertSame(1, $this->countRows('customer_address_entity', 'parent_id', $f->customerId));
        self::assertSame(1, $this->countRows('quote', 'customer_id', $f->customerId), 'cart not duplicated');
        self::assertSame(1, $this->countRows('gift_message', 'customer_id', $f->customerId));
        self::assertSame(1, $this->countRows('sales_order', 'increment_id', $f->incrementId), 'order matched');
        self::assertSame(1, $this->countRows('sales_invoice', 'order_id', $f->orderId), 'invoice matched');
        self::assertSame(1, $this->countRows('catalog_product_entity', 'sku', $f->sku), 'product matched');
        self::assertSame(1, $this->countRows('catalog_product_option', 'product_id', $f->productId));
        if ($f->familySetCode !== null) {
            self::assertSame(1, $this->countRows('ecomeu_familyset_tier_price', 'familyset_code', $f->familySetCode));
        }
        self::assertSame(0, $result['deferred_updates']);
        self::assertSame(0, $result['unlinked']);
        self::assertSame(0, $result['dropped']);
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
        // Documents have their own numbers, unique per store: renumber them like the order.
        foreach (['sales_invoice', 'sales_shipment'] as $document) {
            $this->rewriteCsv($dir, $document, fn (array $r) => ['increment_id' => 'X' . $r['increment_id']] + $r);
            $this->rewriteCsv($dir, $document . '_grid', fn (array $r) => [
                'increment_id' => 'X' . $r['increment_id'],
                'order_increment_id' => $newIncrement,
                'customer_email' => $newEmail,
            ] + $r);
        }

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
        $payments = $this->rows('sales_order_payment', 'parent_id', (int)$order['entity_id']);
        self::assertCount(1, $payments);
        self::assertSame(4, $result['deferred_updates'], '2 default addresses + 2 order address ids');

        // --- the extras hanging off the order and the cart
        $newOrderId = (int)$order['entity_id'];
        $transactions = $this->rows('sales_payment_transaction', 'order_id', $newOrderId);
        self::assertCount(1, $transactions);
        self::assertSame((int)$payments[0]['entity_id'], (int)$transactions[0]['payment_id'], 'txn -> new payment');
        self::assertSame(1, $this->countRows('sales_order_status_history', 'parent_id', $newOrderId));
        $taxes = $this->rows('sales_order_tax', 'order_id', $newOrderId);
        self::assertCount(1, $taxes);
        $taxItems = $this->rows('sales_order_tax_item', 'tax_id', (int)$taxes[0]['tax_id']);
        self::assertCount(1, $taxItems);
        self::assertSame((int)$orderParent['item_id'], (int)$taxItems[0]['item_id'], 'tax item -> new order line');
        $giftMessages = $this->rows('gift_message', 'customer_id', $newId);
        self::assertCount(1, $giftMessages);
        self::assertSame((int)$giftMessages[0]['gift_message_id'], (int)$order['gift_message_id'], 'gift -> new');

        $invoices = $this->rows('sales_invoice', 'order_id', $newOrderId);
        self::assertCount(1, $invoices);
        $invoice = $invoices[0];
        self::assertNotSame($f->invoiceId, (int)$invoice['entity_id']);
        self::assertSame('X' . $f->invoiceIncrementId, $invoice['increment_id']);
        self::assertSame((int)$order['billing_address_id'], (int)$invoice['billing_address_id']);
        $invoiceItems = $this->rows('sales_invoice_item', 'parent_id', (int)$invoice['entity_id']);
        self::assertCount(1, $invoiceItems);
        self::assertSame((int)$orderParent['item_id'], (int)$invoiceItems[0]['order_item_id']);
        $invoiceGrid = $this->row('sales_invoice_grid', 'entity_id', (int)$invoice['entity_id']);
        self::assertNotNull($invoiceGrid, 'grid row follows the invoice id');
        self::assertSame($newOrderId, (int)$invoiceGrid['order_id']);

        $shipments = $this->rows('sales_shipment', 'order_id', $newOrderId);
        self::assertCount(1, $shipments);
        $shipment = $shipments[0];
        self::assertSame($newId, (int)$shipment['customer_id']);
        self::assertSame((int)$order['shipping_address_id'], (int)$shipment['shipping_address_id']);
        $tracks = $this->rows('sales_shipment_track', 'parent_id', (int)$shipment['entity_id']);
        self::assertCount(1, $tracks);
        self::assertSame($newOrderId, (int)$tracks[0]['order_id']);
        self::assertSame(1, $this->countRows('sales_shipment_item', 'parent_id', (int)$shipment['entity_id']));
        self::assertNotNull($this->row('sales_shipment_grid', 'entity_id', (int)$shipment['entity_id']));

        self::assertSame(1, $this->countRows('quote_id_mask', 'quote_id', (int)$quote['entity_id']));
        $shippingQuoteAddress = array_column(
            $this->rows('quote_address', 'quote_id', (int)$quote['entity_id']),
            null,
            'address_type'
        )['shipping'];
        $shippingQuoteAddressId = (int)$shippingQuoteAddress['address_id'];
        self::assertSame(1, $this->countRows('quote_shipping_rate', 'address_id', $shippingQuoteAddressId));

        // --- the product existed: its option and family-set price are matched, not duplicated
        self::assertSame(1, $this->countRows('catalog_product_option', 'product_id', $f->productId));
        if ($f->familySetCode !== null) {
            self::assertSame(1, $this->countRows('ecomeu_familyset_tier_price', 'familyset_code', $f->familySetCode));
        }
        self::assertSame(0, $result['dropped']);

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
        self::assertSame($f->giftMessageId, (int)$order['gift_message_id']);
        self::assertNotNull($this->row('sales_invoice', 'entity_id', $f->invoiceId), 'invoice id kept');
        self::assertNotNull($this->row('sales_invoice_grid', 'entity_id', $f->invoiceId));
        self::assertNotNull($this->row('sales_shipment', 'entity_id', $f->shipmentId), 'shipment id kept');
        self::assertSame(1, $this->countRows('sales_order_status_history', 'parent_id', $f->orderId));
        self::assertSame(1, $this->countRows('sales_payment_transaction', 'order_id', $f->orderId));
        self::assertSame(1, $this->countRows('quote_id_mask', 'quote_id', $f->quoteId));
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
        $invoice = $this->rows('sales_invoice', 'order_id', (int)$order['entity_id'])[0];
        self::assertSame($f->invoiceIncrementId . '-' . $token, $invoice['increment_id'], 'document number suffixed');
        $invoiceGrid = $this->row('sales_invoice_grid', 'entity_id', (int)$invoice['entity_id']);
        self::assertSame($invoice['increment_id'], $invoiceGrid['increment_id'], 'grid mirrors the invoice number');
        self::assertSame($order['increment_id'], $invoiceGrid['order_increment_id'], 'grid mirrors the order number');
        self::assertSame($customer['email'], $invoiceGrid['customer_email']);
        self::assertSame(2, $this->countRows('customer_entity', 'firstname', 'DbTool'));
    }

    /**
     * A dry run goes through every step and reports the outcome, but the database is unchanged.
     *
     * @return void
     */
    public function testDryRunReportsTheRunButWritesNothing(): void
    {
        // MySQL has no nested transactions: the engine's rollback is only real when it owns the
        // outermost one, so this test leaves the per-test transaction and cleans up by hand.
        $this->connection->rollBack();
        $f = (new CustomerFootprint($this->resource))->create();
        try {
            $this->exportCustomer($f->customerId);
            $this->stageForImport($f->customerId);
            $f->deleteCustomerFootprint();

            $result = $this->importer->import($f->customerId, null, false, true);

            self::assertTrue($result['dry_run']);
            self::assertSame(1, $result['tables']['customer_entity'], 'the run reports what it would write');
            self::assertSame(1, $result['tables']['sales_order']);
            self::assertSame([['id' => (string)$f->customerId, 'email' => $f->email]], $result['new_customers']);
            self::assertNull($this->row('customer_entity', 'entity_id', $f->customerId), 'nothing persisted');
            self::assertNull($this->row('sales_order', 'entity_id', $f->orderId));
            self::assertNull($this->row('quote', 'entity_id', $f->quoteId));
            self::assertNull($this->row('gift_message', 'gift_message_id', $f->giftMessageId));
        } finally {
            $f->deleteCustomerFootprint();
            $f->deleteProduct();
            // Re-balance the transaction that tearDown() rolls back.
            $this->connection->beginTransaction();
        }
    }

    /**
     * A CSV column unknown to the target table is dropped and reported, not fatal.
     *
     * @return void
     */
    public function testUnknownCsvColumnIsIgnoredAndReported(): void
    {
        $f = $this->fixture;
        $this->exportCustomer($f->customerId);
        $dir = $this->stageForImport($f->customerId);
        $f->deleteCustomerFootprint();
        $this->rewriteCsv($dir, 'customer_entity', fn (array $r) => $r + ['from_newer_schema' => 'x']);

        $result = $this->importer->import($f->customerId);

        self::assertSame(['customer_entity' => ['from_newer_schema']], $result['ignored_columns']);
        self::assertSame($f->email, $this->row('customer_entity', 'entity_id', $f->customerId)['email']);
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
