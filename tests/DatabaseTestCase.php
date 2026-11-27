<?php

declare(strict_types=1);

namespace Tests;

use App\Connection;
use App\Migrations;
use App\Seeder;
use DateTimeImmutable;
use Dotenv\Dotenv;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $db;
    protected string $databaseUrl;
    protected string $schema;

    protected function setUp(): void
    {
        Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
        $url = getenv('TEST_DATABASE_URL') ?: ($_ENV['TEST_DATABASE_URL'] ?? $_SERVER['TEST_DATABASE_URL'] ?? null);
        $url ??= getenv('DATABASE_URL') ?: ($_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null);
        if (!is_string($url) || $url === '') {
            self::fail('Set TEST_DATABASE_URL or DATABASE_URL to run PostgreSQL integration tests.');
        }

        $this->databaseUrl = $url;
        $this->db = Connection::create($url);
        $schema = 'test_' . bin2hex(random_bytes(8));
        $this->db->exec('CREATE SCHEMA "' . $schema . '"');
        $this->schema = $schema;
        $this->db->exec('SET search_path TO "' . $this->schema . '"');
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->schema)) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->exec('DROP SCHEMA "' . $this->schema . '" CASCADE');
        }
    }

    protected function initializeDatabase(?DateTimeImmutable $today = null): void
    {
        $migrations = new Migrations();
        $migrations->run($this->db);
        (new Seeder())->run($this->db, $today);
    }
}
