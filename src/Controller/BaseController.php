<?php

declare(strict_types=1);

namespace App\Controller;

use PDO;
use Psr\Container\ContainerInterface;

class BaseController
{
    public function __construct(
        protected ContainerInterface $container,
        protected PDO $db,
    ) {
    }
}
