-- Run in phpMyAdmin (database: hostel_management) to fix admin login without email inbox.
-- Option A: Verify existing admin@gmail.com so login works immediately.

UPDATE users
SET email_verified_at = NOW(),
    email_verify_token = NULL,
    is_active = 1,
    role = 'admin'
WHERE LOWER(TRIM(email)) = 'admin@gmail.com';

-- Option B: Remove old admin (uncomment if you want a clean slate).
-- DELETE FROM users WHERE LOWER(TRIM(email)) = 'admin@gmail.com' AND role = 'admin';

-- Option C: Insert new admin — replace YOUR_BCRYPT_HASH with output of PHP password_hash().
-- INSERT INTO users (name, email, password, role, is_active, email_verified_at)
-- VALUES ('Hostel Admin', 'admin@hostel.local', 'YOUR_BCRYPT_HASH', 'admin', 1, NOW());
