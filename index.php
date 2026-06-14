<?php
// ─── Configuration ────────────────────────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('CODE_LENGTH', 6);
define('MAX_FILE_SIZE', 20 * 1024 * 1024); // 20MB
define('DEFAULT_EXPIRY_HOURS', 24);
define('DATA_FILE', UPLOAD_DIR . 'shares.json');

// ─── Helpers ──────────────────────────────────────────────────────────────────
function generateCode(): string {
    return strtoupper(substr(bin2hex(random_bytes(4)), 0, CODE_LENGTH));
}

function loadShares(): array {
    if (!file_exists(DATA_FILE)) return [];
    $data = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($data) ? $data : [];
}

function saveShares(array $shares): void {
    file_put_contents(DATA_FILE, json_encode($shares, JSON_PRETTY_PRINT));
}

function purgeExpired(array $shares): array {
    return array_filter($shares, fn($s) => $s['expires_at'] > time());
}

function humanSize(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

function timeLeft(int $expiresAt): string {
    $diff = $expiresAt - time();
    if ($diff <= 0) return 'Expired';
    if ($diff < 3600) return round($diff / 60) . 'm left';
    if ($diff < 86400) return round($diff / 3600, 1) . 'h left';
    return round($diff / 86400, 1) . 'd left';
}

// ─── Init ─────────────────────────────────────────────────────────────────────
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
$shares = purgeExpired(loadShares());
$action = $_GET['action'] ?? '';
$code   = strtoupper(trim($_GET['code'] ?? ''));
$error  = '';
$success= '';
$share  = null;

// ─── POST: Create a share ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type   = $_POST['type'] ?? 'text';
    $expiry = max(0.5, min(168, (float)($_POST['expiry'] ?? 0.5)));
    $newCode = generateCode();
    // Make sure it's unique
    while (isset($shares[$newCode])) $newCode = generateCode();

    if ($type === 'text') {
        $text = trim($_POST['text'] ?? '');
        if ($text === '') {
            $error = 'Please enter some text to share.';
        } else {
            $shares[$newCode] = [
                'type'       => 'text',
                'content'    => $text,
                'created_at' => time(),
                'expires_at' => time() + ($expiry * 3600),
                'downloads'  => 0,
            ];
            saveShares($shares);
            header("Location: ?action=created&code=$newCode");
            exit;
        }
    } elseif ($type === 'file') {
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
            $error = 'File upload failed. Please try again.';
        } elseif ($file['size'] > MAX_FILE_SIZE) {
            $error = 'File exceeds the 20 MB limit.';
        } else {
            $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $stored   = $newCode . ($ext ? ".$ext" : '');
            move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $stored);
            $shares[$newCode] = [
                'type'        => 'file',
                'filename'    => $file['name'],
                'stored'      => $stored,
                'size'        => $file['size'],
                'mime'        => $file['type'],
                'created_at'  => time(),
                'expires_at'  => time() + ($expiry * 3600),
                'downloads'   => 0,
            ];
            saveShares($shares);
            header("Location: ?action=created&code=$newCode");
            exit;
        }
    }
}

// ─── GET: Download a file ─────────────────────────────────────────────────────
if ($action === 'download' && $code && isset($shares[$code])) {
    $s = $shares[$code];
    if ($s['type'] === 'file') {
        $path = UPLOAD_DIR . $s['stored'];
        if (file_exists($path)) {
            $shares[$code]['downloads']++;
            saveShares($shares);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes($s['filename']) . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        }
    }
}

// ─── GET: Retrieve a share ────────────────────────────────────────────────────
if ($action === 'retrieve' && $code) {
    if (isset($shares[$code])) {
        $share = $shares[$code];
        $share['code'] = $code;
        if ($share['type'] === 'text') {
            $shares[$code]['downloads']++;
            saveShares($shares);
        }
    } else {
        $error = 'No share found with that code. It may have expired or the code is incorrect.';
    }
}

