# FinEase v3.0

A self-hosted PHP/MySQL business management tool for job-order-based businesses — track clients, job orders, a product catalogue, project surveys, transactions, tithes, and generate financial reports (P&L, balance sheet, cash flow) from one dashboard.

## Features

- **Dashboard** — revenue, liquidity, inflow/outflow, and net profit tiles for a selectable period (week / month / year / all time), plus recent transactions and account balances.
- **Clients & Job Orders** — create job orders with auto-generated IDs, line items, VAT, and payment/status tracking.
- **Product Catalogue** — manage items with images and manufacturer links, and pull them into job orders.
- **Project Surveys** — capture on-site surveys per job with custom dynamic fields, GPS location, and a photo upload.
- **Transactions** — a full ledger of inflows/outflows categorized for financial reporting (IFRS-style report categories), linkable to job orders and bank accounts.
- **Tithes engine** — automatically calculates a tithe obligation once a job order is completed and fully paid, based on its net profit.
- **Reports** — P&L statement, balance sheet, cash flow, and revenue breakdowns by client / job / job type, with print support.
- **Backup & Restore** — one-click database backup/restore from the admin panel (pure-PHP dump, no `mysqldump` dependency).
- **Users & Roles** — `admin`, `temp`, and `viewer` roles; admin-only areas (Users, Settings, Backups) are gated server-side.
- **Print templates** — standalone printable invoice and catalogue documents.

## Tech stack

Plain PHP (PDO/MySQL), no framework or build step. Frontend is hand-written HTML/CSS (`assets/css/style.css`) with a mobile-first responsive layout (bottom nav on mobile, sidebar on desktop) plus vanilla JS for interactive bits.

## Requirements

- PHP 7.4+ with the `pdo_mysql` extension
- MySQL / MariaDB
- Apache with `mod_rewrite` (e.g. XAMPP) — a `.htaccess` is included

## Setup

1. Clone/copy this project into your web root, e.g. `htdocs/fe` for XAMPP.
2. Copy the example config and make sure it's not tracked by git (it's already in `.gitignore`):
   ```bash
   cp config/config.example.php config/config.php
   ```
3. Start Apache + MySQL, then visit the app in your browser. If `config/config.php` doesn't yet have working DB credentials, or the database hasn't been initialized, you'll be redirected to the **setup wizard** (`setup/install.php`), which will:
   1. Test and save your database credentials to `config/config.php`
   2. Load the schema (`setup/schema.sql`)
   3. Create your first admin account and company profile
4. Log in at `auth/login.php` with the admin account you just created.

### Configuration notes

- `config/config.php` auto-detects `BASE_URL` from the request, so it works unmodified whether the app lives at the domain root or in a subfolder.
- The app sets its own PHP session name (`finease_v3_session`) so it doesn't collide with `$_SESSION` data from other PHP projects running on the same host (a common gotcha when several local sites share XAMPP's default session cookie).

## Project structure

```
auth/          Login / logout
config/        DB + app config (config.php is gitignored — copy from config.example.php)
includes/      Shared PHP: session/auth helpers, header/footer layout, backup manager, tithe engine
pages/         Application pages (dashboard, clients, job orders, catalogue, transactions, reports, settings, users, ...)
setup/         First-run install wizard and DB schema/migrations
assets/css/    Stylesheet
uploads/       User-uploaded files (logos, signatures, receipts, catalogue/survey photos) — gitignored
backups/       Generated DB backups — gitignored
```

## Security notes

- `config/config.php`, `config/configx.php`, `backups/*.sql`, and everything under `uploads/` (except `.gitkeep` placeholders) are gitignored on purpose — they contain live credentials or real business data and should never be committed.
- Admin-only pages are protected by `require_admin()`; all authenticated pages by `require_login()`, both in `includes/functions.php`.
