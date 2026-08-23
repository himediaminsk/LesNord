<?php
declare(strict_types=1);
require __DIR__ . '/core.php';
nw_session_start();

$notice = '';
$error = '';

/* ------------------------------------------------------------------ вход */
if (($_POST['do'] ?? '') === 'login') {
    [$ok, $err] = nw_login((string)($_POST['password'] ?? ''));
    if ($ok) { header('Location: index.php'); exit; }
    $error = $err;
}

if (($_GET['do'] ?? '') === 'logout') {
    nw_logout();
    header('Location: index.php');
    exit;
}

/* ------------------------------------------------------------- действия */
if (nw_logged_in() && ($_POST['do'] ?? '') !== '' && $_POST['do'] !== 'login') {
    if (!nw_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'Не сходится ключ безопасности. Обновите страницу и повторите.';
    } elseif ($_POST['do'] === 'password') {
        $new = (string)($_POST['new'] ?? '');
        if (mb_strlen($new) < 8) {
            $error = 'Пароль должен быть не короче 8 символов.';
        } else {
            $auth = nw_auth();
            $auth['hash'] = password_hash($new, PASSWORD_DEFAULT);
            nw_write_json(NW_AUTH, $auth);
            $notice = 'Пароль изменён.';
        }
    } elseif ($_POST['do'] === 'restore') {
        $file = NW_DATA . '/backups/' . basename((string)($_POST['file'] ?? ''));
        if (is_file($file)) {
            nw_backup();
            copy($file, NW_CONTENT);
            nw_drop_cache();
            $notice = 'Версия восстановлена.';
        } else {
            $error = 'Такой копии больше нет.';
        }
    } elseif ($_POST['do'] === 'reset') {
        nw_backup();
        nw_write_json(NW_CONTENT, ['text' => [], 'img' => [], 'bg' => []]);
        nw_drop_cache();
        $notice = 'Все тексты вернулись к исходной вёрстке.';
    }
}

