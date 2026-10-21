<?php

declare(strict_types=1);

namespace SystemTempStagingCallersFixture;

use LTS\PHPQA\Filesystem\TemporaryDirectory;

$class = TemporaryDirectory::class;
$class::create('phpqa-fixture');
