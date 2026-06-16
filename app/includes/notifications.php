<?php
/**
 * SportsInfraX – Notification Dispatcher
 *
 * Creates in-app notification rows and queues external channel sends
 * (email / SMS / WhatsApp) for processing by the cron script.
 */

/**
 * Create an in-app notification and optionally queue external channel sends.
 * Returns the new notifications.id.
 *
 * $channels: e.g. ['email'] queues an email send; ['sms','whatsapp'] for both.
 */
function dispatchNotification(
    int     $userId,
    string  $type,
    string  $title,
    string  $body,
    ?string $link     = null,
    ?int    $instId   = null,
    array   $channels = []
): int {
    $db = getDB();

    $db->prepare(
        "INSERT INTO notifications (user_id, institution_id, type, title, body, link)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$userId, $instId, $type, $title, $body, $link]);
    $notifId = (int)$db->lastInsertId();

    if ($channels) {
        $uStmt = $db->prepare("SELECT email, mobile FROM users WHERE id = ?");
        $uStmt->execute([$userId]);
        $user = $uStmt->fetch();

        if ($user) {
            foreach ($channels as $ch) {
                $recipient = match($ch) {
                    'email'              => $user['email']  ?: null,
                    'sms', 'whatsapp'   => $user['mobile'] ?: null,
                    default              => null,
                };
                if ($recipient) {
                    _queueExternalNotif($notifId, $ch, $recipient, $title, $body);
                }
            }
        }
    }

    return $notifId;
}

function _queueExternalNotif(
    int    $notifId,
    string $channel,
    string $recipient,
    string $subject,
    string $body
): void {
    getDB()->prepare(
        "INSERT INTO notification_queue (notification_id, channel, recipient, subject, body)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$notifId, $channel, $recipient, $subject, $body]);
}

// ── Accessors ──────────────────────────────────────────────

function getUnreadNotificationCount(int $userId): int
{
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    try {
        $s = getDB()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $s->execute([$userId]);
        return $cache[$userId] = (int)$s->fetchColumn();
    } catch (Exception $e) {
        return $cache[$userId] = 0;
    }
}

function getRecentNotifications(int $userId, int $limit = 10): array
{
    try {
        $s = getDB()->prepare(
            "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ?"
        );
        $s->execute([$userId, $limit]);
        return $s->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

function markNotificationRead(int $notifId, int $userId): void
{
    getDB()->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE id = ? AND user_id = ? AND is_read = 0"
    )->execute([$notifId, $userId]);
}

function markAllNotificationsRead(int $userId): void
{
    getDB()->prepare(
        "UPDATE notifications SET is_read = 1, read_at = NOW()
         WHERE user_id = ? AND is_read = 0"
    )->execute([$userId]);
}

// ── UI helpers ─────────────────────────────────────────────

function notificationIcon(string $type): string
{
    return match($type) {
        'approval_request'  => 'bi-clipboard-check text-warning',
        'approval_status'   => 'bi-check-circle-fill text-success',
        'new_message'       => 'bi-chat-dots-fill text-primary',
        'membership_expiry' => 'bi-calendar-x-fill text-danger',
        'payment_recorded'  => 'bi-cash-coin text-success',
        default             => 'bi-bell-fill text-secondary',
    };
}
