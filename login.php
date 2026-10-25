<?php
require_once __DIR__ . '/auth.php';
if (!empty($_SESSION['user_id'])) { header('Location: index.php'); exit; }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); exit('Invalid request token. Refresh the page and try again.');
    }
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
        $error = 'Enter a valid email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]); $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($user && password_verify($password, $user['password_hash'])) {
            if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $user['id']]);
            }
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: index.php'); exit;
        }
        $error = 'Email or password is incorrect.';
    }
}
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SmartBank | Login</title><link rel="stylesheet" href="style.css"><style>
.auth-wrap{min-height:100vh;display:grid;place-items:center;padding:24px;background:radial-gradient(ellipse at top,#191936 0%,#080812 60%)}.auth-card{width:min(100%,440px);background:var(--panel,#111120);border:1px solid var(--border,#28283c);border-radius:22px;padding:32px;box-shadow:0 25px 80px #0006}.auth-brand{font-size:28px;font-weight:800;margin-bottom:6px}.auth-brand span{color:var(--green,#00d9a0)}.auth-card h1{margin:26px 0 8px}.auth-card p{color:var(--muted,#a1a1b8)}.auth-card label{display:block;font-size:13px;color:var(--muted,#a1a1b8);margin:16px 0 7px}.auth-card input{box-sizing:border-box;width:100%;padding:13px 14px;background:#0b0b17;color:var(--text,#f5f5ff);border:1px solid var(--border,#28283c);border-radius:10px}.auth-submit{width:100%;padding:13px;margin-top:20px;border:0;border-radius:10px;background:var(--green,#00d9a0);color:#07140f;font-weight:800;cursor:pointer}.auth-error{background:#ff4f7818;color:#ff7190;border:1px solid #ff4f7840;padding:12px;border-radius:9px}.auth-link{color:var(--green,#00d9a0);text-decoration:none}.auth-foot{font-size:13px;text-align:center;margin-top:22px}
</style></head><body><div class="auth-wrap"><main class="auth-card"><div class="auth-brand">Smart<span>Bank</span></div><p>PERSONAL FINANCE, UNDER YOUR CONTROL</p><h1>Welcome back</h1><p>Log in to continue to your dashboard.</p><?php if($error): ?><div class="auth-error"><?=e($error)?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><label for="email">Email address</label><input id="email" type="email" name="email" required maxlength="190" autocomplete="email" value="<?=e($_POST['email'] ?? '')?>"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"><button class="auth-submit" type="submit">Log in securely →</button></form><div class="auth-foot">New to SmartBank? <a class="auth-link" href="register.php">Create an account</a></div></main></div></body></html>