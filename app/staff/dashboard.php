<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('staff');

$db     = getDB();
$instId = authInstId();

// Get institution
$instStmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$instStmt->execute([$instId]);
$inst = $instStmt->fetch();

if (!$inst || $inst['status'] !== 'active') {
    setFlash('warning', 'Your institution is not yet active. Please contact your institution admin.');
    // Show limited view
}

$category = getInstitutionCategory($inst['institution_type'] ?? '');
$isSchool  = ($category === 'school');

// Stats
$totalMembers = 0; $activeMs = 0; $expSoon = 0; $newToday = 0;
if ($instId) {
    if ($isSchool) {
        $mStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE institution_id = ?");
        $mStmt->execute([$instId]);
        $totalMembers = (int)$mStmt->fetchColumn();

        $aStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE institution_id = ? AND is_active = 1");
        $aStmt->execute([$instId]);
        $activeMs = (int)$aStmt->fetchColumn();

        // $expSoon = fee dues placeholder
        $nStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE institution_id = ? AND DATE(created_at) = CURDATE()");
        $nStmt->execute([$instId]);
        $newToday = (int)$nStmt->fetchColumn();
    } else {
        $mStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE institution_id = ? AND is_active = 1");
        $mStmt->execute([$instId]);
        $totalMembers = (int)$mStmt->fetchColumn();

        $aStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms WHERE ms.institution_id = ? AND ms.end_date >= CURDATE()");
        $aStmt->execute([$instId]);
        $activeMs = (int)$aStmt->fetchColumn();

        $eStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms WHERE ms.institution_id = ? AND ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        $eStmt->execute([$instId]);
        $expSoon = (int)$eStmt->fetchColumn();

        $nStmt = $db->prepare("SELECT COUNT(*) FROM members WHERE institution_id = ? AND DATE(created_at) = CURDATE()");
        $nStmt->execute([$instId]);
        $newToday = (int)$nStmt->fetchColumn();
    }
}

// My pending approvals (submissions by this staff member awaiting review)
$myPendingApprovals = [];
if ($instId) {
    $myPaStmt = $db->prepare(
        "SELECT ar.id, ar.entity_type, ar.entity_id, ar.created_at,
                mp.amount, mp.payment_date, mp.payment_mode,
                ms.plan_name, m.first_name, m.last_name, m.id AS member_id
         FROM approval_requests ar
         LEFT JOIN membership_payments mp
               ON mp.id = ar.entity_id AND ar.entity_type = 'membership_payment'
         LEFT JOIN memberships ms ON ms.id = mp.membership_id
         LEFT JOIN members m ON m.id = ms.member_id
         WHERE ar.institution_id = ? AND ar.requested_by = ? AND ar.status = 'pending'
         ORDER BY ar.created_at DESC LIMIT 5"
    );
    $myPaStmt->execute([$instId, authId()]);
    $myPendingApprovals = $myPaStmt->fetchAll();
}

// Recent conversations for this institution
$recentConvs = $instId ? getConversations($instId, 5) : [];

// Expiring soon members (non-school only)
$expMembers = [];
if ($instId && !$isSchool) {
    $emStmt = $db->prepare(
        "SELECT m.first_name, m.last_name, m.mobile, m.member_code, m.id AS member_id,
                ms.end_date, ms.plan_name
         FROM memberships ms
         JOIN members m ON m.id = ms.member_id
         WHERE ms.institution_id = ?
           AND ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
           AND m.is_active = 1
         ORDER BY ms.end_date ASC
         LIMIT 8"
    );
    $emStmt->execute([$instId]);
    $expMembers = $emStmt->fetchAll();
}

// Recent members / students
$recentMembers = [];
if ($instId) {
    if ($isSchool) {
        $rmStmt = $db->prepare(
            "SELECT s.id, s.first_name, s.last_name, s.passport_photo,
                    s.admission_number AS member_code, s.is_active,
                    cls.name AS class_name, dv.name AS division_name
             FROM students s
             LEFT JOIN sections sec ON sec.id = s.section_id
             LEFT JOIN classes cls ON cls.id = sec.class_id
             LEFT JOIN divisions dv ON dv.id = sec.division_id
             WHERE s.institution_id = ? AND s.is_active = 1
             ORDER BY s.created_at DESC LIMIT 6"
        );
    } else {
        $rmStmt = $db->prepare(
            "SELECT m.*, ms.end_date, ms.payment_status
             FROM members m
             LEFT JOIN memberships ms ON ms.id = (
                 SELECT id FROM memberships WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1
             )
             WHERE m.institution_id = ? AND m.is_active = 1
             ORDER BY m.created_at DESC LIMIT 6"
        );
    }
    $rmStmt->execute([$instId]);
    $recentMembers = $rmStmt->fetchAll();
}

