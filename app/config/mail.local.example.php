<?php
/**
 * SMTP credentials – copy this file to mail.local.php and fill in your values.
 * mail.local.php is gitignored and will never be overwritten by deployments.
 *
 * cPanel / shared hosting:
 *   SMTP_HOST   = mail.yourdomain.com
 *   SMTP_PORT   = 587  (STARTTLS) | 465 (SSL) | 25 (plain)
 *   SMTP_SECURE = tls  (port 587) | ssl (port 465) | '' (port 25)
 *
 * Gmail: SMTP_HOST=smtp.gmail.com, SMTP_PORT=587, SMTP_SECURE=tls
 *        SMTP_PASS = App Password from myaccount.google.com
 */

putenv('MAIL_FROM=noreply@yourdomain.com');
putenv('MAIL_FROM_NAME=SportsInfraX');

putenv('SMTP_HOST=mail.yourdomain.com');
putenv('SMTP_PORT=587');
putenv('SMTP_USER=noreply@yourdomain.com');
putenv('SMTP_PASS=YOUR_EMAIL_PASSWORD_HERE');
putenv('SMTP_SECURE=tls');
