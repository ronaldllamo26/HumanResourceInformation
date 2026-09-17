# PrimePower HRIS — Security Architecture & Technical Documentation

**System:** PrimePower Manpower — HRIS (Core Transaction 2)  
**Framework:** Laravel 12 + Inertia.js (v2) + React 18 + PostgreSQL / MySQL  
**Target Compliance:** Republic Act No. 10173 (Philippine Data Privacy Act of 2012), OWASP Top 10  
**Last Updated:** September 2026

---

## Table of Contents

1. [Executive Summary & Security Philosophy](#1-executive-summary--security-philosophy)
2. [Authentication & Credential Security](#2-authentication--credential-security)
3. [Session Management & Idle Inactivity Protection](#3-session-management--idle-inactivity-protection)
4. [HTTP Security Headers & Browser Hardening](#4-http-security-headers--browser-hardening)
5. [Authorization, RBAC & Sensitive Field Gating](#5-authorization-rbac--sensitive-field-gating)
6. [Rate Limiting & Anti-Brute Force Protections](#6-rate-limiting--anti-brute-force-protections)
7. [Comprehensive Audit Trail & Data Privacy Compliance (RA 10173)](#7-comprehensive-audit-trail--data-privacy-compliance-ra-10173)
8. [AI Document Scanner Security & Boundary Controls](#8-ai-document-scanner-security--boundary-controls)
9. [Core Framework Defenses (CSRF, XSS, SQLi)](#9-core-framework-defenses-csrf-xss-sqli)
10. [Security Verification & Testing Matrix](#10-security-verification--testing-matrix)
11. [Employee Information Management — Security Controls](#11-employee-information-management--security-controls)
12. [Leave and Absence — Security Controls](#12-leave-and-absence--security-controls)
13. [Payroll and Compensation — Security Controls](#13-payroll-and-compensation--security-controls)
14. [Performance Management — Security Controls](#14-performance-management--security-controls)
15. [Cross-Cutting Security, Backup & Incident Response](#15-cross-cutting-security-backup--incident-response)

---

## 1. Executive Summary & Security Philosophy

The PrimePower HRIS manages high-risk, legally protected personal and financial data:
* **Government Identifiers:** PhilSys (PSN), SSS, TIN, PhilHealth, Pag-IBIG.
* **Financial Data:** Basic salaries, daily allowances, loan amortizations, net take-home pay, and bank disbursement account numbers.
* **201 Personnel Files:** Digital scans and photographs of NBI Clearances, LTO Professional Driver’s Licenses, Police Clearances, and Medical Certificates.

To protect against external exploitation and internal insider threats, the application adheres to three core design rules:

1. **Defense-in-Depth:** Security is never left to a single layer. Access restrictions are validated at both the database query level (`scopedQuery`) and the policy layer (`Policy`).
2. **Stripping first, then masking:** Sensitive data is omitted from JSON payloads entirely for anyone not allowed to see it — never hidden via CSS or React state. For those who *are* allowed, the web screens still carry only the last four characters, and the full value is fetched one field at a time, logged, when somebody presses Show.
3. **Accountability (Read, Write, and Export Tracking):** It is not enough to prevent unauthorized changes. Under RA 10173, every read of a 201 file and every export of a workforce CSV leaves an immutable audit trail.

---

## 2. Authentication & Credential Security

The authentication subsystem is powered by **Laravel Fortify** and tightly configured in [`config/fortify.php`](file:///c:/Users/Gave/Herd/Core2/config/fortify.php).

### 2.1 Disabled Public Registration
* **Implementation:** `Features::registration()` is omitted in [`config/fortify.php`](file:///c:/Users/Gave/Herd/Core2/config/fortify.php#L191).
* **Rationale:** Public sign-up routes (`/register`) are disabled. Every user account must be provisioned directly by HR through an authorized employee record and assigned a vetted role.

### 2.2 Username Sign-In (no second factor)
* **Configuration:** People sign in with a **username and password** (`'username' => 'username'` in `config/fortify.php`). A company login does not depend on anybody's personal inbox, and the account's role decides what it can open.
* **Usernames look like company addresses but are not email:** the role logins are `admin@primepower.com`, `hrstaff@primepower.com` and `employee@primepower.com`, and others come from the name — Juan Dela Cruz becomes `jdelacruz@primepower.com`, with a number added if it is taken; an admin may type another on Settings → Users & Access, where every username is listed.
* **Accounts have no email.** There is no forgot-password link, no emailed reset and no email verification. An admin resets a forgotten password on Settings → Users & Access, and the person must replace that temporary password at their next sign-in.
* **Second factors were removed.** The authenticator-app 2FA and the emailed sign-in code are both gone, so the password is the only barrier. This is a known gap for a system holding salary and government identifiers, and it is the first control to restore before running with real employee data.

### 2.3 Strict Password Complexity & Breach Verification
Defined centrally in [`app/Providers/AppServiceProvider.php`](file:///c:/Users/Gave/Herd/Core2/app/Providers/AppServiceProvider.php#L91):
* **Length & Composition:** Minimum **12 characters**, requiring lowercase letters, uppercase letters, and numeric digits.
* **Breach Protection (`uncompromised()`):**
  In production, passwords submitted during creation, reset, or update are checked against the k-Anonymity range API of *Have I Been Pwned* (HIBP). Previously compromised passwords circulating on the dark web are refused.

### 2.4 Forced Password Change on First Sign-In
* **Middleware:** [`app/Http/Middleware/RequirePasswordChange.php`](file:///c:/Users/Gave/Herd/Core2/app/Http/Middleware/RequirePasswordChange.php).
* **Behavior:** When an account is provisioned with a generated temporary password, `must_change_password` is flagged as `true`.
* **Lockdown:** The user is redirected exclusively to `Settings → Security` (`/settings/security`) and blocked from accessing all HR modules, dashboards, and APIs until a custom password is saved.

---

## 3. Session Management & Idle Inactivity Protection

Shared desktop computers in operations hubs, dispatch bays, and HR offices present a high risk of session abandonment.

### 3.1 Server-Enforced Inactivity Expiry
* **Config:** [`config/session.php`](file:///c:/Users/Gave/Herd/Core2/config/session.php#L54).
* **Lifetime:** Set to **5 minutes** (`SESSION_LIFETIME=5`).
* **Enforcement:** Enforced server-side. Every request updates `last_activity`. Once the 10-minute idle threshold is breached, the session is invalidated immediately by Laravel's database session driver.

### 3.2 Intelligent Cross-Tab Client Monitor
* **Component:** [`resources/js/Components/layout/IdleTimeout.jsx`](file:///c:/Users/Gave/Herd/Core2/resources/js/Components/layout/IdleTimeout.jsx).
* **Intentional Activity Tracking:**
  The listener tracks deliberate user actions (`mousedown`, `keydown`, `scroll`, `touchstart`, `wheel`). Casual mouse movements (`mousemove`) are ignored so accidental jostling of a trackpad does not prevent session termination.
* **Cross-Tab Synchronization:**
  Timestamps are synced across all open browser tabs via `window.localStorage` (`primepower-last-activity`). Typing in one tab keeps other tabs informed.
* **Grace Warning Modal:**
  At **60 seconds remaining**, an alert modal appears with a live countdown and audio/visual cues, allowing active users to click "Stay signed in" or gracefully "Sign out now".
* **Keepalive Ping:**
  When a user is actively reading a long payslip or document without triggering full page reloads, an automated ping to `/session/keepalive` refreshes the backend session.

### 3.3 Cookie Flags
* **`HttpOnly = true`:** Prevents client-side scripts and browser extensions from accessing the session cookie via `document.cookie`.
* **`SameSite = lax`:** Restricts cross-site cookie transmission to mitigate CSRF.

---

## 4. HTTP Security Headers & Browser Hardening

Configured centrally in [`app/Http/Middleware/SecurityHeaders.php`](file:///c:/Users/Gave/Herd/Core2/app/Http/Middleware/SecurityHeaders.php) and appended to both `web` and `api` route pipelines in [`bootstrap/app.php`](file:///c:/Users/Gave/Herd/Core2/bootstrap/app.php#L30):

| Header | Production Directive | Threat Mitigated |
|---|---|---|
| **X-Frame-Options** | `DENY` | Clickjacking attacks attempting to trick HR into clicking hidden approval buttons inside an iframe. *(Bypassed in local development for IDE webviews).* |
| **Content-Security-Policy** | `frame-ancestors 'none'; object-src 'none'; base-uri 'self'` | Embedding attacks, malicious browser plugins, and `<base>` tag hijacking. |
| **X-Content-Type-Options** | `nosniff` | MIME-type confusion / sniffing; stops browsers from executing uploaded 201 images or text as JavaScript. |
| **Referrer-Policy** | `strict-origin-when-cross-origin` | URL leakage; prevents employee IDs or query parameters from being sent to external sites in referrer headers. |
| **Permissions-Policy** | `camera=(), microphone=(), geolocation=(), payment=()` | Disables unneeded hardware features across the entire domain. |
| **Strict-Transport-Security (HSTS)** | `max-age=31536000; includeSubDomains` | Man-in-the-middle (MITM) attacks; forces browsers to connect exclusively via HTTPS for a full year in production. |

---

## 5. Authorization, RBAC & Sensitive Field Gating

### 5.1 Role Hierarchy
The system supports four distinct operational roles ([`App\Models\User`](file:///c:/Users/Gave/Herd/Core2/app/Models/User.php)):
1. **`admin`:** Full system governance, configuration, audit inspection, and soft-delete restoration.
2. **`hr_staff`:** Day-to-day HR records management, payroll preparation, onboarding, and 201 document handling.
3. **`supervisor`:** Scoped oversight; limited strictly to direct subordinate reports.
4. **`employee`:** Self-service only; restricted to personal 201 data, attendance calendar, and payslips.

### 5.2 Scoped Queries & Laravel Policies
Every database request passes through a two-stage filter:
1. **List Filtering ([`scopedQuery()`](file:///c:/Users/Gave/Herd/Core2/app/Services/EmployeeService.php)):**
   Filters database `SELECT` statements based on `$user->role`. An `employee` role querying `/hr/employees` will only receive their own row from the database.
2. **Individual Record Validation ([12 Model Policies](file:///c:/Users/Gave/Herd/Core2/app/Policies/)):**
   Before any `show`, `update`, `delete`, or `restore` operation, policies such as [`EmployeePolicy`](file:///c:/Users/Gave/Herd/Core2/app/Policies/EmployeePolicy.php) and [`PayrollRunPolicy`](file:///c:/Users/Gave/Herd/Core2/app/Policies/PayrollRunPolicy.php) verify ownership and administrative authority.

### 5.3 Sensitive Field Stripping (`viewSensitive`)
* **Guarded Fields:** Basic Salary, Rate Type, SSS Number, TIN, PhilHealth Number, Pag-IBIG MID, and Bank Account Numbers.
* **Zero UI-Leakage:**
  Fields are not merely hidden with CSS or withheld in React components. They are omitted at the serialization layer in API Resources using conditional inclusion:
  ```php
  $this->mergeWhen($canSeeSensitive, [
      'tin' => $this->tin,
      'sss_number' => $this->sss_number,
      'bank_account' => $this->bank_account_number,
  ]);
  ```
  If `$user->can('viewSensitive', $employee)` evaluates to false, the keys do not exist in the HTTP payload.

### 5.4 Encryption at Rest (AES-256-CBC for Government Identifiers)
* **Migration:** [`database/migrations/2026_09_08_000001_encrypt_government_identifiers.php`](file:///c:/Users/Gave/Herd/Core2/database/migrations/2026_09_08_000001_encrypt_government_identifiers.php).
* **Model Casts ([`app/Models/Employee.php`](file:///c:/Users/Gave/Herd/Core2/app/Models/Employee.php#L298-L304)):**
  Government numbers and bank account numbers are encrypted at rest using AES-256-CBC encryption:
  * `sss_number` => `'encrypted'`
  * `philhealth_number` => `'encrypted'`
  * `pagibig_number` => `'encrypted'`
  * `tin` => `'encrypted'`
  * `bank_account_number` => `'encrypted'`
  * `drivers_license_number` => `'encrypted'`
* **Threat Model:** Even if an attacker or unauthorized internal party steals a raw SQL database backup/dump (`.sql`), the government IDs and bank accounts cannot be decrypted without the cryptographic `APP_KEY`.

### 5.5 Deletion Safeguards (Soft Deletion & Archive)
* Permanent database `DELETE` commands are prohibited for core master data (Employees, Clients).
* All records use Laravel's `SoftDeletes`.
* Restoring an archived employee is an administrative-only action protected by `EmployeePolicy::restore` and `EmployeePolicy::viewArchive`.

---

## 6. Rate Limiting & Anti-Brute Force Protections

To protect against credential guessing, denial-of-service, and automated data scraping:

### 6.1 Authentication Throttle
* **Web Login:** Throttled to **5 attempts per minute** per IP/email pair. Exceeding this threshold triggers a `Lockout` event and an audit log row.
* **API Login Route:** `POST /api/v1/login` is governed by `throttle:6,1` (maximum 6 attempts per minute).

### 6.2 Token Scrape Protection (`throttle:api`)
Configured in [`app/Providers/AppServiceProvider.php`](file:///c:/Users/Gave/Herd/Core2/app/Providers/AppServiceProvider.php#L60-L79) and applied to all [`routes/api.php`](file:///c:/Users/Gave/Herd/Core2/routes/api.php#L28) endpoints:
* **Hashed Token Tracking:** Rate limiting uses `token:hash('sha256', $bearer)` rather than user IDs. This ensures multiple biometric devices operating on the same service account have isolated quotas.
* **Ceiling:** **60 requests per minute** per token.
* **Unauthenticated Requests:** Limited to **20 requests per minute** per IP.

---

## 7. Comprehensive Audit Trail & Data Privacy Compliance (RA 10173)

Philippine Republic Act No. 10173 requires verifiable proof of how personal data is processed, accessed, modified, and extracted. The system maintains an immutable audit log in the `audit_logs` table.

```
                  ┌──────────────────────────────────────────────┐
                  │               audit_logs Table               │
                  └───────────────────────┬──────────────────────┘
                                          │
       ┌──────────────────────────────────┼──────────────────────────────────┐
       ▼                                  ▼                                  ▼
[ Auditable Trait ]           [ RecordAuthEvents ]                [ DataAccessLogger ]
- Model Create/Update/Delete  - Login / Logout                   - 201 File Preview / Download
- Before/After Diff           - Failed Attempt (Recorded Email)  - Bulk CSV / Alphalist Export
- Redacts Passwords & Tokens  - Lockout Events + IP/User-Agent   - Biometric Attendance Imports
```

### 7.1 Model Mutation Tracking ([`Auditable.php`](file:///c:/Users/Gave/Herd/Core2/app/Models/Concerns/Auditable.php))
* All major models implement the `Auditable` concern.
* Automatically records `created`, `updated`, and `deleted` events.
* **Credential Redaction:** Passwords and tokens are explicitly stripped from audit diffs:
  ```php
  $excluded = ['updated_at', 'created_at', 'remember_token', 'password'];
  ```

### 7.2 Authentication Event Tracking ([`RecordAuthenticationEvents.php`](file:///c:/Users/Gave/Herd/Core2/app/Listeners/RecordAuthenticationEvents.php))
* Records all `login`, `logout`, `login_failed`, and `lockout` events.
* Captures Actor ID, Target Email, Timestamp, IP Address, and Browser User-Agent.
* Passwords submitted during failed attempts are never captured; only the attempted username/email is retained to detect targeted credential stuffing.

### 7.3 Data Access & Extraction Logging ([`DataAccessLogger.php`](file:///c:/Users/Gave/Herd/Core2/app/Services/DataAccessLogger.php))
While standard systems only log data modifications, PrimePower logs **reads and exports**:
1. **`accessed`:** Logged whenever an HR staff member previews or downloads a 201 digital document (e.g., PhilSys ID, NBI clearance photo). Distinguishes between `preview` (viewed in browser) and `download` (stored locally on client machine).
2. **`exported`:** Logged whenever a mass report (e.g., BIR Form 2316 Alphalist, SSS Remittance file, Payroll Register CSV) leaves the system. Records the report type, filters applied, and requesting administrator.
3. **`imported`:** Logged when batch files (such as biometric device logs) are loaded, capturing the volume of rows accepted and rejected.

---

## 8. AI Document Scanner Security & Boundary Controls

Module 1 features an automated OCR and classification scanner for employee 201 documents ([`DocumentScanner`](file:///c:/Users/Gave/Herd/Core2/CLAUDE.md#L474)).

1. **Zero Direct-to-Database Writes:** The AI model acts strictly as a data suggestion engine on the upload form. A human HR officer must review, modify if necessary, and submit the form.
2. **Untrusted Input Validation:** Everything extracted by the vision model is treated as untrusted user input:
   * Document types are validated against an allowlist enum (`EmployeeDocument::TYPES`).
   * Dates are re-parsed strictly using Carbon.
   * Identity matches are validated via deterministic PHP logic, not prompt self-reporting.
3. **Data Privacy & Cross-Border Protections:**
   * When using hosted brokers (e.g., OpenRouter), the application sends `provider.data_collection: deny` in every request payload to prevent upstream AI providers from storing or training on uploaded ID cards and clearances.

---

## 9. Core Framework Defenses (CSRF, XSS, SQLi)

* **Cross-Site Request Forgery (CSRF):**
  Handled transparently by Laravel's `ValidateCsrfToken` middleware. Every state-changing Inertia request carries an encrypted CSRF token in headers.
* **SQL Injection Prevention:**
  Database interactions exclusively utilize the Eloquent ORM and Laravel Query Builder, which bind all parameters via PDO prepared statements. Raw concatenations in SQL queries are strictly avoided.
* **Cross-Site Scripting (XSS):**
  Frontend views use React JSX and Blade templates, both of which automatically sanitize and HTML-entity-encode variable outputs before rendering into the DOM.

---

## 10. Security Verification & Testing Matrix

To verify that all security policies remain active across deployments, run the automated test suite:

```bash
# Run full automated test suite
php artisan test

# Run access-control, policy, and API security tests specifically
php artisan test --filter=AccessControl
php artisan test --filter=SecurityHeaders
php artisan test --filter=AuditLog
```

### Key Checklist for Production Deployment:
* [ ] Verify `APP_DEBUG=false` in `.env`.
* [ ] Ensure `APP_ENV=production`.
* [ ] Verify TLS certificate is installed and `APP_URL=https://...`.
* [ ] Confirm `SESSION_SECURE_COOKIE=true` and `SESSION_SAME_SITE=lax`.
* [ ] Ensure `SESSION_DRIVER=database` and database migrations have run.
* [ ] Confirm `SESSION_ENCRYPT=true` (the default) — session rows carry flash messages, including temporary passwords.

---

## 11. Employee Information Management — Security Controls

Employee Information is the central record every other module reads: personal details, employment, government numbers, bank account, education and emergency contacts. Most of it is *sensitive personal information* under RA 10173. The controls below are what protects it, each with where it lives and what tests it.

| # | Control | Status | Where |
|---|---|---|---|
| 1 | Role-based access control | ✅ In place | `EmployeePolicy`, `EmployeeService::scopedQuery()` |
| 2 | Field-level encryption & data masking | ✅ In place | `Employee` encrypted casts, `Employee::mask()`, `EmployeeController::reveal()` |
| 3 | Encryption at rest (AES-256) and in transit (TLS) | ✅ In place | `config/app.php` cipher, `SESSION_ENCRYPT`, HSTS in `SecurityHeaders` |
| 4 | Audit trail — who viewed, who edited, when | ✅ In place | `Auditable`, `DataAccessLogger`, `RecordAuthenticationEvents` |
| 5 | Multi-factor authentication for HR admins | ⏸ Not enabled | Deliberately deferred — see below |
| 6 | Data minimization | ✅ In place | Optional fields, directory/API field lists |
| 7 | Input validation & sanitization | ✅ In place | Form Requests, Eloquent bindings, React escaping |
| 8 | Access de-provisioning | ✅ In place | `Employee::booted()`, `User::booted()`, `EnsureAccountIsActive`, Fortify `authenticateUsing` |
| 9 | Data Privacy Act (RA 10173) compliance | ✅ In place | Privacy notice, read trail, encryption, retention |

Tests: `tests/Feature/Security/EmployeeInformationSecurityTest.php` covers 2, 4, 8 and 9 end to end; the access rules in 1 are covered across `tests/Feature/HR/`.

### 11.1 Role-Based Access Control
* **Admin / HR Staff** — every record, create and edit. Only an admin archives.
* **Supervisor** — view-only, for their own record and their direct reports. Salary, bank account and government numbers are withheld (`viewSensitive`). Opening a report's edit form answers 403.
* **Employee** — view-only, their own record.

The list is narrowed in `scopedQuery()` and each record is checked by the policy, so an id typed into the URL decides nothing on its own.

### 11.2 Field-Level Encryption & Data Masking
* SSS, PhilHealth, Pag-IBIG, TIN, bank account and driver's licence numbers are **encrypted columns** (AES-256-CBC under `APP_KEY`), unreadable in a database dump.
* On screen they are **masked to the last four characters** — `••••••5678` — on the record page and in the employee list payload. A fixed run of dots is used so the mask does not reveal the number's length.
* **Show** fetches one number in full from `POST /hr/employees/{id}/reveal`: the same gate that decided the masked value could be drawn (`viewSensitive`, or `view` for the licence), a `no-store` response, throttled to 30 a minute, and **an `accessed` audit row naming the field**. The number hides itself again after 30 seconds.
* The HR edit form still receives full values (it has to), and opening it is logged as a read.
* The REST API is unchanged: integrations that already hold `viewSensitive` receive full values, and reading a record over the API is logged.

### 11.3 Encryption at Rest and in Transit
* **At rest:** the encrypted columns above; **session rows encrypted** (`SESSION_ENCRYPT`, on by default) because they carry flash messages such as temporary passwords; 201-file documents on the private disk, served only through a policy-checked route.
* **In transit:** HTTPS only in production, `Strict-Transport-Security` sent over TLS, secure-only session cookies (`SESSION_SECURE_COOKIE=true`).

### 11.4 Audit Trail
Every entry records the user, IP address, browser and time, in `audit_logs`, and HR reads it on Settings → Security.
* **Edits** — `created` / `updated` / `deleted` with the before and after values (`Auditable`).
* **Views** — `accessed` when somebody opens another person's record (`view`), its edit form (`edit_form`), a record over the API (`api_view`), a document (`preview` / `download`), or a hidden number (`reveal` + the field). A person opening their own record is not logged.
* **Exports and imports** — `exported` / `imported`.
* **Sign-ins** — `login`, `logout`, `login_failed` (including refused deactivated accounts), `lockout`.
* **Privacy notice** — `privacy_acknowledged` with the notice version.

### 11.5 Multi-Factor Authentication — deferred
Not enabled at the owner's request for now. Login accounts have no email address, so a second factor would be an authenticator app (TOTP). This is a **known gap** for admin and HR accounts, which can see every record; the mitigations in place are the password policy, forced password change on provisioned accounts, sign-in throttling, the 10-minute idle timeout and the sign-in audit trail. It is the first control to add before real employee data goes in.

### 11.6 Data Minimization
* Only what employment, payroll and statutory filing need is required; religion, blood type, secondary phone and similar fields are optional.
* Each audience gets only the fields it needs: the colleague directory carries name, position, department and work contact only; the Fleet `DriverResource` carries licence facts and no salary or government numbers; dashboards and analytics return counts, never people.
* A login account holds no email address.

### 11.7 Input Validation & Sanitization
* Every write goes through a Form Request with explicit rules (formats, lengths, allowed values, `exists` checks), so unknown fields are dropped rather than stored.
* Queries use Eloquent and the query builder with bound parameters. The only raw SQL fragments are built from code constants, never from request input; search terms escape `%`.
* React and Blade escape output, so stored text cannot run as script. Uploaded photos are re-encoded to strip anything hidden inside them.

### 11.8 Access De-provisioning
* **Leaving switches the login off automatically.** When an employee's employment status becomes resigned or terminated, or their status becomes inactive — from the employee form, the API, a released separation, or archiving — their login is deactivated (`Employee::booted()`).
* **Deactivating ends every session at once:** API tokens are deleted, database sessions are dropped, any other open session is signed out on its next request (`EnsureAccountIsActive`), a surviving token is refused by Sanctum, and the login screen refuses the account.
* **A role change takes effect on the next request**, because permissions are read from the account every time rather than from the session or token.
* Turning a login back on is always a deliberate act: Users & Access, or restoring the employee from the archive.

### 11.9 Data Privacy Act (RA 10173)
* **Privacy notice before first use.** Every signed-in person reads what is collected, why, who can see it, how it is protected, where scans are sent (named only when the document scanner is switched on), how long it is kept and their rights — and ticks "I have read and understood" before continuing. The version and date are stored on the account and in the audit log. Changing `config('privacy.notice_version')` shows it to everybody again. It can be re-read from Settings → Security.
* **Accountability:** the read trail in 11.4, so "who looked at my record" has an answer.
* **Security of processing:** encryption, masking, role-based access and de-provisioning above.
* **Retention:** employment records at least 3 years, payroll at least 10; records are archived, never deleted on a timer.
* **Contact:** set `PRIVACY_DPO_NAME` and `PRIVACY_DPO_EMAIL`, or the company email on Settings → General is shown instead.

---

## 12. Leave and Absence — Security Controls

| # | Control | Status | Where |
|---|---|---|---|
| 1 | Workflow-enforced approval | ✅ In place (one step, by design) | `LeaveRequestPolicy::decide()` |
| 2 | Segregation of duties | ✅ Strengthened | `LeaveRequestPolicy::isOwn()`, `LeaveBalanceController::update()` |
| 3 | Access control | ✅ In place | `LeaveService::scopedQuery()`, `LeaveRequestPolicy` |
| 4 | Audit trail | ✅ In place | `LeaveRequest`, `LeaveBalance`, `LeaveType` are `Auditable` |
| 5 | Data validation / no negative balance | ✅ Strengthened | `StoreLeaveRequestRequest`, `LeaveService::creditShortfall()` |
| 6 | Secure integration with payroll | ✅ Not a network exchange | see 12.6 |

### 12.1 Workflow-enforced approval
Employees file; only HR or an administrator decides, and the system — not the screen — enforces it: the decision routes check `decide`, which requires the request to still be pending and the decider to be HR. **The chain is one step (employee → HR) on purpose.** It used to be two (employee → supervisor → HR), and the supervisor's step moved no credits and decided nothing, so it only added delay. Supervisors still *see* their reports' leave. There is no way to mark leave approved except through the decision route.

### 12.2 Segregation of duties
* **Nobody decides their own leave**, HR and admins included — another HR user must.
* **New: nobody adjusts their own leave credits.** The HR user who approves leave cannot top up their own balance on the Balances screen; another HR user or an administrator has to.

### 12.3 Access control
Employees see and cancel only their own requests; supervisors see their direct reports'; HR sees all. Attachments are on the private disk and download only after the same check.

### 12.4 Audit trail
Filing, approval, rejection, cancellation and every balance change are written to the audit log with who, what (before and after) and when. Each entry is signed (see 15.2).

### 12.5 Data validation
* A request is refused at filing if it asks for more days than remain — and days already reserved by other pending requests count as used, so the same credit cannot be filed twice.
* **New: credits are checked again at approval.** A balance can shrink between filing and approval; approving past it is refused rather than leaving a negative balance.
* **New: a balance cannot be edited below the days already used.**
* Days are counted from the calendar (rest days and holidays excluded), never typed in.

### 12.6 Integration with payroll
Leave and payroll are parts of **one application with one database**: payroll reads approved unpaid leave by calling the leave service in the same process. No leave data travels over a network between them, so there is no exchange to encrypt. What does travel is protected: the browser connection is HTTPS, and the database connection uses TLS when the host supports it (`DB_SSLMODE`, default `prefer`; set `require` if the database provider offers TLS).

---

## 13. Payroll and Compensation — Security Controls

| # | Control | Status | Where |
|---|---|---|---|
| 1 | Encryption (AES-256) of bank accounts | ✅ In place | encrypted casts on `Employee` |
| 1b | Encryption of salary figures | ⚠ Not column-encrypted — see 13.1 | |
| 2 | Strict RBAC | ✅ In place | `PayrollRunPolicy`, `PayslipPolicy` |
| 3 | Segregation of duties | ✅ Strengthened | `PayrollRunPolicy::approve()`, `markPaid()` |
| 4 | Multi-level approval | ✅ In place | draft → for approval → approved → paid |
| 5 | Secure file transfer to banks (SFTP) | ➖ Not applicable — see 13.5 | |
| 6 | Tamper-evident audit logs | ✅ **New** | `AuditLogSigner`, `audit:verify` |
| 7 | Data masking in reports | ✅ **New** | payslip, compliance screen |
| 8 | Reconciliation / anomaly detection | ✅ **New** + existing | `PayrollAnomalyScanner`, `PayrollReadinessChecker` |
| 9 | Long-term backup | 📋 Procedure — see 15.4 | |

### 13.1 Encryption
Bank account numbers and government numbers are AES-256 encrypted columns. **Salary and payslip amounts are not**, deliberately: payroll totals, compliance remittances, 13th-month pay and the Finance journal are sums computed by the database, and an encrypted number cannot be added up. Encrypting them would mean pulling every payslip into memory for every report. They are protected instead by access control (only HR, admins and the employee concerned), by the database server's own storage encryption at the host, and by the audit trail.

### 13.2 Strict RBAC
Payroll runs, salaries, compliance reports and 13th-month screens are open to HR and administrators only; supervisors are shut out entirely. Employees see their own payslips, and only once a run is approved.

### 13.3 Segregation of duties — three hands
1. **HR computes and submits** the run (`processed_by`).
2. **An administrator approves it — never the person who computed it.**
3. **New: marking the run paid (confirming the bank transfer) can never be done by the person who computed it.** Before, the processor could also confirm their own run was disbursed.

When Finance confirms disbursement over the API, the credited amount must match the run's net total to the centavo, or nothing is written.

### 13.4 Multi-level approval
A run passes draft → for approval → approved → paid, and cannot skip a step. Approval is the point of no return: loans amortise and the figures lock.

### 13.5 Secure file transfer to banks
This system does not send files to banks. It hands the approved payroll register to the Financial Management system over the authenticated, rate-limited HTTPS API, and Finance makes the transfer. SFTP to a bank belongs in that system. If a bank file is ever produced here, it should be delivered over SFTP or the bank's HTTPS portal, never by email.

### 13.6 Tamper-evident audit logs
See 15.2. Every change to a payroll run, salary adjustment, loan, allowance and payroll adjustment is logged and signed, so an auditor from BIR, SSS, PhilHealth or Pag-IBIG can be shown the log *and* shown it has not been altered.

### 13.7 Data masking in reports
* **Payslips** show government numbers and the bank account as their last four characters. Payslips are printed and shared as PDFs.
* **The Compliance screen** masks each employee's SSS / PhilHealth / Pag-IBIG / TIN number. The **CSV export keeps full numbers**, because the agency portals reject a filing without them, and every export is itself logged.
* The Finance API register still carries the full account number to tokens allowed to see it, because Finance has to pay into it.

### 13.8 Reconciliation and anomaly detection
* **Before computing** (existing): missing time-outs, undecided overtime, employees without attendance, pay below the regional minimum, unserved suspensions.
* **New — after computing, before approval**: the run screen checks the money itself:
  * **two or more employees paid into the same bank account** (the classic ghost employee);
  * **somebody paid who has resigned, been terminated, archived, or separated** before the period;
  * **somebody paid for a period before their hire date**;
  * **a payslip whose net pay is not gross minus deductions** (a figure edited outside the calculator);
  * zero or negative net pay, and net pay that moved more than 50% since the person's last paid run.
* **At posting** (existing): the Finance journal checks debits equal credits, and a disbursement must match the run total.

Findings warn the approver; they do not block, because the approver may have a legitimate reason and the decision is theirs.

---

## 14. Performance Management — Security Controls

| # | Control | Status | Where |
|---|---|---|---|
| 1 | Role-based visibility | ✅ In place | `PerformanceService::scopedQuery()`, `PerformanceReviewPolicy` |
| 2 | Confidentiality of 360 feedback | ✅ **New** | `PerformanceController::reviewerName()` |
| 3 | Record locking after sign-off | ✅ In place | `PerformanceReview::isEditable()` |
| 4 | Audit trail of ratings | ✅ Strengthened | `PerformanceReview`, **`PerformanceReviewRating`**, `EmployeeKpi` are `Auditable` |
| 5 | Access review | ✅ **New** | Settings → Users & Access |

### 14.1 Role-based visibility
An employee sees reviews of themselves (once submitted) and the reviews they write; a supervisor sees the reviews they are assigned to write for their reports; HR sees everything.

### 14.2 Confidentiality of 360 feedback
**New:** for **peer** and **subordinate** reviews, the reviewer's name is replaced with "Anonymous peer" / "Anonymous subordinate" for everybody except HR and the reviewer themselves. Feedback on a manager from their own team is only honest if the manager cannot see who wrote it. HR keeps the name to deal with abuse. Self and supervisor reviews stay named.

### 14.3 Record locking after sign-off
Only the assigned reviewer can edit, only while the cycle is open, and only while the review is a draft. Once submitted it is read-only; once the employee acknowledges it, it is final. There is no edit path after that.

### 14.4 Audit trail
**New:** individual KPI ratings are now audited as well as the review itself, so a score changed while the review was a draft still leaves a record of the old and new value.

### 14.5 Access review
**New:** Settings → Users & Access shows each account's **last sign-in**, marks **active accounts unused for 90 days**, counts who holds full access (Administrator, HR Staff), and has a **Record review** button. Recording a review writes who reviewed and when to the audit log; the screen shows "Review due" when the last review is over 90 days old. This is the regular check that the people who can read finalized appraisals, payroll and 201 files are still the right people.

---

## 15. Cross-Cutting Security, Backup & Incident Response

| Control | Status |
|---|---|
| Strong password policy | ✅ In place — at least 12 characters, upper and lower case, a number, breached-password check in production, forced change of provisioned passwords |
| MFA | ⏸ Deferred at the owner's request — known gap (see 11.5) |
| Authorization (RBAC with attribute checks) | ✅ In place — role, plus ownership and "direct report of" checks in every policy |
| Encryption at rest and in transit | ✅ In place — see 11.3 |
| Comprehensive audit logging (who, what, when, where) | ✅ In place — user, before/after values, time, IP and browser; **now tamper-evident** |
| Session management | ✅ In place — 10-minute idle sign-out, encrypted database sessions, secure cookies |
| Secure API | ✅ In place — expiring tokens, 60 requests/minute per token, validated input, deactivated accounts refused |
| Backup & disaster recovery | 📋 Procedure below |
| RA 10173 / NPC | ✅ Privacy notice, read trail, breach procedure below |
| Vulnerability assessment | ✅ Dependency scan clean; schedule below |
| Security awareness training | 📋 Outline below — not a software control |

### 15.1 Authorization
Roles decide the screen; attributes decide the row. Every policy combines the role with facts about the record — whose it is, whose direct report it is, what state it is in (a draft, an approved run) — which is attribute-based checking inside a role model.

### 15.2 Tamper-evident audit log
Every audit entry is signed with HMAC-SHA256 when it is written, using a key derived from `APP_KEY`. **Verify** on Settings → Security (or `php artisan audit:verify`) re-checks every entry and names any that were changed. Entries that existed before signing was added were signed at upgrade.
* Detects: an entry edited in the database by someone without the application key.
* Reports as a question: missing entry ids (a deletion, or a save that was cancelled).
* Does not stop: someone holding both the database and `APP_KEY`. Keep `APP_KEY` out of the repository and out of chat. Changing `APP_KEY` invalidates every signature (and every encrypted number), so it must never be rotated casually.

### 15.3 Vulnerability assessment
* Run `composer audit` (backend) and `npm audit --omit=dev` (frontend) before every deployment and monthly. **Both reported no known vulnerabilities on 2026-09-15.**
* The full test suite (`npm.cmd run check`) includes access-control, masking, de-provisioning, segregation-of-duties and audit-integrity tests; it must pass before deploying.
* Before real employee data goes in, have an independent tester run an OWASP Top 10 assessment against a staging copy, and repeat yearly or after major changes.

### 15.4 Backup and disaster recovery
The application does not back itself up — the database does. Procedure:
1. **Automatic:** turn on the hosting provider's daily database backups (Hostforge database settings), with at least 30 days kept.
2. **Monthly off-site copy:** from an admin's computer, `pg_dump --format=custom` of the production database, encrypted (e.g. a password-protected 7-Zip with AES-256) and stored somewhere other than the host.
3. **Keep with it, separately:** the `APP_KEY`. Without it every government number, bank account and audit signature in the backup is unreadable.
4. **Retention:** payroll records at least **10 years** (NIRC), employment records at least **3 years** (Labor Code). Keep yearly copies for that long.
5. **Test a restore every quarter** into a scratch database, and run `php artisan audit:verify` against it.
6. **Recovery targets:** lose at most one day of data (RPO 24h); be running again within one working day (RTO 8h).

### 15.5 Personal data breach response (RA 10173, NPC Circular 16-03)
1. **Contain:** deactivate the affected accounts (Users & Access), revoke API tokens, rotate passwords.
2. **Preserve evidence:** run `audit:verify`, export the audit log, take a database backup before changing anything else.
3. **Assess:** what personal data, whose, how many people, and whether it can be used for identity fraud (government numbers and bank accounts can).
4. **Notify within 72 hours** of discovery: the National Privacy Commission, and the affected employees, when sensitive personal information is involved and there is a real risk of harm.
5. **Record:** what happened, what was done, and what changed afterwards — kept even if notification was not required.

### 15.6 Security awareness training (for users of the system)
A short session for every account holder when their account is created, repeated yearly:
* Never share your password; HR will never ask for it. Change a password someone else gave you immediately (the system forces this).
* Lock or sign out when you leave your desk — the system signs you out after 5 minutes, but a locked screen is instant.
* Press **Show** only when you need a full number, and never copy government numbers or bank accounts into chat or email; every Show is recorded.
* Report anything odd — an unknown sign-in, a record changed that you did not change — to HR or the administrator the same day.
* For HR and admins: review access every quarter (14.5), and treat the payroll anomaly panel as a stop-and-check, not a formality.
