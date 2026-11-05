<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['nurse', 'doctor', 'pharmacist']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!in_array($u['role'], ['nurse', 'pharmacist', 'admin'], true)) {
        http_response_code(403);
        exit('Only a nurse or administrator can dispense.');
    }
    $rid = (int)post('id');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $prescriptionQuery = $pdo->prepare('SELECT id, inventory_item_id, quantity, dispensed FROM prescriptions WHERE id=? FOR UPDATE');
        $prescriptionQuery->execute([$rid]);
        $prescription = $prescriptionQuery->fetch();
        $error = null;
        if (!$prescription || (int)$prescription['dispensed'] === 1) {
            $error = 'Prescription not found or already dispensed.';
        } elseif ($prescription['inventory_item_id'] !== null) {
            $itemQuery = $pdo->prepare('SELECT quantity_on_hand, unit, expiry_date FROM inventory_items WHERE id=? AND is_active=1 FOR UPDATE');
            $itemQuery->execute([$prescription['inventory_item_id']]);
            $item = $itemQuery->fetch();
            if (!$item) {
                $error = 'The linked inventory item is unavailable. Ask an administrator to review the stock record.';
            } elseif ($item['expiry_date'] && $item['expiry_date'] < date('Y-m-d')) {
                $error = 'The linked medicine is expired and cannot be dispensed.';
            } elseif ((float)$item['quantity_on_hand'] < (int)$prescription['quantity']) {
                $error = 'There is not enough stock to dispense this prescription.';
            } else {
                $before = (float)$item['quantity_on_hand'];
                $after = $before - (int)$prescription['quantity'];
                $pdo->prepare('UPDATE inventory_items SET quantity_on_hand=? WHERE id=?')->execute([$after, $prescription['inventory_item_id']]);
                $pdo->prepare("INSERT INTO inventory_movements (item_id,movement_type,quantity_delta,quantity_before,quantity_after,reference_type,reference_id,notes,recorded_by) VALUES (?,'issue',?,?,?,?,?,?,?)")
                    ->execute([$prescription['inventory_item_id'], -(int)$prescription['quantity'], $before, $after, 'prescription', $rid, 'Dispensed prescription', $u['id']]);
            }
        }
        if ($error) {
            $pdo->rollBack();
            flash('error', $error);
        } else {
            $pdo->prepare('UPDATE prescriptions SET dispensed=1, dispensed_by=?, dispensed_at=NOW() WHERE id=? AND dispensed=0')
                ->execute([$u['id'], $rid]);
            $pdo->commit();
            audit('rx_dispensed', "rx $rid");
            flash('success', 'Prescription marked as dispensed.');
        }
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $ex;
    }
    redirect('/staff/pharmacy.php');
}

$list = db()->query("SELECT r.*, v.status AS vstatus, p.first_name, p.last_name, p.patient_no, p.allergies
    , i.quantity_on_hand AS stock_on_hand, i.unit AS stock_unit
    FROM prescriptions r JOIN visits v ON v.id=r.visit_id JOIN patients p ON p.id=v.patient_id
    LEFT JOIN inventory_items i ON i.id=r.inventory_item_id
    WHERE r.dispensed=0 AND v.status IN ('disposition','billing','departed')
    ORDER BY v.arrived_at, r.id")->fetchAll();

staff_header('Pharmacy', 'pharmacy.php');
?>
<div class="page-head"><h1>Pharmacy: pending prescriptions</h1></div>
<div class="panel" data-refresh="45">
<?php if (!$list): ?><p>No pending prescriptions.</p><?php else: ?>
<div class="table-wrap"><table>
  <tr><th>Patient</th><th>Drug</th><th>Dose</th><th>Frequency</th><th>Duration</th><th>Qty</th><th>Stock</th><th>Allergies</th><th></th></tr>
  <?php foreach ($list as $r): ?>
  <tr>
    <td><?= e($r['first_name'] . ' ' . $r['last_name']) ?><br><small><?= e($r['patient_no']) ?></small></td>
    <td><?= e($r['drug_name']) ?></td><td><?= e($r['dosage']) ?></td><td><?= e($r['frequency']) ?></td><td><?= e($r['duration']) ?></td><td><?= (int)$r['quantity'] ?></td>
    <td><?= $r['inventory_item_id'] !== null ? e($r['stock_on_hand'] . ' ' . $r['stock_unit']) : 'Not stock-linked' ?></td>
    <td><?= $r['allergies'] ? '<span class="badge emergency">' . e(mb_substr($r['allergies'], 0, 50)) . '</span>' : '—' ?></td>
    <td><a class="btn btn-sm no-print" href="<?= e(app_url('/staff/prescription.php?id=' . (int)$r['id'])) ?>">Print prescription</a>
      <?php if (in_array($u['role'], ['nurse', 'pharmacist', 'admin'], true)): ?>
        <form method="post" action="<?= e(app_url('/staff/pharmacy.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" type="submit">Dispense</button></form>
      <?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
</div>
<?php staff_footer();
