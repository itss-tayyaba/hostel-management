# Feature, Bug & FYP Readiness Checklist

## Bugs fixed in this upgrade
- [x] `index.php` dead HTML removed (clean redirect to landing)
- [x] Landing logo broken path (`/assets/oimges/...`) → `assets/images/uaf-logo.png`
- [x] Email verification not enforced at login → gated via `REQUIRE_EMAIL_VERIFICATION`
- [x] Public warden self-registration → student-only register (wardens via admin)
- [x] Hardcoded AI credentials → `config/env.php`
- [x] Invalid HTML tags (`motion.div`) cleaned in chatbot

## Open bugs / technical debt
- [ ] Dual allocation: `room_allocation` vs `allocations` — KPIs may disagree
- [ ] Password reset link shown on screen (dev only — disable `DEV_SHOW_RESET_LINK` in prod)
- [ ] `auth_throttle` fails open if DB down
- [ ] Partial migrations leave `smart_allocation_tables_ready()` false — vacancy logic branches
- [ ] No SMTP — verification/reset emails not sent automatically
- [ ] `hostel_card.php` avatar path may differ from `student_avatar_url()` in edge cases

## Missing features (optional for higher marks)
- [ ] Student waiting list portal page
- [ ] Email notifications on application status change
- [ ] Admin dashboard for `allocations` table (not only `room_allocation` pending)
- [ ] Dark mode
- [ ] PHPUnit / pytest automated tests
- [ ] Docker Compose one-command deploy
- [ ] Real-time chat (WebSocket) — not required for FYP
- [ ] Mobile app — out of scope

## FYP submission checklist
- [ ] Git repository with 15+ meaningful commits
- [ ] GitHub README badges (PHP, MySQL, Python, License)
- [ ] SRS + SDD documents (PDF for university)
- [ ] ERD diagram
- [ ] User manual with screenshots (admin, student, warden)
- [ ] Demo video (5–8 min): register → apply → smart allocate → card
- [ ] Viva slides: architecture diagram, security table, AI feature vector
- [ ] Production `config/env.php` not committed (use `.gitignore`)
- [ ] Sample admin account + seed data script tested on fresh MySQL
- [ ] Python AI service starts and `/health` returns 200 during demo

## Security pre-defense
- [ ] Change `AI_SERVICE_TOKEN` from default
- [ ] MySQL user not `root` with empty password
- [ ] `REQUIRE_EMAIL_VERIFICATION=true`
- [ ] `DEV_SHOW_RESET_LINK=false`
- [ ] HTTPS on Apache if deploying beyond localhost
