<?php

define('APP_NAME', 'ColiXpress API');
define('APP_VERSION', '1.0.0');
define('APP_ENV', 'development'); // development | production
define('APP_DEBUG', true);

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
define('UPLOAD_URL', '/uploads');
define('MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Pagination
define('DEFAULT_PER_PAGE', 20);
define('MAX_PER_PAGE', 100);

// Google Custom Search API
define('GOOGLE_API_KEY', 'YOUR_GOOGLE_API_KEY_HERE'); // Remplacez par votre clé
define('GOOGLE_SEARCH_CX', 'YOUR_GOOGLE_SEARCH_CX_HERE'); // Remplacez par votre ID de moteur de recherche
