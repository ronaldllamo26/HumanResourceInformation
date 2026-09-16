# PrimePower Manpower — HRIS (Core Transaction 2)

Fleet & Transportation HRIS. **All five modules are built**: Employee
Information, Time & Attendance (rebuilt — see its section), Leave & Absence,
Payroll & Compensation, and Performance Management.

## PrimePower is a manpower agency, not a single employer

This shapes Modules 1 and 4 and is easy to miss from the schema alone. The
workforce splits two ways, on `employees.employment_category`:

- **`internal`** — the staff who run the agency: HR, admin, accounting, plus
  the drivers crewing PrimePower's own vehicles. Filed against a department.
- **`external`** — deployed to one of the **client companies** in `clients`.
  Still PrimePower's employees, on PrimePower's payroll and contributions, but
  the client is who they report to and who is billed for them.

- **A client is master data** (`/hr/clients`), reusing the
  `manageOrganization` gate — in an agency a client *is* org structure, and the
  people allowed to shape one are the people allowed to shape the other.
  Anything with staff filed against it is deactivated, never deleted, so
  payroll and attendance keep the client they were filed under.
  - **It has sat in a sidebar group of its own, and now sits inside Employee
    Information beside Positions instead — the second answer, tried and
    reverted.** The argument for a separate `Client Management` group was
    real: sharing `manageOrganization` with the org screens is a fact about
    the *table*, while the dropdown Clients sat in is about a **person** — who
    was hired, who works here, what job they hold — and a client is not a
    person, it is who the person is sent to and who is billed for them.
    Nothing in that reasoning was wrong. It moved back anyway, because a
    heading of its own is a cost every visitor pays — one more row in the
    sidebar's rhythm — for a benefit that only the two roles who open Clients
    at all ever collect. Filed at the end of Employee Information's children,
    after Positions, so it reads as the last of the org-structure screens
    rather than folded into the middle of them.
- **`client_id` is prohibited on internal staff**, not merely ignored. A stale
  client left on someone brought in-house keeps them in that client's billing
  and headcount — an error nobody would think to go looking for.
- **Deployment is a single `client_id`, with no history.** A move between
  clients therefore rewrites which client a *past* payslip is grouped under.
  Taken deliberately for a workforce that does not move often; the upgrade is a
  `deployments` table with start/end dates and a `clientAsOf()` read, the same
  shape as `SalaryAdjustmentService::rateAsOf()`. **The Clients screen says so
  on the deploy panel** rather than leaving it to be discovered when the next
  billing run disagrees with the last one.
- **Deploying is done from the Clients screen, and it is the employee's record
  that changes.** `PATCH /hr/employees/{employee}/deployment` — addressed as
  the employee and gated on `EmployeePolicy::update`, not on
  `manageOrganization`. That gate is for *shaping* the client list; filing a
  person against one is a different act, and the two abilities are held by the
  same roles today only because nobody has needed them apart. Exactly the
  reasoning `updatePosition()` follows, and the same endpoint shape.
  - **The category moves with the client, in one write.** `client_id` is
    prohibited on internal staff rather than ignored, so setting one without
    setting `employment_category` writes a record the employee form would
    refuse to save — and clearing a client while leaving the category external
    leaves somebody deployed to nobody. Guarded by a test in both directions.
  - **Recall is the same endpoint with a null client**, which is what makes
    somebody internal again. It sits on the person's row rather than behind an
    edit screen: it is the reverse of the button directly above it.
  - **A deactivated client is not offered**, and `exists` alone would not have
    caught it — the row is kept so payroll and attendance keep what they were
    filed under, not so somebody new can be sent there. The same rule the
    position move and the endorsement matcher both apply.
  - Department and position are untouched. A driver deployed to a client is
    still a driver; where they are sent is not what they do.
- **There is no national minimum wage in the Philippines.** Each region's
  RTWPB issues its own wage order, which is what clients mean by a "provincial
  rate". `config('payroll.wage_regions')` holds the floors;
  `Employee::wageRegion()` resolves own posting → client's site →
  `default_wage_region`. Used to **flag, never to enforce**, like a position's
  salary band — and the figures go stale with every new wage order, so treat
  them as this system's assumption rather than as the law.
- **Deployment Readiness (`/hr/deployment`) is the composition screen.** It
  answers "can this person be sent to a client tomorrow?", which no single
  module can: it needs credentials, 201-file completeness, and employment
  standing at once. `DeploymentReadinessChecker` **re-uses
  `CredentialExpiryScanner` and `OnboardingChecker` rather than re-deriving
  either** — if it made its own judgement about a lapsed licence, the three
  screens would eventually disagree about the same driver. Both scanners run
  once over the whole set and are indexed by employee; a per-person loop would
  be two queries each. `blocked` here is not a louder warning: a driver whose
  licence has lapsed may not lawfully drive, so it is the one place the system
  says "this would be wrong" rather than "someone should look".
- **Nothing is destroyed by a delete button.** Employees and clients are both
  soft-deleted, and `/hr/archive` is the master list of what has gone —
  employees and clients on one screen, because "what did we delete" is the
  question, not "what did we delete from Employees". Restore reuses
  `EmployeeService::restore()`, which the API restore already calls, so the
  two entry points cannot drift on reactivating the login. Admin-only, behind
  `EmployeePolicy::viewArchive` (separate from `restore` because it is asked
  of the class, before any record is in hand).
- **`archive.restore_window_days` is a label, not a deadline.**
  `purge_after_days` is deliberately null and nothing is ever deleted on a
  timer: employment records must be kept three years under the Labor Code's
  IRR and payroll records ten under the NIRC, and an employee row anchors
  payslips and BIR alphalists that outlive any UI retention window. The 30
  days only decide whether a row reads as a recent mistake or as history.
- **A client with staff on it is *deactivated*, not archived** — it stays
  listed and selectable in reports, and only stops being offered for new
  deployments. Archiving it would strand the headcount and payslips grouped
  under it. Only a client nobody was deployed to leaves the working list.
- **One payroll run, split by who it is billed to.** `breakdownByClient()` on
  the run screen groups totals per client plus internal staff, and the payslip
  list filters by `client_id` / `employment_category`. Deliberately *not* a
  separate run per client: a run is one statutory filing, and splitting it
  would mean five SSS remittances for one month.

## Stack

Laravel 12 + Inertia 2 + React 18 + Tailwind 3, served by Herd at
`https://core2.test`. **Secured, and not optionally** — `herd secure core2`
issued a local certificate (trusted in the Windows store; see "HSTS" under
Security below for how that trust was set up), and the session cookie now
requires it: `SESSION_SAME_SITE=none` only works paired with
`SESSION_SECURE_COOKIE=true`, which Chrome enforces by discarding the cookie
otherwise. `none` is what lets the app run inside an IDE webview's iframe.
`http://core2.test` is no longer reachable — a browser that has ever loaded
the secured site remembers to upgrade it, and nothing here depends on the
plain-http path working.

**The two stacks live in `backend/` and `frontend/`, and the split is of
dependencies rather than of deployment.** Composer and `vendor/` are in
`backend/`; npm and `node_modules/` are in `frontend/`; the repository root
holds neither. It is still one Inertia app — `frontend/` builds *into*
`backend/public/build`, and the Blade shell reads the manifest from there.

- **Herd serves `Core2/backend`, not `Core2`.** `core2.test` was a *parked*
  site (Herd parks `~/Herd` and serves each subfolder's `public/`), so moving
  `public/` one level down broke it outright. It is an explicit
  `herd link core2` from inside `backend/` now, re-secured afterwards because
  linking issues a new certificate. **A deployment's document root is
  therefore `backend/public`**, not the `public/` every Laravel guide assumes.
- **Three config files reach across the split, and each one fails silently if
  it is wrong.** `frontend/vite.config.js` sets
  `publicDirectory: '../backend/public'` so the manifest lands where Laravel
  looks; `frontend/tailwind.config.js` points three of four content globs at
  `../backend/` because the Blade shell and the paginator views are markup
  too (drop them and those classes vanish from the stylesheet with no error);
  and `backend/config/inertia.php` is published **solely** to repoint
  `page_paths` at `../frontend/resources/js/Pages`.
- **The JSX deliberately kept the `resources/js/` prefix** inside `frontend/`
  rather than being renamed to `src/`. The manifest keys are relative to the
  Vite root, so renaming would have rewritten all 211 page entries and broken
  `@vite(['resources/js/app.jsx', …])` in the Blade. The one-line saving of a
  shorter path is not worth a manifest rewrite.
- **`assertInertia` is what catches a wrong `page_paths`.** It checks the page
  component is really on disk, so the split failed 37 tests with "Inertia page
  component file does not exist" — the assertion doing its job. Nothing failed
  at request time, because `ensure_pages_exist` is false outside tests; the
  tests were the only thing that noticed.
- **Cross-folder scripts are explicit.** `frontend/package.json`'s `test`,
  `lint:php` and `fix:php` all `cd ../backend` first, which keeps
  `npm run check` a single gate over both stacks. CI names a
  `working-directory` on every step for the same reason.
- **Paths elsewhere in this file are relative to their own stack folder.**
  `app/Services/…`, `routes/web.php`, `config/payroll.php` and
  `database/migrations/…` mean `backend/…`; `resources/js/…`,
  `resources/css/app.css` and `scripts/check-imports.mjs` mean `frontend/…`.
  Stated once here rather than prefixing several hundred references, which
  would be churn with no reader benefit — the stack a path belongs to is
  unambiguous from its extension.

**Architecture:** Inertia renders the UI *and* a token-authenticated REST API
lives at `/api/v1`. Both entry points call the same Service class, so behaviour
can't drift between them. Controllers stay thin: authorize, delegate, respond.

**The API is where Core 2 meets the rest of ISMERS**, and it is documented for
the other teams in `docs/INTEGRATION.md`. **Five doors accept writes** — Core 1
proposes a hire (`POST /endorsements`), Core 3 posts a loan for payroll to
deduct (`POST /loans`), Fleet and Supply Chain post a one-off amount
(`POST /payroll/adjustments`), Core 4 posts a disciplinary action
(`POST /disciplinary-actions`), and Financial Management confirms a transfer
(`POST /payroll/runs/{run}/disbursement`) — and everything else is read-only.

- **Not one of the five writes to attendance or to a payslip.** Each stores a
  row, and the services that already compute our own screens read those rows at
  compute time. That is one rule reached four separate times — the loans
  argument below, the adjustments one, the disciplinary one, and the
  disbursement's amount check — and it is what makes a recompute safe: an
  endpoint that *applied* its amount when called would apply it again on the
  next recompute, with the two systems' balances parting company and nobody
  watching. **No external call moves money in this system on its own.**

- **Every published figure comes from the service that already computes it for
  our own screens.** `/drivers` reads `LicenseVerifier`, `/deployment-readiness`
  reads `DeploymentReadinessChecker`, `/payroll/runs` reads
  `PayrollRun::scopeReportable()`. Nothing is re-derived for the API, because a
  consumer and one of our screens disagreeing about the same driver would mean
  one of them is running its own copy of the rules — and the disagreement would
  surface on a dispatch sheet rather than on a screen somebody is watching.
- **`DriverResource` is not `EmployeeResource` with extra fields.** Fleet wants
  one answer before a run is assigned — may this driver lawfully take this
  vehicle, on this shift — so it carries DL codes, conditions, and expiry, and
  carries no salary, bank details, or government numbers at all. An integration
  that hands over more than the consumer needs is the failure noticed after a
  breach rather than before one.
- **A draft payroll run answers `409`, not `404`.** The run exists; it is not
  disbursable yet. That is the difference between "retry later" and "wrong id",
  and Finance needs to be able to tell them apart.
- **`/payroll/journal-summary/{period}` is keyed by period, not by run**, and
  it is the one payroll endpoint that is not a list of people. `/runs` answers
  which runs exist, `/register` answers who gets paid what, `/contributions`
  answers what the agencies are owed — none of them is a journal, and Finance
  cannot post a period to the ledger without one. A ledger is posted per
  accounting period and there can be more than one run in it, so every
  reportable run is summed and named in `meta.runs`: asking Finance to add them
  up would be asking them to re-derive a total this system holds, and the day
  their sum disagrees with ours it surfaces in a trial balance rather than on a
  screen.
  - **`meta.balanced` is a real check, not a formality.** Every figure is read
    back from stored payslips rather than recomputed, so a payslip ever written
    with a `net_pay` that did not equal `gross_pay - deductions_total` turns
    this false and `out_of_balance_by` says by how much. Compared with a
    centavo of tolerance, because these are two sums of rounded currency rather
    than one number twice.
  - **Time not worked is a contra to salary expense, not a payable.** Lateness,
    undertime, absence and unpaid leave are one credit line. Nobody is owed
    that money — the company simply spent less — and filing it as a liability
    would put figures on the balance sheet that will never be paid to anyone,
    which is found in an audit rather than in a reconciliation.
  - The employer's share appears on **both** sides and nets out, and each
    agency payable carries the employee withholding *and* the employer share,
    because one cheque goes to each agency. A zero line is omitted rather than
    sent as `0.00`.
- **Loans are amortised here and nowhere else.** Core 3 approves the loan and
  answers to the employee for it; only this system can take money off a
  payslip. A design where Core 3 kept its own balance and told us what to
  deduct each period fails the first time a run is recomputed — the deduction
  applies twice and the two balances part company with nobody watching.
- **`POST /payroll/adjustments` is the third write door, and it stores a row
  rather than touching a payslip — which is the same lesson as the loans one
  above, applied to one-off amounts.** Fleet posts trip allowances and per
  diems, Supply Chain posts accountability for a damaged item; both are an
  amount, a label, a cutoff and a decider, so they share one endpoint rather
  than two that would drift. `PayrollService::gatherInputs()` sums
  `payroll_adjustments` at compute time, so a recompute re-reads the same rows
  and reaches the same total. An endpoint that *applied* ₱500 when it was
  called would apply it again on the next recompute, and `PayrollAdjustmentApiTest`
  asserts exactly that it does not.
  - **`other_deductions` had been built into `PayrollCalculator` and never
    fed** until an external system needed it — deduction adjustments land
    there, earnings join `allowances`.
  - **A one-off earning is never prorated by frequency.** `amountForPeriod()`
    halves a monthly standing allowance on a semi-monthly run, which is right
    for a rice allowance and wrong for a trip that happened once: halving it
    would pay ₱250 for a ₱500 trip.
  - **The period is required, not inferred.** Left to "the next run that
    happens", a row missed by one run pays out in the following one and a row
    never consumed pays out forever.
  - **Idempotent on `(source, reference)`, and a resend never rewrites the
    amount.** The reference names a fact this system may already have paid, so
    changing the figure behind it would move money nobody asked to move —
    correcting one is a new reference, or a `DELETE` while the period is open.
  - **`409` once the period has a reportable run**, on both the post and the
    delete. Accepting a late amount would write a row nothing ever reads — an
    allowance somebody was promised and never paid, with no error to say so;
    and money already paid is not withdrawn by deleting the row that explained
    it. `source` is a fixed list for the same class of reason: a typo would
    create a row nothing reads and nobody notices.
- **`POST /disciplinary-actions` stores a suspension and refuses to serve it,
  and *that refusal is the design*.** Core 4 runs the investigation; this
  system holds the employment record the outcome attaches to. A suspension
  arriving here does **not** mark days absent and does **not** dock pay —
  `PayrollReadinessChecker::unservedSuspensions()` raises an unpaid one as a
  **warning** before the money is computed, and HR keys the days or decides the
  suspension was lifted.
  - The argument is the one that keeps *our own employees* out of
    `attendance_logs`: **a DTR somebody can rewrite is not a record of
    anything**, which is why an employee files a correction and a person
    decides. Core 4 is another system and is no more entitled to that than an
    employee is. Two alternatives were considered and both fail on it — writing
    absent rows makes another system the author of a time record, and applying
    a deduction moves money with no attendance behind it, so the payslip would
    disagree with the DTR it is supposed to have come from.
  - **The cost is stated rather than designed away: an unpaid suspension
    nobody acts on is paid.** That is the accepted price of not letting one
    system move money inside another, and
    `test_a_suspension_does_not_write_attendance_records` is what stops
    somebody "fixing" it later without reading this.
  - The warning **goes silent once the DTR already explains those days** —
    counted once for everybody rather than per action, since this panel loads
    before every payroll run. A line that is already done is how a panel stops
    being read, which is the same reasoning `OnboardingChecker` uses in not
    listing a complete file.
  - `payroll_effect` is returned on every response (`flagged_for_hr` or
    `none`), because "we stored this and it changed no pay" is the thing an
    integrator is most likely to assume wrongly, and an assumption is cheaper
    to prevent in the payload than to correct in a meeting.
  - **Dismissal is deliberately not one of the `TYPES`.** A separation carries
    a statutory final pay and a DOLE deadline behind it, and it goes through
    Separation & Final Pay where a person releases it — the same reason
    separation pay is left to HR rather than computed.
  - `DisciplinaryActionPolicy::create` is `isHrAdmin()` with **no supervisor
    exemption**, unlike `EmployeePolicy::view`. Recording a warning against
    somebody's employment is not the same act as reading their record.
- **`POST /payroll/runs/{run}/disbursement` closes a loop `/register` left
  open.** The register handed Finance a list and nothing came back, so *approved*
  and *the money arrived* were two facts this system reported as one — a run sat
  at `approved` until somebody in HR remembered to tick it.
  - **The credited `amount` is checked against the run's own `total_net`, not
    trusted.** A file that disbursed less than the register said is somebody
    unpaid, and marking the run `paid` over it would bury exactly that. A
    mismatch is `409` with the difference stated and **nothing is written** —
    not the status, not the reference. A centavo of tolerance, because these
    are two sums of rounded currency reached by two systems rather than one
    number twice, the same comparison `meta.balanced` makes.
  - **The status check runs *before* `Gate::authorize`**, which is the reverse
    of every other controller here and is deliberate: `markPaid` couples the
    ability to the run being approved, so authorising first answers **403** for
    a draft — and for Finance that is the wrong answer, since they *are*
    allowed and the run is simply not ready. The cost is that an
    under-privileged token learns a run's stage from this endpoint, which is a
    payroll run's status rather than anybody's personal data.
  - **`markPaid` was the ability, and `approve` was the first wrong guess.**
    Reaching for `approve` refused every caller, because it requires
    `for_approval` — which is how the existing and correct ability was found.
    Confirming money left the bank is the other half of releasing it, so
    splitting the two would let somebody mark a run paid who was never trusted
    to approve one.
  - `disbursed_at` is **a third date with its own column**, not `updated_at`: a
    transfer sent Friday and confirmed Monday is one event with two dates, and
    `updated_at` would only ever hold whichever we heard about last. It needed a
    `datetime` cast — without one `toIso8601String()` is called on a string and
    the endpoint 500s, which is how it was found.
- **`/analytics/workforce` returns shapes, never people.** A dashboard needs
  counts, and an endpoint that hands over the directory to draw a bar chart is
  the endpoint that will one day be the way the directory left.

Leave and performance are Inertia-only today; the pattern for extending the API
is in `Http/Controllers/Api/`, and the Service layer each one would call
already exists.

```
Request ─┬─ Http/Controllers/EmployeeController      (Inertia -> Pages/…)
         └─ Http/Controllers/Api/EmployeeController  (JSON  -> Resources)
                              │
                       Services/EmployeeService      ← all business logic
                              │
                          Models/…                   ← Auditable, scopes
```

## Commands

Every command runs from one of the two stack folders, never the repository
root — the root has no `composer.json` and no `package.json` since the split.

| Task | Command | From |
|---|---|---|
| Run tests | `php artisan test` | `backend/` |
| Format PHP | `vendor/bin/pint` | `backend/` |
| Format JS | `npm.cmd run format` | `frontend/` |
| **Everything** | `npm.cmd run check` | `frontend/` |
| Dev assets | `npm.cmd run dev` | `frontend/` |
| Build assets | `npm.cmd run build` | `frontend/` |

`npm.cmd run check` is still the single gate over both stacks: its `test` and
`lint:php` steps `cd ../backend` themselves, so it covers formatting,
typecheck, the import check, Pint, and the whole test suite from one command.

**On this machine, `npm` is blocked by the PowerShell execution policy — use
`npm.cmd`.** Assets are pre-built, so the site works without `npm run dev`.

## Roles

`admin`, `hr_staff`, `supervisor`, `employee` (constants on `App\Models\User`).

- **admin / hr_staff** — every record; only admin may archive
- **supervisor** — own record plus direct reports
- **employee** — own record only

Enforced in two places that must agree: `EmployeeService::scopedQuery()` narrows
the *list*, and `EmployeePolicy` guards *individual* records. Salary, bank
details, and government IDs are gated behind `viewSensitive` and omitted from the
API resource entirely — not just hidden in the UI.

Self-registration is disabled by design; HR provisions logins from the employee
form.

## Design system

Every colour is a semantic token in `resources/css/app.css`, exposed through
`tailwind.config.js`. **Never write a raw hex or a `gray-500` in a component** —
use `bg-card`, `text-muted-foreground`, `border-border`, `bg-sidebar-accent`.
Light and dark both work because components reference tokens, not values.

- Shared components live in `resources/js/Components/ui/` — import from the
  `@/Components/ui` barrel.
- Every authenticated page wraps in `@/Layouts/AppLayout` and passes `title` +
  `breadcrumbs`.
- **A list screen's "create" action sits with its filters**, at the top-right
  of the table's own card — never in `AppLayout`'s `actions` slot, and never
  as a floating button (both were tried; the filter row is where it landed).
  The card takes one of two shapes depending on what the screen already has:
  - **Has a filter row** (Employees, Leave, Timekeeping, Overtime): the button
    ends that flex row, pushed right with `ml-auto`. Match the row's own
    breakpoint — `lg:ml-auto` where the row is `lg:flex-row`, `sm:ml-auto`
    where it is `sm:flex-row` — or it detaches on the size in between.
  - **Filters live in `CardHeader`'s `action` prop** (Departments, Positions,
    Salaries, Separations, Holidays): the button joins that same flex group.
  A screen with no filters at all (Payroll Runs, API Tokens, Users) still uses
  the `CardHeader` `action` slot, so the button lands in the same place either
  way. Workflow actions (Submit, Approve, Release, Print) are not create
  actions and stay where they are.
