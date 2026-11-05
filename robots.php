<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: text/plain; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

echo "User-agent: *\n";
echo "Allow: /\n";
$publicUrl = clinic_public_site_url();
$publicPath = $publicUrl !== '' ? rtrim((string)parse_url($publicUrl, PHP_URL_PATH), '/') : '';
$pathPrefix = $publicPath;
foreach (['staff/', 'login.php', 'logout.php', 'database/', 'config/', 'includes/'] as $privatePath) {
    echo 'Disallow: ' . $pathPrefix . '/' . $privatePath . "\n";
}
if ($publicUrl !== '') {
    echo 'Sitemap: ' . $publicUrl . 'sitemap.xml' . "\n";
}
