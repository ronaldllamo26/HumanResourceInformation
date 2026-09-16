# Core Transaction 2 — Integration Guide

**PrimePower Manpower HRIS.** This is what Core 2 publishes to the rest of
ISMERS, and the five doors it accepts writes through.

Base URL: `https://<host>/api/v1`
Auth: `Authorization: Bearer <token>` (Laravel Sanctum)

---

## What this system owns

Core 2 is the record of **people, time, leave, and pay**. Nothing here should
be copied into another system: a headcount kept in two places disagrees within
a month, and the disagreement surfaces on a remittance or a dispatch sheet
rather than on a screen somebody is watching.

Every figure below is computed by the same service that renders our own
screens. If a consumer and one of our screens ever disagree about the same
driver, one of them is running its own copy of the rules.

---

## Getting a token

Each consuming team gets its own token. Ask Core 2's admin to issue one from
**Settings → Security → API Tokens**, or exchange credentials:

```http
POST /api/v1/login
Content-Type: application/json

{ "email": "integration.core3@primepower.test", "password": "…" }
```

```json
{ "token": "12|abc…", "user": { "id": 4, "role": "hr_staff" } }
```

**The token's role decides what it may see.** Salary, bank details, and
government numbers are behind `viewSensitive` — a token issued from an
`employee` account will get `403` on payroll. Ask for the role your
integration actually needs and no more.

Tokens expire after **one year** (`config/sanctum.php`) and are rate limited to
**60 requests/minute** per token.

---

## Core 1 — Client Acquisition, Recruitment & Deployment

### Sending us a hire

**PrimePower does not hire into Core 2 directly.** You recruit; we employ. Post
the candidate and somebody here approves or declines it — so the act of putting
a person on the payroll has a decision, a decider, and a date attached.

```http
POST /api/v1/endorsements
```

```json
{
  "reference": "C1-2026-ABC123",
  "first_name": "Maria",
  "last_name": "Santos",
  "email": "maria.santos@example.com",
  "mobile_number": "09171234567",
  "birth_date": "1995-04-12",
  "position_title": "Driver",
  "client_name": "Metro Fleet Logistics",
  "date_hired": "2026-10-01",
  "remarks": "Cleared final interview."
}
```

| | |
|---|---|
| **`201`** | filed, waiting on a decision |
| **`200`** | we already have this `reference` — the existing row comes back |

**Only `reference`, `first_name`, and `last_name` are required.** Everything
else is optional, down to the email. Refusing to *receive* somebody is the one
outcome this queue exists to avoid: a recruitment system that cannot hand a
candidate over because it does not know our pay frequency has not been
integrated with, it has been locked out.

**`reference` is your identifier, and it is what makes a retry safe.** A
timeout on your side is indistinguishable from a failure, so resend — two rows
for one person would be two employee numbers and one person paid twice.

**What you may not send.** `basic_salary`, `client_id`, `department_id`,
`position_id`, `employment_category`, `employment_status`, and `pay_frequency`
are dropped from the payload. Those decide what PrimePower pays and who it
bills; they are set here, by a person, at approval.

### Reading the outcome

```http
GET /api/v1/endorsements/{reference}
```

```json
{
  "data": {
    "reference": "C1-2026-ABC123",
    "status": "approved",
    "decision_note": null,
    "decided_at": "2026-09-12T09:14:00+08:00",
    "employee": { "id": 42, "employee_number": "PPM-2026-0042" }
  }
}
```

`status` is `pending`, `approved`, or `rejected`. A rejection always carries a
`decision_note` — you need to know what would have to change.

**Poll this; we do not push.** Two systems that cannot assume each other is up
should not depend on a webhook. If you need one later, it hangs off the
`decided_at` write.

### Who can be deployed

```http
GET /api/v1/deployment-readiness?status=ready&client_id=4
```

Answers "can this person be sent to a client tomorrow?" — which no single
module can, because it needs credentials, 201-file completeness, and employment
standing at once.

