<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final class CityRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function all(): array
    {
        $sql = 'SELECT code, name, country FROM cities ORDER BY id';

        $statement = $this->db->query($sql);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
}
