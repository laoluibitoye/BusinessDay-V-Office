# CLAUDE.md — HRI Mail Project Context

> This file gives Claude Code full context of the HRI Mail project.
> Read this entirely before making any changes.
> **KEEP THIS FILE UPDATED** — update it every time a new feature, file, or fix is added.

---

## PROJECT OVERVIEW

**HRI Mail** is a custom-built, PHP-based corporate webmail and HR workflow platform for
**HR Indexx Limited** (HRI), a Nigerian HR firm based at 12 Macarthy Street, Onikan, Lagos Island.

- **Live URL:** https://mail.hrindexx.com
- **Developer:** Olanrewaju (Lanre) Oloritun — Head IT Admin & DPO at HR Indexx Limited
- **Server path:** `/home/hrindexx/mail.hrindexx.com/` (= document root)
- **Stack:** PHP 8.4.21 + MySQL + IMAP (cPanel/LiteSpeed/Dovecot) + PHPMailer + Google Gemini AI
- **DB name:** `hrindexx_hri_webmail`
- **DB user:** `hrindexx_hri_user`
- **Company:** HR Indexx Limited | RC: 446051
- **MD:** Mrs. Ogunlusi
- **NDPC Certificate:** NDPC/DCP/12819 (EHL) — June 2026
- **Email domain:** hrindexx.com
- **Admin email:** ooloritun@hrindexx.com
- **Hosting:** RackNerd shared cPanel hosting

---

## SERVER ENVIRONMENT — CRITICAL QUIRKS

These quirks have caused dozens of bugs. Never forget them.

### 1. LiteSpeed Sends HTTP Headers Before PHP Runs
LiteSpeed's PHP handler commits HTTP response headers at request start — before PHP
line 1 executes. This means:
- `ini_set('session.*', ...)` ALWAYS fails — "cannot be changed after headers sent"
- `session_start()` fails if called after ANY output (even a blank line)
- `ob_start()` alone does NOT prevent this on LiteSpeed

**The fix (mandatory for every page):**
```php
<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
// THEN require config/app.php
```
These two lines must be the VERY FIRST thing in every PHP page — before any require_once.

### 2. CRLF Line Endings Break Everything
When cPanel's zip extractor extracts files, it converts `\n` to `\r\n` (Windows line endings).
On Linux/LiteSpeed, the `\r` character is treated as literal output, which counts as
"headers already sent" and breaks session_start().

**Always write PHP files with LF (`\n`) only. Never `\r\n`.**
**Always deliver files as content to paste in cPanel Code Editor. NOT as zip files.**

When diagnosing 500 errors, check: `bin2hex(substr(file, 0, 8))`.
- `3c3f7068700a` = `<?php\n` = correct (LF)
- `3c3f7068700d0a` = `<?php\r\n` = broken (CRLF)

### 3. LiteSpeed WAF Blocks Certain Filenames
LiteSpeed's built-in WAF returns 403 for files matching these patterns:
- Names starting with: `test`, `debug`, `install`, `temp`, `tmp`, `backup`
- Extensions: `.env`, `.sql`, `.log`, `.sh`, `.bak`, `.conf`, `.ini`, `.md`, `.json`
- The word "template" in filename (e.g. `templates.php` → renamed to `email-tpl.php`)

### 4. Two Layout Class Files — Critical
- `lib/Layout.php` — 206-byte stub that does `require_once 'layout_shell.php'`
- `lib/layout_shell.php` — 51KB full Gmail-style layout, defines `class Layout`

**All pages must require `lib/Layout.php` (the stub), NOT `lib/layout_shell.php` directly.**
If any page requires `layout_shell.php` directly while another has already required `Layout.php`
(or vice versa), PHP throws `Fatal: Cannot redeclare class Layout`.

### 5. output_buffering = Off
The server's `output_buffering` PHP setting is Off. Session ini settings cannot be changed
after PHP starts. All session configuration lives in `.user.ini` (read by LiteSpeed before PHP).

### 6. cURL Available, allow_url_fopen Disabled
- `curl_init()`: YES ✅
- `allow_url_fopen`: NO ❌
All HTTP requests (Gemini API etc.) must use cURL.

### 7. window.CSRF_TOKEN Not var CSRF_TOKEN
The token is declared inside an IIFE in layout_shell.php. Using `var` makes it
inaccessible to external scripts. Must be `window.CSRF_TOKEN = '...'`.

### 8. Email Body Uses srcdoc Not fd.write()
`fd.open(); fd.write()` fails silently on LiteSpeed. Use the HTML5 `srcdoc` attribute:
```php
$srcdocContent = '<!DOCTYPE html><html><head>'.$style.'</head><body>'.$bodyHtml.'</body></html>';
// Then in HTML:
// <iframe srcdoc="<?=htmlspecialchars($srcdocContent, ENT_QUOTES, 'UTF-8')?>"></iframe>
```

### 9. Dashboard Files Are Included, Not Directly Accessed
`dashboard/*.php` files are loaded via `require` from `index.php`. They do NOT call
`Auth::require()` themselves — they only check `if (!isset($user))`. Never access them
directly via URL. `$db`, `$user`, `$mp` are all set by `index.php` before the require.

---

## DATABASE SCHEMA

### Key Table Column Names
**IMPORTANT: These differ from what you might expect.**