$logged = nw_logged_in();
$content = $logged ? nw_content() : [];
$edits = count($content['text'] ?? []) + count($content['img'] ?? []) + count($content['bg'] ?? []);
$backups = $logged ? array_reverse(glob(NW_DATA . '/backups/content-*.json') ?: []) : [];
$defaultPass = $logged && password_verify(NW_DEFAULT_PASSWORD, (string)(nw_auth()['hash'] ?? ''));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>NordWood — управление лендингом</title>
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font:16px/1.55 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;background:#f7f2e8;color:#23201c;padding:40px 20px;}
.wrap{max-width:760px;margin:0 auto;}
h1{font-size:26px;margin-bottom:6px;}
h2{font-size:18px;margin:0 0 12px;}
.sub{color:#5b544c;font-size:14.5px;margin-bottom:28px;}
.card{background:#fff;border:1px solid #e3dccd;border-radius:6px;padding:24px;margin-bottom:18px;}
label{display:block;font-size:12px;letter-spacing:.1em;text-transform:uppercase;color:#5b544c;margin-bottom:7px;}
input[type=password]{width:100%;padding:13px;border:1px solid #e3dccd;border-radius:4px;font:inherit;background:#f7f2e8;}
.btn{display:inline-block;border:0;background:#188b30;color:#fff;padding:13px 22px;border-radius:4px;cursor:pointer;font:600 15px/1 inherit;text-decoration:none;}
.btn:hover{background:#0e5c21;}
.btn--ghost{background:transparent;color:#23201c;border:1.5px solid #23201c;}
.btn--ghost:hover{background:#23201c;color:#f7f2e8;}
.btn--small{padding:8px 14px;font-size:13px;}
.msg{padding:13px 16px;border-radius:4px;margin-bottom:18px;font-size:14.5px;}
.msg--ok{background:#188b30;color:#fff;}
.msg--err{background:#a63a1e;color:#fff;}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:center;}
.list{list-style:none;font-size:14px;}
.list li{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:9px 0;border-bottom:1px solid #f0ebe0;}
.list li:last-child{border-bottom:0;}
.muted{color:#5b544c;font-size:13.5px;}
.steps{font-size:14.5px;color:#5b544c;}
.steps li{margin:0 0 8px 18px;}
</style>
</head>
<body>
<div class="wrap">

<?php if (!$logged): ?>
  <h1>Управление лендингом</h1>
  <p class="sub">Введите пароль, чтобы править тексты и картинки прямо на странице.</p>
  <?php if ($error): ?><div class="msg msg--err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form class="card" method="post">
    <input type="hidden" name="do" value="login">
    <label for="p">Пароль</label>
    <input id="p" type="password" name="password" autofocus required>
    <div style="margin-top:18px;"><button class="btn" type="submit">Войти</button></div>
  </form>
<?php else: ?>
  <h1>Управление лендингом</h1>
  <p class="sub">Правки хранятся в файле cms/data/content.json. Исходная вёрстка не меняется, поэтому откатить можно всё и в любой момент.</p>

  <?php if ($notice): ?><div class="msg msg--ok"><?= htmlspecialchars($notice) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="msg msg--err"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <?php if ($defaultPass): ?>
    <div class="msg msg--err">Сейчас стоит пароль по умолчанию. Смените его в блоке ниже — иначе панель откроет любой желающий.</div>
  <?php endif; ?>

  <div class="card">
    <h2>Как править</h2>
    <ol class="steps">
      <li>Откройте сайт — внизу справа появится панель.</li>
      <li>Нажмите «Включить правку»: тексты обведутся зелёным, картинки — красным.</li>
      <li>Кликните по тексту и печатайте. По картинке — выберите новый файл.</li>
      <li>Нажмите «Сохранить» или Ctrl+S.</li>
    </ol>
    <div class="row" style="margin-top:18px;">
      <a class="btn" href="../">Открыть сайт</a>
      <a class="btn btn--ghost" href="index.php?do=logout">Выйти</a>
      <span class="muted">Сейчас изменено блоков: <?= (int)$edits ?></span>
    </div>
  </div>

  <div class="card">
    <h2>Пароль</h2>
    <form method="post" class="row">
      <input type="hidden" name="do" value="password">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(nw_csrf()) ?>">
      <input type="password" name="new" placeholder="Новый пароль, минимум 8 символов" required style="flex:1;min-width:240px;">
      <button class="btn" type="submit">Сменить</button>
    </form>
  </div>

  <div class="card">
    <h2>История версий</h2>
    <?php if (!$backups): ?>
      <p class="muted">Копии появятся после первого сохранения.</p>
    <?php else: ?>
      <ul class="list">
        <?php foreach ($backups as $file):
          $name = basename($file);
          $dt = DateTime::createFromFormat('Ymd-His', str_replace(['content-', '.json'], '', $name));
          $when = $dt ? $dt->format('d.m.Y H:i') : $name;
        ?>
          <li>
            <span>Версия от <?= htmlspecialchars($when) ?></span>
            <form method="post" onsubmit="return confirm('Вернуть эту версию? Текущие тексты уйдут в копию.');">
              <input type="hidden" name="do" value="restore">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars(nw_csrf()) ?>">
              <input type="hidden" name="file" value="<?= htmlspecialchars($name) ?>">
              <button class="btn btn--ghost btn--small" type="submit">Вернуть</button>
            </form>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Сброс</h2>
    <p class="muted" style="margin-bottom:14px;">Уберёт все правки и вернёт тексты и картинки из исходной вёрстки. Текущая версия сначала уйдёт в историю.</p>
    <form method="post" onsubmit="return confirm('Вернуть исходные тексты и картинки?');">
      <input type="hidden" name="do" value="reset">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars(nw_csrf()) ?>">
      <button class="btn btn--ghost" type="submit">Вернуть исходную вёрстку</button>
    </form>
  </div>
<?php endif; ?>

</div>
</body>
</html>
