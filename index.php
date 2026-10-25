
<?php
require_once __DIR__ . '/auth.php';
require_login();
$userId = current_user_id();

function detectCategory($description) {
    $text = strtolower($description);

    $rules = [
        'Salary' => ['salary', 'salaire', 'payroll'],
        'Groceries' => ['marjane', 'carrefour', 'grocery'],
        'Transport' => ['taxi', 'uber', 'fuel', 'gas station'],
        'Bills' => ['iam', 'orange', 'electricity', 'water'],
        'Subscriptions' => ['netflix', 'spotify'],
        'Rent' => ['rent', 'loyer'],
        'Shopping' => ['shopping', 'clothing', 'amazon'],
        'Transfers' => ['transfer', 'virement', 'transfert'],
        'Cash withdrawal' => ['atm', 'withdrawal', 'retrait'],
        'Restaurants' => ['restaurant', 'cafe', 'coffee']
    ];

    foreach ($rules as $category => $keywords) {
        foreach ($keywords as $keyword) {
            if (str_contains($text, $keyword)) {
                return $category;
            }
        }
    }

    return 'Other';
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    
if (!smartbank_verify_csrf()) {
    http_response_code(403);
    exit('Invalid request. Refresh the page and try again.');
}


    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $description = trim($_POST['description'] ?? '');
        $amountInput = $_POST['amount'] ?? '';
        $type = $_POST['type'] ?? '';
        $date = $_POST['transaction_date'] ?? '';

        $amount = filter_var(
            $amountInput,
            FILTER_VALIDATE_FLOAT
        );

        $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
        $validDate = $dateObject &&
            $dateObject->format('Y-m-d') === $date;

        if (
            $description === '' ||
            strlen($description) > 255 ||
            $amount === false ||
            !is_finite((float)$amount) ||
            $amount <= 0 ||
            $amount > 9999999999.99 ||
            !in_array($type, ['income', 'expense'], true) ||
            !$validDate
        ) {
            $error = 'Please enter valid transaction details.';
        } else {
            $category = detectCategory($description);

            $stmt = $pdo->prepare(
                "INSERT INTO transactions
                (description, amount, type, category, transaction_date, user_id)
                VALUES (?, ?, ?, ?, ?, ?)"
            );

            $stmt->execute([
                $description,
                $amount,
                $type,
                $category,
                $date,
                $userId
            ]);

            header('Location: index.php');
            exit;
        }
    }

    if ($action === 'delete') {
        $id = filter_var(
            $_POST['id'] ?? '',
            FILTER_VALIDATE_INT
        );

        if ($id !== false && $id > 0) {
            $stmt = $pdo->prepare(
                "DELETE FROM transactions WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$id, $userId]);
        }

        header('Location: index.php');
        exit;
    }
}


$income = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM transactions WHERE type = 'income' AND user_id = $userId"
)->fetchColumn();

$expenses = (float)$pdo->query(
    "SELECT COALESCE(SUM(amount), 0)
     FROM transactions WHERE type = 'expense' AND user_id = $userId"
)->fetchColumn();

$balance = $income - $expenses;

$transactions = $pdo->query(
    "SELECT * FROM transactions WHERE user_id = $userId ORDER BY transaction_date DESC, id DESC"
)->fetchAll();

$categoryTotals = $pdo->query(
    "SELECT category, SUM(amount) AS total
     FROM transactions
     WHERE type = 'expense' AND user_id = $userId GROUP BY category ORDER BY total DESC"
)->fetchAll();


$monthlyRows = $pdo->query(
    "SELECT DATE_FORMAT(transaction_date, '%Y-%m') AS month_key,
            SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) AS income_total,
            SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) AS expense_total
     FROM transactions
     WHERE user_id = $userId AND transaction_date >= DATE_FORMAT(DATE_SUB(CURDATE(), INTERVAL 5 MONTH), '%Y-%m-01')
       AND transaction_date < DATE_FORMAT(DATE_ADD(CURDATE(), INTERVAL 1 MONTH), '%Y-%m-01')
     GROUP BY DATE_FORMAT(transaction_date, '%Y-%m')
     ORDER BY month_key ASC"
)->fetchAll();

