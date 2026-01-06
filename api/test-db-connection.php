<?php
header("Content-Type: text/plain");
$wp_config_path = null;
$current = __DIR__;
for ($i = 0; $i < 10; $i++) {
    echo "Checking for wp-config.php in: $current\n";
    if (file_exists($current . "/wp-config.php")) {
        $wp_config_path = $current . "/wp-config.php";
        echo "FOUND wp-config.php at: $wp_config_path\n";
        break;
    }
    $current = dirname($current);
}

require_once __DIR__ . "/config.php";
echo "Resolved DB_HOST: " . $db_host . "\n";
echo "Resolved DB_NAME: " . $db_name . "\n";
echo "PDO initialized: " . (isset($pdo) ? "YES" : "NO") . "\n";
?>