```sql
-- users table
id, name, email, password_hash, role, line_manager_id, department,
phone, avatar_color, is_active, last_login, created_at, updated_at
-- NOTE: column is 'name' NOT 'full_name'

-- sessions table
id, user_id, token, ip_address, user_agent, device, location,
created_at, expires_at, last_active

-- leave_flow_rules table (added June 24)
requester_role (PK VARCHAR 50), stage1_approver, stage2_approver, stage3_approver,
updated_by, updated_at
-- Configures the approval chain per requester role. stage1=LM slot, stage2=HR slot, stage3=MD slot.
-- NULL = skip that stage. Edited via admin/leave-flows.php.

-- leave_requests table
id, user_id, leave_type, start_date, end_date, days, reason, cover_staff,
current_stage, lm_status, lm_comment, lm_actioned_by, lm_actioned_at,
hr_status, hr_comment, hr_actioned_by, hr_actioned_at,
md_status, md_comment, md_actioned_by, md_actioned_at, created_at
-- NOTE: status field is 'current_stage', NOT 'status'
-- NOTE: no 'approved_by', 'rejection_reason', or 'updated_at' columns

-- audit_log table
id, user_id, action, detail, ip_address, created_at

-- vault_files table
id, user_id, filename, stored_name, file_path, category, source,
mime_type, created_at

-- announcements table
id, user_id, title, body, priority, target_role, is_active, expires_at, created_at

-- breach_log table
id, user_id, breach_date, discovered_date, description, data_affected,
subjects_affected, severity, ndpc_notified, ndpc_notified_date, ndpc_ref,
subjects_notified, remediation, status, logged_by, created_at

-- email_templates table
id, user_id, name, subject, body, category, is_shared, created_at, updated_at

-- contacts table
id, user_id, name, email, phone, company, group, notes, created_at

-- tasks table (key columns)
-- progress TINYINT UNSIGNED DEFAULT 0  ← added June 28 (0-100%)

-- task_checklists table (added June 28)
id, task_id, item, is_done, created_by, done_by, done_at, created_at

-- email_queue table (for schedule send / undo send)
id, user_id, to_email, cc, bcc, subject, body, attachments, send_at, sent, created_at

-- payslip_batches table (added July 15)
id, batch_ref (PSL-2026-XXXX), uploaded_by, original_file, pay_period, total, sent, failed,
status ENUM('queued','sending','completed','partial','cancelled'), created_at, completed_at

-- payslip_queue table (added July 15)
id, batch_id, row_num, employee_name, employee_email, employee_id, designation, location,
pay_period, pay_day, pfa, pension_pin, days_in_month, days_worked, tax_id,
basic_salary, housing, transportation, other_allowances, hmo_employer, psf_arrears, total_earnings,
hmo_employee, monthly_pension, monthly_tax, additional_hmo, loan_repayment, caution_fee,
total_deductions, net_pay, pdf_path, status ENUM('pending','sending','sent','failed'),
attempts, sent_at, error_msg, created_at

-- user_signatures table (added June 22)
id, user_id, name, html, is_default, created_at, updated_at

-- user_profiles table (extended profile — DOB, NOK, bank details)
id, user_id, date_of_birth, next_of_kin_name, next_of_kin_phone, next_of_kin_relation,
emergency_contact, bank_name, account_number, account_name, created_at, updated_at
```

### Sprint 4 Tables (Created via migration)

```sql
-- Work schedules (4 types)
CREATE TABLE work_schedules (
    id                INT AUTO_INCREMENT PRIMARY KEY,
    name              VARCHAR(100) NOT NULL,
    days_per_week     INT NOT NULL,         -- 2, 3, 4, or 7
    annual_leave_days INT NOT NULL,         -- 21 (everyday) or 15 (all others)
    description       VARCHAR(300),
    created_at        DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Staff schedule + working days assignment
CREATE TABLE staff_schedules (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT UNIQUE NOT NULL,
    schedule_id    INT NOT NULL,
    working_days   VARCHAR(20) NOT NULL,    -- CSV e.g. "1,2,3,4,5" Mon=1 Sun=7
    effective_from DATE,
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Leave balance per user per year
CREATE TABLE leave_balance (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    year           INT NOT NULL,
    entitled_days  INT NOT NULL DEFAULT 15,
    used_days      INT NOT NULL DEFAULT 0,
    pending_days   INT NOT NULL DEFAULT 0,
    carried_over   INT NOT NULL DEFAULT 0,
    UNIQUE KEY (user_id, year)
);

-- Role permissions matrix
CREATE TABLE role_permissions (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    role_key   VARCHAR(50) NOT NULL,
    feature    VARCHAR(50) NOT NULL,
    access     ENUM('none','read','write') DEFAULT 'none',
    UNIQUE KEY (role_key, feature)
);
```

### Total Tables (33+)
announcements, audit_log, breach_log, compliance_docs, contact_groups,
contacts, email_filters, email_queue, email_templates, escalation_log,
imap_folders, it_requests, leave_balance, leave_flow_rules, leave_requests,
login_history, mail_cache, ooo_settings, role_permissions, sessions,
signing_requests, signing_signatures, sla_tracker, staff_schedules, tasks,
usage_stats, user_profiles, user_signatures, users, vault_files, visitors,
work_schedules + more

---

## ROLES & ORGANISATIONAL STRUCTURE

### Complete Role Hierarchy (16 Roles)

```
MANAGEMENT (level 1) — full system access, approve HOD leave
├── md               MD — Mrs. Ogunlusi
└── bdm              Business Development Manager
                     ← SAME system access as MD

HODs (level 2) — report to Management | leave flow: HOD → HR → Management
├── head_it          Head IT Admin (= Lanre, super-admin)
├── head_outsourcing Head Outsourcing
├── head_compliance  Head Compliance
├── head_accounts    Head Accounts
├── hr               Head of Human Resources
├── head_training    Head Training
└── head_cso         Head Client Service Operations

MANAGERS (level 3) — report to their HOD
├── training_manager Training Manager → reports to head_training
│                    leave: head_training → hr → management
└── cs_manager       Client Service Manager → reports to head_outsourcing
                     leave: head_outsourcing → hr → management

STAFF (level 4)
├── cs_officer       Client Service Officer → reports to cs_manager
│                    leave: cs_manager → head_outsourcing → hr → management
├── accountant       Accountant → reports to head_accounts
│                    leave: head_accounts → hr → management
├── it_admin         IT Staff → reports to head_it
│                    leave: head_it → hr → management
├── front_desk       Front Desk → reports to hr
│                    leave: hr → management
├── cs_ops           Client Service Operations → reports to hr
│                    leave: hr → management
└── cleaner          Cleaner → reports to hr
                     leave: hr → management
```

### ROLES Constant (config/app.php — 16 roles as of June 22)

