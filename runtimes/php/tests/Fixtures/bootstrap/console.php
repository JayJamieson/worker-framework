<?php

declare(strict_types=1);

use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;

// A bootstrap file for an application that wires its own Console Application
// rather than using FrameworkBundle.
return MessengerFixture::console();
