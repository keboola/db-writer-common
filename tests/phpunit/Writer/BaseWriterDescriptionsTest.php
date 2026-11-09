<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests\Writer;

use Keboola\DbWriter\Writer\BaseWriter;
use Keboola\DbWriterAdapter\Connection\Connection;
use Keboola\DbWriterAdapter\DescriptionWriteAdapter;
use Keboola\DbWriterAdapter\ValueObject\TableDescriptions;
use Keboola\DbWriterAdapter\WriteAdapter;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use Keboola\DbWriterConfig\Configuration\ValueObject\ExportConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;
use RuntimeException;

class BaseWriterDescriptionsTest extends TestCase
{
    private string $dataDir;

    private TestLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = new TestLogger();
        $this->dataDir = sys_get_temp_dir() . '/base-writer-descriptions-' . uniqid();
        mkdir($this->dataDir . '/in/tables', 0777, true);
        file_put_contents(
            $this->dataDir . '/in/tables/orders.csv.manifest',
            (string) json_encode([
                'description' => 'Customer orders',
                'schema' => [['name' => 'id', 'description' => 'Order identifier']],
            ]),
        );
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dataDir . '/in/tables/*') ?: []);
        rmdir($this->dataDir . '/in/tables');
        rmdir($this->dataDir . '/in');
        rmdir($this->dataDir);
        parent::tearDown();
    }

    public function testDescriptionsAreAppliedAfterFullWrite(): void
    {
        $adapter = $this->createMock(DescriptionWriteAdapterStub::class);
        $adapter->expects(self::once())->method('writeData');
        $adapter->expects(self::once())
            ->method('applyDescriptions')
            ->with('orders', new TableDescriptions('Customer orders', ['order_id' => 'Order identifier']));

        $this->createWriter($adapter)->write($this->createExportConfig());
    }

    public function testDescriptionsAreAppliedAfterIncrementalWrite(): void
    {
        $adapter = $this->createMock(DescriptionWriteAdapterStub::class);
        $adapter->method('generateTmpName')->willReturn('orders_tmp');
        $adapter->method('tableExists')->willReturn(true);
        $adapter->expects(self::once())->method('upsert');
        $adapter->expects(self::once())->method('applyDescriptions')->with('orders');

        $this->createWriter($adapter)->write($this->createExportConfig(['incremental' => true]));
    }

    public function testToggleOffSkipsDescriptions(): void
    {
        $adapter = $this->createMock(DescriptionWriteAdapterStub::class);
        $adapter->expects(self::never())->method('applyDescriptions');

        $this->createWriter($adapter)->write($this->createExportConfig(['propagateDescriptions' => false]));
    }

    public function testEmptyDescriptionsSkipAdapterCall(): void
    {
        unlink($this->dataDir . '/in/tables/orders.csv.manifest');
        $adapter = $this->createMock(DescriptionWriteAdapterStub::class);
        $adapter->expects(self::never())->method('applyDescriptions');

        $this->createWriter($adapter)->write($this->createExportConfig());
    }

    public function testAdapterWithoutInterfaceIsUnaffected(): void
    {
        $adapter = $this->createMock(WriteAdapter::class);
        $adapter->expects(self::once())->method('writeData');

        $this->createWriter($adapter)->write($this->createExportConfig());

        self::assertFalse($this->logger->hasWarningRecords());
    }

    public function testAdapterFailureIsLoggedAsWarning(): void
    {
        $adapter = $this->createMock(DescriptionWriteAdapterStub::class);
        $adapter->method('applyDescriptions')->willThrowException(new RuntimeException('comment not allowed'));

        $this->createWriter($adapter)->write($this->createExportConfig());

        self::assertTrue($this->logger->hasWarning(
            'Descriptions were not written to table "orders": comment not allowed',
        ));
    }

    private function createWriter(WriteAdapter $adapter): BaseWriter
    {
        $connection = $this->createMock(Connection::class);
        return new class ($connection, $adapter, $this->logger) extends BaseWriter {
            public function __construct(
                private readonly Connection $testConnection,
                private readonly WriteAdapter $testAdapter,
                TestLogger $logger,
            ) {
                parent::__construct(DatabaseConfig::fromArray(['database' => 'db', 'user' => 'user']), $logger);
            }

            protected function createConnection(DatabaseConfig $databaseConfig): Connection
            {
                return $this->testConnection;
            }

            protected function createWriteAdapter(): WriteAdapter
            {
                return $this->testAdapter;
            }
        };
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createExportConfig(array $overrides = []): ExportConfig
    {
        return ExportConfig::fromArray(
            array_merge([
                'data_dir' => $this->dataDir,
                'writer_class' => 'Common',
                'db' => ['database' => 'db', 'user' => 'user'],
                'tableId' => 'in.c-main.orders',
                'dbName' => 'orders',
                'items' => [['name' => 'id', 'dbName' => 'order_id', 'type' => 'int']],
            ], $overrides),
            [['source' => 'in.c-main.orders', 'destination' => 'orders.csv', 'columns' => []]],
        );
    }
}
