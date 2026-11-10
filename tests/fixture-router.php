<?php
/** Router for the loopback-only PHP development server in CI. */
$path = realpath($_SERVER['DOCUMENT_ROOT'] . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if ($path && str_starts_with($path, $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR) && is_file($path)) {
    return false;
}
if ($path && is_dir($path) && str_starts_with($path . DIRECTORY_SEPARATOR, $_SERVER['DOCUMENT_ROOT'] . DIRECTORY_SEPARATOR)
    && is_file($path . '/index.php')) {
    return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
