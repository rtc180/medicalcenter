<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

/** Menu: [file, label, roles allowed (admin always allowed)] */
function staff_menu(): array
{
    return [
        ['dashboard.php',    'Dashboard',    ['reception','nurse','doctor','pharmacist']],
        ['patients.php',     'Patients',     ['reception','nurse','doctor']],
        ['queue.php',        'Live Queue',   ['reception','nurse','doctor']],
        ['pharmacy.php',     'Pharmacy',     ['nurse','pharmacist','doctor']],
        ['inventory.php',    'Inventory',    ['nurse','doctor','pharmacist']],
        ['billing.php',      'Billing',      ['reception']],
        ['appointments.php', 'Appointments', ['reception','doctor','nurse']],
        ['enquiries.php',    'Enquiries',    ['reception']],
        ['reports.php',      'Reports',      []],
        ['users.php',        'Users',        []],
        ['settings.php',     'Settings',     []],
        ['audit_log.php',    'Audit Log',    []],
    ];
}

function staff_menu_icon(string $file): string
{
    $paths = [
        'dashboard.php' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'patients.php' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'queue.php' => '<path d="M4 6h16M4 12h16M4 18h10"/><circle cx="18" cy="18" r="2"/>',
        'pharmacy.php' => '<path d="m10.5 20.5 10-10a4.95 4.95 0 0 0-7-7l-10 10a4.95 4.95 0 0 0 7 7Z"/><path d="m8.5 8.5 7 7"/>',
        'inventory.php' => '<path d="M4 7h16v13H4z"/><path d="M8 7V4h8v3M8 12h8M8 16h5"/>',
        'billing.php' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4"/>',
        'appointments.php' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'enquiries.php' => '<path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8A8.5 8.5 0 0 1 8.7 3.9a8.4 8.4 0 0 1 3.8-.9h.5a8.5 8.5 0 0 1 8 8v.5Z"/>',
        'reports.php' => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-5 5"/><path d="M15 9h4v4"/>',
        'data_transfer.php' => '<path d="M12 16V4m0 0L7 9m5-5 5 5"/><path d="M5 14v5a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-5"/>',
        'users.php' => '<path d="M16 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="10" cy="7" r="4"/><path d="M20 8v6M17 11h6"/>',
        'settings.php' => '<path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Z"/><path d="m19.4 15 .1.1a1.8 1.8 0 0 1-2.5 2.5l-.1-.1a1.8 1.8 0 0 0-3.1 1.3v.2a1.8 1.8 0 0 1-3.6 0v-.2a1.8 1.8 0 0 0-3.1-1.3l-.1.1a1.8 1.8 0 0 1-2.5-2.5l.1-.1a1.8 1.8 0 0 0-1.3-3.1h-.2a1.8 1.8 0 0 1 0-3.6h.2a1.8 1.8 0 0 0 1.3-3.1l-.1-.1a1.8 1.8 0 0 1 2.5-2.5l.1.1a1.8 1.8 0 0 0 3.1-1.3v-.2a1.8 1.8 0 0 1 3.6 0v.2a1.8 1.8 0 0 0 3.1 1.3l.1-.1a1.8 1.8 0 0 1 2.5 2.5l-.1.1a1.8 1.8 0 0 0 1.3 3.1h.2a1.8 1.8 0 0 1 0 3.6h-.2a1.8 1.8 0 0 0-1.3 3.1Z"/>',
        'audit_log.php' => '<path d="M12 8v4l3 2"/><circle cx="12" cy="12" r="9"/><path d="M3 4v5h5"/>',
    ];

    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($paths[$file] ?? '') . '</svg>';
}

function staff_allowed_menu(string $role): array
{
    return array_values(array_filter(staff_menu(), static fn(array $item): bool =>
        $role === 'admin' || in_array($role, $item[2], true)
    ));
}

