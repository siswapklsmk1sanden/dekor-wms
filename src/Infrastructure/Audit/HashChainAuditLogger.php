<?php declare(strict_types=1);

namespace DekorAsia\Wms\Infrastructure\Audit;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class HashChainAuditLogger
{
    public function __construct(private readonly PDO $pdo) {}

    private static function genesisHash(): string
    {
        return str_repeat('0', 64);
    }

    public function recordEvent(
        ?int $userId,
        string $eventAction,
        string $entityTable,
        string|int $entityId,
        array $payload,
        string $ipAddress = '127.0.0.1',
        string $userAgent = 'CLI/System'
    ): string {
        // Jika belum di dalam transaksi, buat transaksi sendiri supaya FOR UPDATE benar-benar mengunci.
        $ownTx = !$this->pdo->inTransaction();
        if ($ownTx) {
            $this->pdo->beginTransaction();
        }

        try {
            $last = $this->pdo->query('SELECT current_hash FROM audit_logs ORDER BY id DESC LIMIT 1 FOR UPDATE')->fetch();
            $prevHash = $last ? (string) $last['current_hash'] : self::genesisHash();

            $timestamp = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $currentHash = hash('sha256', implode('|', [
                $prevHash, (string) ($userId ?? 0), $eventAction, $entityTable, (string) $entityId, $json, $timestamp,
            ]));

            $insert = $this->pdo->prepare(
                'INSERT INTO audit_logs
                 (user_id, event_action, entity_table, entity_id, payload_json, ip_address, user_agent, prev_hash, current_hash, created_at)
                 VALUES (:uid, :act, :tbl, :eid, :payload, :ip, :ua, :prev, :curr, :ts)'
            );
            $insert->execute([
                ':uid' => $userId, ':act' => $eventAction, ':tbl' => $entityTable, ':eid' => (string) $entityId,
                ':payload' => $json, ':ip' => $ipAddress, ':ua' => mb_substr($userAgent, 0, 255),
                ':prev' => $prevHash, ':curr' => $currentHash, ':ts' => $timestamp,
            ]);

            if ($ownTx) {
                $this->pdo->commit();
            }
            return $currentHash;
        } catch (Throwable $e) {
            if ($ownTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Periksa seluruh rantai dari awal. Dipakai Auditor / cronjob harian. */
    public function verifyChain(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, user_id, event_action, entity_table, entity_id, payload_json, prev_hash, current_hash, created_at
             FROM audit_logs ORDER BY id ASC'
        );
        $expectedPrev = self::genesisHash();
        $checked = 0;

        while ($r = $stmt->fetch()) {
            $checked++;
            $calc = hash('sha256', implode('|', [
                $r['prev_hash'], (string) ($r['user_id'] ?? 0), $r['event_action'], $r['entity_table'],
                (string) $r['entity_id'], $r['payload_json'], $r['created_at'],
            ]));
            if ($r['prev_hash'] !== $expectedPrev || !hash_equals($calc, (string) $r['current_hash'])) {
                return ['valid' => false, 'checked' => $checked, 'broken_at_id' => (int) $r['id']];
            }
            $expectedPrev = (string) $r['current_hash'];
        }
        return ['valid' => true, 'checked' => $checked, 'broken_at_id' => null];
    }
}