```json
{
  "data": [
    {
      "employee_id": 15,
      "employee_number": "PPM-2026-0015",
      "employee_name": "Juan Dela Cruz",
      "position": "Driver",
      "client": "Metro Fleet Logistics",
      "status": "blocked",
      "blocking_count": 1,
      "reasons": [
        { "blocking": true, "detail": "Driver's licence expired 12 days ago." }
      ]
    }
  ],
  "meta": { "total": 41, "ready": 22, "warning": 9, "blocked": 10 }
}
```

**`blocked` is not a louder warning.** A driver whose licence has lapsed may
not lawfully drive, so it is the one status here that means *this would be
wrong* rather than *somebody should look*.

---

## Fleet & Transportation Management

```http
GET /api/v1/drivers?available=1&client_id=4
```

Who may lawfully be put behind the wheel, and of what.

```json
{
  "data": [
    {
      "employee_id": 15,
      "employee_number": "PPM-2026-0015",
      "full_name": "Juan Dela Cruz",
      "client": "Metro Fleet Logistics",
      "licence": {
        "number": "N02-24-001292",
        "expires_at": "2028-11-29",
        "dl_codes": [
          { "code": "B", "label": "Up to 5000 kgs GVW / 8 seats" },
          { "code": "C", "label": "Goods over 3500 kgs GVW" }
        ],
        "conditions": [{ "code": "4", "label": "Daylight driving only" }],
        "is_expired": false,
        "expires_within_days": 815,
        "structurally_sound": true,
        "ltms_check": "verified",
        "ltms_checked_at": "2026-08-14"
      },
      "operational_restrictions": ["Daylight driving only"],
      "may_drive": true,
      "warning_window_days": 60
    }
  ]
}
```

**Three things decide a dispatch, and none is on an employee record:**

- **`dl_codes`** are the legal ceiling on the vehicle class. A code-A holder on
  a truck is the same class of problem as a lapsed licence.
- **`operational_restrictions`** rule out some runs. Condition 4 means that
  driver **cannot take a night run** — not a missing document, not a lapsed
  one, so nothing else would have told you.
- **`may_drive`** is the single field a dispatch screen should key on. `false`
  means assigning them would be unlawful.

**`structurally_sound` is not "valid".** It means the card is internally
consistent — the number's shape, the codes, the birthday rule. It cannot catch
a well-made forgery and never claims to. `ltms_check` is a *person's* answer
from the LTO portal, recorded with their name and the date, because **LTO
publishes no API an employer can call.**

Filter by `client_id` so a site dispatcher sees only their own, and
`available=1` for the ones who may drive today.

No salary, bank details, or government numbers are returned on this endpoint —
Fleet has no reason to hold them.

---

## Core 3 — Employee Development, Compliance & Benefits

### Government contributions (what to remit)

```http
GET /api/v1/payroll/runs/{run}/contributions
```

```json
{
  "data": [
    {
      "employee_id": 1,
      "employee_number": "PPM-2026-0001",
      "full_name": "Breana N. Dicki",
      "numbers": {
        "sss": "49-5872667-2",
        "philhealth": "07-849963398-4",
        "pagibig": "9164-1093-9556",
        "tin": "481-946-366-987"
      },
      "employee_share": {
        "sss": 875, "philhealth": 812.5, "pagibig": 100, "withholding_tax": 3671.89
      },
      "employer_share": { "sss": 1750, "philhealth": 812.5, "pagibig": 100 }
    }
  ],
  "meta": {
    "run_number": "PR-2026-0001",
    "employee_count": 41,
    "missing_numbers": ["PPM-2026-0038"]
  }
}
```

**These figures are read back from stored payslips, never recomputed.** A new
SSS circular would otherwise silently rewrite what was already remitted.

An employee missing a government number is **included with a null and listed in
`missing_numbers`**, deliberately — the filing cannot cover them until it is on
their 201 file, and dropping them would hide that from the system whose job it
is to notice.

Returns **`409`** if the run is still a draft. Only approved and paid runs are
reportable; a draft is still being corrected.

### Posting a loan for payroll to deduct

You approve the loan and answer to the employee for it. **Only Core 2 can take
money off a payslip**, so post it here.

```http
POST /api/v1/loans
```

