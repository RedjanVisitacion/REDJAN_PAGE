<?php
function rpsv_route_config()
{
    static $config = null;
    if ($config === null) {
        $loaded = @include __DIR__ . '/route_config.php';
        $config = is_array($loaded) ? $loaded : ['routes' => [], 'aliases' => []];
    }
    return $config;
}

function rpsv_route_id($nameOrId)
{
    $config = rpsv_route_config();
    if (isset($config['routes'][$nameOrId])) {
        return $nameOrId;
    }
    return $config['aliases'][$nameOrId] ?? $nameOrId;
}

function rpsv_public_base_path()
{
    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $dir = rtrim(dirname($scriptName), '/');
    if ($dir === '.' || $dir === '/') {
        return '';
    }
    if (substr($dir, -4) === '/php') {
        $dir = substr($dir, 0, -4);
    } elseif (substr($dir, -2) === '/c') {
        $dir = substr($dir, 0, -2);
    }
    return rtrim($dir, '/');
}

function rpsv_route_path($nameOrId, $params = [])
{
    $id = rpsv_route_id($nameOrId);
    $path = rpsv_public_base_path() . '/c/' . rawurlencode($id);
    $path = preg_replace('#/+#', '/', $path);
    if ($path === '') {
        $path = '/';
    }
    if (!empty($params)) {
        $path .= '?' . http_build_query($params);
    }
    return $path;
}

function rpsv_route_url($nameOrId, $params = [])
{
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . rpsv_route_path($nameOrId, $params);
}
?>
