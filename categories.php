<?php
require_once __DIR__ . '/auth.php';
require_login();
$userId = current_user_id();
function money($value) { return number_format((float)$value, 2) . ' MAD'; }
$sql = "SELECT category, COUNT(*) AS transaction_count, COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expenses FROM transactions WHERE user_id = $userId GROUP BY category ORDER BY expenses DESC, category ASC";
$categories = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
$totalIncome = 0; $totalExpenses = 0; $transactionCount = 0;
foreach ($categories as $c) { $totalIncome += (float)$c['income']; $totalExpenses += (float)$c['expenses']; $transactionCount += (int)$c['transaction_count']; }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>SmartBank | Categories</title><link rel="stylesheet" href="style.css"><style>
.cat-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.cat-stat{background:var(--panel);border:1px solid var(--border);border-radius:15px;padding:18px 20px}.cat-stat small{color:var(--muted)}.cat-stat strong{display:block;font-size:22px;margin-top:9px}.cat-table-wrap{overflow-x:auto}.cat-table{width:100%;border-collapse:collapse}.cat-table th,.cat-table td{text-align:left;padding:14px 12px;border-bottom:1px solid var(--border);font-size:13px}.cat-table th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.7px}.cat-link{display:inline-flex;padding:8px 11px;border-radius:8px;background:#202037;border:1px solid var(--border);color:var(--text);text-decoration:none;font-size:12px}.cat-empty{text-align:center;padding:35px 10px;color:var(--muted)}@media(max-width:650px){.cat-stats{grid-template-columns:1fr}}
</style></head><body><div class="layout"><aside class="sidebar">
    <h2>Smart<span>Bank</span></h2><p class="muted">PERSONAL FINANCE</p>
    <a href="index.php">▦ <span>Dashboard</span></a>
    <a class="active" href="categories.php">◉ <span>Categories</span></a>
    <a href="transactions.php">↕ <span>Transactions</span></a>
    <a href="analytics.php">▥ <span>Analytics</span></a>
    <a href="goals.php">◎ <span>Savings Goals</span></a>
    <a href="index.php#import-statement">⇧ <span>Import Statement</span></a>
    <p class="sidebar-note">Every category, clearly organized</p>
    <div class="sidebar-account" style="margin-top:18px;padding:12px;border:1px solid var(--border);border-radius:12px"><div style="font-size:12px;font-weight:700;overflow-wrap:anywhere">👤 <?= e($_SESSION['user_name'] ?? 'Account') ?></div><div style="font-size:11px;color:var(--muted);margin:4px 0 10px;overflow-wrap:anywhere"><?= e($_SESSION['user_email'] ?? '') ?></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><button type="submit" style="width:100%;padding:9px;border-radius:8px;border:1px solid var(--border);background:#202037;color:var(--text);cursor:pointer">Log out ↗</button></form></div>
</aside>
<main class="main"><header class="topbar"><div><h1>Categories</h1><p class="muted">See where your money comes from and where it goes.</p></div><a class="button" href="index.php#add-transaction">＋ Add transaction</a></header>
<section class="cat-stats"><article class="cat-stat"><small>Categories</small><strong><?= count($categories) ?></strong></article><article class="cat-stat"><small>Income across categories</small><strong style="color:var(--green)"><?= e(money($totalIncome)) ?></strong></article><article class="cat-stat"><small>Expenses across categories</small><strong style="color:var(--red)"><?= e(money($totalExpenses)) ?></strong></article></section>
<section class="panel"><h2>Category overview</h2><p class="muted" style="font-size:13px">Choose “View transactions” to see all transactions in a category.</p><div class="cat-table-wrap"><table class="cat-table"><thead><tr><th>Category</th><th>Transactions</th><th>Income</th><th>Expenses</th><th>Net</th><th></th></tr></thead><tbody>
<?php if (!$categories): ?><tr><td colspan="6" class="cat-empty">No categories yet. Add a transaction to create your first category.</td></tr><?php else: foreach ($categories as $c): $net=(float)$c['income']-(float)$c['expenses']; ?><tr><td><strong><?= e($c['category']) ?></strong></td><td><?= (int)$c['transaction_count'] ?></td><td style="color:var(--green)"><?= e(money($c['income'])) ?></td><td style="color:var(--red)"><?= e(money($c['expenses'])) ?></td><td style="color:<?= $net >= 0 ? 'var(--green)' : 'var(--red)' ?>"><?= e(money($net)) ?></td><td><a class="cat-link" href="transactions.php?<?= e(http_build_query(['category'=>$c['category']])) ?>">View transactions →</a></td></tr><?php endforeach; endif; ?>
</tbody></table></div></section></main></div></body></html>