```json
{
  "reference_number": "C3-LOAN-0001",
  "employee_id": 42,
  "type": "sss",
  "principal_amount": 24000,
  "monthly_amortization": 2000,
  "start_date": "2026-09-01"
}
```

`type` is one of `sss`, `pagibig`, `company`, `salary_advance`.
`monthly_amortization` may not exceed `principal_amount` — an amortisation
larger than the loan is a keying error that would otherwise surface one period
later, on somebody's pay.

Idempotent on `reference_number`: **`201`** the first time, **`200`** on a
resend. Two rows for one loan is the employee paying it twice.

```http
GET /api/v1/loans/{reference_number}
GET /api/v1/employees/{employee}/loans
```

**`amortised_on_payroll_approval` is always `true`, and it matters.** A loan
only moves when a payroll run is *approved*. A balance read mid-cycle is the
balance **before** this period's deduction — do not show it to an employee as
their current figure.

> Do **not** keep your own balance and tell us what to deduct each period. The
> first time a run is recomputed the deduction would apply twice and the two
> balances would part company with nobody watching.

---

## Financial Management System (Transaction Core)

### Runs available for disbursement

```http
GET /api/v1/payroll/runs?from=2026-08-01&to=2026-09-30
```

```json
{
  "data": [
    {
      "id": 1,
      "run_number": "PR-2026-0001",
      "status": "paid",
      "period": {
        "name": "Aug 21 – Sep 4, 2026",
        "start_date": "2026-08-21",
        "end_date": "2026-09-04",
        "pay_date": "2026-09-09"
      },
      "employee_count": 41,
      "total_gross": 940991.51,
      "total_deductions": 178091.89,
      "total_net": 762899.62,
      "approved_at": "2026-09-05T07:55:06+08:00"
    }
  ]
}
```

**Only approved and paid runs are listed.** A draft is still being corrected —
disbursing against one would be paying a figure this system has not agreed to
yet.

### The register behind one run

```http
GET /api/v1/payroll/runs/{run}/register
```

```json
{
  "data": [
    {
      "payslip_number": "PS-2026-0001",
      "employee_id": 1,
      "employee_number": "PPM-2026-0001",
      "full_name": "Breana N. Dicki",
      "gross_pay": 22951.01,
      "deductions_total": 5459.39,
      "net_pay": 17491.62,
      "bank_name": "BDO",
      "bank_account_number": "0012 3456 7890"
    }
  ],
  "meta": {
    "run_number": "PR-2026-0001",
    "employee_count": 41,
    "total_net": 762899.62,
    "control_total": 762899.62
  }
}
```

**Check `control_total` against your own sum of `net_pay`.** If they disagree,
stop — do not disburse a file you cannot reconcile.

`bank_name` and `bank_account_number` are **absent, not null**, for a token
that may not see them. Returns **`409`** on a draft run.

---

### Payroll as a journal entry (General Ledger, AP, Tax)

The register above answers *who gets paid what*. This answers *what to post*.

```http
GET /api/v1/payroll/journal-summary/{payroll_period_id}
```

```json
{
  "data": {
    "debits": [
      { "account": "Basic Pay Expense", "amount": 412000.00 },
      { "account": "Overtime Expense", "amount": 18350.00 },
      { "account": "Night Differential Expense", "amount": 4120.00 },
      { "account": "Holiday Premium Expense", "amount": 9800.00 },
      { "account": "Allowances Expense", "amount": 36000.00 },
      { "account": "SSS Contributions Expense (Employer)", "amount": 34200.00 },
      { "account": "PhilHealth Contributions Expense (Employer)", "amount": 9500.00 },
      { "account": "Pag-IBIG Contributions Expense (Employer)", "amount": 3800.00 }
    ],
    "credits": [
      { "account": "Salaries Expense — Time Not Worked (contra)", "amount": 7420.00 },
      { "account": "SSS Payable", "amount": 51300.00 },
      { "account": "PhilHealth Payable", "amount": 19000.00 },
      { "account": "Pag-IBIG Payable", "amount": 7600.00 },
      { "account": "Withholding Tax Payable (BIR)", "amount": 22840.00 },
      { "account": "Employee Loans Receivable", "amount": 12000.00 },
      { "account": "Net Pay Payable", "amount": 407610.00 }
    ]
  },
  "meta": {
    "period": "Sep 1 – 15",
    "start_date": "2026-09-01",
    "end_date": "2026-09-15",
    "pay_date": "2026-09-20",
    "runs": [
      { "run_number": "PR-2026-0017", "status": "paid", "employee_count": 41 }
    ],
    "employee_count": 41,
    "total_debits": 527770.00,
    "total_credits": 527770.00,
    "balanced": true,
    "out_of_balance_by": 0.00,
    "generated_at": "2026-09-12T10:04:00+08:00"
  }
}
```

