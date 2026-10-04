# 💰 Expense Manager

![Version](https://img.shields.io/badge/version-4.0.0-blue.svg)
![License](https://img.shields.io/badge/license-MIT-green.svg)
![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4.svg)
![MariaDB](https://img.shields.io/badge/MariaDB-10.3%2B-003545.svg)

**Expense Manager** is a self-hosted, multi-family finance tracker built with plain PHP, MariaDB/MySQL and Bootstrap 5. It tracks spending, income, cards, bank balances, budgets and net worth, and includes Islamic-finance modules (Zakath, Sadaqa, interest purification) and UAE open banking.

---

## ✨ Features

### Everyday money
- **Expenses & income** with multi-row entry, AED/INR/USD/EUR/GBP with live exchange rates, tags, subscriptions and cashback tracking.
- **Bank balances** per account, with debit-card spending, income and card payments moving the right balance automatically (and reversed on edit/delete).
- **Open banking (Lean)** — link UAE bank accounts, sync balances daily and review bank transactions before importing them as expenses or income.
- **Budgets** per category with a 50/30/20 needs/wants view and **email alerts** when a category reaches a warning threshold (default 80%) or goes over (default 100%).
- **Credit-card cycles** — statement and due dates, amount still due, utilization, expected vs recorded cashback, and due-date reminder emails.
- **Family split** — split expenses between family members, see who owes whom (simplified to the fewest transfers) and record settlements.
- **Goals, net worth, subscriptions, reminders, calendar, lending tracker, travel planner**, advanced reports and print-ready statements.

### Islamic finance
- **Zakath** — live gold/silver prices for the nisab (gold or silver basis, configurable weights), hawl (lunar year) due-date tracking with Hijri dates, assets pulled from bank balances, net worth and money lent, and reminder emails 30 days, 7 days and on the due date.
- **Sadaqa** tracker with categories, and an **interest** tracker for purification.

### Family & security
- **Multi-tenant**: every family's data is isolated; family admins manage members (edit access, reset passwords, revoke access).
- **Accounts**: email password reset (single-use, 60-minute hashed tokens), change password from *My Profile*, login rate limiting, new-device login alerts, and sessions that end when the password changes.
- **Hardening**: CSRF tokens on every form, a strict nonce-based Content-Security-Policy (no inline JavaScript), output escaping, tenant checks on every record, audit log, and an `.htaccess` that blocks dumps, logs and internal folders.

---

## 🛠️ Requirements

- PHP 8.0+ with `pdo_mysql`, `curl`, `mbstring`, `json` (`intl` optional, for Hijri dates)
- MariaDB 10.3+ (or MySQL 8.0+)
- Apache or LiteSpeed with `.htaccess` support
- A working PHP `mail()` (used for password reset and notifications)
- A daily cron job (see below)

---

## 🚀 Installation

1. **Get the code** and upload it to your web root (or a sub-folder such as `/expenses/`):
   ```bash
   git clone https://github.com/basimibrahim-git/Expense-Manager.git
   ```
2. **Create an empty database** and a database user.
3. **Run the installer** at `https://your-domain/install/install.php`. It writes `.env`, creates the schema (including every file in `migrations/`) and your first family admin account.
4. **Delete the `install/` folder** once you are signed in (it also refuses to run again while `.env` exists).
5. **Finish `.env`** (see *Configuration*), especially `APP_URL` and the mail settings.
6. **Add the cron job** (see *Scheduled jobs*).

## ⬆️ Upgrading an existing installation

1. **Back up the database.**
2. Run every new file in `migrations/` that you have not run yet, **oldest first**, in phpMyAdmin (SQL tab) or the `mysql` client. They are idempotent, so re-running one is safe.
3. Upload **all** application files (a partial upload can leave pages calling helpers that are not on the server yet). `.env` is never part of the code.
4. Add any new keys from `.env.example` to your `.env`.

---

## ⚙️ Configuration (`.env`)

Copy `.env.example` to `.env`. Unquoted values may have a trailing `# comment`.

| Key | Purpose |
|---|---|
| `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` | Database connection |
| `APP_ENV` | `production` forces HTTPS |
| `APP_URL` | Public URL of the app root (include the sub-folder, if any). Used in password-reset and notification links |
| `APP_TIMEZONE` | Defaults to `Asia/Dubai` |
| `APP_VERSION` | Bump to bust CSS/JS caches |
| `MAIL_FROM`, `MAIL_FROM_NAME` | Sender for all emails |
| `MAIL_RECIPIENTS` | Fallback recipients for reminder emails when a family has no members with email |
| `CRON_SECRET` | Required to trigger the cron over HTTP |
| `LEAN_CLIENT_ID`, `LEAN_CLIENT_SECRET`, `LEAN_APP_TOKEN`, `LEAN_ENV`, `LEAN_WEBHOOK_SECRET`, `LEAN_API_BASE` | Open banking (optional — disabled until the client id and secret are set) |
| `ZAKATH_METALS_PROVIDER`, `ZAKATH_METALS_API_KEY` | Gold/silver price source: `gold-api` (default, keyless), `metalpriceapi` (needs a key) or `none` (manual prices) |

## ⏰ Scheduled jobs

One daily job runs reminders, the monthly digest, budget alerts, card due-date reminders, Zakath reminders and the open-banking sync (`cron/jobs/*.php`):

```bash
0 9 * * * php /path/to/app/cron/reminder_emails.php >> /dev/null 2>&1
```

If your host cannot run PHP from cron, call `https://your-domain/cron/reminder_emails.php?key=<CRON_SECRET>` instead.

## 🏦 Open banking (Lean) setup

1. Create an application in the Lean dashboard (sandbox first) and put its credentials in `.env`.
2. Redirect URL: `https://your-domain/lean_return.php`. Allowed web origin: `https://your-domain`.
3. Webhook URL: `https://your-domain/lean_webhook.php`, subscribed to `entity.created`, `entity.reconnected`, `entity.data.refresh.updated` and `consent.status.updated`; copy the signing secret into `LEAN_WEBHOOK_SECRET`.
4. In the app: **Open Banking → Connect bank**, then link each Lean account to a bank. Synced balances are recorded as balance snapshots; transactions wait in **Bank Transactions** until you import or ignore them (imports never move the balance a second time).

---

## 📂 Project structure

| Path | Contents |
|---|---|
| `*.php` (root) | Pages and their `*_actions.php` form handlers |
| `src/Core/Bootstrap.php` | Loads `config.php`, enforces login on every non-public page |
| `src/Helpers/` | Shared logic: `Html` (escaping), `Flash` (messages), `Categories`, `BalanceHelper`, `ExchangeRateHelper`, `Notifier`, `BudgetAlertHelper`, `CardCycleHelper`, `SplitHelper`, `ZakathHelper`, `LeanClient`/`LeanSync`, `SecurityHelper`, `PasswordResetHelper`, `Layout` |
| `includes/` | Layout templates (header, sidebar, footer, public-page layout) |
| `assets/js/app.js` | CSP-safe event wiring (`data-onclick`, `data-confirm`, …) — the app uses no inline JavaScript handlers |
| `cron/` | Daily cron entry point and its jobs (`cron/jobs/`) |
| `migrations/` | Dated, idempotent SQL upgrades |
| `install/` | Web installer |
| `tests/` | CLI checks for date and split maths: `php tests/card_cycle_dates_test.php`, `php tests/split_simplify_test.php` |

## 🔐 Security notes

- Never commit `.env` or database dumps; both are in `.gitignore`, and the bundled `.htaccess` refuses to serve `*.sql`, `*.log`, `*.md` and the `src/`, `includes/`, `migrations/`, `logs/`, `tests/` folders.
- Errors are logged to `logs/php_errors.log` (not web-accessible).
- Report vulnerabilities as described in [SECURITY.md](SECURITY.md).

## 📜 License
MIT — see the badge above.
