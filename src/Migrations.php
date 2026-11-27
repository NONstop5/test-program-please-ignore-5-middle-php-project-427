<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;
use Throwable;

class Migrations
{
    public function run(PDO $conn): void
    {
        $migrations = [
            '001_initial_schema' => dirname(__DIR__) . '/migrations/001_initial_schema.sql',
            '002_flight_schedule' => dirname(__DIR__) . '/migrations/002_flight_schedule.sql',
        ];

        $conn->beginTransaction();
        try {
            $conn->exec('SELECT pg_advisory_xact_lock(427001)');
            $conn->exec(
                'CREATE TABLE IF NOT EXISTS migrations ('
                . 'version VARCHAR(255) PRIMARY KEY, applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            );
            $applied = $conn->prepare('SELECT 1 FROM migrations WHERE version = :version');
            $record = $conn->prepare('INSERT INTO migrations (version) VALUES (:version)');

            foreach ($migrations as $version => $path) {
                $applied->execute(['version' => $version]);
                if ($applied->fetchColumn() !== false) {
                    continue;
                }

                $sql = file_get_contents($path);
                if ($sql === false) {
                    throw new RuntimeException("Unable to read migration: {$path}");
                }

                $conn->exec($sql);
                $record->execute(['version' => $version]);
            }

            $conn->commit();
        } catch (Throwable $exception) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            throw $exception;
        }
    }
}
