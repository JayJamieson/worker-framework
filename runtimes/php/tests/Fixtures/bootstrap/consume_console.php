<?php

declare(strict_types=1);

use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;

// An application that exposes `messenger:consume` through its console, which
// is the path every Symfony worker takes.
return MessengerFixture::consoleWithConsume();
