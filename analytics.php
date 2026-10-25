<?php
session_start();
require_once 'db.php';

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function money($amount) {
    return number_format((float)$amount, 2) . ' MAD';
}
function signedPercent($current, $previous) {
    if ((float)$previous == 0.0) {
        return (float)$current == 0.0 ? 0.0 : null;
    }
    return (($current - $previous) / abs($previous)) * 100;
}
function pctText($value) {
    if ($value === null) return 'New activity';
    $prefix = $value > 0 ? '+' : '';
    return $prefix . number_format($value, 1) . '%';
}

$allowedPeriods = [3, 6, 12];
$period = isset($_GET['period']) ? (int)$_GET['period'] : 6;
if (!in_array($period, $allowedPeriods, true)) $period = 6;


$start = new DateTime('first day of this month');
$start->modify('-' . ($period - 1) . ' months');
$startDate = $start->format('Y-m-01');
$end = new DateTime('first day of next month');
$endDate = $end->format('Y-m-01');
$previousStart = clone $start;
$previousStart->modify('-' . $period . ' months');
$previousStartDate = $previousStart->format('Y-m-01');

$rangeStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income_total,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense_total,
        COUNT(*) AS transaction_count
     FROM transactions
     WHERE transaction_date >= ? AND transaction_date < ?"
);
$rangeStmt->execute([$startDate, $endDate]);
$current = $rangeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$income = (float)($current['income_total'] ?? 0);
$expenses = (float)($current['expense_total'] ?? 0);
$balance = $income - $expenses;
$transactionCount = (int)($current['transaction_count'] ?? 0);

$previousStmt = $pdo->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS income_total,
        COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS expense_total,
        COUNT(*) AS transaction_count
     FROM transactions
     WHERE transaction_date >= ? AND transaction_date < ?"
);
$previousStmt->execute([$previousStartDate, $startDate]);
$previous = $previousStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$previousIncome = (float)($previous['income_total'] ?? 0);
$previousExpenses = (float)($previous['expense_total'] ?? 0);
$incomeChange = signedPercent($income, $previousIncome);
$expenseChange = signedPercent($expenses, $previousExpenses);

$monthlyStmt = $pdo->prepare(
    "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month_key,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income_total,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense_total,
            COUNT(*) AS transaction_count
     FROM transactions
     WHERE transaction_date >= ? AND transaction_date < ?
     GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
     ORDER BY month_key ASC"
);
$monthlyStmt->execute([$startDate, $endDate]);
$monthlyRows = $monthlyStmt->fetchAll(PDO::FETCH_ASSOC);
$monthlyMap = [];
foreach ($monthlyRows as $row) {
    $monthlyMap[$row['month_key']] = [
        'income' => (float)$row['income_total'],
        'expenses' => (float)$row['expense_total'],
        'count' => (int)$row['transaction_count']
    ];
}
$monthLabels = [];
$incomeSeries = [];
$expenseSeries = [];
$netSeries = [];
$monthCursor = clone $start;
for ($i = 0; $i < $period; $i++) {
    $key = $monthCursor->format('Y-m');
    $monthLabels[] = $monthCursor->format('M Y');
    $monthIncome = $monthlyMap[$key]['income'] ?? 0;
    $monthExpense = $monthlyMap[$key]['expenses'] ?? 0;
    $incomeSeries[] = $monthIncome;
    $expenseSeries[] = $monthExpense;
    $netSeries[] = $monthIncome - $monthExpense;
    $monthCursor->modify('+1 month');
}

$categoryStmt = $pdo->prepare(
    "SELECT category, COALESCE(SUM(amount), 0) AS total, COUNT(*) AS transaction_count
     FROM transactions
     WHERE type = 'expense' AND transaction_date >= ? AND transaction_date < ?
     GROUP BY category ORDER BY total DESC"
);
$categoryStmt->execute([$startDate, $endDate]);
$categoryRows = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
$categoryLabels = array_column($categoryRows, 'category');
$categoryValues = array_map('floatval', array_column($categoryRows, 'total'));

$topStmt = $pdo->prepare(
    "SELECT description, category, amount, transaction_date
     FROM transactions
     WHERE type = 'expense' AND transaction_date >= ? AND transaction_date < ?
     ORDER BY amount DESC, transaction_date DESC LIMIT 5"
);
$topStmt->execute([$startDate, $endDate]);
$topExpenses = $topStmt->fetchAll(PDO::FETCH_ASSOC);