**Keyed by period, not by run** — unlike the two endpoints above. A ledger is
posted per accounting period and there can be more than one run in one, so
every reportable run in the period is summed and named in `meta.runs`. Adding
runs up yourself would mean re-deriving a total we already hold, and the day
your sum disagrees with ours the disagreement shows in a trial balance rather
than on a screen.

**Check `balanced` before you post.** Every figure here is read back from
stored payslips rather than recomputed, so this flag is a real check and not a
formality: if a payslip were ever written with a `net_pay` that did not equal
`gross_pay - deductions_total`, `balanced` goes false and
`out_of_balance_by` says by how much. Refuse the entry and tell us — do not
post a journal you cannot reconcile.

Two conventions worth knowing before you map the accounts:

- **Time not worked is a contra to salary expense, not a payable.** Lateness,
  undertime, absence and unpaid leave are one credit line. Nobody is owed that
  money — the company simply spent less — so filing it as a liability would put
  a balance on your books that will never be paid to anyone.
- **The employer's share appears twice**, as an expense (debit) and inside the
  agency payable (credit), and nets out. The payable for each agency carries
  **both** the employee's withholding and the employer's share, because one
  cheque goes to each.

A zero line is **omitted rather than sent as `0.00`**, so a period with no
overtime has no overtime row.

Returns **`409`** when the period's runs are all still drafts — the period
exists and is not finalised yet, which is *retry later* rather than *wrong id*.
An empty journal is never returned: a period posted as zero reads as a month
nobody was paid.

---

### Telling us the money left the bank

The three endpoints above are all reads. This is the one write, and it closes a
loop that was open: the register handed you a list and nothing came back, so a
run sat at `approved` until somebody in HR remembered to tick it — which made
*approved* and *the money arrived* two different facts that this system
reported as one.

```http
POST /api/v1/payroll/runs/{run_id}/disbursement
```

```json
{
  "bank_reference": "BPI-TRF-99120044",
  "amount": 407610.55,
  "disbursed_at": "2026-09-20T09:15:00+08:00",
  "notes": "Batch file 3 of 3, BPI ExpressLink"
}
```

```json
{
  "data": {
    "run_number": "PR-2026-0017",
    "status": "paid",
    "already_confirmed": false,
    "bank_reference": "BPI-TRF-99120044",
    "disbursed_at": "2026-09-20T09:15:00+08:00",
    "amount": 407610.55
  }
}
```

**`amount` is checked against the run's own `total_net`, not trusted.** This is
the assertion the endpoint exists for: a file that disbursed less than the
register said is somebody unpaid, and marking the run `paid` over it would bury
that. A mismatch is **`409`** with the difference stated, and **nothing is
marked paid** — not the status, not the reference. A centavo of tolerance is
allowed, because the two figures are sums of rounded currency reached by two
systems rather than one number twice.

**`bank_reference` is required**, and it is the whole audit trail for *which
transfer paid this run*. Without it the only record that money moved is a
status column.

**`disbursed_at` is a third date**, and deliberately yours to state rather than
ours to assume. A transfer sent Friday and confirmed Monday is one event with
two dates; `approved_at` is when HR released it and `updated_at` would only ever
hold whichever we heard about last.

**Resending is safe.** A run already `paid` comes back **`200`** with
`already_confirmed: true` and the reference we are holding — so a timeout on
your side, which is indistinguishable from a failure, costs you nothing. The
run is never marked paid twice and a second reference never overwrites the
first.

