<?php

declare(strict_types=1);

use WorkerFramework\Runtime\Tests\Fixtures\MessengerFixture;

// The explicit form of a bootstrap file: hand the runtime exactly the pieces
// it needs, no container involved.
return [
    'bus' => MessengerFixture::bus(),
    'receivers' => MessengerFixture::receivers(),
    'serializer' => MessengerFixture::serializer(),
];
