<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['nurse', 'doctor', 'pharmacist']);
$canManage = $u['role'] === 'admin';
$categories = ['medicine' => 'Medicine', 'consumable' => 'Consumable', 'equipment' => 'Equipment', 'other' => 'Other'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (!$canManage) {
        http_response_code(403);
        exit('Only an administrator can change inventory records.');
    }

    $action = post('action');
    if ($action === 'save_item') {
        $id = (int)post('id');
        $name = mb_substr(trim(post('name')), 0, 160);
        $sku = mb_substr(trim(post('sku')), 0, 40);
        $generic = mb_substr(trim(post('generic_name')), 0, 160);
        $category = post('category');
        $unit = mb_substr(trim(post('unit')), 0, 40);
        $reorder = post('reorder_level', '0');
        $cost = post('unit_cost', '0');
        $price = post('selling_price', '0');
        $expiry = post('expiry_date');
        if ($name === '' || !isset($categories[$category]) || $unit === '') {
            $errors[] = 'Enter an item name, category, and unit.';
        } elseif (!is_numeric($reorder) || !is_numeric($cost) || !is_numeric($price) ||
            (float)$reorder < 0 || (float)$reorder > 1000000000 ||
            (float)$cost < 0 || (float)$cost > 99999999.99 ||
            (float)$price < 0 || (float)$price > 99999999.99) {
            $errors[] = 'Stock level and prices must be valid non-negative numbers.';
        } elseif ($expiry !== '' && !valid_date($expiry)) {
            $errors[] = 'Enter a valid expiry date or leave it blank.';
        } else {
            try {
                if ($id > 0) {
                    $stmt = db()->prepare('UPDATE inventory_items SET sku=?, name=?, generic_name=?, category=?, unit=?, reorder_level=?, unit_cost=?, selling_price=?, expiry_date=? WHERE id=?');
                    $stmt->execute([$sku ?: null, $name, $generic ?: null, $category, $unit, $reorder, $cost, $price, $expiry ?: null, $id]);
                    if (!$stmt->rowCount()) {
                        $exists = db()->prepare('SELECT 1 FROM inventory_items WHERE id=?');
                        $exists->execute([$id]);
                        if (!$exists->fetchColumn()) {
                            $errors[] = 'Inventory item not found.';
                        }
                    }
                    $event = 'inventory_item_updated';
                } else {
                    db()->prepare('INSERT INTO inventory_items (sku,name,generic_name,category,unit,reorder_level,unit_cost,selling_price,expiry_date,created_by) VALUES (?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$sku ?: null, $name, $generic ?: null, $category, $unit, $reorder, $cost, $price, $expiry ?: null, $u['id']]);
                    $event = 'inventory_item_added';
                }
                if (!$errors) {
                    audit($event, $id > 0 ? "item $id" : $name);
                    flash('success', $id > 0 ? 'Inventory item updated.' : 'Inventory item added.');
                    redirect('/staff/inventory.php');
                }
            } catch (PDOException $ex) {
                error_log('Inventory item could not be saved: ' . $ex->getMessage());
                $errors[] = 'The item could not be saved. Check that its SKU is unique.';
            }
        }
    } elseif ($action === 'record_stock') {
        $itemId = (int)post('item_id');
        $type = post('movement_type');
        $amount = post('quantity');
        $notes = mb_substr(trim(post('notes')), 0, 255);
        if ($itemId < 1 || !in_array($type, ['receipt', 'issue', 'adjustment'], true) ||
            !is_numeric($amount) || (float)$amount < 0 || (float)$amount > 1000000000) {
            $errors[] = 'Choose an item and enter a valid quantity.';
        } elseif ($type !== 'adjustment' && (float)$amount <= 0) {
            $errors[] = 'Received or issued quantities must be greater than zero.';
        } else {
            $pdo = db();
            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare('SELECT quantity_on_hand FROM inventory_items WHERE id=? AND is_active=1 FOR UPDATE');
                $lock->execute([$itemId]);
                $current = $lock->fetchColumn();
                if ($current === false) {
                    $errors[] = 'Active inventory item not found.';
                } else {
                    $before = (float)$current;
                    $quantity = (float)$amount;
                    $delta = match ($type) {
                        'receipt' => $quantity,
                        'issue' => -$quantity,
                        default => $quantity - $before,
                    };
                    $after = round($before + $delta, 2);
                    if ($type === 'issue' && $after < 0) {
                        $errors[] = 'There is not enough stock to issue that quantity.';
                    } elseif (abs($delta) < 0.00001) {
                        $errors[] = 'The adjusted quantity is already the current stock level.';
                    } else {
                        $pdo->prepare('UPDATE inventory_items SET quantity_on_hand=? WHERE id=?')->execute([$after, $itemId]);
                        $pdo->prepare('INSERT INTO inventory_movements (item_id,movement_type,quantity_delta,quantity_before,quantity_after,notes,recorded_by) VALUES (?,?,?,?,?,?,?)')
                            ->execute([$itemId, $type, $delta, $before, $after, $notes ?: null, $u['id']]);
                    }
                }
                if ($errors) {
                    $pdo->rollBack();
                } else {
                    $pdo->commit();
                    audit('inventory_stock_' . $type, "item $itemId; delta $delta");
                    flash('success', 'Stock movement recorded.');
                    redirect('/staff/inventory.php');
                }
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $ex;
            }
        }
    } elseif ($action === 'toggle_item') {
        $id = (int)post('id');
        $stmt = db()->prepare('UPDATE inventory_items SET is_active=1-is_active WHERE id=?');
        $stmt->execute([$id]);
        if (!$stmt->rowCount()) {
            $errors[] = 'Inventory item not found or unchanged.';
        } else {
            audit('inventory_item_toggled', "item $id");
            flash('success', 'Inventory item status updated.');
            redirect('/staff/inventory.php');
        }
    } else {
        $errors[] = 'Choose a valid inventory action.';
    }
}