- **`CardHeader` stacks below `sm`, and its `action` has to cope with a narrow
  line.** The action slot holds real controls, not just a button: Departments
  puts a 224px search box *and* a "New Department" button in it, and Positions
  adds a department filter on top of that — about 530px of content. A 375px
  phone leaves 301px there once page and card padding come off, and the slot
  was `shrink-0`, so the row overflowed the card and pushed the whole page
  sideways while truncating the title to nothing. The header stacks now, which
  buys the action a full-width line; the actions that hold two or more controls
  stack themselves the same way (`flex flex-col gap-2 sm:flex-row`) and their
  fixed widths are `w-full sm:w-56`, because a full-width line is still only
  301px and a 224px box beside a button does not fit in it.
- **A fixed `w-*` on a filter control is a mobile bug unless it is inside
  something that already copes** — a `flex-wrap` row, a table cell (tables
  scroll), or a modal. Write `w-full sm:w-52`, not `w-52`.
- **Seven columns is seven columns.** The leave calendar and the date picker
  cannot become one column on a phone without ceasing to be calendars, so the
  calendar scrolls inside `min-w-[560px]` instead of shrinking to 38px a day —
  narrower than the pill naming who is off, which would truncate every entry to
  nothing. The negative margin on that scroller is what lets it run to the
  card's edge rather than stopping inside its padding.
- **A label that only exists on desktop belongs in `hidden sm:inline`.** Every
  `AppLayout` `actions` button already does this, which is why the topbar holds
  up on a phone: the icon stays, the word goes.
- **A module with several screens is one sidebar entry with `children`**, which
  the sidebar renders as an expandable dropdown — not a flat link per screen.
  Payroll's seven screens live this way, same shape as Employee Information,
  Timekeeping, Leave, and Performance. Every child `href` is picked up by
  `ALL_HREFS` in `navigation.js` automatically, which is what lets `bestMatch()`
  highlight the right entry when its screen is open. Children filter by role
  like every other nav entry.
- **Those entries sit in labelled groups** — Employee Management, Time &
  Attendance, Payroll & Performance, AI & Analytics, and Administration —
  above an unlabelled Dashboard. The grouping is
  presentational: it is what gives the sidebar its rhythm, and it moves no
  module, route, or permission. Five modules under one heading read as a flat
  list of five things rather than as a system with parts.
  - **The order is business first, system last.** The four groups that are
    about the company's work come before the two that are about the app
    itself. `visibleGroups()` drops any group whose items all filter out by
    role, so `roles` belongs on the item and never on the group — a heading
    with nothing under it is worse than no heading.
- **A fifth group, AI & Analytics, holds only Scanner Accuracy — and the
  reason it holds nothing else is the point.** It briefly held six screens,
  grouped on a criterion that was actually sound: Credentials, 201 File
  Status, Attendance Exceptions, Deployment Readiness, and Record Checks each
  read *across* modules rather than maintaining one, and none of them owns a
  table. The criterion was fine and **the label was a claim none of them could
  meet**: every one of those five is a config-driven rule engine — dates,
  regexes, string comparisons — and `RecordIntegrityChecker` says in its own
  docblock that there is deliberately no model behind it. A group named after
  a technology none of its members use is disproved by the first person who
  clicks into it, and the one feature here that *is* AI — `DocumentScanner` —
  was never in the group at all. So the five went back beside the module whose
  records each one reads: Exceptions to Timekeeping, and the other four into
  **Checks & Readiness**, a sibling entry to Employee Information rather than
  four more of its children — loose in that list they read as four more record
  screens, which is the wrong claim about all four, since none of them stores
  anything. Named for what they do rather than how: "Compliance" was the
  obvious alternative and is taken, meaning SSS, BIR and PhilHealth remittance
  under Payroll. It is a sibling and not a nesting because the sidebar renders
  exactly two levels, which is the same shape Timekeeping and Leave take
  inside Time & Attendance. What
  stays is the screen that *measures* the scanner, which is the only way a
  screen belongs to this feature — the scanner itself fills a form and runs
  the batch filer, and neither is a destination. Filing by input was the
  earlier mistake and it is worth not overcorrecting into the opposite one:
  the fix for a wrong label is a right label, not a wrong group.
  - **"Attendance Exceptions" is "Exceptions" again.** The longer name was
    earned by sitting in a group that mixed modules, where the bare word said
    nothing about which records. Its siblings are Records, Overtime and
    Holidays now, and the subject is not in question.
  - Moving them changed no route, controller, or permission: `ALL_HREFS` is
    derived from every group, so `bestMatch()` still lights the right entry.
- **Settings has been in four places, and the fourth is one entry under a
  label that says what it is.** It began as a 224px section column beside the
  page, which at 1024px left the forms about 468px — squeezed at exactly the
  width where a two-column layout was meant to start helping. So it became a
  sidebar entry with seven `children` like every module, and the column was
  deleted. That was wrong in a different way: it filed "change my password"
  and "back up the database" level with Payroll. So it left the sidebar
  entirely and hung off the two places an account is reached — the user card
  and the topbar. Now it is back in the sidebar as **`System Settings` under
  an `Administration` group**, and the group label is the whole difference
  from the second answer: **the objection was never the sidebar, it was the
  ranking**, and a heading that says "administration" is what ranks it apart
  from the company's work. Nothing about the routes or the permissions has
  moved through any of the four.
  - **One entry, not seven `children`.** The dropdown of seven is what made it
    read as a sixth module. The sections already live on the page as a row, so
    the sidebar carries a door and the page carries the sub-navigation.
  - **`SettingsLayout` renders no section list at all, and that is what
    settled a long argument.** The sections were a 224px column beside the
    content (deleted — at 1024px, once the sidebar and page padding come off,
    it left the forms about 468px, squeezed at exactly the width where a
    two-column layout is meant to start helping), then a tab row above it (a
    row costs *height*, which a settings form has to spare, and not width),
    then both at different breakpoints. Every one of those was trying to put
    the sub-navigation on the same screen as the thing it navigates to. With
    `/settings` a menu, that question does not arise: the list is the page you
    came from, and drawing it again inside each section is the same seven rows
    on two consecutive screens. A section page carries a one-line **back
    link** instead — one line is not a duplicate, and without it a section is a
    dead end, since the topbar accepts `breadcrumbs` and does not render them.
  - **The entry carries no `roles`.** `/settings` redirects to the first
    section the person may open, and Appearance and Security belong to every
    signed-in user — filtering the entry by role would hide the door to
    somebody's own password.
  - **`activePrefix: '/settings'` is what lights it**, not `bestMatch()`. The
    sub-navigation is a row on the page rather than `children` here, so there
    is no child href to match from `/settings/security`. The two gears still
    compute their own state from the URL — a second door to the same place,
    and a control that never shows it is current is one people click twice.
  - **`/settings` is the section menu, not a section.** It renders
    `Settings/Index` — the seven rows with a one-line blurb each — and a
    section is the next click. It used to redirect, which was right while the
    only way in was a gear (the person clicking had already decided they
    wanted *something* in here) and wrong the moment `System Settings` became
    a sidebar entry: a nav link that silently lands somebody on the company's
    regional formats has chosen a section on their behalf. The index and the
    layout share one `visibleSections()` rather than filtering twice — a row
    offered on the menu and then missing from the column beside it would be a
    door that vanishes once you walk through it.
  - **The redirect it replaced had already been fixed once**, and the fix is
    the reason the menu needs no role check of its own. It always
    redirected to General, which is admin-only: harmless while the only way in
    was a role-filtered sidebar entry, and a 403 the moment the gear became a
    door shown to everybody.
  - `SETTINGS_SECTIONS` is now the *only* description of what Settings is —
    one fewer thing to drift. Still exported, because the server enforces the
    same `admin` split with a 403 and the tab row must not disagree about
    which five those are: Appearance and Security are open to every signed-in
    user, the other five are admin-only.
- Chart marks use `--chart-1`, held apart from `--primary` because chart fills
  have to sit inside an OKLCH lightness band that `--primary` misses in dark mode.
- **A summary tile that counts something must be able to show it.** Every
  figure on a list screen links to the rows it counted, through
  `withFilters()` — same path, same filters, one more narrowing. A tile that
  counted 53 late days in March and then opened an unfiltered list has not
  answered the question it raised, it has replaced it.
  - **The link has to return the number on the tile**, and twice it did not.
    "Days Present" counts three statuses (`present`, `late`, `undertime`) and
    linked to `status=present`, reading 1,230 and opening 581. "Awaiting
    Action" counts two (`pending`, `supervisor_approved`). Both were found by
    clicking the tile and counting, not by reading the code. Where the count
    crosses a filter, the filter is *added* to match it — `attended`, `late`,
    `awaiting`, `blocking` — rather than the tile being quietly re-scoped.
  - **Filters that narrow the same axis are cleared together.** Drilling into
    Late and then into Absences would otherwise ask for both and return the
    days somebody was absent *and* late, which is none of them. The dropdowns
    clear the tile-only keys too.
  - **A tile-only filter needs a visible chip.** `late` and `attended` match
    something no dropdown can show, so the filter row draws a removable pill
    when one is on. A list silently narrowed by an invisible filter is a list
    nobody can explain.
  - **Screens whose whole table fits on one page do not link at all** —
    Departments, Positions, Clients. Filtering a ten-row table hides rows the
    reader can already see.
- **Tone is valence, and zero is grey.** A tile in amber is a thing somebody
  has to do something about; `info` is a state, not a fault (an approved
  absence, a scheduled raise); `destructive` is reserved for what is unlawful
  or past a statutory deadline — a lapsed licence, a final pay past DOLE's 30
  days. Every count drops to `muted` at zero, because "0 blocked" in red reads
  as a problem when it is the opposite.
- **A share beats a count wherever there is a denominator.** `MeterCard` says
  "1,230 · 65%" where a `StatCard` said "1,230": 38 active means nothing until
  you know the roster is 41 rather than 400, and the same 40 exceptions read
  differently at 2 critical than at 30.
- **Dashboard tiles take a `tone`.** `StatCard` and `SplitStatCard` colour the
  icon tile; `SplitStatCard` also colours each figure, and `MeterCard`'s `tone`
  colours the bar and badge while `iconTone` handles the tile. Tone means
  *valence*, not decoration — an approved absence is `info`, not `warning`, and
  a stat at **zero drops to grey by itself**, because "0 absent" in red reads as
  a problem when it is the opposite. The headline number in `StatCard` stays in
  the foreground colour: it is the thing being read.
- **The dashboard opens on the reader's own record, above the company's
  figures.** Everything below it is the organisation looking at itself — how
  many people, whose leave is waiting, what payroll came to — and for a
  rank-and-file login, which is most of the workforce, none of that is theirs
  to act on. `ProfileCard` answers the question the rest of the screen does
  not: *where do I go*. Name, employee number, position, department, client and
  supervisor, then the four screens that belong to that person — their 201
  file, their attendance calendar, leave, and payslips.
  - **Every part of it is a link, which is the point rather than a flourish.**
    A card that states a department and cannot open it has told the reader
    something they already knew about themselves.
  - **The client is the one thing deliberately *not* linked.** `/hr/clients` is
    behind `manageOrganization`, so for the employees most likely to be
    deployed to one it would be a link into a 403 — the same rule the org
    directory follows when it draws a plain row instead of a link.
  - **`profile.employee` is null for a login with no 201 file**, and that is a
    real case rather than a defensive check: an administrator need not be an
    employee at all. The card falls back to the account and drops the four
    links, which are built from `employee.id` and would otherwise be four 404s
    on the first screen that account ever sees.
  - **Their own salary is on it, and the policy is asked rather than assumed.**
    `EmployeePolicy::viewSensitive` returns true for HR *and* for the person
    the record belongs to, so an employee has always been able to open their
    own 201 file and read their own rate — withholding it from their own
    dashboard would be the screen disagreeing with the gate. The block is built
    behind `$user->can('viewSensitive', $employee)`, so the day this card is
    widened to somebody else's record the compensation stops being drawn on its
    own rather than because a class was remembered.
  - **Government numbers and the bank account stay out regardless.** They are
    what a stolen dump is worth stealing and nothing on a landing page needs
    them. Asserted against the whole rendered payload rather than the shape of
    the array, the way `DirectoryTest` does it.
  - Attendance is counted through `TimekeepingService::PRESENT_STATUSES` rather
    than a fourth private copy of that list, and both halves of the month are
    shown — "18 days in" and "2 days missed" are different questions, and the
    second is the one somebody acts on. Each links to the employee calendar
    carrying the same `from`/`to` it counted, so the screen it opens returns the
    number on the card.
- **Below that it reads in four bands**, top to bottom: headline `StatCard`s,
  then the `SplitStatCard` / `MeterCard` detail row, then charts, then the
  three summary cards. `StatTile` and `TilePreview` are the units the summary
  cards are built from — a row of tinted figures over the single most recent
  record. One preview row, never a list: the card is a glance, and the screen
  behind it is where the rest lives.