$averageExpense = $transactionCount > 0 ? $expenses / max(1, (int)$pdo->query("SELECT COUNT(*) FROM transactions WHERE type='expense' AND transaction_date >= " . $pdo->quote($startDate) . " AND transaction_date < " . $pdo->quote($endDate))->fetchColumn()) : 0;
$savingsRate = $income > 0 ? (($income - $expenses) / $income) * 100 : null;
$topCategory = $categoryRows[0] ?? null;
$topCategoryShare = ($topCategory && $expenses > 0) ? ((float)$topCategory['total'] / $expenses) * 100 : 0;
$monthCount = count($monthLabels);
$activeMonths = 0;
foreach ($incomeSeries as $i => $v) {
    if ($v > 0 || $expenseSeries[$i] > 0) $activeMonths++;
}

$insights = [];
if ($income == 0 && $expenses == 0) {
    $insights[] = ['icon' => '✦', 'title' => 'Your analytics are ready', 'text' => 'Add transactions or import a CSV statement to see personalized insights based on your activity.'];
} else {
    if ($income > 0) {
        if ($savingsRate >= 20) {
            $insights[] = ['icon' => '↗', 'title' => 'Healthy savings pace', 'text' => 'You kept ' . number_format($savingsRate, 1) . '% of recorded income after expenses in this period.'];
        } elseif ($savingsRate >= 0) {
            $insights[] = ['icon' => '◎', 'title' => 'Room to grow your savings', 'text' => 'Your current savings rate is ' . number_format($savingsRate, 1) . '%. Try setting a small monthly spending limit to build it up.'];
        } else {
            $insights[] = ['icon' => '!', 'title' => 'Expenses are above income', 'text' => 'Recorded expenses exceed income by ' . money(abs($balance)) . ' for this period. Review your largest categories first.'];
        }
    }
    if ($topCategory) {
        $insights[] = ['icon' => '◉', 'title' => e($topCategory['category']) . ' is your biggest category', 'text' => number_format($topCategoryShare, 1) . '% of your recorded expenses (' . money($topCategory['total']) . ') came from this category.'];
    }
    if ($incomeChange !== null && $previousIncome > 0) {
        $insights[] = ['icon' => '↕', 'title' => 'Income ' . ($incomeChange >= 0 ? 'increased' : 'decreased'), 'text' => 'Income is ' . number_format(abs($incomeChange), 1) . '% ' . ($incomeChange >= 0 ? 'higher' : 'lower') . ' than the previous ' . $period . ' months.'];
    }
    if ($expenseChange !== null && $previousExpenses > 0) {
        $insights[] = ['icon' => '⌁', 'title' => 'Spending ' . ($expenseChange <= 0 ? 'is down' : 'has risen'), 'text' => 'Expenses are ' . number_format(abs($expenseChange), 1) . '% ' . ($expenseChange <= 0 ? 'lower' : 'higher') . ' than the previous ' . $period . ' months.'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SmartBank | Analytics</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .analytics-intro { display:flex; align-items:flex-end; justify-content:space-between; gap:18px; margin: 4px 0 22px; }
        .analytics-intro h1 { margin-bottom:8px; }
        .period-switch { display:flex; gap:5px; padding:5px; background:#111120; border:1px solid var(--border); border-radius:11px; }
        .period-switch a { color:var(--muted); text-decoration:none; padding:9px 13px; border-radius:7px; font-size:12px; transition:all .2s ease; }
        .period-switch a:hover, .period-switch a.selected { background:#202038; color:var(--green); }
        .analytics-kpis { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:18px; }
        .analytics-kpi { min-width:0; padding:20px; }
        .analytics-kpi .kpi-label { color:var(--muted); font-size:10px; font-weight:750; letter-spacing:.8px; text-transform:uppercase; margin:0 0 12px; }
        .analytics-kpi h2 { font-size:clamp(19px,2vw,25px); margin:0 0 10px; overflow-wrap:anywhere; }
        .kpi-foot { font-size:11px; color:var(--muted); }
        .kpi-foot.good { color:var(--green); } .kpi-foot.bad { color:var(--red); }
        .analytics-charts { display:grid; grid-template-columns:minmax(0,1.7fr) minmax(280px,1fr); gap:16px; margin-bottom:18px; }
        .analytics-chart { min-width:0; }
        .analytics-chart .chart-box { height:310px; position:relative; }
        .analytics-chart .chart-box.short { height:270px; }
        .analytics-bottom { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(0,1fr); gap:16px; }
        .insight-list { display:grid; gap:11px; }
        .insight-item { display:flex; gap:13px; padding:14px; background:#0d0d1a; border:1px solid #222237; border-radius:12px; animation:fadeIn .5s ease both; }
        .insight-icon { display:grid; place-items:center; width:36px; height:36px; flex:0 0 36px; border-radius:10px; background:rgba(0,217,160,.11); color:var(--green); font-weight:800; }
        .insight-item h3 { margin:1px 0 6px; font-size:13px; }
        .insight-item p { margin:0; color:var(--muted); font-size:12px; line-height:1.6; }
        .category-progress { display:grid; gap:15px; }
        .category-progress-head { display:flex; justify-content:space-between; gap:12px; font-size:12px; margin-bottom:8px; }
        .category-progress-head span { color:#a5a6c0; }
        .category-progress-head strong { white-space:nowrap; }
        .progress-track { height:7px; border-radius:99px; overflow:hidden; background:#242439; }
        .progress-fill { height:100%; border-radius:99px; background:linear-gradient(90deg,var(--green),#39f2c1); transform-origin:left; animation:growBar .9s cubic-bezier(.2,.75,.25,1) both; }
        .top-expenses { width:100%; border-collapse:collapse; }
        .top-expenses td { padding:13px 7px; }
        .top-expenses td:last-child { text-align:right; color:var(--red); font-weight:750; }
        .empty-state { display:grid; place-content:center; min-height:180px; text-align:center; color:var(--muted); line-height:1.7; }
        .period-note { font-size:11px; color:var(--muted); margin:0; }
        @keyframes growBar { from { transform:scaleX(0); } to { transform:scaleX(1); } }
        @media(max-width:1100px) { .analytics-kpis { grid-template-columns:repeat(2,minmax(0,1fr)); } .analytics-charts,.analytics-bottom { grid-template-columns:1fr; } }
        @media(max-width:600px) { .analytics-intro { align-items:flex-start; flex-direction:column; } .analytics-kpis { grid-template-columns:1fr; } .period-switch { width:100%; } .period-switch a { flex:1; text-align:center; } .analytics-chart .chart-box { height:250px; } }
    </style>
</head>
<body>
<div class="layout">
    
<aside class="sidebar">
    <h2>Smart<span>Bank</span></h2>
    <p class="muted">PERSONAL FINANCE</p>

    <a href="index.php">▦ <span>Dashboard</span></a>
    <a href="categories.php">◉ <span>Categories</span></a>
    <a href="transactions.php">↕ <span>Transactions</span></a>
    <a class="active" href="analytics.php">▥ <span>Analytics</span></a>
    <a href="goals.php">◎ <span>Savings Goals</span></a>
    <a href="index.php#import-statement">⇧ <span>Import Statement</span></a>

    <p class="sidebar-note">
        Understand your money. Make smarter moves.
    </p>

    

<div class="sidebar-user">
    <div class="sidebar-user-info">
        <div class="sidebar-user-name">👤 SmartBank Test</div>
        <div class="sidebar-user-email">test@example.com</div>
    </div>

    <a class="logout-button" href="logout.php">
        Log out ↗
    </a>
</div>


</aside>


    <main class="main">
        <header class="topbar">
            <div><h1>Analytics <span class="accent-text">Overview</span> ✦</h1><p class="muted">Your money patterns, explained.</p></div>
            <span class="currency">Currency: MAD</span>
        </header>

        <section class="analytics-intro reveal">
            <div><h1>Financial performance</h1><p class="muted">Review your cash flow, spending habits, and progress.</p></div>
            <nav class="period-switch" aria-label="Analytics period">
                <a class="<?= $period === 3 ? 'selected' : '' ?>" href="?period=3">3M</a>
                <a class="<?= $period === 6 ? 'selected' : '' ?>" href="?period=6">6M</a>
                <a class="<?= $period === 12 ? 'selected' : '' ?>" href="?period=12">1Y</a>
            </nav>
        </section>

        <section class="analytics-kpis">
            <article class="stat-card analytics-kpi reveal"><p class="kpi-label">Income · <?= $period ?> months</p><h2 class="positive"><?= e(money($income)) ?></h2><div class="kpi-foot <?= $incomeChange !== null && $incomeChange >= 0 ? 'good' : 'bad' ?>"><?= e(pctText($incomeChange)) ?> vs previous period</div></article>
            <article class="stat-card analytics-kpi reveal"><p class="kpi-label">Expenses · <?= $period ?> months</p><h2 class="negative"><?= e(money($expenses)) ?></h2><div class="kpi-foot <?= $expenseChange !== null && $expenseChange <= 0 ? 'good' : 'bad' ?>"><?= e(pctText($expenseChange)) ?> vs previous period</div></article>
            <article class="stat-card analytics-kpi reveal"><p class="kpi-label">Net cash flow</p><h2 class="<?= $balance >= 0 ? 'positive' : 'negative' ?>"><?= e(money($balance)) ?></h2><div class="kpi-foot">Income minus expenses</div></article>
            <article class="stat-card analytics-kpi reveal"><p class="kpi-label">Savings rate</p><h2><?= $savingsRate === null ? '—' : e(number_format($savingsRate, 1) . '%') ?></h2><div class="kpi-foot"><?= $transactionCount ?> transactions recorded</div></article>
        </section>

        <section class="analytics-charts">
            <article class="panel analytics-chart reveal">
                <div class="panel-heading"><div><h2>Income vs. expenses</h2><p class="muted">Monthly cash flow for the selected period</p></div><span class="chart-badge"><?= $period ?> MONTHS</span></div>
                <div class="chart-box"><canvas id="cashFlowChart" aria-label="Monthly income and expenses chart"></canvas></div>
            </article>
            <article class="panel analytics-chart reveal">
                <div class="panel-heading"><div><h2>Spending breakdown</h2><p class="muted">Expenses grouped by category</p></div></div>
                <?php if ($categoryRows): ?><div class="chart-box short"><canvas id="categoryChart" aria-label="Expenses by category chart"></canvas></div><?php else: ?><div class="empty-state">◉<br>No expense categories yet.<br>Add or import expense transactions.</div><?php endif; ?>
            </article>
        </section>

        <section class="analytics-bottom">
            <article class="panel reveal">
                <div class="panel-heading"><div><h2>Spending insights</h2><p class="muted">Based on your recorded transactions</p></div></div>
                <div class="insight-list">
                    <?php foreach ($insights as $insight): ?>
                        <div class="insight-item"><div class="insight-icon"><?= e($insight['icon']) ?></div><div><h3><?= $insight['title'] ?></h3><p><?= e($insight['text']) ?></p></div></div>
                    <?php endforeach; ?>
                </div>
            </article>
            <article class="panel reveal">
                <div class="panel-heading"><div><h2>Category breakdown</h2><p class="muted">Share of total expenses</p></div></div>
                <?php if ($categoryRows): ?>
                    <div class="category-progress">
                        <?php foreach ($categoryRows as $index => $category): $share = $expenses > 0 ? ((float)$category['total'] / $expenses) * 100 : 0; $colors = ['#00d9a0','#579bff','#a987ff','#ffd166','#ff4f78','#48d6d0']; $color = $colors[$index % count($colors)]; ?>
                            <div><div class="category-progress-head"><span><?= e($category['category']) ?></span><strong><?= e(money($category['total'])) ?> · <?= number_format($share, 1) ?>%</strong></div><div class="progress-track"><div class="progress-fill" style="width:<?= min(100, max(0, $share)) ?>%;background:<?= e($color) ?>"></div></div></div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?><div class="empty-state">Your category breakdown will appear here<br>after you record some expenses.</div><?php endif; ?>
            </article>
        </section>

        <section class="panel reveal" style="margin-top:16px">
            <div class="panel-heading"><div><h2>Largest expenses</h2><p class="muted">Top five expense transactions in this period</p></div><a href="index.php#transactions" style="color:var(--green);text-decoration:none;font-size:12px">View transactions →</a></div>
            <?php if ($topExpenses): ?>
                <div class="table-wrap"><table class="top-expenses"><thead><tr><th>Description</th><th>Category</th><th>Date</th><th>Amount</th></tr></thead><tbody>
                <?php foreach ($topExpenses as $expense): ?><tr><td><?= e($expense['description']) ?></td><td><span class="tag"><?= e($expense['category']) ?></span></td><td><?= e($expense['transaction_date']) ?></td><td>−<?= e(money($expense['amount'])) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
            <?php else: ?><div class="empty-state">No expenses found in this period.</div><?php endif; ?>
        </section>
        <p class="period-note">Period: <?= e($startDate) ?> through <?= e(date('Y-m-d', strtotime($endDate . ' -1 day'))) ?>. Insights are calculated from transactions stored in your local SmartBank database.</p>
    </main>
</div>
<script>
(function () {
    const labels = <?= json_encode($monthLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const incomeData = <?= json_encode($incomeSeries, JSON_NUMERIC_CHECK) ?>;
    const expenseData = <?= json_encode($expenseSeries, JSON_NUMERIC_CHECK) ?>;
    const categoryLabels = <?= json_encode($categoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
    const categoryData = <?= json_encode($categoryValues, JSON_NUMERIC_CHECK) ?>;
    const gridColor = 'rgba(137, 139, 171, .12)';
    const tickColor = '#8586a3';
    const mad = value => new Intl.NumberFormat('en-US', { maximumFractionDigits: 0 }).format(value) + ' MAD';

    if (typeof Chart === 'undefined') {
        document.querySelectorAll('.chart-box').forEach(box => {
            box.innerHTML = '<div class="empty-state">Charts could not load. Check your internet connection and refresh.</div>';
        });
        return;
    }

    Chart.defaults.color = tickColor;
    Chart.defaults.font.family = 'Inter, Segoe UI, Arial, sans-serif';
    const cashCanvas = document.getElementById('cashFlowChart');
    if (cashCanvas) {
        const ctx = cashCanvas.getContext('2d');
        const greenFill = ctx.createLinearGradient(0, 0, 0, 300);
        greenFill.addColorStop(0, 'rgba(0,217,160,.27)');
        greenFill.addColorStop(1, 'rgba(0,217,160,0)');
        const redFill = ctx.createLinearGradient(0, 0, 0, 300);
        redFill.addColorStop(0, 'rgba(255,79,120,.16)');
        redFill.addColorStop(1, 'rgba(255,79,120,0)');
        new Chart(ctx, {
            type: 'line',
            data: { labels, datasets: [
                { label: 'Income', data: incomeData, borderColor: '#00d9a0', backgroundColor: greenFill, fill: true, tension: .38, borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#00d9a0' },
                { label: 'Expenses', data: expenseData, borderColor: '#ff4f78', backgroundColor: redFill, fill: true, tension: .38, borderWidth: 2.5, pointRadius: 3, pointHoverRadius: 6, pointBackgroundColor: '#ff4f78' }
            ]},
            options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false }, animation: { duration: 1100, easing: 'easeOutQuart' }, plugins: { legend: { position: 'top', align: 'end', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 16 } }, tooltip: { backgroundColor: '#19192b', borderColor: '#303049', borderWidth: 1, padding: 12, callbacks: { label: item => item.dataset.label + ': ' + mad(item.parsed.y) } } }, scales: { x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 0, autoSkip: true } }, y: { beginAtZero: true, grid: { color: gridColor }, border: { display: false }, ticks: { callback: value => value >= 1000 ? (value / 1000).toFixed(value % 1000 ? 1 : 0) + 'k' : value } } } }
        });
    }

    const categoryCanvas = document.getElementById('categoryChart');
    if (categoryCanvas && categoryData.length) {
        const colors = ['#579bff','#00d9a0','#a987ff','#ffd166','#ff4f78','#48d6d0','#ff9f68','#7f8cff'];
        new Chart(categoryCanvas, {
            type: 'doughnut',
            data: { labels: categoryLabels, datasets: [{ data: categoryData, backgroundColor: categoryLabels.map((_, i) => colors[i % colors.length]), borderColor: '#111120', borderWidth: 4, hoverOffset: 9, spacing: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '70%', animation: { animateRotate: true, animateScale: true, duration: 1000 }, plugins: { legend: { position: 'bottom', labels: { color: tickColor, usePointStyle: true, pointStyle: 'circle', padding: 15, boxWidth: 8 } }, tooltip: { backgroundColor: '#19192b', borderColor: '#303049', borderWidth: 1, padding: 12, callbacks: { label: item => item.label + ': ' + mad(item.parsed) } } } }
        });
    }

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('is-visible');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: .08 });
    document.querySelectorAll('.reveal').forEach(el => observer.observe(el));
})();
</script>
</body>
</html>
