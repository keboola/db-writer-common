<?php

declare(strict_types=1);

namespace Keboola\DbWriter\Tests\Writer;

use Keboola\DbWriterAdapter\DescriptionWriteAdapter;
use Keboola\DbWriterAdapter\WriteAdapter;

interface DescriptionWriteAdapterStub extends WriteAdapter, DescriptionWriteAdapter
{
}
