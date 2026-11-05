<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_login(['admin']);

$transferTables = [
    'users', 'clinic_settings', 'patients', 'medical_aid_types', 'payment_methods',
    'inventory_items', 'visits', 'triage', 'consultations', 'prescriptions', 'referrals',
    'appointments', 'invoices', 'inventory_movements', 'audit_log', 'enquiries',
];
$csvTables = [
    'patients', 'visits', 'triage', 'consultations', 'prescriptions', 'referrals',
    'appointments', 'invoices', 'inventory_items',
];
$errors = [];
$pdo = db();
$columnsByTable = [];
$nullableByTable = [];
$maxLengthByTable = [];
foreach ($transferTables as $table) {
    $description = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll();
    $columnsByTable[$table] = array_column($description, 'Field');
    $nullableByTable[$table] = [];
    $maxLengthByTable[$table] = [];
    foreach ($description as $column) {
        $nullableByTable[$table][$column['Field']] = $column['Null'] === 'YES';
        if (preg_match('/^(?:var)?char\((\d+)\)/i', $column['Type'], $matches)) {
            $maxLengthByTable[$table][$column['Field']] = (int)$matches[1];
        }
    }
}

$validateImportLength = static function (string $table, string $column, mixed $value) use ($maxLengthByTable): void {
    $maxLength = $maxLengthByTable[$table][$column] ?? null;
    if ($value !== null && $maxLength !== null && mb_strlen((string)$value, 'UTF-8') > $maxLength) {
        throw new RuntimeException('Import value exceeds the ' . $column . ' field limit for ' . $table . '.');
    }
};

$spreadsheetCell = static function (mixed $value): string {
    $cell = $value === null ? '' : (is_scalar($value) ? (string)$value : '');
    return preg_match('/^\s*[=+\-@]/u', $cell) ? "'" . $cell : $cell;
};

if (isset($_GET['download_backup'])) {
    audit('data_backup_exported', 'JSON system backup');
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="good-samaritan-backup-' . date('Y-m-d-His') . '.json"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;
    echo '{"format":' . json_encode('good-samaritan-clinic-backup', $jsonFlags);
    echo ',"version":1,"exported_at":' . json_encode(date(DATE_ATOM), $jsonFlags) . ',"tables":{';
    foreach ($transferTables as $index => $table) {
        if ($index > 0) {
            echo ',';
        }
        echo json_encode($table, $jsonFlags) . ':[';
        $rows = $pdo->query("SELECT * FROM `$table`");
        $firstRow = true;
        while ($row = $rows->fetch()) {
            if (!$firstRow) {
                echo ',';
            }
            echo json_encode($row, $jsonFlags);
            $firstRow = false;
        }
        echo ']';
    }
    echo '}}';
    exit;
}

