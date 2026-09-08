<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

/**
 * A stock Symfony kernel. The runtime instantiates it as `new Kernel($env,
 * $debug)` and boots it, which is all WORKER_KERNEL_CLASS needs.
 */
final class Kernel extends BaseKernel
{
    use MicroKernelTrait;
}
