<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
$u = require_login(['reception']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    db()->prepare('UPDATE enquiries SET is_read=1 WHERE id=?')->execute([(int)post('id')]);
    redirect('/staff/enquiries.php');
}
$rows = db()->query('SELECT * FROM enquiries ORDER BY is_read, created_at DESC LIMIT 100')->fetchAll();

staff_header('Website enquiries', 'enquiries.php');
?>
<div class="page-head"><h1>Website enquiries</h1></div>
<div class="panel">
<?php if (!$rows): ?><p>No enquiries yet.</p><?php else: ?>
<div class="table-wrap"><table>
  <tr><th>Received</th><th>From</th><th>Message</th><th></th></tr>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e(date('j M H:i', strtotime($r['created_at']))) ?></td>
    <td><strong><?= e($r['name']) ?></strong><br><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $r['phone'])) ?>"><?= e($r['phone']) ?></a><br><?= e($r['email'] ?? '') ?></td>
    <td><?= nl2br(e($r['message'])) ?></td>
    <td><?php if (!$r['is_read']): ?><form method="post" action="<?= e(app_url('/staff/enquiries.php')) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-sm" type="submit">Mark handled</button></form><?php else: ?><?= badge('Handled', 'paid') ?><?php endif; ?></td>
  </tr>
  <?php endforeach; ?>
</table></div>
<?php endif; ?>
</div>
<?php staff_footer();
