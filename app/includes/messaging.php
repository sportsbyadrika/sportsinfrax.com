<?php
/**
 * SportsInfraX – Internal Messaging (Institution ↔ Member)
 *
 * Append-only message log between institution staff and a member.
 * Adult↔minor conversations are always visible to institution admins.
 * No delete endpoint exists for messages or conversations.
 * Per-user archive flag lives in message_receipts.is_archived.
 */

/**
 * Find an existing conversation by (institution, member, subject)
 * or create one. Returns the conversation ID.
 */
function getOrCreateConversation(
    int    $memberId,
    int    $instId,
    ?int   $createdBy = null,
    string $subject   = 'General'
): int {
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM conversations
         WHERE institution_id = ? AND member_id = ? AND subject = ?"
    );
    $stmt->execute([$instId, $memberId, $subject]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    $db->prepare(
        "INSERT INTO conversations (institution_id, member_id, subject, created_by)
         VALUES (?, ?, ?, ?)"
    )->execute([$instId, $memberId, $subject, $createdBy]);
    return (int)$db->lastInsertId();
}

/**
 * Append a message to a conversation.
 * $senderType: 'staff' (institution user) | 'member' (logged by staff on member's behalf)
 * Returns the new message ID.
 */
function postMessage(
    int    $conversationId,
    string $senderType,
    ?int   $senderId,
    string $body
): int {
    $body = trim($body);
    if ($body === '') throw new RuntimeException('Message body cannot be empty.');

    $db = getDB();

    $lockStmt = $db->prepare("SELECT is_locked, institution_id FROM conversations WHERE id = ?");
    $lockStmt->execute([$conversationId]);
    $conv = $lockStmt->fetch();
    if (!$conv) throw new RuntimeException('Conversation not found.');
    if ($conv['is_locked']) throw new RuntimeException('This conversation is locked.');

    $db->prepare(
        "INSERT INTO messages (conversation_id, sender_type, sender_id, body) VALUES (?, ?, ?, ?)"
    )->execute([$conversationId, $senderType, $senderId, $body]);
    $msgId = (int)$db->lastInsertId();

    $db->prepare(
        "UPDATE conversations SET last_message_at = NOW() WHERE id = ?"
    )->execute([$conversationId]);

    // When a member message is logged, create receipts + notify staff
    if ($senderType === 'member') {
        _notifyStaffOfMemberMessage($conversationId, (int)$conv['institution_id'], $msgId);
    }

    return $msgId;
}

function _notifyStaffOfMemberMessage(int $convId, int $instId, int $msgId): void
{
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM users
         WHERE institution_id = ? AND role IN ('institution_admin','staff') AND is_active = 1"
    );
    $stmt->execute([$instId]);
    $link = BASE_URL . '/app/messages/conversation?id=' . $convId;

    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $userId) {
        dispatchNotification(
            (int)$userId,
            'new_message',
            'New Message Received',
            'A member response has been logged and is awaiting your attention.',
            $link,
            $instId
        );
        // Pre-create unread receipt for each staff user
        $db->prepare(
            "INSERT IGNORE INTO message_receipts (message_id, user_id) VALUES (?, ?)"
        )->execute([$msgId, $userId]);
    }
}

/**
 * Mark all messages in a conversation as read for $userId.
 * Uses INSERT ... SELECT to avoid N+1 queries.
 */
function markConversationRead(int $conversationId, int $userId): void
{
    getDB()->prepare(
        "INSERT INTO message_receipts (message_id, user_id, is_read, read_at)
         SELECT m.id, ?, 1, NOW()
         FROM messages m
         WHERE m.conversation_id = ?
         ON DUPLICATE KEY UPDATE
           is_read = 1,
           read_at = COALESCE(read_at, NOW())"
    )->execute([$userId, $conversationId]);
}

/**
 * Set the per-user archive flag on a single message.
 * The message itself is never deleted.
 */
function archiveMessageForUser(int $messageId, int $userId): void
{
    getDB()->prepare(
        "INSERT INTO message_receipts (message_id, user_id, is_archived)
         VALUES (?, ?, 1)
         ON DUPLICATE KEY UPDATE is_archived = 1"
    )->execute([$messageId, $userId]);
}

// ── Queries ────────────────────────────────────────────────

/**
 * Count unread messages (member-side messages) for an institution user.
 * Unread = message with sender_type='member' and no is_read receipt for this user.
 */
function getUnreadMessageCount(int $userId): int
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    try {
        $s = getDB()->prepare(
            "SELECT COUNT(m.id)
             FROM messages m
             JOIN conversations c ON c.id = m.conversation_id
             JOIN users u ON u.id = ? AND u.institution_id = c.institution_id
             LEFT JOIN message_receipts mr ON mr.message_id = m.id AND mr.user_id = ?
             WHERE m.sender_type = 'member'
               AND (mr.id IS NULL OR mr.is_read = 0)"
        );
        $s->execute([$userId, $userId]);
        return $cache[$userId] = (int)$s->fetchColumn();
    } catch (Exception $e) {
        return $cache[$userId] = 0;
    }
}

function getConversations(int $instId, int $limit = 30, int $offset = 0): array
{
    try {
        $s = getDB()->prepare(
            "SELECT c.*,
                    m.first_name, m.last_name, m.member_code,
                    (SELECT body FROM messages WHERE conversation_id = c.id
                     ORDER BY created_at DESC LIMIT 1) AS last_body,
                    (SELECT created_at FROM messages WHERE conversation_id = c.id
                     ORDER BY created_at DESC LIMIT 1) AS last_msg_time
             FROM conversations c
             JOIN members m ON m.id = c.member_id
             WHERE c.institution_id = ?
             ORDER BY COALESCE(c.last_message_at, c.created_at) DESC
             LIMIT ? OFFSET ?"
        );
        $s->execute([$instId, $limit, $offset]);
        return $s->fetchAll();
    } catch (Exception $e) { return []; }
}

function getConversation(int $conversationId, int $instId): ?array
{
    try {
        $s = getDB()->prepare(
            "SELECT c.*, m.first_name, m.last_name, m.member_code,
                    m.mobile AS member_mobile, m.id AS member_id
             FROM conversations c
             JOIN members m ON m.id = c.member_id
             WHERE c.id = ? AND c.institution_id = ?"
        );
        $s->execute([$conversationId, $instId]);
        return $s->fetch() ?: null;
    } catch (Exception $e) { return null; }
}

function getConversationMessages(int $conversationId, ?int $viewingUserId = null): array
{
    try {
        $s = getDB()->prepare(
            "SELECT msg.*,
                    u.full_name AS sender_name,
                    mr.is_read,
                    mr.is_archived
             FROM messages msg
             LEFT JOIN users u ON u.id = msg.sender_id
             LEFT JOIN message_receipts mr
                    ON mr.message_id = msg.id AND mr.user_id = ?
             WHERE msg.conversation_id = ?
             ORDER BY msg.created_at ASC"
        );
        $s->execute([$viewingUserId, $conversationId]);
        return $s->fetchAll();
    } catch (Exception $e) { return []; }
}