if (isset($_GET['download_csv'])) {
    $table = (string)$_GET['download_csv'];
    if (!in_array($table, $transferTables, true) || $table === 'users') {
        http_response_code(400);
        exit('Choose an available data table.');
    }
    $columns = $columnsByTable[$table];
    audit('data_table_exported', $table . ' CSV');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $table . '-' . date('Y-m-d-His') . '.csv"');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open the CSV download stream.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, $columns, ',', '"', '\\');
    $rows = $pdo->query("SELECT * FROM `$table`");
    while ($row = $rows->fetch()) {
        fputcsv($output, array_map($spreadsheetCell, array_map(static fn(string $column): mixed => $row[$column], $columns)), ',', '"', '\\');
    }
    fclose($output);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');
    $file = $_FILES['import_file'] ?? null;
    if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || (int)$file['size'] > 20 * 1024 * 1024) {
        $errors[] = 'Choose a valid import file up to 20 MB.';
    } elseif ($action === 'import_json') {
        $contents = file_get_contents($file['tmp_name']);
        if ($contents === false) {
            $backup = null;
            $errors[] = 'The JSON file could not be read.';
        } else {
            try {
                $backup = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $backup = null;
                $errors[] = 'The JSON file is not valid.';
            }
        }
        if (!$errors && (!is_array($backup) || ($backup['format'] ?? '') !== 'good-samaritan-clinic-backup' ||
            ($backup['version'] ?? null) !== 1 || !is_array($backup['tables'] ?? null))) {
            $errors[] = 'This is not a supported clinic JSON backup.';
        }
        if (!$errors) {
            $unknownTables = array_diff(array_keys($backup['tables']), $transferTables);
            if ($unknownTables) {
                $errors[] = 'The backup contains unsupported table names.';
            } else {
                $pdo->beginTransaction();
                $inserted = 0;
                $userIdMap = [];
                $sourceUserEmails = [];
                $userReferenceColumns = ['created_by', 'nurse_id', 'doctor_id', 'registered_by', 'recorded_by', 'dispensed_by', 'cashier_id', 'user_id', 'updated_by'];
                try {
                    foreach ($transferTables as $table) {
                        $rows = $backup['tables'][$table] ?? [];
                        if (!is_array($rows)) {
                            throw new RuntimeException('Backup table data is malformed: ' . $table);
                        }
                        foreach ($rows as $row) {
                            if (!is_array($row) || array_is_list($row)) {
                                throw new RuntimeException('Backup record is malformed: ' . $table);
                            }
                            if (array_diff(array_keys($row), $columnsByTable[$table])) {
                                throw new RuntimeException('Backup contains unsupported fields: ' . $table);
                            }
                            foreach ($row as $value) {
                                if (is_array($value) || is_object($value) || is_resource($value)) {
                                    throw new RuntimeException('Backup contains an invalid field value.');
                                }
                            }
                            foreach ($row as $column => $value) {
                                $validateImportLength($table, $column, $value);
                            }
                            $sourceUserId = null;
                            if ($table === 'users') {
                                $sourceUserId = (int)($row['id'] ?? 0);
                                if ($sourceUserId < 1 || !filter_var($row['email'] ?? '', FILTER_VALIDATE_EMAIL) ||
                                    trim((string)($row['full_name'] ?? '')) === '' ||
                                    !isset(ROLE_LABELS[$row['role'] ?? '']) ||
                                    !is_string($row['password_hash'] ?? null) ||
                                    password_get_info($row['password_hash'])['algo'] === null) {
                                    throw new RuntimeException('Backup contains an invalid staff account.');
                                }
                                $emailKey = strtolower($row['email']);
                                if (isset($userIdMap[$sourceUserId]) || isset($sourceUserEmails[$emailKey])) {
                                    throw new RuntimeException('Backup contains duplicate staff identities.');
                                }
                                $sourceUserEmails[$emailKey] = true;
                                $existing = $pdo->prepare('SELECT id FROM users WHERE email=?');
                                $existing->execute([$row['email']]);
                                $existingId = $existing->fetchColumn();
                                if ($existingId !== false) {
                                    $userIdMap[$sourceUserId] = (int)$existingId;
                                    continue;
                                }
                                $idCollision = $pdo->prepare('SELECT id FROM users WHERE id=?');
                                $idCollision->execute([$sourceUserId]);
                                if ($idCollision->fetchColumn() !== false) {
                                    unset($row['id']);
                                }
                            } elseif ($table === 'medical_aid_types') {
                                $existing = $pdo->prepare('SELECT id FROM medical_aid_types WHERE name=?');
                                $existing->execute([$row['name'] ?? '']);
                                $existingId = $existing->fetchColumn();
                                if ($existingId !== false) {
                                    continue;
                                }
                            } elseif ($table === 'payment_methods') {
                                $existing = $pdo->prepare('SELECT id, method_key, label FROM payment_methods WHERE method_key=? OR label=?');
                                $existing->execute([$row['method_key'] ?? '', $row['label'] ?? '']);
                                $existingMethod = $existing->fetch();
                                if ($existingMethod) {
                                    if ($existingMethod['method_key'] !== ($row['method_key'] ?? null) ||
                                        $existingMethod['label'] !== ($row['label'] ?? null)) {
                                        throw new RuntimeException('A payment method conflicts with an existing key or label.');
                                    }
                                    continue;
                                }
                            }
                            if ($table === 'clinic_settings') {
                                $existingSettings = $pdo->query('SELECT id FROM clinic_settings WHERE id=1')->fetchColumn();
                                if ($existingSettings !== false) {
                                    continue;
                                }
                            }
                            foreach ($row as $column => $value) {
                                if (in_array($column, $userReferenceColumns, true) && $value !== null && isset($userIdMap[(int)$value])) {
                                    $row[$column] = $userIdMap[(int)$value];
                                }
                            }
                            $rowColumns = array_keys($row);
                            if (!$rowColumns) {
                                continue;
                            }
                            $quotedColumns = array_map(static fn(string $column): string => '`' . $column . '`', $rowColumns);
                            $placeholders = implode(',', array_fill(0, count($rowColumns), '?'));
                            $insert = $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $quotedColumns) . ') VALUES (' . $placeholders . ')');
                            $insert->execute(array_values($row));
                            if ($table === 'users' && $sourceUserId !== null) {
                                $userIdMap[$sourceUserId] = isset($row['id']) ? (int)$row['id'] : (int)$pdo->lastInsertId();
                            }
                            $inserted++;
                            if ($inserted > 50000) {
                                throw new RuntimeException('Import is limited to 50,000 records at a time.');
                            }
                        }
                    }
                    $pdo->commit();
                    audit('data_backup_imported', $inserted . ' records');
                    flash('success', $inserted . ' records imported. Existing records were not overwritten.');
                    redirect('/staff/data_transfer.php');
                } catch (Throwable $ex) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    if ($ex instanceof RuntimeException) {
                        $errors[] = $ex->getMessage();
                    } else {
                        error_log('JSON backup import failed: ' . $ex->getMessage());
                        $errors[] = 'Import stopped because a record conflicts with existing data or violates a relationship. No imported records were kept.';
                    }
                }
            }
        }
    } elseif ($action === 'import_csv') {
        $table = post('table');
        if (!in_array($table, $csvTables, true)) {
            $errors[] = 'Choose a supported CSV record type.';
        } else {
            $handle = fopen($file['tmp_name'], 'rb');
            if ($handle === false) {
                $errors[] = 'The CSV file could not be read.';
            } else {
                $header = fgetcsv($handle, 0, ',', '"', '\\');
                if ($header) {
                    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
                }
                $columns = $columnsByTable[$table];
                if (!$header || count(array_unique($header)) !== count($header) ||
                    array_diff($header, $columns) || array_diff($header, ['id', ...$columns])) {
                    $errors[] = 'CSV headers must be unique column names for the selected record type.';
                } else {
                    $pdo->beginTransaction();
                    $inserted = 0;
                    try {
                        while (($values = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
                            if (count($values) !== count($header)) {
                                throw new RuntimeException('CSV rows must have the same number of fields as the header.');
                            }
                            $row = array_combine($header, $values);
                            $rowColumns = [];
                            $rowValues = [];
                            foreach ($header as $column) {
                                $value = $row[$column];
                                if ($value === '' && $nullableByTable[$table][$column]) {
                                    $value = null;
                                } elseif (is_string($value) && preg_match("/^'[=+\\-@]/", $value)) {
                                    $value = substr($value, 1);
                                }
                                $validateImportLength($table, $column, $value);
                                $rowColumns[] = '`' . $column . '`';
                                $rowValues[] = $value;
                            }
                            $placeholders = implode(',', array_fill(0, count($rowColumns), '?'));
                            $insert = $pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $rowColumns) . ') VALUES (' . $placeholders . ')');
                            $insert->execute($rowValues);
                            $inserted++;
                            if ($inserted > 20000) {
                                throw new RuntimeException('CSV imports are limited to 20,000 rows at a time.');
                            }
                        }
                        $pdo->commit();
                        fclose($handle);
                        audit('data_csv_imported', $table . ': ' . $inserted . ' records');
                        flash('success', $inserted . ' ' . $table . ' records imported.');
                        redirect('/staff/data_transfer.php');
                    } catch (Throwable $ex) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        fclose($handle);
                        if ($ex instanceof RuntimeException) {
                            $errors[] = $ex->getMessage();
                        } else {
                            error_log('CSV import failed for ' . $table . ': ' . $ex->getMessage());
                            $errors[] = 'Import stopped because a record conflicts with existing data or violates a relationship. No imported rows were kept.';
                        }
                    }
                }
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }
    } else {
        $errors[] = 'Choose a valid import action.';
    }
}

