<?php
defined('ABSPATH') || exit;

spl_autoload_register(static function (string $class): void {
    if (!preg_match('/^Wpus\\\\([A-Z][A-Za-z]+)$/D', $class, $match)) {
        return;
    }

    $path = __DIR__ . '/' . $match[1] . '.php';
    if (is_readable($path)) {
        require_once $path;
    }
});
