<?php

/**
 * PHP 内置服务器路由（替代 artisan serve，避免其二次派生进程时丢失本地库路径）。
 * 静态文件直接返回，其余全部交给 public/index.php（Laravel）。
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

if ($uri !== '/' && file_exists(__DIR__.'/public'.$uri)) {
    return false;
}

require_once __DIR__.'/public/index.php';
