<?php
define('PUBLIC_PATH', __DIR__ . '/../../public_html');
require_once __DIR__ . '/app/Config/App.php';
require_once __DIR__ . '/app/Config/Database.php';

use App\Config\Database;

$db = Database::getInstance();

try {
    // Check if column exists first to avoid error
    $stmt = $db->query("SHOW COLUMNS FROM shops LIKE 'company_id'");
    if ($stmt->rowCount() == 0) {
        // Add company_id to shops table
        $db->exec("ALTER TABLE shops ADD COLUMN company_id INT DEFAULT NULL AFTER user_id");
        $db->exec("ALTER TABLE shops ADD CONSTRAINT fk_shop_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL");
        echo "Column 'company_id' added to 'shops' table.\n";
    } else {
        echo "Column 'company_id' already exists in 'shops' table.\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
