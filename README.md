# UAF Hostel Management System with AI Integration

[![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8-4479A1?logo=mysql&logoColor=white)](https://www.mysql.com/)
[![Python](https://img.shields.io/badge/Python-3.9+-3776AB?logo=python&logoColor=white)](https://www.python.org/)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-7952B3?logo=bootstrap&logoColor=white)](https://getbootstrap.com/)

A full-stack **University Hostel Management System** for the University of Agriculture, Faisalabad (UAF).  
Students apply for rooms, pay fees, raise complaints, and print hostel documents. Administrators manage hostels, wardens, payments, and **AI-assisted room allocation**. Wardens handle hostel-scoped operations.

> **Final Year Project (FYP)** — includes evaluation matrix, viva Q&A, and deployment guide in [`docs/`](docs/).

---

## Highlights

| Area | Implementation |
|------|----------------|
| **Smart allocation** | Python Flask + scikit-learn `NearestNeighbors` with PHP rule-based fallback |
| **Help assistant** | Context-aware FAQ chatbot (rule engine, no external API cost) |
| **Security** | Bcrypt, CSRF, session hardening, login throttling, audit log |
| **Documents** | Printable hostel ID card (QR), allotment slip, fee voucher |
| **UI** | Bootstrap 5 dashboards, Chart.js analytics, toast notifications, responsive sidebar |

---

## Screenshots & roles

| Role | Entry | Capabilities |
|------|-------|--------------|
| **Public** | `landing.php` | Marketing page, public notices |
| **Student** | `student/dashboard.php` | Apply, room, payments, complaints, chatbot |
| **Warden** | `warden.php` | Hostel-scoped complaints & room changes |
| **Admin** | `admin/dashboard.php` | Full CMS, smart allocation, reports, audit |

---

## Quick start (XAMPP)

### 1. Clone & configure

```bash
# Place project in htdocs
cd C:\xampp\htdocs\hostel
copy config\env.example.php config\env.php
# Edit config\env.php with your MySQL credentials
```

### 2. Database

Open phpMyAdmin or MySQL CLI:

```sql
SOURCE database/schema.sql;
SOURCE database/smart_allocation_schema.sql;
SOURCE database/v2_full_upgrade.sql;
-- Optional demo data:
SOURCE database/smart_allocation_sample_data.sql;
SOURCE database/test_seed_hostels_rooms.sql;
```

### 3. First admin user

Generate a password hash (temporary PHP file or CLI):

```php
<?php echo password_hash('Admin@123', PASSWORD_DEFAULT);
```

Insert into `users` with `role='admin'`, `is_active=1`, `email_verified_at=NOW()`.

### 4. Run

1. Start **Apache + MySQL** in XAMPP  
2. Open **http://localhost/hostel/**  
3. Register a student → verify email (link shown in dev) → login  

### 5. Optional: AI microservice

```bash
cd ai_service
pip install -r requirements.txt
python app.py
```

Admin → **Smart Allocation** shows **Python · scikit-learn KNN** when healthy.

---

## Project structure

```
hostel/
├── admin/              # Administrator pages
├── student/            # Student portal
├── warden/             # Warden sub-pages
├── ai_service/         # Flask + scikit-learn microservice
├── assets/css|js/      # style.css, components.css, ui.js
├── config/             # db.php, env.php (gitignored)
├── database/           # SQL migrations & seeds
├── docs/               # FYP evaluation, viva, deployment, checklist
├── includes/
│   ├── models/         # Data access layer
│   ├── services/       # AI recommendation bridge
│   ├── layout/         # Header, sidebar, footer
│   └── partials/       # Reusable UI blocks
├── uploads/            # Student avatars (protected)
├── landing.php         # Public homepage
└── login.php           # Authentication
```

---

## Configuration (`config/env.php`)

| Key | Purpose |
|-----|---------|
| `DB_*` | MySQL connection |
| `AI_SERVICE_URL` | Python recommend endpoint |
| `AI_SERVICE_TOKEN` | Shared secret header |
| `REQUIRE_EMAIL_VERIFICATION` | Block login until email verified |
| `DEV_SHOW_RESET_LINK` | Show reset URL on screen (local only) |

Copy from `config/env.example.php`. **Never commit** real `config/env.php`.

---

## Security features

- PDO prepared statements (`ATTR_EMULATE_PREPARES => false`)
- `password_hash` / `password_verify` with legacy upgrade
- CSRF tokens on POST forms and chat API
- Session: `httponly`, `samesite=Lax`, `session_regenerate_id` on login
- Brute-force throttling (`login_attempts` table)
- Warden scope enforced via `warden_assignments` + hostel EXISTS queries
- Upload MIME validation + `.htaccess` blocking PHP in `uploads/`

---

## Code comments

Every PHP file has a `@file` header explaining its purpose. See [docs/CODE_COMMENTS.md](docs/CODE_COMMENTS.md).

Re-annotate after adding new files:

```bash
C:\xampp\php\php.exe tools\annotate_project.php
```

## FYP documentation

| Document | Description |
|----------|-------------|
| [docs/CODE_COMMENTS.md](docs/CODE_COMMENTS.md) | How comments are organized in this repo |
| [docs/FYP_EVALUATION.md](docs/FYP_EVALUATION.md) | Marks breakdown & improvement roadmap (61 → 75+) |
| [docs/VIVA_QA.md](docs/VIVA_QA.md) | 25+ viva questions with answers |
| [docs/CHECKLIST.md](docs/CHECKLIST.md) | Bugs, missing features, submission checklist |
| [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) | Production deployment steps |
| [ai_service/README.md](ai_service/README.md) | AI service API & environment |

---

## Version control

```bash
git init
git add .
git commit -m "feat: UAF hostel portal with AI allocation and security hardening"
```

Use GitHub with regular commits before your defense (see evaluation doc).

---

## Tech stack

- **Backend:** PHP 8+ (no framework)
- **Database:** MySQL / MariaDB
- **Frontend:** Bootstrap 5.3, Bootstrap Icons, Chart.js 4
- **AI:** Python 3.9+, Flask, scikit-learn, PyMySQL

---

## License & academic use

Developed as a university FYP. Adapt and cite appropriately for academic submission.

---

## Authors

University of Agriculture, Faisalabad — Hostel Management System FYP Team.
