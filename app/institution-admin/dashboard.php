<?php
require_once dirname(__DIR__) . '/bootstrap.php';
requireRole('institution_admin');

$db     = getDB();
$instId = authInstId();

$stmt = $db->prepare("SELECT * FROM institutions WHERE id = ?");
$stmt->execute([$instId]);
$inst = $stmt->fetch();

if (!$inst) { setFlash('error', 'Institution not found.'); logoutUser(); header('Location: ' . BASE_URL . '/app/auth/login'); exit; }

$category = getInstitutionCategory($inst['institution_type'] ?? '');
$isSchool  = ($category === 'school');

// Stats
$staffCount  = (int)$db->prepare("SELECT COUNT(*) FROM staff WHERE institution_id = ? AND is_active = 1")->execute([$instId]) ? 0 : 0;
$scStmt = $db->prepare("SELECT COUNT(*) FROM staff WHERE institution_id = ? AND is_active = 1");
$scStmt->execute([$instId]);
$staffCount = (int)$scStmt->fetchColumn();

$mcStmt = $db->prepare($isSchool
    ? "SELECT COUNT(*) FROM students WHERE institution_id = ?"
    : "SELECT COUNT(*) FROM members WHERE institution_id = ? AND is_active = 1");
$mcStmt->execute([$instId]);
$memberCount = (int)$mcStmt->fetchColumn();

