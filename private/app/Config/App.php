<?php

define('APP_NAME', 'ColiXpress API');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'development'); // development | production
define('APP_DEBUG', true);
define('APP_URL', (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'colixpress_db');
define('DB_USER', 'colixpress_user');
define('DB_PASS', 'C0l1Xpr3ss_2025!Secure');
define('DB_CHARSET', 'utf8mb4');

// Auth
define('TOKEN_SECRET', 'cX_s3cr3t_k3y_2025_ch@ng3_m3_in_pr0d!');
define('TOKEN_EXPIRY_HOURS', 720); // 30 days
define('OTP_EXPIRY_MINUTES', 5);
define('OTP_LENGTH', 4);

// Upload
define('UPLOAD_DIR', PUBLIC_PATH . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Twilio Verify (SMS OTP) — valeurs dans /home/legeekoconsulting/web/api.colixpress.com/.env
define('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: '');
define('TWILIO_AUTH_TOKEN',  getenv('TWILIO_AUTH_TOKEN')  ?: '');
define('TWILIO_VERIFY_SID',  getenv('TWILIO_VERIFY_SID')  ?: '');
define('SMS_ENABLED',        getenv('SMS_ENABLED') === 'true');

// Pagination
define('DEFAULT_PER_PAGE', 20);
define('MAX_PER_PAGE', 100);

