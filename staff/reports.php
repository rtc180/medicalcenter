<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_login(['admin']);

// Resolve a named period or validate the administrator's custom date range.
$period = (string)($_GET['period'] ?? 'month');
$today = new DateTimeImmutable('today');
$periodOptions = [
    'week' => 'Last 7 days',
    '30days' => 'Last 30 days',
    '90days' => 'Last 90 days',
    'month' => 'This month',
    'custom' => 'Custom range',
];
$periodError = '';

if ($period === 'custom') {
    $fromInput = trim((string)($_GET['from'] ?? ''));
    $toInput = trim((string)($_GET['to'] ?? ''));
    if (!valid_date($fromInput) || !valid_date($toInput) || $fromInput > $toInput) {
        $periodError = 'Choose a valid start and end date; the start must not be after the end.';
        $period = 'month';
        $startDate = $today->modify('first day of this month');
        $endDate = $today;
        $fromInput = $startDate->format('Y-m-d');
        $toInput = $endDate->format('Y-m-d');
    } else {
        $startDate = new DateTimeImmutable($fromInput);
        $endDate = new DateTimeImmutable($toInput);
    }
} else {
    if (!isset($periodOptions[$period])) {
        $period = 'month';
    }
    $startDate = match ($period) {
        'week' => $today->modify('-6 days'),
        '30days' => $today->modify('-29 days'),
        '90days' => $today->modify('-89 days'),
        default => $today->modify('first day of this month'),
    };
    $endDate = $today;
    $fromInput = $startDate->format('Y-m-d');
    $toInput = $endDate->format('Y-m-d');
}

$startAt = $startDate->format('Y-m-d 00:00:00');
$endExclusive = $endDate->modify('+1 day')->format('Y-m-d 00:00:00');
$pdo = db();
$recordOptions = [
    'patients' => 'Patients',
    'appointments' => 'Appointments',
    'prescriptions' => 'Prescriptions',
    'billing' => 'Billing',
    'audit' => 'Audit Log',
];
$requestedRecords = $_GET['records'] ?? null;
$selectedRecords = $requestedRecords === null
    ? array_keys($recordOptions)
    : (is_array($requestedRecords)
        ? array_values(array_intersect(array_keys($recordOptions), $requestedRecords))
        : []);
$recordError = $selectedRecords ? '' : 'Select at least one record type to generate a report.';
$reportRows = [];
$reportLimit = 500;

