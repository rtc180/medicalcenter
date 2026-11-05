<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['admin']);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = post('action');
    if ($action === 'save_clinic_print_info') {
        $clinicName = mb_substr(trim(post('clinic_name')), 0, 160);
        $address = mb_substr(trim(post('address')), 0, 255);
        $phone = mb_substr(trim(post('phone')), 0, 80);
        $email = mb_substr(trim(post('email')), 0, 160);
        $registration = mb_substr(trim(post('registration_no')), 0, 80);
        $footerNote = mb_substr(trim(post('footer_note')), 0, 255);
        if ($clinicName === '') {
            $errors[] = 'Clinic name is required for printed forms.';
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid clinic email address or leave it blank.';
        } else {
            db()->prepare('INSERT INTO clinic_settings (id,clinic_name,address,phone,email,registration_no,footer_note,updated_by)
                VALUES (1,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE clinic_name=VALUES(clinic_name), address=VALUES(address),
                phone=VALUES(phone), email=VALUES(email), registration_no=VALUES(registration_no), footer_note=VALUES(footer_note), updated_by=VALUES(updated_by)')
                ->execute([$clinicName, $address ?: null, $phone ?: null, $email ?: null, $registration ?: null, $footerNote ?: null, $u['id']]);
            audit('clinic_print_info_updated');
            flash('success', 'Printed form information updated.');
            redirect('/staff/settings.php');
        }
    } elseif ($action === 'add_payment_method') {
        $label = mb_substr(post('label'), 0, 80);
        $key = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $label), '-'));
        if ($label === '' || $key === '' || strlen($key) > 40) {
            $errors[] = 'Enter a payment method name using letters and numbers.';
        } else {
            try {
                db()->prepare('INSERT INTO payment_methods (method_key,label) VALUES (?,?)')->execute([$key, $label]);
                audit('payment_method_added', $key);
                flash('success', 'Payment method added.');
                redirect('/staff/settings.php');
            } catch (PDOException $ex) {
                error_log('Payment method could not be added: ' . $ex->getMessage());
                $errors[] = 'That payment method already exists or could not be saved.';
            }
        }
    } elseif ($action === 'toggle_payment_method') {
        $id = (int)post('id');
        $catalog = db();
        $status = $catalog->prepare('SELECT is_active FROM payment_methods WHERE id=?');
        $status->execute([$id]);
        $current = $status->fetchColumn();
        if ($current === false) {
            $errors[] = 'Payment method not found.';
        } elseif ((int)$current === 1 && (int)$catalog->query('SELECT COUNT(*) FROM payment_methods WHERE is_active=1')->fetchColumn() <= 1) {
            $errors[] = 'At least one payment method must remain active.';
        } else {
            $catalog->prepare('UPDATE payment_methods SET is_active=1-is_active WHERE id=?')->execute([$id]);
            audit('payment_method_toggled', "method $id");
            flash('success', 'Payment method updated.');
            redirect('/staff/settings.php');
        }
    } elseif ($action === 'add_medical_aid') {
        $name = mb_substr(post('name'), 0, 120);
        if ($name === '') {
            $errors[] = 'Enter a medical aid type.';
        } else {
            try {
                db()->prepare('INSERT INTO medical_aid_types (name) VALUES (?)')->execute([$name]);
                audit('medical_aid_type_added', $name);
                flash('success', 'Medical aid type added.');
                redirect('/staff/settings.php');
            } catch (PDOException $ex) {
                error_log('Medical aid type could not be added: ' . $ex->getMessage());
                $errors[] = 'That medical aid type already exists or could not be saved.';
            }
        }
    } elseif ($action === 'toggle_medical_aid') {
        $id = (int)post('id');
        $stmt = db()->prepare('UPDATE medical_aid_types SET is_active=1-is_active WHERE id=?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) {
            $errors[] = 'Medical aid type not found.';
        } else {
            audit('medical_aid_type_toggled', "type $id");
            flash('success', 'Medical aid type updated.');
            redirect('/staff/settings.php');
        }
    } else {
        $errors[] = 'Choose a valid settings action.';
    }
}

