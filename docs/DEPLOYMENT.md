# Deployment Guide — Production & Demo

## 1. Server requirements

| Component | Version |
|-----------|---------|
| PHP | 8.0+ (extensions: pdo_mysql, mbstring, curl, json) |
| MySQL / MariaDB | 10.4+ |
| Apache | 2.4+ with `mod_rewrite` optional |
| Python (AI) | 3.9+ for optional microservice |

## 2. Database setup

```sql
CREATE DATABASE hostel_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE hostel_management;
SOURCE database/schema.sql;
SOURCE database/smart_allocation_schema.sql;
SOURCE database/v2_full_upgrade.sql;
-- Optional demo data:
SOURCE database/smart_allocation_sample_data.sql;
SOURCE database/test_seed_hostels_rooms.sql;
```

Create first admin (run once in PHP CLI or temporary script):

```php
echo password_hash('YourSecureAdminPass', PASSWORD_DEFAULT);
```

Insert into `users` with `role='admin'`, `is_active=1`, `email_verified_at=NOW()`.

## 3. Application configuration

```bash
cp config/env.example.php config/env.php
```

Edit `config/env.php`:

```php
'DB_HOST' => 'localhost',
'DB_NAME' => 'hostel_management',
'DB_USER' => 'hostel_app',
'DB_PASS' => 'strong-password-here',
'AI_SERVICE_TOKEN' => 'generate-random-32-chars',
'REQUIRE_EMAIL_VERIFICATION' => true,
'DEV_SHOW_RESET_LINK' => false,
```

## 4. Apache virtual host (example)

```apache
<VirtualHost *:80>
    ServerName hostel.uaf.local
    DocumentRoot "C:/xampp/htdocs/hostel"
    <Directory "C:/xampp/htdocs/hostel">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Enable HTTPS in production so session cookies use `secure` flag (`includes/app.php` detects HTTPS).

## 5. Python AI microservice

```bash
cd ai_service
python -m venv venv
venv\Scripts\activate   # Windows
pip install -r requirements.txt
set AI_TOKEN=same-as-config-env
set DB_HOST=localhost
set DB_USER=hostel_app
set DB_PASS=strong-password-here
python app.py
```

Service listens on `127.0.0.1:5001` — do not expose publicly without firewall; PHP calls it server-side only.

## 6. File permissions

- `uploads/student_avatars/` writable by Apache user
- `uploads/.htaccess` must remain (blocks PHP execution in uploads)

## 7. Pre-go-live checklist

- [ ] `config/env.php` not in git
- [ ] Default AI token changed
- [ ] MySQL not using empty root password
- [ ] Error display off in `php.ini` (`display_errors=Off`)
- [ ] Backup script for `hostel_management` database
- [ ] Test login for admin, student, warden roles

## 8. Demo laptop setup (FYP day)

1. Start XAMPP Apache + MySQL  
2. Start `python ai_service/app.py` in separate terminal  
3. Open `http://localhost/hostel/`  
4. Admin → Smart Allocation — confirm badge shows **Python · scikit-learn KNN**  
5. Student → Help assistant — test one question  

If Python fails, demo still works on PHP fallback scorer.
