<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests\Writer;

use Keboola\DbWriter\Writer\TableDescriptionsReader;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;
use PHPUnit\Framework\TestCase;

class TableDescriptionsReaderTest extends TestCase
{
    private string $dataDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDir = sys_get_temp_dir() . '/descriptions-reader-' . uniqid();
        mkdir($this->dataDir . '/in/tables', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dataDir . '/in/tables/*') ?: []);
        rmdir($this->dataDir . '/in/tables');
        rmdir($this->dataDir . '/in');
        rmdir($this->dataDir);
        parent::tearDown();
    }

    public function testMissingManifestReturnsEmpty(): void
    {
        $descriptions = (new TableDescriptionsReader())->read($this->createExportConfig());

        self::assertTrue($descriptions->isEmpty());
    }

    public function testInvalidJsonReturnsEmpty(): void
    {
        file_put_contents($this->manifestPath(), '{not json');

        self::assertTrue((new TableDescriptionsReader())->read($this->createExportConfig())->isEmpty());
    }

    public function testManifestWithoutSchemaReturnsTableDescriptionOnly(): void
    {
        $this->writeManifest(['columns' => ['id', 'name'], 'description' => 'Customer orders']);

        $descriptions = (new TableDescriptionsReader())->read($this->createExportConfig());

        self::assertSame('Customer orders', $descriptions->getTable());
        self::assertSame([], $descriptions->getColumns());
    }

    public function testManifestWithoutAnyDescriptionReturnsEmpty(): void
    {
        $this->writeManifest(['columns' => ['id', 'name']]);

        self::assertTrue((new TableDescriptionsReader())->read($this->createExportConfig())->isEmpty());
    }

    public function testEmptyDescriptionsAreSkipped(): void
    {
        $this->writeManifest([
            'description' => '   ',
            'schema' => [
                ['name' => 'id', 'data_type' => [], 'description' => ''],
                ['name' => 'name', 'data_type' => []],
            ],
        ]);

        self::assertTrue((new TableDescriptionsReader())->read($this->createExportConfig())->isEmpty());
    }

    public function testColumnDescriptionsAreKeyedByDbName(): void
    {
        $this->writeManifest([
            'description' => 'Customer orders',
            'schema' => [
                ['name' => 'id', 'description' => 'Order identifier'],
                ['name' => 'name', 'description' => 'Customer name'],
            ],
        ]);

        $descriptions = (new TableDescriptionsReader())->read($this->createExportConfig([
            ['name' => 'id', 'dbName' => 'order_id', 'type' => 'int'],
            ['name' => 'name', 'dbName' => 'customer_name', 'type' => 'varchar'],
        ]));

        self::assertSame('Customer orders', $descriptions->getTable());
        self::assertSame(
            ['order_id' => 'Order identifier', 'customer_name' => 'Customer name'],
            $descriptions->getColumns(),
        );
    }

    public function testIgnoredAndUnmappedColumnsAreSkipped(): void
    {
        $this->writeManifest([
            'schema' => [
                ['name' => 'id', 'description' => 'Order identifier'],
                ['name' => 'name', 'description' => 'Customer name'],
                ['name' => 'not_in_items', 'description' => 'Not written'],
            ],
        ]);

        $descriptions = (new TableDescriptionsReader())->read($this->createExportConfig([
            ['name' => 'id', 'dbName' => 'id', 'type' => 'int'],
            ['name' => 'name', 'dbName' => 'name', 'type' => 'ignore'],
        ]));

        self::assertNull($descriptions->getTable());
        self::assertSame(['id' => 'Order identifier'], $descriptions->getColumns());
    }

    public function testKbcDescriptionMetadataIsNotUsed(): void
    {
        $this->writeManifest([
            'metadata' => [['key' => 'KBC.description', 'value' => 'Legacy table description']],
            'schema' => [
                [
                    'name' => 'id',
                    'metadata' => [['key' => 'KBC.description', 'value' => 'Legacy column description']],
                ],
            ],
        ]);

        self::assertTrue((new TableDescriptionsReader())->read($this->createExportConfig())->isEmpty());
    }

    private function manifestPath(): string
    {
        return $this->dataDir . '/in/tables/orders.csv.manifest';
    }

    /**
     * @param array<mixed> $manifest
     */
    private function writeManifest(array $manifest): void
    {
        file_put_contents($this->manifestPath(), (string) json_encode($manifest));
    }

    /**
     * @param array<array{name: string, dbName: string, type: string}>|null $items
     */
    private function createExportConfig(?array $items = null): ExportConfig
    {
        return ExportConfig::fromArray(
            [
                'data_dir' => $this->dataDir,
                'writer_class' => 'Common',
                'db' => ['host' => 'host', 'database' => 'db', 'user' => 'user', '#password' => 'pass'],
                'tableId' => 'in.c-main.orders',
                'dbName' => 'orders',
                'items' => $items ?? [
                    ['name' => 'id', 'dbName' => 'id', 'type' => 'int'],
                    ['name' => 'name', 'dbName' => 'name', 'type' => 'varchar'],
                ],
            ],
            [['source' => 'in.c-main.orders', 'destination' => 'orders.csv', 'columns' => []]],
        );
    }
}
