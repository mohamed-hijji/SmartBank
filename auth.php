```php
<?php
// SmartBank authentication and per-account data isolation.

// Configure session security before starting the session.
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');

    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);

    session_start();
}

// Create the database tables and safely add ownership columns.
function smartbank_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $tables = ['transactions', 'savings_goals'];

    foreach ($tables as $table) {
        $exists = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = ?
        ");

        $exists->execute([$table]);

        if ((int) $exists->fetchColumn() === 0) {
            continue;
        }

        $column = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND column_name = 'user_id'
        ");

        $column->execute([$table]);

        if ((int) $column->fetchColumn() === 0) {
            $pdo->exec("
                ALTER TABLE `$table`
                ADD COLUMN user_id INT UNSIGNED NULL,
                ADD INDEX idx_{$table}_user_id (user_id)
            ");
        }
    }
}

// Generate a CSRF token for forms.
function smartbank_csrf(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

// Verify a submitted CSRF token.
function smartbank_verify_csrf(): bool
{
    $submitted = (string) ($_POST['csrf_token'] ?? '');
    $stored = (string) ($_SESSION['csrf_token'] ?? '');

    return $stored !== ''
        && $submitted !== ''
        && hash_equals($stored, $submitted);
}

// Protect pages that require authentication.
function require_login(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}

// Get the logged-in user's ID.
function current_user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

// Escape output to help prevent cross-site scripting.
function e($value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

// Load the database connection and initialize the schema.
require_once __DIR__ . '/db.php';

smartbank_schema($pdo);
smartbank_csrf();