```php
define('ROLES', [
    'md'              => ['label'=>'MD',                       'level'=>1, 'icon'=>'👑', 'color'=>'#002850', 'permissions'=>['all']],
    'bdm'             => ['label'=>'Business Dev Manager',     'level'=>1, 'icon'=>'💼', 'color'=>'#002850', 'permissions'=>['all']],
    'head_it'         => ['label'=>'Head IT Admin',            'level'=>2, 'icon'=>'🖥️', 'color'=>'#0891b2', 'permissions'=>['all']],
    'head_outsourcing'=> ['label'=>'Head Outsourcing',         'level'=>2, 'icon'=>'🤝', 'color'=>'#7c3aed', 'permissions'=>['leave_approve','signing','vault','tasks','broadcast','compliance','directory','roster']],
    'head_compliance' => ['label'=>'Head Compliance',          'level'=>2, 'icon'=>'📋', 'color'=>'#0891b2', 'permissions'=>['signing','tasks','vault','compliance','roster']],
    'head_accounts'   => ['label'=>'Head Accounts',            'level'=>2, 'icon'=>'💰', 'color'=>'#059669', 'permissions'=>['vault','tasks','roster']],
    'hr'              => ['label'=>'Human Resources',          'level'=>2, 'icon'=>'👥', 'color'=>'#7c3aed', 'permissions'=>['leave_approve','signing','vault','tasks','broadcast','compliance','directory','roster']],
    'head_training'   => ['label'=>'Head Training',            'level'=>2, 'icon'=>'🎓', 'color'=>'#d97706', 'permissions'=>['leave_approve','signing','tasks','vault','directory','roster']],
    'head_cso'        => ['label'=>'Head Client Svc Ops',      'level'=>2, 'icon'=>'📞', 'color'=>'#db2777', 'permissions'=>['leave_approve','tasks','directory','roster']],
    'training_manager'=> ['label'=>'Training Manager',         'level'=>3, 'icon'=>'🎓', 'color'=>'#d97706', 'permissions'=>['leave_approve','tasks','directory','roster']],
    'cs_manager'      => ['label'=>'Client Service Manager',   'level'=>3, 'icon'=>'📞', 'color'=>'#db2777', 'permissions'=>['leave_approve','tasks','directory','roster']],
    'cs_officer'      => ['label'=>'Client Service Officer',   'level'=>4, 'icon'=>'👤', 'color'=>'#64748b', 'permissions'=>['leave_request','tasks','vault','it_request']],
    'accountant'      => ['label'=>'Accountant',               'level'=>4, 'icon'=>'💰', 'color'=>'#059669', 'permissions'=>['vault','tasks','it_request']],
    'it_admin'        => ['label'=>'IT Admin',                 'level'=>4, 'icon'=>'🛡️', 'color'=>'#0891b2', 'permissions'=>['leave_request','tasks','vault','it_request']],
    'front_desk'      => ['label'=>'Front Desk',               'level'=>4, 'icon'=>'🏢', 'color'=>'#db2777', 'permissions'=>['visitors','directory','tasks','it_request']],
    'cs_ops'          => ['label'=>'Client Service Ops',       'level'=>4, 'icon'=>'📞', 'color'=>'#db2777', 'permissions'=>['tasks','it_request']],
    'cleaner'         => ['label'=>'Cleaner',                  'level'=>4, 'icon'=>'🧹', 'color'=>'#94a3b8', 'permissions'=>['tasks','it_request']],
]);
```

### Leave Approval Chains (Dynamic — Not Hardcoded)

```php
$leaveApprovalChains = [
    'md'              => [],
    'bdm'             => ['md'],
    'head_it'         => ['hr', 'md'],
    'head_outsourcing'=> ['hr', 'md'],
    'head_compliance' => ['hr', 'md'],
    'head_accounts'   => ['hr', 'md'],
    'hr'              => ['md'],
    'head_training'   => ['hr', 'md'],
    'head_cso'        => ['hr', 'md'],
    'training_manager'=> ['head_training', 'hr', 'md'],
    'cs_manager'      => ['head_outsourcing', 'hr', 'md'],
    'cs_officer'      => ['cs_manager', 'head_outsourcing', 'hr', 'md'],
    'accountant'      => ['head_accounts', 'hr', 'md'],
    'it_admin'        => ['head_it', 'hr', 'md'],
    'front_desk'      => ['hr', 'md'],
    'cs_ops'          => ['hr', 'md'],
    'cleaner'         => ['hr', 'md'],
];
```

---

## WORK SCHEDULES

```
Schedule 1: Full Time   — works every day (Mon–Sun) — 21 days annual leave
Schedule 2: 4-Day Week  — works 4 days/week         — 15 days annual leave
Schedule 3: 3-Day Week  — works 3 days/week         — 15 days annual leave
Schedule 4: 2-Day Week  — works 2 days/week         — 15 days annual leave
```

### Business Rules
- **Leave = calendar days** (not working days). A Mon–Fri request = 5 days regardless of schedule.
- **Unused leave carries over** to the next year (no cap defined yet).
- **Probation rule:** Staff entitled to leave only after **1 full year** of employment.
- **Working days** stored as CSV of day numbers: Mon=1, Tue=2, Wed=3, Thu=4, Fri=5, Sat=6, Sun=7.
  Example: "1,2,3,4,5" = Mon–Fri; "1,3,5" = Mon/Wed/Fri.

### Roster Visibility
| Role | Can See Roster? |
|------|----------------|
| md, bdm | ✅ All staff |
| hr | ✅ All staff |
| head_* roles | ✅ Their department |
| cs_manager, training_manager | ✅ Their direct reports only |
| All level 4 staff | ❌ Cannot see roster |

---

## ROLE-BASED DASHBOARDS ✅ COMPLETE (Sprint 5 — June 21–22, 2026)

### Dashboard Router (index.php — current state)
```php
$dashboards = [
    'md'              => __DIR__ . '/dashboard/management.php',
    'bdm'             => __DIR__ . '/dashboard/management.php',
    'head_it'         => __DIR__ . '/dashboard/superadmin.php',   // ← SUPER ADMIN
    'head_outsourcing'=> __DIR__ . '/dashboard/hod.php',
    'head_compliance' => __DIR__ . '/dashboard/hod.php',
    'head_accounts'   => __DIR__ . '/dashboard/hod.php',
    'hr'              => __DIR__ . '/dashboard/hr.php',
    'head_training'   => __DIR__ . '/dashboard/hod.php',
    'head_cso'        => __DIR__ . '/dashboard/hod.php',
    'training_manager'=> __DIR__ . '/dashboard/manager.php',
    'cs_manager'      => __DIR__ . '/dashboard/manager.php',
    'it_admin'        => __DIR__ . '/dashboard/superadmin.php',   // ← SUPER ADMIN
    'cs_officer'      => __DIR__ . '/dashboard/staff.php',
    'accountant'      => __DIR__ . '/dashboard/staff.php',
    'front_desk'      => __DIR__ . '/dashboard/staff.php',
    'cs_ops'          => __DIR__ . '/dashboard/staff.php',
    'cleaner'         => __DIR__ . '/dashboard/staff.php',
];
$dash = $dashboards[$user['role']] ?? __DIR__ . '/dashboard/staff.php';
require $dash;
```

### Dashboard Files

| File | Roles | Key Widgets |
|------|-------|-------------|
| `dashboard/superadmin.php` | head_it, it_admin | Everything: all KPIs, leave queue, IT tickets, failed logins, dept headcount, new joiners, breach log, audit log, announcements, staff online, compliance expiry, own leave balance |
| `dashboard/management.php` | md, bdm | All pending leave, staff online, audit log, announcements, compliance expiry, on leave today, quick actions |
| `dashboard/hr.php` | hr | HR-stage leave queue, dept headcount, new joiners, on leave today, compliance expiry, announcements |
| `dashboard/hod.php` | all head_* | Direct reports' leave, team tasks, pending signatures, own leave balance, team list |
| `dashboard/manager.php` | cs_manager, training_manager | Direct reports' leave, team tasks, team roster, own leave balance |
| `dashboard/it.php` | (now unused — superseded by superadmin) | IT tickets, sessions, failed logins, audit log, breach log |
| `dashboard/staff.php` | level 4 staff | Unread mail, own leave balance, leave status, pending tasks, docs to sign, announcements |

