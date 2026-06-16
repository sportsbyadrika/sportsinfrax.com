<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$instId = authInstId();
$id     = (int)($_GET['id'] ?? 0);

$conv = getConversation($id, $instId);
if (!$conv) {
    setFlash('error', 'Conversation not found.');
    header('Location: ' . BASE_URL . '/app/messages');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    $body       = trim($_POST['body'] ?? '');
    $senderType = $_POST['sender_type'] ?? 'staff'; // 'staff' or 'member' (log received)
    $senderId   = $senderType === 'staff' ? authId() : null;

    if (!$body) {
        $error = 'Message cannot be empty.';
    } elseif (!in_array($senderType, ['staff', 'member'], true)) {
        $error = 'Invalid sender type.';
    } else {
        try {
            postMessage($id, $senderType, $senderId, $body);
            header('Location: ' . BASE_URL . '/app/messages/conversation?id=' . $id . '#bottom');
            exit;
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

// Mark all messages in this conversation as read for the current user
markConversationRead($id, authId());

$msgs = getConversationMessages($id, authId());

$memberName  = $conv['first_name'] . ' ' . $conv['last_name'];
$pageTitle   = 'Chat – ' . $memberName;
$breadcrumbs = [
    'Dashboard'  => dashboardUrl(),
    'Messages'   => BASE_URL . '/app/messages',
    $memberName  => '',
];
$pageAction = '<a href="' . h(BASE_URL . '/app/members/view?id=' . $conv['member_id']) . '"
                   class="btn btn-sm btn-outline-primary">
  <i class="bi bi-person me-1"></i>View Member Profile
</a>';
require_once APP_ROOT . '/includes/header.php';
?>

<div class="row g-4">

  <!-- Left: Thread -->
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header d-flex align-items-center justify-content-between">
        <span>
          <i class="bi bi-chat-dots me-2 text-primary"></i>
          <?= h($conv['subject']) ?>
          <?php if ($conv['is_locked']): ?>
          <span class="badge bg-secondary ms-2">Locked</span>
          <?php endif; ?>
        </span>
        <span class="text-muted small"><?= count($msgs) ?> message<?= count($msgs) !== 1 ? 's' : '' ?></span>
      </div>

      <!-- Messages -->
      <div class="card-body p-3" style="min-height:360px;max-height:520px;overflow-y:auto;" id="messageThread">
        <?php if ($msgs): ?>
        <?php foreach ($msgs as $msg):
          $isStaff    = $msg['sender_type'] === 'staff';
          $senderLabel = $isStaff ? h($msg['sender_name'] ?? 'Staff') : h($memberName) . ' <span class="badge bg-info text-dark" style="font-size:.65rem;">Member</span>';
        ?>
        <div class="d-flex mb-3 <?= $isStaff ? 'justify-content-end' : '' ?>
                    <?= $msg['is_archived'] ? 'opacity-50' : '' ?>">
          <div style="max-width:80%;">
            <div class="small text-muted mb-1 <?= $isStaff ? 'text-end' : '' ?>"><?= $senderLabel ?></div>
            <div class="rounded-3 px-3 py-2 <?= $isStaff ? 'bg-primary text-white' : 'bg-light' ?>"
                 style="font-size:.9rem;">
              <?= nl2br(h($msg['body'])) ?>
            </div>
            <div class="small text-muted mt-1 <?= $isStaff ? 'text-end' : '' ?>">
              <?= fmtDate($msg['created_at'], 'd M Y, H:i') ?>
              <?php if ($isStaff && $msg['is_read']): ?>
              <i class="bi bi-check2-all text-primary ms-1" title="Read"></i>
              <?php endif; ?>
              <?php if ($msg['is_archived']): ?>
              <span class="badge bg-secondary ms-1" style="font-size:.6rem;">Archived</span>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <div id="bottom"></div>
        <?php else: ?>
        <div class="text-center text-muted py-4 small">No messages yet. Start the conversation below.</div>
        <?php endif; ?>
      </div>

      <!-- Compose -->
      <?php if (!$conv['is_locked']): ?>
      <div class="card-footer p-3">
        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small mb-2"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="POST">
          <?= csrfField() ?>
          <div class="mb-2">
            <select class="form-select form-select-sm mb-2" name="sender_type" style="max-width:220px;">
              <option value="staff">Sending as: <?= h(authName()) ?> (Staff)</option>
              <option value="member">Logging: <?= h($memberName) ?>'s reply</option>
            </select>
            <textarea class="form-control" name="body" rows="3"
                      placeholder="Type your message…" required></textarea>
          </div>
          <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted">
              <i class="bi bi-info-circle me-1"></i>
              All messages are visible to institution admins.
            </small>
            <button type="submit" class="btn btn-primary btn-sm px-3">
              <i class="bi bi-send me-1"></i>Send
            </button>
          </div>
        </form>
      </div>
      <?php else: ?>
      <div class="card-footer text-muted small py-2 text-center">
        <i class="bi bi-lock-fill me-1"></i>This conversation is locked and cannot receive new messages.
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Right: Member Info -->
  <div class="col-lg-4">
    <div class="card mb-3">
      <div class="card-header small"><i class="bi bi-person me-2 text-primary"></i>Member</div>
      <div class="card-body small">
        <div class="fw-semibold"><?= h($memberName) ?></div>
        <div class="text-muted font-monospace" style="font-size:.72rem;"><?= h($conv['member_code']) ?></div>
        <?php if ($conv['member_mobile']): ?>
        <div class="mt-2"><i class="bi bi-telephone me-1 text-muted"></i><?= h($conv['member_mobile']) ?></div>
        <?php endif; ?>
        <div class="mt-2">
          <a href="<?= h(BASE_URL . '/app/members/view?id=' . $conv['member_id']) ?>"
             class="btn btn-sm btn-outline-primary w-100">View Profile</a>
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-header small"><i class="bi bi-chat-square me-2 text-primary"></i>Conversation</div>
      <div class="card-body small">
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Subject</span><span><?= h($conv['subject']) ?></span>
        </div>
        <div class="d-flex justify-content-between mb-1">
          <span class="text-muted">Started</span><span><?= fmtDate($conv['created_at'], 'd M Y') ?></span>
        </div>
        <div class="d-flex justify-content-between">
          <span class="text-muted">Messages</span><span><?= count($msgs) ?></span>
        </div>
      </div>
      <?php if (isInstAdmin()): ?>
      <div class="card-footer">
        <a href="<?= h(BASE_URL . '/app/messages') ?>" class="btn btn-sm btn-outline-secondary w-100">
          <i class="bi bi-arrow-left me-1"></i>All Conversations
        </a>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<script>
// Scroll thread to bottom on load
document.addEventListener('DOMContentLoaded', function () {
  const thread = document.getElementById('messageThread');
  if (thread) thread.scrollTop = thread.scrollHeight;
});
</script>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
