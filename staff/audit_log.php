<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/layout.php';
require_login(['admin']);

$log = db()->query(
    'SELECT a.*, u.full_name
     FROM audit_log a
     LEFT JOIN users u ON u.id=a.user_id
     ORDER BY a.id DESC
     LIMIT 40'
)->fetchAll();

staff_header('Audit Log', 'audit_log.php');
?>
<div class="page-head"><h1>Audit Log</h1></div>
<div class="panel">
  <div class="table-wrap"><table>
    <tr><th>Time</th><th>User</th><th>Action</th><th>Detail</th><th>IP</th></tr>
    <?php foreach ($log as $entry): ?>
      <tr>
        <td><?= e(date('j M H:i:s', strtotime($entry['created_at']))) ?></td>
        <td><?= e($entry['full_name'] ?? '—') ?></td>
        <td><?= e($entry['action']) ?></td>
        <td><?= e($entry['detail'] ?? '') ?></td>
        <td><?= e($entry['ip_address'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
  </table></div>
</div>
<?php staff_footer();
