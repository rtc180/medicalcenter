<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception', 'nurse', 'doctor']);

// Action: patient arrives (existing patient) -> opens a visit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!in_array($u['role'], ['reception', 'admin'], true)) {
        http_response_code(403);
        exit('Only reception can check patients in.');
    }
    $pid = (int)post('patient_id');
    $st = db()->prepare('SELECT id FROM patients WHERE id=?');
    $st->execute([$pid]);
    if ($st->fetch()) {
        $vid = open_visit($pid, post('priority', 'routine'));
        audit('visit_opened', "visit $vid patient $pid");
        flash('success', 'Patient checked in and added to the nurse queue.');
    }
    redirect('/staff/queue.php');
}

$q = trim((string)($_GET['q'] ?? ''));
$illness = trim((string)($_GET['illness'] ?? ''));
$paymentMethod = (string)($_GET['payment_method'] ?? '');
$medicalAid = trim((string)($_GET['medical_aid'] ?? ''));
$aidStatus = (string)($_GET['aid_status'] ?? '');
$gender = (string)($_GET['gender'] ?? '');
$visitStatus = (string)($_GET['visit_status'] ?? '');
$registeredFrom = trim((string)($_GET['registered_from'] ?? ''));
$registeredTo = trim((string)($_GET['registered_to'] ?? ''));
$paymentMethods = db()->query('SELECT method_key, label FROM payment_methods ORDER BY sort_order, label')->fetchAll();
$filterError = '';
$conditions = [];
$params = [];