**Note:** `dashboard/it.php` still exists but `head_it` and `it_admin` both now route to `superadmin.php`.

---

## FILE STRUCTURE

```
/home/hrindexx/mail.hrindexx.com/
│
├── config/
│   ├── app.php          — Constants, ROLES (16), GEMINI_API_KEY, ob_start+session
│   ├── db.php           — getDB() PDO connection
│   └── mail.php         — IMAP/SMTP constants, imapString()
│
├── lib/
│   ├── Auth.php         — Auth::require(), requireRole(), validateCsrf(),
│   │                      login(), logout(), csrfToken(), csrfField(),
│   │                      auditLog(), sanitiseString(), can(), requirePermission()
│   ├── payslip-pdf.php  — HriPayslip class: generates HTML payslip email body (earnings/deductions table, company header, NDPC footer, base64 logo)
│   ├── Layout.php       — 206-byte STUB → requires layout_shell.php
│   ├── layout_shell.php — Full Gmail-style layout (51KB+)
│   │                      Methods: shell(), end(), topbar(), footer()
│   │                      Sets window.CSRF_TOKEN, auto-injects CSRF into forms
│   ├── layout_shell.css — CSS variables (--navy #002850, --green #64A014)
│   ├── ImapHelper.php   — IMAP connection, getBody(), sanitizeHtml(),
│   │                      listFolders(), parseParts(), CID map for inline images
│   └── vendor/          — PHPMailer (composer installed)
│
├── api/
│   ├── mail/
│   │   ├── action.php      — mark_read, mark_unread, star, unstar, move,
│   │   │                     save_draft, vault (all return JSON)
│   │   ├── send.php        — PHPMailer SMTP send (checks X-CSRF-Token)
│   │   ├── queue.php       — Undo send + schedule send queue
│   │   ├── poll.php        — Real-time inbox polling (30s interval)
│   │   ├── attachment.php  — Serve email attachments
│   │   ├── attachement.php — Legacy typo copy (exists on server, both work)
│   │   ├── check.php       — Unread count check
│   │   ├── preview.php     — Email preview
│   │   ├── smart-reply.php — Smart reply via AI
│   │   ├── summary.php     — Email summary
│   │   └── unread-count.php — Unread count endpoint
│   ├── ai/
│   │   ├── summarise.php   — Gemini AI email summary
│   │   ├── smart-reply.php — Gemini AI smart replies (3 tones)
│   │   └── compose.php     — Gemini AI email compose
│   ├── auth/
│   │   ├── kill-session.php     — End a specific session
│   │   └── kill-all-sessions.php — End all other sessions
│   ├── tasks/
│   │   └── add.php         — Add task via API
│   ├── sop/
│   │   └── preview.php     — SOP document preview
│   ├── compliance/
│   │   └── preview.php     — Compliance document preview
│   └── payslip/
│       ├── upload.php      — CSV upload → parse → create payslip_batches + payslip_queue rows
│       ├── template.php    — Serve blank CSV template download
│       ├── csv-tpl.php     — CSV template endpoint
│       ├── report.php      — Batch status + per-employee resend trigger
│       └── cancel.php      — Cancel a pending batch
│
├── cron/
│   ├── send-queue.php   — Process email_queue (every minute)
│   ├── sla-check.php    — SLA deadline alerts (daily 8am)
│   ├── ooo-reply.php    — Out-of-office auto-replies
│   ├── retention.php    — Data retention cleanup
│   └── payslip-send.php — Payslip distribution queue processor (every minute; rate-limited 15 emails/hour)
│
├── admin/
│   ├── users.php        — User management (CRUD, role, line manager, schedule)
│   ├── audit.php        — Audit log viewer
│   ├── broadcast.php    — Role-based broadcast email
│   ├── it-requests.php  — IT support ticket management
│   ├── usage.php        — Usage analytics dashboard
│   ├── tasks.php        — Task management admin view
│   ├── roles.php        — Role permission matrix (16 roles × 13 features)
│   ├── work-schedules.php — Work schedule CRUD
│   ├── irs-settings.php — IRS dynamic config (roles + petty cash limit), change log
│   ├── sessions.php     — Active sessions viewer
│   ├── announcements.php — Company announcements
│   └── leave-queue.php  — Admin leave approvals queue
│
├── dashboard/           — Role-based dashboards (loaded by index.php)
│   ├── superadmin.php   — head_it + it_admin: ALL sections combined
│   ├── management.php   — md + bdm: company-wide view
│   ├── hr.php           — hr: people & leave focus
│   ├── hod.php          — all head_* HODs: team + leave + tasks
│   ├── manager.php      — cs_manager, training_manager
│   ├── it.php           — (superseded by superadmin, kept for reference)
│   └── staff.php        — all level 4 staff: personal view
│
├── database/
│   └── sprint5_migration.sql — user_profiles + role_permissions tables
│
├── uploads/
│   ├── .htaccess        — php_flag engine off + block .php files
│   ├── signing/         — Signed PDFs
│   ├── signatures/      — Signature PNG images
│   ├── vault/           — User vault files (subdir per user ID)
│   ├── compliance/      — Compliance documents
│   └── sops/            — SOP documents
│
├── mail.php             — Main inbox/email viewer (42KB, core page)
├── search.php           — Full IMAP text search
├── index.php            — Role-based dashboard router
├── login.php / logout.php
├── profile.php          — User profile (account, HR fields, password, sessions)
├── compose.php          — Redirects to mail.php#compose
├── contacts.php         — Contact book + CSV import + group send
├── email-tpl.php        — Email templates (CRUD + {{variable}} substitution)
├── folders.php          — Custom IMAP folder management
├── filters.php          — Email filter rules engine
├── leave.php            — Staff leave request submission
├── leave-approvals.php  — Manager/HR leave approval workflow
├── leave-approve.php    — Email link approval (token-based, no login required)
├── tasks.php            — Kanban task manager
├── task-analytics.php   — Task analytics and reporting
├── my-stats.php         — Personal usage statistics
├── vault.php            — Document vault (upload, download, categorise)
├── vault-serve.php      — Secure vault file serving (auth-gated, ownership check)
├── signing.php          — Document signing list
├── signing-detail.php   — Individual signing request detail
├── sign.php             — Signature capture page
├── signature.php        — Email signature manager (3 presets, NDPA disclaimer)
├── compliance.php       — SLA register + compliance documents
├── breach.php           — NDPC data breach log (NDPA 2023)
├── directory.php        — Staff directory
├── it-request.php       — IT support ticket submission
├── visitors.php         — Visitor management (front desk)
├── ooo.php              — Out of office settings
├── sop.php              — Standard Operating Procedures viewer (+ version control / Supersedes)
├── chat.php             — Internal team chat (channels + DMs, 15s polling)
├── payslip.php          — Payslip distribution manager: CSV upload, batch queue, send status, download/resend per employee (roles: md, bdm, head_it, head_accounts, hr + HODs/managers)
│
├── .htaccess            — HTTPS redirect, HSTS, security headers, WAF rules,
│                          debug file blocklist (expanded June 22)
├── .user.ini            — PHP session settings (LiteSpeed reads before PHP)
└── favicon.ico          — Navy blue HRI favicon (also favicon.svg)
```

