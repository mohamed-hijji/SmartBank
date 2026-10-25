<?php
require_once __DIR__ . '/auth.php';
require_login();
$userId = current_user_id();

function money($value) { return number_format((float)$value, 2) . ' MAD'; }

// Create the table automatically on first visit; no existing data is changed.
$pdo->exec("CREATE TABLE IF NOT EXISTS savings_goals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    target_amount DECIMAL(12,2) NOT NULL,
    saved_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    target_date DATE NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    user_id INT UNSIGNED NULL,
    INDEX idx_savings_goals_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
if (!smartbank_verify_csrf()) {
    http_response_code(403);
    exit('Invalid request. Refresh the page and try again.');
}
    }
    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
        $name = trim((string)($_POST['name'] ?? ''));
        $target = filter_var($_POST['target_amount'] ?? '', FILTER_VALIDATE_FLOAT);
        $savedInput = $_POST['saved_amount'] ?? '0';
        $saved = filter_var($savedInput, FILTER_VALIDATE_FLOAT);
        $date = trim((string)($_POST['target_date'] ?? ''));
        $validDate = $date === '';
        if ($date !== '') {
            $dateObj = DateTime::createFromFormat('!Y-m-d', $date);
            $validDate = $dateObj && $dateObj->format('Y-m-d') === $date;
        }
        if ($name === '' || strlen($name) > 120 || $target === false || !is_finite((float)$target) || $target <= 0 || $target > 999999999999.99 || $saved === false || !is_finite((float)$saved) || $saved < 0 || $saved > $target || !$validDate) {
            $error = 'Enter a goal name, a positive target, a saved amount between zero and the target, and a valid optional date.';
        } else {
            $stmt = $pdo->prepare('INSERT INTO savings_goals (name, target_amount, saved_amount, target_date, user_id) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $target, $saved, $date === '' ? null : $date, $userId]);
            header('Location: goals.php?status=created'); exit;
        }
    } elseif ($action === 'contribute') {
        $id = filter_var($_POST['goal_id'] ?? '', FILTER_VALIDATE_INT);
        $amount = filter_var($_POST['contribution'] ?? '', FILTER_VALIDATE_FLOAT);
        if ($id === false || $id < 1 || $amount === false || !is_finite((float)$amount) || $amount <= 0) {
            $error = 'Enter a valid contribution amount.';
        } else {
            $stmt = $pdo->prepare('UPDATE savings_goals SET saved_amount = LEAST(target_amount, saved_amount + ?) WHERE id = ? AND user_id = ?');
            $stmt->execute([$amount, $id, $userId]);
            header('Location: goals.php?status=updated'); exit;
        }
    } elseif ($action === 'delete') {
        $id = filter_var($_POST['goal_id'] ?? '', FILTER_VALIDATE_INT);
        if ($id !== false && $id > 0) {
            $stmt = $pdo->prepare('DELETE FROM savings_goals WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
        }
        header('Location: goals.php?status=deleted'); exit;
    }

if (($_GET['status'] ?? '') === 'created') $message = 'Your savings goal was created.';
if (($_GET['status'] ?? '') === 'updated') $message = 'Your savings progress was updated.';
if (($_GET['status'] ?? '') === 'deleted') $message = 'Savings goal deleted.';
$goals = $pdo->query('SELECT * FROM savings_goals WHERE user_id = ' . $userId . ' ORDER BY (saved_amount >= target_amount) ASC, target_date IS NULL ASC, target_date ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC);
$targetTotal = 0.0; $savedTotal = 0.0; $completed = 0;
foreach ($goals as $goal) { $targetTotal += (float)$goal['target_amount']; $savedTotal += (float)$goal['saved_amount']; if ((float)$goal['saved_amount'] >= (float)$goal['target_amount']) $completed++; }
$overallProgress = $targetTotal > 0 ? min(100, $savedTotal / $targetTotal * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SmartBank | Savings Goals</title><link rel="stylesheet" href="style.css">
</head>
<body>
<div class="layout">
<aside class="sidebar">
    <h2>Smart<span>Bank</span></h2><p class="muted">PERSONAL FINANCE</p>
    <a href="index.php">▦ <span>Dashboard</span></a>
    <a href="categories.php">◉ <span>Categories</span></a>
    <a href="transactions.php">↕ <span>Transactions</span></a>
    <a href="analytics.php">▥ <span>Analytics</span></a>
    <a class="active" href="goals.php">◎ <span>Savings Goals</span></a>
    <a href="index.php#import-statement">⇧ <span>Import Statement</span></a>
    <p class="sidebar-note">Small steps today. Big goals tomorrow.</p>
    <div class="sidebar-account" style="margin-top:18px;padding:12px;border:1px solid var(--border);border-radius:12px"><div style="font-size:12px;font-weight:700;overflow-wrap:anywhere">👤 <?= e($_SESSION['user_name'] ?? 'Account') ?></div><div style="font-size:11px;color:var(--muted);margin:4px 0 10px;overflow-wrap:anywhere"><?= e($_SESSION['user_email'] ?? '') ?></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><button type="submit" style="width:100%;padding:9px;border-radius:8px;border:1px solid var(--border);background:#202037;color:var(--text);cursor:pointer">Log out ↗</button></form></div>
</aside>
<main class="main">
<header class="topbar"><div><h1>Savings <span class="accent-text">Goals</span> ✦</h1><p class="muted">Make your next big thing happen, one deposit at a time.</p></div><span class="currency">MY FINANCIAL FUTURE</span></header>
<?php if ($message !== ''): ?><div class="goal-flash"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="goal-flash goal-error"><?= e($error) ?></div><?php endif; ?>
<section class="goals-summary">
  <article class="stat-card"><p>Total saved toward goals</p><h2 class="positive"><?= e(money($savedTotal)) ?></h2><span class="stat-caption">Across <?= count($goals) ?> active goal<?= count($goals) === 1 ? '' : 's' ?></span></article>
  <article class="stat-card"><p>Total target amount</p><h2><?= e(money($targetTotal)) ?></h2><span class="stat-caption">Your combined savings target</span></article>
  <article class="stat-card"><p>Goals completed</p><h2><?= (int)$completed ?> / <?= count($goals) ?></h2><span class="stat-caption">Keep building your momentum</span></article>
</section>
<section class="panel">
  <div class="panel-heading"><div><h2>Create a savings goal</h2><p class="muted">Set a target and track your progress.</p></div><span class="chart-badge">GOAL PLANNER</span></div>
  <form method="POST" class="goal-form">
    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="create">
    <label>Goal name<input name="name" maxlength="120" placeholder="e.g. New laptop" required></label>
    <label>Target amount (MAD)<input type="number" name="target_amount" min="0.01" max="999999999999.99" step="0.01" placeholder="12000" required></label>
    <label>Already saved (MAD)<input type="number" name="saved_amount" min="0" step="0.01" value="0"></label>
    <label>Target date (optional)<input type="date" name="target_date" min="<?= e(date('Y-m-d')) ?>"></label>
    <div class="full"><button type="submit">＋ Create goal</button></div>
  </form>
</section>
<section class="panel">
 <div class="panel-heading"><div><h2>Your goals</h2><p class="muted">Update your savings as you make progress.</p></div><span class="chart-badge"><?= count($goals) ?> GOAL<?= count($goals) === 1 ? '' : 'S' ?></span></div>
 <?php if (!$goals): ?>
  <div class="goal-empty"><span style="font-size:36px">◎</span><strong>Your first goal starts here</strong>Create a goal above to see your progress and celebrate milestones.</div>
 <?php else: ?>
  <?php foreach ($goals as $i => $goal): $target=(float)$goal['target_amount']; $saved=(float)$goal['saved_amount']; $progress=$target > 0 ? min(100, max(0, $saved / $target * 100)) : 0; $remaining=max(0, $target-$saved); $isComplete=$progress >= 100; ?>
   <article class="goal-card" style="animation-delay:<?= min($i, 8) * 70 ?>ms">
    <div class="goal-card-top"><div><h3 class="goal-title"><?= e($goal['name']) ?> <?= $isComplete ? '✓' : '' ?></h3><p class="goal-description"><?= $isComplete ? 'Goal achieved — congratulations!' : ($goal['target_date'] ? 'Target date: ' . e(date('M j, Y', strtotime($goal['target_date']))) : 'No deadline set') ?></p></div><div class="goal-amount"><?= e(money($saved)) ?> <small>of <?= e(money($target)) ?></small></div></div>
    <div class="goal-track"><span style="--goal-progress:<?= number_format($progress, 2, '.', '') ?>%"></span></div>
    <div class="goal-meta"><span><?= number_format($progress, 1) ?>% complete</span><span><?= $isComplete ? 'Fully funded' : e(money($remaining)) . ' to go' ?></span></div>
    <div class="goal-actions">
      <?php if (!$isComplete): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="contribute"><input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>"><label>Add savings (MAD)<input type="number" name="contribution" min="0.01" step="0.01" max="<?= e(number_format($remaining, 2, '.', '')) ?>" placeholder="e.g. 200" required></label><button type="submit">＋ Add savings</button></form><?php endif; ?>
      <form method="POST" onsubmit="return confirm('Delete this savings goal? This cannot be undone.');" style="flex:0 0 auto;min-width:0"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="goal_id" value="<?= (int)$goal['id'] ?>"><button class="goal-delete" type="submit">Delete</button></form>
    </div>
   </article>
  <?php endforeach; ?>
 <?php endif; ?>
</section>
<section class="panel"><div class="panel-heading"><div><h2>Overall progress</h2><p class="muted">Your combined progress across all savings goals.</p></div><strong class="accent-text"><?= number_format($overallProgress, 1) ?>%</strong></div><div class="goal-track"><span style="--goal-progress:<?= number_format($overallProgress, 2, '.', '') ?>%"></span></div><div class="goal-meta"><span><?= e(money($savedTotal)) ?> saved</span><span><?= e(money(max(0, $targetTotal - $savedTotal))) ?> remaining</span></div></section>
<footer class="muted" style="font-size:11px;padding:0 0 25px">Savings goal contributions update your goal progress only; they do not create bank transactions automatically.</footer>
</main></div>
</body></html>