function staff_notification_items(string $role): array
{
    $pdo = db();
    $items = [];
    $allowed = array_column(staff_allowed_menu($role), 0);
    $add = static function (string $file, string $label, int $count) use (&$items, $allowed): void {
        if ($count > 0 && in_array($file, $allowed, true)) {
            $items[] = ['label' => $label, 'count' => $count, 'url' => app_url('/staff/' . $file)];
        }
    };

    $urgent = (int)$pdo->query("SELECT COUNT(*) FROM visits WHERE status<>'departed' AND priority IN ('urgent','emergency')")->fetchColumn();
    $add('queue.php', 'Urgent and emergency visits', $urgent);

    if (in_array('appointments.php', $allowed, true)) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM appointments WHERE status='scheduled' AND scheduled_for>=NOW() AND scheduled_for<DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
        $add('appointments.php', 'Appointments today', $count);
    }
    if (in_array('billing.php', $allowed, true)) {
        $count = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='unpaid'")->fetchColumn();
        $add('billing.php', 'Unpaid bills', $count);
    }
    if (in_array('pharmacy.php', $allowed, true) && in_array($role, ['admin', 'nurse', 'pharmacist'], true)) {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM prescriptions WHERE dispensed=0')->fetchColumn();
        $add('pharmacy.php', 'Prescriptions awaiting dispensing', $count);
    }
    if (in_array('inventory.php', $allowed, true)) {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM inventory_items WHERE is_active=1 AND quantity_on_hand<=reorder_level')->fetchColumn();
        $add('inventory.php', 'Items at or below reorder level', $count);
    }
    if (in_array('enquiries.php', $allowed, true)) {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM enquiries WHERE is_read=0')->fetchColumn();
        $add('enquiries.php', 'Unread enquiries', $count);
    }

    return $items;
}

function staff_notifications(string $role): void
{
    $items = staff_notification_items($role);
    $total = array_sum(array_column($items, 'count'));
    ?>
    <details class="staff-notifications">
      <summary aria-label="<?= $total > 0 ? e($total . ' notifications') : 'Notifications' ?>" aria-haspopup="true">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/></svg>
        <?php if ($total > 0): ?><span class="staff-notification-count"><?= $total > 99 ? '99+' : $total ?></span><?php endif; ?>
      </summary>
      <div class="staff-notification-popover">
        <strong>Needs attention</strong>
        <?php if ($items): ?>
          <?php foreach ($items as $item): ?>
            <a href="<?= e($item['url']) ?>"><span><?= e($item['label']) ?></span><b><?= $item['count'] ?></b></a>
          <?php endforeach; ?>
        <?php else: ?>
          <p>You're all caught up.</p>
        <?php endif; ?>
      </div>
    </details>
    <?php
}

function staff_user_menu(): void
{
    $user = current_user();
    if (!$user) {
        return;
    }
    ?>
    <details class="user-menu">
      <summary aria-label="Open account menu" aria-haspopup="true">
        <svg class="user-avatar-icon" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
        <span><?= e($user['name']) ?><small><?= e(ROLE_LABELS[$user['role']] ?? $user['role']) ?></small></span>
        <svg class="user-menu-caret" viewBox="0 0 20 20" aria-hidden="true"><path d="m5 7.5 5 5 5-5"/></svg>
      </summary>
      <div class="user-menu-popover">
        <a href="<?= e(app_url('/staff/profile.php')) ?>">Profile</a>
        <form method="post" action="<?= e(app_url('/logout.php')) ?>">
          <?= csrf_field() ?><button type="submit">Logout</button>
        </form>
      </div>
    </details>
    <?php
}

function clinic_print_header(string $class = ''): void
{
    $settings = clinic_print_settings();
    $details = array_filter([
        $settings['address'],
        $settings['phone'],
        $settings['email'],
        $settings['registration_no'] ? 'Registration: ' . $settings['registration_no'] : '',
    ]);
    ?>
    <header class="print-clinic-header<?= $class !== '' ? ' ' . e($class) : '' ?>">
      <img src="<?= e(app_asset('/assets/img/logo.png')) ?>" alt="<?= e($settings['clinic_name']) ?> logo">
      <div>
        <h1><?= e($settings['clinic_name']) ?></h1>
        <?php if ($details): ?><p><?= e(implode(' · ', $details)) ?></p><?php endif; ?>
      </div>
    </header>
    <?php
}

