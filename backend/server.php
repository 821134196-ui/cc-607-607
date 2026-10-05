<?php

/**
 * Laravel 内置服务器路由脚本（不依赖 artisan serve，便于本地一条命令启动）。
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

$publicPath = __DIR__.'/public';
$candidate = $publicPath.$uri;

if ($uri !== '/' && file_exists($candidate)) {
    return false; // 让内置服务器直接返回静态资源（含正确 MIME）
}

require_once $publicPath.'/index.php';
