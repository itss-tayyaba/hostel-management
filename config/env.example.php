<?php
declare(strict_types=1);
/**
 * @file config/env.example.php
 * @brief Environment template — Copy to config/env.php — DB and AI settings
 * @access system
 */

/**
 * Copy this file to config/env.php and adjust values for your environment.
 * config/env.php is gitignored — never commit real credentials.
 */

return [
  'DB_HOST' => 'localhost',
  'DB_NAME' => 'hostel_management',
  'DB_USER' => 'root',
  'DB_PASS' => '',

  // Python AI microservice (admin smart allocation)
  'AI_SERVICE_URL' => 'http://127.0.0.1:5001/recommend',
  'AI_SERVICE_TOKEN' => 'change-me-in-production',
  'AI_SERVICE_TIMEOUT' => 2,

  // Students must verify email before login. Admins are always exempt.
  // Set false on localhost/XAMPP when you do not have real SMTP.
  'REQUIRE_EMAIL_VERIFICATION' => false,

  // Set true to show password-reset link on screen (local dev only)
  'DEV_SHOW_RESET_LINK' => true,
];