- **`TrendChart` is one series only.** The fill under the line reads as "this
  quantity"; two overlapping fills stop meaning anything. It paints with
  `currentColor` so the caller sets `text-chart-1` and the SVG gradient stays
  on a token — a gradient stop needs a real colour value, and `currentColor`
  is the only way to give it one without a hex. Its axis is padded away from
  the data rather than anchored at zero: headcount moving 34 → 39 against a
  0-based axis is a flat line, and the change is the thing being shown.
- **Company-wide figures on the dashboard are gated.** Total net pay,
  everyone's leave counts, and the payroll stage breakdown are HR's view of
  the organisation, behind `can.viewCompanyFigures` (`isHrAdmin()`). They are
  withheld in the *controller*, not hidden in the component — the tile used to
  print the month's total net to every signed-in role, which is the same line
  `EmployeePolicy::viewSensitive` draws for salary on a record. A withheld
  summary arrives as `null` and its card is not drawn; payroll arrives zeroed
  so the four-column headline row keeps its shape.
- **`--grade-1` … `--grade-6` are a ramp, not six colours.** Dark green →
  green → yellow-green → yellow → orange → red, meaningful only in order.
  Reach for them when something is *a position on a scale*; keep
  `success` / `warning` / `destructive` for states that mean one thing
  (approved, pending, rejected). `Badge` exposes them as `variant="grade-3"`.
  The dark block lifts every stop — `#15803D` is L 29%, dark enough to
  disappear on the dark surface, which is the logo-subtitle mistake again.

## Adding a module (the Module 1 recipe)

1. **Migration** already exists for Modules 2–5 — check
   `database/migrations/2026_08_09_0000*` before writing a new one.
2. **Model** — `use Auditable` for anything HR edits; add `scopeFilter()` for
   list screens.
3. **Policy** — `App\Policies\{Model}Policy`, auto-discovered.
4. **Service** — `App\Services\{Module}Service`, holds the logic and a
   `scopedQuery(User)` for role narrowing.