$activeMship  = 0;
$expiringSoon = 0;
if ($inst['status'] === 'active') {
    if ($isSchool) {
        $asStmt = $db->prepare("SELECT COUNT(*) FROM students WHERE institution_id = ? AND is_active = 1");
        $asStmt->execute([$instId]);
        $activeMship = (int)$asStmt->fetchColumn();
        // $expiringSoon = fee dues — placeholder until fee module is built
    } else {
        $msStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms JOIN members m ON m.id = ms.member_id
                                 WHERE ms.institution_id = ? AND ms.end_date >= CURDATE()");
        $msStmt->execute([$instId]);
        $activeMship = (int)$msStmt->fetchColumn();

        $esStmt = $db->prepare("SELECT COUNT(*) FROM memberships ms JOIN members m ON m.id = ms.member_id
                                 WHERE ms.institution_id = ? AND ms.end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        $esStmt->execute([$instId]);
        $expiringSoon = (int)$esStmt->fetchColumn();
    }
}

// Pending approvals widget
$pendingApprovals = [];
$pendingCount     = 0;
if ($inst['status'] === 'active') {
    $pendingCount  = getPendingApprovalsCount($instId);
    $paStmt = $db->prepare(
        "SELECT ar.id, ar.entity_type, ar.entity_id, ar.created_at,
                u.full_name AS requester_name,
                mp.amount, mp.payment_date, mp.payment_mode,
                ms.plan_name, m.first_name, m.last_name, m.id AS member_id
         FROM approval_requests ar
         JOIN users u ON u.id = ar.requested_by
         LEFT JOIN membership_payments mp
               ON mp.id = ar.entity_id AND ar.entity_type = 'membership_payment'
         LEFT JOIN memberships ms ON ms.id = mp.membership_id
         LEFT JOIN members m ON m.id = ms.member_id
         WHERE ar.institution_id = ? AND ar.status = 'pending'
         ORDER BY ar.created_at DESC LIMIT 5"
    );
    $paStmt->execute([$instId]);
    $pendingApprovals = $paStmt->fetchAll();
}

// Recent conversations widget
$recentConvs = [];
if ($inst['status'] === 'active') {
    $recentConvs = getConversations($instId, 5);
}

// Recent members / students
$recentMembers = [];
if ($inst['status'] === 'active') {
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
             ORDER BY s.created_at DESC LIMIT 5"
        );
    } else {
        $rmStmt = $db->prepare("SELECT m.*, ms.end_date, ms.payment_status
                                 FROM members m
                                 LEFT JOIN memberships ms ON ms.id = (SELECT id FROM memberships WHERE member_id = m.id ORDER BY created_at DESC LIMIT 1)
                                 WHERE m.institution_id = ? AND m.is_active = 1
                                 ORDER BY m.created_at DESC LIMIT 5");
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

// Recent announcements (pinned first, then latest 5)
$recentAnnouncements = [];
if ($inst['status'] === 'active') {
    $annStmt = $db->prepare(
        "SELECT a.id, a.title, a.type, a.audience, a.is_pinned, a.published_at,
                u.full_name AS author
         FROM announcements a
         LEFT JOIN users u ON u.id = a.created_by
         WHERE a.institution_id = ? AND a.is_active = 1
           AND (a.expires_at IS NULL OR a.expires_at >= CURDATE())
         ORDER BY a.is_pinned DESC, a.published_at DESC
         LIMIT 5"
    );
    $annStmt->execute([$instId]);
    $recentAnnouncements = $annStmt->fetchAll();
}

$pageTitle = 'Dashboard';
require_once APP_ROOT . '/includes/header.php';
?>

<!-- Pending Profile Warning -->
<?php if ($inst['status'] === 'pending_profile'): ?>
<div class="approval-banner mb-4">
  <i class="bi bi-building-exclamation"></i>
  <div>
    <h6 class="fw-bold mb-1">Complete Your Institution Profile</h6>
    <p class="mb-2 small opacity-90">Please complete your institution profile (logo, type, registration details) to submit for Super Admin approval.</p>
    <a href="<?= h(BASE_URL . '/app/institution-admin/profile') ?>" class="btn btn-light btn-sm">
      Complete Profile →
    </a>
  </div>
</div>

<?php elseif ($inst['status'] === 'pending_approval'): ?>
<div class="alert alert-info d-flex align-items-center gap-3 mb-4">
  <i class="bi bi-hourglass-split fs-4 flex-shrink-0"></i>
  <div>
    <strong>Awaiting Super Admin Approval</strong><br>
    <span class="small">Your profile has been submitted. The platform administrator will review and approve your institution shortly.</span>
  </div>
</div>

<?php elseif ($inst['status'] === 'suspended'): ?>
<div class="alert alert-danger d-flex align-items-center gap-3 mb-4">
  <i class="bi bi-slash-circle fs-4 flex-shrink-0"></i>
  <div>
    <strong>Institution Suspended</strong><br>
    <span class="small">Your institution has been suspended. Please contact the platform administrator.</span>
  </div>
</div>
<?php endif; ?>

<!-- Stats Row -->
<div class="row g-4 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card primary">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)"><i class="bi bi-people-fill"></i></div>
      </div>
      <div class="stat-value"><?= $memberCount ?></div>
      <div class="stat-label mt-1">Total <?= memberLabel() ?></div>
      <i class="bi bi-people-fill stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card success">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)">
          <i class="bi <?= $isSchool ? 'bi-mortarboard-fill' : 'bi-card-checklist' ?>"></i>
        </div>
      </div>
      <div class="stat-value"><?= $activeMship ?></div>
      <div class="stat-label mt-1"><?= $isSchool ? 'Active Students' : 'Active Memberships' ?></div>
      <i class="bi <?= $isSchool ? 'bi-mortarboard-fill' : 'bi-card-checklist' ?> stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card warning">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)">
          <i class="bi <?= $isSchool ? 'bi-cash-stack' : 'bi-calendar-x' ?>"></i>
        </div>
      </div>
      <div class="stat-value"><?= $expiringSoon ?></div>
      <div class="stat-label mt-1"><?= $isSchool ? 'Fee Dues' : 'Expiring in 30 Days' ?></div>
      <i class="bi <?= $isSchool ? 'bi-cash-stack' : 'bi-calendar-x' ?> stat-bg"></i>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card purple">
      <div class="d-flex align-items-start justify-content-between mb-3">
        <div class="stat-icon" style="background:rgba(255,255,255,.2)"><i class="bi bi-person-badge-fill"></i></div>
      </div>
      <div class="stat-value"><?= $staffCount ?></div>
      <div class="stat-label mt-1">Staff Members</div>
      <i class="bi bi-person-badge-fill stat-bg"></i>
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
    <a href="<?= h(BASE_URL . '/app/settings/holidays') ?>"
       class="btn btn-sm btn-outline-secondary">Manage</a>
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

