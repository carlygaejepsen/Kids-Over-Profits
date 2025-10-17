<?php
/**
 * Database Configuration Loader
 * Loads database credentials from .env file
 */

// Load environment variables from .env file
$envPath = __DIR__ . '/.env';

if (!file_exists($envPath)) {
    throw new Exception(
        'Missing .env file. Please copy .env.example to .env and configure your database credentials.'
    );
}

// Parse .env file
$envLines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($envLines as $line) {
    // Skip comments
    if (strpos(trim($line), '#') === 0) {
        continue;
    }

    // Parse KEY=VALUE pairs
    if (strpos($line, '=') !== false) {
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);

        // Remove quotes if present
        $value = trim($value, '"\'');

        // Set as environment variable
        putenv("$key=$value");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

// Database configuration
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASS');
$db_charset = getenv('DB_CHARSET') ?: 'utf8mb4';

if (empty($db_name) || empty($db_user)) {
    throw new Exception(
        'Database credentials not configured. Please check your .env file.'
    );
}

// Create PDO connection
try {
    $dsn = "mysql:host=$db_host;dbname=$db_name;charset=$db_charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, $db_user, $db_pass, $options);

} catch (PDOException $e) {
    throw new Exception('Database connection failed: ' . $e->getMessage());
}