5. **Form Requests** — validation only; `authorize()` delegates to the policy.
6. **Resource** — use `mergeWhen($canSeeSensitive, …)` for restricted fields.
7. **Controllers** — an Inertia one and an `Api\` one, both injecting the service.
8. **Routes** — web under `Route::prefix('hr')->name('hr.')`, API under
   `/api/v1` inside `auth:sanctum`.
9. **Pages** — `resources/js/Pages/HR/{Module}/`, built from the UI kit.
10. **Tests** — management, access-control, and API tests, mirroring
    `tests/Feature/HR/` and `tests/Feature/Api/`.

Replace the module's entry in `resources/js/config/navigation.js` (the route is
already listed) and delete its `ModulePlaceholderController` method.

## Document scanner (Module 1, AI)

**The one AI feature in the system.** `DocumentScanner` reads a scanned 201-file
upload — LTO licence, NBI clearance, PhilSys ID, medical certificate, contract —
and proposes `type`, `title`, `issued_at`, and `expires_at` on the upload form.

It exists because `CredentialExpiryScanner` is only as good as the `expires_at`
someone typed: a licence keyed a year late is a driver the system believes is
legal to dispatch.

- **`DocumentScanner` itself never writes to the database.** It fills a form;
  HR corrects it; the existing `StoreEmployeeDocumentRequest` validates the
  save exactly as it does a hand-typed entry. Same shape as PayrollReadiness
  warning without blocking. The **one** place a reading becomes a stored fact
  unattended is `BulkDocumentFiler::process()`, behind six config gates and
  marked on the row — see "Batch filing" below. Everywhere else, including
  every single-document upload and every 201-form scan, a person still
  confirms.
- **Everything the model returns is untrusted input.** The type is checked
  against `EmployeeDocument::TYPES` (a hallucinated type becomes `null`), dates
  are re-parsed through Carbon, and **the name check runs in PHP, not in the
  prompt** — catching a document filed under the wrong employee should not
  depend on the thing being checked.
- **A null is a valid answer.** The prompt says so explicitly, and the form
  only fills fields that came back non-null — overwriting with a null would
  erase a correction HR had already typed.
- **The local driver was removed, and that is the biggest single decision
  here.** There was a fourth driver — Ollama running `glm-ocr` on
  `127.0.0.1` — and it was the *default*, because a 201-file scan is a
  photograph of somebody's PhilSys ID or NBI clearance and locally the image
  never left the machine. It went because **it could not run where the system
  runs**: Ollama has to be installed on whatever serves the app, and a small
  VPS cannot hold even a 2.2 GB vision model. So the default driver was one
  that goes dark on every deployment, and a fresh clone shipped pointing at a
  feature that could not work.
  - The argument that decided it: **a local option nobody can deploy is not a
    privacy control.** It is a privacy control that is switched off in
    production, which is the only place it would have mattered. Keeping it
    made the repository *look* like it protected the data while the running
    system did not.
  - **So the RA 10173 obligation is now unavoidable rather than avoided.**
    Every scan is a cross-border transfer of personal data. That has to be met
    with disclosure to the employee and consent on file — it is no longer
    something a config value can satisfy, and there is no driver left that
    sidesteps it. Saying so plainly is the point; deleting the reasoning along
    with the driver would have left the system quietly doing the thing the
    design used to refuse.
  - `SCANNER_DRIVER=ollama` left in an old `.env` **goes dark rather than
    falling through** to a working driver. `.env` is not in the repository, so
    every machine that ran the old default still has that value after a pull;
    falling through would silently start sending 201-file photographs abroad
    from a machine whose owner never chose that. Guarded by a test.
- **Three drivers, one shape.** `gemini` (hosted, free tier, **default**),
  `openrouter` (hosted broker), `anthropic` (hosted, paid). All three are
  constrained by the *same* JSON schema, so everything downstream of `read()`
  is driver-agnostic — and a test asserts the normalised keys are identical
  across them, because a drift would otherwise only appear in production on
  whichever driver the suite does not exercise. Each one wraps that schema
  differently and nothing else differs: Gemini takes it as
  `responseJsonSchema`, OpenRouter under a named `response_format.json_schema`,
  Anthropic as `outputConfig.format`.
- **`gemini` is the default, and the one to prefer.** Free at this tier,
  reachable from anywhere, and — the part that matters once every driver is
  hosted — a **single named processor**. "Google processed it" is an answer;
  "OpenRouter, and then whichever upstream served it that day" is not.
- **`openrouter` is a broker, and that is the whole of what to know about
  it.** One key reaches many models, so changing model is an env edit rather
  than a new driver — which is genuinely why somebody picks it. But OpenRouter
  does not run the model: the image goes to OpenRouter and OpenRouter forwards
  it to whichever upstream is serving that model at that moment. A 201-file
  scan is a photograph of somebody's PhilSys ID or NBI clearance, so under
  RA 10173 this is a cross-border transfer to **two** processors rather than
  one, and which the second is can change without anything here changing.
  Gemini is a single named processor; a broker is two. It is the worst of the
  three on the axis this feature was designed around, and it is not the axis
  it is chosen for.
  - **`provider.data_collection: deny` is sent on every request**, not left to
    a dashboard setting. It asks OpenRouter to route only to upstreams that do
    not retain or train on the prompt — the difference between one
    cross-border transfer and an indefinite one — and putting it in the body
    means the guarantee travels with the code that relies on it.
  - **The likely misconfiguration here is the *model*, not the key**, which is
    the opposite of every other driver. The catalogue is large and only part
    of it can both read an image and honour a JSON schema. A model that can do
    neither answers prose, which reaches the form as a scan that simply found
    nothing — so `strict: true` is sent, `firstOpenRouterJson()` logs the model
    name and OpenRouter's `refusal` field, and `scanner:check` lists the model
    before the key among the likely causes.
- **The deployment driver had never been run, and did not work.** `gemini`
  was posting an OpenAI-shaped body (`input`, `response_format`) to
  `/v1beta/interactions`, which is not a Gemini endpoint — Gemini names the
  model in the URL (`/v1beta/models/{model}:generateContent`) and takes
  `contents[].parts[].inline_data`, `systemInstruction`, and
  `generationConfig`. Nothing caught it because every scanner
  test stubs `read()`, which is right for the rules around it and leaves the
  envelope untested — and at the time the only driver anyone ran locally was
  the local one, so the driver that existed *for deployment* was the one with
  no test. `ScannerDriverRequestTest` asserts what each driver puts on the
  wire, and every driver is a deployment driver now, so every one needs a leg
  there.
- **The free tier is 20 scans a day, per model.** Measured, not read: Google's
  429 body names the quota — `GenerateRequestsPerDayPerProjectPerModel-FreeTier`,
  value `20`. Enough to demonstrate the feature and nowhere near enough to run
  an HR department on. Past it a scan returns nothing and HR types the fields,
  which is what every other failure here collapses to — so the limit degrades
  the feature rather than breaking the screen. Paid billing lifts it; so does
  a paid model on `openrouter`. There is no longer a local driver to fall back
  to, and that is the trade accepted when it was removed.
- **Gemini is retried and the other two are not**, because only this one shares
  a quota. Six identical scans on a real key came back three answered and three
  429, so a driver that gave up on the first would look broken half the time
  while being perfectly configured. Only 429 and 5xx are waited out — a 400 or
  a 401 is an answer, not a queue.
  - **`retry()` needs its exception to be thrown.** The first attempt passed
    `throw: false` to keep a bad key falling through to the existing handler,
    which quietly turned the retry into a no-op: Laravel only retries what
    throws. The throw is caught below instead, and logged with the same two
    fields a plain failure carries.
  - The 429 body says "this model is currently experiencing high demand",
    which reads as load and is a quota. The status code is the honest signal;
    the prose is not.
- **The schema field was wrong too, and that was found later still.** Gemini
  has *two* of them and they take different dialects: `responseSchema` is an
  OpenAPI 3.0 subset whose `type` is a single value, and `responseJsonSchema`
  takes real JSON Schema. This schema is full of `["string", "null"]` unions
  and carries `additionalProperties`, so on `responseSchema` every request came
  back `INVALID_ARGUMENT` — "Proto field is not repeating, cannot start list".
  It is `responseJsonSchema` now, which is what lets all three drivers be sent
  the *same* schema, which is the only reason everything downstream of `read()`
  can stay driver-agnostic.
  - **Gemini validates the body before the API key**, so a wrong schema and a
    wrong key are indistinguishable from outside — both simply fail. That is
    also what makes the envelope testable without a key at all: send a
    deliberately invalid one and read which error comes back.
  - CLAUDE.md previously recorded the opposite ("the schema itself was fine"),
    which was asserted from the documentation rather than from a request. The
    docs describe `responseJsonSchema`'s dialect; the code was sending
    `responseSchema`.
- **Thinking is charged against `maxOutputTokens`, and that silently broke the
  scanner for two days.** `thinkingConfig.thinkingBudget` is `0` on the Gemini
  request, and it is load-bearing rather than tidy: `gemini-3.5-flash` is a
  thinking model, `usageMetadata.thoughtsTokenCount` comes out of the same
  budget the answer has to fit in, and a 201-file photograph is precisely the
  input it thinks hardest about. Measured on one synthetic card: **458–574
  thinking tokens of the 1024**, leaving the JSON to be cut mid-string —
  `{"document_type": "DIGITAL TIN ID",` — which `json_decode` refuses. So a
  document the model had read **correctly** reached the upload form as "nothing
  found", with the red "not a valid document" panel on top of it.
  - **The comment on `MAX_TOKENS` was the bug.** It said a cap that size
    "cannot truncate" a handful of short fields, which is true of the *answer*
    and false of the *budget* — an assumption about the model rather than a
    fact about the API, the same shape as the HSTS scheme check that assumed
    the environment. The figure did not change; what changed is what else was
    allowed to spend it.
  - **It is the right call on the merits, not just a way to buy tokens.** This
    call is transcription: read what is printed, hand back the fields. Every
    judgement is made afterwards in PHP — `resolveType()` ranks five sources,
    `nameMatches()` compares the name, Carbon re-parses the dates — precisely
    because the small model is good at reading and poor at judging. The
    thinking was being spent on a decision this code discards.
  - **`scanner:check` passed throughout, and that is not a flaw in it.** It
    sends one *generated* card, which needs little thought and answered inside
    the budget every time. The failure needed a busy real document, so "the
    key, the model and the request all work" was true and useless — the same
    gap `ScannerDriverRequestTest` exists to cover, which is where the
    `thinkingBudget` assertion now lives.
  - **Logging only `array_keys($body)` is what made it undiagnosable.** The
    envelope looks perfectly healthy when the reply is truncated — `candidates`,
    `usageMetadata`, `modelVersion` all present — so the warning reported the
    one thing identical in the working and the broken case, six times across
    two days. It now names `finish_reason`, the thinking and output token
    counts, and the first 30 characters of the text: `MAX_TOKENS` is
    recoverable by widening the budget, `SAFETY` is the model declining, and
    those need different fixes. **The head, not the whole reading** — it is a
    transcription of somebody's government ID and a log is not where that
    belongs. Same lesson as the discarded `heading`: the evidence that says
    what to fix has to survive the failure.
  - Gemini-only. `MAX_TOKENS` is shared with the other two drivers; this field
    is not, and neither is the failure — OpenRouter and Anthropic are not
    spending that budget on thought.
- **The caption is read along with the number, and must not refuse the card.**
  A real scan returned `document_number` as `"NBI ID NO.: N2G4-25-123456"`.
  `numberMatches()` compared for equality, so that read as a *contradiction* —
  and a contradicted number blocks the upload, which meant the correct
  document, correctly read, was refused because of the words printed beside
  the number. It compares by containment now, and `documentNumber()` strips
  the caption off what the form shows. A false negative is the expensive
  direction here; the stored numbers are 9–16 characters, so a coincidental
  substring match is not a real risk.
- **What a document *is* is decided from five sources, strongest first.** It
  used to be two — a keyword in the printed heading, else the model's own key.
  Three more were already in data the scanner returns and were going unread.
  `DocumentScanner::resolveType()` tries, in order:
  1. **A number already on this employee's 201 file.** If the printed number
     matches what HR typed into the licence field, the paper is a licence.
     The only signal here with a human behind it rather than the model.
  2. **The document's own printed heading** — evidence about the paper, from
     the paper.
  3. **The shape of the number**, against `type_defining_formats`.
  4. **How long it is valid for**, against `validity_months`. Two dates the
     model transcribed separately, so the gap is not something it can bend to
     fit a guess: five years is a licence and nothing else in a 201 file runs
     that long.
  5. **The model's own key**, last, measured wrong 4/4 on an NBI clearance
     whose heading it transcribed correctly every time.
  - `type_certain` is true only for the first two, and that is what the upload
    form refuses over. A shape is shared between cards and a validity period
    overlaps between types; blocking on either would refuse correct filings.
    The panel names the source either way, because "this is a Clearance" and
    "this is a Clearance because its heading says so" are different claims.
  - **`type_defining_formats` is deliberately shorter than `number_formats`.**
    A pattern good enough to *check* a number once the type is known is not
    tight enough to *decide* it: the passport shape `[a-z]{1,2}\d{6,9}` is a
    fair check on a filed government ID and it swallowed a medical certificate
    numbered "MC-2026-4471" — two letters, eight digits, a perfect fit for a
    rule never meant to classify. Only the LTO licence and the 16-digit
    PhilSys PSN are unmistakable enough to survive the shorter list.
  - **The issuing authority is the strongest thing printed, and it is
    printed in full.** The keyword list began as abbreviations and filed a
    real NBI clearance as a licence: its letterhead reads "REPUBLIC OF THE
    PHILIPPINES / Department of Justice / National Bureau of Investigation"
    and the word "NBI" is nowhere on it. `title_keywords` now carries the
    agencies as they write themselves — DOJ/NBI, PNP, LTO, PSA, DOH, SSS,
    BIR, PRC, DFA — not the shorthand people say.
  - **The heading is returned as well as used** (`heading`), and shown on the
    panel. It used to be replaced by the derived label and thrown away, which
    is what made a wrong type impossible to explain: three attempts were made
    at improving the classifier while the one piece of evidence saying what to
    fix was being discarded. It named the NBI bug in one second.
  - **A guess the document contradicts is discarded, not replaced.** A
    résumé carries no ID number and does not expire; a PSA civil registry
    document does not expire either (`type_cannot_have`). When every positive
    signal has declined and the model answers "resume" while reporting a
    printed number, the reading has argued with itself — the type comes back
    **null**, which the form leaves blank, rather than a second guess. This is
    the miss that prompted it: a real government ID, no heading keyword, an
    unrecognisable number, no dates, filed as a Résumé and looking confident.
    The rule covers only the two types where the claim is absolute; a contract
    genuinely has an end date, so ruling it out would discard correct readings
    to catch wrong ones.
  - The check applies **only to the model's guess**. Evidence from the paper
    or the 201 file is not second-guessed by an absence: a licence whose
    expiry the model failed to read is still a licence.
  - Where real documents overlap, the rule **declines** rather than inventing
    certainty — a one-year validity fits both a clearance and a medical, so it
    decides nothing and the guess stands. A range narrowed to force an answer
    would be manufacturing confidence.
- **Whether the document has already lapsed is said at the moment it is
  filed.** The check only existed *afterwards* — `CredentialExpiryScanner`
  reads stored rows — so a licence two years out of date was uploaded, looked
  correct on the panel, and surfaced on another screen later. `expiry` now
  reports `expired` / `expiring` / `valid` with the day count and whether the
  type blocks work, reading `credentials.warning_days` and
  `credentials.blocking_types` rather than holding a second opinion: the
  upload panel, the Credentials screen and Deployment Readiness must not
  disagree about the same licence. **Reported, never refused** — an expired
  document is filed deliberately often enough (for the record, or during a
  renewal) that blocking the upload would leave the 201 file emptier than the
  truth, and Deployment Readiness is the screen that stops somebody being
  sent out. Shown per row in the batch filer too, where forty expiry dates is
  forty chances to let one through.
- **A type that cannot expire is never given a date.** `type_cannot_have`
  states one fact — a résumé has no ID number and does not lapse, a PSA civil
  registry document does not lapse — and it does two jobs from it: it rejects
  the model's guess when the document contradicts it, and it clears the field
  once the type is settled *by any means*. The second half matters on its own,
  because a PSA certificate identified from its letterhead is correctly typed
  and the model can still have read a date off it: PSA paper prints an issue
  date and a registry date, and a misread of either arrives looking like an
  expiry. Kept, it would put a birth certificate into
  `CredentialExpiryScanner`'s renewal queue to be chased forever for a
  renewal that does not exist. The panel then says **"Does not expire"**
  rather than "Not found", which would read as a failed reading rather than a
  fact.
- **Whether a document expires is not always a fact about its *type*, and a
  TIN ID is where that broke.** `type_cannot_have` is keyed by type, which
  works for a résumé or a PSA certificate because every document of that type
  is alike. `government_id` is not: a TIN ID, a UMID, an adult PhilID and a
  voter ID are issued for life and print no expiry anywhere, while a
  **passport and a postal ID do expire** — and a passport's expiry is the kind
  of thing somebody is turned back at an airport over. So
  `'government_id' => ['expires_at']` would have been wrong in the expensive
  direction, silently discarding a correctly read passport expiry, and leaving
  it out is what let a TIN ID be handed one.
  - `config('scanner.non_expiring_ids')` names the cards one by one, **keyed
    by the type the rule may act on** so the config says where it applies
    rather than the code naming a type inline. The scoping is load-bearing: an
    NBI clearance prints a real "VALID UNTIL" and its letterhead names an
    agency, so a list read against every type would eventually clear a real
    expiry off a real credential.
  - Matched against **the heading the model transcribed** — evidence printed
    on the paper, the same source `title_keywords` ranks second of five — and
    it **clears the date without ever rejecting the type**. A hallucinated
    expiry on a TIN ID is the model answering a question the card does not
    have, which is the PSA failure exactly, and it is not a reason to doubt
    that the paper is a government ID.
  - **The prompt had been asserting the opposite, in as many words.** It
    listed `an expiry date ("TIN ID ISSUE / EXPIRY DATE")` among the things an
    authentic BIR card carries, so the system was *instructing* the
    hallucination rather than merely failing to catch it. It now states that a
    TIN ID, UMID, PhilID and voter ID carry no expiry and that a passport and
    postal ID do. The config rule is the guard behind that fix, not a
    substitute for it: a model told the right thing can still read a control
    number or an issue date as an expiry.
  - **The model condenses the heading rather than transcribing it** — a real
    scan of a card printing three lines of agency name came back with
    `heading` of just "TIN ID". So the short name people actually use belongs
    on the keyword list beside the formal one, which is why `national id` sits
    with `philippine identification`.
  - **The panel reads one `never_expires` flag off the scan**, and the
    `neverExpires` prop the controller used to derive is gone. The component
    matched `scan.type` against that list, which can only ever answer for a
    type that never expires as a class — so a TIN ID showed "Not found" under
    Expires, inviting HR to type a date that does not exist. Both grains now
    answer through the same field, so there is no second copy of the rule for
    the screen to disagree with.
- **An expiry date already in the past refuses the upload**, alongside the
  name and type checks. It reads the **form field**, not the scan, and that is
  the design: the model misreads this date — a real upload returned "Jul 25,
  2024" from a line that was a date of birth — so blocking on what the scanner
  said would refuse a current document over a bad reading with no way out.
  Blocking on the field means correcting the date lifts the block, which is
  precisely the recovery a misread needs, and it also catches a past date
  typed by hand with no scan at all. The refusal is stated **on the field**
  rather than only in the scan panel, since a disabled button with no reason
  beside it is where somebody stops trusting the screen. The escape hatch for
  a document that genuinely must be filed expired is the one the name check
  already uses: upload it as a PDF, which the scanner never reads.
- **A PSA certificate is read a second time, in its own terms.** The general
  prompt asks for an ID card's fields — a number, an issue date, an expiry —
  and a Certificate of Live Birth has none of them, so the model answered with
  whatever sat nearby: the receiving date became `expires_at` and the mother's
  occupation became the `note`. It was not misreading the page; it was
  answering questions the page does not have. `readCivilRegistry()` runs only
  once the type has resolved to `psa`, and asks by the form's own numbered
  labels — item 1 the child, item 6 the mother's maiden name, item 13 the
  father. One extra call, on a handful of uploads.
- **A birth certificate names the employee's child, so the name check cannot
  be the gate.** `scanner.names_may_differ` exempts `psa` from the refusal —
  the check still runs and HR is still told whose name is on the paper. The
  question worth asking is instead **whether the employee is a parent on it**,
  compared with the same `nameMatches()` rules rather than a second set.
- **Two parents sharing given names is a misread, not a coincidence.**
  Measured: on a certificate whose father is "Leopoldo Jr. Arong Pikit Pikit",
  the mother came back as "Leopoldo Jr. Arong Bontigao" — her surname from the
  right box, his given names bled in. Compared on the **first two tokens**;
  everything-but-the-last was tried first and missed it, because his surname is
  two words and hers is one. Which box was misread cannot be told from the
  values, so neither is trusted and the parent check returns null rather than
  an answer — the same choice the validity ranges make where documents overlap.
- **The small model is good at reading and poor at judging**, and the design
  leans on that split. Numbers and dates come back right; free text does not.
  So the **title is derived from the validated `type`** (`scanner.labels`)
  rather than taken from what the model wrote — the type is checked against
  `EmployeeDocument::TYPES`, the free text is checked against nothing, so
  between the two the derived label is the sounder source. `text()` also
  flattens newlines and caps length: these land in single-line inputs, where
  a newline silently becomes a space and a block of OCR output arrives
  looking like a deliberate answer.
- **A dark feature is not a broken one**: with no key for the configured
  driver `can.scanDocuments` is false, the button is never drawn, and the
  endpoint 404s. Uploading by hand works exactly as before. `isEnabled()`
  deliberately asks config, not the network — calling the provider would make
  the button truthful but put an HTTP request in every page render and tie the
  test suite to a third party being up. A revoked key is handled where every
  other failure is: `read()` logs and returns null, the form stays empty, HR
  types the fields.
- **`php artisan scanner:check` asks whether the configured driver is really
  there.** `isEnabled()` reads config rather than the network, and the cost of
  that is a deployment with a key that is present but wrong, revoked, or out
  of quota: the button draws and every scan fails silently. This is the other
  half of that bargain — one real image through the real driver, run once after
  a deploy or an env change, reporting *switched off* and *configured but
  broken* as the different answers they are. Not automatic and it gates
  nothing.
- Images only, ≤5 MB (`config/scanner.php`). A PDF or DOCX upload skips the
  scanner rather than failing.
- **Setup**: get a free key at `aistudio.google.com/apikey`, put
  `GEMINI_API_KEY` in `.env`, then `php artisan config:clear` and
  `php artisan scanner:check`. Nothing to install — every driver is hosted,
  which is the whole reason the local one went. The same three lines are what
  a deployment needs, in the server's own `.env` and never in Git.
- `DocumentScanner::read()` is `protected` for one reason: the SDK's
  `MessagesService` is `final`, so tests stub that single method to exercise
  every rule around it without a key or a network call.

## Measuring the scanner (AI & Analytics)

**Scanner Accuracy (`/hr/scan-accuracy`)** answers how well the one AI feature
actually works — and it is the instrument that produces the accuracy figures a
write-up has to report, so it is one job with two deliverables.

- **The model's own `confidence` is not used, because it carries nothing.**
  Asked across six documents it answered "high" six times, including on the
  readings that were wrong. A dashboard built on a model's opinion of itself
  reports the model's mood. So accuracy is taken from the only thing that
  carries information: **whether HR kept the value or typed over it.**
- `document_scans` stores the proposal when the scan runs and completes it when
  the document is filed. **The row is written at scan time on purpose** — a
  scan somebody abandoned is a real failure, usually a reading bad enough to
  start over, and counting only the scans that ended in a filed document would
  flatter every figure on the screen.
- The upload posts a `scan_id` and nothing else about the proposal. The values
  are read back from the row server-side and the lookup is scoped to that
  employee, so a form cannot attach a stranger's measurement or claim an
  outcome it did not have.
- Only `type`, `title`, `issued_at`, `expires_at` are compared. The number and
  the name are *checks* rather than stored values, so there is nothing to
  compare them against, and counting them would score the scanner as always
  wrong on two of six fields.
- Comparison is case- and whitespace-insensitive: rewarding a trailing space
  would understate the scanner rather than measure it.
- **The headline is the clean rate**, not a per-field average — "how often does
  this just work" is the question a reader actually has. Duration is the
  **median**, because the first scan after a reboot loads the model into VRAM
  and takes forty seconds, and one of those misdescribes every other scan.
- The by-source table is where the five-way type precedence is *checked*
  against outcomes rather than asserted. If the ordering is wrong, it shows
  there.
- Behind `viewAuditLog` rather than a new permission: it is the same class of
  thing, a record of what the system and its users did.

## Hiring comes from Core 1 (Module 1)

**PrimePower does not hire into this system directly.** Core 1 recruits; Core 2
employs. A hire arrives over the API as an **endorsement**, waits in an inbox,
and becomes an employee only when somebody here approves it — so the act of
putting a person on the payroll has a decision, a decider, and a date attached
to it.

- **`/hr/employees/create` has a direct door again — and it has to say why.**
  It was once open with no record, then closed so every hire came through an
  endorsement; the owner asked for an **Add Employee** button back. A bare visit
  now opens the form as a *direct add*: `store()` without `endorsement_id`
  requires `direct_hire_reason` (10–500 characters) and writes a `direct_hire`
  audit row with the reason beside the created employee. That keeps the thing
  the endorsement existed to record — someone decided, who, and why — without
  pretending every hire comes from recruitment (rehires, transfers, urgent
  replacements do not). An `endorsement_id` that no longer exists still goes
  back to the inbox and creates nobody.
- **Bulk import stays open, because it is a different act.** Digitising a
  workforce that already works here is not hiring: there is no endorsement for
  somebody on their sixth year. `/hr/employees/import` and the batch document
  filer are untouched.
- **Approving opens the form; it does not create the record outright.** Core 1
  cannot know the basic salary, the pay frequency, the employment category, or
  which client is billed, and those are not fields to default silently on
  somebody about to be paid. `EndorsementController::show` names them under
  "Still needed here" *before* the click, rather than meeting the reviewer as
  six required fields afterwards.
- **The employee is still created by `EmployeeService::create()`.** Approving
  is creating, and that has always gone through one place — employee
  numbering, the photo, and the optional login all live there. `EndorsementService`
  deliberately does not create employees; it hands the form its starting values
  and links the record afterwards. A second creation path would eventually
  disagree with the first about one of the three.
- **`endorsement_id` is the only thing the form asserts about the endorsement.**
  The values are re-read from the row server-side and the ability re-checked —
  the same shape as `scan_id` on a document upload, and for the same reason: a
  form must not be able to claim what it was given.
- **What Core 1 may *not* send is as deliberate as what it may.**
  `basic_salary`, `client_id`, `department_id`, `position_id`,
  `employment_category`, `employment_status`, and `pay_frequency` are absent
  from `StoreEndorsementRequest` and dropped from the payload. Accepting them
  would let another system decide what PrimePower pays and who it bills, over
  an API token, with nobody here having agreed to either.
- **Everything else is optional, down to a reference and a name.** Refusing to
  *receive* somebody is the one outcome this queue exists to avoid: a
  recruitment system that cannot hand a candidate over because it does not know
  this system's pay frequency has not been integrated with, it has been locked
  out — and the workaround is re-typing the record by hand, which is what the
  handover was for. Missing detail is a gap on the review screen, not a refusal.
  Everything a payroll record needs is still required by `StoreEmployeeRequest`,
  at approval, where it always was.
- **`reference` is Core 1's own identifier, and it is what makes a retry safe.**
  A timeout on their side is indistinguishable from a failure, so they resend —
  and two rows for one person is two approvals, two employee numbers, and one
  person paid twice. A resend returns the existing row with **200** rather than
  201, so they can tell a duplicate from a fresh submission without either being
  an error. A resend after a decision returns the decided row: an answer already
  given is not undone by the sender repeating the question.
- **A decided endorsement is closed.** `EmployeeEndorsementPolicy::decide()`
  requires `isPending()`, so re-deciding cannot create a second employee from
  one endorsement or overwrite who was recorded as approving the first.
- **A rejection needs a reason.** Core 1 reads the outcome back from the row,
  and a recruiter told only "rejected" sends the same candidate again — the
  queue fills with the same unstated argument.
- **A named client makes the hire external, and that is not cosmetic.**
  `client_id` is *prohibited* on internal staff rather than ignored, so
  prefilling the client while leaving the category at its 'internal' default
  would hand back a form that refuses to save, with the error on a field the
  reviewer never touched. `formDefaults()` sets both together for that reason.
- **Master data is matched exactly, or not at all.** Core 1 sends "Driver" in
  words; this system files against a `position_id`. An unmatched title leaves
  the select empty rather than inventing a row — a spreadsheet must not reshape
  the org chart behind `manageOrganization`'s back, and another system is no
  more entitled to. Deactivated positions and clients are skipped: they are kept
  so history keeps what it was filed under, not so a new hire can be filed
  against them. The match is exact rather than fuzzy, unlike
  `DocumentScanner::nameMatches()` — a near miss between "Driver" and "Driver
  (Heavy Vehicle)" is two salary bands, and master data is chosen from a list
  and has one spelling.
- **`EmployeeEndorsement` is not the employees table with a status column.** An
  endorsement has no employee number, no salary, no contributions, and may never
  have any — filing it as a half-built employee would put it in the scope of
  every query meaning "our workforce", and the first one somebody forgot to
  exclude a rejected candidate from would be a stranger on a remittance.
- **The inbox is a work queue, so it opens on what is waiting.** `pending` is
  the default filter; `all` is an explicit choice. The summary tiles count the
  whole table, not the filtered view.
- **`php artisan endorsements:simulate` files one without Core 1**, going
  through `EndorsementService::receive()` rather than inserting a row — a
  shortcut that wrote straight to the table would prove the screen renders and
  nothing else. It prints the equivalent `curl` afterwards, which is what Core 1
  actually sends.

## Digitising an existing workforce (Module 1)

Two ways in on the Employees list, plus the paper form on the create screen —
the same act at different scale rather than three separate features. They sit
beside each other for that reason. **Add Employee** sits with them as the
direct door for a single new hire (above); **New Hires** stays the usual way in.

- **Bulk import (`/hr/employees/import`)** — `EmployeeImporter`. The columns
  are the columns `DataExportController` writes: export, correct the
  spreadsheet, import. A round trip that does not round-trip is a trap.
  - **A preview writes nothing**, unlike `AttendanceImporter` which commits as
    it reads. A malformed DTR row is a day somebody re-keys; forty employees
    created by accident are forty records and forty burnt employee numbers.
    The file is uploaded twice and re-validated on commit, so nothing the
    browser sends in between decides what is created.
  - Employee numbers are taken **at commit**, never at preview — previewing
    twice must not spend two.
  - `error` refuses the row, `warning` still creates it, and the split follows
    the system's own line: a missing government number does not stop somebody
    working, so it must not stop them being recorded either.
  - **`employment_category` is required, never defaulted.** Filing deployed
    staff as internal by omission keeps them off a client's headcount — the
    same reason the form refuses it.
  - An unknown department or position is a **warning**, and the employee is
    left unfiled. Inventing master data would let a spreadsheet reshape the
    org chart behind `manageOrganization`'s back.
  - **Dates are read day-first.** `Carbon::parse()` reads a slashed date
    month-first, so "15/02/2026" throws and "05/02/2026" silently becomes
    2 May — a hire date six weeks out, which moves regularisation, 13th-month
    proration, and the first payslip with it. Unambiguous slashed dates are
    read from the numbers; a genuinely ambiguous one is read day-first *and
    reported*, so the only case that can be wrong is the one somebody is asked
    to look at.
- **Paper 201 forms (`Scan form` on the create screen)** —
  `DocumentScanner::scanEmployeeForm()`. A spreadsheet imports in bulk; a
  filing cabinet does not. Same driver, different prompt and schema — the
  three drivers now take both as arguments so there is still **one** place the
  Gemini envelope can be wrong.
  - Only blank fields are filled, so a second scan cannot overwrite a
    correction.
  - **A value copied from a neighbouring line is dropped.** Measured: on a
    sheet with no RELIGION and no PERMANENT ADDRESS the model answered
    "Mother" (the emergency contact's relationship) and the SSS number. Two
    checks catch it — an address that is almost all digits is somebody's ID
    number wearing an address's label, and a value appearing in two unrelated
    fields is a copy rather than a coincidence, where the field with no shape
    of its own loses. `present_address` matching `permanent_address` is
    exempt: "same as present address" is what people write.
- **Batch filing (`/hr/employees/documents/batch`)** — `BulkDocumentFiler`.
  The single-document upload already knows whose file it is; here the question
  runs the other way — *whose is this?*
  - It reuses `DocumentScanner::nameMatches()` (made public for exactly this)
    rather than re-deriving the token and Levenshtein rules, the same reasoning
    that has `DeploymentReadinessChecker` reuse the two scanners.
  - **An ID number outranks a name**, as on the upload form.
  - **Two people fitting one name is not a match.** Taking the first would file
    the document under a coin toss, and the error is silent afterwards.
  - Behind `EmployeePolicy::fileDocumentBatch`, a class-level ability for the
    same reason `viewArchive` is one: it is asked before any record is in hand.
    The scope is re-derived at the write, never trusted from the form.
  - **`process()` files what it can defend and hands back the rest.** This is
    the one place in the system where a reading becomes a stored fact without
    a person confirming it, and it is a decision about where attention is
    *spent* rather than about trusting the model more: retyping twenty
    documents to catch the two that are wrong puts the same care on the
    eighteen that are right, and care spread evenly over twenty rows is care
    nobody is really taking by row fifteen. `examine()` still exists, still
    writes nothing, and is what runs with the switch off.
  - **Six gates, in `config('scanner.autofile')`, each a failure this scanner
    has actually produced.** The owner must be found by a strength the batch
    accepts (`number` is a value already on the 201 file — the only evidence
    in a reading with a person behind it; `name` is one exact match and no
    other). The type must be `type_certain` — the three weaker sources are
    exactly the ones measured wrong. A name contradicting a number match is
    held, because a number keyed against the wrong person puts somebody else's
    licence in this file. An expiring type with no date read is held, since
    `CredentialExpiryScanner` reads `expires_at` and a null one is a licence
    that never reaches a renewal queue — invisible rather than wrong. An
    already-lapsed document is held: filing one is legitimate and common,
    doing it silently is not.
  - **Every gate *holds*, never refuses.** A held row lands in the review
    table the screen always had, with the reason named — "held" with no cause
    sends somebody to work out what the system already knows.
  - **`employee_documents.filed_automatically` is what makes this defensible
    rather than merely convenient.** It is on `EmployeeDocumentResource` and
    badged on the 201 file, so "the system decided this" can be told from
    "somebody typed this" by whoever reads the record, not only by whoever can
    write a query. `uploaded_by` still names the person who fed the batch
    through. Both paths write through one private `store()`, so the confirmed
    row and the automatic one cannot come to look different.
  - `SCANNER_AUTOFILE=false` restores the old behaviour exactly, and a test
    asserts that rather than the comment being the only claim.
## Record checks (AI & Analytics)

**Record Checks (`/hr/record-checks`)** asks the question the other two checkers
cannot: not what is *missing* (`OnboardingChecker`) or what is *lapsing*
(`CredentialExpiryScanner`), but **where the records disagree with each
other** — a number keyed against two people, a licence filed twice, a document
dated before the employee was born, a scan that named somebody the file does
not.

- **There is no model behind this screen, deliberately.** Every finding is a
  regex or a string comparison: a TIN with eleven digits is wrong for a reason
  that can be written down, and a rule that can be written down should not be
  inferred by something that might hallucinate it. The one non-trivial
  comparison — are these two names the same person — reuses
  `DocumentScanner::nameMatches()` rather than restating it, so this screen and
  the upload form cannot disagree about the same employee.
- `config/integrity.php` holds the agencies own lengths: SSS 10, PhilHealth 12,
  Pag-IBIG 12, TIN 9 or 12, licence a letter and ten digits. **Length and
  digits only** — none of the four publishes a checksum, and inventing a check
  digit rule would refuse real numbers to look clever.
- **A duplicate number is looked for across every record, not only the ones in
  scope.** A collision with somebody this user cannot see is still a collision,
  and hiding it would let the duplicate survive because of who happened to be
  looking. It is reported on *both* records: until somebody says which is
  right, both are wrong.
- **A missing number is not a finding here.** That is `OnboardingChecker` job,
  and saying it twice on two screens teaches people to ignore both.
- Only `single_copy_types` are checked for duplicates. Clearances and
  certificates accumulate legitimately — a fresh NBI clearance every year —
  and a superseded copy whose expiry has passed is a renewal with its history
  kept, which is correct.
- **`EmployeeFactory` issued three letters and eight digits** for a licence,
  which is not a shape the LTO produces, and this screen rightly flagged every
  seeded driver. The factory now issues `?##-##-######`. It made the same
  mistake twice over: its restriction codes were `1,2` and `3,8` from the
  retired numeric scheme, which against the real DL codes are simply codes LTO
  does not issue — so it now seeds real ones, and an expiry on the seeded
  employee's own birthday. Synthetic either way; the point is that it should
  look like what is really issued.

## Driver's licences (Module 1)

Modelled on a real card rather than on what the schema happened to hold. Two
things came out of that, and the second is the important one.

- **DL Codes replaced "restriction codes".** The record held one free-text box
  named after the retired numeric scheme (1–8), which a card issued today does
  not carry. A current licence prints two panels: **DL Codes**
  (A, A1, B, B1, B2, C, D, BE, CE — the vehicle classes) and **Conditions**
  (1–5). For a fleet operator the first is not paperwork: a DL code is the
  legal ceiling on what somebody may be put behind the wheel of, and putting a
  code-A holder on a truck is the same class of problem as dispatching a lapsed
  licence. `config/licenses.php` holds both lists in the agency's own wording.
- **Condition 4 reaches scheduling.** "Daylight driving only" means that driver
  cannot lawfully take a night run — which is neither a missing document nor a
  lapsed one, so nothing on Deployment Readiness would have said it and the
  dispatcher would have found out at the depot. `operational_conditions`
  surfaces it there as a **warning**, not a block: it rules out some runs, not
  the roster.
- **A licence expires on the holder's birthday**, which is checkable against
  the employee's own `birth_date` — a mismatch means one of the two was keyed
  wrong. Reported, never enforced: renewals around a birthday and extensions
  granted by memorandum are real enough that refusing the entry would reject
  correct records to catch wrong ones.
- The number is an agency code, a two-digit year, and a six-digit serial
  (`N02-24-001292`), stored without the dashes — which is why the integrity
  rule reads a letter and ten digits.

### Verifying one against LTO

**LTO publishes no API an employer can call, and this system does not pretend
it does.** LTMS is citizen-facing: a holder signs in to manage their own
licence. The commercial "LTO verification APIs" that advertise otherwise are
private wrappers whose data source the agency does not vouch for. Building
against one, or scraping the portal, would put a green tick on this screen that
nobody could account for.

So the claim is split in two, and the screen labels each half as what it is:

- **Structure** — `LicenseVerifier`, automatic and database-free like the other
  scanners. The number's shape, the DL codes, the conditions, the birthday
  rule. This catches a typo, a transposition, and a card filed under the
  retired scheme. **It cannot catch a well-made forgery and never claims to** —
  which is why the method is `isStructurallySound()` rather than `isValid()`.
- **Authenticity** — a person checks the LTMS portal and records what it said,
  against their name and the date. The note is **required and free text** on
  purpose: "active", "suspended until March", and "no record found" are three
  different answers, only one of them is good news, and a boolean loses two of
  them.

`verification_valid_days` marks a check **stale**, not expired. A licence can
be suspended the day after somebody looked at it, so the date says "nobody has
checked this in a year" — not "this licence is now invalid".

Findings appear on the employee's own screen and on Record Checks, which reuses
`LicenseVerifier` rather than restating the rules, the same reasoning that has
that screen reuse `DocumentScanner::nameMatches()`.

## Credential expiry (Module 1)

**Credentials** watches the `expires_at` already stored on every 201-file
document. `EmployeeDocument::isExpired()` answers one document at a time and
only once it is too late; `CredentialExpiryScanner` looks *forward* instead —
config-driven and database-free, the same shape as `AttendanceExceptionScanner`.

- **The warning window is per document type**, in `config/credentials.php`, and
  it is not cosmetic: an LTO licence renewal wants weeks of lead time (60 days),
  a certificate does not (30). Widening a window is a config edit.
- `blocking_types` marks documents whose expiry legally stops the employee
  working — a driver with a lapsed licence may not drive, and the liability is
  the company's. That is a harder flag than an expired certificate.
- Scoped through `EmployeeService::scopedQuery()`, so it doubles as
  self-service: an employee sees their own licence running out, no second
  screen needed.
- It gets **its own topbar indicator, not the bell.** A link has one
  destination, and the bell already means "leave waiting on you". The indicator
  hides at zero — an always-lit icon stops being read.
- `--warning-foreground` was added for its badge and **flips between modes**:
  near-white on light mode's darker orange, near-black on dark mode's brighter
  amber, which would otherwise sit near 2.5:1.

## The org directory (Module 1)

**Org Directory (`/hr/directory`) is a colleague's screen, not HR's record.**
It answers "who is in Operations, and how do I reach them" — a question
everybody has and that the employee directory beside it refuses to answer for
anybody but HR.

- **The widening and the narrowing are one decision.** `EmployeePolicy::viewDirectory`
  returns true for every signed-in user — a deliberate departure from
  `viewAny`, where a supervisor sees only their reports and an employee only
  themselves. That scoping is right for a screen carrying salary, government
  numbers, and the 201 file; it is useless for a directory, which is a list
  nobody can read if it holds one person. What makes the widening safe is that
  the *fields* narrow to match, in `DirectoryController::card()`: a name, an
  employee number, a position, a department, a client, and a work contact.
  Widening the audience without narrowing the fields would be a leak;
  narrowing the fields without widening the audience would be pointless.
- **`DirectoryController` deliberately does not call `EmployeeService::scopedQuery()`.**
  That service narrows by role because it feeds screens with sensitive fields.
  Applying it here would give every non-HR user a directory of themselves. The
  protection on this screen is the field list, not the row list — and
  `DirectoryTest` asserts it by searching the whole rendered payload for a
  salary, a TIN, an address, and a bank number.
- **Grouped department → position → person**, because that is the shape of the
  question: somebody looking for "a driver at Metro Fleet" is walking down the
  org chart, not searching a flat list of 41 names. The grouping is built
  server-side so the page does not re-derive it on every keystroke.
- **Somebody with no department is still listed.** A new hire filed before
  their department was decided is still a colleague to reach, and dropping
  them would make the directory quietly wrong rather than visibly incomplete.
- **Separated and inactive staff are not.** A directory is for reaching people
  who are here; somebody who has left is a record, which is what the archive
  and the employee screen are for.
- **A row is a link only for somebody who may follow it.** `card()` asks
  `EmployeePolicy::view` per person — HR may open anybody, a supervisor their
  own reports, an employee themselves — and renders a plain row otherwise.
  Drawing a link that 403s is worse than drawing none: it says there is
  something behind it *and* that the reader is not trusted with it, which is
  the least useful pair of facts a screen can offer. Measured on the seeded
  set: 38 rows clickable for HR, 1 for a rank-and-file login.
- **Credential findings ride only for that same viewer.** What is lapsing on
  somebody's 201 file is document data and belongs to the gate the *record*
  has, not to the directory's open one — so the key is null for everybody
  else and the badge is not drawn. It is read from `CredentialExpiryScanner`,
  scanned once over the whole set and indexed by employee, so this screen, the
  Credentials screen, and Deployment Readiness cannot disagree about the same
  licence.

## Master data (Module 1)

**Departments** and **Positions** are the org structure every employee record
is filed against, and every other module reads: KPI scoping, payroll grouping,
and the salary band Salaries & Adjustments checks a new rate against.

They used to be two tables stacked on one cramped Settings page. They are two
full-width screens under Employee Information now — HR maintains them while
filing people, not while configuring the system — and `/settings/organization`
redirects to `/hr/departments` rather than 404ing.

- Both reuse the **`manageOrganization` gate**, not a new permission: moving a
  screen does not change who is allowed to shape the org chart. Supervisors and
  employees get a 403, so the nav entries carry `roles` to match.
- **Anything in use is deactivated, never deleted** — a department with
  employees or positions under it, a position somebody holds. History has to
  keep the department and job title it was filed under. Only a genuinely unused
  row is removed, and the confirm dialog says which of the two will happen
  *before* the click.
- Search uses `scopeSearch` on both models with the **`ilike`/`like` driver
  switch**, the same shape as `Employee::scopeSearch` — a plain `like` matches
  case-sensitively on Postgres and would silently return nothing.
- The **summary tiles count the whole table, not the filtered view**. A summary
  that moves while you type is not a summary.
- Positions with no salary band are **counted, not flagged as an error**. A
  band is optional and advisory, but a rate keyed against a bandless position
  has nothing to be compared to, which is worth seeing.

## Educational & qualification records (Module 1)

**A Qualifications section on the employee's own profile**, between Employment
and Documents — three lists: schooling, completed trainings and certifications,
and skills. It answers what somebody is *qualified for*, which the rest of the
201 file does not: employment says what they are paid to do, documents say what
paper is on file, and neither says whether this driver may be sent on a job
that needs a forklift ticket.

- **Three tables, not one with a `type` column.** The three are alike only in
  belonging to a person: a school has a course and a year, a training has a
  provider and an expiry, a skill has a grade and neither. One table would be
  eleven mostly-null columns and a validation rule per type pretending to be a
  schema.
- **The paper stays in Documents.** A diploma, a transcript, and a TESDA
  certificate are *evidence*; these rows are the *fact*, and the fact survives
  the paper going missing. Filing it twice would be two answers to one
  question — which is why `diploma` and `transcript` became document types of
  their own rather than fields here.
- **Highest attainment is derived, never stored.** `Employee::highestEducation()`
  ranks by the order of `config('qualifications.education_levels')`, so adding
  a degree cannot leave a stale flag behind, and an unknown level ranks *last*
  so a level dropped from config cannot outrank a real one. The same config
  drives the form's dropdown, sent from the controller rather than restated in
  the component: the order is what makes "highest" mean anything, and a second
  copy would drift from the one the server ranks by.
- **A blank expiry on a training means "does not lapse", not "not typed".** The
  screen says so in those words, the same distinction the document scanner
  draws — "—" there would read as a date somebody forgot. `expiryState()` reads
  `credentials.warning_days.certificate` rather than holding its own number, so
  this row and the Credentials screen cannot disagree about the same TESDA
  card.
- **No service, deliberately.** A school and a training course are recorded
  facts, not derived ones: nothing computes off them and nothing else has to
  agree with them, so a service that only forwarded to `create()` would be a
  layer pretending to earn its place. The rules that do exist — which levels,
  which grades, what a plausible year is — live in `config/qualifications.php`.
- **Writes are gated on `EmployeePolicy::update`, not a permission of their
  own**: editing somebody's qualifications is editing their record. Reading is
  outside `viewSensitive` for the opposite reason — a school and a forklift
  ticket are not salary, and a supervisor deciding who to send on a run
  legitimately needs them.
- **A child row is checked against its parent.** Both ids are in the URL and
  Laravel binds them independently, so `/employees/7/skill/12` resolves happily
  when skill 12 is employee 9's — and the policy check passed on employee 7.
  Without `belongsTo()`, being allowed to edit one person is being allowed to
  delete a row off anybody. Guarded by a test.
- **Duplicate skills are caught case-insensitively in PHP, not by a unique
  index.** "Forklift" and "forklift" are the same skill and a list holding both
  is a list nobody can count — but a database uniqueness rule compares by the
  driver's collation, which differs between the Postgres this runs on and the
  SQLite the tests use.
- **`EmployeeEducation` states its table name.** Laravel treats "education" as
  uncountable and looks for `employee_education`; renaming the table to match
  would leave one singular table in a schema where nothing else is.
- **Skills are free text today, and that is the known limit.** The upgrade is a
  skills library beside Departments and Positions, with employees tagged from
  it — which is what "who has a Forklift NC II" needs, and what a client asking
  for five certified operators is really asking.

## 201 file completeness (Module 1)

**201 File Status** answers what Credentials doesn't: not "what is about to
lapse" but "what was never filed". `OnboardingChecker` is config-driven and
database-free, the same shape as the other scanners — `config/onboarding.php`
holds what a complete file needs, so a new client audit demanding another
document is a config edit.

- **Requirements are per position.** A dispatcher does not need a driver's
  licence; a driver may not legally work without one, matched on a fragment of
  the position title.
- The same **blocking** distinction as Credentials: a missing contract or
  licence stops deployment, a missing résumé is untidy.
- Missing **government numbers** are reported too, and deliberately as
  non-blocking — they don't stop the person working, they stop the company
  filing for them. Compliance already catches this at remittance time, when
  the filing is due; this catches it while it is still cheap to fix.
- A complete file is not a finding. Listing every compliant employee would
  bury the ones that aren't.

## Time & Attendance (Module 2)

**Rebuilt from scratch** after the first version was deleted on the owner's
request (every old file is recoverable from commit `4a9a355`; a `pg_dump` taken
before the drop is in `storage/app/backups/`). Seven screens under one
`Timekeeping` sidebar entry, all under `/hr/timekeeping`, in
`Http/Controllers/Timekeeping/`: **Daily Time Records**, **Shifts & Rest
Days**, **Holiday Calendar**, **Overtime Requests**, **Time Corrections**,
**Cutoff Closing** and **Client Timesheets**. Tables come from
`2026_09_16_000001_create_time_and_attendance_tables`. Old links
(`/hr/timekeeping/period`, `/reports`, …) redirect to the records with a reason.

- **`AttendanceCalculator` is database-free and unit tested**, because payroll
  multiplies its output by money. Grace forgives lateness entirely; past it,
  lateness counts from the shift start. A shift or a time-out at or before the
  start ends the next day. Night differential is 22:00–06:00 per night, never
  double counted. The break comes off only after five hours (the Labor Code's
  meal period), so a four-hour rest-day job is four hours. No time-in is
  absent / rest day / holiday by the calendar; a time-in with no time-out is
  `incomplete` and computes nothing.
- **`WorkCalendar` is the one answer to "was this a working day, for whom"**,
  shared by the DTR, `LeaveService::workingDays()` and payroll so the three
  cannot disagree about a Tuesday. It resolves the `employee_shifts` row in
  force on the date (history, not a column — moving somebody to nights in
  October leaves September computed against days), and somebody with no
  schedule rests Saturday and Sunday. Memoised per request.
- **`TimekeepingService::record()` is the only door into `attendance_logs`** —
  HR's entry, the biometric CSV import, an approved correction and the seeder
  all go through it, so every day is computed the same way and refused the same
  way once its cutoff is closed. Looked up with `whereDate`, never
  `updateOrCreate` (see Gotchas). An absence an approved leave covers is stored
  `on_leave`.
- **Only HR writes a day** (`AttendanceLogPolicy::manage`). Everybody else —
  HR's own days included — files a **Time Correction**; approving rewrites the
  day through `record()` in one transaction, and a blank punch on the request
  keeps the punch already recorded. The approver sees the record as it stands
  now, read live.
- **Overtime and corrections: file your own, somebody else decides.** `create`
  needs an employee record with no HR exemption; `decide` is HR or the
  requester's own supervisor and never the requester. A rejection needs
  remarks. Only **approved** overtime hours are paid; the raw minutes past the
  shift are shown beside the request so the approver can compare.
- **Cutoff Closing freezes a payroll period's days.** Periods come from
  Payroll, so the cutoff and the run cannot cover different days.
  `TimekeepingService::assertOpen()` refuses records, imports, holiday edits,
  filing and decisions dated inside a closed period. **Closing is refused while
  anything is undecided** — an incomplete punch, pending overtime or a pending
  correction — because after closing nothing could decide it. HR closes; only an
  admin reopens, with a reason stored on the row.
- **Client Timesheets are the agency's half of the week.** The client is who
  saw the work done, so each client's deployed staff get a sheet per period:
  prepared (a **snapshot** of `TimekeepingService::summaries()` into
  `client_timesheet_lines`), marked sent, then confirmed or disputed with the
  representative's name and remarks. A confirmed sheet cannot be prepared again
  — what the client signed is not silently regenerated; a disputed one can,
  after the records are fixed. Printed from the browser like payslips.
- **Holidays are read by three modules**: the DTR names the day, leave does not
  charge for it, payroll pays the premium. Regular sorts before special, so a
  date carrying both pays the higher. Only fixed-date holidays are seeded (this
  year and next); movable ones are added when proclaimed.

**What payroll takes from it** — `PayrollService::gatherInputs()` reads
`TimekeepingService::summaryFor()`: days worked, hours, lateness, undertime,
night differential, **unexcused** absences (not those an approved leave covers —
a paid leave is inside the salary, an unpaid one is deducted once, as leave)
and approved overtime hours. `holidayPremium()` pays +100% of the hourly rate
for hours worked on a regular holiday and +30% on a special day, capped at a
normal day (past that is overtime, paid only if approved). Work on a rest day
earns nothing extra unless overtime is filed — a stated limit.

**`PayrollReadinessChecker`** now checks the DTR before the money:
`incomplete_punches` and `disputed_timesheets` are **blockers**;
`pending_corrections`, `pending_overtime`, `missing_records` (nobody recorded a
day for someone on the payroll), `unconfirmed_timesheets` and `cutoff_open` are
warnings. The unpaid-suspension warning goes silent for a suspension once every
one of its days is on the DTR as absent, on leave, rest day or holiday. None of
them hard-stops a run.

**The bell** counts overtime and corrections the viewer may decide (HR:
everyone's; supervisor: direct reports'; never their own), as
`NotificationFeed::awaitingDecision()`.

Not rebuilt from the old module, on purpose for now: the Exceptions scanner,
the edit History screen (the audit log still records every change), the
per-employee calendar, the dashboard attendance tiles and `/api/v1/attendance`.
## Leave (Module 3)

**The employee files; HR or an admin decides.** One step, and nobody signs off
on their own leave — HR included.

- **Everybody files their own, HR included.** `LeaveRequestPolicy::create`
  requires an employee record rather than exempting `isHrAdmin()`, and
  `StoreLeaveRequestRequest` refuses an `employee_id` that is not the filer's.
  It used to work the other way: HR passed the policy on its own and the form
  offered a picker for whose leave to file. That made HR both the filer and the
  sole approver of the same request, which is the one thing the rest of this
  module is built to prevent — and it put a "File Leave" button on the screen
  HR opens to *decide* on other people's leave. An HR staff member who is on
  the roster still files their own here, like everybody else.
- **The supervisor endorsement is gone.** It was step one of two: the
  supervisor endorsed, then HR confirmed, and only that second step ever moved
  credits. The first signature therefore bought a delay rather than a decision,
  so `endorse` and `confirm` collapsed into one ability, `decide`, which only
  `isHrAdmin()` holds. Supervisors still **see** their reports' leave —
  `view` is untouched, and reading is not deciding.
- **`supervisor_approved` stays on the model, and it is not dead code.** Rows
  sitting in it when the rule changed are real requests somebody is waiting on,
  so `decide` accepts that status too and `OPEN_STATUSES` still reserves their
  credits. No new row ever enters it.
- `LeaveService::workingDays()` skips holidays and the employee's rest days, so a
  Friday-to-Monday request over a weekend costs two days, not four. It reads the
  schedule through `TimekeepingService` — Module 2 and 3 share one calendar.
- **Open requests reserve credits.** `availableCredits()` subtracts days on
  requests still awaiting a decision, so the same credit cannot be filed against
  twice before either is approved.
- Cancelling or rejecting an already-approved request hands the credits back.
- Unpaid types (`is_paid = false`) never touch the ledger.
- Attachments live on the private disk and download through
  `hr.leave.attachment` after a policy check, same as 201-file documents.
- **The topbar bell is a dropdown** (`NotificationBell.jsx`), and its badge
  counts only what is waiting on *this* person to decide:
  `NotificationFeed::count()` — pending leave (HR, never their own), overtime
  and DTR corrections (HR, or a supervisor for direct reports), and Core 1
  hires (whoever may open the inbox). Shared lazily as `notificationCount`.
  The list itself is `GET /notifications`, fetched **when the bell opens**
  rather than on every page, and adds what is informational rather than
  actionable — expiring documents in the viewer's scope and decisions on their
  own leave from the last 14 days. Nothing is stored as a notification, so
  there is no read state to go stale: an entry disappears when the thing it
  points at is dealt with. Supervisors are not counted for leave, because the
  decision is HR's alone and a badge they cannot act on teaches them to ignore
  the bell.

**Credits accrue, they are not handed out.** The screen used to grant every
active employee a full year's entitlement on 1 January — wrong in the direction
that costs money, since someone hired in November started with the same fifteen
days as someone who had worked all year. `LeaveAccrualCalculator` earns credits
per **whole calendar month** of service instead: a 15-day type accrues 1.25 days
a month, and rates and any waiting period live in `config/leave.php`.

- **Whole calendar months, not anniversaries.** 1 January to 31 December is 364
  days — one short of twelve anniversary months — so anniversary counting would
  leave a full-year employee at 11/12 of their entitlement.
- `LeaveAccrualService::accrue()` **recomputes rather than adds**, so it is safe
  to re-run and needs no "already accrued this month" state to keep in step.
- A balance already spent past what accrual grants is **held at the used figure,
  not reduced**: dropping earned below used would invent a negative balance and
  imply the approved leave was never valid. Those cases are reported to HR
  instead.

## Payroll (Module 4)

**What counts as "already earned" is defined once**, on
`PayrollRun::REPORTABLE` / `scopeReportable()`: approved and paid, never a
draft. 13th-month pay, compliance remittances, and final pay all have to agree
on it, and they used to each keep a private copy of the list — which is how
final pay came to quote 13th month against payslips the 13th-month screen did
not even show. Read it from the model; do not re-write the condition.

**Statutory rates live in `config/payroll.php`, not in code.** SSS, PhilHealth,
Pag-IBIG, and the TRAIN withholding tables are all config values, so a new
circular is a config edit. `StatutoryContributionsTest` asserts every bracket
against the published tables — change the config and the expectations together.

Two database-free, unit-tested classes do the arithmetic; `PayrollService` only
gathers inputs and stores results:

- `StatutoryContributions` — contributions and withholding tax.
- `PayrollCalculator` — earnings, deductions, net pay, and the payslip lines.
  Daily rate is `monthly × 12 ÷ 261`; hourly is daily ÷ 8.

Rules worth knowing before touching it:

- **Only *approved* overtime is paid.** Attendance records raw time past the
  shift; the `OvertimeRequest` decides what is payable.
- **Only *unpaid* leave is deducted.** Paid leave is already inside the salary.
- Taxable income is gross less non-taxable allowances, less time not worked,
  less the employee's statutory contributions.
- Contributions are assessed monthly, so a semi-monthly run withholds half.
- **Loans only move at approval.** A draft can be recomputed freely; approving
  is the point of no return.
- **Separation of duties:** HR staff compute and submit, an *admin* approves,
  and never the same person who processed the run.
- Employees see their own payslips only once the run is approved or paid — a
  draft is still being corrected.
- The payslip page is print-styled; "Save as PDF" is the browser's own print
  dialog, so there is no PDF dependency to maintain.

**Salaries & Adjustments — where the rate is set.** `basic_salary` used to be a
bare field on the employee form: HR typed over it, and the old rate, the date
it changed, and the reason were gone. The `salary_adjustments` history is now
the record, and `basic_salary` is a **cache of today's rate**. The two answer
different questions and must not be swapped:

- `basic_salary` — "what does this employee earn now?" (forms, directory)
- `SalaryAdjustmentService::rateAsOf()` — "what were they on over this
  period?" **Money reads this one.** `PayrollService::gatherInputs()` and
  `SeparationService` both call it, so a raise keyed in late cannot rewrite a
  period that closed before it took effect.
- `previous_salary` is **stored, not derived** from the preceding row: the row
  records a decision, and an audit needs the two figures that were on the paper
  that was signed.
- Back-dating takes its "from" figure from the rate in force *on the effective
  date*, not from the employee's current field.
- A **future-dated** adjustment does not move `basic_salary` until its date.
  `php artisan salaries:apply-due` recomputes the cache (safe to re-run, same
  shape as leave accrual). Money never depends on it — a missed run costs a
  stale figure on a form, never a wrong payslip.
- Deleting reads `previous_salary` **before** the delete and applies it
  directly when no history is left: with an empty history `rateAsOf()` falls
  back to `basic_salary`, which still holds the rate that adjustment set, so
  removing an employee's only adjustment would otherwise keep the raise.
- Position salary bands are shown and flagged, never enforced — HR pays outside
  a band deliberately often enough that refusing the entry would be wrong.
- Admin-only to delete: re-pointing someone's rate is a change to what they are
  paid, not a tidy-up. Supervisors are shut out entirely, matching
  `EmployeePolicy::viewSensitive`.

**Readiness — the Module 2 → Module 4 gate.** `gatherInputs()` reads attendance
without judging it, so a forgotten time-out quietly understates hours and a
pending `OvertimeRequest` quietly pays nothing. `PayrollReadinessChecker` runs
those checks *before* the money is computed and shows them on the run screen:
`blocker` (paying from this would be wrong) versus `warning` (payable, but
someone should have decided). It reuses `AttendanceExceptionScanner` rather
than re-deriving what a bad record looks like. It is also where an external
fact that must not move money on its own surfaces instead —
`unservedSuspensions()` reports a Core 4 suspension the DTR does not account
for (see the API section above). **Nothing here hard-stops a
run** — a payroll that cannot be run is worse than one that warns loudly — and
the panel is hidden once a run is approved, since the figures are then history
and the advice can no longer be applied.

**13th-month pay** (PD 851) is `ThirteenthMonthCalculator` — database-free and
unit tested, like the other calculators. The rule is one line, *total basic
salary earned ÷ 12*, and the whole difficulty is in **earned**: a payslip's
`basic_pay` is the nominal period salary, because `PayrollCalculator` takes
lateness, undertime, absences, and unpaid leave off *separately* as deductions.
Using `basic_pay` alone would pay a full 13th month to someone absent a month
unpaid, so those four deductions are subtracted back out. Allowances, overtime,
night differential, and holiday premium are excluded — it is computed on basic
salary, not gross. Pro-rating needs no special case: a mid-year hire simply has
fewer payslips. Like Compliance, only *approved* and *paid* runs count, and the
screen states the 24 December deadline rather than leaving it as a date the
reader has to know.

**Compliance** is the remittance and BIR reporting screen: SSS (R-3),
PhilHealth (RF-1), Pag-IBIG (MCRF), and the BIR alphalist, each with a CSV
export carrying a control total. `ComplianceReportBuilder` is database-free and
**reads figures back from stored payslips, never recomputes them** — otherwise
a new SSS circular in `config/payroll.php` would silently rewrite what was
already remitted. Only *approved* and *paid* runs are reportable; a draft is
still being corrected. An employee missing the relevant government number is
flagged, because the filing cannot include them until it is on their 201 file.

**Separation & Final Pay** closes the lifecycle the rest of the system opens.
`FinalPayCalculator` is database-free like the rest: unpaid salary + pro-rated
13th month + convertible leave, less outstanding loans. `SeparationService`
gathers those inputs from the modules that already know them — payroll for what
was paid, leave for what was not taken, compensation for what is still borrowed.

- **Figures are snapshotted on the row, not recomputed on read**, the same
  reason payslips are: a later change to salary, leave credits, or a loan
  balance must not rewrite a settlement already handed over.
- **Separation pay is deliberately excluded.** It is owed only for authorised
  causes at rates that depend on which cause applies, and getting that wrong in
  either direction is a labour case — that is a decision HR records, not
  arithmetic the system performs silently.
- The **loan deduction is capped at what the settlement holds.** Anything left
  is a debt to collect, not a negative cheque to hand someone; the breakdown
  says how much still stands.
- Status **follows the clearance checklist** rather than being set by hand, so
  the two cannot disagree. Blocking items (`config/separation.php`) hold up
  release; the rest are recorded only — withholding a final pay over an
  unreturned lanyard is not a defensible reason to miss a statutory deadline.
- Release is the point of no return, and follows payroll's **separation of
  duties**: HR staff prepare, an *admin* releases. It freezes the figures,
  settles the loans against the deduction, and marks the employee separated and
  inactive.
- The list counts down against DOLE Labor Advisory 06-20's 30 days, which is
  what turns it from a table into a work queue.

## Performance (Module 5)

The rating scale, the 360 reviewer weights, and the performance bands live in
`config/performance.php`. `PerformanceScorer` is database-free and unit tested;
`PerformanceService` handles the cycle workflow.

- A review's score is the **weighted mean** of its KPI ratings. Weights come
  from the employee's scorecard, never from the submitted form — a reviewer
  rates, they do not decide what counts.
- An employee's score for a cycle blends the four perspectives. **Missing
  perspectives are re-normalised, not scored as zero**, so someone with only a
  supervisor review still scores on the same 1–5 scale.
- Rolling out a cycle builds every scorecard from the KPI library and creates
  the self and supervisor evaluations. It is **safe to re-run** — existing
  scorecards and reviews are left alone.
- Only the assigned reviewer edits, only while the cycle accepts submissions,
  and only a draft. The employee acknowledges afterwards; a self review needs
  no acknowledgement.
- A KPI already on a scorecard is deactivated rather than deleted.

## Settings

Seven sections under `/settings`, sharing `SettingsLayout` (section list on the
left). Company-wide sections are **admin-only**; Appearance and Security belong
to every signed-in user.

- Values live in a **key/value `settings` table**, namespaced (`company.name`),
  JSON-valued, read through one cached map. A new preference is a new key in
  `Setting::DEFAULTS`, not a migration.
- **Users & Access** is the only place besides the employee form where a login
  is created — an admin cannot demote or deactivate themselves, and
  deactivating revokes API tokens.
- **Organization moved out.** Departments and positions are master data under
  Employee Information now (see below); `/settings/organization` redirects to
  `/hr/departments` so old links still land.
- **Security** replaced the starter kit's `/profile`, which now redirects there.
- **Only an admin renames themselves.** `SettingPolicy::renameSelf` gates the
  Name field on Settings > Security; HR staff, supervisors, and employees see
  their name stated rather than editable. `users.name` and the employee record
  are meant to name the same person, and **nothing in this system reconciles
  them** — Record Checks compares scanned documents to employees, not logins to
  employees — so a drift is silent and permanent, which is a reason to prevent
  it rather than to detect it. An admin keeps the field because an admin need
  not be an employee at all: a pure system account has no 201 file to be held
  to, and locking it would leave a wrong name with nowhere to be fixed.
  - **The password is deliberately outside this.** It is a credential rather
    than a display name, and the Security screen exists so every signed-in
    user manages their own.
  - The rule is enforced in `updateProfile`, not just hidden: a posted name
    from anyone who may not set it is **ignored rather than refused**, and
    nothing wrong is stored either way.
- **Appearance** (theme, sidebar default) is per-device and lives in
  `localStorage`, not the database.

## Security

The access rules themselves are Module 1's (`scopedQuery()` + policies, salary
behind `viewSensitive`). What follows is the layer underneath them.