<?php if ($recentAnnouncements): ?>
<!-- Recent Announcements -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span><i class="bi bi-megaphone-fill me-2 text-primary"></i>Announcements
      <span class="badge bg-secondary ms-1"><?= count($recentAnnouncements) ?></span>
    </span>
    <a href="<?= h(BASE_URL . '/app/institution-admin/announcements') ?>"
       class="btn btn-sm btn-outline-secondary">Manage</a>
  </div>
  <div class="card-body p-0">
    <ul class="list-group list-group-flush">
      <?php foreach ($recentAnnouncements as $ann):
        $typeBadge = match($ann['type']) {
            'notice'   => 'warning text-dark',
            'circular' => 'info text-dark',
            'event'    => 'success',
            default    => 'primary',
        };
      ?>
      <li class="list-group-item py-2 <?= $ann['is_pinned'] ? 'border-start border-warning border-3' : '' ?>">
        <div class="d-flex align-items-start gap-2">
          <?php if ($ann['is_pinned']): ?>
          <i class="bi bi-pin-angle-fill text-warning mt-1 flex-shrink-0"></i>
          <?php endif; ?>
          <div class="flex-grow-1 min-w-0">
            <div class="d-flex align-items-center gap-1 flex-wrap mb-1">
              <span class="badge bg-<?= $typeBadge ?>" style="font-size:.65rem;">
                <?= ucfirst($ann['type']) ?>
              </span>
              <span class="fw-600 small text-truncate"><?= h($ann['title']) ?></span>
            </div>
            <div class="text-muted" style="font-size:.72rem;">
              <?= h(fmtDate($ann['published_at'])) ?>
              <?php if ($ann['author']): ?>
              &middot; <?= h($ann['author']) ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <div class="card-footer py-2">
    <a href="<?= h(BASE_URL . '/app/services/announcements') ?>"
       class="btn btn-sm btn-outline-primary">
      <i class="bi bi-list me-1"></i>View All Announcements
    </a>
  </div>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">

  <!-- Institution Info Card -->
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-building me-2 text-primary"></i>Institution</span>
        <a href="<?= h(BASE_URL . '/app/institution-admin/profile') ?>" class="btn btn-sm btn-outline-primary">
          <i class="bi bi-pencil me-1"></i>Edit
        </a>
      </div>
      <div class="card-body small">
        <div class="d-flex align-items-center gap-3 mb-3">
          <?php if ($inst['logo']): ?>
          <img src="<?= h(LOGO_URL . '/' . $inst['logo']) ?>" alt="Logo" style="width:56px;height:56px;object-fit:contain;border-radius:8px;background:#f3f4f6;padding:4px;">
          <?php else: ?>
          <div style="width:56px;height:56px;border-radius:8px;background:linear-gradient(135deg,#0b5ed7,#6f42c1);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#fff;">
            <?= mb_strtoupper(mb_substr($inst['institution_name'], 0, 1)) ?>
          </div>
          <?php endif; ?>
          <div>
            <div class="fw-bold"><?= h($inst['institution_name']) ?></div>
            <div class="text-muted"><?= h(institutionTypeLabel($inst['institution_type'])) ?></div>
          </div>
        </div>
        <?php if ($inst['city']): ?>
        <div class="mb-1"><i class="bi bi-geo-alt me-2 text-muted"></i><?= h($inst['city']) ?>, <?= h($inst['state'] ?? '') ?></div>
        <?php endif; ?>
        <?php if ($inst['contact_phone']): ?>
        <div class="mb-1"><i class="bi bi-telephone me-2 text-muted"></i><?= h($inst['contact_phone']) ?></div>
        <?php endif; ?>
        <?php if ($inst['valid_until']): ?>
        <div class="mt-2 pt-2 border-top">
          <small class="text-muted">Valid until:</small>
          <span class="ms-1 fw-600"><?= fmtDate($inst['valid_until']) ?></span>
          <?= membershipStatusBadge($inst['valid_until']) ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Members -->
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-people me-2 text-primary"></i><?= $isSchool ? 'Recent Students' : 'Recent Members' ?></span>
        <?php if ($inst['status'] === 'active'): ?>
        <div class="d-flex gap-2">
          <a href="<?= h(BASE_URL . ($isSchool ? '/app/services/students-add' : '/app/members/add')) ?>"
             class="btn btn-sm btn-primary">
            <i class="bi bi-plus me-1"></i><?= $isSchool ? 'Add Student' : 'Add Member' ?>
          </a>
          <a href="<?= h(BASE_URL . ($isSchool ? '/app/services/students' : '/app/members/list')) ?>"
             class="btn btn-sm btn-outline-primary">All</a>
        </div>
        <?php endif; ?>
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
                <th>Code</th><th>Sport</th><th>Membership</th>
                <?php endif; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recentMembers as $m): ?>
              <tr>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <?php if ($m['passport_photo']): ?>
                    <img src="<?= h(PHOTO_URL . '/' . $m['passport_photo']) ?>" alt=""
                         style="width:30px;height:36px;object-fit:cover;border-radius:4px;">
                    <?php else: ?>
                    <div class="avatar-circle" style="width:30px;height:30px;font-size:.72rem;border-radius:6px;">
                      <?= mb_strtoupper(mb_substr($m['first_name'], 0, 1)) ?>
                    </div>
                    <?php endif; ?>
                    <div class="fw-600 small"><?= h($m['first_name'] . ' ' . $m['last_name']) ?></div>
                  </div>
                </td>
                <?php if ($isSchool): ?>
                <td class="small text-muted"><?= h($m['member_code'] ?? '—') ?></td>
                <td class="small"><?= h(trim(($m['class_name'] ?? '') . ' ' . ($m['division_name'] ?? '')) ?: '—') ?></td>
                <?php else: ?>
                <td class="small text-muted"><?= h($m['member_code']) ?></td>
                <td class="small"><?= h($m['sport_category'] ?? '—') ?></td>
                <td>
                  <?php if ($m['end_date']): ?>
                    <?= membershipStatusBadge($m['end_date']) ?>
                  <?php else: ?>
                    <span class="badge bg-secondary">No membership</span>
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
          <h6><?= $inst['status'] === 'active' ? ('No ' . strtolower(memberLabel()) . ' yet') : 'Awaiting activation' ?></h6>
          <p class="small"><?= $inst['status'] === 'active' ? ('Start adding ' . strtolower(memberLabel()) . ' to your institution.') : 'Complete your profile to get approved.' ?></p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div>

