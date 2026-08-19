# ONGkoleyt Payroll — Owner's Handover

Everything the owner login can do, and the routine that keeps payroll clean.
Branch staff have their own, shorter guide (`Branch-Handover.md`) — hand them
that one instead of this.

---

## 1. Your login

There is one full-access login, shown on screen as **Owner · full access**.
Everything in this guide requires it.

Keep it to yourself. Branch supervisors get their own branch logins, which
cannot see other branches, cannot change pay rates, and cannot see the audit
log.

### The owner account

| Email | Password | Access |
|---|---|---|
| owner@ongkoleyt.ph | _set on handover_ | Owner · full access |

### Branch accounts

| Branch | Email | Password |
|---|---|---|
| Mabini | — | _set on handover_ |
| Bodega | — | _set on handover_ |
| Diego | — | _set on handover_ |
| Bonifacio | — | _set on handover_ |
| Kanto Craving/Brew | — | _set on handover_ |
| Admin Office | — | _set on handover_ |

### Logins being removed

Setup left five full-access logins behind. Only **owner@ongkoleyt.ph** is
kept; these four are cleared with the Remove button. The `.example` and
`.test` addresses are reserved placeholder domains that can never receive
mail.

- admin@ongkoleyt.ph
- admin@ongkoleyt.example
- admin@ongkoleyt.test
- owner@ongkoleyt.example

> **Setting the passwords.** Existing passwords cannot be looked up — the
> system stores them scrambled, so they can only be reset, never read back.
> Set each one on the Settings screen, write it into the table above, and
> keep this document where the branch guides are not kept.

**To change your password:** Settings → Logins & Passwords → type a new
password (8 characters minimum) → Update.

**To remove a login you no longer need:** Settings → Logins & Passwords →
Remove. The system will not let you remove the login you are signed in with,
or the last full-access login, so you cannot lock yourself out.

---

## 2. What only you can see

Branch logins share the first four tabs with you but see **only their own
branch's** employees and payroll. These three tabs are yours alone:

| Tab | What it is for |
|---|---|
| **13th Month** | 13th-month computation, release and payslips |
| **Settings** | Pay rates, 13th-month rules, logins and passwords |
| **Audit Log** | Who changed what, and the reason they gave |

---

## 3. Settings — check these before the first payroll

Settings → **Basic Pay Rate & Overtime**:

- **Daily Basic Rate (₱)** — the company default. An individual employee can
  be given their own rate on the Employees tab, which overrides this.
- **Overtime Multiplier**
- **Night Differential Multiplier**
- **Unpaid Break (hours)** — deducted from worked hours.

Settings → **13th Month Period & Release**: release date, minimum months of
service, which earnings count toward the base, and which employment types
are eligible.

Confirm all of these before go-live. They apply to every computation from
that point on.

---

## 4. Employees

Add each employee with their **branch**, **shift start and end**, and **PIN**
for the time clock. Leave the daily rate blank to use the company default, or
fill it in to override for that person.

Two things worth doing properly:

- **Real PINs.** Never leave a demo PIN such as `1234` in place — anyone who
  knows it can clock in as that employee.
- **Separations.** Separate an employee rather than deleting them. Their past
  payroll stays intact and they drop off the current register.

---

## 5. The pay cycle

Payroll runs in two halves each month: **1–15** and **16–end**.

### Step 1 — Attendance

Attendance tab. Review the period and fix anything wrong. Every correction
asks for a reason, and the reason is what appears in the Audit Log.

Watch for missing clock-outs: a day with a clock-in and no clock-out earns
nothing until it is corrected.

### Step 2 — Adjustments

Open an employee's payslip (Payroll → Payslip) and add anything that is not
attendance-based. Pick the **type** from the dropdown — the type is what
decides which column the amount lands in on the summary:

| Type | Effect |
|---|---|
| Cash on Hand | Added, and marked as already handed over |
| Allowance / Rice Allowance / Bonus | Added |
| Authorized Deduction | Subtracted |
| Penalty Late | Subtracted |
| Cash Advance | Subtracted |
| SSS / Pag-IBIG / PhilHealth | Subtracted |
| Other | Type your own label |

Always enter a **positive** amount — deductions are subtracted automatically
by their type.

**"Already paid (cash on hand)"** means the money is already in the
employee's hands. It is added to Total Salary but subtracted from what is
still to be handed over, so the take-home figure stays honest.

### Step 3 — Export the summary

Payroll → pick the month and half → export. You get a workbook with a
Dashboard sheet and the Payroll Summary in the standard column layout.

### Step 4 — Payslips

Print or issue payslips from the Payslip screen.

---

## 6. Reading the payroll summary

Columns, left to right:

- **Employee, Branch**
- **NSD** — night shift differential
- **Late / SH / RH / UT** — time adjustments, in pesos
- **Total Auth. Ded.** — the total of all authorized deductions
- **Penalty Lates** and **CA etc** — two components of that total, broken out
- **SSS / PhilHealth / Pag-IBIG**
- **Days Worked** — days actually stood, not attendance rows. A half day
  counts as half; an absence or unworked rest day counts as none.
- **Rice Allowance, Daily Rate**
- **Gross, Net Pay**

Two columns depend on how the adjustment was entered:

- **Penalty Lates** fills from any adjustment with *penalty* or *late* in its
  label, whatever type was chosen.
- **Rice Allowance** fills from the **Rice Allowance** type, or from an
  Allowance whose label mentions rice.

If either column comes out blank when you expected a figure, the amount is
almost certainly sitting inside Total Auth. Ded. instead — check how the
adjustment was typed and labelled.

Neither column changes anyone's pay. They break an existing total into parts
for reporting.

---

## 7. Audit log

Every rate change and attendance correction is recorded with the person, the
change and the reason. This is the first place to look when a figure is
questioned.

---

## 8. Monthly routine

1. Attendance reviewed and corrected for the half.
2. Adjustments entered — allowances, deductions, cash advances, statutory.
3. Summary exported and checked against the branch reports.
4. Payslips issued.
5. At year end: 13th Month tab — review, then release.

---

## 9. Things to watch

- **Negative net pay.** An employee with no attendance in a half, but with
  statutory deductions entered, shows a negative Net Pay. Decide deliberately
  whether statutory deductions should apply to someone who did not work that
  half.
- **Adjustment dates.** An adjustment only appears in the half its date falls
  in. Dated the 1st, it belongs to the first half; dated the 16th, the second.
- **Rates are not retroactive.** Changing the daily rate affects computations
  from that point on. Correct past periods through the employee's own rate.

---

## 10. Support and technical notes

The system is deployed from the project repository; pushing to the deployed
branch rebuilds and redeploys both the backend and the frontend
automatically, and database migrations run on boot.

To create an additional full-access login from the server shell:

```
php artisan admin:create --name="Owner" --email="you@example.com" --password="a-real-password"
```

Add `--force` to reset the password on a login that already exists. For a
branch login, add `--role=branch --branch="Branch Name"`, repeating
`--branch` for each branch it should cover.

Full deployment instructions are in `DEPLOYMENT.md`.