$createdCode = ($action === 'created' && $code && isset($shares[$code])) ? $code : null;
$createdShare = $createdCode ? $shares[$createdCode] : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DropCode — Share files & text instantly</title>
<style>
  /* ── Tokens ── */
  :root {
    --bg:       #0d0d0f;
    --surface:  #16161a;
    --surface2: #1e1e24;
    --border:   #2a2a35;
    --accent:   #7c6af7;
    --accent2:  #a594ff;
    --text:     #e8e8f0;
    --muted:    #7a7a92;
    --danger:   #f75a5a;
    --success:  #4ade80;
    --mono:     'JetBrains Mono', 'Fira Code', 'Cascadia Code', monospace;
    --sans:     'Inter', 'DM Sans', system-ui, sans-serif;
    --radius:   12px;
    --radius-sm: 8px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--sans);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 24px 16px 64px;
    line-height: 1.6;
  }

  /* ── Header ── */
  header {
    width: 100%;
    max-width: 640px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px 0 40px;
  }
  .logo {
    font-size: 1.2rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--text);
    text-decoration: none;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .logo span {
    display: inline-block;
    width: 28px; height: 28px;
    background: var(--accent);
    border-radius: 7px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
  }
  .logo em { color: var(--accent2); font-style: normal; }

  /* ── Main container ── */
  .container { width: 100%; max-width: 640px; }

  /* ── Tabs ── */
  .tabs {
    display: flex;
    gap: 4px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 5px;
    margin-bottom: 24px;
  }
  .tab-btn {
    flex: 1;
    padding: 10px;
    border: none;
    background: transparent;
    color: var(--muted);
    font-family: var(--sans);
    font-size: 0.9rem;
    font-weight: 500;
    border-radius: var(--radius-sm);
    cursor: pointer;
    transition: all 0.18s ease;
  }
  .tab-btn.active {
    background: var(--surface2);
    color: var(--text);
    box-shadow: 0 1px 4px rgba(0,0,0,0.4);
  }
  .tab-btn:hover:not(.active) { color: var(--text); }

  /* ── Cards ── */
  .card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 28px;
    margin-bottom: 20px;
  }

  /* ── Form elements ── */
  label {
    display: block;
    font-size: 0.82rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.06em;
    margin-bottom: 8px;
  }
  textarea, input[type="text"], select {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    color: var(--text);
    font-family: var(--sans);
    font-size: 0.95rem;
    padding: 12px 14px;
    transition: border-color 0.15s;
    outline: none;
    -webkit-appearance: none;
  }
  textarea:focus, input[type="text"]:focus, select:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(124,106,247,0.15);
  }
  textarea {
    min-height: 160px;
    resize: vertical;
    font-family: var(--mono);
    font-size: 0.9rem;
    line-height: 1.7;
  }
  select {
    cursor: pointer;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%237a7a92' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 12px center;
    padding-right: 36px;
  }

  .field { margin-bottom: 20px; }
  .field:last-of-type { margin-bottom: 0; }

  /* ── File drop zone ── */
  .drop-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius-sm);
    padding: 36px 24px;
    text-align: center;
    cursor: pointer;
    transition: all 0.18s ease;
    position: relative;
  }
  .drop-zone:hover, .drop-zone.drag-over {
    border-color: var(--accent);
    background: rgba(124,106,247,0.05);
  }
  .drop-zone input[type="file"] {
    position: absolute; inset: 0;
    opacity: 0; cursor: pointer; width: 100%; height: 100%;
  }
  .drop-icon { font-size: 2rem; margin-bottom: 10px; display: block; }
  .drop-zone p { color: var(--muted); font-size: 0.9rem; }
  .drop-zone p strong { color: var(--accent2); }
  .file-selected { color: var(--success) !important; font-weight: 600; }

  /* ── Row layout ── */
  .row { display: flex; gap: 12px; }
  .row .field { flex: 1; margin-bottom: 0; }

  /* ── Button ── */
  .btn {
    width: 100%;
    padding: 14px;
    border: none;
    border-radius: var(--radius-sm);
    font-family: var(--sans);
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.18s ease;
    letter-spacing: 0.01em;
    margin-top: 24px;
  }
  .btn-primary {
    background: var(--accent);
    color: #fff;
  }
  .btn-primary:hover {
    background: var(--accent2);
    transform: translateY(-1px);
    box-shadow: 0 4px 20px rgba(124,106,247,0.35);
  }
  .btn-primary:active { transform: translateY(0); }

  /* ── Code display ── */
  .code-display {
    text-align: center;
    padding: 32px 24px;
  }
  .code-display p { color: var(--muted); font-size: 0.88rem; margin-bottom: 20px; }
  .code-value {
    font-family: var(--mono);
    font-size: 2.8rem;
    font-weight: 700;
    letter-spacing: 0.22em;
    color: var(--text);
    background: var(--bg);
    border: 2px solid var(--accent);
    border-radius: var(--radius);
    padding: 16px 28px;
    display: inline-block;
    margin-bottom: 12px;
    cursor: pointer;
    transition: all 0.18s ease;
    user-select: all;
  }
  .code-value:hover { border-color: var(--accent2); box-shadow: 0 0 30px rgba(124,106,247,0.2); }
  .copy-hint { font-size: 0.8rem; color: var(--muted); }
  .copy-hint.copied { color: var(--success); }
  .meta-row {
    display: flex;
    justify-content: center;
    gap: 20px;
    margin-top: 20px;
    flex-wrap: wrap;
  }
  .meta-pill {
    font-size: 0.8rem;
    color: var(--muted);
    background: var(--surface2);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 4px 12px;
  }

  /* ── Retrieve pane ── */
  .retrieve-pane { display: none; }
  .retrieve-pane.show { display: block; }

  /* ── Result / preview ── */
  .result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 16px;
    flex-wrap: wrap;
    gap: 8px;
  }
  .result-header h3 { font-size: 1rem; font-weight: 600; }
  .result-type {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    padding: 3px 10px;
    border-radius: 20px;
    border: 1px solid;
  }
  .type-text { color: #60a5fa; border-color: #60a5fa40; background: #60a5fa10; }
  .type-file { color: #f59e0b; border-color: #f59e0b40; background: #f59e0b10; }

  .text-content {
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 16px;
    font-family: var(--mono);
    font-size: 0.88rem;
    line-height: 1.7;
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 320px;
    overflow-y: auto;
    color: var(--text);
  }

  .file-info {
    display: flex;
    align-items: center;
    gap: 16px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius-sm);
    padding: 20px;
  }
  .file-icon { font-size: 2.2rem; }
  .file-meta { flex: 1; min-width: 0; }
  .file-name { font-weight: 600; font-size: 0.95rem; word-break: break-all; }
  .file-size { font-size: 0.82rem; color: var(--muted); margin-top: 2px; }
  .btn-download {
    background: var(--success);
    color: #000;
    font-weight: 700;
    padding: 10px 20px;
    border: none;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 0.88rem;
    text-decoration: none;
    display: inline-block;
    transition: opacity 0.15s;
    white-space: nowrap;
  }
  .btn-download:hover { opacity: 0.85; }

  /* ── Code input ── */
  .code-input {
    font-family: var(--mono);
    font-size: 1.6rem !important;
    font-weight: 700;
    letter-spacing: 0.18em;
    text-transform: uppercase;
    text-align: center;
    padding: 16px !important;
  }

  /* ── Alert ── */
  .alert {
    padding: 14px 16px;
    border-radius: var(--radius-sm);
    font-size: 0.9rem;
    margin-bottom: 20px;
  }
  .alert-error { background: rgba(247,90,90,0.12); border: 1px solid rgba(247,90,90,0.3); color: #fca5a5; }
  .alert-success { background: rgba(74,222,128,0.1); border: 1px solid rgba(74,222,128,0.3); color: var(--success); }

  /* ── Divider ── */
  .section-label {
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 12px;
  }
  .section-label::before, .section-label::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--border);
  }

  /* ── Hidden ── */
  .hidden { display: none !important; }

  /* ── Footer ── */
  footer {
    margin-top: 48px;
    text-align: center;
    color: var(--muted);
    font-size: 0.8rem;
  }
  footer a { color: var(--muted); }

  /* ── Scrollbar ── */
  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: transparent; }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  @media (max-width: 480px) {
    .code-value { font-size: 2rem; letter-spacing: 0.18em; }
    .row { flex-direction: column; }
    header { padding-bottom: 24px; }
  }
