# Conscious Solutions — Service Management System

A PHP/MySQL invoicing and statement system for Conscious Solutions (Pty) Ltd, 
managing vehicle service invoices across Hyundai branches.

## Company Details
- **Conscious Solutions (Pty) Ltd**
- Reg: 2018/579539/07
- 94 President Street, JHB, Gauteng
- ncubenduduzo23@gmail.com | 0835168273

## Branches Managed
- **Hyundai East Rand** — 1 Pond Road, Boksburg 1508
- **Hyundai Kempton Park Airport** — Kempton Park, Gauteng
- **Hyundai The Glen** — 4 Boundary Road, Oakdene 2190

## Features
- Invoice creation (R200 / R250 service fees)
- Vehicle details per invoice (Model, Registration No, VIN)
- Monthly statements per branch — matching invoice branding
- Role-based access (Master Admin / Mechanic)
- Branch reassignment — takes effect immediately without logout
- WhatsApp share on invoices
- Print-optimised invoice and statement layouts

## Setup

### 1. Database
```sql
-- For shared hosting (no CREATE DATABASE permission):
source sql/schema_hosting.sql

-- For full access:
source sql/schema.sql
```

### 2. Config
Edit `config/db.php` with your database credentials.

### 3. Users
Logins and PINs are never written down in this repository. Add or change them in **Users** (Master Admin).

## Security
The site is **locked by default** after a deploy. How to unlock it, and what to do after a hack: [docs/SECURITY-RECOVERY.md](docs/SECURITY-RECOVERY.md).

- `signin.php` is the login page. `index.php` is blocked on purpose.
- A new page must be added to the script list in `.htaccess` (section 2) or it shows "Access denied".
- Never edit files on the server: the guard puts them back. Change them here and deploy.

## File Structure
```
conscious-solutions/
├── config/
│   ├── app.php          # App settings, CS company details, session
│   └── db.php           # Database connection
├── includes/
│   ├── auth.php         # Login, session sync, role checks
│   ├── users.php        # User CRUD with immediate session sync
│   ├── invoices.php     # Invoice/statement business logic
│   ├── header.php       # Nav with Conscious Solutions branding
│   └── footer.php       # Footer with CS company details
├── pages/
│   ├── dashboard.php    # Stats overview
│   ├── invoices.php     # Invoice list + create
│   ├── print_invoice.php # Print-optimised invoice (CS branded)
│   ├── statement.php    # Monthly statement view
│   ├── print_statement.php # Print-optimised statement (CS branded)
│   └── users.php        # User management (admin only)
├── sql/
│   ├── schema.sql       # Full schema with CREATE DATABASE
│   └── schema_hosting.sql # Schema for shared hosting
├── security/guard.php   # Repairs changed files (runs outside the web root)
├── deploy.sh            # Run by cPanel on every deploy
├── index.php            # Blocked on purpose
└── signin.php           # Login page
```