- **Sign-in is a username and a password, and there is no second factor.**
  Both second factors — Fortify's authenticator-app 2FA and the emailed
  one-time code — were removed on request, along with their routes, pages,
  middleware (`RequireOtp`), service, config and tests; migration
  `2026_09_13_000001_replace_second_factors_with_username_login` drops their
  columns and adds `users.username`. **This is a known gap** on a system that
  holds salary, government identifiers and bank details, and the first
  control to restore before real employee data goes in.
  - **A company login should not hang off a personal inbox**, which is the
    reason for the username. The role already lives on the account, so
    `admin@primepower.test` and `hrstaff@primepower.test` say who is signing in
    and what they may open.
  - **A login account has no email, and nothing on the web side sends mail.**
    The forgot-password link, the emailed reset flow (`Features::resetPasswords`,
    `ResetUserPassword`, the `ForgotPassword`/`ResetPassword` pages) and email
    verification (its three controllers, routes, page and the `verified`
    middleware) were removed on request, and migration
    `2026_09_13_000002_make_login_accounts_email_free` makes `users.email`
    nullable and drops `email_verified_at` and `password_reset_tokens`. **A
    forgotten password is reset by an admin on Users & Access**, which shows
    the new temporary password once. Existing emails were kept rather than
    wiped, because the API login still accepts one (below) and the data could
    not be put back.
  - **The employee's email is contact information, not a login.** The employee
    form no longer needs one to create an account and no longer copies it onto
    `users`; the 201 file's email field is untouched.
  - **A username is shaped like a company address and is not one.** Every
    username ends in `@primepower.test` (`User::USERNAME_DOMAIN`) — the seeded
    role logins are `admin@`, `hrstaff@` and `employee@primepower.test` — and
    nothing is ever mailed to it. Migration
    `2026_09_13_000003_give_usernames_the_company_domain` gave existing
    usernames the domain and renamed `hr` to `hrstaff`. Users & Access accepts
    `nina` or `nina@primepower.test` and stores the second (`User::withDomain()`).
  - **Every account gets a username however it was created.**
    `User::usernameFor()` makes one from the name — Juan Dela Cruz becomes
    `jdelacruz@primepower.test`, numbered if taken — which the employee form and the
    seeder use; Users & Access takes one typed in, or makes it from the name
    when left blank. `User::booted()` fills a blank one on `creating` so no
    path can make a login that cannot sign in. An explicit username is kept.
  - **Fortify lowercases the typed username** (`lowercase_usernames`), so
    `Admin` and `admin` are the same login. An email typed into the username
    box does not sign anybody in: one way in, not two.
  - **Every place that hands out a login states the username**, because that
    is now what the person needs to be told — the employee form's flash, Users
    & Access create and reset, and the API's `username` beside
    `temporary_password`. Users & Access lists each account's username.
  - **The audit trail records the typed username** in `new_values.username`
    for failed sign-ins and lockouts; rows written before the change hold
    `email`, and the Security screen reads either.
  - The API's token login (`POST /api/v1/login`) takes a `username`, and still
    accepts an `email` in its place for accounts that have one — a documented
    contract other ISMERS systems already call.
  - **An SPA conversion was attempted and backed out.** A commit briefly
    replaced Inertia with `react-router-dom`, a custom `@inertiajs/react`
    shim, CORS and a `frontend/dist` build, while 39 controllers still called
    `Inertia::render()` — neither architecture working. It was restored to the
    one-app setup; the attempt is kept on branch `backup/spa-attempt-2026-09-13`.
