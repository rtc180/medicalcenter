<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception', 'nurse', 'doctor']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $vid = (int)post('visit_id');
    $action = post('action');
    $st = db()->prepare('SELECT * FROM visits WHERE id=?');
    $st->execute([$vid]);
    $visit = $st->fetch();

    if ($visit && $action === 'start_triage' && in_array($u['role'], ['nurse', 'admin'], true) && $visit['status'] === 'waiting_nurse') {
        db()->prepare("UPDATE visits SET status='triage', nurse_id=? WHERE id=? AND status='waiting_nurse'")->execute([$u['id'], $vid]);
        audit('triage_started', "visit $vid");
        redirect('/staff/triage.php?visit=' . $vid);
    }
    if ($visit && $action === 'start_consult' && in_array($u['role'], ['doctor', 'admin'], true) && $visit['status'] === 'waiting_doctor') {
        db()->prepare("UPDATE visits SET status='consultation', doctor_id=? WHERE id=? AND status='waiting_doctor'")->execute([$u['id'], $vid]);
        audit('consult_started', "visit $vid");
        redirect('/staff/consult.php?visit=' . $vid);
    }
    http_response_code(403);
    exit('Action not allowed for your role or the visit stage.');
}

$rows = db()->query("SELECT v.*, p.first_name, p.last_name, p.patient_no, p.date_of_birth, p.allergies
    FROM visits v JOIN patients p ON p.id=v.patient_id
    WHERE v.status<>'departed'
    ORDER BY CASE v.priority WHEN 'emergency' THEN 0 WHEN 'urgent' THEN 1 ELSE 2 END, v.arrived_at")->fetchAll();
$by = [];
foreach ($rows as $r) { $by[$r['status']][] = $r; }

$sections = [
    'waiting_nurse'  => 'Waiting for available nurse',
    'triage'         => 'In triage',
    'waiting_doctor' => 'Waiting for available doctor',
    'consultation'   => 'In consultation',
    'disposition'    => 'Treatment decision in progress',
    'billing'        => 'Waiting at billing',
];

staff_header('Live queue', 'queue.php');
?>
<div class="page-head"><h1>Live queue</h1><small>Refreshes every 30 seconds</small></div>
<div data-refresh="30">
<?php foreach ($sections as $key => $title): $list = $by[$key] ?? []; ?>
  <div class="panel">
    <h2><?= e($title) ?> <?= badge((string)count($list)) ?></h2>
    <?php if (!$list): ?><p class="empty-state">Nobody here.</p><?php else: ?>
    <div class="table-wrap"><table>
      <tr><th>Patient</th><th>Priority</th><th>Arrived</th><th>Alerts</th><th></th></tr>
      <?php foreach ($list as $r): ?>
      <tr>
        <td><a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$r['patient_id'])) ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></a><br>
            <small><?= e($r['patient_no']) ?> &middot; <?= age_from_dob($r['date_of_birth']) ?> yrs</small></td>
        <td><?= badge($r['priority'], $r['priority']) ?></td>
        <td><?= e(date('H:i', strtotime($r['arrived_at']))) ?></td>
        <td><?php if ($r['allergies']): ?><span class="badge emergency">Allergies: <?= e(mb_substr($r['allergies'], 0, 60)) ?></span><?php else: ?>—<?php endif; ?></td>
        <td>
          <?php if ($key === 'waiting_nurse' && in_array($u['role'], ['nurse', 'admin'], true)): ?>
            <form method="post" action="<?= e(app_url('/staff/queue.php')) ?>"><?= csrf_field() ?><input type="hidden" name="visit_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="start_triage"><button class="btn btn-sm" type="submit">Start triage</button></form>
          <?php elseif ($key === 'triage' && in_array($u['role'], ['nurse', 'admin'], true)): ?>
            <a class="btn btn-sm btn-dark" href="<?= e(app_url('/staff/triage.php?visit=' . (int)$r['id'])) ?>">Record vitals</a>
          <?php elseif ($key === 'waiting_doctor' && in_array($u['role'], ['doctor', 'admin'], true)): ?>
            <form method="post" action="<?= e(app_url('/staff/queue.php')) ?>"><?= csrf_field() ?><input type="hidden" name="visit_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="action" value="start_consult"><button class="btn btn-sm" type="submit">Start consultation</button></form>
          <?php elseif (in_array($key, ['consultation', 'disposition'], true) && in_array($u['role'], ['doctor', 'admin'], true)): ?>
            <a class="btn btn-sm btn-dark" href="<?= e(app_url('/staff/consult.php?visit=' . (int)$r['id'])) ?>">Open</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table></div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>
<?php staff_footer();