function clinic_print_footer(): void
{
    $note = clinic_print_settings()['footer_note'];
    if ($note !== '') {
        echo '<p class="print-clinic-footer">' . e($note) . '</p>';
    }
}

function staff_header(string $title, string $active = '', string $printTarget = '', string $bodyClass = ''): void
{
    $u = current_user();
    ?>
<!DOCTYPE html>
<html lang="en" class="no-js">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> | <?= e(APP_NAME) ?></title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="<?= e(app_asset('/assets/img/logo.png')) ?>">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="<?= e(app_asset('/assets/css/style.css')) ?>">
</head>
<body class="staff-body<?= $bodyClass !== '' ? ' ' . e($bodyClass) : '' ?>">
<a class="skip" href="#content">Skip to content</a>
<div class="app">
  <aside class="sidebar">
    <div class="sidebar-menu-head">
      <button class="staff-menu-toggle" type="button" aria-label="Collapse navigation" aria-expanded="true" aria-controls="staff-navigation">
        <svg class="navigation-arrow-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14M5 12l6-6M5 12l6 6"/></svg>
        <span class="hamburger-icon" aria-hidden="true"><i></i><i></i><i></i></span>
      </button>
    </div>
    <nav id="staff-navigation" aria-label="Staff">
      <?php foreach (staff_menu() as [$file, $label, $roles]):
          if ($u['role'] !== 'admin' && !in_array($u['role'], $roles, true)) { continue; }
          $current = $active === $file; ?>
        <a href="<?= e(app_url('/staff/' . $file)) ?>" class="<?= $current ? 'active' : '' ?>" aria-label="<?= e($label) ?>" title="<?= e($label) ?>"<?= $current ? ' aria-current="page"' : '' ?>>
          <?= staff_menu_icon($file) ?><span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="who">
      <strong><?= e($u['name']) ?></strong><br><?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?>
      <form class="sidebar-logout-form" method="post" action="<?= e(app_url('/logout.php')) ?>"><?= csrf_field() ?>
        <button class="btn btn-ghost btn-sm" type="submit">Sign out</button></form>
    </div>
  </aside>
  <main class="main" id="content">
    <header class="staff-topbar">
      <button class="staff-menu-open" type="button" aria-label="Open navigation" aria-expanded="false" aria-controls="staff-navigation">Menu</button>
      <a class="dashboard-brand" href="<?= e(app_url('/staff/dashboard.php')) ?>" aria-label="<?= e(APP_NAME) ?> dashboard">
        <img src="<?= e(app_asset('/assets/img/logo.png')) ?>" alt="" width="44" height="44">
        <strong><?= e(APP_NAME) ?></strong>
      </a>
      <?php staff_notifications($u['role']); ?>
      <?php staff_user_menu(); ?>
    </header>
    <div class="page-tools no-print">
      <button class="btn btn-sm" type="button" data-print<?= $printTarget !== '' ? ' data-print-target="' . e($printTarget) . '"' : '' ?>>Print / Save as PDF</button>
    </div>
    <?php foreach ($_SESSION['flash'] ?? [] as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?>" role="status"><?= e($f['msg']) ?></div>
    <?php endforeach; unset($_SESSION['flash']); ?>
<?php
}

function staff_footer(): void
{
    ?>
  </main>
</div>
<script src="<?= e(app_asset('/assets/js/main.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Progress tracker mirroring the flowchart. */
function tracker(string $status): string
{
    $keys = array_keys(STAGES);
    $idx = array_search($status, $keys, true);
    $html = '<div class="tracker" aria-label="Visit progress">';
    foreach (STAGES as $k => $label) {
        $i = array_search($k, $keys, true);
        $cls = $i < $idx ? 'done' : ($i === $idx ? 'now' : '');
        $html .= '<span class="' . $cls . '">' . e($label) . '</span>';
    }
    return $html . '</div>';
}