$items = db()->query('SELECT * FROM inventory_items ORDER BY is_active DESC, name')->fetchAll();
$movements = db()->query('SELECT m.*, i.name AS item_name, i.unit, u.full_name AS recorded_by_name
    FROM inventory_movements m JOIN inventory_items i ON i.id=m.item_id
    LEFT JOIN users u ON u.id=m.recorded_by ORDER BY m.id DESC LIMIT 50')->fetchAll();

staff_header('Inventory', 'inventory.php');
?>
<div class="page-head"><div><h1>Inventory</h1><p>Monitor medicines and supplies, stock levels, and expiry dates.</p></div></div>
<?php foreach ($errors as $error): ?><div class="alert alert-error" role="alert"><?= e($error) ?></div><?php endforeach; ?>

<?php if ($canManage): ?>
<div class="inventory-admin-grid">
  <section class="panel">
    <h2>Add inventory item</h2>
    <form method="post" action="<?= e(app_url('/staff/inventory.php')) ?>" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="save_item">
      <div><label for="item-name">Item name</label><input id="item-name" name="name" maxlength="160" required></div>
      <div><label for="item-generic">Generic / description</label><input id="item-generic" name="generic_name" maxlength="160"></div>
      <div><label for="item-sku">SKU / batch code</label><input id="item-sku" name="sku" maxlength="40"></div>
      <div><label for="item-category">Category</label><select id="item-category" name="category"><?php foreach ($categories as $key => $label): ?><option value="<?= e($key) ?>"><?= e($label) ?></option><?php endforeach; ?></select></div>
      <div><label for="item-unit">Stock unit</label><input id="item-unit" name="unit" maxlength="40" value="unit" required></div>
      <div><label for="item-reorder">Reorder level</label><input id="item-reorder" name="reorder_level" type="number" step="0.01" min="0" value="0" required></div>
      <div><label for="item-cost">Unit cost ($)</label><input id="item-cost" name="unit_cost" type="number" step="0.01" min="0" value="0" required></div>
      <div><label for="item-price">Dispensing price ($)</label><input id="item-price" name="selling_price" type="number" step="0.01" min="0" value="0" required></div>
      <div><label for="item-expiry">Expiry date</label><input id="item-expiry" name="expiry_date" type="date"></div>
      <div class="full"><button class="btn btn-dark" type="submit">Add item</button></div>
    </form>
  </section>
  <section class="panel">
    <h2>Record stock movement</h2>
    <form method="post" action="<?= e(app_url('/staff/inventory.php')) ?>" class="form-grid">
      <?= csrf_field() ?><input type="hidden" name="action" value="record_stock">
      <div><label for="movement-item">Item</label><select id="movement-item" name="item_id" required><option value="">Select an item</option><?php foreach ($items as $item): if (!$item['is_active']) { continue; } ?><option value="<?= (int)$item['id'] ?>"><?= e($item['name']) ?> (<?= e($item['quantity_on_hand']) ?> <?= e($item['unit']) ?>)</option><?php endforeach; ?></select></div>
      <div><label for="movement-type">Movement</label><select id="movement-type" name="movement_type"><option value="receipt">Receive stock</option><option value="issue">Issue stock</option><option value="adjustment">Set counted stock level</option></select></div>
      <div><label for="movement-quantity">Quantity</label><input id="movement-quantity" name="quantity" type="number" step="0.01" min="0" required></div>
      <div><label for="movement-notes">Notes / reference</label><input id="movement-notes" name="notes" maxlength="255"></div>
      <div class="full"><button class="btn btn-dark" type="submit">Save stock movement</button></div>
    </form>
  </section>
</div>
<?php else: ?>
<div class="alert alert-info">Stock is read-only for your role. Contact an administrator to add items or record stock movements.</div>
<?php endif; ?>

<section class="panel">
  <h2>Stock on hand</h2>
  <div class="table-wrap"><table class="inventory-table">
    <thead><tr><th>Item</th><th>Category</th><th>Stock</th><th>Reorder level</th><th>Expiry</th><th>Status</th><?php if ($canManage): ?><th>Actions</th><?php endif; ?></tr></thead>
    <tbody>
      <?php foreach ($items as $item):
        $low = (float)$item['quantity_on_hand'] <= (float)$item['reorder_level'];
        $expired = $item['expiry_date'] && $item['expiry_date'] < date('Y-m-d'); ?>
        <tr>
          <td><strong><?= e($item['name']) ?></strong><?php if ($item['generic_name']): ?><br><small><?= e($item['generic_name']) ?></small><?php endif; ?><?php if ($item['sku']): ?><br><small>SKU <?= e($item['sku']) ?></small><?php endif; ?></td>
          <td><?= e($categories[$item['category']] ?? $item['category']) ?></td>
          <td><strong class="<?= $low ? 'inventory-low' : '' ?>"><?= e($item['quantity_on_hand']) ?> <?= e($item['unit']) ?></strong></td>
          <td><?= e($item['reorder_level']) ?> <?= e($item['unit']) ?></td>
          <td><?= $item['expiry_date'] ? e(date('j M Y', strtotime($item['expiry_date']))) : '—' ?><?= $expired ? ' · Expired' : '' ?></td>
          <td><?= $item['is_active'] ? ($low ? badge('Reorder', 'emergency') : badge('In stock', 'paid')) : badge('Inactive') ?></td>
          <?php if ($canManage): ?><td class="inventory-actions">
            <details><summary class="btn btn-sm btn-ghost">Edit</summary>
              <form method="post" action="<?= e(app_url('/staff/inventory.php')) ?>" class="inventory-edit-form">
                <?= csrf_field() ?><input type="hidden" name="action" value="save_item"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
                <label>Name<input name="name" maxlength="160" value="<?= e($item['name']) ?>" required></label>
                <label>Generic / description<input name="generic_name" maxlength="160" value="<?= e($item['generic_name'] ?? '') ?>"></label>
                <label>SKU<input name="sku" maxlength="40" value="<?= e($item['sku'] ?? '') ?>"></label>
                <label>Category<select name="category"><?php foreach ($categories as $key => $label): ?><option value="<?= e($key) ?>" <?= $item['category'] === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
                <label>Unit<input name="unit" maxlength="40" value="<?= e($item['unit']) ?>" required></label>
                <label>Reorder level<input name="reorder_level" type="number" step="0.01" min="0" value="<?= e($item['reorder_level']) ?>" required></label>
                <label>Unit cost<input name="unit_cost" type="number" step="0.01" min="0" value="<?= e($item['unit_cost']) ?>" required></label>
                <label>Dispensing price<input name="selling_price" type="number" step="0.01" min="0" value="<?= e($item['selling_price']) ?>" required></label>
                <label>Expiry date<input name="expiry_date" type="date" value="<?= e($item['expiry_date'] ?? '') ?>"></label>
                <button class="btn btn-sm" type="submit">Save item</button>
              </form>
            </details>
            <form method="post" action="<?= e(app_url('/staff/inventory.php')) ?>" data-confirm="Change this inventory item's active status?">
              <?= csrf_field() ?><input type="hidden" name="action" value="toggle_item"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
              <button class="btn btn-sm btn-ghost" type="submit"><?= $item['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
          </td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$items): ?><tr><td colspan="<?= $canManage ? 7 : 6 ?>">No inventory items have been added.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</section>

<section class="panel">
  <h2>Recent stock movements</h2>
  <?php if (!$movements): ?><p>No stock movements recorded.</p><?php else: ?>
  <div class="table-wrap"><table>
    <thead><tr><th>Date</th><th>Item</th><th>Movement</th><th>Change</th><th>Balance</th><th>Recorded by</th><th>Notes</th></tr></thead>
    <tbody><?php foreach ($movements as $movement): ?>
      <tr><td><?= e(date('j M Y H:i', strtotime($movement['created_at']))) ?></td><td><?= e($movement['item_name']) ?></td>
        <td><?= e(ucfirst($movement['movement_type'])) ?></td><td><?= e($movement['quantity_delta']) ?> <?= e($movement['unit']) ?></td>
        <td><?= e($movement['quantity_after']) ?> <?= e($movement['unit']) ?></td><td><?= e($movement['recorded_by_name'] ?? 'System') ?></td><td><?= e($movement['notes'] ?? '—') ?></td></tr>
    <?php endforeach; ?></tbody>
  </table></div><?php endif; ?>
</section>
<?php staff_footer();
