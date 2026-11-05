<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception']);
$medicalAidTypes = active_medical_aid_types();

$f = [
    'first_name' => '', 'last_name' => '', 'national_id' => '', 'date_of_birth' => '', 'gender' => 'female',
    'phone' => '', 'email' => '', 'address' => '', 'next_of_kin' => '', 'next_of_kin_phone' => '',
    'insurance_provider' => '', 'insurance_no' => '', 'blood_group' => 'unknown',
    'allergies' => '', 'chronic_conditions' => '', 'medical_history' => '', 'priority' => 'routine',
];
$prefill = trim((string)($_GET['name'] ?? ''));
if ($prefill !== '' && !preg_match('/^[0-9+()\-\s]+$/', $prefill)) {
    $parts = preg_split('/\s+/', $prefill, 2);
    $f['first_name'] = mb_substr($parts[0], 0, 80);
    $f['last_name'] = mb_substr($parts[1] ?? '', 0, 80);
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($f as $k => $_) {
        $f[$k] = post($k, $f[$k]);
    }
    if ($f['first_name'] === '' || $f['last_name'] === '') { $errors[] = 'First and last name are required.'; }
    if (!valid_date($f['date_of_birth']) || $f['date_of_birth'] > date('Y-m-d')) { $errors[] = 'Enter a valid date of birth.'; }
    if (!in_array($f['gender'], ['male', 'female', 'other'], true)) { $errors[] = 'Choose a gender.'; }
    if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $f['phone'])) { $errors[] = 'Enter a valid phone number.'; }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email is not valid.'; }
    if ($f['insurance_provider'] !== '' && !in_array($f['insurance_provider'], $medicalAidTypes, true)) { $errors[] = 'Choose an active medical aid type.'; }
    if (!in_array($f['blood_group'], ['A+','A-','B+','B-','AB+','AB-','O+','O-','unknown'], true)) { $f['blood_group'] = 'unknown'; }
    if (!in_array($f['priority'], ['routine', 'urgent', 'emergency'], true)) { $f['priority'] = 'routine'; }

    if (!$errors) {
        // duplicate guard: same national ID, or same name + DOB
        $dup = db()->prepare('SELECT id FROM patients WHERE (national_id IS NOT NULL AND national_id<>\'\' AND national_id=?) OR (first_name=? AND last_name=? AND date_of_birth=?) LIMIT 1');
        $dup->execute([$f['national_id'], $f['first_name'], $f['last_name'], $f['date_of_birth']]);
        if ($row = $dup->fetch()) {
            $errors[] = 'This patient appears to be registered already.';
            $dupId = (int)$row['id'];
        }
    }
    if (!$errors) {
        $no = generate_patient_no();
        db()->prepare('INSERT INTO patients (patient_no,first_name,last_name,national_id,date_of_birth,gender,phone,email,address,
              next_of_kin,next_of_kin_phone,insurance_provider,insurance_no,blood_group,allergies,chronic_conditions,medical_history,created_by)
              VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([$no, $f['first_name'], $f['last_name'], $f['national_id'] ?: null, $f['date_of_birth'], $f['gender'], $f['phone'],
                $f['email'] ?: null, $f['address'] ?: null, $f['next_of_kin'] ?: null, $f['next_of_kin_phone'] ?: null,
                $f['insurance_provider'] ?: null, $f['insurance_no'] ?: null, $f['blood_group'],
                $f['allergies'] ?: null, $f['chronic_conditions'] ?: null, $f['medical_history'] ?: null, $u['id']]);
        $pid = (int)db()->lastInsertId();
        $vid = open_visit($pid, $f['priority']);
        audit('patient_registered', "patient $pid ($no) visit $vid");
        flash('success', "Patient registered as $no and sent to the nurse queue.");
        redirect('/staff/queue.php');
    }
}

staff_header('Register patient', 'patients.php');
?>
<div class="page-head"><h1>Register new patient</h1><a href="<?= e(app_url('/staff/patients.php')) ?>">&larr; Back to search</a></div>
<?php foreach ($errors as $er): ?><div class="alert alert-error" role="alert"><?= e($er) ?>
  <?php if (isset($dupId) && $er === 'This patient appears to be registered already.'): ?> <a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$dupId)) ?>">Open record</a><?php endif; ?></div><?php endforeach; ?>

<form method="post" action="<?= e(app_url('/staff/patient_new.php')) ?>" class="panel">
  <?= csrf_field() ?>
  <?php $showPriority = true; require __DIR__ . '/../includes/patient_form_fields.php'; ?>
  <p class="form-actions-compact"><button class="btn" type="submit">Register &amp; send to nurse queue</button></p>
</form>
<?php staff_footer();