- **Employee Information's security controls are listed one by one in
  `docs/SECURITY.md` §11**, against the nine the owner asked for, with MFA
  recorded as deliberately deferred. `EmployeeInformationSecurityTest` covers
  the four added for it:
  - **A deactivated account kept working, and that was a real hole.** Fortify
    only checked username and password, nothing checked `is_active` on a web
    request, and only archiving ever switched a login off — so a resigned
    employee could still sign in and read 201 files. Now: Fortify
    `authenticateUsing` refuses the account (the "deactivated" wording only
    after the password matches, so it tells a guesser nothing);
    `EnsureAccountIsActive` signs an open session out on its next request;
    `User::booted()` deletes tokens and database sessions the moment
    `is_active` goes false; Sanctum's `authenticateAccessTokensUsing` refuses a
    surviving token; and `Employee::booted()` switches the login off whenever
    status becomes `inactive` or employment status `resigned`/`terminated`,
    from any path. It only ever switches *off* — turning a login on is a
    decision (Users & Access, or archive restore).
  - **`authenticateUsing` fires `Failed` with no user**, which silently stopped
    the audit log naming the account on a wrong password.
    `RecordAuthenticationEvents::recordFailed` now looks the account up from
    the typed username when the event carries none.
  - **Maskable numbers reach the web as `••••••` plus the last four**
    (`Employee::MASKABLE`, `EmployeeResource::masked()`), on the record page
    and the list payload. The full value crosses only through
    `POST /hr/employees/{employee}/reveal` — one field, logged as `accessed`
    with the field named, `no-store`, `throttle:30,1` — and hides again after
    30 seconds on screen. The gate is the one that drew the masked value:
    `viewSensitive`, except the licence number, which supervisors already saw
    and so asks `view`. The **edit form and the API stay unmasked** (both need
    real values); opening the edit form and reading a record over the API are
    logged as reads. A person opening their own record is not logged — it
    would bury the reads that are findings.
  - **The privacy notice holds every web session until read**
    (`RequirePrivacyAcknowledgement`), keyed on `config('privacy.notice_version')`
    so a changed notice is shown again. It steps aside while
    `must_change_password` is set, or the two holds would redirect into each
    other. The acknowledgement is stored on the user and as a
    `privacy_acknowledged` audit row. The scanner's processor is named in the
    notice only when the scanner is enabled — that disclosure is the RA 10173
    obligation the scanner section above says is unavoidable. **`UserFactory`
    defaults to acknowledged**, or every feature test would be redirected;
    `withoutPrivacyAcknowledgement()` is the state for testing the hold.
  - **`SESSION_ENCRYPT` defaults to true** in `config/session.php`: session
    rows carry flash messages, and two of those are temporary passwords.
  - **`scripts/check-imports.mjs` reads JSX prose too.** "encrypted (AES-256)"
    in the notice text failed the check as a call to an undefined
    `encrypted()`; reword prose rather than weakening the checker.
