<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole(['institution_admin']);

$instId = authInstId();
$filter = $_GET['status'] ?? 'pending';
$items  = getAllApprovals($instId, in_array($filter, ['pending','approved','rejected','cancelled','']) ? $filter : 'pending');

$pageTitle   = 'Approval Queue';
$breadcrumbs = ['Dashboard' => dashboardUrl(), 'Approval Queue' => ''];
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Filter tabs -->
<ul class="nav nav-pills mb-4">
  <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', '' => 'All'] as $val => $label): ?>
  <li class="nav-item">
    <a class="nav-link <?= $filter === $val ? 'active' : '' ?>"
       href="<?= h(BASE_URL . '/app/approval?status=' . $val) ?>"><?= $label ?></a>
  </li>
  <?php endforeach; ?>
</ul>

<?php if ($items): ?>
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0">
        <thead>
          <tr>
            <th>#</th>
            <th>Entity</th>
            <th>Details</th>
            <th>Submitted By</th>
            <th>Date</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $ar):
            $entity = getApprovalEntityDetails($ar['entity_type'], (int)$ar['entity_id']);
          ?>
          <tr>
            <td class="small text-muted"><?= $ar['id'] ?></td>
            <td>
              <span class="badge bg-secondary"><?= h(str_replace('_', ' ', ucfirst($ar['entity_type']))) ?></span>
            </td>
            <td class="small">
              <?php if ($entity): ?>
              <div class="fw-semibold"><?= h($entity['first_name'] . ' ' . $entity['last_name']) ?></div>
              <div class="text-muted"><?= h($entity['plan_name']) ?> &middot; ₹<?= number_format($entity['amount'], 2) ?></div>
              <?php else: ?>
              <span class="text-muted">ID #<?= $ar['entity_id'] ?></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= h($ar['requester_name']) ?></td>
            <td class="small text-muted"><?= fmtDate($ar['created_at'], 'd M Y') ?></td>
            <td><?= approvalStatusBadge($ar['status']) ?></td>
            <td>
              <a href="<?= h(BASE_URL . '/app/approval/review?id=' . $ar['id']) ?>"
                 class="btn btn-sm btn-outline-primary">
                <?= $ar['status'] === 'pending' ? 'Review' : 'View' ?>
              </a>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php else: ?>
<div class="empty-state py-5">
  <i class="bi bi-clipboard-check fs-1 text-muted"></i>
  <h5 class="mt-3 text-muted">
    <?= $filter === 'pending' ? 'No pending approvals' : 'No records found' ?>
  </h5>
</div>
<?php endif; ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