Returns **`409`** for a run that is not `approved`, naming the status it is in.
A draft is still being corrected and a cancelled one was withdrawn; a bank
transfer against either is a fact somebody needs to look at rather than a state
this system should quietly accept. **The status is checked before the
permission**, so a caller who *is* allowed gets "retry later" rather than a
`403` that reads as "you may not do this at all".

Gated on the same ability that **approves** a run. Confirming money left the
bank is the other half of releasing it, and splitting the two would let
somebody mark a run paid who was never trusted to approve one.

---

## One-off amounts on a payslip — Fleet and Supply Chain

**Fleet** posts trip allowances and per diems. **Supply Chain** posts a
deduction when an employee is accountable for a damaged or lost item. Both are
the same act, so they share one endpoint.

```http
POST /api/v1/payroll/adjustments
```

```json
{
  "reference": "TRIP-4471",
  "source": "fleet",
  "employee_id": 42,
  "payroll_period_id": 17,
  "kind": "earning",
  "label": "Trip allowance — Manila to Batangas",
  "amount": 500.00,
  "is_taxable": false,
  "notes": "Two-day run, 11–12 Sep"
}
```

`201` on the first call, `200` with the stored row on a resend:

```json
{
  "data": {
    "source": "fleet",
    "reference": "TRIP-4471",
    "employee_id": 42,
    "payroll_period_id": 17,
    "kind": "earning",
    "payslip_label": "Fleet — Trip allowance — Manila to Batangas",
    "amount": 500.00,
    "is_taxable": false,
    "created_at": "2026-09-12T10:20:00+08:00"
  }
}
```

### What this endpoint does not do, and why it matters to you

**It does not touch a payslip.** It stores a row, and payroll sums those rows
when the run is computed.

That is not a detail of our implementation — it is the reason you can retry
safely. A draft run can be recomputed any number of times before it is
approved. An endpoint that *applied* your ₱500 when you called it would apply
it again on every recompute, and our total and yours would part company with
nobody watching. Reading stored rows means a recompute reaches the same figure.

### `reference` is required, and it is what makes a retry safe

A timeout on your side is indistinguishable from a failure, so you resend — and
two rows for one trip allowance is money. Send your own identifier and we key
on `(source, reference)`.

- **A resend returns `200`** with the row we hold. Not an error: that is how you
  tell a duplicate from a fresh submission.
- **A resend carrying a different `amount` does not change ours.** The
  reference names a fact we may already have paid, so rewriting the amount
  behind it would move money nobody asked to move. To correct one, use a new
  reference — or `DELETE` it while the period is still open.
- Your `TRIP-001` and Supply Chain's `TRIP-001` are different facts. The key is
  scoped to `source`.

### `payroll_period_id` is required

A trip allowance is earned in a fortnight and paid in that fortnight. Left to
"the next run that happens", a row missed by one run pays out in the following
one, and a row never consumed pays out forever. Ask us for the open period, or
read it from `GET /api/v1/payroll/runs`.

### `kind`

| Value | Where it lands |
| :--- | :--- |
| `earning` | Added to allowances. Paid at face value — **never prorated by pay frequency**, unlike a standing monthly allowance. |
| `deduction` | Withheld under "other deductions". |

### `is_taxable` is yours to state, not ours to assume

Only meaningful on an `earning`, and it reaches the withholding tax. A trip
allowance may be taxable or a de minimis benefit that is not — that is a
judgement about your own scheme, so we do not guess it. Defaults to `true`.

### Taking one back

```http
DELETE /api/v1/payroll/adjustments/fleet/TRIP-4471
GET    /api/v1/payroll/adjustments/fleet/TRIP-4471
```

Use `GET` when you lost our response and want to know what we hold rather than
resending and reading the status code.

### `409` — the period is closed

Both `POST` and `DELETE` return `409` once the period has an approved or paid
run. The period exists; it is simply past the point where an amount can still
reach a payslip.

Accepting a late one would be worse than refusing it: the row would sit there
and nothing would ever read it — an allowance somebody was promised and never
paid, with no error anywhere to say so. **Money already paid is not withdrawn
by deleting the row that explained it** either; post an opposite `kind` on the
next period.