- **Leave, Payroll, Performance and cross-cutting controls are `docs/SECURITY.md`
  §12–15**, each marked in place / new / deliberately not done with the reason.
  `ModuleSecurityTest` covers what was added:
  - **Nobody adjusts their own leave credits**, the balance-screen twin of
    nobody deciding their own leave. A balance cannot be set below
    `credits_used`, and `LeaveService::creditShortfall()` re-checks credits at
    *approval* — filing already checked, but a balance can shrink in between.
  - **`markPaid` excludes `processed_by`.** `approve` already did; confirming
    disbursement did not, so the processor could confirm their own run was
    paid. Compute, approve, confirm are now three different hands.
  - **`PayrollAnomalyScanner` checks the computed money** on the run screen
    (draft and for-approval), beside `PayrollReadinessChecker`, which checks the
    DTR *before* computing: shared bank accounts (compared in PHP — encrypted),
    pay after leaving, pay before hire, net ≠ gross − deductions, zero net, and
    a >50% swing against the last reportable payslip. Warns, never blocks.
  - **Payslip and Compliance screen payloads are masked**; the compliance CSV
    and the Finance register API keep full numbers because the recipients need
    them. Salary figures are **not** column-encrypted on purpose: every payroll
    report sums them in SQL.
  - **Peer and subordinate reviewers are anonymous** to everybody but HR and the
    writer (`PerformanceController::reviewerName()`); `PerformanceReviewRating`
    is now `Auditable`.
  - **Audit rows are signed** (`AuditLogSigner`, HMAC-SHA256, key derived from
    `APP_KEY`), in the model's `created` hook so the id is inside the signature;
    `audit:verify` and Settings → Security *Verify integrity* re-check them.
    Canonicalised with sorted JSON keys and whole-second timestamps — verified
    against the real Postgres data (1,896 rows) not just SQLite. Rotating
    `APP_KEY` breaks every signature, as it already breaks encrypted columns.
  - **Users & Access is also the access review**: last sign-in (from `login`
    audit rows), active accounts unused 90 days, and *Record review* writing an
    `access_reviewed` row; "Review due" after 90 days.
  - **A PHPUnit test helper must not be named `run()`** — `TestCase::run()` is
    final and the whole file fatals before a single test reports.
- **Government identifiers and the bank account are encrypted at rest.** SSS,
  PhilHealth, Pag-IBIG, TIN, the bank account number, and the licence number
  are `encrypted` casts. `viewSensitive` already decides who may *see* them;
  this decides what is readable in the file the database sits in, which is a
  different question and one an application gate cannot answer at all. A name
  and a department are what a colleague already knows; a TIN and a bank account
  are what somebody opens a loan with.
  - **Checked before it was written, not after**: nothing filters, sorts or
    groups by these columns in SQL. `RecordIntegrityChecker::sharedNumbers()`
    finds duplicates by loading the rows and comparing **in PHP**, which is why
    duplicate detection survived — a version written as a SQL `groupBy` would
    have gone silently blind, because ciphertext differs per row even for
    identical input. There is a test for exactly that, and it exists to stop
    somebody "optimising" the comparison into SQL later.
  - The migration **widens the columns to `text` first**: the ciphertext is a
    few hundred characters and a twelve-digit TIN no longer fits `string(32)`,
    which would have truncated it with no error worth reading. It rewrites
    through the query builder rather than Eloquent, because the casts are
    already declared by the time it runs.
  - The cost, stated: an encrypted column cannot be searched or indexed.
    Nothing needs that today; if it ever does, the answer is a blind index — a
    second column holding a keyed hash — not undoing this.
- **Employee photos are re-encoded rather than stored.** They sit on the
  *public* disk on purpose, so an avatar costs no PHP request — which means the
  file is served to anyone with the URL with no policy in front of it. A phone
  photo carries EXIF and EXIF carries GPS, so a picture taken at somebody's
  house would put the coordinates of their home on a public URL. Laravel's
  `image` rule does not catch this: it checks the format, not what travels
  inside it. `PhotoStore` decodes to a pixel buffer and writes a fresh JPEG,
  which keeps the picture and discards every chunk that came with it — EXIF,
  GPS, camera serial, thumbnails, and anything a crafted file smuggled past the
  mime check. A file that cannot be decoded is **refused rather than stored
  raw**, and the record keeps the photo it already had: storing it unprocessed
  is the one path that would defeat the point.
- **Authentication is Fortify's.** Signing in, signing out, and the password
  reset flow are `laravel/fortify` routes; the Inertia pages are pointed at
  them from `FortifyServiceProvider`. Its config is trimmed rather than left
  at defaults, and each trim is a rule this system already had:
  `Features::registration()` is **off** — installing Fortify reopened
  `/register`, which this system deliberately 404s, because HR provisions
  every login from the employee form; `fortify.home` points at `/dashboard`,
  since Fortify defaults to a `/home` that does not exist here; and
  **`limiters.login` is null**, which is not "unlimited". Naming a limiter
  makes Fortify apply the `throttle` middleware and skip
  `EnsureLoginIsNotThrottled`, and only that action fires Laravel's `Lockout`
  event — the one `RecordAuthenticationEvents` writes to the audit log. Same
  five attempts either way; nulling it buys the trail. Password confirmation
  moved to Fortify's `/user/confirm-password` for the same reason: it is not
  an optional feature, so the hand-written pair became unreachable duplicates.
- **`composer.json` pins `config.platform.php` to 8.2.12.** Without it,
  installing Fortify resolved Symfony 8.1 packages that require PHP >= 8.4.1,
  and `artisan` stopped booting at all. The pin makes Composer resolve for the
  PHP that actually runs here rather than the newest that satisfies the
  constraint graph.
- **The password floor is set once**, in `AppServiceProvider::definePasswordPolicy()`.
  Unconfigured, `Password::defaults()` means `min:8` and nothing else, and all
  four auth controllers plus the Security screen defer to it — so that one
  callback is the whole policy. `uncompromised()` (the Have I Been Pwned
  lookup) is production-only: in tests it would put the network in the path of
  every password assertion, and locally it fails open anyway.
- **Never generate a password with `Str::password()`.** Its pool contains every
  character class but guarantees none, so roughly one call in twenty produces
  something the policy above rejects — and the three places that hand a
  generated password to a person (the employee form, Users & Access create and
  reset) would be issuing credentials the account holder cannot keep. Use
  `User::generatePassword()`, which seeds one character per required class.
- **A password somebody else chose is temporary, and that is enforced.**
  `users.must_change_password` marks a login provisioned by a third party —
  the seeder, the employee form, a Users & Access create or reset. All four
  deliver the password through a channel that keeps a copy: a chat message, a
  spoken sentence, a console someone can scroll back through. So the password
  is known to two people from the moment it exists, and an instruction to
  change it is not a control. `RequirePasswordChange` is: it holds the account
  on `/settings/security` and lets through exactly three routes — that screen,
  the PUT that changes the password, and logout. Logout is there because
  trapping someone in a session they cannot leave is worse than the risk being
  managed, and signing out reduces exposure rather than adding to it.
  - The flag is cleared by the act that removes the reason for it rather than
    by a "done" button reachable without changing anything:
    `SecurityController::updatePassword()`. It also revokes the account's API
    tokens, since a token issued while the shared password was live was issued
    to whoever held it, and rotating one while leaving the other is half a
    rotation. There is no emailed reset link to be a second way out any more.
  - **The API stack is deliberately outside this.** A Sanctum token is an
    unattended credential on a biometric device with nobody at the other end to
    type a new password; holding it would take the timeclock down rather than
    secure it.
  - It defaults to false, so deploying the migration does not lock out logins
    that already chose their own password.
- **Authentication is audited alongside model changes.** `Auditable` answers
  "who changed this record"; `RecordAuthenticationEvents` answers "who signed
  in, and who tried and failed", both into `audit_logs`. A failed attempt
  against an unknown address has no user row to point at, which is why
  `auditable_id` is nullable — that entry is the one worth keeping, since it is
  what somebody guessing at addresses looks like. The attempted address is
  recorded; the attempted password never is.
- **Reading personal data is audited, not only changing it.** `Auditable`
  answers "who changed this record" and `RecordAuthenticationEvents` answers
  "who signed in"; `DataAccessLogger` answers **"who read it"** — an
  `accessed` row for every 201-file document opened (`download` and `preview`
  recorded separately, because taking a copy away and reading it on screen are
  different acts) and an `exported` row for every CSV that leaves carrying
  many people. Every access gate in Modules 1, 2, and 4 was already correct;
  what was missing was the trace afterwards, which is the half of RA 10173
  accountability that "we gated it properly" does not answer. An export sets
  `auditable_type` and leaves `auditable_id` null — the same split a failed
  sign-in uses, since it is about many rows rather than one. The logger runs
  *after* the gate, so a refused request is never recorded as an access.
- **`/api/v1` is rate limited per token**, via `throttle:api` and
  `AppServiceProvider::defineApiRateLimit()`. Every endpoint there was gated
  and none was paced: an authorised token could walk the whole employee
  directory and its documents as fast as the server answered, and these are
  unattended credentials on biometric devices. Keyed off a hash of the bearer
  string rather than `$request->user()` — `ThrottleRequests` carries a
  middleware priority and `Authenticate` does not, so the limiter can be asked
  for its key before a user is resolved, and keying off a null user silently
  drops every token into one shared per-address bucket. Ceiling is
  `sanctum.rate_limit` (60/min), far above a device's real use.
- The Security screen's log **defaults to record changes, not everything**.
  Sign-ins vastly outnumber edits and the window is 50 rows, so an unfiltered
  view would push every change off the screen by mid-morning.
- **API tokens expire** — `config/sanctum.php` sets a year, where Sanctum's own
  default is never. These are unattended machine credentials on biometric
  devices; an expiry short enough to be inconvenient is one that gets worked
  around by never rotating. Sanctum measures from `created_at`, so the setting
  reaches tokens already issued.
- **Ten minutes of nobody being there signs the session out.**
  `config('session.lifetime')` is the whole enforcement — Laravel refreshes
  `last_activity` on every request, so it is a true idle window rather than a
  fixed expiry, and it holds whether or not any JavaScript is running. Short on
  purpose: this is an HRIS on office machines that get walked away from, and
  what sits on the screen is salary, government numbers, and 201 files — the
  same data `EmployeePolicy::viewSensitive` guards on the way in, left visible
  to whoever sits down next.
  - **`IdleTimeout.jsx` is the courtesy half, not the rule.** It warns 60
    seconds out and signs out through `POST /logout/idle`, which lands on the
    login screen *saying why*. An unexplained login screen reads as a crash,
    and that is the reaction that gets a timeout switched off.
  - **It compares timestamps; it never counts down.** A laptop closed at 4pm
    and opened at 9am has an interval that simply did not fire, and a
    decrementing counter would wake believing no time had passed — on exactly
    the machine somebody else has since sat down at.
  - **Activity is shared across tabs through `localStorage`.** Typing in one
    tab is being present at the computer; without sharing it, a second tab
    signs the person out mid-sentence in the first.
  - **Being active is not the same as talking to the server**, and that gap is
    what `GET /session/keepalive` closes. Reading a long payslip is activity to
    the person and silence to Laravel, whose session would expire underneath a
    countdown that still looked healthy — so real activity refreshes the server
    once the last request is older than half the window. At most one request
    every five minutes for somebody actually working.
  - `mousemove` is deliberately **not** an activity event. A trackpad brushed
    by a sleeve is not somebody at the desk, and counting it is how an idle
    timeout quietly stops timing out.
  - **The number is shared, never restated.** `HandleInertiaRequests` publishes
    `idle.timeout` from the same config the server expires on. Two copies would
    drift the first time one was tuned, and silently in the worst direction: a
    screen counting down from ten against a session that died at five.
  - **A 419 now lands on the login screen too.** The timeout cannot cover the
    case it exists for — a tab whose session Laravel expired with no browser
    running — and the first click after that used to open Inertia's black
    overlay containing "Page Expired", which is a dead end with nothing on it
    saying to sign in again. `router.on('invalid')` in `app.jsx` catches it.
  - **API tokens are outside this**, like `RequirePasswordChange`:
    `session.lifetime` only reaches session auth, and an unattended biometric
    device has nobody at the other end to sign in again.
