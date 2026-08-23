<?php
/**
 * Точка входа лендинга.
 * Отдаёт page.html с применёнными правками из админки.
 * Готовый HTML кэшируется и пересобирается только после сохранения в админке.
 */
declare(strict_types=1);
require __DIR__ . '/cms/core.php';

header('Content-Type: text/html; charset=utf-8');
echo nw_render();
