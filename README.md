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

### 3. Seed passwords
Visit `/setup/seed_passwords.php` once after deployment to regenerate secure PIN hashes.

## Default Logins
| User           | Phone       | PIN  |
|----------------|-------------|------|
| Master Admin   | 0820000001  | 1234 |
| East Rand      | 0821111111  | 2345 |
| Kempton Park   | 0832222222  | 3456 |
| The Glen       | 0713333333  | 4567 |

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
└── index.php            # Login page
```