### `source` is a fixed list

`fleet`, `supply_chain`, `core3`, `core4`, `hr`. An open field would let a
typo create a row nothing reads and nobody notices — and on this endpoint that
is somebody's allowance.

### Who may call it

The same token permission as `/loans`: "may this caller put money on a payslip"
has one answer in this system. A rank-and-file token gets `403`.

---

## Core 4 — Governance, Safety & Admin

Core 4 has two halves that talk to this system, and they are in two places
here: the governance half writes a disciplinary action, below, and the
reporting half reads `/analytics/workforce` further down.

### Posting a disciplinary action

Core 4 runs the investigation and signs the outcome off. This system holds the
employment record that outcome attaches to.

```http
POST /api/v1/disciplinary-actions
```

```json
{
  "reference": "CASE-2026-0188",
  "employee_id": 17,
  "type": "suspension",
  "reason": "Failed pre-trip inspection twice in one week; unit dispatched regardless.",
  "effective_from": "2026-09-08",
  "effective_to": "2026-09-11",
  "is_unpaid": true,
  "issued_by": "Safety Committee",
  "notes": "Findings attached to case file in Core 4."
}
```

```json
{
  "data": {
    "source": "core4",
    "reference": "CASE-2026-0188",
    "employee_id": 17,
    "type": "suspension",
    "reason": "Failed pre-trip inspection twice in one week; unit dispatched regardless.",
    "effective_from": "2026-09-08",
    "effective_to": "2026-09-11",
    "is_unpaid": true,
    "issued_by": "Safety Committee",
    "payroll_effect": "flagged_for_hr",
    "created_at": "2026-09-12T10:04:00+08:00"
  }
}
```

`type` is one of `verbal_warning`, `written_warning`, `final_warning`,
`suspension`. **Dismissal is deliberately not one of them** — a separation is
a different act with a statutory final pay behind it, and it goes through
Separation & Final Pay where a person releases it, not through an API.

#### A suspension posted here does not dock anybody's pay

**This is the design, not a gap, and it is the thing most likely to be assumed
wrong.** A suspension is *stored*, and `PayrollReadinessChecker` raises an
unpaid one as a **warning** on the payroll run screen before the money is
computed — "3 employees are on unpaid suspension covering 7 days of this
cutoff" — leaving HR to record the days as unpaid or to decide the suspension
was lifted. Once the time records show every suspended day as absent (or a rest
day, holiday or leave), the warning for that suspension goes quiet.

`payroll_effect` says which of the two you got, in the response, rather than
leaving you to infer it:

| Value | Meaning |
|---|---|
| `flagged_for_hr` | an unpaid suspension; it will be raised on the next run for this period |
| `none` | a warning, or a suspension *with* pay — nothing about a payslip changes |

Why it works this way: **a DTR another system can write is not a record of
anything.** The same argument keeps our own employees out of `attendance_logs`
— they file a correction and somebody decides — and Core 4 is another system
and no more entitled to it. The gap that leaves is real and stated: an unpaid
suspension nobody acts on is paid. That is the accepted price of not letting
one system move money inside another.

If the days *were* served and should be unpaid, the answer is the DTR, which HR
keys. Do not post a payroll adjustment to simulate it — a negative earning
against a suspension is money taken off with no attendance record behind it,
and the payslip then disagrees with the DTR it is supposed to have come from.

#### Reading it back

```http
GET /api/v1/disciplinary-actions/{source}/{reference}
GET /api/v1/employees/{employee_id}/disciplinary-actions
```

The second is what stops a second first-warning being issued, and what
Performance Management reads to put a rating in context. Both are scoped by the
token's role, like every other employee read here.

**Idempotent on `(source, reference)`**, the same contract `/endorsements`,
`/loans` and `/payroll/adjustments` offer: a resend returns the stored row with
**`200`** rather than `201`, and **returns it unchanged**. The reference names
an action HR may already have acted on, so rewriting the dates behind it would
silently move which days are unpaid. A correction is a new action with a new
reference; if one was issued in error, tell us — reversal is HR's, not an API
call.