---

## AUTHENTICATION & SECURITY SYSTEM

### Auth::require()
```php
$user = Auth::require();
// Returns: ['id', 'name', 'email', 'role', 'department', 'phone', ...]
// ALSO: auto-validates CSRF token for all POST requests
```

### Auth::requireRole(array $roles)
```php
$user = Auth::requireRole(['md', 'bdm', 'hr']);
// Pass role keys from the ROLES constant above
// ALSO: auto-validates CSRF token for all POST requests
```

### Auth::validateCsrf() — Added June 22, 2026
CSRF validation is now **automatic** — it is called inside `Auth::require()` and
`Auth::requireRole()` for every POST request. You do NOT need to call it manually.

How it works:
- Checks `$_POST['_csrf']` for HTML form submissions
- Checks `$_SERVER['HTTP_X_CSRF_TOKEN']` header for AJAX/fetch calls
- Compares with `$_SESSION['csrf_token']` using `hash_equals()` (timing-safe)
- Returns JSON `{"ok":false,"error":"CSRF token invalid"}` for API calls
- Returns 403 error page for browser form submissions

**HTML forms get CSRF automatically** via a JS listener in `layout_shell.php` that
appends `<input name="_csrf">` to any POST form before submission. No per-form changes needed.

**AJAX/fetch already sends** `X-CSRF-Token: window.CSRF_TOKEN` header in all calls.

### Security Headers (.htaccess — updated June 22)
```
Strict-Transport-Security: max-age=31536000; includeSubDomains   ← NEW (HSTS)
X-Content-Type-Options: nosniff
X-Frame-Options: SAMEORIGIN
X-XSS-Protection: 1; mode=block
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=()
```

### Session Storage
- `$_SESSION['user_id']`    — authenticated user ID
- `$_SESSION['token']`      — session token (validated against sessions table)
- `$_SESSION['mail_pass']`  — IMAP password for this session
- `$_SESSION['csrf_token']` — CSRF token (auto-generated on first access)

---

## LAYOUT SYSTEM

### Using Layout in a Page
```php
<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';  // NOT layout_shell.php

$user = Auth::requireRole(['md', 'hr']);
$db   = getDB();

Layout::shell($user, 'page_active_key', $unreadCount, 'Page Title');
?>
<!-- page HTML here using HRI CSS classes -->
<?php Layout::end(); ?>
```

### Available CSS Classes
```css
.hri-page, .hri-page-hd, .hri-page-title
.hri-card, .hri-card-hd, .hri-card-title
.hri-btn, .hri-btn-navy, .hri-btn-outline
.hri-input, .hri-select, .hri-textarea, .hri-label, .hri-form-group
.hri-pill, .hri-pill-info, .hri-pill-success
.hri-empty
/* CSS vars: --navy #002850, --green #64A014, --g50→--g900, --red, --w */
```

### Responsive Design
All pages are now responsive (fixed June 21–22, 2026):
- Sidebar collapses at 768px (layout_shell.css)
- Tables get `overflow-x: auto` wrapper at 768px
- Two-column grids (`grid-template-columns: 1fr 1fr` or `1fr Xpx`) collapse to 1-col at 640px
- Stat card grids collapse to 2-col at 420px, then 1-col at 380px
- Vault and SOP sidebars become horizontal scroll strips on mobile

---

## AI INTEGRATION

### Provider: Google Gemini (Free Tier)
- **Model:** `gemini-2.5-flash`
- **Endpoint:** `https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=KEY`
- **Key location:** `config/app.php` → `define('GEMINI_API_KEY', 'AQ.Ab8RN...');`
- **Free limit:** 1,500 requests/day, no credit card required
- **Key format:** Starts with `AQ.` (newer Google AI Studio format, not `AIza`)
- **Must use cURL** — `allow_url_fopen` is disabled on server

### AI Files
- `api/ai/summarise.php` — POST `body`, `subject`, `uid`, `folder`
- `api/ai/smart-reply.php` — POST `body`, `subject`, `from` → `{ok:true, replies:[{label,body}]}`
- `api/ai/compose.php` — JSON body `{prompt, to, subject}`

### Gemini cURL Pattern
```php
$payload = json_encode([
    'contents'         => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature'=>0.3,'maxOutputTokens'=>300,'thinkingConfig'=>['thinkingBudget'=>0]]
]);
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$payload,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>true]);
$response = curl_exec($ch); curl_close($ch);
$text = trim(json_decode($response,true)['candidates'][0]['content']['parts'][0]['text'] ?? '');
```

---

## MAIL SYSTEM

### Email Reading (mail.php)
- IMAP pagination (20 per page), 458 pages for 9,153 emails
- Email body rendered via `srcdoc` on `<iframe id="mailFrame">`
- CID inline images replaced with proxied URLs via `api/mail/attachment.php`
- `ImapHelper::getBody()` returns `['html', 'plain', 'attachments', 'cid_map']`
- HTML preferred over plain; `sanitizeHtml()` keeps `<style>` tags (safe in iframe)
- Spam folder shown in sidebar; Trash delete = move, Trash re-delete = expunge

### Email Actions (api/mail/action.php)
Switch cases: `mark_read`, `mark_unread`, `star`, `unstar`, `move`, `save_draft`, `vault`

### Send Queue
- Undo send: 10s delay, stored in `email_queue`
- Schedule send: custom datetime
- cPanel cron: `* * * * * php /home/hrindexx/mail.hrindexx.com/cron/send-queue.php`

### Email Signatures (signature.php — rebuilt June 21)
- `user_signatures` table stores per-user HTML signatures
- `$defaultSig` — logged-in user's personal branded signature (logo + NDPA disclaimer)
- `$sysSigTemplate` — admin System Default using `{name}`, `{role}`, `{email}` placeholders
- Three presets: Professional, Modern, Executive — all use `/hri-logo.png`
- HRI logo: `/hri-logo.png` (root of document root)
- NDPA 2023 disclaimer and NDPC/DCP/12819 cert reference included in company default