staff_header('Data backup', 'settings.php');
?>
<div class="page-head"><div><p><a href="<?= e(app_url('/staff/settings.php')) ?>">&larr; Settings</a></p><h1>Data backup &amp; transfer</h1><p>Back up clinic records or exchange records with a spreadsheet or another system.</p></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

<section class="panel transfer-backup">
  <h2>Full system backup</h2>
  <p>Download the complete clinic database as a JSON backup, including staff account password hashes and confidential patient data.</p>
  <a class="btn" href="<?= e(app_url('/staff/data_transfer.php?download_backup=1')) ?>">Download full JSON backup</a>
  <p class="transfer-warning"><strong>Protect this file.</strong> Store it offline in a secure location. It contains sensitive health information and password hashes. Do not send it by email or upload it to an untrusted service.</p>
  <form method="post" action="<?= e(app_url('/staff/data_transfer.php')) ?>" enctype="multipart/form-data" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_json">
    <div><label for="json-backup">Import JSON backup</label><input id="json-backup" type="file" name="import_file" accept=".json,application/json" required></div>
    <div class="full"><p>Imports add records only; conflicts stop the import and roll back all changes. Existing staff accounts and clinic print settings are preserved.</p></div>
    <div><button class="btn btn-ghost" type="submit">Import JSON backup</button></div>
  </form>
