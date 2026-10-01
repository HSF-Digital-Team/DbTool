<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Test\Unit\Model\Storage;

use EcomEU\DbTool\Model\Storage\CsvStorage;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use PHPUnit\Framework\TestCase;

/**
 * The CSV dialect: a table's rows survive the write/read round trip exactly, and a file that
 * could be misread is rejected.
 */
class CsvStorageTest extends TestCase
{
    /** @var string */
    private string $dir;
    /** @var CsvStorage */
    private CsvStorage $storage;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/dbtool-csv-' . uniqid('', true);
        mkdir($this->dir, 0777, true);
        $this->storage = new CsvStorage($this->createStub(Filesystem::class), new FileDriver());
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * NULL, '', leading "+", quotes, commas, newlines, booleans and missing columns all round-trip.
     *
     * @return void
     */
    public function testRoundTripPreservesDelicateValues(): void
    {
        $header = ['id', 'phone', 'street', 'company', 'note', 'flag', 'json'];
        $rows = [
            ['id' => 1, 'phone' => '+33 1 23 45 67 89', 'street' => "1 rue\nBât. B", 'company' => null,
                'note' => '', 'flag' => true, 'json' => '{"a":"5\" long","b":[1,2]}'],
            ['id' => 2, 'phone' => '-1', 'street' => 'a, "quoted", b', 'company' => '=SUM(1)',
                'note' => CsvStorage::NULL_MARKER . 'x', 'flag' => false],
        ];
        $this->storage->writeTable($this->dir, 't', $header, $rows);

        $read = $this->storage->readTable($this->dir, 't');
        self::assertSame([
            ['id' => '1', 'phone' => '+33 1 23 45 67 89', 'street' => "1 rue\nBât. B", 'company' => null,
                'note' => '', 'flag' => '1', 'json' => '{"a":"5\" long","b":[1,2]}'],
            ['id' => '2', 'phone' => '-1', 'street' => 'a, "quoted", b', 'company' => '=SUM(1)',
                'note' => '\\Nx', 'flag' => '0', 'json' => null],
        ], $read);
    }

    /**
     * A header-only file reads as an empty list; a missing file as null.
     *
     * @return void
     */
    public function testEmptyAndMissingTables(): void
    {
        $this->storage->writeTable($this->dir, 'empty', ['a', 'b'], []);
        self::assertSame([], $this->storage->readTable($this->dir, 'empty'));
        self::assertNull($this->storage->readTable($this->dir, 'nope'));
    }

    /**
     * Hand-edited files: blank lines ignored, short lines padded with NULL, long lines trimmed.
     *
     * @return void
     */
    public function testToleratesHandEditedFiles(): void
    {
        file_put_contents($this->dir . '/t.csv', "a,b,c\n\n1,2\n\n4,5,6,7\n");
        self::assertSame([
            ['a' => '1', 'b' => '2', 'c' => null],
            ['a' => '4', 'b' => '5', 'c' => '6'],
        ], $this->storage->readTable($this->dir, 't'));
    }

    /**
     * A duplicated column name would misfile every value: the file is rejected.
     *
     * @return void
     */
    public function testDuplicateHeaderColumnIsRejected(): void
    {
        file_put_contents($this->dir . '/t.csv', "a,b,a\n1,2,3\n");
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('names column "a" twice');
        $this->storage->readTable($this->dir, 't');
    }

    /**
     * An empty column name is rejected as well.
     *
     * @return void
     */
    public function testEmptyHeaderColumnIsRejected(): void
    {
        file_put_contents($this->dir . '/t.csv', "a,,c\n1,2,3\n");
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('empty column name');
        $this->storage->readTable($this->dir, 't');
    }

    /**
     * A processor returning a nested value is a bug, reported with the table/row/column.
     *
     * @return void
     */
    public function testNonScalarValueIsRejectedOnWrite(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('t row 0 column "b" holds a non-scalar value (array)');
        $this->storage->writeTable($this->dir, 't', ['a', 'b'], [['a' => 1, 'b' => ['nested']]]);
    }
}