<?php if ($inst['status'] === 'active'): ?>
<div class="row g-4 mt-0">

  <!-- Pending Approvals Widget -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>
          <i class="bi bi-hourglass-split me-2 text-warning"></i>Pending Approvals
          <?php if ($pendingCount > 0): ?>
          <span class="badge bg-warning text-dark ms-1"><?= $pendingCount ?></span>
          <?php endif; ?>
        </span>
        <a href="<?= h(BASE_URL . '/app/approval') ?>" class="btn btn-sm btn-outline-warning">View All</a>
      </div>
      <div class="card-body p-0">
        <?php if ($pendingApprovals): ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($pendingApprovals as $ap): ?>
          <li class="list-group-item px-3 py-2">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <div class="flex-grow-1 small">
                <div class="fw-600">
                  <?php if ($ap['first_name']): ?>
                    <a href="<?= h(BASE_URL . '/app/members/view?id=' . $ap['member_id']) ?>" class="text-decoration-none">
                      <?= h($ap['first_name'] . ' ' . $ap['last_name']) ?>
                    </a>
                    &nbsp;<span class="text-muted fw-normal">— <?= h($ap['plan_name'] ?? '—') ?></span>
                  <?php else: ?>
                    <?= h(ucfirst(str_replace('_', ' ', $ap['entity_type']))) ?>
                  <?php endif; ?>
                </div>
                <?php if ($ap['amount']): ?>
                <div class="text-muted">
                  ₹<?= number_format($ap['amount'], 2) ?>
                  · <?= h(ucfirst(str_replace('_', ' ', $ap['payment_mode'] ?? ''))) ?>
                  · <?= fmtDate($ap['payment_date'], 'd M Y') ?>
                </div>
                <?php endif; ?>
                <div class="text-muted" style="font-size:.72rem;">
                  Submitted by <?= h($ap['requester_name']) ?> · <?= fmtDate($ap['created_at'], 'd M Y') ?>
                </div>
              </div>
              <a href="<?= h(BASE_URL . '/app/approval/review?id=' . $ap['id']) ?>"
                 class="btn btn-xs btn-warning flex-shrink-0" style="font-size:.75rem;padding:3px 10px;">
                Review
              </a>
            </div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php if ($pendingCount > count($pendingApprovals)): ?>
        <div class="text-center py-2 border-top">
          <a href="<?= h(BASE_URL . '/app/approval') ?>" class="small text-primary">
            +<?= $pendingCount - count($pendingApprovals) ?> more pending
          </a>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <div class="empty-state py-4">
          <i class="bi bi-check2-all"></i>
          <h6>All caught up!</h6>
          <p class="small">No pending approvals.</p>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Recent Conversations Widget -->
  <div class="col-lg-6">
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
                  <?= h(mb_strimwidth($cv['last_body'] ?? '(no messages)', 0, 60, '…')) ?>
                </div>
                <div class="text-muted" style="font-size:.7rem;">
                  <?= $cv['last_msg_time'] ? fmtDate($cv['last_msg_time'], 'd M, H:i') : fmtDate($cv['created_at'], 'd M') ?>
                  <?php if ($cv['is_locked']): ?>
                  <span class="badge bg-secondary ms-1" style="font-size:.6rem;">Locked</span>
                  <?php endif; ?>
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
