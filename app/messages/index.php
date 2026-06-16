<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$instId = authInstId();
$db     = getDB();

// New conversation: staff selects a member
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $memberId = (int)($_POST['member_id'] ?? 0);
    $subject  = trim($_POST['subject'] ?? 'General') ?: 'General';

    // Verify member belongs to this institution
    $mStmt = $db->prepare("SELECT id FROM members WHERE id = ? AND institution_id = ? AND is_active = 1");
    $mStmt->execute([$memberId, $instId]);
    if (!$mStmt->fetchColumn()) {
        $error = 'Member not found.';
    } else {
        $convId = getOrCreateConversation($memberId, $instId, authId(), $subject);
        header('Location: ' . BASE_URL . '/app/messages/conversation?id=' . $convId);
        exit;
    }
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$allConvs = getConversations($instId, $perPage, $offset);

$totalStmt = $db->prepare("SELECT COUNT(*) FROM conversations WHERE institution_id = ?");
$totalStmt->execute([$instId]);
$total = (int)$totalStmt->fetchColumn();

// Members for new-conversation dropdown
$membersStmt = $db->prepare(
    "SELECT id, first_name, last_name, member_code FROM members
     WHERE institution_id = ? AND is_active = 1
     ORDER BY first_name, last_name LIMIT 500"
);
$membersStmt->execute([$instId]);
$members = $membersStmt->fetchAll();

$pageTitle   = 'Messages';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Messages' => ''];
$pageAction  = '<button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#newConvModal">
  <i class="bi bi-plus-lg me-1"></i>New Conversation
</button>';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- New conversation modal -->
<div class="modal fade" id="newConvModal" tabindex="-1" aria-labelledby="newConvModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST">
        <?= csrfField() ?>
        <div class="modal-header">
          <h5 class="modal-title" id="newConvModalLabel">
            <i class="bi bi-chat-plus me-2 text-primary"></i>New Conversation
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <?php if ($error): ?>
          <div class="alert alert-danger small py-2"><?= h($error) ?></div>
          <?php endif; ?>
          <div class="mb-3">
            <label class="form-label">Member <span class="required">*</span></label>
            <select class="form-select" name="member_id" required>
              <option value="">Select member…</option>
              <?php foreach ($members as $m): ?>
              <option value="<?= $m['id'] ?>"><?= h($m['first_name'] . ' ' . $m['last_name']) ?> (<?= h($m['member_code']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="mb-3">
            <label class="form-label">Subject</label>
            <input type="text" class="form-control" name="subject" value="General" maxlength="255">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-chat-plus me-1"></i>Open</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($allConvs): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-chat-dots me-2 text-primary"></i>Conversations</div>
  <ul class="list-group list-group-flush">
    <?php foreach ($allConvs as $conv): ?>
    <li class="list-group-item list-group-item-action px-4 py-3">
      <a href="<?= h(BASE_URL . '/app/messages/conversation?id=' . $conv['id']) ?>"
         class="text-decoration-none text-reset d-flex align-items-start gap-3">
        <span class="avatar-circle flex-shrink-0" style="width:40px;height:40px;font-size:.9rem;">
          <?= h(mb_strtoupper(mb_substr($conv['first_name'], 0, 1))) ?>
        </span>
        <div class="flex-grow-1 min-w-0">
          <div class="d-flex justify-content-between">
            <span class="fw-semibold">
              <?= h($conv['first_name'] . ' ' . $conv['last_name']) ?>
              <span class="text-muted fw-normal small font-monospace">(<?= h($conv['member_code']) ?>)</span>
            </span>
            <span class="text-muted small flex-shrink-0 ms-2">
              <?= $conv['last_msg_time'] ? fmtDate($conv['last_msg_time'], 'd M') : fmtDate($conv['created_at'], 'd M') ?>
            </span>
          </div>
          <div class="small text-muted"><?= h($conv['subject']) ?></div>
          <?php if ($conv['last_body']): ?>
          <div class="small text-muted text-truncate" style="max-width:420px;">
            <?= h(mb_strtolower(mb_substr(strip_tags($conv['last_body']), 0, 80))) ?>…
          </div>
          <?php endif; ?>
        </div>
        <?php if ($conv['is_locked']): ?>
        <span class="badge bg-secondary flex-shrink-0">Locked</span>
        <?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($total > $perPage): ?>
  <div class="card-footer"><?= paginate($total, $page, $perPage, BASE_URL . '/app/messages') ?></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="empty-state py-5">
  <i class="bi bi-chat-dots fs-1 text-muted"></i>
  <h5 class="mt-3 text-muted">No conversations yet</h5>
  <p class="text-muted small">Start a conversation with a member.</p>
  <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#newConvModal">
    <i class="bi bi-plus-lg me-1"></i>New Conversation
  </button>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
