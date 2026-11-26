<?php

declare(strict_types=1);

use App\Connection;
use App\Migrations;
use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->safeLoad();

$databaseUrl = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? getenv('DATABASE_URL');
if (!is_string($databaseUrl) || $databaseUrl === '') {
    throw new RuntimeException('DATABASE_URL must be set');
}

(new Migrations())->run(Connection::create($databaseUrl));