// Upcoming holidays & events (next 45 days)
$upcomingHolidays = [];
if ($instId) {
    $uhStmt = $db->prepare(
        "SELECT *, DATEDIFF(holiday_date, CURDATE()) AS days_away
         FROM holiday_calendar
         WHERE institution_id = ? AND is_active = 1
           AND holiday_date >= CURDATE()
           AND holiday_date <= DATE_ADD(CURDATE(), INTERVAL 45 DAY)
         ORDER BY holiday_date ASC LIMIT 12"
    );
    $uhStmt->execute([$instId]);
    $upcomingHolidays = $uhStmt->fetchAll();
}

$pageTitle = 'Staff Dashboard';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Welcome Bar -->
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-4">
  <div>
    <h5 class="fw-bold mb-0">Welcome, <?= h(authName()) ?>!</h5>
    <p class="text-muted small mb-0"><?= h($inst['institution_name'] ?? 'Your Institution') ?></p>
  </div>
  <?php if ($inst && $inst['status'] === 'active'): ?>
  <a href="<?= h(BASE_URL . ($isSchool ? '/app/services/students-add' : '/app/members/add')) ?>"
     class="btn btn-primary">
    <i class="bi bi-plus-circle me-2"></i>Add <?= memberLabel(false) ?>
  </a>
  <?php endif; ?>
</div>

<!-- Stats -->
<div class="row g-4 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card primary">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)"><i class="bi bi-people-fill"></i></div>
      <div class="stat-value"><?= $totalMembers ?></div>
      <div class="stat-label mt-1">Total <?= memberLabel() ?></div>
      <i class="bi bi-people-fill stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card success">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)">
        <i class="bi <?= $isSchool ? 'bi-mortarboard-fill' : 'bi-card-checklist' ?>"></i>
      </div>
      <div class="stat-value"><?= $activeMs ?></div>
      <div class="stat-label mt-1"><?= $isSchool ? 'Active Students' : 'Active Memberships' ?></div>
      <i class="bi <?= $isSchool ? 'bi-mortarboard-fill' : 'bi-card-checklist' ?> stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card warning">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)">
        <i class="bi <?= $isSchool ? 'bi-cash-stack' : 'bi-clock-history' ?>"></i>
      </div>
      <div class="stat-value"><?= $expSoon ?></div>
      <div class="stat-label mt-1"><?= $isSchool ? 'Fee Dues' : 'Expiring (30 Days)' ?></div>
      <i class="bi <?= $isSchool ? 'bi-cash-stack' : 'bi-clock-history' ?> stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card purple">
      <div class="stat-icon mb-2" style="background:rgba(255,255,255,.2)"><i class="bi bi-person-plus-fill"></i></div>
      <div class="stat-value"><?= $newToday ?></div>
      <div class="stat-label mt-1">New Today</div>
      <i class="bi bi-person-plus-fill stat-bg"></i>
    </div>
  </div>
</div>