$monthlyMap = [];
foreach ($monthlyRows as $monthRow) {
    $monthlyMap[$monthRow['month_key']] = [
        'income' => (float)$monthRow['income_total'],
        'expense' => (float)$monthRow['expense_total']
    ];
}
$monthlyLabels = [];
$monthlyIncome = [];
$monthlyExpenses = [];
$monthCursor = new DateTime('first day of this month');
$monthCursor->modify('-5 months');
for ($i = 0; $i < 6; $i++) {
    $monthKey = $monthCursor->format('Y-m');
    $monthlyLabels[] = $monthCursor->format('M');
    $monthlyIncome[] = $monthlyMap[$monthKey]['income'] ?? 0;
    $monthlyExpenses[] = $monthlyMap[$monthKey]['expense'] ?? 0;
    $monthCursor->modify('+1 month');
}

$categoryLabels = array_column($categoryTotals, 'category');
$categoryValues = array_map('floatval', array_column($categoryTotals, 'total'));

function money($amount) {
    return number_format((float)$amount, 2) . ' MAD';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">
    <title>SmartBank | Finance Dashboard</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>

<div class="layout">
    <aside class="sidebar">
    <h2>Smart<span>Bank</span></h2><p class="muted">PERSONAL FINANCE</p>
    <a class="active" href="index.php">▦ <span>Dashboard</span></a>
    <a href="categories.php">◉ <span>Categories</span></a>
    <a href="transactions.php">↕ <span>Transactions</span></a>
    <a href="analytics.php">▥ <span>Analytics</span></a>
    <a href="goals.php">◎ <span>Savings Goals</span></a>
    <a href="index.php#import-statement">⇧ <span>Import Statement</span></a>
    <p class="sidebar-note">Manage your money smarter.</p>
    <div class="sidebar-account" style="margin-top:18px;padding:12px;border:1px solid var(--border);border-radius:12px"><div style="font-size:12px;font-weight:700;overflow-wrap:anywhere">👤 <?= e($_SESSION['user_name'] ?? 'Account') ?></div><div style="font-size:11px;color:var(--muted);margin:4px 0 10px;overflow-wrap:anywhere"><?= e($_SESSION['user_email'] ?? '') ?></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><button type="submit" style="width:100%;padding:9px;border-radius:8px;border:1px solid var(--border);background:#202037;color:var(--text);cursor:pointer">Log out ↗</button></form></div>
</aside>

    <main class="main" id="dashboard">
        <header class="topbar">
            <div>
                <h1>Good morning, <span class="accent-text">SmartBank</span> 👋</h1>
                <p class="muted">Your money, organized.</p>
            </div>
            <span class="currency">Currency: MAD</span>
        </header>

        <?php if ($error !== ''): ?>
            <p class="error"><?= e($error) ?></p>
        <?php endif; ?>

<!-- SmartBank Virtual Card -->
<section class="virtual-card-section">
    <div class="virtual-card">
        <div class="virtual-card-top">
            <div class="virtual-card-brand">
                <span class="virtual-card-mark">S</span>
                <span>SMARTBANK</span>
            </div>
            <span class="virtual-card-type">DEBIT</span>
        </div>

        <div class="virtual-card-chip">
            <span></span>
        </div>

        <div class="virtual-card-label">AVAILABLE BALANCE</div>

        <div class="virtual-card-balance">
            <?= e(number_format($balance, 2)) ?>
            <span>MAD</span>
        </div>

        <div class="virtual-card-number">
            •••• &nbsp; •••• &nbsp; •••• &nbsp; 4821
        </div>

        <div class="virtual-card-bottom">
            <div>
                <span class="virtual-card-caption">CARDHOLDER</span>
                <strong><?= e($_SESSION['user_name'] ?? 'SMARTBANK USER') ?></strong>
            </div>
            <div class="virtual-card-network">SB</div>
        </div>
    </div>

    <div class="virtual-card-caption-text">
        <span class="virtual-card-status"></span>
        Your personal finance overview
    </div>
</section>

        
            <article class="stat-card stat-income reveal">
                <div class="stat-icon">↗</div>
                <p>Total income</p>
                <h2 class="positive count-up" data-value="<?= e(number_format($income, 2, '.', '')) ?>">0.00 MAD</h2>
                <span class="stat-caption">Money coming in</span>
            </article>
            <article class="stat-card stat-expense reveal">
                <div class="stat-icon">↘</div>
                <p>Total expenses</p>
                <h2 class="negative count-up" data-value="<?= e(number_format($expenses, 2, '.', '')) ?>">0.00 MAD</h2>
                <span class="stat-caption">Money going out</span>
            </article>
            <article class="stat-card stat-balance reveal">
                <div class="stat-icon">◈</div>
                <p>Net balance change</p>
<h2 class="<?= $balance >= 0 ? 'positive' : 'negative' ?> count-up" data-value="<?= e(number_format($balance, 2, '.', '')) ?>">0.00 MAD</h2>
<span class="stat-caption">Income minus expenses</span>
            </article>
        </section>

        <section class="chart-grid">
            <article class="panel chart-panel reveal">
                <div class="panel-heading">
                    <div><h2>Cash flow</h2><p class="muted">Income vs. expenses · last 6 months</p></div>
                    <span class="chart-badge">MAD</span>
                </div>
                <div class="chart-container"><canvas id="cashFlowChart" aria-label="Income and expenses by month" role="img"></canvas></div>
                <p class="chart-empty muted" id="cashFlowEmpty" hidden>No transaction data for this period yet.</p>
            </article>
            <article class="panel chart-panel reveal" id="categories">
                <div class="panel-heading">
                    <div><h2>Spending breakdown</h2><p class="muted">Where your money goes</p></div>
                </div>
                <?php if ($categoryTotals): ?>
                    <div class="donut-wrap"><canvas id="categoryChart" aria-label="Expenses by category" role="img"></canvas></div>
                    <div class="chart-legend" id="categoryLegend"></div>
                <?php else: ?>
                    <div class="empty-chart"><span>◌</span><p>No expenses recorded yet.</p></div>
                <?php endif; ?>
            </article>
        </section>

        <section class="panel" id="add-transaction">
            <h2>Add a transaction</h2>

            <form method="POST" class="transaction-form">
                <input type="hidden" name="csrf_token"
                       value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add">

                <label>
                    Description
                    <input name="description" maxlength="255"
                           placeholder="e.g. Marjane Market"
                           required>
                </label>

                <label>
                    Amount (MAD)
                    <input type="number" name="amount"
                           min="0.01" max="9999999999.99"
                           step="0.01" placeholder="250.00"
                           required>
                </label>

                <label>
                    Transaction type
                    <select name="type" required>
                        <option value="expense">Expense</option>
                        <option value="income">Income</option>
                    </select>
                </label>

                <label>
                    Date
                    <input type="date" name="transaction_date"
                           value="<?= e(date('Y-m-d')) ?>" required>
                </label>

                <button type="submit">Add transaction</button>
            </form>
            <p class="muted small">
                Categories are suggested automatically from descriptions.
            </p>
        </section>

<section class="panel" id="import-statement"><?php if (!empty($_SESSION['import_message'])): ?><p class="flash-success"><?= e($_SESSION['import_message']) ?></p><?php unset($_SESSION['import_message']); endif; ?><?php if (!empty($_SESSION['import_error'])): ?><p class="flash-error"><?= e($_SESSION['import_error']) ?></p><?php unset($_SESSION['import_error']); endif; ?>
    <h2>Import bank statement</h2>

    <p class="muted">
        Upload a CSV file to organize your transactions automatically.
    </p>

    <form action="import.php" method="POST"
          enctype="multipart/form-data">
        <input type="hidden" name="csrf_token"
               value="<?= e($_SESSION['csrf_token']) ?>">

        <label>
            Choose your CSV statement
            <input type="file" name="statement"
                   accept=".csv,text/csv" required>
        </label>

        <p class="muted small">
            Required columns: date, description, amount, type.
            Maximum file size: 2 MB.
        </p>

        <button type="submit">Import statement</button>
    </form>
</section>
        <section class="panel" id="transactions">
            <h2>Recent transactions</h2>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($transactions as $t): ?>
                        <tr>
                            <td><?= e($t['transaction_date']) ?></td>
                            <td><?= e($t['description']) ?></td>
                            <td><span class="tag">
                                <?= e($t['category']) ?>
                            </span></td>
                            <td><?= e(ucfirst($t['type'])) ?></td>
                            <td class="<?= $t['type'] === 'income'
                                ? 'positive' : 'negative' ?>">
                                <?= $t['type'] === 'income' ? '+' : '-' ?>
                                <?= e(money($t['amount'])) ?>
                            </td>
                            <td>
                                <form method="POST"
                                      onsubmit="return confirm('Delete this transaction?');">
                                    <input type="hidden" name="csrf_token"
                                           value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action"
                                           value="delete">
                                    <input type="hidden" name="id"
                                           value="<?= (int)$t['id'] ?>">
                                    <button class="delete-btn"
                                            type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$transactions): ?>
                        <tr><td colspan="6">
                            No transactions yet. Add your first one above.
                        </td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>


