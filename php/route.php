<?php
require __DIR__ . '/route_helpers.php';

$config = rpsv_route_config();
$route = trim((string)($_GET['route'] ?? ''), " \t\n\r\0\x0B/");
if ($route === '' && !empty($_SERVER['PATH_INFO'])) {
    $route = trim((string)$_SERVER['PATH_INFO'], " \t\n\r\0\x0B/");
}

$routeId = rpsv_route_id($route);
$record = $config['routes'][$routeId] ?? null;
if (!$record || empty($record['file'])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Route not found.';
    exit;
}

$root = realpath(dirname(__DIR__));
$target = realpath($root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $record['file']));
if (!$root || !$target || strpos($target, $root . DIRECTORY_SEPARATOR) !== 0 || !is_file($target)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Page not found.';
    exit;
}

$publicDir = trim(str_replace('\\', '/', dirname($record['file'])), './');
$basePath = rpsv_public_base_path();
$baseHref = preg_replace('#/+#', '/', $basePath . '/' . ($publicDir !== '' ? $publicDir . '/' : ''));
if ($baseHref === '') {
    $baseHref = '/';
}

$html = file_get_contents($target);
if ($html === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unable to load page.';
    exit;
}
if (stripos($html, '<base ') === false) {
    $baseTag = '<base href="' . htmlspecialchars($baseHref, ENT_QUOTES, 'UTF-8') . '">';
    $html = preg_replace('/<head(\s[^>]*)?>/i', '$0' . "\n  " . $baseTag, $html, 1);
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Robots-Tag: noindex');
echo $html;
?>