$paymentMethods = db()->query('SELECT id, method_key, label, is_active FROM payment_methods ORDER BY sort_order, label')->fetchAll();
$medicalAidTypes = db()->query('SELECT id, name, is_active FROM medical_aid_types ORDER BY name')->fetchAll();
$clinicSettings = clinic_print_settings();

staff_header('Settings', 'settings.php');
?>
<div class="page-head"><div><h1>Settings</h1><p>Manage clinic details, payment options, medical-aid types, and system backups.</p></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

<section class="panel">
  <h2>System backup &amp; data transfer</h2>
  <p>Create protected system backups or import and export clinic records.</p>
  <a class="btn" href="<?= e(app_url('/staff/data_transfer.php')) ?>">Open data backup</a>
</section>

<section class="panel">
  <h2>Printed form information</h2>
  <p>These details appear on patient documents, prescriptions, receipts, and reports printed or saved as PDF.</p>
  <form method="post" action="<?= e(app_url('/staff/settings.php')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_clinic_print_info">
    <div><label for="clinic_name">Clinic name</label><input id="clinic_name" name="clinic_name" maxlength="160" value="<?= e($clinicSettings['clinic_name']) ?>" required></div>
    <div><label for="clinic_phone">Phone</label><input id="clinic_phone" name="phone" maxlength="80" value="<?= e($clinicSettings['phone'] ?? '') ?>"></div>
    <div><label for="clinic_email">Email</label><input id="clinic_email" name="email" type="email" maxlength="160" value="<?= e($clinicSettings['email'] ?? '') ?>"></div>
    <div><label for="clinic_address">Address</label><input id="clinic_address" name="address" maxlength="255" value="<?= e($clinicSettings['address'] ?? '') ?>"></div>
    <div><label for="clinic_registration">Registration number</label><input id="clinic_registration" name="registration_no" maxlength="80" value="<?= e($clinicSettings['registration_no'] ?? '') ?>"></div>
    <div class="full"><label for="clinic_footer">Printed footer note</label><input id="clinic_footer" name="footer_note" maxlength="255" value="<?= e($clinicSettings['footer_note'] ?? '') ?>"></div>
    <div><button class="btn btn-dark" type="submit">Save printed information</button></div>
  </form>
</section>

<section class="panel">
  <h2>Payment methods</h2>
  <form method="post" action="<?= e(app_url('/staff/settings.php')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_payment_method">
    <div><label for="payment-label">New payment method</label><input id="payment-label" name="label" maxlength="80" required></div>
    <div><button class="btn btn-dark" type="submit">Add payment method</button></div>
  </form>
  <div class="table-wrap"><table><tr><th>Method</th><th>Status</th><th></th></tr>
    <?php foreach ($paymentMethods as $method): ?><tr>
      <td><?= e($method['label']) ?></td><td><?= $method['is_active'] ? 'Active' : 'Inactive' ?></td>
      <td><form method="post" action="<?= e(app_url('/staff/settings.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_payment_method"><input type="hidden" name="id" value="<?= (int)$method['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= $method['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
    </tr><?php endforeach; ?>
  </table></div>
</section>

<section class="panel">
  <h2>Medical aid types</h2>
  <form method="post" action="<?= e(app_url('/staff/settings.php')) ?>" class="form-grid">
    <?= csrf_field() ?><input type="hidden" name="action" value="add_medical_aid">
    <div><label for="medical-aid-name">New medical aid type</label><input id="medical-aid-name" name="name" maxlength="120" required></div>
    <div><button class="btn btn-dark" type="submit">Add medical aid type</button></div>
  </form>
  <div class="table-wrap"><table><tr><th>Type</th><th>Status</th><th></th></tr>
    <?php foreach ($medicalAidTypes as $type): ?><tr>
      <td><?= e($type['name']) ?></td><td><?= $type['is_active'] ? 'Active' : 'Inactive' ?></td>
      <td><form method="post" action="<?= e(app_url('/staff/settings.php')) ?>"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_medical_aid"><input type="hidden" name="id" value="<?= (int)$type['id'] ?>"><button class="btn btn-sm btn-ghost" type="submit"><?= $type['is_active'] ? 'Deactivate' : 'Activate' ?></button></form></td>
    </tr><?php endforeach; ?>
  </table></div>
</section>
<?php staff_footer();
