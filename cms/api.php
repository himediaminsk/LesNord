<?php
declare(strict_types=1);
require __DIR__ . '/core.php';

$action = $_GET['a'] ?? $_POST['a'] ?? '';

/* Состояние — единственный запрос, который делает страница сайта. */
if ($action === 'state') {
    nw_json([
        'ok'   => nw_logged_in(),
        'csrf' => nw_logged_in() ? nw_csrf() : '',
    ]);
}

if ($action === 'logout') {
    nw_logout();
    nw_json(['ok' => true]);
}

/* Дальше — только для авторизованных. */
if (!nw_logged_in()) {
    nw_json(['ok' => false, 'error' => 'Сессия закончилась. Войдите в админку заново.'], 401);
}

if ($action === 'save') {
    $raw = file_get_contents('php://input') ?: '';
    $in = json_decode($raw, true);
    if (!is_array($in) || !nw_check_csrf($in['csrf'] ?? null)) {
        nw_json(['ok' => false, 'error' => 'Не сходится ключ безопасности. Обновите страницу.'], 403);
    }

    $content = nw_content();
    $changed = 0;

    foreach ((array)($in['text'] ?? []) as $key => $value) {
        if (!preg_match('~^[a-z0-9_.\-]{1,64}$~i', (string)$key)) continue;
        $content['text'][$key] = nw_clean((string)$value);
        $changed++;
    }
    foreach ((array)($in['img'] ?? []) as $key => $value) {
        if (!preg_match('~^[a-z0-9_.\-]{1,64}$~i', (string)$key)) continue;
        $content['img'][$key] = preg_replace('~[^a-z0-9_./\-]~i', '', (string)$value);
        $changed++;
    }
    foreach ((array)($in['bg'] ?? []) as $key => $value) {
        if (!preg_match('~^[a-z0-9_.\-]{1,64}$~i', (string)$key)) continue;
        $content['bg'][$key] = preg_replace('~[^a-z0-9_./\-]~i', '', (string)$value);
        $changed++;
    }

    if ($changed) {
        nw_backup();
        if (!nw_write_json(NW_CONTENT, $content)) {
            nw_json(['ok' => false, 'error' => 'Не удалось записать cms/data/content.json — проверьте права на папку.'], 500);
        }
        nw_drop_cache();
    }
    nw_json(['ok' => true, 'saved' => $changed]);
}

if ($action === 'upload') {
    if (!nw_check_csrf($_POST['csrf'] ?? null)) {
        nw_json(['ok' => false, 'error' => 'Не сходится ключ безопасности. Обновите страницу.'], 403);
    }
    [$path, $error] = nw_save_upload($_FILES['file'] ?? []);
    if (!$path) nw_json(['ok' => false, 'error' => $error], 400);
    nw_json(['ok' => true, 'src' => $path]);
}

nw_json(['ok' => false, 'error' => 'Неизвестное действие.'], 400);