</style>
</head>
<body>

<header>
  <a class="logo" href="?">
    <span>📦</span>
    Drop<em>Code</em>
  </a>
</header>

<main class="container">

  <?php if ($error): ?>
  <div class="alert alert-error">⚠ <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- ── Created confirmation ────────────────────────────────────────── -->
  <?php if ($createdCode && $createdShare): ?>
  <div class="card">
    <div class="code-display">
      <p>Your share is ready. Give this code to anyone.</p>
      <div class="code-value" id="codeVal" onclick="copyCode()"><?= htmlspecialchars($createdCode) ?></div>
      <div class="copy-hint" id="copyHint">Click to copy</div>
      <div class="meta-row">
        <span class="meta-pill">⏱ <?= timeLeft($createdShare['expires_at']) ?></span>
        <?php if ($createdShare['type'] === 'file'): ?>
        <span class="meta-pill">📄 <?= htmlspecialchars($createdShare['filename']) ?></span>
        <span class="meta-pill"><?= humanSize($createdShare['size']) ?></span>
        <?php else: ?>
        <span class="meta-pill">📝 Text</span>
        <span class="meta-pill"><?= strlen($createdShare['content']) ?> chars</span>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div style="text-align:center; margin-top: 8px;">
    <a href="?" style="color: var(--muted); font-size: 0.88rem; text-decoration: none;">← Share something else</a>
  </div>

  <!-- ── Retrieved share ───────────────────────────────────────────── -->
  <?php elseif ($share): ?>
  <div class="card">
    <div class="result-header">
      <h3>Here's your share</h3>
      <span class="result-type <?= $share['type'] === 'text' ? 'type-text' : 'type-file' ?>">
        <?= $share['type'] === 'text' ? '📝 Text' : '📁 File' ?>
      </span>
    </div>

    <?php if ($share['type'] === 'text'): ?>
      <div class="text-content"><?= htmlspecialchars($share['content']) ?></div>
      <button class="btn btn-primary" style="margin-top: 16px;" onclick="copyText(this)">Copy text</button>

    <?php else: ?>
      <div class="file-info">
        <span class="file-icon">📄</span>
        <div class="file-meta">
          <div class="file-name"><?= htmlspecialchars($share['filename']) ?></div>
          <div class="file-size"><?= humanSize($share['size']) ?></div>
        </div>
        <a class="btn-download" href="?action=download&code=<?= urlencode($share['code']) ?>">⬇ Download</a>
      </div>
    <?php endif; ?>

    <div class="meta-row" style="justify-content:flex-start; margin-top: 16px;">
      <span class="meta-pill">⏱ <?= timeLeft($share['expires_at']) ?></span>
      <span class="meta-pill">📥 <?= $share['downloads'] ?> access<?= $share['downloads'] !== 1 ? 'es' : '' ?></span>
    </div>
  </div>
  <div style="text-align:center; margin-top: 8px;">
    <a href="?" style="color: var(--muted); font-size: 0.88rem; text-decoration: none;">← Share something</a>
  </div>

  <!-- ── Main UI ────────────────────────────────────────────────────── -->
  <?php else: ?>

  <!-- Tabs -->
  <div class="tabs">
    <button class="tab-btn active" onclick="switchTab('share', this)">Share</button>
    <button class="tab-btn" onclick="switchTab('retrieve', this)">Retrieve</button>
  </div>

  <!-- Share pane -->
  <div id="sharePane">
    <div class="card">
      <!-- Type switcher -->
      <div class="tabs type-tabs" style="margin-bottom: 24px;">
        <button class="tab-btn type-btn active" onclick="switchType('text', this)">📝 Text</button>
        <button class="tab-btn type-btn" onclick="switchType('file', this)">📁 File</button>
      </div>

      <!-- Text form -->
      <form method="POST" id="textForm">
        <input type="hidden" name="type" value="text">
        <div class="field">
          <label for="textContent">Your text</label>
          <textarea name="text" id="textContent" placeholder="Paste or type anything — a note, a snippet, a secret…"><?= htmlspecialchars($_POST['text'] ?? '') ?></textarea>
        </div>
        <div class="field">
          <label for="textExpiry">Expires after</label>
          <select name="expiry" id="textExpiry">
            <option value="0.5" selected>30 minutes</option>
            <option value="1">1 hour</option>
            <option value="6">6 hours</option>
            <option value="24">24 hours</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary">Generate share code →</button>
      </form>

      <!-- File form -->
      <form method="POST" id="fileForm" enctype="multipart/form-data" class="hidden">
        <input type="hidden" name="type" value="file">
        <div class="field">
          <label>File (max 20 MB)</label>
          <div class="drop-zone" id="dropZone">
            <input type="file" name="file" id="fileInput" onchange="fileChosen(this)">
            <span class="drop-icon">☁️</span>
            <p id="dropText">Drop a file here, or <strong>browse</strong></p>
          </div>
        </div>
        <div class="field">
          <label for="fileExpiry">Expires after</label>
          <select name="expiry" id="fileExpiry">
            <option value="0.5" selected>30 minutes</option>
            <option value="1">1 hour</option>
            <option value="6">6 hours</option>
            <option value="24">24 hours</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" id="fileSubmitBtn" disabled>Choose a file first</button>
      </form>
    </div>
  </div>

  <!-- Retrieve pane -->
  <div id="retrievePane" class="hidden">
    <div class="card">
      <form method="GET">
        <input type="hidden" name="action" value="retrieve">
        <div class="field">
          <label for="codeIn">Enter your code</label>
          <input
            type="text"
            name="code"
            id="codeIn"
            class="code-input"
            maxlength="6"
            placeholder="A1B2C3"
            value="<?= htmlspecialchars($_GET['code'] ?? '') ?>"
            autocomplete="off"
            spellcheck="false"
          >
        </div>
        <button type="submit" class="btn btn-primary">Retrieve →</button>
      </form>
    </div>
  </div>

  <?php endif; ?>

