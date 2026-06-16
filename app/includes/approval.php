<?php
/**
 * SportsInfraX – Approval Workflow Helpers
 *
 * Scope: membership_payment approvals.
 * When a staff user records a payment, createApprovalRequest() is called.
 * institution_admin reviews from /app/approval.
 */

/**
 * Submit an entity for approval.
 * If a pending request already exists for this entity, returns its ID unchanged.
 * Returns the approval_request ID.
 */
function createApprovalRequest(
    string  $entityType,
    int     $entityId,
    int     $instId,
    int     $requestedBy,
    ?string $notes = null
): int {
    $db = getDB();

    // Guard: one pending request per entity
    $chk = $db->prepare(
        "SELECT id FROM approval_requests WHERE entity_type = ? AND entity_id = ? AND status = 'pending'"
    );
    $chk->execute([$entityType, $entityId]);
    if ($existing = $chk->fetchColumn()) return (int)$existing;

    $db->prepare(
        "INSERT INTO approval_requests (entity_type, entity_id, institution_id, requested_by, notes)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([$entityType, $entityId, $instId, $requestedBy, $notes]);
    $requestId = (int)$db->lastInsertId();

    // Record submission in history
    $db->prepare(
        "INSERT INTO approval_history (request_id, actor_id, action, comment) VALUES (?, ?, 'submitted', ?)"
    )->execute([$requestId, $requestedBy, $notes]);

    // Notify all active institution_admins
    _notifyAdminsOfApproval($requestId, $instId);

    return $requestId;
}

function _notifyAdminsOfApproval(int $requestId, int $instId): void
{
    $db   = getDB();
    $stmt = $db->prepare(
        "SELECT id FROM users
         WHERE institution_id = ? AND role = 'institution_admin' AND is_active = 1"
    );
    $stmt->execute([$instId]);

    foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $adminId) {
        dispatchNotification(
            (int)$adminId,
            'approval_request',
            'Payment Approval Required',
            'A payment has been submitted by staff and requires your review.',
            BASE_URL . '/app/approval/review?id=' . $requestId,
            $instId,
            ['email']
        );
    }
}

/**
 * Approve, reject, or cancel an approval request.
 * $action: 'approved' | 'rejected' | 'cancelled'
 * Returns false if the request was already reviewed.
 */
function reviewApprovalRequest(
    int     $requestId,
    int     $actorId,
    string  $action,
    ?string $comment = null
): bool {
    if (!in_array($action, ['approved', 'rejected', 'cancelled'], true)) return false;

    $db = getDB();
    $db->prepare(
        "UPDATE approval_requests SET status = ?, updated_at = NOW()
         WHERE id = ? AND status = 'pending'"
    )->execute([$action, $requestId]);

    if ($db->rowCount() === 0) return false;

    $db->prepare(
        "INSERT INTO approval_history (request_id, actor_id, action, comment) VALUES (?, ?, ?, ?)"
    )->execute([$requestId, $actorId, $action, $comment]);

    // Notify original requester
    $stmt = $db->prepare(
        "SELECT requested_by, institution_id FROM approval_requests WHERE id = ?"
    );
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if ($req) {
        $suffix = $comment ? ' Note: ' . $comment : '.';
        dispatchNotification(
            (int)$req['requested_by'],
            'approval_status',
            'Payment ' . ucfirst($action),
            'Your submitted payment record has been ' . $action . $suffix,
            BASE_URL . '/app/approval/review?id=' . $requestId,
            (int)$req['institution_id'],
            ['email']
        );
    }

    return true;
}

// ── Queries ────────────────────────────────────────────────

function getApprovalRequest(int $requestId): ?array
{
    try {
        $s = getDB()->prepare(
            "SELECT ar.*, u.full_name AS requester_name, u.email AS requester_email
             FROM approval_requests ar
             JOIN users u ON u.id = ar.requested_by
             WHERE ar.id = ?"
        );
        $s->execute([$requestId]);
        return $s->fetch() ?: null;
    } catch (Exception $e) { return null; }
}

function getApprovalHistory(int $requestId): array
{
    try {
        $s = getDB()->prepare(
            "SELECT ah.*, u.full_name AS actor_name
             FROM approval_history ah
             LEFT JOIN users u ON u.id = ah.actor_id
             WHERE ah.request_id = ? ORDER BY ah.created_at ASC"
        );
        $s->execute([$requestId]);
        return $s->fetchAll();
    } catch (Exception $e) { return []; }
}

function getPendingApprovalsCount(int $instId): int
{
    try {
        $s = getDB()->prepare(
            "SELECT COUNT(*) FROM approval_requests WHERE institution_id = ? AND status = 'pending'"
        );
        $s->execute([$instId]);
        return (int)$s->fetchColumn();
    } catch (Exception $e) { return 0; }
}

function getAllApprovals(int $instId, string $status = '', int $limit = 50): array
{
    try {
        $where  = $status ? "AND ar.status = ?" : "";
        $params = $status ? [$instId, $status, $limit] : [$instId, $limit];
        $s = getDB()->prepare(
            "SELECT ar.*, u.full_name AS requester_name
             FROM approval_requests ar
             JOIN users u ON u.id = ar.requested_by
             WHERE ar.institution_id = ? {$where}
             ORDER BY ar.created_at DESC LIMIT ?"
        );
        $s->execute($params);
        return $s->fetchAll();
    } catch (Exception $e) { return []; }
}

/**
 * Load the entity linked to an approval request (currently: membership_payment).
 */
function getApprovalEntityDetails(string $entityType, int $entityId): ?array
{
    if ($entityType !== 'membership_payment') return null;
    try {
        $s = getDB()->prepare(
            "SELECT mp.*, ms.plan_name, ms.membership_number,
                    m.first_name, m.last_name, m.member_code, m.id AS member_id
             FROM membership_payments mp
             JOIN memberships ms ON ms.id = mp.membership_id
             JOIN members m ON m.id = ms.member_id
             WHERE mp.id = ?"
        );
        $s->execute([$entityId]);
        return $s->fetch() ?: null;
    } catch (Exception $e) { return null; }
}

// ── UI helpers ─────────────────────────────────────────────

function approvalStatusBadge(string $status): string
{
    $map = [
        'pending'   => ['warning text-dark', 'Pending Review'],
        'approved'  => ['success',           'Approved'],
        'rejected'  => ['danger',            'Rejected'],
        'cancelled' => ['secondary',         'Cancelled'],
    ];
    [$cls, $label] = $map[$status] ?? ['secondary', ucfirst($status)];
    return '<span class="badge bg-' . $cls . '">' . $label . '</span>';
}
