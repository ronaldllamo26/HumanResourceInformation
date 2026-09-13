# PrimePower Manpower — HRIS

A Human Resource Information System for a fleet & transportation manpower
company, covering the full employee lifecycle: **Employee Information,
Timekeeping & Attendance, Leave & Absence, Payroll & Compensation, and
Performance Management.**

## Tech Stack

| Category | Technology | Version |
|---|---|---|
| **Programming Language** | TypeScript & PHP | TS ^7.0, PHP ^8.2 |
| **Frontend** | ReactJS with Inertia.js | React ^18.2, Inertia ^2.0 |
| **Styling** | Tailwind CSS | ^3.2 |
| **Backend** | Laravel | ^12.0 |
| **Database** | PostgreSQL | — |
| **Build Tool** | Vite | ^7.0 |
| **API** | RESTful (`/api/v1`) | Laravel Sanctum ^4.0 (Token-Based) |
| **Authentication (Web)** | Laravel Fortify | ^1.39 (Session/Cookie-Based, username + password) |
| **Authentication (API)** | Laravel Sanctum | ^4.0 (Token-Based) |
| **Routing (JS)** | Ziggy | ^2.0 |
| **Version Control** | Git / GitHub | — |
| **CI/CD** | GitHub Actions | Tests on push/PR to `main` |
| **DevOps** | Hostforge | Domain / hosting for the HRIS (one PHP host) |
| **DevOps** | Vercel | Hosting for the public landing page (`landing/`) only |
| **Local Dev Server** | Laravel Herd | — |

**The HRIS and the landing page are two deployments, and only the landing page
is on Vercel.** The HRIS cannot be: its React pages are Inertia pages that a
Laravel controller renders and fills with data in the same request, so there
is no standalone frontend bundle to host anywhere. `landing/` is a genuinely
separate static React site — its own `package.json`, its own build, no
database — joined to the HRIS by one plain link. See
[landing/README.md](landing/README.md).

- **Inertia.js** is the bridge between Laravel and React — server-driven
  routing, no separate SPA API needed. Inertia renders pages *and* a
  token-authenticated REST API lives at `/api/v1`; both call the same Service
  layer so behaviour can't drift between them
- **Tailwind CSS** — every colour is a semantic design token, so light and dark
  mode both work without per-component overrides
- **Vite** — asset bundling and HMR for development
- **Ziggy** — exposes Laravel named routes to the JS frontend via `route()`

## Architecture

**Each stack owns its folder and its own dependencies** — composer in
`backend/`, npm in `frontend/`, nothing shared at the root:

```
core2/
├── backend/                    ← PHP / Laravel
│   ├── composer.json, vendor/      the backend's dependencies
│   ├── app/, routes/, database/, config/
│   ├── resources/views/            Blade — the shell Inertia renders into
│   └── public/                     the web root; Vite's output lands here
│
├── frontend/                   ← JavaScript / React
│   ├── package.json, node_modules/ the frontend's dependencies
│   ├── vite.config.js, tailwind.config.js, tsconfig.json
│   └── resources/js/, resources/css/
│
├── landing/                    ← separate again: the public landing page
│                                  (own package.json, deployed to Vercel)
└── docs/, README.md, CLAUDE.md
```

**The split is of dependencies, not of deployment.** The two halves are still
one Inertia app: `frontend/` builds and writes its output into
`backend/public/build`, and `backend/resources/views/app.blade.php` reads it
from there through `@vite(['resources/js/app.jsx', …])`. Three consequences
worth knowing before editing config:

- **Vite emits across the split.** `frontend/vite.config.js` sets
  `publicDirectory: '../backend/public'`. The JS deliberately stayed at
  `frontend/resources/js/` rather than being renamed to `src/`, so the
  manifest keys remain `resources/js/…` and the Blade needed no change —
  renaming it would rewrite all 211 page entries in the manifest.
- **Tailwind scans across the split.** Three of the four globs in
  `frontend/tailwind.config.js` point into `../backend/` — the Blade shell and
  Laravel's paginator views are markup too, and dropping them silently removes
  those classes from the stylesheet.
- **`backend/config/inertia.php` is published for one key.** `page_paths` has
  to point at `../frontend/resources/js/Pages`, or `assertInertia` fails 37
  tests looking for components in the wrong folder.

