<?php declare(strict_types=1);

namespace DekorAsia\Wms\Infrastructure\Persistence;

use PDO;

final class UnitRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function all(): array
    {
        return $this->pdo
            ->query('SELECT id, unit_code, unit_name, decimal_places FROM units ORDER BY unit_code')
            ->fetchAll();
    }

    public function codeExists(string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM units WHERE unit_code = :code LIMIT 1');
        $stmt->execute([':code' => $code]);
        return $stmt->fetchColumn() !== false;
    }

    public function create(string $code, string $name, int $decimalPlaces): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO units (unit_code, unit_name, decimal_places) VALUES (:code, :name, :dec)'
        );
        $stmt->execute([':code' => $code, ':name' => $name, ':dec' => $decimalPlaces]);
    }
}