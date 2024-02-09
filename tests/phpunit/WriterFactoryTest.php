<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\DbWriter\Exception\UserException;
use Keboola\DbWriter\WriterFactory;
use Keboola\DbWriterConfig\Config;
use Keboola\DbWriterConfig\Configuration\ActionConfigDefinition;
use Keboola\DbWriterConfig\Configuration\ValueObject\DatabaseConfig;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Psr\Log\Test\TestLogger;

class WriterFactoryTest extends TestCase
{
    public function testCreateWriterClass(): void
    {
        $config = new Config(
            [
                'action' => 'testConnection',
                'parameters' => [
                    'data_dir' => '/data',
                    'writer_class' => 'Common',
                    'db' => [
                        'host' => (string) getenv('COMMON_DB_HOST'),
                        'port' => (int) getenv('COMMON_DB_PORT'),
                        'database' => (string) getenv('COMMON_DB_DATABASE'),
                        'user' => (string) getenv('COMMON_DB_USER'),
                        '#password' => (string) getenv('COMMON_DB_PASSWORD'),
                    ],
                ],
            ],
            new ActionConfigDefinition(),
        );

        $writerFactory = new WriterFactory($config);
        $writer = $writerFactory->create(new TestLogger(), DatabaseConfig::fromArray($config->getParameters()['db']));

        Assert::assertSame('Keboola\DbWriter\Writer\Common', get_class($writer));
    }

    public function testUnknownWriterClass(): void
    {
        $config = new Config(
            [
                'action' => 'testConnection',
                'parameters' => [
                    'data_dir' => '/data',
                    'writer_class' => 'Unknown',
                    'db' => [
                        'host' => (string) getenv('COMMON_DB_HOST'),
                        'port' => (int) getenv('COMMON_DB_PORT'),
                        'database' => (string) getenv('COMMON_DB_DATABASE'),
                        'user' => (string) getenv('COMMON_DB_USER'),
                        '#password' => (string) getenv('COMMON_DB_PASSWORD'),
                    ],
                ],
            ],
            new ActionConfigDefinition(),
        );

        $writerFactory = new WriterFactory($config);
        $this->expectException(UserException::class);
        $this->expectExceptionMessage("Writer class 'Keboola\DbWriter\Writer\Unknown' doesn't exist");
        $writerFactory->create(new TestLogger(), DatabaseConfig::fromArray($config->getParameters()['db']));
    }
}
