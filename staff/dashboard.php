<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception', 'nurse', 'doctor', 'pharmacist']);

$pdo = db();
$scalar = static function (string $sql) use ($pdo): int {
    return (int)$pdo->query($sql)->fetchColumn();
};

$stats = [
    ['Patients', $scalar('SELECT COUNT(*) FROM patients'), 'registered in the system', 'patients'],
    ['Visits today', $scalar('SELECT COUNT(*) FROM visits WHERE DATE(arrived_at)=CURDATE()'), 'arrivals today', 'visits'],
    ['Appointments', $scalar("SELECT COUNT(*) FROM appointments WHERE status='scheduled' AND scheduled_for>=NOW()"), 'scheduled upcoming', 'appointments'],
    ['Active visits', $scalar("SELECT COUNT(*) FROM visits WHERE status<>'departed'"), 'currently in clinic', 'clinic'],
    ['Unpaid bills', $scalar("SELECT COUNT(*) FROM invoices WHERE status='unpaid'"), 'awaiting payment', 'billing'],
    ['Awaiting dispensing', $scalar('SELECT COUNT(*) FROM prescriptions WHERE dispensed=0'), 'prescription items', 'pharmacy'],
    ['Low stock items', $scalar('SELECT COUNT(*) FROM inventory_items WHERE is_active=1 AND quantity_on_hand<=reorder_level'), 'at or below reorder level', 'inventory'],
];
$activeVisits = $stats[3][1];
$priorityCounts = $pdo->query("SELECT priority, COUNT(*) AS total FROM visits WHERE status<>'departed' GROUP BY priority")->fetchAll(PDO::FETCH_KEY_PAIR);
$urgentVisits = (int)($priorityCounts['urgent'] ?? 0) + (int)($priorityCounts['emergency'] ?? 0);
$dailyTotals = $pdo->query("SELECT DATE(arrived_at) AS visit_day, COUNT(*) AS total FROM visits
    WHERE arrived_at>=DATE_SUB(CURDATE(), INTERVAL 6 DAY) GROUP BY DATE(arrived_at)")->fetchAll(PDO::FETCH_KEY_PAIR);
$weeklyArrivals = [];
for ($dayOffset = 6; $dayOffset >= 0; $dayOffset--) {
    $day = new DateTimeImmutable('-' . $dayOffset . ' days');
    $key = $day->format('Y-m-d');
    $weeklyArrivals[] = ['label' => $day->format('D'), 'date' => $key, 'total' => (int)($dailyTotals[$key] ?? 0)];
}
$weeklyMaximum = max(1, max(array_column($weeklyArrivals, 'total')));
$lowStockItems = $pdo->query("SELECT id, name, quantity_on_hand, unit, reorder_level FROM inventory_items
    WHERE is_active=1 AND quantity_on_hand<=reorder_level ORDER BY quantity_on_hand ASC, name LIMIT 5")->fetchAll();

$active = $pdo->query("SELECT v.id, v.status, v.priority, v.arrived_at, p.id AS patient_id, p.first_name, p.last_name, p.patient_no
    FROM visits v JOIN patients p ON p.id=v.patient_id WHERE v.status<>'departed'
    ORDER BY CASE v.priority WHEN 'emergency' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END, v.arrived_at LIMIT 10")->fetchAll();

$upcoming = $pdo->query("SELECT a.id, a.scheduled_for, a.reason, p.id AS patient_id, p.first_name, p.last_name
    FROM appointments a JOIN patients p ON p.id=a.patient_id
    WHERE a.status='scheduled' AND a.scheduled_for>=NOW()
    ORDER BY a.scheduled_for LIMIT 5")->fetchAll();

$todayAppointments = $scalar("SELECT COUNT(*) FROM appointments WHERE status='scheduled' AND scheduled_for>=NOW() AND scheduled_for<DATE_ADD(CURDATE(), INTERVAL 1 DAY)");
$unpaidBills = $scalar("SELECT COUNT(*) FROM invoices WHERE status='unpaid'");
$pendingDispensing = $scalar('SELECT COUNT(*) FROM prescriptions WHERE dispensed=0');
$recentBills = $pdo->query("SELECT i.id, i.total, i.status, v.patient_id, p.first_name, p.last_name
    FROM invoices i JOIN visits v ON v.id=i.visit_id JOIN patients p ON p.id=v.patient_id
    ORDER BY i.id DESC LIMIT 5")->fetchAll();
$canOpenPatients = in_array($u['role'], ['admin', 'reception', 'nurse', 'doctor'], true);
$canOpenAppointments = in_array($u['role'], ['admin', 'reception', 'nurse', 'doctor'], true);
$canOpenBilling = in_array($u['role'], ['admin', 'reception'], true);
$canOpenInventory = in_array($u['role'], ['admin', 'nurse', 'doctor', 'pharmacist'], true);

staff_header('Dashboard', 'dashboard.php', '', 'dashboard-body');
?>
<div class="dashboard-page">
    <div class="page-head dashboard-heading">
      <div><h1>Dashboard</h1><p>Operational overview.</p></div>
      <span><?= e(date('l, j F Y')) ?></span>
    </div>

    <section class="dashboard-mobile-summary" aria-label="Clinic at a glance">
      <span>Patients currently in clinic</span>
      <strong><?= $activeVisits ?></strong>
      <small>Active visits</small>
    </section>

    <nav class="dashboard-quick-actions" aria-label="Quick actions">
      <?php if ($canOpenPatients): ?>
        <a href="<?= e(app_url('/staff/patient_new.php')) ?>"><span aria-hidden="true"><?= staff_menu_icon('users.php') ?></span><small>Register</small></a>
        <a href="<?= e(app_url('/staff/patients.php')) ?>"><span aria-hidden="true"><?= staff_menu_icon('patients.php') ?></span><small>Patients</small></a>
      <?php endif; ?>
      <a href="<?= e(app_url('/staff/queue.php')) ?>"><span aria-hidden="true"><?= staff_menu_icon('queue.php') ?></span><small>Live queue</small></a>
      <?php if ($canOpenAppointments): ?>
        <a href="<?= e(app_url('/staff/appointments.php')) ?>"><span aria-hidden="true"><?= staff_menu_icon('appointments.php') ?></span><small>Appointments</small></a>
      <?php endif; ?>
      <?php if ($canOpenBilling): ?>
        <a href="<?= e(app_url('/staff/billing.php')) ?>"><span aria-hidden="true"><?= staff_menu_icon('billing.php') ?></span><small>Billing</small></a>
      <?php endif; ?>
      <?php if ($canOpenInventory): ?>
        <a href="<?= e(app_url('/staff/inventory.php')) ?>"><span aria-hidden="true"><?= staff_menu_icon('inventory.php') ?></span><small>Inventory</small></a>
      <?php endif; ?>
    </nav>

    <div class="dashboard-mobile-section-title"><h2>Clinic overview</h2></div>
    <div class="dashboard-stats">
      <?php foreach ($stats as [$label, $number, $detail, $icon]):
        $iconFile = match ($icon) {
            'patients' => 'patients.php',
            'visits', 'clinic' => 'queue.php',
            'appointments' => 'appointments.php',
            'billing' => 'billing.php',
            'pharmacy' => 'pharmacy.php',
            default => 'inventory.php',
        }; ?>
        <section class="dashboard-stat">
          <span class="dashboard-stat-icon" aria-hidden="true"><?= staff_menu_icon($iconFile) ?></span>
          <div><span class="dashboard-stat-label"><?= e($label) ?></span><strong><?= $number ?></strong><small><?= e($detail) ?></small></div>
        </section>
      <?php endforeach; ?>
    </div>

    <section class="clinic-pulse" aria-label="Clinic activity and stock alerts">
      <article class="panel clinic-pulse-chart">
        <?php $weeklyVisitCount = array_sum(array_column($weeklyArrivals, 'total')); ?>
        <div class="dashboard-section-heading"><div><h2>Weekly arrivals</h2><p>Patient visits over the last seven days</p></div><strong><?= $weeklyVisitCount ?> <?= $weeklyVisitCount === 1 ? 'visit' : 'visits' ?></strong></div>
        <div class="clinic-pulse-bars" role="img" aria-label="Daily arrivals for the last seven days">
          <?php foreach ($weeklyArrivals as $day):
            $level = $day['total'] > 0 ? max(1, (int)round($day['total'] / $weeklyMaximum * 10)) : 0; ?>
            <div class="clinic-pulse-day" aria-label="<?= e(date('j M', strtotime($day['date']))) ?>: <?= $day['total'] ?> visits">
              <span class="clinic-pulse-value"><?= $day['total'] ?></span><span class="clinic-pulse-track"><span class="clinic-pulse-bar level-<?= $level ?>"></span></span><small><?= e($day['label']) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
      </article>
      <article class="panel clinic-pulse-alerts">
        <div class="dashboard-section-heading"><div><h2>Attention needed</h2><p>Priority queue, stock, billing and care tasks</p></div></div>
        <div class="clinic-alert-metrics">
          <div><span>Urgent / emergency queue</span><strong class="<?= $urgentVisits > 0 ? 'text-danger' : '' ?>"><?= $urgentVisits ?></strong></div>
          <div><span>Items at reorder level</span><strong><?= count($lowStockItems) ?><?= $stats[6][1] > 5 ? '+' : '' ?></strong></div>
        </div>
        <ul class="dashboard-attention-list">
          <li><a href="<?= e(app_url('/staff/queue.php')) ?>">Urgent queue</a><strong><?= $urgentVisits ?></strong></li>
          <?php if ($canOpenAppointments): ?><li><a href="<?= e(app_url('/staff/appointments.php')) ?>">Appointments today</a><strong><?= $todayAppointments ?></strong></li><?php endif; ?>
          <?php if ($canOpenBilling): ?><li><a href="<?= e(app_url('/staff/billing.php')) ?>">Unpaid bills</a><strong><?= $unpaidBills ?></strong></li><?php endif; ?>
          <?php if (in_array($u['role'], ['admin', 'nurse', 'pharmacist'], true)): ?><li><a href="<?= e(app_url('/staff/pharmacy.php')) ?>">Awaiting dispensing</a><strong><?= $pendingDispensing ?></strong></li><?php endif; ?>
          <?php if ($canOpenInventory): ?><li><a href="<?= e(app_url('/staff/inventory.php')) ?>">Low-stock items</a><strong><?= $stats[6][1] ?></strong></li><?php endif; ?>
        </ul>
        <?php if ($lowStockItems): ?>
          <ul class="clinic-stock-alerts"><?php foreach ($lowStockItems as $item): ?>
            <li><span><?= e($item['name']) ?></span><strong><?= e($item['quantity_on_hand']) ?> <?= e($item['unit']) ?></strong></li>
          <?php endforeach; ?></ul>
        <?php else: ?><p class="dashboard-empty">No items are below their reorder level.</p><?php endif; ?>
        <?php if ($canOpenInventory): ?><a class="dashboard-more" href="<?= e(app_url('/staff/inventory.php')) ?>">Review inventory</a><?php endif; ?>
      </article>
    </section>

    <div class="dashboard-panels">
      <section class="panel dashboard-panel">
        <h2>Upcoming appointments</h2>
        <?php if (!$upcoming): ?><p class="dashboard-empty">No appointments scheduled.</p><?php else: ?>
          <ul class="dashboard-list">
            <?php foreach ($upcoming as $appointment): ?>
              <li>
                <?php if ($canOpenPatients): ?><a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$appointment['patient_id'])) ?>"><?= e($appointment['first_name'] . ' ' . $appointment['last_name']) ?></a><?php else: ?><?= e($appointment['first_name'] . ' ' . $appointment['last_name']) ?><?php endif; ?>
                <span><?= e(date('j M, H:i', strtotime($appointment['scheduled_for']))) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <a class="dashboard-more" href="<?= e(app_url('/staff/appointments.php')) ?>">View appointments</a>
        <?php endif; ?>
      </section>

      <section class="panel dashboard-panel">
        <h2>Recent bills</h2>
        <?php if (!$recentBills): ?><p class="dashboard-empty">No bills recorded.</p><?php else: ?>
          <ul class="dashboard-list dashboard-bills">
            <?php foreach ($recentBills as $bill): ?>
              <li>
                <span>INV-<?= (int)$bill['id'] ?> &middot; <?php if ($canOpenPatients): ?><a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$bill['patient_id'])) ?>"><?= e($bill['first_name'] . ' ' . $bill['last_name']) ?></a><?php else: ?><?= e($bill['first_name'] . ' ' . $bill['last_name']) ?><?php endif; ?></span>
                <span><?= e(money($bill['total'])) ?> <?= badge(ucfirst($bill['status']), $bill['status']) ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if (in_array($u['role'], ['admin', 'reception'], true)): ?>
            <a class="dashboard-more" href="<?= e(app_url('/staff/billing.php')) ?>">Open billing</a>
          <?php endif; ?>
        <?php endif; ?>
      </section>
    </div>

    <section class="panel dashboard-active">
      <div class="dashboard-section-heading"><h2>Patients currently in the centre</h2><a href="<?= e(app_url('/staff/queue.php')) ?>">View live queue</a></div>
      <?php if (!$active): ?><p class="dashboard-empty">No patients in the centre right now.</p><?php else: ?>
      <div class="table-wrap"><table>
        <tr><th>Patient</th><th>Stage</th><th>Priority</th><th>Arrived</th></tr>
        <?php foreach ($active as $v): ?>
          <tr>
            <td><?php if ($canOpenPatients): ?><a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$v['patient_id'])) ?>"><?= e($v['first_name'] . ' ' . $v['last_name']) ?></a><?php else: ?><?= e($v['first_name'] . ' ' . $v['last_name']) ?><?php endif; ?><br><small><?= e($v['patient_no']) ?></small></td>
            <td><?= e(STAGES[$v['status']]) ?></td>
            <td><?= badge($v['priority'], $v['priority']) ?></td>
            <td><?= e(date('H:i', strtotime($v['arrived_at']))) ?></td>
          </tr>
        <?php endforeach; ?>
      </table></div>
      <?php endif; ?>
    </section>
  </div>
  <?php staff_footer();
