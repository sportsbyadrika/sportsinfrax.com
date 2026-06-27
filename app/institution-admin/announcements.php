<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

// ── Load record for editing ───────────────────────────────────────────────────
$editId  = (int)($_GET['edit_id'] ?? 0);
$editAnn = null;
if ($editId) {
    $es = $db->prepare("SELECT * FROM announcements WHERE id = ? AND institution_id = ?");
    $es->execute([$editId, $instId]);
    $editAnn = $es->fetch();
    if (!$editAnn) {
        setFlash('error', 'Announcement not found.');
        header('Location: ' . BASE_URL . '/app/institution-admin/announcements');
        exit;
    }
}

$error = '';

// ── POST handling ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    // ── Toggle active ────────────────────────────────────────────────────────
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $db->prepare(
            "UPDATE announcements SET is_active = 1 - is_active WHERE id = ? AND institution_id = ?"
        )->execute([$id, $instId]);
        setFlash('success', 'Announcement status updated.');
        header('Location: ' . BASE_URL . '/app/institution-admin/announcements');
        exit;

    // ── Delete ───────────────────────────────────────────────────────────────
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            $db->prepare(
                "DELETE FROM announcements WHERE id = ? AND institution_id = ?"
            )->execute([$id, $instId]);
            setFlash('success', 'Announcement deleted.');
        } catch (Exception $e) {
            setFlash('error', 'Could not delete this announcement.');
        }
        header('Location: ' . BASE_URL . '/app/institution-admin/announcements');
        exit;

    // ── Add / Edit ───────────────────────────────────────────────────────────
    } elseif ($action === 'add' || $action === 'edit') {
        $title      = trim($_POST['title']      ?? '');
        $body       = trim($_POST['body']       ?? '');
        $type       = $_POST['type']            ?? 'announcement';
        $audience   = $_POST['audience']        ?? 'all';
        $isPinned   = isset($_POST['is_pinned']) ? 1 : 0;
        $expiresAt  = trim($_POST['expires_at'] ?? '');
        $actId      = (int)($_POST['id']        ?? 0);

        $validTypes     = ['announcement', 'notice', 'circular', 'event'];
        $validAudiences = ['all', 'staff', 'students'];

        if (!$title) {
            $error = 'Title is required.';
        } elseif (mb_strlen($title) > 200) {
            $error = 'Title must be 200 characters or fewer.';
        } elseif (!in_array($type, $validTypes, true)) {
            $error = 'Invalid type selected.';
        } elseif (!in_array($audience, $validAudiences, true)) {
            $error = 'Invalid audience selected.';
        }

        if (!$error) {
            $expiresVal = ($expiresAt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiresAt))
                ? $expiresAt
                : null;
            $bodyVal = $body !== '' ? $body : null;

            try {
                if ($action === 'edit' && $actId) {
                    $db->prepare(
                        "UPDATE announcements
                         SET title=?, body=?, type=?, audience=?, is_pinned=?, expires_at=?
                         WHERE id=? AND institution_id=?"
                    )->execute([
                        $title, $bodyVal, $type, $audience,
                        $isPinned, $expiresVal, $actId, $instId
                    ]);
                    setFlash('success', 'Announcement updated.');
                } else {
                    $db->prepare(
                        "INSERT INTO announcements
                         (institution_id, title, body, type, audience, is_pinned, created_by)
                         VALUES (?,?,?,?,?,?,?)"
                    )->execute([
                        $instId, $title, $bodyVal, $type, $audience,
                        $isPinned, authId()
                    ]);
                    setFlash('success', "Announcement '{$title}' added.");
                }
            } catch (Exception $e) {
                $error = 'Database error. Please try again.';
            }

            if (!$error) {
                header('Location: ' . BASE_URL . '/app/institution-admin/announcements');
                exit;
            }
        }
    }
}