// Each report uses its own event date so the chosen reporting period is meaningful.
$reportDefinitions = [
    'patients' => [
        'title' => 'Patients',
        'countSql' => 'SELECT COUNT(*) FROM patients WHERE created_at>=? AND created_at<?',
        'sql' => "SELECT p.patient_no, CONCAT(p.first_name, ' ', p.last_name) AS patient,
                    p.date_of_birth, p.gender, p.phone, p.insurance_provider, p.created_at AS event_date
                  FROM patients p WHERE p.created_at>=? AND p.created_at<?
                  ORDER BY p.created_at DESC LIMIT " . ($reportLimit + 1),
        'columns' => ['patient_no' => 'Patient no.', 'patient' => 'Patient', 'date_of_birth' => 'Date of birth',
            'gender' => 'Sex', 'phone' => 'Phone', 'insurance_provider' => 'Medical aid', 'event_date' => 'Registered'],
    ],
    'appointments' => [
        'title' => 'Appointments',
        'countSql' => 'SELECT COUNT(*) FROM appointments WHERE scheduled_for>=? AND scheduled_for<?',
        'sql' => "SELECT a.id AS appointment_id, CONCAT(p.first_name, ' ', p.last_name) AS patient,
                    a.scheduled_for, a.status, a.reason, d.full_name AS doctor
                  FROM appointments a JOIN patients p ON p.id=a.patient_id
                  LEFT JOIN users d ON d.id=a.doctor_id
                  WHERE a.scheduled_for>=? AND a.scheduled_for<?
                  ORDER BY a.scheduled_for DESC LIMIT " . ($reportLimit + 1),
        'columns' => ['appointment_id' => 'Appointment', 'patient' => 'Patient', 'scheduled_for' => 'Scheduled for',
            'status' => 'Status', 'reason' => 'Reason', 'doctor' => 'Doctor'],
    ],
    'prescriptions' => [
        'title' => 'Prescriptions',
        'countSql' => 'SELECT COUNT(*) FROM prescriptions r JOIN visits v ON v.id=r.visit_id WHERE v.arrived_at>=? AND v.arrived_at<?',
        'sql' => "SELECT r.id AS prescription_id, CONCAT(p.first_name, ' ', p.last_name) AS patient,
                    v.arrived_at AS prescribed_on, r.drug_name, r.dosage, r.frequency, r.duration,
                    r.quantity, r.dispensed, r.dispensed_at
                  FROM prescriptions r JOIN visits v ON v.id=r.visit_id
                  JOIN patients p ON p.id=v.patient_id
                  WHERE v.arrived_at>=? AND v.arrived_at<?
                  ORDER BY v.arrived_at DESC, r.id DESC LIMIT " . ($reportLimit + 1),
        'columns' => ['prescription_id' => 'Prescription', 'patient' => 'Patient', 'prescribed_on' => 'Date',
            'drug_name' => 'Medicine', 'dosage' => 'Dosage', 'frequency' => 'Frequency', 'duration' => 'Duration',
            'quantity' => 'Quantity', 'dispensed' => 'Dispensed', 'dispensed_at' => 'Dispensed at'],
    ],
    'billing' => [
        'title' => 'Billing',
        'countSql' => 'SELECT COUNT(*) FROM invoices i JOIN visits v ON v.id=i.visit_id WHERE v.arrived_at>=? AND v.arrived_at<?',
        'sql' => "SELECT i.id AS invoice_id, CONCAT(p.first_name, ' ', p.last_name) AS patient,
                    v.arrived_at AS visit_date, i.total, i.insurance_covered, i.amount_paid, i.status,
                    COALESCE(pm.label, i.payment_method, '—') AS payment_method, i.paid_at
                  FROM invoices i JOIN visits v ON v.id=i.visit_id
                  JOIN patients p ON p.id=v.patient_id
                  LEFT JOIN payment_methods pm ON pm.method_key=i.payment_method
                  WHERE v.arrived_at>=? AND v.arrived_at<?
                  ORDER BY v.arrived_at DESC LIMIT " . ($reportLimit + 1),
        'columns' => ['invoice_id' => 'Invoice', 'patient' => 'Patient', 'visit_date' => 'Visit date',
            'total' => 'Total', 'insurance_covered' => 'Medical aid', 'amount_paid' => 'Patient paid',
            'status' => 'Status', 'payment_method' => 'Payment method', 'paid_at' => 'Paid at'],
    ],
    'audit' => [
        'title' => 'Audit Log',
        'countSql' => 'SELECT COUNT(*) FROM audit_log WHERE created_at>=? AND created_at<?',
        'sql' => "SELECT a.created_at, u.full_name AS user, a.action, a.detail, a.ip_address
                  FROM audit_log a LEFT JOIN users u ON u.id=a.user_id
                  WHERE a.created_at>=? AND a.created_at<?
                  ORDER BY a.id DESC LIMIT " . ($reportLimit + 1),
        'columns' => ['created_at' => 'Time', 'user' => 'User', 'action' => 'Action',
            'detail' => 'Details', 'ip_address' => 'IP address'],
    ],
];

foreach ($selectedRecords as $recordType) {
    $statement = $pdo->prepare($reportDefinitions[$recordType]['sql']);
    $statement->execute([$startAt, $endExclusive]);
    $rows = $statement->fetchAll();
    $truncated = count($rows) > $reportLimit;
    if ($truncated) {
        array_pop($rows);
    }
    $countStatement = $pdo->prepare($reportDefinitions[$recordType]['countSql']);
    $countStatement->execute([$startAt, $endExclusive]);
    $reportRows[$recordType] = [
        'rows' => $rows,
        'truncated' => $truncated,
        'total' => (int)$countStatement->fetchColumn(),
    ];
}

// Show totals only for report types selected by the administrator.
$summary = [];
foreach ($selectedRecords as $recordType) {
    $summary[] = [$reportDefinitions[$recordType]['title'], $reportRows[$recordType]['total']];
}

