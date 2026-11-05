<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['doctor']);

$vid = get_int('visit');
$load = function () use ($vid) {
    $st = db()->prepare('SELECT v.*, p.first_name, p.last_name, p.patient_no, p.date_of_birth, p.gender, p.allergies, p.chronic_conditions, p.medical_history,
        t.temperature_c, t.bp_systolic, t.bp_diastolic, t.pulse_bpm, t.resp_rate, t.spo2, t.weight_kg, t.height_cm, t.chief_complaint, t.health_condition
        FROM visits v JOIN patients p ON p.id=v.patient_id LEFT JOIN triage t ON t.visit_id=v.id WHERE v.id=?');
    $st->execute([$vid]);
    return $st->fetch();
};
$v = $load();
$inventoryItems = db()->query("SELECT id, name, selling_price FROM inventory_items
    WHERE is_active=1 AND category='medicine' AND quantity_on_hand>0 AND (expiry_date IS NULL OR expiry_date>=CURDATE()) ORDER BY name")->fetchAll();
if (!$v || !in_array($v['status'], ['consultation', 'disposition'], true)) {
    flash('error', 'That visit is not in consultation.');
    redirect('/staff/queue.php');
}
if ($u['role'] !== 'admin' && (int)$v['doctor_id'] !== $u['id']) {
    http_response_code(403);
    exit('This patient is being seen by another doctor.');
}

$errors = [];
$self = app_url('/staff/consult.php?visit=' . $vid);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');
    $pdo = db();

    if ($action === 'save_consult' && $v['status'] === 'consultation') {
        if (post('diagnosis') === '') {
            $errors[] = 'Diagnosis is required.';
        } else {
            $pdo->prepare('INSERT INTO consultations (visit_id,examination,diagnosis,treatment_plan,lab_requested,imaging_requested,doctor_id) VALUES (?,?,?,?,?,?,?)')
                ->execute([$vid, post('examination') ?: null, post('diagnosis'), post('treatment_plan') ?: null,
                    isset($_POST['lab_requested']) ? 1 : 0, isset($_POST['imaging_requested']) ? 1 : 0, $u['id']]);
            $pdo->prepare("UPDATE visits SET status='disposition' WHERE id=?")->execute([$vid]);
            audit('consult_saved', "visit $vid");
            flash('success', 'Consultation saved. Choose what happens next.');
            redirect($self);
        }
    } elseif ($v['status'] === 'disposition') {
        if ($action === 'add_rx') {
            $drug = post('drug_name'); $dose = post('dosage'); $freq = post('frequency'); $dur = post('duration');
            $qty = (int)post('quantity', '1'); $price = (float)post('unit_price', '0');
            $inventoryItemId = (int)post('inventory_item_id');
            if ($inventoryItemId > 0) {
                $stockItem = $pdo->prepare("SELECT name, selling_price FROM inventory_items WHERE id=? AND is_active=1 AND category='medicine'");
                $stockItem->execute([$inventoryItemId]);
                $selectedItem = $stockItem->fetch();
                if (!$selectedItem) {
                    $errors[] = 'Choose an active medicine from inventory.';
                } else {
                    $drug = $selectedItem['name'];
                    $price = (float)$selectedItem['selling_price'];
                }
            }
            if (!$errors && ($drug === '' || $dose === '' || $freq === '' || $dur === '' || $qty < 1 || $qty > 999 || $price < 0 || $price > 100000)) {
                $errors[] = 'Complete all prescription fields with sensible values.';
            } elseif (!$errors) {
                $pdo->prepare('INSERT INTO prescriptions (visit_id,inventory_item_id,drug_name,dosage,frequency,duration,quantity,unit_price) VALUES (?,?,?,?,?,?,?,?)')
                    ->execute([$vid, $inventoryItemId ?: null, $drug, $dose, $freq, $dur, $qty, $price]);
                audit('rx_added', "visit $vid");
                flash('success', 'Prescription added for pharmacy dispensing.');
                redirect($self);
            }
        } elseif ($action === 'add_referral') {
            $type = post('type'); $dest = post('destination'); $reason = post('reason'); $fee = (float)post('fee', '0');
            if (!in_array($type, ['lab', 'diagnostics', 'specialist'], true) || $dest === '' || $reason === '' || $fee < 0 || $fee > 100000) {
                $errors[] = 'Complete all referral fields.';
            } else {
                $pdo->prepare('INSERT INTO referrals (visit_id,type,destination,reason,fee) VALUES (?,?,?,?,?)')->execute([$vid, $type, $dest, $reason, $fee]);
                audit('referral_added', "visit $vid");
                flash('success', 'Referral recorded.');
                redirect($self);
            }
        } elseif ($action === 'add_followup') {
            $when = str_replace('T', ' ', post('scheduled_for'));
            $dt = DateTime::createFromFormat('Y-m-d H:i', $when);
            if (!$dt || $dt <= new DateTime()) {
                $errors[] = 'Choose a follow-up date and time in the future.';
            } else {
                $pdo->prepare('INSERT INTO appointments (patient_id,visit_id,doctor_id,scheduled_for,reason) VALUES (?,?,?,?,?)')
                    ->execute([$v['patient_id'], $vid, $u['id'], $dt->format('Y-m-d H:i:00'), post('reason') ?: 'Follow-up']);
                audit('followup_added', "visit $vid");
                flash('success', 'Follow-up appointment arranged.');
                redirect($self);
            }
        } elseif ($action === 'remove_rx') {
            $pdo->prepare('DELETE FROM prescriptions WHERE id=? AND visit_id=? AND dispensed=0')->execute([(int)post('id'), $vid]);
            redirect($self);
        } elseif ($action === 'finish') {
            rebuild_invoice($vid);
            $pdo->prepare("UPDATE visits SET status='billing' WHERE id=? AND status='disposition'")->execute([$vid]);
            audit('sent_to_billing', "visit $vid");
            flash('success', 'Patient sent to billing.');
            redirect('/staff/queue.php');
        }
    }
}

$cons = null; $rx = []; $refs = []; $fups = [];
if ($v['status'] === 'disposition') {
    $s = db()->prepare('SELECT * FROM consultations WHERE visit_id=?'); $s->execute([$vid]); $cons = $s->fetch();
    $s = db()->prepare('SELECT * FROM prescriptions WHERE visit_id=?'); $s->execute([$vid]); $rx = $s->fetchAll();
    $s = db()->prepare('SELECT * FROM referrals WHERE visit_id=?'); $s->execute([$vid]); $refs = $s->fetchAll();
    $s = db()->prepare('SELECT * FROM appointments WHERE visit_id=?'); $s->execute([$vid]); $fups = $s->fetchAll();
}

staff_header('Consultation', 'queue.php');
?>
<div class="page-head"><h1>Consultation: <?= e($v['first_name'] . ' ' . $v['last_name']) ?></h1><a href="<?= e(app_url('/staff/queue.php')) ?>">&larr; Queue</a></div>
<?= tracker($v['status']) ?>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?></div><?php endforeach; ?>

<div class="panel">
  <h2>Patient &amp; triage summary</h2>
  <div class="kv">
    <div><small>Age / sex</small><?= age_from_dob($v['date_of_birth']) ?> / <?= e(ucfirst($v['gender'])) ?></div>
    <div><small>Allergies</small><strong class="clinical-alert-text"><?= e($v['allergies'] ?: 'None recorded') ?></strong></div>
    <div><small>Chronic conditions</small><?= e($v['chronic_conditions'] ?: 'None recorded') ?></div>
    <div><small>Temp</small><?= e($v['temperature_c'] ?? '—') ?> °C</div>
    <div><small>BP</small><?= e((string)($v['bp_systolic'] ?? '—')) ?>/<?= e((string)($v['bp_diastolic'] ?? '—')) ?></div>
    <div><small>Pulse</small><?= e((string)($v['pulse_bpm'] ?? '—')) ?></div>
    <div><small>SpO₂</small><?= e((string)($v['spo2'] ?? '—')) ?>%</div>
    <div><small>Weight</small><?= e((string)($v['weight_kg'] ?? '—')) ?> kg</div>
  </div>
  <p><strong>Chief complaint:</strong> <?= e($v['chief_complaint'] ?? '—') ?></p>
  <?php if ($v['health_condition']): ?><p><strong>Nurse notes:</strong> <?= e($v['health_condition']) ?></p><?php endif; ?>
  <?php if ($v['medical_history']): ?><p><strong>History:</strong> <?= e($v['medical_history']) ?></p><?php endif; ?>
</div>

<?php if ($v['status'] === 'consultation'): ?>
<form method="post" action="<?= e($self) ?>" class="panel">
  <?= csrf_field() ?><input type="hidden" name="action" value="save_consult">
  <h2>Medical consultation &amp; exam</h2>
  <div class="form-grid">
    <div class="full"><label for="examination">Examination findings</label><textarea id="examination" name="examination" maxlength="4000"></textarea></div>
    <div class="full"><label class="req" for="diagnosis">Diagnosis</label><textarea id="diagnosis" name="diagnosis" maxlength="2000" required></textarea></div>
    <div class="full"><label for="treatment_plan">Treatment plan</label><textarea id="treatment_plan" name="treatment_plan" maxlength="4000"></textarea></div>
    <div><label><input type="checkbox" name="lab_requested"> Lab tests ordered</label></div>
    <div><label><input type="checkbox" name="imaging_requested"> Imaging ordered</label></div>
  </div>
  <p class="form-actions-compact"><button class="btn" type="submit">Save &amp; continue to treatment decision</button></p>
</form>

<?php else: ?>
<div class="panel"><h2>Diagnosis</h2><p><?= nl2br(e($cons['diagnosis'] ?? '')) ?></p>
  <?php if (!empty($cons['treatment_plan'])): ?><p><strong>Plan:</strong> <?= nl2br(e($cons['treatment_plan'])) ?></p><?php endif; ?>
  <?php if (!empty($cons['lab_requested'])): ?><?= badge('Lab ordered') ?><?php endif; ?>
  <?php if (!empty($cons['imaging_requested'])): ?><?= badge('Imaging ordered') ?><?php endif; ?></div>

<div class="panel">
  <h2>Needs medication? &rarr; Prescription / pharmacy</h2>
  <?php if ($rx): ?><div class="table-wrap"><table><tr><th>Drug</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Qty</th><th>Price</th><th class="no-print">Actions</th></tr>
    <?php foreach ($rx as $r): ?><tr><td><?= e($r['drug_name']) ?></td><td><?= e($r['dosage']) ?></td><td><?= e($r['frequency']) ?></td><td><?= e($r['duration']) ?></td>
      <td><?= (int)$r['quantity'] ?></td><td><?= money($r['unit_price']) ?></td>
      <td class="no-print"><a class="btn btn-sm" href="<?= e(app_url('/staff/prescription.php?id=' . (int)$r['id'])) ?>">Print prescription</a>
        <?php if (!$r['dispensed']): ?><form method="post" action="<?= e($self) ?>" data-confirm="Remove this prescription?"><?= csrf_field() ?><input type="hidden" name="action" value="remove_rx"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm btn-danger" type="submit">Remove</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?></table></div><?php endif; ?>
  <form method="post" action="<?= e($self) ?>" class="form-grid prescription-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_rx">
    <div><label for="inventory_item_id">Medicine stock item (optional)</label><select id="inventory_item_id" name="inventory_item_id"><option value="0">Not linked to inventory</option><?php foreach ($inventoryItems as $item): ?><option value="<?= (int)$item['id'] ?>"><?= e($item['name']) ?> (<?= money($item['selling_price']) ?> each)</option><?php endforeach; ?></select><small>Selecting a stock item fills the prescription name and price from the catalog.</small></div>
    <div><label for="drug_name">Drug</label><input id="drug_name" name="drug_name" maxlength="160" required></div>
    <div><label for="dosage">Dosage</label><input id="dosage" name="dosage" maxlength="80" placeholder="500 mg" required></div>
    <div><label for="frequency">Frequency</label><input id="frequency" name="frequency" maxlength="80" placeholder="3 times daily" required></div>
    <div><label for="duration">Duration</label><input id="duration" name="duration" maxlength="80" placeholder="5 days" required></div>
    <div><label for="quantity">Quantity</label><input id="quantity" name="quantity" type="number" min="1" max="999" value="1" required></div>
    <div><label for="unit_price">Unit price ($)</label><input id="unit_price" name="unit_price" type="number" step="0.01" min="0" value="0" required></div>
    <div><button class="btn btn-dark" type="submit">Add prescription</button></div>
  </form>
</div>

<div class="panel">
  <h2>Needs specialist or lab work? &rarr; Refer</h2>
  <?php foreach ($refs as $r): ?><p><?= badge($r['type']) ?> <strong><?= e($r['destination']) ?></strong>: <?= e($r['reason']) ?> (<?= money($r['fee']) ?>)</p><?php endforeach; ?>
  <form method="post" action="<?= e($self) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_referral">
    <div><label for="type">Type</label><select id="type" name="type"><option value="lab">Laboratory</option><option value="diagnostics">Diagnostics / imaging</option><option value="specialist">Specialist</option></select></div>
    <div><label for="destination">Destination</label><input id="destination" name="destination" maxlength="160" required></div>
    <div><label for="fee">Fee charged here ($)</label><input id="fee" name="fee" type="number" step="0.01" min="0" value="0"></div>
    <div class="full"><label for="reason">Reason / tests requested</label><textarea id="reason" name="reason" maxlength="2000" required></textarea></div>
    <div><button class="btn btn-dark" type="submit">Add referral</button></div>
  </form>
</div>

<div class="panel">
  <h2>Needs follow-up? &rarr; Arrange appointment</h2>
  <?php foreach ($fups as $a): ?><p><?= badge('Scheduled') ?> <?= e(date('D j M Y, H:i', strtotime($a['scheduled_for']))) ?> &ndash; <?= e($a['reason']) ?></p><?php endforeach; ?>
  <form method="post" action="<?= e($self) ?>" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="add_followup">
    <div><label for="scheduled_for">Date &amp; time</label><input id="scheduled_for" type="datetime-local" name="scheduled_for" required></div>
    <div><label for="freason">Reason</label><input id="freason" name="reason" maxlength="255"></div>
    <div><button class="btn btn-dark" type="submit">Arrange follow-up</button></div>
  </form>
</div>

<form method="post" action="<?= e($self) ?>" class="panel" data-confirm="Send this patient to billing? You can no longer change the treatment decision."><?= csrf_field() ?><input type="hidden" name="action" value="finish">
  <p class="form-intro-compact">When the treatment decision is complete, send the patient to reception for payment.</p>
  <button class="btn" type="submit">Complete &amp; send to billing</button>
</form>
<?php endif; ?>
<?php staff_footer();
