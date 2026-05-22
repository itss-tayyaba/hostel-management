# FYP Evaluation Report — UAF Hostel Management System with AI Integration

**Project path:** `c:\xampp\htdocs\hostel`  
**Evaluation date:** May 2026  
**Total matrix:** 80 marks

---

## Executive summary

| Category | Max | Score | Notes |
|----------|-----|-------|-------|
| 1. Project Documentation | 15 | **11** | README strong; needs SRS/SDD PDF, ERD diagram, user manual |
| 2. Originality & Code Quality | 10 | **8** | Real KNN microservice + clean PHP; dual allocation model hurts clarity |
| 3. Features Developed | 15 | **13** | Full CRUD, payments, audit, smart allocation, chatbot |
| 4. UI/UX | 10 | **8** | Modern dashboards; auth pages simpler than dashboards |
| 5. Portability & Deployment | 10 | **7** | XAMPP-ready; env config added; no Docker/CI yet |
| 6. Technical Knowledge / Viva | 15 | **12** | Strong security story; must explain two allocation tables |
| 7. GitHub / Version Control | 5 | **2** | No git history in workspace (initialize + regular commits) |
| **TOTAL** | **80** | **61/80** | **Target 75+:** see roadmap below |

---

## 1. Project Documentation (11/15)

### Strengths
- `README.md` with stack, install order, security table, feature matrix
- `ai_service/README.md` for Python service
- Inline PHPDoc on models and `recommendation_service.php`
- SQL migrations numbered in `database/`

### Weaknesses (mark deduction)
- No formal **SRS**, **SDD**, or **test plan** PDF for examiners
- No visual **ERD** in repository (only implied in SQL)
- No **user manual** with screenshots per role
- FYP report chapters often missing: feasibility, Gantt, risk analysis

### To reach 14+/15
1. Add `docs/SRS.md` and `docs/SDD.md` (10–15 pages each)
2. Export ERD from phpMyAdmin or draw.io → `docs/erd.png`
3. Add `docs/USER_MANUAL.md` with login → apply → allocate flow screenshots
4. Include **test cases table** (pass/fail) in report appendix

---

## 2. Originality & Code Quality (8/10)

### Strengths
- **Python Flask + scikit-learn `NearestNeighbors`** for room ranking (genuine ML, not fake AI)
- PHP fallback scorer when microservice offline (resilient design)
- `declare(strict_types=1)`, PDO prepared statements, model layer separation
- Audit log, rate limiting, CSRF, bcrypt with legacy upgrade path

### Weaknesses
- **Two allocation systems:** `room_allocation` (applications) vs `allocations`+`beds` (smart assign)
- No Composer/autoload; repetitive `require_once` chains
- Some static `$pdo->query()` for aggregates (acceptable but inconsistent)
- No automated tests (PHPUnit / pytest)

### To reach 9+/10
1. Document unified allocation roadmap in `docs/ARCHITECTURE.md`
2. Add `tests/` with 5–10 PHPUnit tests on auth + student model
3. Extract shared validation into `includes/validators.php`

---

## 3. Features Developed (13/15)

### Implemented
| Module | Status |
|--------|--------|
| Student registration + profile + avatar | ✅ |
| Room application workflow | ✅ |
| Admin student/room/warden management | ✅ |
| Smart allocation (AI + PHP fallback) | ✅ |
| Complaints + escalation | ✅ |
| Room change requests | ✅ |
| Payments + fee structure + vouchers | ✅ |
| Notices CMS + public board | ✅ |
| Hostel ID card + QR + allotment slip | ✅ |
| Forgot/reset password + email verify | ✅ |
| Warden scoped dashboard | ✅ |
| Rule-based help chatbot | ✅ |
| CSV reports + audit log | ✅ |

### Missing / partial (for higher marks)
- Email **SMTP** delivery (tokens shown on screen in dev)
- SMS/push notifications
- Admin analytics export beyond CSV
- Waiting list UI for students
- Merge smart allocation with application approval in one workflow

---

## 4. UI/UX (8/10)

### Strengths
- Role-specific dashboard shells (admin analytics, student modern sidebar)
- Bootstrap 5.3, Chart.js, responsive sidebar
- Landing page (`landing.css`) separate marketing design
- New: toast notifications, chat UI, stat cards, empty states (`components.css`)

### Weaknesses
- Login/register pages less polished than dashboards
- Tables on mobile need horizontal scroll labels
- No dark mode
- Inconsistent empty states across all admin tables

### To reach 9+/10
1. Apply `hostel-empty` partial to all “no rows” table states
2. Unify auth pages with landing typography (Inter/Poppins)
3. Add skeleton loaders on dashboard AJAX (if added)

---

## 5. Portability & Deployment (7/10)

### Strengths
- Standard LAMP stack (XAMPP)
- `config/env.example.php` for credentials and AI token
- `.gitignore` for secrets and uploads
- Python service isolated in `ai_service/`

### Weaknesses
- No Docker Compose for one-command deploy
- No production Apache `VirtualHost` sample
- HTTPS / `secure` cookie flag depends on server config

### To reach 9+/10
1. Follow `docs/DEPLOYMENT.md` (included)
2. Add `docker-compose.yml` (php-apache + mysql + ai_service)
3. Set `REQUIRE_EMAIL_VERIFICATION=true` and rotate `AI_SERVICE_TOKEN` on server

---

## 6. Technical Knowledge / Viva Preparation (12/15)

See `docs/VIVA_QA.md` for 25+ Q&A from your codebase.

**Critical viva topics:**
- Why MySQL over Firebase (relational hostel data, FK integrity)
- How KNN features are built (department, semester, preference, beds, gender/hostel type)
- CSRF + session fixation prevention
- Warden scope: `warden_assignments` + EXISTS queries on hostel_id
- Email verification gate at login (`REQUIRE_EMAIL_VERIFICATION`)

---

## 7. GitHub / Version Control (2/5)

### Current state
- Repository was **not initialized** in workspace during audit
- `.gitignore` added in this upgrade

### To reach 5/5
```bash
cd c:\xampp\htdocs\hostel
git init
git add .
git commit -m "Initial FYP release: hostel portal with AI allocation"
# Push to GitHub with meaningful commit history (feature branches optional)
```

Use commit messages: `feat:`, `fix:`, `docs:` — show 20+ commits before defense.

---

## Roadmap: 61 → 75+ marks

| Priority | Action | Marks impact |
|----------|--------|--------------|
| P0 | Initialize Git + 15 commits + public GitHub | +3 (VC) |
| P0 | SRS + SDD + ERD in `docs/` | +4 (docs) |
| P1 | Viva rehearsal using `VIVA_QA.md` | +3 (viva) |
| P1 | Docker or deployment demo on laptop | +2 (deploy) |
| P2 | Unify allocation documentation + demo script | +2 (originality) |
| P2 | SMTP email (PHPMailer) | +1 (features) |

**Realistic target after P0+P1:** **72–76/80**

---

## Honest final score: **61/80** (current baseline after code upgrades)

**After documentation + Git + viva prep:** **74–78/80** achievable before defense.