---

## CHAT SYSTEM (Sprint 6 — June 23, 2026)

### Architecture
- **15-second smart polling** — `document.visibilityState` check, only polls when tab visible
- **Incremental fetch** — `after=lastId` so only new messages returned (indexed query, near-zero load)
- **~20 users × 4 polls/min = 80 lightweight queries/min** — safe on shared hosting

### Tables
- `chat_channels` — public channels + DM channels (`type ENUM('public','direct')`)
- `chat_members` — per-user membership + `last_read_id` for unread tracking
- `chat_messages` — messages with `reply_to_id` for threading, `is_deleted` soft delete

### Files
| File | Purpose |
|------|---------|
| `chat.php` | Main page — channel sidebar, message thread, input bar |
| `api/chat/poll.php` | GET `?channel_id=X&after=Y` — new messages + total_unread nav badge |
| `api/chat/send.php` | POST send message, returns full message object for immediate render |
| `api/chat/channels.php` | GET channel list with unread counts; POST create channel (HODs+) |
| `api/chat/read.php` | POST mark channel read (uses GREATEST to never go backwards) |
| `api/chat/dm.php` | POST get-or-create DM channel between two users |
| `database/chat_migration.sql` | CREATE TABLE + seed #general, #hr, #it-support + auto-enroll all users to #general |

### Setup steps (server)
1. Run `database/chat_migration.sql` in phpMyAdmin
2. Upload all new/modified files
3. #general, #hr, #it-support channels auto-created; all active users auto-joined to #general

### Nav badge
- `id="chatNavBadge"` span added to Chat nav item in `layout_shell.php`
- Updated by `CHAT.updateNavBadge(total_unread)` on every poll response

---

## CURRENT STATUS (as of June 23, 2026)

### ✅ FULLY WORKING

- Inbox (9,153+ messages, pagination, threading, scroll)
- HTML email rendering with inline images (srcdoc iframe)
- Reply / Reply All / Forward / Mark Read / Star / Print / Delete (Trash + expunge)
- Bulk select + bulk actions (read/unread/star/delete)
- AI Summary (Gemini 2.5 Flash) + Smart Replies (3 tones) + AI Compose
- Compose: rich text, attachments, Undo Send (10s), Schedule Send
- Draft auto-save (localStorage + IMAP server)
- Search (IMAP full-text, keyword highlighting)
- Folders (IMAP: create/rename/delete) + Spam folder in sidebar
- Contacts (add, CSV import, group send)
- Email Templates (CRUD + {{variable}} substitution)
- Email Filters (rules engine)
- Email Signature manager (3 branded presets, NDPA disclaimer, system default)
- Out of Office auto-reply settings
- Tasks (Kanban board) + Task Analytics
- Leave request + approval workflow + email link approval
- Document Signing (create, sign, track)
- Document Vault (upload, download, save email to vault, secure serving)
- Compliance/SLA register
- Data Breach Log (NDPA 2023 compliant)
- Staff Directory, IT Support tickets, Visitor Management
- Profile (account info, HR fields, password change, session management)
- SOPs viewer + version control (Supersedes — upload new version, old auto-deactivated, chain badges shown)
- Payslip Distribution (CSV upload, batch queue, rate-limited cron send, per-employee resend, cancel batch)
- Internal Chat (channels, DMs, reply threading, unread badges, 15s polling)
- Admin: User Management, Broadcast, Audit Log, IT Requests, Analytics, Roles Matrix, Work Schedules
- Role-based Dashboards (all 7 files) — superadmin, management, hr, hod, manager, it, staff
- CSRF protection (auto-validated on all POST requests via Auth::require)
- HSTS + security headers in .htaccess
- Responsive design (all pages mobile-optimised)

### ⚠️ NEEDS SERVER UPLOAD (local files now fully corrected — June 23)

Use FileZilla to upload entire local folder to `/home/hrindexx/mail.hrindexx.com/`
EXCLUDE: `uploads/` directory and `.user.ini` — these must NOT be overwritten.

**Files fixed June 23 (upload all of these):**

| File | Fix Applied |
|------|-------------|
| `lib/Auth.php` | login() now updates `users.last_login` on every login; Auth::require() enforces IDLE_TIMEOUT (2h inactivity = auto-logout) |
| `lib/layout_shell.php` | Fixed `user_signatures` query: column `html` (not `signature`), condition `is_default=1` (not `is_active=1`) — signatures in compose were broken |
| `dashboard/management.php` | Fixed signing table names: `sign_signatories`/`sign_requests` (was `signing_signatures`/`signing_requests`) |
| `dashboard/superadmin.php` | Sessions quick action icon changed from green dot to lock (🔒) |
| `cron/sla-check.php` | Fixed: `sign_requests` (was `signing_requests`) |
| `cron/retention.php` | Now also deletes sessions idle past IDLE_TIMEOUT (2h), not just expires_at |
| `mail.php` | Added `ob_start`/`session_start` header |
| `login.php` | Added `ob_start`/`session_start` header |
| `logout.php` | Added `ob_start`/`session_start` header |
| `compose.php` | Added `ob_start`/`session_start` header |
| `vault-serve.php` | Added `ob_start`/`session_start` header |
| `leave-approve.php` | Added `ob_start`/`session_start` header |
| `visitors.php` | Added visitor history section with date filter and all-time stats |
| `it-request.php` | Added expandable description view on each ticket row |
| `tasks.php` | `$rShort` now uses `ROLES` constant (stale hardcoded map removed) |
| `task-analytics.php` | `$rLabel` now uses `ROLES` constant (stale hardcoded map removed) |
| `admin/tasks.php` | `$rShort` now uses `ROLES` constant (stale hardcoded map removed) |

**Previously fixed (June 22) — also need uploading if not yet done:**

| File | Fix Applied |
|------|-------------|
| `lib/Auth.php` | CSRF validateCsrf() auto-validation added |
| `lib/layout_shell.php` | CSRF auto-inject JS added |
| `.htaccess` | HSTS + expanded debug file blocklist |
| `dashboard/superadmin.php` | New file — combined view for head_it + it_admin |
| `index.php` | 16-role router updated |
| `signature.php` | Dynamic user name (not hardcoded) |
| `breach.php` | Removed invalid 'compliance' role |
| `task-analytics.php` | Fixed $isAdmin, removed invalid roles |
| `tasks.php` | Fixed $isAdmin, $isManager |
| `sop.php` | Fixed canUpload/canDelete role arrays |
| `signing-detail.php` | Fixed super-admin SQL to include head_it/md/bdm |
| `api/compliance/preview.php` | Removed invalid 'compliance' role |
| `admin/sessions.php` | STALE badge for idle >1h |

### 🚨 MUST DELETE FROM LIVE SERVER (via cPanel File Manager)

