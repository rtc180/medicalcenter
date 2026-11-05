<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception']);

$id = get_int('id');
$patientQuery = db()->prepare('SELECT * FROM patients WHERE id=?');
$patientQuery->execute([$id]);
$patient = $patientQuery->fetch();
if (!$patient) {
    http_response_code(404);
    exit('Patient not found.');
}

$f = [
    'first_name' => $patient['first_name'], 'last_name' => $patient['last_name'],
    'national_id' => $patient['national_id'] ?? '', 'date_of_birth' => $patient['date_of_birth'],
    'gender' => $patient['gender'], 'phone' => $patient['phone'], 'email' => $patient['email'] ?? '',
    'address' => $patient['address'] ?? '', 'next_of_kin' => $patient['next_of_kin'] ?? '',
    'next_of_kin_phone' => $patient['next_of_kin_phone'] ?? '',
    'insurance_provider' => $patient['insurance_provider'] ?? '', 'insurance_no' => $patient['insurance_no'] ?? '',
    'blood_group' => $patient['blood_group'], 'allergies' => $patient['allergies'] ?? '',
    'chronic_conditions' => $patient['chronic_conditions'] ?? '',
    'medical_history' => $patient['medical_history'] ?? '',
];
$medicalAidTypes = active_medical_aid_types();
if ($f['insurance_provider'] !== '' && !in_array($f['insurance_provider'], $medicalAidTypes, true)) {
    $medicalAidTypes[] = $f['insurance_provider'];
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($f as $key => $_) {
        $f[$key] = post($key);
    }

    if ($f['first_name'] === '' || $f['last_name'] === '') { $errors[] = 'First and last name are required.'; }
    if (!valid_date($f['date_of_birth']) || $f['date_of_birth'] > date('Y-m-d')) { $errors[] = 'Enter a valid date of birth.'; }
    if (!in_array($f['gender'], ['male', 'female', 'other'], true)) { $errors[] = 'Choose a gender.'; }
    if (!preg_match('/^[0-9+()\-\s]{7,30}$/', $f['phone'])) { $errors[] = 'Enter a valid phone number.'; }
    if ($f['email'] !== '' && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Email is not valid.'; }
    if ($f['insurance_provider'] !== '' && !in_array($f['insurance_provider'], $medicalAidTypes, true)) { $errors[] = 'Choose an active medical aid type.'; }
    if (!in_array($f['blood_group'], ['A+','A-','B+','B-','AB+','AB-','O+','O-','unknown'], true)) { $f['blood_group'] = 'unknown'; }

    if (!$errors) {
        $duplicate = db()->prepare(
            "SELECT id FROM patients WHERE id<>? AND (
                (national_id IS NOT NULL AND national_id<>'' AND national_id=?)
                OR (first_name=? AND last_name=? AND date_of_birth=?)
            ) LIMIT 1"
        );
        $duplicate->execute([$id, $f['national_id'], $f['first_name'], $f['last_name'], $f['date_of_birth']]);
        if ($duplicate->fetch()) {
            $errors[] = 'Another patient already has this national ID or name and date of birth.';
        }
    }

    if (!$errors) {
        db()->prepare(
            'UPDATE patients SET first_name=?,last_name=?,national_id=?,date_of_birth=?,gender=?,phone=?,email=?,address=?,
                next_of_kin=?,next_of_kin_phone=?,insurance_provider=?,insurance_no=?,blood_group=?,allergies=?,
                chronic_conditions=?,medical_history=? WHERE id=?'
        )->execute([
            $f['first_name'], $f['last_name'], $f['national_id'] ?: null, $f['date_of_birth'], $f['gender'],
            $f['phone'], $f['email'] ?: null, $f['address'] ?: null, $f['next_of_kin'] ?: null,
            $f['next_of_kin_phone'] ?: null, $f['insurance_provider'] ?: null, $f['insurance_no'] ?: null,
            $f['blood_group'], $f['allergies'] ?: null, $f['chronic_conditions'] ?: null,
            $f['medical_history'] ?: null, $id,
        ]);
        audit('patient_updated', "patient $id");
        flash('success', 'Patient record updated.');
        redirect('/staff/patient_view.php?id=' . $id);
    }
}

staff_header('Edit patient', 'patients.php');
?>
<div class="page-head"><h1>Edit patient record</h1><a href="<?= e(app_url('/staff/patient_view.php?id=' . $id)) ?>">&larr; Patient record</a></div>
<?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>
<form method="post" action="<?= e(app_url('/staff/patient_edit.php?id=' . $id)) ?>" class="panel">
  <?= csrf_field() ?>
  <?php $showPriority = false; require __DIR__ . '/../includes/patient_form_fields.php'; ?>
  <p class="form-actions-compact"><button class="btn" type="submit">Save changes</button></p>
</form>
<?php staff_footer();
