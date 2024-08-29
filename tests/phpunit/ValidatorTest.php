<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Generator;
use Keboola\DbWriter\Exception\ApplicationException;
use Keboola\DbWriter\Exception\InvalidDatabaseHostException;
use Keboola\DbWriter\Validator;
use Keboola\DbWriterConfig\Config;
use Keboola\DbWriterConfig\Configuration\ActionConfigDefinition;
use Keboola\Temp\Temp;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class ValidatorTest extends TestCase
{
    public function testValidHostname(): void
    {
        $config = new Config($this->getConfig(), new ActionConfigDefinition());

        $validator = new Validator(new TestLogger());
        $validator->validateDatabaseHost($config);

        Assert::assertTrue(true);
    }

    public function testValidHostnameWithoutPort(): void
    {
        $rawConfig = $this->getConfig();
        unset($rawConfig['image_parameters']['approvedHostnames'][0]['port']);
        $config = new Config($rawConfig, new ActionConfigDefinition());

        $validator = new Validator(new TestLogger());
        $validator->validateDatabaseHost($config);

        Assert::assertTrue(true);
    }

    public function testInvalidHostnameWithoutPort(): void
    {
        $rawConfig = $this->getConfig();
        unset($rawConfig['image_parameters']['approvedHostnames'][0]['port']);
        $rawConfig['image_parameters']['approvedHostnames'][0]['host'] = 'wrongHost';

        $config = new Config($rawConfig, new ActionConfigDefinition());

        $validator = new Validator(new TestLogger());

        $this->expectException(InvalidDatabaseHostException::class);
        $this->expectExceptionMessage('Hostname "localhost" with port "3306" is not approved.');
        $validator->validateDatabaseHost($config);
    }

    /**
     * @dataProvider invalidHostnameProvider
     */
    public function testInvalidHostname(array $config, string $expectedMessage): void
    {
        $config = new Config($config, new ActionConfigDefinition());
        $validator = new Validator(new TestLogger());

        $this->expectException(InvalidDatabaseHostException::class);
        $this->expectExceptionMessage($expectedMessage);
        $validator->validateDatabaseHost($config);
    }

    /**
     * @dataProvider validateTableItemsProvider
     */
    public function testReorderTableItems(array $items, array $expectedItems): void
    {
        $manifest = [
            'columns' => ['test', 'test2'],
        ];

        $temp = new Temp();
        $file = $temp->createFile('table.csv.manifest');
        file_put_contents($file->getPathname(), json_encode($manifest));

        $validator = new Validator(new TestLogger());
        $result = $validator->reorderItems(
            substr($file->getPathname(), 0, -9),
            $items,
        );

        Assert::assertEquals($expectedItems, $result);
    }

    /**
     * @dataProvider invalidTableItemsProvider
     */
    public function testInvalidTableItems(
        ?array $manifestContent,
        bool $encodeJsonContent,
        string $expectedExceptionMessage,
    ): void {
        if ($manifestContent) {
            $temp = new Temp();
            $expectedExceptionMessage = sprintf(
                $expectedExceptionMessage,
                $temp->getTmpFolder(),
            );
            $file = $temp->createFile('table.csv.manifest');
            file_put_contents(
                $file->getPathname(),
                $encodeJsonContent ? json_encode($manifestContent) : $manifestContent,
            );
            $tablePath = substr($file->getPathname(), 0, -9);
        } else {
            $tablePath = 'table.csv';
        }

        $validator = new Validator(new TestLogger());

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);
        $validator->validateTableManifest($tablePath);
    }

    private function getConfig(): array
    {
        return [
            'parameters' => [
                'data_dir' => '/data/',
                'writer_class' => 'Common',
                'db' => [
                    'host' => 'localhost',
                    'port' => '3306',
                    'database' => 'test',
                    'user' => 'root',
                    '#password' => 'root',
                ],
            ],
            'image_parameters' => [
                'approvedHostnames' => [
                    [
                        'host' => 'localhost',
                        'port' => '3306',
                    ],
                ],
            ],
        ];
    }

    public function invalidHostnameProvider(): Generator
    {
        $config = $this->getConfig();
        $config['image_parameters']['approvedHostnames'][0]['host'] = 'wrongHost';

        yield 'wrongHost' => [
            $config,
            'Hostname "localhost" with port "3306" is not approved.',
        ];

        $config['image_parameters']['approvedHostnames'][0]['port'] = 'wrongPort';
        yield 'wrongPort' => [
            $config,
            'Hostname "localhost" with port "3306" is not approved.',
        ];

        $config['image_parameters']['approvedHostnames'] = [];
        yield 'emptyImageParamsArray' => [
            $config,
            'Hostname "localhost" with port "3306" is not approved.',
        ];
    }

    public function validateTableItemsProvider(): Generator
    {
        $baseItems = [
            [
                'name' => 'test',
                'type' => 'varchar',
                'size' => '255',
            ],
            [
                'name' => 'test2',
                'type' => 'varchar',
                'size' => '255',
            ],
        ];
        yield 'valid' => [
            $baseItems,
            $baseItems,
        ];

        yield 'reorderItems' => [
            array_reverse($baseItems),
            $baseItems,
        ];
    }

    public function invalidTableItemsProvider(): Generator
    {
        yield 'manifestNotExists' => [
            null,
            true,
            'Manifest "table.csv.manifest" not found.',
        ];

        yield 'manifestNotValidJson' => [
            ['test'],
            false,
            'Manifest "%s/table.csv.manifest" is not valid JSON.',
        ];

        yield 'manifestMissingColumns' => [
            ['test'],
            true,
            'Manifest "%s/table.csv.manifest" is missing "columns" key.',
        ];
    }
}
