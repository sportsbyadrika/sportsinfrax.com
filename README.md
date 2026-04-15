# SportsInfraX

**Digital OS for Sports Institutions, Facilities and Revenue Operations**  
By SportsByA Tech (OPC) Private Limited

---

## Membership & Training Management Module

A PHP/MySQL SaaS web application for managing sports institutions, members, staff, and memberships.

---

## Tech Stack

| Layer    | Technology                                |
|----------|-------------------------------------------|
| Backend  | PHP 8.1+                                  |
| Database | MySQL 8.0+                                |
| Frontend | Bootstrap 5.3.3 + Bootstrap Icons 1.11.3  |
| Auth     | Session-based with CSRF protection        |
| Uploads  | Local filesystem (MIME-type validated)    |

---

## Directory Structure

```
sportsinfrax.com/
├── index.html                  # Landing page
├── database/
│   ├── schema.sql              # Full DB schema + seed
│   └── install.php             # Web-based DB installer
└── app/
    ├── bootstrap.php           # Session + require loader
    ├── index.php               # Smart redirect entry point
    ├── config/
    │   ├── config.php          # App constants & settings
    │   └── database.php        # PDO singleton
    ├── includes/
    │   ├── functions.php       # Helpers (CSRF, flash, upload, mail…)
    │   ├── auth_check.php      # Auth/role guards
    │   ├── header.php          # HTML head + page header
    │   ├── footer.php          # Footer + JS
    │   └── navbar.php          # Role-based top navbar
    ├── assets/
    │   ├── css/app.css         # Custom styles (brand colours, cards, tables)
    │   └── js/app.js           # Custom JavaScript
    ├── auth/
    │   ├── login.php
    │   ├── logout.php
    │   ├── change-password.php
    │   └── forgot-password.php
    ├── register/
    │   └── index.php           # 3-step institution registration flow
    ├── super-admin/
    │   ├── dashboard.php
    │   ├── institutions.php
    │   └── institution-detail.php  # View + Approve / Suspend
    ├── institution-admin/
    │   ├── dashboard.php
    │   ├── profile.php         # Complete institution profile & submit
    │   ├── staff.php           # Staff list
    │   ├── staff-add.php       # Add / Edit staff
    │   └── staff-toggle.php    # Activate / Deactivate staff
    ├── staff/
    │   └── dashboard.php
    ├── members/
    │   ├── index.php           # Member list with search & filters
    │   ├── add.php             # Full member application form
    │   ├── edit.php            # Edit member
    │   ├── view.php            # Member profile + memberships + payments
    │   ├── membership-add.php  # Create / renew membership
    │   └── payment-add.php     # Record payment with proof upload
    └── uploads/
        ├── logos/
        ├── photos/
        ├── payments/
        └── documents/
```

---

## Installation

### 1. Requirements
- PHP 8.1+ with: `pdo_mysql`, `fileinfo`, `gd`
- MySQL 8.0+
- Apache / Nginx

### 2. Database

Configure credentials in `app/config/database.php` or via env vars:

```
DB_HOST=127.0.0.1
DB_NAME=sportsinfrax
DB_USER=root
DB_PASS=yourpassword
```

Import schema:
```bash
mysql -u root -p sportsinfrax < database/schema.sql
```

Or use the web installer:
```
http://yourdomain/database/install.php?token=INSTALL_TOKEN_CHANGE_ME
```
**Delete `install.php` after use.**

### 3. Set Super Admin Password

Generate a secure bcrypt hash:
```bash
php -r "echo password_hash('YourSecurePassword', PASSWORD_BCRYPT, ['cost' => 12]);"
```

Update in MySQL:
```sql
UPDATE users SET password = '<hash>' WHERE email = 'admin@sportsinfrax.com';
```

### 4. Upload Directories (writable)
```bash
chmod 755 app/uploads/logos app/uploads/photos app/uploads/payments app/uploads/documents
```

---

## User Roles & Workflow

### Super Admin
- Reviews institution registrations and profiles
- **Approves** institutions with a validity date
- Can suspend / reactivate institutions

### Institution Admin
1. Registers at `/app/register/index.php` (3-step: Fill → Verify → Confirm)
2. Receives login credentials by email
3. Logs in → completes profile (logo, type, registration details)
4. Submits for Super Admin approval
5. Once approved → manages Staff and Members

### Staff
- Created by Institution Admin; credentials emailed automatically
- Manages Members: add, edit, view profiles, memberships, payments

---

## Key Features

| Feature | Details |
|---------|---------|
| Registration Flow | 3-step with verification screen before creation |
| Email Notifications | On registration, staff creation, approval |
| Role-Based Access | Super Admin / Institution Admin / Staff |
| Member Form | Full application: personal, contact, ID proof, emergency contact, photo |
| Membership | New / Renewal types, auto end-date from duration |
| Payments | Multiple payments per membership, proof upload (image/PDF) |
| Expiry Alerts | Dashboard highlights memberships expiring within 30 days |
| Security | CSRF tokens, PDO prepared statements, MIME validation, XSS-safe output |
| Responsive | Bootstrap 5.3.3, mobile-first horizontal navbar |

---

## Default Login

| Role        | Email                    | Password          |
|-------------|--------------------------|-------------------|
| Super Admin | admin@sportsinfrax.com   | Set during install |

---

## License

© SportsByA Tech (OPC) Private Limited. All rights reserved.
