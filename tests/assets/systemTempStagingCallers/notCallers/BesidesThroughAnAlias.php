<?php

declare(strict_types=1);

namespace SystemTempStagingCallersFixture;

use LTS\PHPQA\Filesystem\TemporaryDirectory as Staging;

// Staging::create('phpqa-fixture') is what this file must not do.
Staging::besides('/library/file', 'create');
\call_user_func([Staging::class, 'besides'], '/library/file', 'phpqa-fixture');
