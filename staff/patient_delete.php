<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_login(['reception']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}
csrf_verify();

$id = (int)post('patient_id');
$patientQuery = db()->prepare('SELECT id FROM patients WHERE id=?');
$patientQuery->execute([$id]);
if (!$patientQuery->fetch()) {
    flash('error', 'Patient record not found.');
    redirect('/staff/patients.php');
}

// Keep patient history intact; records with visits or appointments cannot be deleted.
$history = db()->prepare(
    'SELECT
        EXISTS(SELECT 1 FROM visits WHERE patient_id=?) AS has_visits,
        EXISTS(SELECT 1 FROM appointments WHERE patient_id=?) AS has_appointments'
);
$history->execute([$id, $id]);
$linkedRecords = $history->fetch();
if ($linkedRecords['has_visits'] || $linkedRecords['has_appointments']) {
    flash('error', 'This patient has visit or appointment history and cannot be deleted.');
    redirect('/staff/patient_view.php?id=' . $id);
}

$delete = db()->prepare('DELETE FROM patients WHERE id=?');
$delete->execute([$id]);
if ($delete->rowCount() !== 1) {
    flash('error', 'Patient record could not be deleted.');
    redirect('/staff/patient_view.php?id=' . $id);
}

audit('patient_deleted', "patient $id");
flash('success', 'Patient record deleted.');
redirect('/staff/patients.php');
