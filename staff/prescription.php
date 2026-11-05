<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['nurse', 'doctor', 'pharmacist']);

$st = db()->prepare("SELECT r.*, v.id AS visit_id, v.doctor_id, v.arrived_at,
    p.first_name, p.last_name, p.patient_no, p.date_of_birth, p.gender,
    c.diagnosis, d.full_name AS doctor_name
    FROM prescriptions r
    JOIN visits v ON v.id=r.visit_id
    JOIN patients p ON p.id=v.patient_id
    LEFT JOIN consultations c ON c.visit_id=v.id
    LEFT JOIN users d ON d.id=c.doctor_id
    WHERE r.visit_id=(SELECT visit_id FROM prescriptions WHERE id=?)
    ORDER BY r.id");
$st->execute([get_int('id')]);
$prescriptions = $st->fetchAll();
$prescription = $prescriptions[0] ?? null;
if (!$prescription) {
    http_response_code(404);
    exit('Prescription not found.');
}
if ($u['role'] === 'doctor' && (int)$prescription['doctor_id'] !== (int)$u['id']) {
    http_response_code(403);
    exit('This prescription belongs to another doctor’s patient.');
}

staff_header('Prescription', 'pharmacy.php');
?>
<div class="page-head no-print">
  <h1>Prescription</h1>
  <a href="<?= e(app_url('/staff/pharmacy.php')) ?>">&larr; Pharmacy</a>
</div>
<section class="panel prescription-print">
  <?php clinic_print_header(); ?>
  <h2>Prescription</h2>
  <div class="kv">
    <div><small>Patient</small><?= e($prescription['first_name'] . ' ' . $prescription['last_name']) ?></div>
    <div><small>Patient no.</small><?= e($prescription['patient_no']) ?></div>
    <div><small>Date of birth</small><?= e($prescription['date_of_birth']) ?></div>
    <div><small>Date prescribed</small><?= e(date('j M Y', strtotime($prescription['arrived_at']))) ?></div>
    <div><small>Prescriber</small><?= e($prescription['doctor_name'] ?: 'Clinic doctor') ?></div>
  </div>
  <?php if ($prescription['diagnosis']): ?><p><strong>Diagnosis:</strong> <?= nl2br(e($prescription['diagnosis'])) ?></p><?php endif; ?>
  <div class="table-wrap">
    <table>
      <tr><th>Medicine</th><th>Dosage</th><th>Frequency</th><th>Duration</th><th>Quantity</th></tr>
      <?php foreach ($prescriptions as $item): ?>
        <tr>
          <td><?= e($item['drug_name']) ?></td>
          <td><?= e($item['dosage']) ?></td>
          <td><?= e($item['frequency']) ?></td>
          <td><?= e($item['duration']) ?></td>
          <td><?= (int)$item['quantity'] ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  </div>
  <p class="prescription-signature">Prescriber signature: ______________________________</p>
  <?php clinic_print_footer(); ?>
</section>
<?php staff_footer();