</section>

<section class="panel">
  <h2>Spreadsheet-compatible CSV</h2>
  <p>CSV files open in Excel and other spreadsheet applications. Export or import one record type at a time. Staff accounts are excluded from CSV to protect password hashes; use the protected JSON backup for system recovery. Import related records only after their referenced patient, visit, or inventory item exists.</p>
  <div class="transfer-export-list"><?php foreach ($transferTables as $table): if ($table === 'users') { continue; } ?>
    <a class="btn btn-sm btn-ghost" href="<?= e(app_url('/staff/data_transfer.php?download_csv=' . rawurlencode($table))) ?>">Export <?= e(str_replace('_', ' ', ucfirst($table))) ?> CSV</a>
  <?php endforeach; ?></div>
  <form method="post" action="<?= e(app_url('/staff/data_transfer.php')) ?>" enctype="multipart/form-data" class="form-grid transfer-import-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="import_csv">
    <div><label for="csv-table">Record type</label><select id="csv-table" name="table" required><?php foreach ($csvTables as $table): ?><option value="<?= e($table) ?>"><?= e(str_replace('_', ' ', ucfirst($table))) ?></option><?php endforeach; ?></select></div>
    <div><label for="csv-file">CSV file</label><input id="csv-file" type="file" name="import_file" accept=".csv,text/csv" required></div>
    <div class="full"><p>Imports insert new records only. Duplicate identifiers and invalid references are rejected; an unsuccessful import leaves the database unchanged.</p></div>
    <div><button class="btn btn-dark" type="submit">Import CSV records</button></div>
  </form>
</section>
<?php staff_footer();