- `SecurityHeaders` is **deliberately not a full CSP.** A real `script-src`
  needs a nonce threaded through the Vite tags and the Inertia root, and a
  half-written one breaks the payslip print view. The three directives it does
  set — `frame-ancestors`, `object-src`, `base-uri` — cannot break a script or
  a stylesheet.
- **HSTS is sent over TLS *and* outside `local`, and the second half was added
  after it nearly cost a year.** The scheme check alone used to be the whole
  guard, on the reasoning that development is served over plain http so the
  branch could never fire. That was an assumption about the environment rather
  than a rule, and it stopped being true the moment somebody pressed **Secure**
  in Herd: the site began answering on https with a self-signed certificate,
  Herd rewrote `APP_URL` to `https://`, and nginx started **301-ing http to
  https** — so "just use http" was no longer available. One click through the
  browser's certificate warning would then have pinned `core2.test` to HTTPS
  for `max-age=31536000`, with `includeSubDomains` taking every
  `*.core2.test` with it.
  - **Turning TLS back off does not undo that.** HSTS lives in the browser, so
    the host stays unreachable over http until the max-age expires or somebody
    digs it out of `chrome://net-internals/#hsts`. It is the only header here a
    browser remembers, and the only one that outlives the mistake that sent it.
  - **Unsecuring is the wrong recovery once a browser has been pinned**, which
    is how this was found out. `herd unsecure` was the first move; the browser
    kept upgrading to https and got `ERR_CONNECTION_REFUSED` instead, because
    nothing was listening on 443 any more. The pin is browser state, so taking
    TLS away makes the site *less* reachable, not more.
  - **The actual fix was to make https work**: Herd's CA
    (`~/.config/herd/config/valet/CA/LaravelValetCASelfSigned.crt`) was never
    in the Windows trust store, which is the whole of `ERR_CERT_AUTHORITY_INVALID`.
    Importing it into `Cert:\CurrentUser\Root` and re-running `herd secure core2`
    leaves the site on https with a certificate Chromium accepts — verified
    with `Invoke-WebRequest`, which validates against the same Windows store the
    browser uses, rather than with `curl`, which carries its own CA bundle and
    answers a different question.
  - Guarded by `HardeningTest` now. It already asserted both scheme cases; what
    it could not catch was the environment, because nothing had ever put the
    suite in `local`.

**Before deploying**, `APP_DEBUG` must be `false` (a stack trace prints the
database password), `SESSION_SECURE_COOKIE` true, and `SESSION_ENCRYPT`
considered — session payloads are plaintext in the `sessions` table today.

The full sequence is `docs/DEPLOYMENT.md`, and `backend/.env.production.example`
is the file to copy up rather than the local `.env`. Three things there are not
guesses about what might go wrong, they are findings from auditing this
codebase:

- **`TRUSTED_PROXIES` must be set** behind Cloudflare or a host's load
  balancer. Unset, `$request->ip()` is the proxy on every request — which
  silently wrecks the `RecordAuthenticationEvents` sign-in trail, the
  `DataAccessLogger` 201-file read trail, `Auditable`, and drops every
  tokenless API caller into one shared 20/min bucket. `$request->secure()`
  also stays false, so HSTS never sends.
- **The document root is `backend/public`**, not `public/`. Pointed at the
  repository root instead, `/backend/.env` is served as plaintext.
- **`php artisan storage:link` has to be run on the server.** The symlink is
  gitignored, and five `asset('storage/...')` call sites depend on it — the
  folder split broke it locally exactly this way.

**Hostforge builds the root `Dockerfile`, and `docker/start.sh` runs at
container start** — storage link, then (with `RUN_MIGRATIONS=true`) migrate and
`php artisan hris:seed-if-empty`, then config/route/view caching and
`artisan serve` on port 8000 with `/up` as the health check.

- **A first deploy would otherwise have no accounts at all.** A container host
  has no terminal to run `db:seed` from, and `start.sh` only migrated — so the
  database came up empty and nobody could sign in. `hris:seed-if-empty` seeds
  only when `users` has no rows: re-seeding on every restart would re-issue
  every seeded password outside `local`, locking people out of the ones they
  chose. The generated passwords go to the container log once.
- **`fakerphp/faker` is in `require`, not `require-dev`, and that is not a
  mistake.** The image installs with `--no-dev`, and `DatabaseSeeder` builds
  its demo employees through factories that call `fake()` — so with Faker
  dev-only the first-start seed threw, `set -e` stopped the script, and the
  container restarted forever. Found by migrating and seeding a scratch
  database under `APP_ENV=production` before any deploy saw it.
- **`hris:set-admin-password` is the way back in without a terminal.** With no
  emailed reset and no shell on the host, a lost admin password had no
  recovery at all. `HRIS_ADMIN_PASSWORD` in the panel is applied by `start.sh`
  to `admin@primepower.test` (created if missing), flagged
  `must_change_password`, tokens revoked. **Applied once per value**: an HMAC
  of the value under `APP_KEY` is stored in `settings` as
  `security.admin_password_applied`, so a restart with the variable still set
  cannot silently undo the password the admin then chose — and the stored
  fingerprint is not the password. Read through
  `config('auth.bootstrap_admin_password')`, never `env()`, because a cached
  config makes `env()` return null.
- **`start.sh` must stay LF.** `.gitattributes` enforces it, and the Dockerfile
  strips `\r` anyway: a CRLF script fails under Linux `sh` with an error that
  names neither the file nor the line ending.

## Gotchas that have already cost time

- **Settings are cached forever.** `Setting::all()` uses `rememberForever`, and
  `setMany()` clears it — but editing `Setting::DEFAULTS` in code does not. After
  changing a default, run `php artisan cache:clear` or you will read the old one.
- **The logo subtitle is `#0c0a0a` in both modes, per the brand spec.** On the
  dark sidebar (`#131E29`) that is roughly 1.1:1 — effectively invisible.
  Raising `--logo-subtitle` in the `.dark` block is the one-line fix.

- **Paginator links.** `employees.links` is the `{first,last,prev,next}` *object*;
  the numbered page buttons are `employees.meta.links` (an *array*). Passing the
  object to `<Pagination>` crashes React and blanks the page. Guarded by a test.
- **Infinite scroll needs `preserveUrl`.** The Employee Directory loads more
  rows with `<WhenVisible>` + `Inertia::merge(...)->append('data', 'id')`
  instead of numbered pages — merge is server-driven and only applies on a
  *partial* reload, so a full visit (first load, or a filter/sort change)
  renders fresh instead of showing a stitched-together mix of two result sets.
  `WhenVisible`'s `params` must carry `preserveUrl: true`, or every
  scroll-triggered fetch pushes `?page=2`, `?page=3`… onto the URL and browser
  history: Back needs one press per page loaded, and refreshing mid-scroll
  re-renders as a full visit showing only that lone page instead of everything
  loaded so far.
- **File uploads over PUT.** Browsers can't send multipart on PUT — put
  `_method: 'put'` in the `useForm` data and `post()` with `forceFormData: true`.
- **Documents are on the private disk.** Never link to `/storage/...` for a 201
  file; they're streamed through `hr.employees.documents.download` (forces a
  save) or `…documents.preview` (serves `inline` for the in-app viewer) after
  the same `view` policy check — two routes rather than one with a flag, so
  neither can change the other by accident. `EmployeeDocumentResource` decides
  `preview_as` (`image` / `pdf` / `text` / `null`) from the **stored mime type**,
  not the filename, and a `null` offers download only — a viewer that renders a
  blank frame for a `.docx` is worse than no viewer. Employee *photos* stay
  public so avatars don't cost a PHP request per row.
- **A soft-deleted employee is hidden from `belongsTo` but not from a `join`.**
  The payroll run screen joins `employees` to sort by surname, and a join
  ignores the soft-delete scope — so an archived employee's payslip stayed in
  the list while `$payslip->employee` came back null. Three screens fataled on
  it and the SSS R-3 exported a line carrying money with no person attached,
  which is worse than omitting them: the filing looks complete and cannot be
  reconciled. `Payslip::employee()` and `PerformanceReview::employee()` are
  therefore declared `->withTrashed()`: a financial or appraisal record may
  never forget whose it is, and keeping archived people *out of a list* is the
  job of the query that builds the list, not of the record's own memory.
  Guarded by `ArchivedEmployeeHistoryTest`.
- **Archiving a supervisor orphans their reports.** `EmployeeService::delete()`
  deactivates the login but leaves `supervisor_id` pointing at the archived
  row, so those employees' leave has nobody who can endorse it. Departments and
  positions refuse deletion while in use; an employee who supervises somebody
  does not, and that inconsistency is unresolved.
- **Tests that read a default date range must pin "now".** The exceptions
  screen defaults to the current month, so a test filing attendance at
  `now()->subDay()` and reading with no filter passes for 27 days and fails on
  the 1st. `AttendanceExceptionTest` travels to mid-month in `setUp()` for
  exactly this reason. The same trap caught a hard-coded `2026-08-01`
  assertion in `SecurityTest`, which had been passing by coincidence.
- **`SESSION_SAME_SITE=none` requires `SESSION_SECURE_COOKIE=true`, and getting
  that wrong breaks sign-in with no error anywhere.** Since Chrome 80 the
  pairing is mandatory: a `Set-Cookie` carrying `SameSite=None` and no `Secure`
  attribute is **discarded by the browser on arrival**. That drops the session
  cookie *and* `XSRF-TOKEN`, so every form posts without a token, Laravel
  answers 419, and `router.on('invalid')` bounces back to `/login` — signing in
  looks like it silently does nothing.
  - **Nothing server-side can see it.** The response is correct and the browser
    throws it away afterwards, so the suite passes and `curl` logs in fine
    (curl does not enforce the rule). The tell is the **audit log**: because the
    419 happens before authentication is attempted, not even a `login_failed`
    row is written. A user who "cannot log in" with no failed-login rows behind
    them is a session-cookie problem, not a password problem.
  - `none` is deliberate — it is what lets the app run inside an IDE webview's
    iframe, the same reason `SecurityHeaders` skips `X-Frame-Options` in local.
    It only works over TLS, so it is coupled to the site being secured in Herd.
  - Guarded by `HardeningTest::test_a_same_site_none_session_cookie_is_also_secure`,
    which is a no-op on the default `lax` and fails loudly on the broken pair.
- **`APP_URL` is pinned in `phpunit.xml`, and it has to be.** It was unset, so
  it fell through to the developer's own `.env` — and a relative-path request
  in a test (`$this->get('/login')`) builds its absolute URL from that value,
  so the *scheme* of one laptop's local site decided what the suite asserted.
  Pressing **Secure** in Herd rewrites `APP_URL` to `https://`, which flipped
  every such request to TLS and failed the HSTS assertions on a machine where
  no code had changed. Anything a test reads out of the environment rather than
  out of the code is a test that passes by coincidence — the same shape as the
  hard-coded date in `SecurityTest` further up this list.
- **`ilike` is Postgres-only.** Tests run on SQLite — pick the operator from
  `getDriverName()`, as `Employee::scopeSearch` does.
- **Factory sequences.** Batch `create()` runs every `definition()` before the
  first insert, so a DB-derived counter hands out duplicates. `EmployeeFactory`
  counts in memory instead.
- **A name used but never imported fails only in the browser**, and takes
  more with it than you expect. `npm run check` runs
  `scripts/check-imports.mjs` for exactly this. Two have shipped: a lucide icon
  used without its import blanked one screen, and a `roles: [ROLE.ADMIN]`
  written into `navigation.js` — where no such constant exists — blanked
  **every** screen, because every page imports that config and a
  ReferenceError at module load takes the whole bundle down. The build is
  silent on both. The checker looks at three shapes — `<Component>` usage,
  bare `CONSTANT.property` reads, and plain `helper()` calls — each added
  after the previous version let one through. The third was a `withFilters()`
  used in a page that never imported it. It strips comments and string
  literals before scanning, because `{count} day(s) of leave` is JSX prose
  that reads to a regex exactly like a call.
- **Lucide icon names don't fail the build.** A misspelled icon imports as
  `undefined` and only blows up at render. Vite will not warn you.
- **A listener method named `handle*` in `app/Listeners` registers itself.**
  Laravel discovers listeners there by scanning for that prefix and reading the
  type hint — so a class that is *also* registered explicitly fires twice, and
  writes two audit rows for one sign-in. `RecordAuthenticationEvents` names its
  methods `record*` for exactly this reason. Nothing errors; the rows simply
  double, which is why it is caught by a counting test rather than by the suite
  going red on its own.
- **`updateOrCreate` matches on exact column equality.** A `date`-cast column can
  be stored as `Y-m-d 00:00:00`, so looking it up with a `Y-m-d` string misses
  and inserts a duplicate. Use `whereDate` then update, as
  `TimekeepingService::record()` does.
- **A new page must be built before its feature test passes.** The root blade
  `@vite`s the page component by name, so an unbuilt page throws and the test
  reports "Not a valid Inertia response." Run `npm.cmd run build` first.
- **Verify in a browser, not with curl.** A `200` means the server sent correct
  HTML; it says nothing about whether React mounted.

## Database

**PostgreSQL** (`primepower_hris`, local server on port `5433` — not the 5432
default; check `DB_PORT` in `.env` before assuming). Tests default to
in-memory SQLite regardless of the app's own connection (see `phpunit.xml`),
so a Postgres-only bug (like the `ilike` operator) won't show up in a normal
`php artisan test` run.

**`composer test:pgsql` runs the same suite against real Postgres**, in the
`primepower_hris_test` database. It only overrides `DB_CONNECTION` and
`DB_DATABASE` — host, port, and credentials come from `.env`, so no secret is
committed. PHPUnit's `<env>` entries do not override a variable already set in
the environment, which is what lets the override work at all. Run it before
trusting anything that touches raw SQL; the whole suite passes on both drivers
today, and that is worth keeping true.

The old `database/database.sqlite` is kept only as a pre-migration backup
under `storage/app/backups/` (gitignored, not the live source of truth).

**Foreign keys are indexed deliberately, not automatically.** Postgres indexes
the primary key side of a relationship and leaves the foreign key column bare,
so `2026_08_11_000001_index_foreign_keys` adds the ones the app actually joins
and filters on — supervisor scoping, payslips by employee, audit logs by user.
Five are left un-indexed on purpose (`departments.head_employee_id`,
`positions.department_id`, `kpis.department_id`, `kpis.position_id`,
`separations.processed_by`): small tables where the index costs writes for a
scan the planner would choose anyway, and nothing filters on the column —
`separations.processed_by` is only ever eager-loaded, which reads `users` by
its primary key. Adding a foreign key means deciding which of those two cases
it is.

Seed accounts (password `password` **on a local machine only** — see below)
sign in by **username**: `admin@primepower.test`, `hrstaff@primepower.test`, and
`employee@primepower.test` —
a rank-and-file login with a supervisor above it, so the self-service half (own
payslip, own leave, own 201 file) and the approval routing can both be
exercised. The supervisor accounts are the seeded department heads, with
usernames made from their Faker-generated names; read one out of the `users`
table.

**`password` is local-only, and the seeder enforces that rather than trusting
it.** The fixed password is the whole point of a seed account on a development
machine, and it is indefensible anywhere else — this is coursework in a
repository people read, so a seeded deployment would be publishing its own
administrator account. `DatabaseSeeder::seededPassword()` returns `password`
under `local` and `testing` and generates a distinct one per login otherwise,
printed once to the console by `reportIssuedPasswords()` and nowhere else. It
cannot be recovered afterwards: it is hashed on the way in.

**The seeded payroll run is carried through to *paid*.** Everything downstream
of payroll reads finalised runs only, so a run left at `for_approval` leaves
13th-month pay, compliance, final pay, and every employee's payslip screen
empty on a fresh install — which looks broken rather than pending.

## Known gaps

Time & Attendance is removed pending a redesign (see its section). Still outstanding: separation pay for
authorised causes (deliberately left to HR, see Payroll above); peer and
subordinate reviews are supported by the schema and scoring but have no
assignment UI (only self and supervisor are created at rollout); email
notifications (the bell and the credential indicator are in-app only).

Movable holidays — Maundy Thursday, Good Friday, and the two Eids — are
deliberately *not* seeded: they follow the liturgical and lunar calendars and
are fixed by annual proclamation, so HR adds them from Timekeeping → Holidays
once Malacañang publishes them. Only the fixed-date holidays under RA 9492 are
seeded, currently through 2027.

**Core 1's half of the handover is not in this repository.** This system
receives endorsements, decides on them, and publishes the outcome back; what
Core 1 has to build is one POST and one GET against `/api/v1/endorsements`
(above). Until it does, `php artisan endorsements:simulate` files one through
the same service the API calls, so the receiving half stands on its own. There
is no push *to* Core 1 when a decision is taken — they poll for it, which is
the right default for two systems that cannot assume each other is up, and the
upgrade is a webhook on the `decided_at` write.
