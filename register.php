<?php
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); exit('Invalid request token. Refresh the page and try again.');
    }
    $name = trim((string)($_POST['name'] ?? ''));
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    if ($name === '' || mb_strlen($name) > 100) $error = 'Enter your name (up to 100 characters).';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) $error = 'Enter a valid email address.';
    elseif (strlen($password) < 10) $error = 'Choose a password with at least 10 characters.';
    elseif ($password !== $confirm) $error = 'The passwords do not match.';
    else {
        try {
            $pdo->beginTransaction();
            $userCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $stmt = $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)');
            $stmt->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
            $newId = (int)$pdo->lastInsertId();
            // Existing installation data with no owner is preserved and assigned to the first account only.
            if ($userCount === 0) {
                $pdo->exec('UPDATE transactions SET user_id = ' . $newId . ' WHERE user_id IS NULL');
                $goalTable = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'savings_goals'")->fetchColumn();
                if ((int)$goalTable > 0) $pdo->exec('UPDATE savings_goals SET user_id = ' . $newId . ' WHERE user_id IS NULL');
            }
            $pdo->commit();
            session_regenerate_id(true);
            $_SESSION['user_id'] = $newId; $_SESSION['user_name'] = $name; $_SESSION['user_email'] = $email;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: index.php'); exit;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $ex->getCode() === '23000' ? 'That email address is already registered.' : 'Could not create the account. Check the database connection and try again.';
        }
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SmartBank | Create account</title><link rel="stylesheet" href="style.css"><style>
.auth-wrap{min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(ellipse at top,#191936 0%,#080812 60%)}.auth-card{width:min(100%,460px);background:var(--panel,#111120);border:1px solid var(--border,#28283c);border-radius:22px;padding:32px;box-shadow:0 25px 80px #0006}.auth-brand{font-size:28px;font-weight:800;margin-bottom:6px}.auth-brand span{color:var(--green,#00d9a0)}.auth-card h1{margin:24px 0 8px}.auth-card p{color:var(--muted,#a1a1b8)}.auth-card label{display:block;font-size:13px;color:var(--muted,#a1a1b8);margin:14px 0 7px}.auth-card input{box-sizing:border-box;width:100%;padding:13px 14px;background:#0b0b17;color:var(--text,#f5f5ff);border:1px solid var(--border,#28283c);border-radius:10px}.auth-submit{width:100%;padding:13px;margin-top:20px;border:0;border-radius:10px;background:var(--green,#00d9a0);color:#07140f;font-weight:800;cursor:pointer}.auth-error{background:#ff4f7818;color:#ff7190;border:1px solid #ff4f7840;padding:12px;border-radius:9px}.auth-link{color:var(--green,#00d9a0);text-decoration:none}.auth-foot{font-size:13px;text-align:center;margin-top:22px}
</style></head><body><div class="auth-wrap"><main class="auth-card"><div class="auth-brand">Smart<span>Bank</span></div><p>YOUR MONEY. YOUR GOALS. YOUR SPACE.</p><h1>Create your account</h1><p>Your financial records are private to your account.</p><?php if($error): ?><div class="auth-error"><?=e($error)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><label for="name">Full name</label><input id="name" name="name" required maxlength="100" autocomplete="name" value="<?=e($_POST['name'] ?? '')?>"><label for="email">Email address</label><input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?=e($_POST['email'] ?? '')?>"><label for="password">Password (at least 10 characters)</label><input id="password" type="password" name="password" required minlength="10" autocomplete="new-password"><label for="confirm_password">Confirm password</label><input id="confirm_password" type="password" name="confirm_password" required minlength="10" autocomplete="new-password"><button class="auth-submit" type="submit">Create account →</button></form><div class="auth-foot">Already registered? <a class="auth-link" href="login.php">Log in</a></div></main></div></body></html>