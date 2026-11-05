<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['nurse']);

$vid = get_int('visit');
$st = db()->prepare('SELECT v.*, p.first_name, p.last_name, p.patient_no, p.date_of_birth, p.allergies, p.chronic_conditions
    FROM visits v JOIN patients p ON p.id=v.patient_id WHERE v.id=?');
$st->execute([$vid]);
$v = $st->fetch();
if (!$v || $v['status'] !== 'triage') {
    flash('error', 'That visit is not in triage.');
    redirect('/staff/queue.php');
}

$errors = [];
$t = ['temperature_c' => '', 'bp_systolic' => '', 'bp_diastolic' => '', 'pulse_bpm' => '', 'resp_rate' => '', 'spo2' => '',
      'weight_kg' => '', 'height_cm' => '', 'chief_complaint' => '', 'health_condition' => '', 'priority' => $v['priority']];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($t as $k => $_) { $t[$k] = post($k); }
    $ranges = ['temperature_c' => [30, 45], 'bp_systolic' => [40, 300], 'bp_diastolic' => [20, 200], 'pulse_bpm' => [20, 300],
               'resp_rate' => [4, 80], 'spo2' => [30, 100], 'weight_kg' => [0.3, 400], 'height_cm' => [20, 260]];
    foreach ($ranges as $k => [$lo, $hi]) {
        if ($t[$k] !== '' && (!is_numeric($t[$k]) || (float)$t[$k] < $lo || (float)$t[$k] > $hi)) {
            $errors[] = "Value for " . str_replace('_', ' ', $k) . " must be between $lo and $hi.";
        }
    }
    if ($t['chief_complaint'] === '') { $errors[] = 'Chief complaint is required.'; }
    if (!in_array($t['priority'], ['routine', 'urgent', 'emergency'], true)) { $t['priority'] = 'routine'; }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO triage (visit_id,temperature_c,bp_systolic,bp_diastolic,pulse_bpm,resp_rate,spo2,weight_kg,height_cm,chief_complaint,health_condition,recorded_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$vid, num_or_null($t['temperature_c']), num_or_null($t['bp_systolic']), num_or_null($t['bp_diastolic']),
                    num_or_null($t['pulse_bpm']), num_or_null($t['resp_rate']), num_or_null($t['spo2']),
                    num_or_null($t['weight_kg']), num_or_null($t['height_cm']), $t['chief_complaint'], $t['health_condition'] ?: null, $u['id']]);
            $pdo->prepare("UPDATE visits SET status='waiting_doctor', priority=? WHERE id=? AND status='triage'")->execute([$t['priority'], $vid]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            error_log($ex->getMessage());
            $errors[] = 'Could not save triage (it may already be recorded).';
        }
        if (!$errors) {
            audit('triage_recorded', "visit $vid");
            flash('success', 'Triage saved. Patient is now waiting for a doctor.');
            redirect('/staff/queue.php');
        }
    }
}

staff_header('Triage & vitals', 'queue.php');
?>
<div class="page-head"><h1>Triage &amp; vitals</h1><a href="<?= e(app_url('/staff/queue.php')) ?>">&larr; Queue</a></div>
<?= tracker($v['status']) ?>
<div class="panel kv">
  <div><small>Patient</small><strong><?= e($v['first_name'] . ' ' . $v['last_name']) ?></strong></div>
  <div><small>Number</small><?= e($v['patient_no']) ?></div>
  <div><small>Age</small><?= age_from_dob($v['date_of_birth']) ?> years</div>
  <div><small>Allergies</small><?= e($v['allergies'] ?: 'None recorded') ?></div>
  <div><small>Chronic conditions</small><?= e($v['chronic_conditions'] ?: 'None recorded') ?></div>
</div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>
<form method="post" action="<?= e(app_url('/staff/triage.php?visit=' . $vid)) ?>" class="panel">
  <?= csrf_field() ?>
  <h2>Vital signs</h2>
  <div class="form-grid">
    <div><label for="temperature_c">Temperature (°C)</label><input id="temperature_c" name="temperature_c" inputmode="decimal" value="<?= e($t['temperature_c']) ?>"></div>
    <div><label for="bp_systolic">BP systolic</label><input id="bp_systolic" name="bp_systolic" inputmode="numeric" value="<?= e($t['bp_systolic']) ?>"></div>
    <div><label for="bp_diastolic">BP diastolic</label><input id="bp_diastolic" name="bp_diastolic" inputmode="numeric" value="<?= e($t['bp_diastolic']) ?>"></div>
    <div><label for="pulse_bpm">Pulse (bpm)</label><input id="pulse_bpm" name="pulse_bpm" inputmode="numeric" value="<?= e($t['pulse_bpm']) ?>"></div>
    <div><label for="resp_rate">Respiratory rate</label><input id="resp_rate" name="resp_rate" inputmode="numeric" value="<?= e($t['resp_rate']) ?>"></div>
    <div><label for="spo2">SpO₂ (%)</label><input id="spo2" name="spo2" inputmode="numeric" value="<?= e($t['spo2']) ?>"></div>
    <div><label for="weight_kg">Weight (kg)</label><input id="weight_kg" name="weight_kg" inputmode="decimal" value="<?= e($t['weight_kg']) ?>"></div>
    <div><label for="height_cm">Height (cm)</label><input id="height_cm" name="height_cm" inputmode="decimal" value="<?= e($t['height_cm']) ?>"></div>
  </div>
  <h2 class="assessment-heading">Assessment</h2>
  <div class="form-grid">
    <div class="full"><label class="req" for="chief_complaint">Chief complaint</label><textarea id="chief_complaint" name="chief_complaint" maxlength="2000" required><?= e($t['chief_complaint']) ?></textarea></div>
    <div class="full"><label for="health_condition">Health condition notes</label><textarea id="health_condition" name="health_condition" maxlength="2000"><?= e($t['health_condition']) ?></textarea></div>
    <div><label for="priority">Priority after assessment</label><select id="priority" name="priority">
      <?php foreach (['routine', 'urgent', 'emergency'] as $p): ?><option value="<?= $p ?>" <?= $t['priority'] === $p ? 'selected' : '' ?>><?= ucfirst($p) ?></option><?php endforeach; ?></select></div>
  </div>
  <p class="form-actions-compact"><button class="btn" type="submit">Save &amp; send to doctor queue</button></p>
</form>
<?php staff_footer();