</main>

<footer>
  <p>Files auto-delete when they expire · No account needed · Max 20 MB per file</p>
    <p>Designed & Developed by <a href="https://nikesh41.com.np" target="_blank" rel="noopener noreferrer" style="color:var(--text);">Nikesh Adhikari</a></p>
</footer>

<script>
// ── Tab switching ────────────────────────────────────────────────────────────
function switchTab(tab, btn) {
  document.querySelectorAll('.tabs > .tab-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('sharePane').classList.toggle('hidden', tab !== 'share');
  document.getElementById('retrievePane').classList.toggle('hidden', tab !== 'retrieve');
  if (tab === 'retrieve') {
    document.getElementById('codeIn')?.focus();
  }
  if (tab === 'share') {
    // Restore active state on the correct type button
    document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
    const activeType = currentType === 'file' ? 'file' : 'text';
    document.querySelector(`.type-btn[onclick*="${activeType}"]`)?.classList.add('active');
  }
}

// ── Type switching (text/file) ───────────────────────────────────────────────
let currentType = 'text';
function switchType(type, btn) {
  currentType = type;
  document.querySelectorAll('.type-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  document.getElementById('textForm').classList.toggle('hidden', type !== 'text');
  document.getElementById('fileForm').classList.toggle('hidden', type !== 'file');
}

// ── File chosen ──────────────────────────────────────────────────────────────
function fileChosen(input) {
  const file = input.files[0];
  if (file) {
    document.getElementById('dropText').innerHTML =
      '<span class="file-selected">✓ ' + file.name + '</span>';
    const btn = document.getElementById('fileSubmitBtn');
    btn.disabled = false;
    btn.textContent = 'Share ' + file.name + ' →';
  }
}

// Drag-and-drop highlighting
const dz = document.getElementById('dropZone');
if (dz) {
  dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('drag-over'); });
  dz.addEventListener('dragleave', () => dz.classList.remove('drag-over'));
  dz.addEventListener('drop', () => dz.classList.remove('drag-over'));
}

