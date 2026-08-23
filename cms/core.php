<?php
/**
 * NW Admin — мини-CMS для лендинга NordWood78.
 * Без базы данных: тексты и ссылки на картинки лежат в cms/data/content.json,
 * исходная вёрстка (page.html) никогда не изменяется.
 */
declare(strict_types=1);

define('NW_ROOT', dirname(__DIR__));           // корень сайта
define('NW_CMS',  __DIR__);                    // папка админки
define('NW_DATA', NW_CMS . '/data');
define('NW_CONTENT', NW_DATA . '/content.json');
define('NW_AUTH',    NW_DATA . '/auth.json');
define('NW_CACHE',   NW_DATA . '/cache.html');
define('NW_TEMPLATE', NW_ROOT . '/page.html');
define('NW_UPLOAD',  NW_ROOT . '/upload');

const NW_DEFAULT_PASSWORD = 'nordwood78';      // пароль первого входа — сразу смените
const NW_MAX_UPLOAD = 6 * 1024 * 1024;         // 6 МБ на картинку
const NW_KEEP_BACKUPS = 20;
const NW_LOCK_ATTEMPTS = 8;                    // блокировка входа после N ошибок
const NW_LOCK_MINUTES = 15;

/* ------------------------------------------------------------------ данные */

function nw_read_json(string $file, array $fallback = []): array
{
    if (!is_file($file)) return $fallback;
    $raw = file_get_contents($file);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $fallback;
}

function nw_write_json(string $file, array $data): bool
{
    $dir = dirname($file);
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $tmp = $file . '.tmp';
    if (file_put_contents($tmp, $json, LOCK_EX) === false) return false;
    return rename($tmp, $file);
}

function nw_content(): array
{
    return nw_read_json(NW_CONTENT, ['text' => [], 'img' => [], 'bg' => []]);
}

function nw_auth(): array
{
    $auth = nw_read_json(NW_AUTH);
    if (!$auth) {
        $auth = ['hash' => password_hash(NW_DEFAULT_PASSWORD, PASSWORD_DEFAULT), 'fails' => 0, 'locked_until' => 0];
        nw_write_json(NW_AUTH, $auth);
    }
    return $auth;
}

/* -------------------------------------------------------------- авторизация */

function nw_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('nwadm');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function nw_logged_in(): bool
{
    nw_session_start();
    return !empty($_SESSION['nw_ok']);
}

function nw_login(string $password): array
{
    nw_session_start();
    $auth = nw_auth();
    $now = time();

    if (!empty($auth['locked_until']) && $auth['locked_until'] > $now) {
        $left = (int)ceil(($auth['locked_until'] - $now) / 60);
        return [false, "Слишком много попыток. Повторите через {$left} мин."];
    }
    if (!password_verify($password, (string)$auth['hash'])) {
        $auth['fails'] = (int)($auth['fails'] ?? 0) + 1;
        if ($auth['fails'] >= NW_LOCK_ATTEMPTS) {
            $auth['fails'] = 0;
            $auth['locked_until'] = $now + NW_LOCK_MINUTES * 60;
        }
        nw_write_json(NW_AUTH, $auth);
        return [false, 'Неверный пароль.'];
    }

    $auth['fails'] = 0;
    $auth['locked_until'] = 0;
    nw_write_json(NW_AUTH, $auth);

    session_regenerate_id(true);
    $_SESSION['nw_ok'] = true;
    $_SESSION['nw_csrf'] = bin2hex(random_bytes(16));
    // Метка для фронта: пока её нет, editor.js не делает ни одного запроса.
    setcookie('nw_edit', '1', ['expires' => 0, 'path' => '/', 'samesite' => 'Lax']);
    return [true, ''];
}

function nw_logout(): void
{
    nw_session_start();
    $_SESSION = [];
    session_destroy();
    setcookie('nw_edit', '', ['expires' => time() - 3600, 'path' => '/']);
}