// ── Fetch list ────────────────────────────────────────────────────────────────
$listStmt = $db->prepare(
    "SELECT a.*, u.full_name AS author
     FROM announcements a
     LEFT JOIN users u ON u.id = a.created_by
     WHERE a.institution_id = ?
     ORDER BY a.is_pinned DESC, a.published_at DESC"
);
$listStmt->execute([$instId]);
$announcements = $listStmt->fetchAll();

// ── Page meta ─────────────────────────────────────────────────────────────────
$pageTitle   = 'Announcements';
$breadcrumbs = [
    'Dashboard'     => BASE_URL . '/app/institution-admin/dashboard',
    'Services'      => BASE_URL . '/app/services',
    'Announcements' => '',
];
require_once APP_ROOT . '/includes/header.php';

// Shorthand for form repopulation
$v = fn(string $f, string $d = '') => h($editAnn[$f] ?? $_POST[$f] ?? $d);

// Badge helpers
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
?>

<div class="row g-4 align-items-start">

  <!-- ── Left: Add / Edit Form ──────────────────────────────────────────── -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">
        <i class="bi bi-megaphone me-2 text-primary"></i>
        <?= $editAnn ? 'Edit Announcement' : 'Add Announcement' ?>
      </div>
      <div class="card-body">

        <?php if ($error): ?>
        <div class="alert alert-danger py-2 small"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="POST" novalidate>
          <?= csrfField() ?>
          <input type="hidden" name="action" value="<?= $editAnn ? 'edit' : 'add' ?>">
          <?php if ($editAnn): ?>
          <input type="hidden" name="id" value="<?= (int)$editAnn['id'] ?>">
          <?php endif; ?>

          <div class="mb-3">
            <label class="form-label">Title <span class="required">*</span></label>
            <input type="text" class="form-control" name="title"
                   value="<?= $v('title') ?>"
                   maxlength="200"
                   placeholder="e.g. Annual Sports Day, Fee Reminder"
                   required>
          </div>

          <div class="mb-3">
            <label class="form-label">Body</label>
            <textarea class="form-control" name="body"
                      rows="4"
                      placeholder="Optional details or message…"><?= $v('body') ?></textarea>
          </div>

          <div class="mb-3">
            <label class="form-label">Type <span class="required">*</span></label>
            <select class="form-select" name="type" required>
              <?php foreach ($typeLabels as $val => $lbl): ?>
              <option value="<?= $val ?>"
                <?= ($v('type', 'announcement') === $val) ? 'selected' : '' ?>>
                <?= $lbl ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Audience <span class="required">*</span></label>
            <select class="form-select" name="audience" required>
              <?php foreach ($audienceLabels as $val => $lbl): ?>
              <option value="<?= $val ?>"
                <?= ($v('audience', 'all') === $val) ? 'selected' : '' ?>>
                <?= $lbl ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">Expires On</label>
            <input type="date" class="form-control" name="expires_at"
                   value="<?= $v('expires_at') ?>">
            <div class="form-text">Leave blank for no expiry.</div>
          </div>

          <div class="mb-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="is_pinned"
                     id="is_pinned" value="1"
                     <?= (!empty($editAnn['is_pinned']) || (!$editAnn && !empty($_POST['is_pinned']))) ? 'checked' : '' ?>>
              <label class="form-check-label" for="is_pinned">
                <i class="bi bi-pin-angle-fill text-warning me-1"></i>Pin this announcement
              </label>
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
              <i class="bi bi-check2 me-1"></i>
              <?= $editAnn ? 'Save Changes' : 'Add Announcement' ?>
            </button>
            <?php if ($editAnn): ?>
            <a href="<?= h(BASE_URL . '/app/institution-admin/announcements') ?>"
               class="btn btn-outline-secondary">Cancel</a>
            <?php endif; ?>
          </div>

        </form>
      </div>
    </div>
  </div>

  <!-- ── Right: List ────────────────────────────────────────────────────── -->
  <div class="col-lg-8">
    <div class="card table-card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-megaphone me-2 text-primary"></i>Announcements
          <span class="badge bg-secondary ms-1"><?= count($announcements) ?></span>
        </span>
      </div>

      <?php if ($announcements): ?>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th style="width:2.5rem">#</th>
              <th>Title</th>
              <th>Type</th>
              <th>Audience</th>
              <th style="width:2.5rem" class="text-center" title="Pinned" data-bs-toggle="tooltip">
                <i class="bi bi-pin-angle"></i>
              </th>
              <th>Status</th>
              <th>Expires</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($announcements as $i => $ann): ?>
            <?php
                $title60    = mb_strlen($ann['title']) > 60
                    ? mb_substr($ann['title'], 0, 60) . '…'
                    : $ann['title'];
                $isExpired  = $ann['expires_at'] && $ann['expires_at'] < date('Y-m-d');
            ?>
            <tr>
              <td class="text-muted small"><?= $i + 1 ?></td>
              <td>
                <span title="<?= h($ann['title']) ?>" data-bs-toggle="tooltip">
                  <?= h($title60) ?>
                </span>
                <?php if ($ann['is_pinned']): ?>
                <i class="bi bi-pin-angle-fill text-warning ms-1" style="font-size:.75rem;"></i>
                <?php endif; ?>
              </td>
              <td>
                <span class="badge bg-<?= $typeBadge[$ann['type']] ?? 'secondary' ?>">
                  <?= h($typeLabels[$ann['type']] ?? ucfirst($ann['type'])) ?>
                </span>
              </td>
              <td>
                <span class="badge bg-<?= $audienceBadge[$ann['audience']] ?? 'secondary' ?>">
                  <?= h($audienceLabels[$ann['audience']] ?? ucfirst($ann['audience'])) ?>
                </span>
              </td>
              <td class="text-center">
                <?php if ($ann['is_pinned']): ?>
                <i class="bi bi-pin-angle-fill text-warning" title="Pinned" data-bs-toggle="tooltip"></i>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($isExpired): ?>
                <span class="badge bg-secondary">Expired</span>
                <?php elseif ($ann['is_active']): ?>
                <span class="badge bg-success">Active</span>
                <?php else: ?>
                <span class="badge bg-danger">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="small text-muted">
                <?= $ann['expires_at'] ? fmtDate($ann['expires_at'], 'd M Y') : '—' ?>
              </td>
              <td>
                <div class="d-flex gap-1 flex-nowrap">
                  <!-- Edit -->
                  <a href="<?= h(BASE_URL . '/app/institution-admin/announcements?edit_id=' . (int)$ann['id']) ?>"
                     class="btn btn-sm btn-outline-primary btn-icon"
                     title="Edit" data-bs-toggle="tooltip">
                    <i class="bi bi-pencil"></i>
                  </a>
                  <!-- Toggle active -->
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-icon <?= $ann['is_active'] ? 'btn-outline-warning' : 'btn-outline-success' ?>"
                            title="<?= $ann['is_active'] ? 'Deactivate' : 'Activate' ?>"
                            data-bs-toggle="tooltip"
                            data-confirm="<?= $ann['is_active']
                                ? 'Deactivate this announcement?'
                                : 'Activate this announcement?' ?>">
                      <i class="bi <?= $ann['is_active'] ? 'bi-eye-slash' : 'bi-eye' ?>"></i>
                    </button>
                  </form>
                  <!-- Delete -->
                  <form method="POST" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id"     value="<?= (int)$ann['id'] ?>">
                    <button type="submit"
                            class="btn btn-sm btn-outline-danger btn-icon"
                            title="Delete" data-bs-toggle="tooltip"
                            data-confirm="Delete '<?= h(addslashes($ann['title'])) ?>'? This cannot be undone.">
                      <i class="bi bi-trash"></i>
                    </button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php else: ?>
      <div class="empty-state py-5">
        <i class="bi bi-megaphone"></i>
        <h6>No announcements yet</h6>
        <p class="small">Use the form on the left to publish your first announcement.</p>
      </div>
      <?php endif; ?>

    </div>
  </div>

</div>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
