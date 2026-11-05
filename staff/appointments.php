<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception', 'doctor', 'nurse']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)post('id');
    $status = post('status');
    if (in_array($status, ['completed', 'cancelled', 'missed'], true)) {
        db()->prepare("UPDATE appointments SET status=? WHERE id=? AND status='scheduled'")->execute([$status, $id]);
        audit('appointment_' . $status, "appointment $id");
        flash('success', 'Appointment updated.');
    }
    redirect('/staff/appointments.php');
}

$upcoming = db()->query("SELECT a.*, p.first_name, p.last_name, p.phone, p.patient_no, d.full_name AS doctor
    FROM appointments a JOIN patients p ON p.id=a.patient_id LEFT JOIN users d ON d.id=a.doctor_id
    WHERE a.status='scheduled' ORDER BY a.scheduled_for LIMIT 100")->fetchAll();

staff_header('Appointments', 'appointments.php');
?>
<div class="page-head"><h1>Follow-up appointments</h1></div>
<div class="panel">
<?php if (!$upcoming): ?><p>No scheduled appointments.</p><?php else: ?>
<div class="table-wrap"><table>
  <tr><th>When</th><th>Patient</th><th>Phone</th><th>Reason</th><th>Doctor</th><th></th></tr>
  <?php foreach ($upcoming as $a): $overdue = strtotime($a['scheduled_for']) < time(); ?>
  <tr>
    <td><?= e(date('D j M Y, H:i', strtotime($a['scheduled_for']))) ?> <?= $overdue ? badge('Overdue', 'urgent') : '' ?></td>
    <td><a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$a['patient_id'])) ?>"><?= e($a['first_name'] . ' ' . $a['last_name']) ?></a><br><small><?= e($a['patient_no']) ?></small></td>
    <td><?= e($a['phone']) ?></td><td><?= e($a['reason'] ?? '') ?></td><td><?= e($a['doctor'] ?? '—') ?></td>
    <td><form method="post" action="<?= e(app_url('/staff/appointments.php')) ?>" class="appointment-status-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
      <select name="status" aria-label="Update status" class="field-auto"><option value="completed">Attended</option><option value="missed">Missed</option><option value="cancelled">Cancelled</option></select>
      <button class="btn btn-sm" type="submit">Update</button></form></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
</div>
<?php staff_footer();
