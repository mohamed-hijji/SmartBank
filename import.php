
<?php
require_once __DIR__ . '/auth.php';

require_login();
$userId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#import-statement');
    exit;
}

if (!smartbank_verify_csrf()) {
    http_response_code(403);
    exit('Invalid request token. Refresh and try again.');
}

function import_date($value): ?string
{
    $value = trim((string) $value);

    foreach (['!Y-m-d', '!d/m/Y', '!m/d/Y', '!d-m-Y'] as $format) {
        $date = DateTime::createFromFormat($format, $value);
        $errors = DateTime::getLastErrors();

        if (
            $date &&
            $date->format(ltrim($format, '!')) === $value &&
            (
                $errors === false ||
                (
                    $errors['warning_count'] === 0 &&
                    $errors['error_count'] === 0
                )
            )
        ) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function import_category(string $description): string
{
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
        foreach ($keywords as $word) {
            if (str_contains($text, $word)) {
                return $category;
            }
        }
    }

    return 'Other';
}

$error = '';
$imported = 0;
$skipped = 0;

if (
    !isset($_FILES['statement']) ||
    !is_array($_FILES['statement']) ||
    $_FILES['statement']['error'] !== UPLOAD_ERR_OK
) {
    $error = 'Choose a valid CSV file and try again.';
} elseif (
    !isset($_FILES['statement']['size']) ||
    $_FILES['statement']['size'] > 5 * 1024 * 1024
) {
    $error = 'The file must be smaller than 5 MB.';
} elseif (
    !isset($_FILES['statement']['name'], $_FILES['statement']['tmp_name']) ||
    strtolower(pathinfo($_FILES['statement']['name'], PATHINFO_EXTENSION)) !== 'csv'
) {
    $error = 'Please upload a .csv file.';
} elseif (
    !is_uploaded_file($_FILES['statement']['tmp_name'])
) {
    $error = 'Invalid uploaded file.';
} else {
    $handle = fopen($_FILES['statement']['tmp_name'], 'rb');

    if ($handle === false) {
        $error = 'Could not read the uploaded file.';
    } else {
        $first = fgetcsv($handle);
        $header = [];

        if ($first === false || $first === [null]) {
            fclose($handle);
            $error = 'The CSV file is empty.';
        } else {
            // Remove a UTF-8 BOM from the beginning of each header field.
            $normalized = array_map(
                fn($value) => strtolower(
                    trim(
                        preg_replace(
                            '/^\xEF\xBB\xBF/',
                            '',
                            (string) $value
                        )
                    )
                ),
                $first
            );

            $hasHeader = (bool) array_intersect(
                $normalized,
                ['description', 'amount', 'type', 'category', 'transaction_date', 'date']
            );

            if ($hasHeader) {
                foreach ($normalized as $index => $key) {
                    if ($key !== '') {
                        $header[$key] = $index;
                    }
                }

                // A description and amount column are required.
                if (
                    !isset($header['description']) ||
                    !isset($header['amount'])
                ) {
                    fclose($handle);
                    $error = 'CSV must contain description and amount columns.';
                }
            } else {
                // Headerless CSV format:
                // description, amount, type, category, transaction_date
                $header = [
                    'description' => 0,
                    'amount' => 1,
                    'type' => 2,
                    'category' => 3,
                    'transaction_date' => 4
                ];

                rewind($handle);
            }

            if ($error === '') {
                try {
                    $pdo->beginTransaction();

                    $insert = $pdo->prepare(
                        'INSERT INTO transactions
                        (description, amount, type, category, transaction_date, user_id)
                        VALUES (?, ?, ?, ?, ?, ?)'
                    );

                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) < 2) {
                            $skipped++;
                            continue;
                        }

                        $get = function ($key, $fallback = null) use ($row, $header) {
                            return isset($header[$key], $row[$header[$key]])
                                ? trim((string) $row[$header[$key]])
                                : $fallback;
                        };

                        $description = trim((string) $get('description', ''));

                        $amountRaw = str_replace(
                            [',', ' ', '$', 'MAD', 'mad'],
                            '',
                            (string) $get('amount', '')
                        );

                        $amount = filter_var(
                            $amountRaw,
                            FILTER_VALIDATE_FLOAT
                        );

                        $type = strtolower(
                            (string) $get('type', 'expense')
                        );

                        if (
                            in_array(
                                $type,
                                ['debit', 'withdrawal', 'payment', 'expense'],
                                true
                            )
                        ) {
                            $type = 'expense';
                        } elseif (
                            in_array(
                                $type,
                                ['credit', 'deposit', 'income'],
                                true
                            )
                        ) {
                            $type = 'income';
                        }

                        $date = import_date(
                            $get('transaction_date', $get('date', ''))
                        );

                        $category = trim(
                            (string) $get('category', '')
                        );

                        if (
                            $description === '' ||
                            strlen($description) > 255 ||
                            $amount === false ||
                            !is_finite((float) $amount) ||
                            $amount <= 0 ||
                            !in_array($type, ['income', 'expense'], true) ||
                            $date === null
                        ) {
                            $skipped++;
                            continue;
                        }

                        if (
                            $category === '' ||
                            strlen($category) > 100
                        ) {
                            $category = import_category($description);
                        }

                        // Always assign imported transactions to the logged-in user.
                        $insert->execute([
                            $description,
                            $amount,
                            $type,
                            $category,
                            $date,
                            $userId
                        ]);

                        $imported++;
                    }

                    fclose($handle);
                    $handle = false;

                    $pdo->commit();

                } catch (Throwable $exception) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'SmartBank CSV import failed: ' .
                        $exception->getMessage()
                    );

                    $imported = 0;
                    $error = 'The import failed. Please check your CSV file and try again.';
                }
            }
        }
    }
}

if ($error !== '') {
    $_SESSION['import_error'] = $error;
} else {
    $_SESSION['import_message'] =
        "Imported $imported transaction(s); skipped $skipped invalid row(s).";
}

header('Location: index.php#import-statement');
exit;