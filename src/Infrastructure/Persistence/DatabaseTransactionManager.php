<?php declare(strict_types=1);

namespace DekorAsia\Wms\Infrastructure\Persistence;

use Closure;
use PDO;
use Throwable;

final class DatabaseTransactionManager
{
    private ?PDO $pdo = null;
    private int $transactionDepth = 0;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $dbName,
        private readonly string $username,
        #[\SensitiveParameter]
        private readonly string $password,
    ) {}

    /** Koneksi dibuat sekali (lazy) saat pertama dibutuhkan. */
    public function getConnection(): PDO
    {
        if ($this->pdo === null) {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->dbName);
            $this->pdo = new PDO($dsn, $this->username, $this->password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // prepared statement asli dari MySQL (anti SQL Injection)
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $this->pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
        }
        return $this->pdo;
    }

    public function inTransaction(): bool
    {
        return $this->transactionDepth > 0;
    }

    /** Jalankan $callback di dalam transaksi atomik. Pemanggilan bersarang memakai SAVEPOINT. */
    #[\NoDiscard("Hasil eksekusi transaksi wajib ditangkap atau ditangani oleh pemanggil.")]
    public function runInTransaction(Closure $callback): mixed
    {
        $conn = $this->getConnection();
        $isOuter = $this->transactionDepth === 0;
        $savepoint = 'sp_' . $this->transactionDepth;

        if ($isOuter) {
            $conn->beginTransaction();
        } else {
            $conn->exec("SAVEPOINT {$savepoint}");
        }
        $this->transactionDepth++;

        try {
            $result = $callback($conn);
            $this->transactionDepth--;
            if ($isOuter) {
                $conn->commit();
            } else {
                $conn->exec("RELEASE SAVEPOINT {$savepoint}");
            }
            return $result;
        } catch (Throwable $e) {
            $this->transactionDepth--;
            if ($isOuter) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
            } else {
                $conn->exec("ROLLBACK TO SAVEPOINT {$savepoint}");
            }
            throw $e;
        }
    }
}
