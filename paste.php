<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once AKH_ROOT . '/includes/paste-store.php';

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$pasteId = trim((string) ($_GET['id'] ?? ''));
$isApi = isset($_GET['api']) && (string) $_GET['api'] === '1';

if ($isApi && $method === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        http_response_code(400);
        echo json_encode(['error' => 'empty'], JSON_THROW_ON_ERROR);
        exit;
    }

    try {
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_json'], JSON_THROW_ON_ERROR);
        exit;
    }

    $content = isset($payload['content']) ? (string) $payload['content'] : '';
    $result = akh_paste_save($content);

    if (isset($result['error'])) {
        $status = $result['error'] === 'too_large' ? 413 : 400;
        http_response_code($status);
        echo json_encode($result, JSON_THROW_ON_ERROR);
        exit;
    }

    echo json_encode($result, JSON_THROW_ON_ERROR);
    exit;
}

if ($isApi) {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'method_not_allowed'], JSON_THROW_ON_ERROR);
    exit;
}

$initialContent = '';
$initialLoaded = false;
if ($pasteId !== '') {
    $loaded = akh_paste_load($pasteId, true);
    if ($loaded !== null) {
        $initialContent = $loaded;
        $initialLoaded = true;
    }
}

$cssPath = AKH_ROOT . '/assets/css/paste.css';
$jsPath = AKH_ROOT . '/assets/js/paste.js';
$cssVer = is_file($cssPath) ? (string) filemtime($cssPath) : '1';
$jsVer = is_file($jsPath) ? (string) filemtime($jsPath) : '1';
$saveUrl = base_path('paste.php?api=1');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
  <meta name="description" content="Simple online clipboard for plain text. Copy, paste, and share between devices with no login." />
  <meta name="robots" content="noindex, nofollow" />
  <title>Online clipboard — <?php echo h(SITE_NAME); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Source+Sans+3:ital,wght@0,400;0,500;0,600;1,400&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?php echo h(base_path('assets/css/paste.css')); ?>?v=<?php echo h($cssVer); ?>" />
</head>
<body>
  <div class="paste-page">
    <div class="paste-top">
      <p class="paste-brand"><strong><?php echo h(SITE_NAME); ?></strong> · Online clipboard</p>
      <a class="paste-home" href="<?php echo h(base_path('')); ?>">Home</a>
    </div>

    <header class="paste-head">
      <h1 class="paste-title">Copy &amp; paste</h1>
      <p class="paste-lead">A simple place to move text between devices. Plain text only — no login, no tracking.</p>
    </header>

    <section class="paste-card" aria-label="Clipboard">
      <label class="visually-hidden" for="paste-content">Clipboard text</label>
      <textarea
        id="paste-content"
        class="paste-area"
        placeholder="Paste or type your text here…"
        spellcheck="true"
        data-max-bytes="<?php echo (int) AKH_PASTE_MAX_BYTES; ?>"
        data-save-url="<?php echo h($saveUrl); ?>"
        data-initial-loaded="<?php echo $initialLoaded ? '1' : '0'; ?>"
      ><?php echo h($initialContent); ?></textarea>

      <div class="paste-toolbar">
        <button type="button" class="paste-btn paste-btn--primary" id="paste-copy">Copy</button>
        <button type="button" class="paste-btn" id="paste-share">Share link</button>
        <button type="button" class="paste-btn" id="paste-clear">Clear</button>
        <span class="paste-meta" id="paste-count" aria-live="polite">0 characters</span>
      </div>
    </section>

    <p class="paste-status" id="paste-status" aria-live="polite"></p>
    <p class="paste-note">Share links work once and are removed after 24 hours. Your draft is saved in this browser until you clear it.</p>
  </div>

  <style>
    .visually-hidden {
      position: absolute;
      width: 1px;
      height: 1px;
      padding: 0;
      margin: -1px;
      overflow: hidden;
      clip: rect(0, 0, 0, 0);
      white-space: nowrap;
      border: 0;
    }
  </style>
  <script src="<?php echo h(base_path('assets/js/paste.js')); ?>?v=<?php echo h($jsVer); ?>" defer></script>
</body>
</html>
