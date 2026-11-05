<?php
declare(strict_types=1);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/functions.php';

$publicUrl = clinic_public_site_url();
if ($publicUrl === '') {
    http_response_code(404);
    exit;
}

header('Content-Type: application/xml; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=3600');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url>
    <loc><?= htmlspecialchars($publicUrl, ENT_XML1 | ENT_QUOTES, 'UTF-8') ?></loc>
    <changefreq>monthly</changefreq>
    <priority>1.0</priority>
  </url>
</urlset>