`reason` is required. Core 4 reads the outcome back, and an action nobody can
answer for later is the same failure an unexplained rejection is on
`/endorsements`.

`effective_to` is nullable, because a suspension of unknown length — pending
investigation — is a real state, and defaulting it to the start date would be
inventing the outcome.

---

## Business Intelligence, and Core 4's reporting half

One endpoint serves both, because both are asking the same thing of this
system — the shape of the workforce over a range, not the workforce itself.

```http
GET /api/v1/analytics/workforce?from=2026-09-01&to=2026-09-30
```

```json
{
  "data": {
    "headcount": { "total": 41, "active": 38, "on_leave": 3, "probationary": 6 },
    "by_category": { "internal": 12, "external": 29 },
    "by_client": { "Metro Fleet Logistics": 14, "Visayas Island Transport": 15 },

    "leave": { "total": 24, "pending": 9, "approved": 6, "approved_days": 18 }
  },
  "meta": { "from": "2026-09-01", "to": "2026-09-30", "scope": "organisation" }
}
```

**Aggregates only — no names, no salaries, no government numbers.** A dashboard
needs shapes, not people, and an endpoint that hands over the directory to draw
a bar chart is the endpoint that will one day be the way the directory left.

`meta.scope` says whether the caller is seeing the whole organisation or only
what their role narrows to.

If you need per-person figures, use `GET /api/v1/employees` with a token whose
role permits it — and say why in your integration notes.

---

> **Time & Attendance has no API endpoints yet.** The module was rebuilt as screens
> first (daily records, shifts, holidays, overtime, corrections, cutoffs, client
> timesheets); a biometric device's export is imported as a CSV on the Daily Time
> Records screen for now.

## Also available

| Endpoint | For |
|---|---|
| `GET /api/v1/employees` | the directory, paginated and filterable |
| `GET /api/v1/employees/{id}` | one record |
| `GET /api/v1/employees/statistics` | headcount tiles |
| `GET /api/v1/employees/{id}/documents` | 201-file index (metadata only) |


---

## Conventions

**Errors.** `401` no or bad token · `403` the token's role may not see this ·
`404` no such record · `409` the record exists but is not in a state that can
answer (a draft payroll run) · `422` validation, with a `errors` object keyed by
field · `429` rate limited.

**Dates** are `YYYY-MM-DD`. **Timestamps** are ISO 8601 with the offset
(`+08:00`) — the system runs on `Asia/Manila`.

**Money** is a number, two decimals, in PHP. Never a formatted string.

**Every list response carries `data`.** Aggregates and totals ride in `meta`.

**Retries.** Every write door is safe to repeat. Four are idempotent on a
`reference` you supply — `/endorsements`, `/loans`, `/payroll/adjustments`,
`/disciplinary-actions` — and a resend returns the stored row with **`200`**
rather than `201`, unchanged. The fifth, `/payroll/runs/{run}/disbursement`, is
idempotent on the run itself and answers `already_confirmed: true`. Everything
else is a `GET`.

**The five write doors**, so you can see at a glance which are yours:

| Endpoint | Who calls it | What it does here |
|---|---|---|
| `POST /endorsements` | Core 1 | proposes a hire; HR approves it into an employee |
| `POST /loans` | Core 3 | a loan for payroll to amortise |
| `POST /payroll/adjustments` | Fleet, Supply Chain | a one-off amount on a payslip |
| `POST /disciplinary-actions` | Core 4 | an action on the employment record |
| `POST /payroll/runs/{run}/disbursement` | Financial Management | confirms the money left the bank |

Everything else this system publishes is read-only. **None of the five writes
to attendance or to a payslip directly** — each stores a row that our own
services read at compute time, so a recompute reaches the same figures and no
external call can move money on its own.

---

## Questions worth asking us before you build

- **Do you need names, or just counts?** If counts, use
  `/analytics/workforce` — it is cheaper for both of us and carries no
  personal data.
- **What role should your token have?** Ask for the least that works. It is
  easier to widen later than to explain a leak.
- **Are you about to store a copy of something here?** Say so first. Almost
  every field on these endpoints changes, and a stale copy is worse than a
  round trip.

Contact: the Core 2 team.
