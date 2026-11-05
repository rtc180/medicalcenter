<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception']);
$paymentMethods = active_payment_methods();
$allPaymentMethods = db()->query('SELECT method_key, label FROM payment_methods ORDER BY label')->fetchAll();
$paymentLabels = array_column($allPaymentMethods, 'label', 'method_key');

$vid = get_int('visit');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!in_array($u['role'], ['reception', 'admin'], true)) {
        http_response_code(403);
        exit('Only reception can take payment.');
    }
    $vid = (int)post('visit_id');
    $method = post('payment_method');
    $insurance = (float)post('insurance_covered', '0');
    $paid = (float)post('amount_paid', '0');

    $st = db()->prepare("SELECT i.*, v.status AS vstatus FROM invoices i JOIN visits v ON v.id=i.visit_id WHERE i.visit_id=?");
    $st->execute([$vid]);
    $inv = $st->fetch();

    $err = null;
    if (!$inv || $inv['vstatus'] !== 'billing' || $inv['status'] !== 'unpaid') {
        $err = 'This invoice cannot be settled.';
    } elseif (!in_array($method, array_column($paymentMethods, 'method_key'), true)) {
        $err = 'Choose a payment method.';
    } elseif ($insurance < 0 || $paid < 0 || $insurance > (float)$inv['total']) {
        $err = 'Amounts are not valid.';
    } elseif (round($insurance + $paid, 2) < round((float)$inv['total'], 2)) {
        $err = 'Insurance cover plus amount paid must equal the full total (' . money($inv['total']) . ').';
    }
    if ($err) {
        flash('error', $err);
        redirect('/staff/billing.php?visit=' . $vid);
    }
    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE invoices SET insurance_covered=?, amount_paid=?, payment_method=?, status='paid', cashier_id=?, paid_at=NOW() WHERE visit_id=?")
        ->execute([$insurance, $paid, $method, $u['id'], $vid]);
    $pdo->prepare("UPDATE visits SET status='departed', departed_at=NOW() WHERE id=? AND status='billing'")->execute([$vid]);
    $pdo->commit();
    audit('payment_taken', "visit $vid total " . $inv['total']);
    flash('success', 'Payment recorded. Patient has departed.');
    redirect('/staff/billing.php?receipt=' . $vid);
}

$receipt = get_int('receipt');
staff_header('Billing', 'billing.php');

