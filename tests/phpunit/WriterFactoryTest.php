<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

class WriterFactoryTest extends \Keboola\DbWriter\Test\BaseTest
{
    public function testCreate(): void
    {
        $config = $this->getConfig();
        $config['parameters']['writer_class'] = 'Common';

        $validate = \Keboola\DbWriter\Configuration\Validator::getValidator(
            new \Keboola\DbWriter\Configuration\ConfigDefinition()
        );
        $config['parameters'] = $validate($config['parameters']);

        $writerFactory = new \Keboola\DbWriter\WriterFactory($config['parameters']);
        $writer = $writerFactory->create(new \Keboola\DbWriter\Logger());

        $this->assertInstanceOf('Keboola\DbWriter\Writer\Common', $writer);
    }
}
