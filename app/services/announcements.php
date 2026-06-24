<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin', 'staff']);

$db     = getDB();
$instId = authInstId();

$isAdmin = isInstAdmin();

// ── Build query based on role ─────────────────────────────────────────────────
if ($isAdmin) {
    // Admin sees all announcements for the institution (active and inactive),
    // ordered pinned first then most recent.
    $stmt = $db->prepare(
        "SELECT a.*, u.full_name AS author
         FROM announcements a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.institution_id = ?
         ORDER BY a.is_pinned DESC, a.published_at DESC
         LIMIT 50"
    );
    $stmt->execute([$instId]);
} else {
    // Staff sees active, non-expired announcements targeted at all or staff.
    $stmt = $db->prepare(
        "SELECT a.*, u.full_name AS author
         FROM announcements a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.institution_id = ?
           AND a.is_active = 1
           AND a.audience IN ('all', 'staff')
           AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
         ORDER BY a.is_pinned DESC, a.published_at DESC
         LIMIT 50"
    );
    $stmt->execute([$instId]);
}

$announcements = $stmt->fetchAll();

// ── Badge helpers ─────────────────────────────────────────────────────────────
$typeBadge = [
    'announcement' => 'primary',
    'notice'       => 'warning text-dark',
    'circular'     => 'info text-dark',
    'event'        => 'success',
];
$audienceBadge = [
    'all'      => 'secondary',
    'staff'    => 'primary',
    'students' => 'success',
];
$typeLabels = [
    'announcement' => 'Announcement',
    'notice'       => 'Notice',
    'circular'     => 'Circular',
    'event'        => 'Event',
];
$audienceLabels = [
    'all'      => 'All',
    'staff'    => 'Staff',
    'students' => 'Students',
];

// ── Page meta ─────────────────────────────────────────────────────────────────
$pageTitle   = 'Announcements';
$breadcrumbs = [
    'Dashboard'     => dashboardUrl(),
    'Services'      => BASE_URL . '/app/services',
    'Announcements' => '',
];
if ($isAdmin) {
    $pageAction = '<a href="' . h(BASE_URL . '/app/institution-admin/announcements') . '"
                      class="btn btn-primary btn-sm">
                     <i class="bi bi-plus-circle me-1"></i>Add Announcement
                   </a>';
}
require_once APP_ROOT . '/includes/header.php';
?>

<div class="section-header-strip mb-4">
  <div class="section-icon"><i class="bi bi-megaphone-fill"></i></div>
  <div>
    <h4>Announcements</h4>
    <p>Stay up to date with the latest notices, circulars and events from your institution.</p>
  </div>
</div>

<?php if (!$announcements): ?>
<div class="empty-state py-5">
  <i class="bi bi-megaphone"></i>
  <h6>No announcements</h6>
  <p class="small">There are no announcements to display at this time.</p>
  <?php if ($isAdmin): ?>
  <a href="<?= h(BASE_URL . '/app/institution-admin/announcements') ?>"
     class="btn btn-primary btn-sm mt-2">
    <i class="bi bi-plus-circle me-1"></i>Add Announcement
  </a>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="row g-3">
  <?php foreach ($announcements as $ann): ?>
  <?php
      $isExpired = $ann['expires_at'] && $ann['expires_at'] < date('Y-m-d');
      $isPinned  = (bool)$ann['is_pinned'];
      $cardStyle = $isPinned ? ' border-start border-warning border-3' : '';
  ?>
  <div class="col-12">
    <div class="card<?= $cardStyle ?>">
      <div class="card-body pb-2">

        <!-- Top row: badges + date -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
          <!-- Type badge -->
          <span class="badge bg-<?= $typeBadge[$ann['type']] ?? 'secondary' ?>">
            <?= h($typeLabels[$ann['type']] ?? ucfirst($ann['type'])) ?>
          </span>

          <!-- Audience badge -->
          <span class="badge bg-<?= $audienceBadge[$ann['audience']] ?? 'secondary' ?>">
            <i class="bi bi-people me-1"></i><?= h($audienceLabels[$ann['audience']] ?? ucfirst($ann['audience'])) ?>
          </span>

          <?php if ($isPinned): ?>
          <span title="Pinned" data-bs-toggle="tooltip">
            <i class="bi bi-pin-angle-fill text-warning" style="font-size:.95rem;"></i>
          </span>
          <?php endif; ?>

          <?php if ($isExpired): ?>
          <span class="badge bg-secondary">Expired</span>
          <?php elseif ($isAdmin && !$ann['is_active']): ?>
          <span class="badge bg-danger">Inactive</span>
          <?php endif; ?>

          <!-- Published date (push to right on wider screens) -->
          <span class="ms-auto text-muted small text-nowrap">
            <i class="bi bi-clock me-1"></i><?= fmtDate($ann['published_at'], 'd M Y') ?>
          </span>
        </div>

        <!-- Title -->
        <h6 class="fw-bold mb-1<?= ($isExpired || ($isAdmin && !$ann['is_active'])) ? ' text-muted' : '' ?>">
          <?= h($ann['title']) ?>
        </h6>

        <!-- Body -->
        <?php if (!empty($ann['body'])): ?>
        <p class="text-muted small mb-2"
           style="white-space:pre-line;overflow:hidden;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;">
          <?= h($ann['body']) ?>
        </p>
        <?php endif; ?>

      </div>

      <!-- Footer: author + admin link -->
      <div class="card-footer py-2 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <span class="small text-muted">
          <i class="bi bi-person me-1"></i>
          Posted by <?= $ann['author'] ? h($ann['author']) : 'System' ?>
          <?php if ($ann['expires_at'] && !$isExpired): ?>
          &middot; Expires <?= fmtDate($ann['expires_at'], 'd M Y') ?>
          <?php endif; ?>
        </span>
        <?php if ($isAdmin): ?>
        <a href="<?= h(BASE_URL . '/app/institution-admin/announcements?edit_id=' . (int)$ann['id']) ?>"
           class="btn btn-sm btn-outline-secondary py-0 px-2">
          <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