// ── Universal copy (works on HTTP and HTTPS) ─────────────────────────────────
function copyToClipboard(text, onSuccess) {
  if (navigator.clipboard && window.isSecureContext) {
    // HTTPS / localhost — use modern API
    navigator.clipboard.writeText(text).then(onSuccess).catch(() => fallbackCopy(text, onSuccess));
  } else {
    // HTTP — use legacy execCommand fallback
    fallbackCopy(text, onSuccess);
  }
}

function fallbackCopy(text, onSuccess) {
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;left:-9999px;top:-9999px;opacity:0';
  document.body.appendChild(ta);
  ta.focus();
  ta.select();
  try {
    document.execCommand('copy');
    onSuccess();
  } catch(e) {
    alert('Copy failed. Please select and copy manually.');
  }
  document.body.removeChild(ta);
}

// ── Copy code ────────────────────────────────────────────────────────────────
function copyCode() {
  const code = document.getElementById('codeVal').textContent.trim();
  copyToClipboard(code, () => {
    const hint = document.getElementById('copyHint');
    hint.textContent = '✓ Copied!';
    hint.classList.add('copied');
    setTimeout(() => { hint.textContent = 'Click to copy'; hint.classList.remove('copied'); }, 2000);
  });
}

// ── Copy text content ────────────────────────────────────────────────────────
function copyText(btn) {
  const content = document.querySelector('.text-content')?.textContent;
  if (!content) return;
  copyToClipboard(content, () => {
    const orig = btn.textContent;
    btn.textContent = '✓ Copied!';
    setTimeout(() => btn.textContent = orig, 2000);
  });
}

// ── Auto-uppercase code input ────────────────────────────────────────────────
const codeIn = document.getElementById('codeIn');
if (codeIn) {
  codeIn.addEventListener('input', () => {
    const pos = codeIn.selectionStart;
    codeIn.value = codeIn.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    codeIn.setSelectionRange(pos, pos);
  });
}
</script>
</body>
</html>