These diagnostic/debug files exist only on the server, not in local copy:

**Critical (delete first):**
- `fixpass.php` — no-auth admin password reset backdoor

**High priority:**
- `gemini-test.php` — exposes API key without auth
- `colcheck.php` — exposes DB schema without auth
- `chk.php` — exposes file/error state without auth
- `autofix.php`, `autofix2.php`, `autofix3.php`, `autofix4.php`

**Medium priority:**
- `err.php`, `errshow.php`, `page-diag.php`, `quick-diag.php`
- `srvcheck.php`, `test500.php`
- `debugdash.php`, `shelldebug.php`, `testcompose.php`
- `uploads/signing-detail.php`

### ⚠️ KNOWN ISSUES (Lower Priority)

- Document Vault: 2 test files show "File missing" — orphaned DB records, safe to delete
- Inbox badge counter: slight inconsistency between poll.php and PHP-seeded count
- Draft avatar shows "?" — cosmetic (no sender on drafts)
- MIME-encoded subjects: some show `=?utf-8?Q?...` — edge case in ImapHelper
- `leave-approve.php` token: uses base64("role:id:id") with no HMAC — low risk but weak

---

## SPRINT ROADMAP

### ✅ Sprint 1 — Complete
Search, Mark Read/Unread, Starred, Print, Undo Send, Draft→IMAP

### ✅ Sprint 2 — Complete
Email Threading, Custom Folders, Schedule Send, Email Templates, Contacts

### ✅ Sprint 4 — Mostly Complete (June 21–22)
- 16-role system in config/app.php ✅
- `admin/roles.php` — role permission matrix ✅
- `admin/work-schedules.php` — work schedule CRUD ✅
- `admin/users.php` updated with role dropdown, line manager, schedule ✅
- `work_schedules`, `staff_schedules`, `leave_balance`, `role_permissions` tables created ✅
- Leave approval chain logic ✅
- Org chart: not yet built

### ✅ Sprint 5 — Complete (June 21–22)
- All 7 dashboard files created ✅
- `index.php` role router ✅
- `dashboard/superadmin.php` — combined view for head_it + it_admin ✅

### 🔲 Sprint 3 — HR Automation (partially done)
- Leave approval auto-emails — not yet wired to stage changes
- NDPC expiry alerts — `cron/sla-check.php` exists but NDPC-specific cron not confirmed
- Staff onboarding email — not yet built
- Out of Office auto-reply — `ooo.php` + `cron/ooo-reply.php` built ✅
- Email filter rules — `filters.php` built ✅

### 🔲 Sprint 6 — Roster & Attendance
- Calendar-style roster grid (🟢 Working / 🟡 On Leave / ⚫ Off Day)
- Public holiday integration
- Visible only to md, bdm, hr, head_* roles, cs_manager, training_manager

### 🔲 Security (Ongoing)
- CSRF token on login form — not yet added (login.php has no CSRF since session pre-auth)
- Content Security Policy — ✅ added to .htaccess (July 12, 2026)
- Brute force lockout UI — backend in Auth.php (email+IP rate limiting both), no UI yet
- `leave-approve.php` token — upgrade to HMAC-SHA256

---

## COMMON PATTERNS

### Standard Page Template
```php
<?php
if (ob_get_level() === 0) ob_start();
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/app.php';   // '/../config/app.php' for admin/
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Layout.php';   // ALWAYS Layout.php, NEVER layout_shell.php

$user = Auth::requireRole(['md', 'hr']);    // use new 16 role keys
$db   = getDB();

Layout::shell($user, 'nav_key', 0, 'Page Title');
?>
<!-- HTML -->
<?php Layout::end(); ?>
```

### Standard API Endpoint
```php
<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../lib/Auth.php';
header('Content-Type: application/json');
$user = Auth::require();   // auto-validates CSRF on POST
$action = $_POST['action'] ?? '';
echo json_encode(['ok' => true]);
```

### Safe PHP → JS (Always json_encode)
```php
var data    = <?=json_encode($phpArray)?>;
var subject = <?=json_encode($subject ?? '')?>;
// NEVER: var x = '<?=$rawValue?>'; — breaks on quotes/newlines
```

### CSRF in JS Fetch (all fetch calls must include this header)
```javascript
fetch('api/mail/action.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': window.CSRF_TOKEN },
    body: fd
})
```

### Audit Logging
```php
Auth::auditLog($user['id'], 'action_name', 'detail string max 500 chars');
```

---

## KNOWN PHP PITFALLS

1. **Never use `fn()` arrow functions** — use `function($x){ return expr; }` instead.
2. **Never use Python to replace `$variable`** — Python escapes `$` as `\$`. Write line by line.
3. **Always check brace balance** — `{` count must equal `}` in PHP sections.
4. **`ob_end_flush()` is in `layout_shell.php`** — don't call it in individual pages.
5. **`window.CSRF_TOKEN` not `var CSRF_TOKEN`** — must be on window to be globally accessible.
6. **Email body is `srcdoc` not `fd.write()`** — fd.write() fails silently on LiteSpeed.
7. **Users table column is `name` not `full_name`** — always `u.name` in queries.
8. **Leave status is `current_stage` not `status`** — no `approved_by` or `rejection_reason`.
9. **Dashboard files are `require`d by index.php** — they check `if (!isset($user))`, not `Auth::require()`. Never add Auth::require() inside them.
10. **CSRF is auto-validated** — `Auth::require()` calls `validateCsrf()` internally for POSTs. Don't call it again manually.

---

## CRON JOBS (Set in cPanel)

```
* * * * *   php /home/hrindexx/mail.hrindexx.com/cron/send-queue.php
* * * * *   php /home/hrindexx/mail.hrindexx.com/cron/payslip-send.php
0 8 * * *   php /home/hrindexx/mail.hrindexx.com/cron/sla-check.php
0 * * * *   php /home/hrindexx/mail.hrindexx.com/cron/ooo-reply.php
0 2 * * *   php /home/hrindexx/mail.hrindexx.com/cron/retention.php
```

---

## QA SCORE HISTORY