function nw_csrf(): string
{
    nw_session_start();
    if (empty($_SESSION['nw_csrf'])) $_SESSION['nw_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['nw_csrf'];
}

function nw_check_csrf(?string $token): bool
{
    nw_session_start();
    return !empty($_SESSION['nw_csrf']) && is_string($token) && hash_equals($_SESSION['nw_csrf'], $token);
}

/* ------------------------------------------------------- правки и рендеринг */

function nw_backup(): void
{
    if (!is_file(NW_CONTENT)) return;
    $dir = NW_DATA . '/backups';
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    copy(NW_CONTENT, $dir . '/content-' . date('Ymd-His') . '.json');

    $files = glob($dir . '/content-*.json') ?: [];
    sort($files);
    while (count($files) > NW_KEEP_BACKUPS) {
        unlink(array_shift($files));
    }
}

/** Чистит присланный из браузера HTML: оставляем только безопасную разметку. */
function nw_clean(string $html): string
{
    $html = preg_replace('~<\s*(script|style|iframe|object|embed|form)\b.*?<\s*/\s*\1\s*>~is', '', $html) ?? '';
    $html = preg_replace('~\son[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)~is', '', $html) ?? '';
    $html = preg_replace('~javascript:~i', '', $html) ?? '';
    $allowed = '<br><b><strong><i><em><span><small><sup><sub><u><mark><a>';
    $html = strip_tags($html, $allowed);
    return trim($html);
}

/** Применяет сохранённые правки к вёрстке. */
function nw_apply(string $html, array $content): string
{
    $text = $content['text'] ?? [];
    $img  = $content['img'] ?? [];
    $bg   = $content['bg'] ?? [];
    if (!$text && !$img && !$bg) return $html;

    if (!class_exists('DOMDocument')) return nw_apply_fallback($html, $content);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);

    foreach ($text as $key => $value) {
        foreach ($xp->query('//*[@data-nw="' . addslashes((string)$key) . '"]') as $node) {
            while ($node->firstChild) $node->removeChild($node->firstChild);
            $frag = $doc->createDocumentFragment();
            if (@$frag->appendXML(nw_xml_ready((string)$value))) {
                $node->appendChild($frag);
            } else {
                $node->appendChild($doc->createTextNode(strip_tags((string)$value)));
            }
        }
    }
    foreach ($img as $key => $src) {
        foreach ($xp->query('//*[@data-nw-img="' . addslashes((string)$key) . '"]') as $node) {
            $node->setAttribute('src', (string)$src);
            $node->removeAttribute('data-src');
        }
    }
    foreach ($bg as $key => $src) {
        foreach ($xp->query('//*[@data-nw-bg="' . addslashes((string)$key) . '"]') as $node) {
            $node->setAttribute('style', "--hero-photo:url('" . str_replace("'", '', (string)$src) . "')");
        }
    }

    $out = $doc->saveHTML();
    return $out !== false ? preg_replace('~<\?xml encoding="utf-8"\?>~', '', $out, 1) : $html;
}

/** Приводит фрагмент к виду, который понимает appendXML. */
function nw_xml_ready(string $html): string
{
    // одиночные теги закрываем по-XML-ному
    $html = preg_replace('~<(br|hr|img)([^>]*?)/?>~i', '<$1$2/>', $html) ?? $html;
    // именованные сущности превращаем в числовые, одинокие "&" экранируем
    $html = str_replace('&nbsp;', '&#160;', $html);
    $html = preg_replace('~&(?!#\d+;|#x[0-9a-f]+;|amp;|lt;|gt;|quot;|apos;)~i', '&amp;', $html) ?? $html;
    return $html;
}

/** Запасной вариант, если в PHP нет расширения dom. */
function nw_apply_fallback(string $html, array $content): string
{
    foreach (($content['text'] ?? []) as $key => $value) {
        $pattern = '~(<([a-z0-9]+)[^>]*\sdata-nw="' . preg_quote((string)$key, '~') . '"[^>]*>)(.*?)(</\2>)~is';
        $html = preg_replace_callback($pattern, function ($m) use ($value) {
            return $m[1] . $value . $m[4];
        }, $html, 1) ?? $html;
    }
    foreach (($content['img'] ?? []) as $key => $src) {
        $pattern = '~(<img[^>]*\sdata-nw-img="' . preg_quote((string)$key, '~') . '"[^>]*>)~i';
        $html = preg_replace_callback($pattern, function ($m) use ($src) {
            $tag = preg_replace('~\ssrc="[^"]*"~i', '', $m[1]);
            return preg_replace('~<img~i', '<img src="' . htmlspecialchars((string)$src, ENT_QUOTES) . '"', $tag, 1);
        }, $html, 1) ?? $html;
    }
    return $html;
}

/** Отдаёт готовую страницу, пересобирая кэш только после правок. */
function nw_render(): string
{
    $template = is_file(NW_TEMPLATE) ? NW_TEMPLATE : null;
    if (!$template) return '<h1>Не найден файл page.html</h1>';

    $stamp = max(filemtime($template), is_file(NW_CONTENT) ? filemtime(NW_CONTENT) : 0);
    if (is_file(NW_CACHE) && filemtime(NW_CACHE) >= $stamp) {
        return (string)file_get_contents(NW_CACHE);
    }
    $html = nw_apply((string)file_get_contents($template), nw_content());
    @file_put_contents(NW_CACHE, $html, LOCK_EX);
    return $html;
}

function nw_drop_cache(): void
{
    if (is_file(NW_CACHE)) @unlink(NW_CACHE);
}

/* ------------------------------------------------------------- изображения */

function nw_save_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return [null, 'Файл не загрузился. Попробуйте ещё раз.'];
    }
    if ($file['size'] > NW_MAX_UPLOAD) {
        return [null, 'Файл тяжелее 6 МБ. Уменьшите картинку и повторите.'];
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];
    if (!$info || !isset($types[$info[2]])) {
        return [null, 'Подойдут только JPG, PNG, WEBP или GIF.'];
    }
    if (!is_dir(NW_UPLOAD)) mkdir(NW_UPLOAD, 0775, true);

    $name = date('Ymd') . '-' . bin2hex(random_bytes(4)) . '.' . $types[$info[2]];
    $path = NW_UPLOAD . '/' . $name;
    if (!move_uploaded_file($file['tmp_name'], $path)) {
        return [null, 'Не удалось сохранить файл: проверьте права на папку /upload.'];
    }
    @chmod($path, 0644);
    return ['upload/' . $name, ''];
}

function nw_json(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