$amount = static function (string $sql) use ($pdo, $startAt, $endExclusive): float {
    $statement = $pdo->prepare($sql);
    $statement->execute([$startAt, $endExclusive]);
    return (float)$statement->fetchColumn();
};

$includeBilling = in_array('billing', $selectedRecords, true);
$revenue = $includeBilling
    ? $amount("SELECT COALESCE(SUM(amount_paid),0) FROM invoices WHERE status='paid' AND paid_at>=? AND paid_at<?")
    : 0.0;
$openBalance = $includeBilling
    ? $amount("SELECT COALESCE(SUM(i.total-i.amount_paid-i.insurance_covered),0)
        FROM invoices i JOIN visits v ON v.id=i.visit_id
        WHERE i.status='unpaid' AND v.arrived_at>=? AND v.arrived_at<?")
    : 0.0;
$appointmentStatuses = [];
if (in_array('appointments', $selectedRecords, true)) {
    $appointmentStatusQuery = $pdo->prepare(
        'SELECT status, COUNT(*) AS total FROM appointments WHERE scheduled_for>=? AND scheduled_for<? GROUP BY status ORDER BY total DESC'
    );
    $appointmentStatusQuery->execute([$startAt, $endExclusive]);
    $appointmentStatuses = $appointmentStatusQuery->fetchAll();
}

$revenueMethods = [];
if ($includeBilling) {
    $revenueMethodQuery = $pdo->prepare(
        "SELECT COALESCE(pm.label, i.payment_method, 'Unspecified') AS method, SUM(i.amount_paid) AS total
         FROM invoices i LEFT JOIN payment_methods pm ON pm.method_key=i.payment_method
         WHERE i.status='paid' AND i.paid_at>=? AND i.paid_at<?
         GROUP BY method ORDER BY total DESC"
    );
    $revenueMethodQuery->execute([$startAt, $endExclusive]);
    $revenueMethods = $revenueMethodQuery->fetchAll();
}

// Match the visual activity chart to the selected report types.
$activitySources = [
    'patients' => ['label' => 'Patients', 'sql' => "SELECT created_at AS event_date, 'Patients' AS kind FROM patients"],
    'appointments' => ['label' => 'Appointments', 'sql' => "SELECT scheduled_for AS event_date, 'Appointments' AS kind FROM appointments"],
    'prescriptions' => ['label' => 'Prescriptions', 'sql' => "SELECT v.arrived_at AS event_date, 'Prescriptions' AS kind FROM prescriptions r JOIN visits v ON v.id=r.visit_id"],
    'billing' => ['label' => 'Billing', 'sql' => "SELECT v.arrived_at AS event_date, 'Billing' AS kind FROM invoices i JOIN visits v ON v.id=i.visit_id"],
    'audit' => ['label' => 'Audit Log', 'sql' => "SELECT created_at AS event_date, 'Audit Log' AS kind FROM audit_log"],
];
$seriesNames = array_map(
    static fn(string $type): string => $activitySources[$type]['label'],
    $selectedRecords
);
$seriesColors = [
    'Patients' => '#16806a',
    'Appointments' => '#d5a548',
    'Prescriptions' => '#81b7a5',
    'Billing' => '#155e75',
    'Audit Log' => '#728394',
];

// Choose chart granularity from the selected date range to keep long periods readable.
$rangeDays = (int)$startDate->diff($endDate)->days + 1;
$bucket = $rangeDays <= 31
    ? "DATE_FORMAT(activity.event_date, '%e %b')"
    : ($rangeDays <= 180
        ? "DATE_FORMAT(DATE_SUB(DATE(activity.event_date), INTERVAL WEEKDAY(activity.event_date) DAY), '%e %b')"
        : "DATE_FORMAT(activity.event_date, '%b %Y')");
$activityRows = [];
if ($selectedRecords) {
    $activityUnion = implode(
        ' UNION ALL ',
        array_map(static fn(string $type): string => $activitySources[$type]['sql'], $selectedRecords)
    );
    $activityQuery = $pdo->prepare(
        "SELECT $bucket AS period_label, activity.kind, COUNT(*) AS total
         FROM ($activityUnion) activity
         WHERE activity.event_date>=? AND activity.event_date<?
         GROUP BY period_label, activity.kind ORDER BY MIN(activity.event_date)"
    );
    $activityQuery->execute([$startAt, $endExclusive]);
    $activityRows = $activityQuery->fetchAll();
}
$activitySeries = [];
foreach ($activityRows as $row) {
    $activitySeries[$row['period_label']][$row['kind']] = (int)$row['total'];
}
$chartMax = 1;
foreach ($activitySeries as $counts) {
    foreach ($seriesNames as $name) {
        $chartMax = max($chartMax, $counts[$name] ?? 0);
    }
}
$chartWidth = max(900, count($activitySeries) * 70 + 70);
$chartHeight = 250;
$plotHeight = 170;
$barWidth = 9;
$bucketWidth = count($activitySeries) ? ($chartWidth - 80) / count($activitySeries) : 40;

staff_header('Reports', 'reports.php', $recordError ? 'no-report' : 'reports-output');
?>
<div class="page-head">
  <div><h1>Reports &amp; analytics</h1><p>System activity for <?= e($startDate->format('j M Y')) ?> – <?= e($endDate->format('j M Y')) ?></p></div>
</div>
<?php if ($periodError): ?><div class="alert alert-error" role="alert"><?= e($periodError) ?></div><?php endif; ?>
<form method="get" action="<?= e(app_url('/staff/reports.php')) ?>" class="panel report-filter-form">
  <div class="report-period-form">
    <div><label for="period">Reporting period</label>
      <select id="period" name="period">
        <?php foreach ($periodOptions as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $period === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div><label for="from">From</label><input id="from" type="date" name="from" value="<?= e($fromInput) ?>"></div>
    <div><label for="to">To</label><input id="to" type="date" name="to" value="<?= e($toInput) ?>"></div>
  </div>
  <fieldset class="report-record-options">
    <legend>Choose records to include</legend>
    <?php foreach ($recordOptions as $key => $label): ?>
      <label><input type="checkbox" name="records[]" value="<?= e($key) ?>" <?= in_array($key, $selectedRecords, true) ? 'checked' : '' ?>> <?= e($label) ?></label>
    <?php endforeach; ?>
  </fieldset>
  <button class="btn btn-dark" type="submit">Generate selected report</button>
</form>
<?php if ($recordError): ?><div class="alert alert-error" role="alert"><?= e($recordError) ?></div><?php endif; ?>

<?php if (!$recordError): ?>
<div class="report-print-output" data-print-target="reports-output">
  <?php clinic_print_header('report-print-header'); ?>
  <div class="report-document-heading">
    <h2><?= e(implode(', ', array_map(static fn(string $type): string => $recordOptions[$type], $selectedRecords))) ?></h2>
    <p><strong>Included:</strong> <?= e(implode(', ', array_map(static fn(string $type): string => $recordOptions[$type], $selectedRecords))) ?></p>
    <p><strong>Period:</strong> <?= e($startDate->format('j M Y')) ?> – <?= e($endDate->format('j M Y')) ?></p>
  </div>
  <?php foreach ($selectedRecords as $recordType): $report = $reportRows[$recordType]; ?>
    <section class="panel report-panel report-record-section">
      <h2><?= e($reportDefinitions[$recordType]['title']) ?> report <span class="report-result-count">(<?= (int)$report['total'] ?> records)</span></h2>
      <?php if (!$report['rows']): ?><p class="dashboard-empty">No <?= e(strtolower($reportDefinitions[$recordType]['title'])) ?> found for the selected period.</p><?php else: ?>
        <div class="table-wrap"><table>
          <thead><tr><?php foreach ($reportDefinitions[$recordType]['columns'] as $label): ?><th><?= e($label) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach ($report['rows'] as $row): ?>
            <tr><?php foreach ($reportDefinitions[$recordType]['columns'] as $key => $_): ?>
              <?php
              $value = $row[$key] ?? '';
              if (in_array($key, ['total', 'insurance_covered', 'amount_paid'], true)) {
                  $value = money($value);
              } elseif ($key === 'dispensed') {
                  $value = (int)$value ? 'Yes' : 'No';
              } elseif ($key === 'gender') {
                  $value = ucfirst((string)$value);
              }
              ?>
              <td><?= e((string)$value ?: '—') ?></td>
            <?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php if ($report['truncated']): ?><p class="report-note">Showing the first <?= $reportLimit ?> records. Narrow the date range to see a smaller, complete result.</p><?php endif; ?>
      <?php endif; ?>
    </section>
  <?php endforeach; ?>

<?php if ($summary): ?>
<section class="report-summary-grid" aria-label="Selected report totals">
  <?php foreach ($summary as [$label, $total]): ?>
    <article class="report-summary-card"><span><?= e($label) ?></span><strong><?= (int)$total ?></strong></article>
  <?php endforeach; ?>
  <?php if ($includeBilling): ?>
    <article class="report-summary-card report-money"><span>Revenue collected</span><strong><?= e(money($revenue)) ?></strong></article>
    <article class="report-summary-card report-money"><span>Unpaid balance from period visits</span><strong><?= e(money($openBalance)) ?></strong></article>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="panel report-panel">
  <h2>Selected activity over time</h2>
  <p class="report-note">Visual trend for the selected report types and date range.</p>
  <?php if (!$activitySeries): ?><p class="dashboard-empty">No activity recorded in this period.</p><?php else: ?>
    <div class="report-chart-scroll">
      <svg class="report-chart" viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-label="Bar chart showing activity counts over time">
        <line x1="45" y1="195" x2="<?= $chartWidth - 15 ?>" y2="195" stroke="#dce5ec" />
        <?php foreach ($activitySeries as $label => $counts): ?>
          <?php $bucketIndex = array_search($label, array_keys($activitySeries), true); $groupX = 52 + $bucketIndex * $bucketWidth; ?>
          <?php foreach ($seriesNames as $seriesIndex => $name): ?>
            <?php $countValue = $counts[$name] ?? 0; $height = $countValue ? max(2, (int)round($countValue / $chartMax * $plotHeight)) : 0; ?>
            <?php if ($height): ?>
              <rect x="<?= (int)round($groupX + $seriesIndex * ($barWidth + 2)) ?>" y="<?= 195 - $height ?>" width="<?= $barWidth ?>" height="<?= $height ?>" rx="2" fill="<?= e($seriesColors[$name]) ?>">
                <title><?= e($label . ': ' . $name . ' ' . $countValue) ?></title>
              </rect>
            <?php endif; ?>
          <?php endforeach; ?>
          <?php if ($bucketIndex % max(1, (int)ceil(count($activitySeries) / 12)) === 0): ?>
            <text x="<?= (int)round($groupX) ?>" y="215" class="report-chart-label"><?= e($label) ?></text>
          <?php endif; ?>
        <?php endforeach; ?>
      </svg>
    </div>
    <div class="report-chart-legend">
      <?php foreach ($seriesNames as $name): $legendKey = strtolower(str_replace(' ', '-', $name)); ?><span><i class="report-chart-key report-chart-key-<?= e($legendKey) ?>"></i><?= e($name) ?></span><?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<div class="report-breakdown-grid">
  <?php if (in_array('appointments', $selectedRecords, true)): ?>
  <section class="panel report-panel">
    <h2>Appointments by status</h2>
    <?php if (!$appointmentStatuses): ?><p class="dashboard-empty">No appointments created in this period.</p><?php else: ?>
      <div class="table-wrap"><table><tr><th>Status</th><th>Appointments</th></tr>
        <?php foreach ($appointmentStatuses as $row): ?><tr><td><?= e(ucfirst($row['status'])) ?></td><td><?= (int)$row['total'] ?></td></tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php if ($includeBilling): ?>
  <section class="panel report-panel">
    <h2>Revenue by payment method</h2>
    <?php if (!$revenueMethods): ?><p class="dashboard-empty">No payments collected in this period.</p><?php else: ?>
      <div class="table-wrap"><table><tr><th>Payment method</th><th>Collected</th></tr>
        <?php foreach ($revenueMethods as $row): ?><tr><td><?= e($row['method']) ?></td><td><?= e(money($row['total'])) ?></td></tr><?php endforeach; ?>
      </table></div>
    <?php endif; ?>
    <p class="report-note">Bills created in this period: <?= (int)$reportRows['billing']['total'] ?></p>
  </section>
  <?php endif; ?>
  <?php clinic_print_footer(); ?>
</div>
<?php endif; ?>

<?php staff_footer();
