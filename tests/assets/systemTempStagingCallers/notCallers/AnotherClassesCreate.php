<?php

declare(strict_types=1);

namespace SystemTempStagingCallersFixture;

use LTS\PHPQA\Tests\Support\TempDir as TemporaryDirectory;

TemporaryDirectory::create('phpqa-fixture');
\call_user_func([TemporaryDirectory::class, 'create'], 'phpqa-fixture');
\call_user_func('OtherVendor\TemporaryDirectory::create', 'phpqa-fixture');
