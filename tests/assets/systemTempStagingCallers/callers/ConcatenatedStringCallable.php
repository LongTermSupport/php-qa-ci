<?php

declare(strict_types=1);

namespace SystemTempStagingCallersFixture;

use LTS\PHPQA\Filesystem\TemporaryDirectory;

\call_user_func(TemporaryDirectory::class . '::create', 'phpqa-fixture');
