<?php
// XML sitemap of the public pages.
define('DROP01', 1);
require __DIR__ . '/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$base = Http::baseUrl();
$urls = ['/', '/pages/privacy', '/pages/terms', '/pages/returns', '/pages/contact'];
echo '<?xml version="1.0" encoding="UTF-8"?>', "\n", '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
foreach ($urls as $u) echo '<url><loc>', htmlspecialchars($base . $u), '</loc></url>';
echo '</urlset>';