if ($q !== '') {
    $like = '%' . $q . '%';
    $conditions[] = "(p.first_name LIKE ? OR p.last_name LIKE ? OR CONCAT(p.first_name,' ',p.last_name) LIKE ?
        OR p.phone LIKE ? OR p.national_id LIKE ? OR p.patient_no LIKE ?)";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($illness !== '') {
    $conditions[] = "(p.chronic_conditions LIKE ? OR EXISTS (
        SELECT 1 FROM visits iv
        LEFT JOIN consultations ic ON ic.visit_id=iv.id
        LEFT JOIN triage it ON it.visit_id=iv.id
        WHERE iv.patient_id=p.id AND (ic.diagnosis LIKE ? OR it.chief_complaint LIKE ? OR it.health_condition LIKE ?)
    ))";
    $like = '%' . $illness . '%';
    array_push($params, $like, $like, $like, $like);
}
if (in_array($paymentMethod, array_column($paymentMethods, 'method_key'), true)) {
    $conditions[] = "EXISTS (
        SELECT 1 FROM visits pv JOIN invoices pi ON pi.visit_id=pv.id
        WHERE pv.patient_id=p.id AND pi.payment_method=?
    )";
    $params[] = $paymentMethod;
} else {
    if ($paymentMethod !== '') {
        $filterError = $filterError ?: 'Select a valid payment method.';
    }
    $paymentMethod = '';
}
if ($medicalAid !== '') {
    $like = '%' . $medicalAid . '%';
    $conditions[] = '(p.insurance_provider LIKE ? OR p.insurance_no LIKE ?)';
    array_push($params, $like, $like);
}
if ($aidStatus === 'has_aid') {
    $conditions[] = "(p.insurance_provider IS NOT NULL AND p.insurance_provider<>'')";
} elseif ($aidStatus === 'self_pay') {
    $conditions[] = "(p.insurance_provider IS NULL OR p.insurance_provider='')";
} else {
    if ($aidStatus !== '') {
        $filterError = $filterError ?: 'Select a valid medical aid status.';
    }
    $aidStatus = '';
}
if (in_array($gender, ['male', 'female', 'other'], true)) {
    $conditions[] = 'p.gender=?';
    $params[] = $gender;
} else {
    if ($gender !== '') {
        $filterError = $filterError ?: 'Select a valid sex.';
    }
    $gender = '';
}
if ($visitStatus === 'in_clinic') {
    $conditions[] = "EXISTS (SELECT 1 FROM visits sv WHERE sv.patient_id=p.id AND sv.status<>'departed')";
} elseif ($visitStatus === 'not_in_clinic') {
    $conditions[] = "NOT EXISTS (SELECT 1 FROM visits sv WHERE sv.patient_id=p.id AND sv.status<>'departed')";
} else {
    if ($visitStatus !== '') {
        $filterError = $filterError ?: 'Select a valid clinic status.';
    }
    $visitStatus = '';
}
$dateRangeValid = true;
if ($registeredFrom !== '' && !valid_date($registeredFrom)) {
    $filterError = $filterError ?: 'Enter a valid registration start date.';
    $registeredFrom = '';
    $dateRangeValid = false;
}
if ($registeredTo !== '' && !valid_date($registeredTo)) {
    $filterError = $filterError ?: 'Enter a valid registration end date.';
    $registeredTo = '';
    $dateRangeValid = false;
}
if ($registeredFrom !== '' && $registeredTo !== '' && $registeredFrom > $registeredTo) {
    $filterError = $filterError ?: 'Registration start date must be on or before the end date.';
    $dateRangeValid = false;
}
if ($dateRangeValid && $registeredFrom !== '') {
    $conditions[] = 'p.created_at>=?';
    $params[] = $registeredFrom . ' 00:00:00';
}
if ($dateRangeValid && $registeredTo !== '') {
    $conditions[] = 'p.created_at<?';
    $params[] = (new DateTime($registeredTo))->modify('+1 day')->format('Y-m-d 00:00:00');
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$st = db()->prepare("SELECT p.*,
    EXISTS(SELECT 1 FROM visits v WHERE v.patient_id=p.id AND v.status<>'departed') AS in_clinic,
    (SELECT c.diagnosis FROM visits dv JOIN consultations c ON c.visit_id=dv.id
        WHERE dv.patient_id=p.id ORDER BY dv.arrived_at DESC LIMIT 1) AS latest_diagnosis,
    (SELECT pm.label FROM visits pv JOIN invoices pi ON pi.visit_id=pv.id
        LEFT JOIN payment_methods pm ON pm.method_key=pi.payment_method
        WHERE pv.patient_id=p.id AND pi.payment_method IS NOT NULL
        ORDER BY pi.paid_at DESC LIMIT 1) AS latest_payment_method
    FROM patients p $where ORDER BY p.created_at DESC LIMIT 100");
$st->execute($params);
$results = $st->fetchAll();
$canCheckIn = in_array($u['role'], ['reception', 'admin'], true);
$hasAdvancedFilters = $illness !== '' || $paymentMethod !== '' || $medicalAid !== ''
    || $aidStatus !== '' || $gender !== '' || $visitStatus !== ''
    || $registeredFrom !== '' || $registeredTo !== '';
$activeFilterCount = count(array_filter([
    $illness, $paymentMethod, $medicalAid, $aidStatus, $gender,
    $visitStatus, $registeredFrom, $registeredTo,
], static fn(string $value): bool => $value !== ''));

staff_header('Patients', 'patients.php', 'patient-results');
?>
<div class="page-head">
  <h1>Patients</h1>
  <?php if ($canCheckIn): ?><a class="btn" href="<?= e(app_url('/staff/patient_new.php')) ?>">+ Register new patient</a><?php endif; ?>
</div>

<div class="panel">
  <h2>Search</h2>
  <form method="get" action="<?= e(app_url('/staff/patients.php')) ?>" class="patient-search-form">
    <div class="patient-search-row">
      <div class="patient-search-input"><label for="q">Name, phone, ID or patient number</label>
        <input id="q" name="q" value="<?= e($q) ?>" autofocus maxlength="80"></div>
      <button class="btn btn-dark" type="submit">Search</button>
    </div>
    <details class="patient-filters" <?= $hasAdvancedFilters ? 'open' : '' ?>>
      <summary class="btn btn-ghost">Filter options<?php if ($activeFilterCount): ?> <span class="filter-count" aria-label="<?= $activeFilterCount ?> active filters"><?= $activeFilterCount ?></span><?php endif; ?></summary>
      <div class="form-grid patient-filter-panel">
        <div><label for="illness">Illness, diagnosis or complaint</label><input id="illness" name="illness" value="<?= e($illness) ?>" maxlength="100"></div>
        <div><label for="payment_method">Payment method</label><select id="payment_method" name="payment_method">
          <option value="">Any payment method</option>
          <?php foreach ($paymentMethods as $method): ?>
            <option value="<?= e($method['method_key']) ?>" <?= $paymentMethod === $method['method_key'] ? 'selected' : '' ?>><?= e($method['label']) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div><label for="medical_aid">Medical aid provider or member no.</label><input id="medical_aid" name="medical_aid" value="<?= e($medicalAid) ?>" maxlength="120"></div>
        <div><label for="aid_status">Medical aid status</label><select id="aid_status" name="aid_status">
          <option value="">Any</option><option value="has_aid" <?= $aidStatus === 'has_aid' ? 'selected' : '' ?>>Has medical aid</option>
          <option value="self_pay" <?= $aidStatus === 'self_pay' ? 'selected' : '' ?>>Self-pay</option>
        </select></div>
        <div><label for="gender">Sex</label><select id="gender" name="gender"><option value="">Any</option>
          <?php foreach (['female' => 'Female', 'male' => 'Male', 'other' => 'Other'] as $value => $label): ?>
            <option value="<?= e($value) ?>" <?= $gender === $value ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select></div>
        <div><label for="visit_status">Clinic status</label><select id="visit_status" name="visit_status">
          <option value="">Any</option><option value="in_clinic" <?= $visitStatus === 'in_clinic' ? 'selected' : '' ?>>Currently in clinic</option>
          <option value="not_in_clinic" <?= $visitStatus === 'not_in_clinic' ? 'selected' : '' ?>>Not currently in clinic</option>
        </select></div>
        <div><label for="registered_from">Registered from</label><input id="registered_from" name="registered_from" type="date" value="<?= e($registeredFrom) ?>"></div>
        <div><label for="registered_to">Registered to</label><input id="registered_to" name="registered_to" type="date" value="<?= e($registeredTo) ?>"></div>
        <div class="patient-filter-actions"><button class="btn btn-dark" type="submit">Apply filters</button>
          <a class="btn btn-ghost" href="<?= e(app_url('/staff/patients.php')) ?>">Clear filters</a></div>
      </div>
    </details>
  </form>
</div>

<div class="panel" data-print-target="patient-results">
  <?php clinic_print_header(); ?>
  <h2>Patient records (<?= count($results) ?><?= count($results) === 100 ? '+' : '' ?>)</h2>
  <?php if ($filterError): ?><div class="alert alert-error" role="alert"><?= e($filterError) ?></div><?php endif; ?>
  <?php if (!$results): ?>
    <div class="alert alert-info">No patients match those filters.</div>
    <?php if ($canCheckIn && $q !== ''): ?><a class="btn" href="<?= e(app_url('/staff/patient_new.php?name=' . urlencode($q))) ?>">Register new patient</a><?php endif; ?>
  <?php else: ?>
  <div class="table-wrap"><table class="patient-results">
    <tr><th>No.</th><th>Name</th><th>Age / Sex</th><th>Phone</th><th>Medical aid</th><th>Latest diagnosis</th><th>Latest payment</th><th class="no-print">Action</th></tr>
    <?php foreach ($results as $p): ?>
      <tr>
        <td><?= e($p['patient_no']) ?></td>
        <td><a href="<?= e(app_url('/staff/patient_view.php?id=' . (int)$p['id'])) ?>"><?= e($p['first_name'] . ' ' . $p['last_name']) ?></a></td>
        <td><?= age_from_dob($p['date_of_birth']) ?> / <?= e(ucfirst($p['gender'])) ?></td>
        <td><?= e($p['phone']) ?></td>
        <td><?= e($p['insurance_provider'] ?: 'Self-pay') ?></td>
        <td><?= e($p['latest_diagnosis'] ?: '—') ?></td>
        <td><?= e($p['latest_payment_method'] ?: '—') ?></td>
        <td class="no-print">
          <div class="patient-row-actions">
          <?php if ($p['in_clinic']): ?>
            <?= badge('Already in clinic') ?>
          <?php elseif ($canCheckIn): ?>
            <form method="post" action="<?= e(app_url('/staff/patients.php')) ?>" class="patient-check-in">
              <?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int)$p['id'] ?>">
              <select name="priority" aria-label="Priority" class="field-auto">
                <option value="routine">Routine</option><option value="urgent">Urgent</option><option value="emergency">Emergency</option>
              </select>
              <button class="btn btn-sm" type="submit">Check in</button>
            </form>
          <?php endif; ?>
          <?php if ($canCheckIn): ?>
            <div class="patient-record-actions">
              <a class="btn btn-sm btn-ghost" href="<?= e(app_url('/staff/patient_edit.php?id=' . (int)$p['id'])) ?>">Edit</a>
              <form method="post" action="<?= e(app_url('/staff/patient_delete.php')) ?>" data-confirm="Delete this patient record? Records with visit or appointment history cannot be deleted. This cannot be undone.">
                <?= csrf_field() ?><input type="hidden" name="patient_id" value="<?= (int)$p['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Delete</button>
              </form>
            </div>
          <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </table></div>
  <?php endif; ?>
  <?php clinic_print_footer(); ?>
</div>
<?php staff_footer();