| Date | Score | Notes |
|------|-------|-------|
| June 19, 2026 (initial) | 7.0/10 B- | Baseline after Sprint 1+2 |
| June 20, 2026 (post-fixes) | 8.7/10 B+ | All inbox buttons, AI live |
| June 22, 2026 (post-security) | 9.3/10 A | CSRF, HSTS, debug files removed, all dashboards, responsive |
| June 23, 2026 (post-bugfix) | 9.6/10 A | Signature column fix, signing table names, ob_start on all pages, role maps, last_login, idle timeout, visitor history, IT ticket view |
| June 23, 2026 (chat + SOP) | 9.7/10 A | SOP version control (Supersedes), BOM fix on 9 pages, internal chat system (channels+DMs+polling) |
| June 24, 2026 (leave flows) | 9.8/10 A | Configurable leave approval flows: admin/leave-flows.php, leave_flow_rules table, leave.php + leave-approvals.php rewritten to be fully DB-driven and multi-stage |
| June 27, 2026 (signature fix) | 9.9/10 A | Fixed critical column name bugs in signature.php (signature→html, is_active→is_default); multi-sig support with named sigs + set-default; compose panel signature picker added (hriSigPickerBtn, hriSetSig, window.HRI_SIGS); send function now uses hriSigInner instead of outerHTML |
| June 28, 2026 (task v3) | 10/10 A+ | Task management v3: progress % tracking (slider + live bar on cards), checklists/subtasks in detail panel, kanban drag-drop board with view toggle, management dashboard avg_progress column; CC recipients now shown in email view; announcements on all pages; chat panel safety guard; api/tasks/add.php fixed (ENUM source, CSRF, try/catch) |
| July 1, 2026 (arch + PWA) | 10/10 A+ | Phase 1-4 architecture overhaul: 500 fix (users.php), Auth::isAdmin(), sendSystemMail(), SMTP_VERIFY_SSL, batch mail_cache upsert, subquery aggregates, Layout::getAnnouncements() dedup, phase4_indexes.sql; PWA install (manifest.webmanifest + sw.js, network-first) |
| July 1, 2026 (IRS v1) | 10/10 A+ | Internal Request System: 5 workflows (Requisition, Caution, Payment Request, Petty Cash, Retirement), multi-stage approval, Sage ref posting, file attachments, full audit trail; irs.php, irs-new.php, irs-detail.php, irs-approvals.php, api/irs/* (submit/action/upload/serve), database/irs_migration.sql; IRS_ACCOUNTS_ROLES + IRS_MANAGER_ROLES + IRS_PETTY_CASH_LIMIT constants in config/app.php; nav item added to layout_shell.php |
| July 1, 2026 (Subscriptions) | 10/10 A+ | Subscription Tracker: internet/software/domain/hosting renewals, one-click Renew (auto-calculates next cycle date), full renewal history ledger, cost analytics (monthly+annual totals), alert integration in cron/sla-check.php; subscriptions.php, api/subscriptions/* (save/renew/delete/history), database/subscriptions_migration.sql; nav item in layout_shell.php ADMIN section |
| July 12, 2026 (enterprise security) | 10/10 A+ | Enterprise-grade hardening: email+IP rate limiting (Auth.php), getIP() CF-only trust, trackUsage allowlist, AES-256 encrypt/decrypt helpers, DB_PASS moved to env var (config/db.php), CSP header (.htaccess), APP_ENCRYPTION_KEY infrastructure (config/app.php), role hierarchy check (admin/users.php), phone/LM restricted to level≤3 (directory.php), checklist BOLA fix + allUsers parameterized + max-length guards (tasks.php), leave balance check before INSERT (leave.php), admin-only IRS retire (api/irs/submit.php), server-side MIME validation (compliance.php), signing Remind moved server-side — token no longer in HTML (signing-detail.php + api/signing/remind.php) |
| July 12, 2026 (IRS dashboard + bug sweep) | 10/10 A+ | IRS dynamic config system: getIrsConfig() reads irs_config table (DB-driven roles + petty cash limit), admin/irs-settings.php (visual role picker + change log), dashboard/_irs_widget.php injected into all 6 dashboards; mail delete + star CSRF headers fixed (quickDel/bulkAction/toggleStar); compose body-overflow fixed (CSS flex); contacts.php 4 AJAX fetch calls missing X-CSRF-Token fixed; vault.php upload form missing csrfField fixed; sign.php missing ob_start/session_start fixed; admin/users.php sendSystemMail 3-arg crash fixed; api/signing/remind.php sendSystemMail 3-arg crash fixed; mail.php printEmail wrong CSS selectors fixed; IRS Settings nav item added to layout_shell.php ADMIN section; superadmin quick-actions: IRS Settings + IRS Queue links added; database/irs_config_migration.sql created |
| July 15, 2026 (payslip distribution) | 10/10 A+ | Payslip Distribution System: CSV upload with column mapping, payslip_batches + payslip_queue tables, HriPayslip HTML email generator (lib/payslip-pdf.php), rate-limited cron sender (15/hour, cron/payslip-send.php), batch management UI (payslip.php) with send status, per-employee resend, cancel batch; api/payslip/* (upload/template/report/cancel) |
| July 17, 2026 (IRS UX overhaul) | 10/10 A+ | irs.php redesigned: sticky-column table replaces card grid — amber row highlight for action rows, inline approve/reject buttons always visible, floating confirm popup (no expand/collapse); irs-new.php wizard: 3-step progress indicator, review step before submit, `goToReview()` builds summary of all fields; admin/users.php 500 fixed: added missing `require_once config/mail.php` (sendSystemMail was undefined on new user creation) |
| July 27, 2026 (IRS rejection push-back + accounting workflows) | 10/10 A+ | Rejection push-back: every `reject` action now pushes the request to the PREVIOUS stage instead of terminal `rejected`; first-stage rejection → `pending_corrections` (requester edits description/amount/beneficiary and resubmits); intermediate-stage rejection → previous approver re-reviews with push-back context banner; all rejections still logged in `irs_audit_log`; `pending_corrections` stage added to all 5 request types (DB migration: irs_pushback_migration.sql); IrsFlow.php updated with new stage codes/labels/colors; `submit_correction` action in action.php clears all prior approvals and restarts flow; saved-account inline autocomplete via HTML `<datalist>` on beneficiary rows (oninput `_fillFromSaved()` auto-fills all 3 fields); Payment/Retirement accounting flows: journals at initiation (payment), journals attached by HOD at retire stage (approve_journals action); `irs_workflow_revision.sql` + `irs_saved_accounts_migration.sql` + `irs_pushback_migration.sql` (run all in phpMyAdmin) |

---

## PWA

- **manifest.webmanifest** — `.webmanifest` extension used (LiteSpeed WAF blocks `.json`)
- **sw.js** — network-first service worker; caches shell assets only; never intercepts POST or `/api/` calls
- **layout_shell.php** — registers SW + links manifest in `<head>`; Apple PWA meta tags included
- **MIME type** — `AddType application/manifest+json .webmanifest` in `.htaccess`
- **Install** — Chrome/Edge shows install icon in address bar on `https://mail.hrindexx.com`

---

*Last updated: July 17, 2026 (IRS UX overhaul — sticky table, wizard, users.php 500 fix)*
*Built by Claude (Anthropic) with Olanrewaju (Lanre) Oloritun*