**What is *not* separated is the deployment** — this ships as one app, not
two. Inertia.js is why: a Laravel controller calls
`Inertia::render('HR/Employees/Show', [...])` and Laravel hands the browser
the matching React page pre-loaded with that data, in one request. There is
no separate frontend server calling a JSON API to render a screen — the
`/api/v1` routes above exist for *other systems* integrating with Core 2 (see
[docs/INTEGRATION.md](docs/INTEGRATION.md)), not for this frontend to call
itself.

That is also why there is one deploy, not two: `cd frontend && npm run build`
compiles the JSX into static files under `backend/public/build/`, and from
that point on Node is not needed on the server at all — Laravel just serves
those compiled files like any other static asset. One PHP host, one domain,
with **`backend/public` as the document root** (not `public/`, since the
split).

### Request flow (inside the backend folder)

```
Request ─┬─ Http/Controllers/EmployeeController      (Inertia -> Pages/…)
         └─ Http/Controllers/Api/EmployeeController  (JSON  -> Resources)
                              │
                       Services/EmployeeService      ← all business logic
                              │
                          Models/…                   ← Auditable, scopes
```

Controllers stay thin — authorize, delegate, respond. Business rules that
need to be unit-tested in isolation (attendance calculation, statutory
contributions, payroll arithmetic, performance scoring, DTR anomaly
detection) live in dedicated, database-free calculator/scanner classes.
Policy-tunable values (statutory rates, exception thresholds, credential
renewal windows) live in `config/`, not in code.

## Roles

Four roles, enforced in two places that must agree — `scopedQuery()` narrows
what a *list* returns, and each model's Policy guards an *individual* record:

| Role | Sees |
|---|---|
| `admin` | Every record; only admin may archive or approve payroll |
| `hr_staff` | Every record; provisions logins, processes payroll (never approves their own run) |
| `supervisor` | Own record + direct reports; endorses leave and overtime |
| `employee` | Own record only — self-service |

Self-registration is disabled by design; HR provisions logins from the
employee form.

## Documentation

- **[Integration Guide](docs/INTEGRATION.md)** — REST API specification, tokens, and endpoints for external ISMERS modules (Core 1, Core 3, Fleet).
- **[Security Architecture](docs/SECURITY.md)** — Comprehensive security documentation covering authentication, session protection, HTTP headers, RBAC, audit logs, and RA 10173 compliance.
- **[Deployment Guide](docs/DEPLOYMENT.md)** — step-by-step for Hostforge, in the order you actually do it, with a way to verify each step. Start with the document root: pointed at the repository instead of `backend/public`, the site serves `.env` to anyone who asks for it.

## Getting started

Requires PHP 8.2+, Composer, Node 18+, and PostgreSQL.

```bash
git clone <this-repo>
cd Core2

composer install
npm install

cp .env.example .env
php artisan key:generate
```

Edit `.env` — switch `DB_CONNECTION` to `pgsql` and fill in real credentials
(a fresh clone defaults to SQLite so it boots with zero setup, but this
project is built and tested against Postgres):

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=primepower_hris
DB_USERNAME=postgres
DB_PASSWORD=
```

```bash
php artisan migrate --seed
npm run build
php artisan serve
```

Seed accounts (password `password`): `admin@primepower.test`,
`hr@primepower.test`.

## Testing

```bash
php artisan test          # SQLite, in-memory — fast, runs anywhere
composer test:pgsql       # same suite against real Postgres
```

`composer test:pgsql` catches driver-specific bugs (raw SQL, operators like
`ILIKE`) that an in-memory SQLite run can't see. It targets a
`primepower_hris_test` database using the same host/credentials as `.env`.

## Formatting

```bash
vendor/bin/pint        # PHP
npm run format          # JS/JSX
npm run check           # everything
```

## Known gaps

13th-month pay and final-pay computation; peer and subordinate performance
reviews (schema and scoring support them, but rollout only creates self and
supervisor evaluations); a holidays management screen (2026 holidays are
seeded, no UI yet); leave credit accrual on a schedule (currently allocated in
bulk per year); email notifications (in-app only, via the topbar bell and
credential-expiry indicator).
