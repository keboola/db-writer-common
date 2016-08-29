<?php

/**
 * Created by PhpStorm.
 * User: miroslavcillik
 * Date: 26/08/16
 * Time: 15:01
 */
class WriterFactoryTest extends \Keboola\DbWriter\Test\BaseTest
{
    public function testCreate()
    {
        $config = $this->getConfig('common');
        $config['parameters']['writer_class'] = 'Common';

        $writerFactory = new \Keboola\DbWriter\WriterFactory($config['parameters']);
        $writer = $writerFactory->create(new \Keboola\DbWriter\Logger());

        $this->assertInstanceOf('Keboola\DbWriter\Writer\Common', $writer);
    }

}
