<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireLogin();

$userId = authId();
$db     = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    markAllNotificationsRead($userId);
    setFlash('success', 'All notifications marked as read.');
    header('Location: ' . BASE_URL . '/app/notifications');
    exit;
}

// Mark notification as read + redirect (GET ?read_id=X&to=URL)
if (isset($_GET['read_id'])) {
    $readId = (int)$_GET['read_id'];
    $to     = $_GET['to'] ?? '';
    markNotificationRead($readId, $userId);
    if ($to && str_starts_with($to, '/')) {
        header('Location: ' . BASE_URL . $to);
        exit;
    }
    header('Location: ' . BASE_URL . '/app/notifications');
    exit;
}

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset  = ($page - 1) * $perPage;

$cntStmt = $db->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$cntStmt->execute([$userId]);
$total = (int)$cntStmt->fetchColumn();

$listStmt = $db->prepare(
    "SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?"
);
$listStmt->execute([$userId, $perPage, $offset]);
$items = $listStmt->fetchAll();

$unread = getUnreadNotificationCount($userId);

$pageTitle   = 'Notifications';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Notifications' => ''];
$pageAction  = $unread > 0
    ? '<form method="POST">' . csrfField()
      . '<button class="btn btn-sm btn-outline-secondary"><i class="bi bi-check2-all me-1"></i>Mark All Read</button></form>'
    : '';
require_once APP_ROOT . '/includes/header.php';
?>

<?php if ($items): ?>
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span><i class="bi bi-bell me-2 text-primary"></i>All Notifications</span>
    <span class="text-muted small"><?= $total ?> total<?= $unread ? ' &middot; <strong>' . $unread . ' unread</strong>' : '' ?></span>
  </div>
  <ul class="list-group list-group-flush">
    <?php foreach ($items as $n):
      $isRead  = (bool)$n['is_read'];
      $linkHref = $n['link']
          ? h(BASE_URL . '/app/notifications?read_id=' . $n['id'] . '&to=' . urlencode(parse_url($n['link'], PHP_URL_PATH) . (parse_url($n['link'], PHP_URL_QUERY) ? '?' . parse_url($n['link'], PHP_URL_QUERY) : '')))
          : h(BASE_URL . '/app/notifications?read_id=' . $n['id']);
    ?>
    <li class="list-group-item list-group-item-action px-4 py-3 <?= $isRead ? '' : 'bg-light' ?>">
      <a href="<?= $linkHref ?>" class="text-decoration-none text-reset d-flex gap-3 align-items-start">
        <span class="mt-1"><i class="bi <?= notificationIcon($n['type']) ?> fs-5"></i></span>
        <div class="flex-grow-1">
          <div class="<?= $isRead ? 'text-muted' : 'fw-semibold' ?>"><?= h($n['title']) ?></div>
          <?php if ($n['body']): ?>
          <div class="small text-muted mt-1"><?= h($n['body']) ?></div>
          <?php endif; ?>
          <div class="text-muted" style="font-size:.75rem;"><?= fmtDate($n['created_at'], 'd M Y, H:i') ?></div>
        </div>
        <?php if (!$isRead): ?>
        <span class="flex-shrink-0 mt-2" style="width:9px;height:9px;background:#0b5ed7;border-radius:50%;display:block;"></span>
        <?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php if ($total > $perPage): ?>
  <div class="card-footer"><?= paginate($total, $page, $perPage, BASE_URL . '/app/notifications') ?></div>
  <?php endif; ?>
</div>
<?php else: ?>
<div class="empty-state py-5">
  <i class="bi bi-bell-slash fs-1 text-muted"></i>
  <h5 class="mt-3 text-muted">No notifications yet</h5>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
