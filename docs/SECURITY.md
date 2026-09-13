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

---

## 1. Executive Summary & Security Philosophy

The PrimePower HRIS manages high-risk, legally protected personal and financial data:
* **Government Identifiers:** PhilSys (PSN), SSS, TIN, PhilHealth, Pag-IBIG.
* **Financial Data:** Basic salaries, daily allowances, loan amortizations, net take-home pay, and bank disbursement account numbers.
* **201 Personnel Files:** Digital scans and photographs of NBI Clearances, LTO Professional Driver’s Licenses, Police Clearances, and Medical Certificates.

To protect against external exploitation and internal insider threats, the application adheres to three core design rules:

1. **Defense-in-Depth:** Security is never left to a single layer. Access restrictions are validated at both the database query level (`scopedQuery`) and the policy layer (`Policy`).
2. **Field-Level Stripping Over UI Masking:** Sensitive data is omitted from JSON payloads and API responses entirely rather than hidden via CSS or React state.
3. **Accountability (Read, Write, and Export Tracking):** It is not enough to prevent unauthorized changes. Under RA 10173, every read of a 201 file and every export of a workforce CSV leaves an immutable audit trail.

---

## 2. Authentication & Credential Security

The authentication subsystem is powered by **Laravel Fortify** and tightly configured in [`config/fortify.php`](file:///c:/Users/Gave/Herd/Core2/config/fortify.php).

### 2.1 Disabled Public Registration
* **Implementation:** `Features::registration()` is omitted in [`config/fortify.php`](file:///c:/Users/Gave/Herd/Core2/config/fortify.php#L191).
* **Rationale:** Public sign-up routes (`/register`) are disabled. Every user account must be provisioned directly by HR through an authorized employee record and assigned a vetted role.

### 2.2 Two-Factor Authentication (2FA / TOTP)
* **Configuration:** Time-based One-Time Password (TOTP) is implemented via Fortify.
* **Re-Authentication Guard (`confirmPassword: true`):**
  Enabling or disabling 2FA forces a password re-entry. This prevents an unauthorized actor from turning off 2FA if an authorized administrator leaves their computer physically unlocked.
* **Session Persistence:** Once confirmed during login, the 2FA status is trusted for the lifetime of that session, avoiding mid-session fatigue.

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
* **Lifetime:** Set to **10 minutes** (`SESSION_LIFETIME=10`).
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
