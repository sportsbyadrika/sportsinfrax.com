<?php
/**
 * SportsInfraX – Notification Queue Processor
 *
 * Set up in cPanel → Cron Jobs:
 *   Command : php /home/<cpanel_user>/public_html/app.sportsinfrax.com/app/cron/send_notifications.php
 *   Schedule: every 5 minutes  → * /5 * * * *
 *
 * Processes up to 50 pending notification_queue rows per run.
 * Email is sent via the existing SMTP helper (sendMail).
 * SMS and WhatsApp are stubs – replace _cronSendSms / _cronSendWhatsapp
 * with your API integration when ready.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }

define('APP_ROOT', dirname(__DIR__));
require_once APP_ROOT . '/bootstrap.php';

$db      = getDB();
$maxJobs = 50;
$maxTry  = 3;

$stmt = $db->prepare(
    "SELECT * FROM notification_queue
     WHERE status = 'pending' AND attempts < ? AND scheduled_at <= NOW()
     ORDER BY scheduled_at ASC
     LIMIT ?"
);
$stmt->execute([$maxTry, $maxJobs]);
$jobs = $stmt->fetchAll();

$sent = $failed = $skipped = 0;

foreach ($jobs as $job) {
    // Increment attempts first to prevent re-processing on timeout
    $db->prepare(
        "UPDATE notification_queue SET attempts = attempts + 1 WHERE id = ?"
    )->execute([$job['id']]);

    try {
        $ok = match($job['channel']) {
            'email'     => _cronSendEmail($job),
            'sms'       => _cronSendSms($job),
            'whatsapp'  => _cronSendWhatsapp($job),
            default     => false,
        };
    } catch (Throwable $e) {
        $db->prepare(
            "UPDATE notification_queue SET status = 'failed', last_error = ? WHERE id = ?"
        )->execute([$e->getMessage(), $job['id']]);
        $failed++;
        continue;
    }

    if ($ok) {
        $db->prepare(
            "UPDATE notification_queue SET status = 'sent', sent_at = NOW() WHERE id = ?"
        )->execute([$job['id']]);
        $sent++;
    } else {
        $attempts = (int)$job['attempts'] + 1;
        if ($attempts >= $maxTry) {
            $db->prepare(
                "UPDATE notification_queue SET status = 'failed', last_error = 'Max attempts reached' WHERE id = ?"
            )->execute([$job['id']]);
            $failed++;
        } else {
            $skipped++;
        }
    }
}

$total = count($jobs);
echo date('Y-m-d H:i:s')
   . " | Jobs: {$total} | Sent: {$sent} | Failed: {$failed} | Retry later: {$skipped}\n";

// ── Channel implementations ────────────────────────────────

function _cronSendEmail(array $job): bool
{
    $safeBody = nl2br(htmlspecialchars($job['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $htmlBody = <<<HTML
<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="font-family:Segoe UI,Arial,sans-serif;color:#1f2937;background:#f8fbff;padding:24px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 2px 12px rgba(0,0,0,.07);">
    <div style="text-align:center;margin-bottom:20px;">
      <h3 style="color:#0b1f3a;margin:0;">SportsInfraX</h3>
    </div>
    <p style="font-size:14px;line-height:1.6;">{$safeBody}</p>
    <hr style="border:none;border-top:1px solid #e5e7eb;margin:20px 0;">
    <p style="font-size:11px;color:#9ca3af;text-align:center;">
      &copy; SportsInfraX &middot; SportsByA Tech (OPC) Private Limited
    </p>
  </div>
</body></html>
HTML;
    return sendMail(
        $job['recipient'],
        $job['subject'] ?? APP_NAME . ' Notification',
        $htmlBody
    );
}

function _cronSendSms(array $job): bool
{
    // Plug-in stub: integrate your SMS gateway (e.g. MSG91, Twilio, TextLocal)
    // Example MSG91: POST https://api.msg91.com/api/v5/flow with your template
    error_log("SportsInfraX SMS stub – to: {$job['recipient']}");
    return false;
}

function _cronSendWhatsapp(array $job): bool
{
    // Plug-in stub: integrate your WhatsApp Business API or 360dialog/Twilio
    error_log("SportsInfraX WhatsApp stub – to: {$job['recipient']}");
    return false;
}
