# Viva Questions & Answers — Hostel Management System

Study these answers; adapt wording to your own voice.

---

## Project overview

**Q: What problem does your system solve?**  
A: UAF hostel office processes—room applications, bed allocation, fee tracking, complaints, and notices—were manual and fragmented. Our portal centralizes these for students, wardens, and admins, with AI-assisted room ranking to reduce mismatch and admin workload.

**Q: Why PHP and not Laravel?**  
A: FYP scope required demonstrating core PHP: PDO, sessions, security primitives, and a clear model layer without framework magic. The structure (`includes/models`, `includes/services`) could migrate to Laravel later.

**Q: Is the chatbot real AI?**  
A: The **help assistant** is rule-based pattern matching in `includes/help_chatbot.php`—fast, offline, and predictable for FAQ flows. The **smart allocation** uses scikit-learn `NearestNeighbors` in `ai_service/app.py`, which is genuine machine learning.

---

## Database

**Q: Name your main tables and relationships.**  
A: `users` 1:1 `students`; `hostels` 1:N `rooms`; `students` N:M rooms via `room_allocation` (application workflow) and via `allocations`+`beds` (smart assignment). `complaints`, `room_change_requests`, `payments`, `notices`, `audit_log`, `warden_assignments` hang off students/users/hostels.

**Q: Is the database normalized?**  
A: Yes to 3NF for core entities: no repeating hostel names in rooms (FK `hostel_id`), payments reference `student_id`, fee structure is separate from payment instances. `allocation_history` denormalizes events for audit trail—acceptable.

**Q: Why two allocation tables?**  
A: `room_allocation` is the original student **application** workflow (pending/approved/rejected). Smart allocation added `beds` and `allocations` for bed-level occupancy and KNN. Future work: single workflow; currently both coexist with admin using smart assign after approval.

---

## Security

**Q: How do you prevent SQL injection?**  
A: PDO with `ATTR_EMULATE_PREPARES => false` and bound parameters in all models. User input never concatenated into SQL except whitelisted status values in controlled UPDATE branches.

**Q: How is the password stored?**  
A: `password_hash()` with bcrypt/argon. `verify_user_password()` upgrades legacy plain/MD5 on successful login.

**Q: What is CSRF and where is it used?**  
A: Cross-Site Request Forgery protection via `csrf_token()` in session and `csrf_validate()` on POST forms and the chat API (`X-CSRF-Token` header).

**Q: How do you limit brute-force login?**  
A: `includes/auth_throttle.php` logs attempts in `login_attempts`—blocks after 5 failures per email+IP or 15 per IP in 15 minutes.

**Q: How are wardens restricted to their hostel?**  
A: `require_allocated_warden()` checks `warden_assignments`. Queries use `EXISTS` subqueries filtering `rooms.hostel_id = :hid` so wardens never see other hostels' complaints.

**Q: Is email verification enforced?**  
A: Yes when `REQUIRE_EMAIL_VERIFICATION` is true in `config/env.php`; `login.php` calls `user_email_verified()` before session creation.

---

## AI / Smart allocation

**Q: Explain the KNN recommendation.**  
A: Each vacant room becomes a 5-dimensional feature vector (department match signal, semester, room type preference, available beds, gender/hostel compatibility). We compute distance to an ideal vector for the student; `NearestNeighbors` ranks rooms; scores map to 0–100 for the admin UI.

**Q: What if Python service is down?**  
A: `hostel_recommend_via_python()` returns null; `hostel_recommend_rooms_with_reasons_php()` applies additive rule scoring (+10 department, +5 semester, etc.) documented in `recommendation_service.php`.

**Q: How is the AI service secured?**  
A: Flask checks header `X-AI-Token` matching `AI_SERVICE_TOKEN` in env. Service binds to localhost by default.

---

## Architecture

**Q: What design pattern did you use?**  
A: Thin page controllers (`admin/*.php`, `student/*.php`) + **model functions** (`*_model.php`) + **service layer** for AI (`recommendation_service.php`). Layout partials for DRY UI.

**Q: Where is business logic for payments?**  
A: `includes/models/payment_model.php`—fee structure lookup, recording payments, balance calculations; admin UI in `admin/payments.php`.

---

## Testing & deployment

**Q: How did you test?**  
A: Manual test flows in README: register → verify → apply → admin approve → smart allocate → print card/voucher. Recommend adding PHPUnit before defense.

**Q: How to deploy on XAMPP?**  
A: Import SQL files in order, copy `config/env.example.php` to `config/env.php`, set DB credentials, run `python ai_service/app.py`, open `http://localhost/hostel/`.

---

## Tricky questions (prepare honest answers)

**Q: Why no real email sending?**  
A: XAMPP has no SMTP by default; we store verification tokens and show dev link once. Production would use PHPMailer + SMTP—documented in deployment guide.

**Q: Can a student hack into admin?**  
A: `require_role('admin')` on every admin page; session role checked server-side; CSRF on mutations.

**Q: What would you improve given more time?**  
A: Unify allocation tables, SMTP emails, Docker deploy, automated tests, and student-facing waiting list UI.
