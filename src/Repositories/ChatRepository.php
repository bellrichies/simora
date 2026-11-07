<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class ChatRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    private function one(string $sql, array $values): ?array
    {
        $query = $this->db->prepare($sql);
        $query->execute($values);
        $row = $query->fetch();
        return $row === false ? null : $row;
    }

    private function execute(string $sql, array $values): void
    {
        $this->db->prepare($sql)->execute($values);
    }

    public function room(): ?array
    {
        return $this->one("SELECT * FROM chat_rooms WHERE code='general' FOR UPDATE", []);
    }

    public function configureRoom(string $policy, bool $open): array
    {
        $this->execute(
            'INSERT INTO chat_rooms (public_id,code,status,policy_reference) VALUES (?,\'general\',?,?) '
            . 'ON DUPLICATE KEY UPDATE '
                . 'status=VALUES(status),policy_reference=VALUES(policy_reference),version=version+1',
            ['rom_' . bin2hex(random_bytes(16)), $open ? 'open' : 'closed', $policy]
        );
        return $this->room();
    }

    public function membership(int $room, int $actor): ?array
    {
        return $this->one('SELECT * FROM chat_memberships WHERE room_id=? AND user_id=? FOR UPDATE', [$room, $actor]);
    }

    public function join(int $room, int $actor, string $consent): void
    {
        $this->execute(
            'INSERT INTO chat_memberships (room_id,user_id,consent_reference,created_at) '
            . 'VALUES (?,?,?,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE consent_reference=VALUES(consent_reference)',
            [$room, $actor, $consent]
        );
    }

    public function messages(int $room, bool $queue, int $before, int $limit): array
    {
        $query = $this->db->prepare('SELECT * FROM chat_messages WHERE room_id=? AND id<? '
            . ($queue ? "AND status IN ('pending','published') " : "AND status='published' ")
            . 'ORDER BY id DESC LIMIT ?');
        $query->bindValue(1, $room, PDO::PARAM_INT);
        $query->bindValue(2, $before, PDO::PARAM_INT);
        $query->bindValue(3, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }

    public function prior(int $actor, string $key): ?array
    {
        return $this->one('SELECT * FROM chat_messages WHERE user_id=? AND request_key=?', [$actor, $key]);
    }

    public function message(string $id): ?array
    {
        return $this->one('SELECT * FROM chat_messages WHERE public_id=? FOR UPDATE', [$id]);
    }

    public function send(int $room, int $actor, string $body, string $key, string $hash): array
    {
        $id = 'cht_' . bin2hex(random_bytes(16));
        $this->execute('INSERT INTO chat_messages (public_id,room_id,user_id,body,request_key,fingerprint,created_at) '
            . 'VALUES (?,?,?,?,?,?,UTC_TIMESTAMP(6))', [$id, $room, $actor, $body, $key, $hash]);
        return $this->message($id);
    }

    public function moderate(string $id, int $actor, bool $publish, string $reason): array
    {
        $this->execute('UPDATE chat_messages SET '
            . 'status=?,moderator_user_id=?,moderation_reason=?,moderated_at=UTC_TIMESTAMP(6),'
            . 'version=version+1 WHERE public_id=?', [$publish ? 'published' : 'hidden', $actor, $reason, $id]);
        return $this->message($id);
    }

    public function restrict(int $room, int $user, bool $ban, int $minutes): void
    {
        $this->execute(
            'UPDATE chat_memberships SET status=?,muted_until=CASE WHEN ?>0 '
            . 'THEN UTC_TIMESTAMP(6)+INTERVAL ? MINUTE ELSE NULL END WHERE room_id=? AND user_id=?',
            [$ban ? 'banned' : 'active', $minutes, $minutes, $room, $user]
        );
    }

    public function reportExists(int $actor, string $id): bool
    {
        return $this->one("SELECT id FROM audit_logs WHERE actor_user_id=? AND operation='chat.reported' "
            . 'AND object_type=\'chat_message\' AND object_id=? LIMIT 1', [$actor, $id]) !== null;
    }

    public function reports(int $before, int $limit): array
    {
        $query = $this->db->prepare("SELECT id,object_id AS message_id,correlation_id AS reason,occurred_at "
            . "FROM audit_logs WHERE operation='chat.reported' AND id<? ORDER BY id DESC LIMIT ?");
        $query->bindValue(1, $before, PDO::PARAM_INT);
        $query->bindValue(2, $limit, PDO::PARAM_INT);
        $query->execute();
        return $query->fetchAll();
    }
}
