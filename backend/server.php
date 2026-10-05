<?php

/**
 * PHP 内置服务器路由（等价于旧版 Laravel server.php）。
 * 用单进程 `php -S 127.0.0.1:8000 -t public server.php` 启动，
 * 避免 artisan serve 再派生子进程时丢失 -c 指定的扩展 ini。
 */

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false; // 让内置服务器直接返回静态文件
}

require_once __DIR__ . '/public/index.php';