<?php if ($upcomingHolidays): ?>
<!-- Upcoming Holidays & Events -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-calendar-event-fill me-2 text-danger"></i>Upcoming Holidays &amp; Events
      <span class="badge bg-danger ms-1"><?= count($upcomingHolidays) ?></span>
    </span>
  </div>
  <div class="card-body py-3">
    <div class="d-flex flex-wrap gap-3">
      <?php foreach ($upcomingHolidays as $uh):
        $daysAway = (int)$uh['days_away'];
        $dayLabel = match(true) {
            $daysAway === 0 => 'Today',
            $daysAway === 1 => 'Tomorrow',
            default         => 'In ' . $daysAway . ' day' . ($daysAway > 1 ? 's' : ''),
        };
        $catColor = match($uh['category']) {
            'public_holiday'      => 'danger',
            'special_day'         => 'info',
            'institution_holiday' => 'primary',
            default               => 'warning',
        };
        $typeColor = ($uh['type'] === 'holiday') ? 'danger' : 'success';
      ?>
      <div class="d-flex align-items-center gap-2 px-3 py-2 rounded border"
           style="min-width:200px;max-width:260px;">
        <div class="text-center flex-shrink-0" style="min-width:38px;">
          <div class="fw-bold fs-5 lh-1" style="color:var(--bs-<?= $catColor ?>);">
            <?= date('d', strtotime($uh['holiday_date'])) ?>
          </div>
          <div class="text-muted" style="font-size:.72rem;text-transform:uppercase;">
            <?= date('M', strtotime($uh['holiday_date'])) ?>
          </div>
        </div>
        <div class="flex-grow-1 min-w-0">
          <div class="fw-600 small text-truncate" title="<?= h($uh['name']) ?>">
            <?= h($uh['name']) ?>
          </div>
          <div class="d-flex gap-1 flex-wrap mt-1">
            <span class="badge bg-<?= $catColor ?> bg-opacity-10 text-<?= $catColor ?>"
                  style="font-size:.65rem;">
              <?= match($uh['category']) {
                  'public_holiday'      => 'Public Holiday',
                  'special_day'         => 'Special Day',
                  'institution_holiday' => 'Institution',
                  default               => 'Event',
              } ?>
            </span>
            <?php if ($daysAway <= 7): ?>
            <span class="badge bg-<?= $typeColor ?>" style="font-size:.65rem;">
              <?= $dayLabel ?>
            </span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-4">
  <!-- Expiring Soon / Fee Dues -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <?php if ($isSchool): ?>
        <span><i class="bi bi-cash-stack me-2 text-warning"></i>Fee Dues</span>
        <?php else: ?>
        <span><i class="bi bi-calendar-x me-2 text-warning"></i>Expiring Memberships</span>
        <a href="<?= h(BASE_URL . '/app/members/list?filter=expiring') ?>"
           class="btn btn-sm btn-outline-warning">View All</a>
        <?php endif; ?>
      </div>
      <div class="card-body p-0">
        <?php if ($isSchool): ?>
        <div class="empty-state py-4">
          <i class="bi bi-cash-stack"></i>
          <h6>Fee module coming soon</h6>
          <p class="small">Student fee tracking will be available here.</p>
        </div>
        <?php elseif ($expMembers): ?>
        <div class="table-responsive">
          <table class="table">
            <thead><tr><th><?= memberLabel(false) ?></th><th>Plan</th><th>Expires</th></tr></thead>
            <tbody>
              <?php foreach ($expMembers as $em): ?>
              <tr>
                <td>
                  <a href="<?= h(BASE_URL . '/app/members/view?id=' . $em['member_id']) ?>" class="text-decoration-none">
                    <div class="fw-600 small"><?= h($em['first_name'] . ' ' . $em['last_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><?= h($em['mobile']) ?></div>
                  </a>
                </td>
                <td class="small"><?= h($em['plan_name']) ?></td>
                <td><?= membershipStatusBadge($em['end_date']) ?><div class="text-muted" style="font-size:.72rem;"><?= fmtDate($em['end_date']) ?></div></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-check2-all"></i>
          <h6>No memberships expiring soon</h6>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Members / Students -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lines-fill me-2 text-primary"></i><?= $isSchool ? 'Recent Students' : 'Recent Members' ?></span>
        <a href="<?= h(BASE_URL . ($isSchool ? '/app/services/students' : '/app/members/list')) ?>"
           class="btn btn-sm btn-outline-primary">All <?= memberLabel() ?></a>
      </div>
      <div class="card-body p-0">
        <?php if ($recentMembers): ?>
        <div class="table-responsive">
          <table class="table">
            <thead>
              <tr>
                <th><?= memberLabel(false) ?></th>
                <?php if ($isSchool): ?>
                <th>Admission No</th><th>Section</th>
                <?php else: ?>
                <th>Code</th><th>Membership</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentMembers as $m): ?>
              <tr>
                <td>
                  <?php if ($isSchool): ?>
                  <div class="fw-600 small"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></div>
                  <?php else: ?>
                  <a href="<?= h(BASE_URL . '/app/members/view?id=' . $m['id']) ?>" class="text-decoration-none">
                    <div class="fw-600 small"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></div>
                    <div class="text-muted" style="font-size:.72rem;"><?= h($m['sport_category'] ?? '—') ?></div>
                  </a>
                  <?php endif; ?>
                </td>
                <?php if ($isSchool): ?>
                <td class="small text-muted"><?= h($m['member_code'] ?? '—') ?></td>
                <td class="small"><?= h(trim(($m['class_name'] ?? '') . ' ' . ($m['division_name'] ?? '')) ?: '—') ?></td>
                <?php else: ?>
                <td class="small text-muted"><?= h($m['member_code']) ?></td>
                <td>
                  <?php if ($m['end_date']): ?>
                    <?= membershipStatusBadge($m['end_date']) ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">—</span>
                  <?php endif; ?>
                </td>
                <?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-people"></i>
          <h6>No <?= strtolower(memberLabel()) ?> yet</h6>
          <a href="<?= h(BASE_URL . ($isSchool ? '/app/services/students-add' : '/app/members/add')) ?>"
             class="btn btn-primary btn-sm mt-2">Add <?= memberLabel(false) ?></a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($inst && $inst['status'] === 'active'): ?>
<div class="row g-4 mt-0">

  <!-- My Pending Approvals Widget -->
  <?php if ($myPendingApprovals): ?>
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-hourglass-split me-2 text-warning"></i>My Pending Approvals
          <span class="badge bg-warning text-dark ms-1"><?= count($myPendingApprovals) ?></span>
        </span>
        <a href="<?= h(BASE_URL . '/app/approval') ?>" class="btn btn-sm btn-outline-secondary">View All</a>
      </div>
      <div class="card-body p-0">
        <ul class="list-group list-group-flush">
          <?php foreach ($myPendingApprovals as $ap): ?>
          <li class="list-group-item px-3 py-2 small">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="flex-grow-1">
                <div class="fw-600">
                  <?php if ($ap['first_name']): ?>
                    <?= h($ap['first_name'] . ' ' . $ap['last_name']) ?>
                    <span class="text-muted fw-normal">— <?= h($ap['plan_name'] ?? '—') ?></span>
                  <?php else: ?>
                    <?= h(ucfirst(str_replace('_', ' ', $ap['entity_type']))) ?>
                  <?php endif; ?>
                </div>
                <?php if ($ap['amount']): ?>
                <div class="text-muted">
                  ₹<?= number_format($ap['amount'], 2) ?>
                  · <?= fmtDate($ap['payment_date'], 'd M Y') ?>
                </div>
                <?php endif; ?>
                <div class="text-muted" style="font-size:.7rem;">
                  Submitted <?= fmtDate($ap['created_at'], 'd M Y') ?>
                </div>
              </div>
              <span class="badge bg-warning text-dark flex-shrink-0">Pending</span>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Recent Conversations Widget -->
  <div class="col-lg-<?= $myPendingApprovals ? '6' : '12' ?>">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-chat-dots me-2 text-primary"></i>Recent Messages</span>
        <a href="<?= h(BASE_URL . '/app/messages') ?>" class="btn btn-sm btn-outline-primary">All Messages</a>
      </div>
      <div class="card-body p-0">
        <?php if ($recentConvs): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($recentConvs as $cv): ?>
          <li class="list-group-item px-3 py-2">
            <a href="<?= h(BASE_URL . '/app/messages/conversation?id=' . $cv['id']) ?>"
               class="text-decoration-none d-flex align-items-start gap-2">
              <div class="avatar-circle flex-shrink-0" style="width:32px;height:32px;font-size:.75rem;border-radius:8px;">
                <?= mb_strtoupper(mb_substr($cv['first_name'], 0, 1)) ?>
              </div>
              <div class="flex-grow-1 min-w-0 small">
                <div class="fw-600 text-truncate"><?= h($cv['first_name'] . ' ' . $cv['last_name']) ?></div>
                <div class="text-muted text-truncate" style="font-size:.8rem;">
                  <?= h(mb_strimwidth($cv['last_body'] ?? '(no messages)', 0, 70, '…')) ?>
                </div>
                <div class="text-muted" style="font-size:.7rem;">
                  <?= $cv['last_msg_time'] ? fmtDate($cv['last_msg_time'], 'd M, H:i') : fmtDate($cv['created_at'], 'd M') ?>
                </div>
              </div>
            </a>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-chat-square"></i>
          <h6>No conversations yet</h6>
          <a href="<?= h(BASE_URL . '/app/messages') ?>" class="btn btn-sm btn-primary mt-2">
            Start a Conversation
          </a>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>
<?php endif; // active institution ?>

<?php require_once APP_ROOT . '/includes/footer.php'; ?>
