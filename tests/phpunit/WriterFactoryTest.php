<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests;

use Keboola\DbWriter\Configuration\ConfigDefinition;
use Keboola\DbWriter\Configuration\Validator;
use Keboola\DbWriter\Test\BaseTest;
use Keboola\DbWriter\WriterFactory;
use Psr\Log\Test\TestLogger;

class WriterFactoryTest extends BaseTest
{
    public function testCreate(): void
    {
        $config = $this->getConfig();
        $config['parameters']['writer_class'] = 'Common';

        $validate = Validator::getValidator(
            new ConfigDefinition(),
        );
        $config['parameters'] = $validate($config['parameters']);

        $writerFactory = new WriterFactory($config['parameters']);
        $writer = $writerFactory->create(new TestLogger());

        $this->assertInstanceOf('Keboola\DbWriter\Writer\Common', $writer);
    }
}
