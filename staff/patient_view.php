<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception', 'nurse', 'doctor']);

$id = get_int('id');
$st = db()->prepare('SELECT * FROM patients WHERE id=?');
$st->execute([$id]);
$p = $st->fetch();
if (!$p) {
    http_response_code(404);
    exit('Patient not found.');
}
audit('patient_viewed', "patient $id");
$canManagePatient = in_array($u['role'], ['reception', 'admin'], true);

$vs = db()->prepare("SELECT v.*, c.diagnosis, c.treatment_plan, t.chief_complaint, i.total, i.status AS inv_status
    FROM visits v LEFT JOIN consultations c ON c.visit_id=v.id LEFT JOIN triage t ON t.visit_id=v.id LEFT JOIN invoices i ON i.visit_id=v.id
    WHERE v.patient_id=? ORDER BY v.arrived_at DESC");
$vs->execute([$id]);
$visits = $vs->fetchAll();

$rxs = db()->prepare('SELECT r.*, v.arrived_at FROM prescriptions r JOIN visits v ON v.id=r.visit_id WHERE v.patient_id=? ORDER BY v.arrived_at DESC LIMIT 30');
$rxs->execute([$id]);
$rx = $rxs->fetchAll();
$patientName = trim($p['first_name'] . ' ' . $p['last_name']);

staff_header($patientName, 'patients.php', 'patient-report');
?>
<div class="page-head no-print"><h1><?= e($patientName) ?></h1>
  <div>
    <?php if ($canManagePatient): ?>
      <a class="btn btn-sm" href="<?= e(app_url('/staff/patient_edit.php?id=' . $id)) ?>">Edit</a>
      <form class="inline-form" method="post" action="<?= e(app_url('/staff/patient_delete.php')) ?>" data-confirm="Delete this patient record? Records with visit or appointment history cannot be deleted. This cannot be undone.">
        <?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= $id ?>">
        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
      </form>
    <?php endif; ?>
    <button class="btn btn-sm" type="button" data-print data-print-target="patient-card">Print patient details / Save as PDF</button>
    <a href="<?= e(app_url('/staff/patients.php')) ?>">&larr; Patients</a></div></div>
<div data-print-target="patient-report">
  <?php clinic_print_header('patient-report-header'); ?>
  <h2 class="print-report-title"><?= e($patientName) ?> — Patient report</h2>
<div class="panel patient-card" data-print-target="patient-card">
  <?php clinic_print_header('patient-card-header'); ?>
  <h2 class="print-report-title"><?= e($patientName) ?></h2>
  <div class="kv">
    <div><small>Patient no.</small><?= e($p['patient_no']) ?></div>
    <div><small>Age / sex</small><?= age_from_dob($p['date_of_birth']) ?> / <?= e(ucfirst($p['gender'])) ?></div>
    <div><small>Date of birth</small><?= e($p['date_of_birth']) ?></div>
    <div><small>National ID</small><?= e($p['national_id'] ?: '—') ?></div>
    <div><small>Phone</small><?= e($p['phone']) ?></div>
    <div><small>Email</small><?= e($p['email'] ?: '—') ?></div>
    <div><small>Address</small><?= e($p['address'] ?: '—') ?></div>
    <div><small>Next of kin</small><?= e($p['next_of_kin'] ?: '—') ?> <?= e($p['next_of_kin_phone'] ?: '') ?></div>
    <div><small>Medical aid</small><?= e(trim(($p['insurance_provider'] ?? '') . ' ' . ($p['insurance_no'] ?? '')) ?: 'Self-pay') ?></div>
    <div><small>Blood group</small><?= e($p['blood_group']) ?></div>
  </div>
  <p><strong>Allergies:</strong> <span class="patient-allergies"><?= e($p['allergies'] ?: 'None recorded') ?></span></p>
  <p><strong>Chronic conditions:</strong> <?= e($p['chronic_conditions'] ?: 'None recorded') ?></p>
  <p class="patient-history"><strong>Medical history:</strong> <?= nl2br(e($p['medical_history'] ?: 'None recorded')) ?></p>
  <?php clinic_print_footer(); ?>
</div>

<div class="panel">
  <h2>Visit history</h2>
  <?php if (!$visits): ?><p>No visits yet.</p><?php else: ?>
  <div class="table-wrap"><table>
    <tr><th>Date</th><th>Complaint</th><th>Diagnosis</th><th>Stage</th><th>Bill</th></tr>
    <?php foreach ($visits as $v): ?>
      <tr><td><?= e(date('j M Y H:i', strtotime($v['arrived_at']))) ?></td>
          <td><?= e($v['chief_complaint'] ?? '—') ?></td>
          <td><?= e($v['diagnosis'] ?? '—') ?></td>
          <td><?= e(STAGES[$v['status']]) ?></td>
          <td><?= $v['total'] !== null ? money($v['total']) . ' ' . badge($v['inv_status'], $v['inv_status']) : '—' ?></td></tr>
    <?php endforeach; ?>
  </table></div><?php endif; ?>
</div>

<?php if ($rx): ?>
<div class="panel"><h2>Medication history</h2>
  <div class="table-wrap"><table><tr><th>Date</th><th>Drug</th><th>Dose</th><th>Frequency</th><th>Duration</th></tr>
  <?php foreach ($rx as $r): ?><tr><td><?= e(date('j M Y', strtotime($r['arrived_at']))) ?></td><td><?= e($r['drug_name']) ?></td><td><?= e($r['dosage']) ?></td><td><?= e($r['frequency']) ?></td><td><?= e($r['duration']) ?></td></tr><?php endforeach; ?>
  </table></div></div>
<?php endif; ?>
  </div>
  <?php staff_footer();