if ($vid || $receipt):
    $target = $vid ?: $receipt;
    $st = db()->prepare('SELECT i.*, v.status AS vstatus, v.arrived_at, p.first_name, p.last_name, p.patient_no, p.insurance_provider, p.insurance_no
        FROM invoices i JOIN visits v ON v.id=i.visit_id JOIN patients p ON p.id=v.patient_id WHERE i.visit_id=?');
    $st->execute([$target]);
    $inv = $st->fetch();
    if (!$inv) { echo '<div class="alert alert-error">Invoice not found.</div>'; staff_footer(); exit; }
    $rx = db()->prepare('SELECT * FROM prescriptions WHERE visit_id=?'); $rx->execute([$target]); $rx = $rx->fetchAll();
    $rf = db()->prepare('SELECT * FROM referrals WHERE visit_id=?'); $rf->execute([$target]); $rf = $rf->fetchAll();
    ?>
<div class="page-head"><h1><?= $inv['status'] === 'paid' ? 'Receipt' : 'Billing & checkout' ?></h1>
  <span class="no-print"><a href="<?= e(app_url('/staff/billing.php')) ?>">&larr; Back</a><?php if ($inv['status'] === 'paid'): ?> &nbsp; <button class="btn btn-sm" type="button" data-print data-print-target="billing-document">Print / Save receipt</button><?php endif; ?></span></div>
<div class="panel" data-print-target="billing-document">
  <?php clinic_print_header(); ?>
  <p><strong><?= e($inv['first_name'] . ' ' . $inv['last_name']) ?></strong> &middot; <?= e($inv['patient_no']) ?> &middot; <?= e(date('j M Y', strtotime($inv['arrived_at']))) ?></p>
  <div class="table-wrap"><table>
    <tr><th>Item</th><th>Amount</th></tr>
    <tr><td>Consultation</td><td><?= money($inv['consultation_fee']) ?></td></tr>
    <?php foreach ($rx as $r): ?><tr><td>Medication: <?= e($r['drug_name']) ?> × <?= (int)$r['quantity'] ?></td><td><?= money($r['quantity'] * $r['unit_price']) ?></td></tr><?php endforeach; ?>
    <?php foreach ($rf as $r): ?><tr><td>Referral (<?= e($r['type']) ?>): <?= e($r['destination']) ?></td><td><?= money($r['fee']) ?></td></tr><?php endforeach; ?>
    <tr><th>Total</th><th><?= money($inv['total']) ?></th></tr>
  </table></div>
  <?php if ($inv['status'] === 'paid'): ?>
    <p>Medical aid covered: <?= money($inv['insurance_covered']) ?> &middot; Patient paid: <?= money($inv['amount_paid']) ?> (<?= e($paymentLabels[$inv['payment_method']] ?? str_replace('_', ' ', (string)$inv['payment_method'])) ?>)</p>
    <p><?= badge('Paid', 'paid') ?> <?= e(date('j M Y H:i', strtotime($inv['paid_at']))) ?></p>
  <?php elseif ($inv['vstatus'] === 'billing' && in_array($u['role'], ['reception', 'admin'], true)): ?>
    <?php if ($inv['insurance_provider']): ?><p class="alert alert-info">Medical aid on file: <?= e($inv['insurance_provider'] . ' ' . $inv['insurance_no']) ?></p><?php endif; ?>
    <form method="post" action="<?= e(app_url('/staff/billing.php')) ?>" class="form-grid" data-confirm="Confirm payment and discharge the patient?">
      <?= csrf_field() ?><input type="hidden" name="visit_id" value="<?= (int)$target ?>">
      <div><label for="insurance_covered">Medical aid covered ($)</label><input id="insurance_covered" name="insurance_covered" type="number" step="0.01" min="0" value="0"></div>
      <div><label for="amount_paid">Patient pays ($)</label><input id="amount_paid" name="amount_paid" type="number" step="0.01" min="0" value="<?= e((string)$inv['total']) ?>"></div>
      <div><label for="payment_method">Method</label><select id="payment_method" name="payment_method" required>
        <?php foreach ($paymentMethods as $method): ?><option value="<?= e($method['method_key']) ?>"><?= e($method['label']) ?></option><?php endforeach; ?>
      </select></div>
      <div class="full"><button class="btn" type="submit">Take payment &amp; check out</button></div>
    </form>
  <?php endif; ?>
  <?php if ($inv['status'] === 'paid'): clinic_print_footer(); endif; ?>
</div>
<?php else:
    $list = db()->query("SELECT v.id, v.arrived_at, p.first_name, p.last_name, p.patient_no, i.total
        FROM visits v JOIN patients p ON p.id=v.patient_id LEFT JOIN invoices i ON i.visit_id=v.id
        WHERE v.status='billing' ORDER BY v.arrived_at")->fetchAll();
    $recent = db()->query("SELECT v.id, p.first_name, p.last_name, i.total, i.paid_at FROM invoices i JOIN visits v ON v.id=i.visit_id JOIN patients p ON p.id=v.patient_id
        WHERE i.status='paid' ORDER BY i.paid_at DESC LIMIT 10")->fetchAll();
    ?>
<div class="page-head"><h1>Billing &amp; checkout</h1></div>
<div class="panel" data-refresh="45"><h2>Waiting for payment at reception</h2>
  <?php if (!$list): ?><p>No patients waiting for billing.</p><?php else: ?>
  <div class="table-wrap"><table><tr><th>Patient</th><th>Total</th><th></th></tr>
  <?php foreach ($list as $r): ?><tr><td><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br><small><?= e($r['patient_no']) ?></small></td><td><?= money($r['total']) ?></td>
    <td><a class="btn btn-sm" href="<?= e(app_url('/staff/billing.php?visit=' . (int)$r['id'])) ?>">Open invoice</a></td></tr><?php endforeach; ?></table></div><?php endif; ?>
</div>
<div class="panel"><h2>Recent payments</h2>
  <?php if (!$recent): ?><p>None yet.</p><?php else: ?>
  <div class="table-wrap"><table><tr><th>Patient</th><th>Total</th><th>Paid at</th><th></th></tr>
  <?php foreach ($recent as $r): ?><tr><td><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td><td><?= money($r['total']) ?></td><td><?= e(date('j M H:i', strtotime($r['paid_at']))) ?></td>
    <td><a href="<?= e(app_url('/staff/billing.php?receipt=' . (int)$r['id'])) ?>">Receipt</a></td></tr><?php endforeach; ?></table></div><?php endif; ?>
</div>
<?php endif;
staff_footer();
