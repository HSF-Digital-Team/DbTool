<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Integration;

use EcomEU\DbTool\Model\Storage\CsvStorage;
use EcomEU\DbTool\Test\Integration\Fixture\CustomerFootprint;
use Magento\Framework\Exception\LocalizedException;

/**
 * Export: one CSV per mapped table, with the footprint's rows in them, plus the zip bundle.
 */
class ExportTest extends AbstractDbToolTestCase
{
    /**
     * Every mapped table present on the database gets its CSV, and the key tables hold the rows.
     *
     * @return void
     */
    public function testExportWritesOneCsvPerMappedTableWithTheFootprintRows(): void
    {
        $result = $this->exportCustomer($this->fixture->customerId);

        self::assertDirectoryExists($result['directory']);
        foreach ($this->map->getExportOrder() as $definition) {
            $table = $definition->getTable();
            if (in_array($table, $result['absent'], true)) {
                continue;
            }
            $file = $result['directory'] . '/' . $table . '.csv';
            self::assertFileExists($file, "$table.csv should be written");
            self::assertArrayHasKey($table, $result['tables'], "$table should be reported");
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            self::assertNotEmpty($lines, "$table.csv should at least carry the header");
            self::assertNotSame('', $lines[0], "$table.csv header must not be empty");
        }

        $expected = [
            'customer_entity' => 1,
            'customer_address_entity' => 1,
            'catalog_product_entity' => 1,
            'quote' => 1,
            'quote_item' => 2,
            'quote_item_option' => 1,
            'quote_address' => 2,
            'sales_order' => 1,
            'sales_order_item' => 2,
            'sales_order_address' => 2,
            'sales_order_payment' => 1,
            'customer_group' => 1,
        ];
        foreach ($expected as $table => $count) {
            self::assertSame($count, $result['tables'][$table], "$table row count");
            self::assertCount($count, $this->storage->readTable($result['directory'], $table) ?? []);
        }
    }

    /**
     * The CSV keeps the values that are easy to lose: NULL vs '', leading "+", JSON quotes, newlines.
     *
     * @return void
     */
    public function testExportPreservesDelicateValues(): void
    {
        $dir = $this->exportCustomer($this->fixture->customerId)['directory'];

        $address = ($this->storage->readTable($dir, 'customer_address_entity') ?? [])[0];
        self::assertSame(CustomerFootprint::PHONE, $address['telephone'], 'leading "+" must survive');
        self::assertSame("1 rue du Test\nBâtiment B", $address['street'], 'newline inside a value');
        self::assertNull($address['company'], 'SQL NULL reads back as NULL');

        $order = ($this->storage->readTable($dir, 'sales_order') ?? [])[0];
        self::assertSame('', $order['remote_ip'], "empty string stays '' (distinct from NULL)");
        self::assertNull($order['coupon_code']);

        $option = ($this->storage->readTable($dir, 'quote_item_option') ?? [])[0];
        self::assertSame(CustomerFootprint::OPTION_VALUE, $option['value'], 'JSON with escaped quotes');

        $raw = file_get_contents($dir . '/sales_order.csv') ?: '';
        self::assertStringContainsString(CsvStorage::NULL_MARKER, $raw, 'NULL is written as the marker');
    }

    /**
     * The zip bundle sits next to the directory and holds every CSV.
     *
     * @return void
     */
    public function testExportZipBundlesEveryCsv(): void
    {
        $result = $this->exportCustomer($this->fixture->customerId);
        $zipPath = $this->exporter->zip($this->fixture->customerId);

        self::assertFileExists($zipPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath));
        $csvCount = count(glob($result['directory'] . '/*.csv') ?: []);
        self::assertSame($csvCount, $zip->numFiles, 'one zip entry per CSV');
        self::assertNotFalse($zip->locateName('customer_entity.csv'), 'flat entry names');
        $zip->close();
    }

    /**
     * A customer id that yields no row at all is an error, not an empty export.
     *
     * @return void
     */
    public function testExportOfUnknownCustomerFails(): void
    {
        $unknown = 2000000000;
        $this->expectException(LocalizedException::class);
        $this->exportCustomer($unknown);
    }
}
