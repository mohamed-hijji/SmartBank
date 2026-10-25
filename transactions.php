<?php
require_once __DIR__ . '/auth.php';
require_login();
$userId = current_user_id();

function money($value) { return number_format((float)$value, 2) . ' MAD'; }
function validDate($value) {
    if (!is_string($value) || $value === '') return false;
    $d = DateTime::createFromFormat('!Y-m-d', $value);
    $errors = DateTime::getLastErrors();
    return $d && $d->format('Y-m-d') === $value && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
}
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   
if (!smartbank_verify_csrf()) {
    http_response_code(403);
    exit('Invalid request token. Refresh and try again.');
}
    }
    $action = $_POST['action'] ?? '';
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($action === 'update' && $id && $id > 0) {
        $description = trim((string)($_POST['description'] ?? ''));
        $amount = filter_var($_POST['amount'] ?? '', FILTER_VALIDATE_FLOAT);
        $type = $_POST['type'] ?? '';
        $category = trim((string)($_POST['category'] ?? 'Other'));
        $date = $_POST['transaction_date'] ?? '';
        if ($description === '' || strlen($description) > 255 || $amount === false || !is_finite((float)$amount) || $amount <= 0 || $amount > 9999999999.99 || !in_array($type, ['income','expense'], true) || $category === '' || strlen($category) > 100 || !validDate($date)) {
            $error = 'Please enter valid transaction details.';
        } else {
            $stmt = $pdo->prepare('UPDATE transactions SET description = ?, amount = ?, type = ?, category = ?, transaction_date = ? WHERE id = ? AND user_id = ?');
            $stmt->execute([$description, $amount, $type, $category, $date, $id, $userId]);
            header('Location: transactions.php?updated=1'); exit;
        }
    } elseif ($action === 'delete' && $id && $id > 0) {
        $stmt = $pdo->prepare('DELETE FROM transactions WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        header('Location: transactions.php?deleted=1'); exit;
    }

if (isset($_GET['updated'])) $success = 'Transaction updated successfully.';
if (isset($_GET['deleted'])) $success = 'Transaction deleted.';
$editId = filter_var($_GET['edit'] ?? '', FILTER_VALIDATE_INT);
$editing = null;
if ($editId && $editId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM transactions WHERE id = ? AND user_id = ?'); $stmt->execute([$editId, $userId]); $editing = $stmt->fetch();
}
$categories = $pdo->query("SELECT DISTINCT category FROM transactions WHERE user_id = $userId AND category <> '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
$category = trim((string)($_GET['category'] ?? ''));
$type = (string)($_GET['type'] ?? 'all');
$from = (string)($_GET['from'] ?? '');
$to = (string)($_GET['to'] ?? '');
$search = trim((string)($_GET['q'] ?? ''));
if (!in_array($type, ['all','income','expense'], true)) $type = 'all';
if (!validDate($from)) $from = '';
if (!validDate($to)) $to = '';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$where = ['user_id = ?']; $params = [$userId];
if ($search !== '') { $where[] = '(description LIKE ? OR category LIKE ?)'; $params[] = '%' . $search . '%'; $params[] = '%' . $search . '%'; }
if ($type !== 'all') { $where[] = 'type = ?'; $params[] = $type; }
if ($category !== '') { $where[] = 'category = ?'; $params[] = $category; }
if ($from !== '') { $where[] = 'transaction_date >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'transaction_date <= ?'; $params[] = $to; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM transactions' . $whereSql); $countStmt->execute($params); $total = (int)$countStmt->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage)); if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;
$listStmt = $pdo->prepare('SELECT * FROM transactions' . $whereSql . ' ORDER BY transaction_date DESC, id DESC LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
$listStmt->execute($params); $rows = $listStmt->fetchAll();
$sumStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income, COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expenses FROM transactions" . $whereSql);
$sumStmt->execute($params); $totals = $sumStmt->fetch();
function queryUrl($overrides = []) { $values = $_GET; foreach ($overrides as $key=>$value) { if ($value === null || $value === '') unset($values[$key]); else $values[$key] = $value; } return 'transactions.php?' . http_build_query($values); }
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>SmartBank | Transactions</title><link rel="stylesheet" href="style.css"><style>
.tx-main{max-width:1500px}.tx-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:20px}.tx-stat{background:var(--panel);border:1px solid var(--border);border-radius:15px;padding:18px 20px;animation:txIn .45s both}.tx-stat:nth-child(2){animation-delay:.08s}.tx-stat:nth-child(3){animation-delay:.16s}.tx-stat small{color:var(--muted)}.tx-stat strong{display:block;font-size:22px;margin-top:9px}.tx-filters{display:grid;grid-template-columns:minmax(180px,2fr) repeat(4,minmax(130px,1fr)) auto;gap:10px;align-items:end}.tx-filters label,.edit-grid label{display:flex;flex-direction:column;gap:7px;font-size:12px;color:var(--muted)}.tx-filters input,.tx-filters select,.edit-grid input,.edit-grid select{min-width:0;width:100%;padding:11px 12px;border-radius:9px;border:1px solid var(--border);background:#0b0b17;color:var(--text)}.tx-filters button,.tx-btn{border:0;border-radius:9px;padding:11px 14px;cursor:pointer;background:var(--green);color:#06140f;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:6px}.tx-btn.secondary{background:#202037;color:var(--text);border:1px solid var(--border)}.tx-btn.danger{background:rgba(255,79,120,.12);color:var(--red);border:1px solid rgba(255,79,120,.25)}.tx-table-wrap{overflow-x:auto}.tx-table{width:100%;border-collapse:collapse;white-space:nowrap}.tx-table th,.tx-table td{text-align:left;padding:14px 12px;border-bottom:1px solid var(--border);font-size:13px}.tx-table th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.7px}.tx-table tbody tr{transition:background .2s}.tx-table tbody tr:hover{background:rgba(255,255,255,.025)}.tx-type{display:inline-block;border-radius:30px;padding:5px 9px;font-size:11px;font-weight:700}.tx-type.income{background:rgba(0,217,160,.12);color:var(--green)}.tx-type.expense{background:rgba(255,79,120,.12);color:var(--red)}.tx-actions{display:flex;gap:7px;align-items:center}.tx-actions form{margin:0}.tx-alert{padding:12px 14px;border-radius:10px;margin-bottom:15px;background:rgba(0,217,160,.1);color:var(--green)}.tx-alert.error{background:rgba(255,79,120,.1);color:var(--red)}.tx-pagination{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}.tx-pages{display:flex;gap:6px}.tx-pages a,.tx-pages span{padding:8px 11px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text);font-size:12px}.tx-pages .current{background:var(--green);border-color:var(--green);color:#07140f}.edit-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.edit-grid .wide{grid-column:1/-1}.tx-empty{text-align:center;padding:35px 10px;color:var(--muted)}@keyframes txIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}@media(max-width:1100px){.tx-filters{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:650px){.tx-stats{grid-template-columns:1fr}.tx-filters{grid-template-columns:repeat(2,minmax(0,1fr))}.tx-filters .search-field{grid-column:1/-1}.edit-grid{grid-template-columns:1fr}.edit-grid .wide{grid-column:auto}}
</style></head><body><div class="layout"><aside class="sidebar">
    <h2>Smart<span>Bank</span></h2><p class="muted">PERSONAL FINANCE</p>
    <a href="index.php">▦ <span>Dashboard</span></a>
    <a href="categories.php">◉ <span>Categories</span></a>
    <a class="active" href="transactions.php">↕ <span>Transactions</span></a>
    <a href="analytics.php">▥ <span>Analytics</span></a>
    <a href="goals.php">◎ <span>Savings Goals</span></a>
    <a href="index.php#import-statement">⇧ <span>Import Statement</span></a>
    <p class="sidebar-note">Every transaction, under control.</p>
    <div class="sidebar-account" style="margin-top:18px;padding:12px;border:1px solid var(--border);border-radius:12px"><div style="font-size:12px;font-weight:700;overflow-wrap:anywhere">👤 <?= e($_SESSION['user_name'] ?? 'Account') ?></div><div style="font-size:11px;color:var(--muted);margin:4px 0 10px;overflow-wrap:anywhere"><?= e($_SESSION['user_email'] ?? '') ?></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><button type="submit" style="width:100%;padding:9px;border-radius:8px;border:1px solid var(--border);background:#202037;color:var(--text);cursor:pointer">Log out ↗</button></form></div>
</aside><main class="main tx-main"><header class="topbar"><div><h1>Transactions</h1><p class="muted">Search, filter, and manage every movement.</p></div><a class="tx-btn" href="index.php#add-transaction">＋ Add transaction</a></header>
<?php if ($success): ?><div class="tx-alert"><?=e($success)?></div><?php endif; ?><?php if ($error): ?><div class="tx-alert error"><?=e($error)?></div><?php endif; ?>
<?php if ($editing): ?><section class="panel" id="edit"><h2>✎ Edit transaction #<?= (int)$editing['id'] ?></h2><form method="post" class="edit-grid"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><label class="wide">Description<input name="description" maxlength="255" required value="<?=e($editing['description'])?>"></label><label>Amount (MAD)<input type="number" name="amount" min="0.01" max="9999999999.99" step="0.01" required value="<?=e($editing['amount'])?>"></label><label>Type<select name="type"><option value="expense" <?=$editing['type']==='expense'?'selected':''?>>Expense</option><option value="income" <?=$editing['type']==='income'?'selected':''?>>Income</option></select></label><label>Category<input name="category" maxlength="100" required value="<?=e($editing['category'])?>"></label><label>Date<input type="date" name="transaction_date" required value="<?=e($editing['transaction_date'])?>"></label><div class="wide" style="display:flex;gap:9px"><button class="tx-btn" type="submit">Save changes</button><a class="tx-btn secondary" href="transactions.php">Cancel</a></div></form></section><?php elseif ($editId): ?><div class="tx-alert error">Transaction not found.</div><?php endif; ?>
<section class="tx-stats"><div class="tx-stat"><small>Matching transactions</small><strong><?=number_format($total)?></strong></div><div class="tx-stat"><small>Filtered income</small><strong style="color:var(--green)"><?=e(money($totals['income']))?></strong></div><div class="tx-stat"><small>Filtered expenses</small><strong style="color:var(--red)"><?=e(money($totals['expenses']))?></strong></div></section>
<section class="panel"><h2>Find transactions</h2><form method="get" id="filters" class="tx-filters"><label class="search-field">Live search<input id="liveSearch" type="search" name="q" value="<?=e($search)?>" placeholder="Description or category…" autocomplete="off"></label><label>Type<select name="type"><option value="all" <?=$type==='all'?'selected':''?>>All types</option><option value="income" <?=$type==='income'?'selected':''?>>Income</option><option value="expense" <?=$type==='expense'?'selected':''?>>Expense</option></select></label><label>Category<select name="category"><option value="">All categories</option><?php foreach($categories as $cat): ?><option value="<?=e($cat)?>" <?=$category===$cat?'selected':''?>><?=e($cat)?></option><?php endforeach; ?></select></label><label>From date<input type="date" name="from" value="<?=e($from)?>"></label><label>To date<input type="date" name="to" value="<?=e($to)?>"></label><button type="submit">Filter</button><a class="tx-btn secondary" href="transactions.php">Reset</a></form><p class="muted" style="font-size:12px;margin-bottom:0">Search updates automatically. Other filters apply when you select a value or press Filter.</p></section>
<section class="panel"><div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:14px"><h2 style="margin:0">All transactions</h2><span class="muted" style="font-size:12px">Showing <?= $total ? $offset+1 : 0 ?>–<?=min($offset+count($rows),$total)?> of <?=number_format($total)?></span></div><div class="tx-table-wrap"><table class="tx-table"><thead><tr><th>Date</th><th>Description</th><th>Category</th><th>Type</th><th>Amount</th><th>Actions</th></tr></thead><tbody><?php if (!$rows): ?><tr><td colspan="6" class="tx-empty">No transactions match these filters.</td></tr><?php else: foreach($rows as $row): ?><tr><td><?=e($row['transaction_date'])?></td><td><?=e($row['description'])?></td><td><span class="tag"><?=e($row['category'])?></span></td><td><span class="tx-type <?=e($row['type'])?>"><?=e(ucfirst($row['type']))?></span></td><td style="font-weight:700;color:<?=$row['type']==='income'?'var(--green)':'var(--red)'?>"><?=$row['type']==='income'?'+':'−'?> <?=e(money($row['amount']))?></td><td><div class="tx-actions"><a class="tx-btn secondary" href="transactions.php?<?=e(http_build_query(array_merge($_GET,['edit'=>(int)$row['id']])))?>#edit">Edit</a><form method="post" onsubmit="return confirm('Delete this transaction permanently?')"><input type="hidden" name="csrf_token" value="<?=e($_SESSION['csrf_token'])?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=(int)$row['id']?>"><button class="tx-btn danger" type="submit">Delete</button></form></div></td></tr><?php endforeach; endif; ?></tbody></table></div><div class="tx-pagination"><span class="muted" style="font-size:12px">Page <?=$page?> of <?=$pages?></span><div class="tx-pages"><?php if($page>1): ?><a href="<?=e(queryUrl(['page'=>$page-1]))?>">← Previous</a><?php endif; ?><?php for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++): ?><?php if($p===$page): ?><span class="current"><?=$p?></span><?php else: ?><a href="<?=e(queryUrl(['page'=>$p]))?>"><?=$p?></a><?php endif; ?><?php endfor; ?><?php if($page<$pages): ?><a href="<?=e(queryUrl(['page'=>$page+1]))?>">Next →</a><?php endif; ?></div></div></section></main></div><script>
(() => { const input=document.getElementById('liveSearch'); const form=document.getElementById('filters'); let timer; input.addEventListener('input',()=>{clearTimeout(timer);timer=setTimeout(()=>{const params=new URLSearchParams(new FormData(form));params.delete('page');window.location.href='transactions.php?'+params.toString();},350);}); form.querySelectorAll('select,input[type="date"]').forEach(el=>el.addEventListener('change',()=>form.requestSubmit())); })();
</script></body></html>