<script>
(() => {
    const money = value => Number(value).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' MAD';

    // Count-up animation for the summary cards.
    document.querySelectorAll('.count-up').forEach(element => {
        const target = Number(element.dataset.value || 0);
        const duration = 1100;
        const start = performance.now();
        const animate = now => {
            const progress = Math.min((now - start) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3);
            const current = target * eased;
            element.textContent = money(current);
            if (progress < 1) requestAnimationFrame(animate);
        };
        requestAnimationFrame(animate);
    });

    // Reveal panels as they enter the viewport.
    const revealItems = document.querySelectorAll('.reveal');
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(entries => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12 });
        revealItems.forEach(item => observer.observe(item));
    } else {
        revealItems.forEach(item => item.classList.add('is-visible'));
    }

    if (typeof Chart === 'undefined') return;

    const labels = <?= json_encode($monthlyLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const incomeData = <?= json_encode($monthlyIncome, JSON_NUMERIC_CHECK) ?>;
    const expenseData = <?= json_encode($monthlyExpenses, JSON_NUMERIC_CHECK) ?>;
    const categoryLabels = <?= json_encode($categoryLabels, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    const categoryValues = <?= json_encode($categoryValues, JSON_NUMERIC_CHECK) ?>;

    const cashCanvas = document.getElementById('cashFlowChart');
    if (cashCanvas && labels.length) {
        new Chart(cashCanvas, {
            type: 'line',
            data: {
                labels,
                datasets: [
                    { label: 'Income', data: incomeData, borderColor: '#22c58b', backgroundColor: 'rgba(34,197,139,.12)', fill: true, tension: .4, borderWidth: 3, pointRadius: 3, pointHoverRadius: 6 },
                    { label: 'Expenses', data: expenseData, borderColor: '#6480ff', backgroundColor: 'rgba(100,128,255,.08)', fill: true, tension: .4, borderWidth: 3, pointRadius: 3, pointHoverRadius: 6 }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: { duration: 1400, easing: 'easeOutQuart' },
                interaction: { intersect: false, mode: 'index' },
                plugins: { legend: { position: 'top', align: 'end', labels: { color: '#9a9bb6', usePointStyle: true, pointStyle: 'circle', boxWidth: 7, padding: 14 } }, tooltip: { callbacks: { label: context => context.dataset.label + ': ' + money(context.parsed.y) } } },
                scales: {
                    x: { grid: { display: false }, border: { display: false }, ticks: { color: '#686984' } },
                    y: { beginAtZero: true, grid: { color: 'rgba(130,145,175,.12)' }, border: { display: false }, ticks: { color: '#686984', callback: value => Number(value).toLocaleString() } }
                }
            }
        });
    } else {
        const empty = document.getElementById('cashFlowEmpty');
        if (empty) empty.hidden = false;
    }

    const categoryCanvas = document.getElementById('categoryChart');
    if (categoryCanvas && categoryLabels.length) {
        const palette = ['#5575f7', '#27c994', '#ffb454', '#a78bfa', '#f472b6', '#38bdf8', '#fb7185', '#94a3b8'];
        new Chart(categoryCanvas, {
            type: 'doughnut',
            data: { labels: categoryLabels, datasets: [{ data: categoryValues, backgroundColor: palette, borderColor: '#11111f', borderWidth: 4, hoverOffset: 10 }] },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '72%',
                animation: { animateRotate: true, animateScale: true, duration: 1300, easing: 'easeOutQuart' },
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: context => context.label + ': ' + money(context.parsed) } } }
            }
        });
        const legend = document.getElementById('categoryLegend');
        categoryLabels.forEach((label, index) => {
            const row = document.createElement('div');
            row.className = 'legend-row';
            const name = document.createElement('span');
            name.className = 'legend-name';
            const dot = document.createElement('i');
            dot.style.backgroundColor = palette[index % palette.length];
            const text = document.createElement('span');
            text.textContent = label;
            name.append(dot, text);
            const value = document.createElement('strong');
            value.textContent = money(categoryValues[index]);
            row.append(name, value);
            legend.appendChild(row);
        });
    }
})();
</script>

</body>
</html>