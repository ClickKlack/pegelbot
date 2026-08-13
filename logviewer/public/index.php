<?php

declare(strict_types=1);

// ============================================================================
//  Log-Betrachter
//
//  Die Anmeldung uebernimmt der Webserver per HTTP-Basic-Auth, siehe .htaccess.
//  Diese Datei enthaelt deshalb keinerlei Kennwortlogik mehr.
// ============================================================================

use LogViewer\LogReader;

// LogReader hat keine externen Abhaengigkeiten und wird bewusst direkt geladen,
// damit logviewer/ auch ohne Composer-Autoload ausgerollt werden kann.
require_once __DIR__ . '/../src/LogReader.php';

// ---------- Konfiguration ----------
// Liegt oberhalb des Dokumentenstamms und ist damit nicht ueber HTTP erreichbar.
$configFile = __DIR__ . '/../config.php';

if (!is_readable($configFile)) {
    http_response_code(500);
    exit('Konfiguration fehlt. Bitte config.sample.php nach config.php kopieren.');
}

$config = require $configFile;

$logPrefix = (string) $config['logPrefix'];   // Dateiname-Präfix: pegelbot-YYYY-MM-DD.log
$tailLines = (int) $config['tailLines'];      // Maximale Zeilen pro Datei
$interval  = (int) $config['interval'];       // Auto-Refresh in ms, mindestens 500
$timezone  = (string) $config['timezone'];

// ---------- Sicherung der Anmeldung ----------
// Die Anmeldung erledigt der Webserver. Faellt die .htaccess beim Ausrollen unter
// den Tisch, waere der Betrachter ohne diese Pruefung still und leise offen - und
// er liefert Logs aus, die E-Mail-Adressen von Abonnenten enthalten. Lieber
// sichtbar kaputt als unbemerkt offen.
if (($config['requireAuth'] ?? true) === true) {
    // Je nach PHP-Anbindung (Modul, CGI, FastCGI) steht der angemeldete Benutzer
    // in einer anderen Variablen.
    $authUser = $_SERVER['PHP_AUTH_USER']
        ?? $_SERVER['REMOTE_USER']
        ?? $_SERVER['REDIRECT_REMOTE_USER']
        ?? '';

    if ($authUser === '') {
        http_response_code(500);
        exit(
            'Zugriffsschutz nicht aktiv. Bitte .htaccess einrichten '
            . '(Vorlage: .htaccess.sample) oder requireAuth in config.php abschalten.'
        );
    }
}

date_default_timezone_set($timezone);

if ($interval < 500) {
    $interval = 500;
}

$reader = new LogReader((string) $config['logFolder'], $logPrefix);

// ---------- AJAX-Endpunkte ----------

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');

    $todayFile = $reader->fileNameForDay();

    if ($_GET['api'] === 'list') {
        $list = [];
        foreach ($reader->listFiles() as $path) {
            $name = basename($path);
            $list[] = [
                'path'  => $name,
                'label' => $reader->formatDate($path),
                'size'  => $reader->fileSize($path),
                'today' => $name === $todayFile,
            ];
        }
        echo json_encode($list);
        exit;
    }

    if ($_GET['api'] === 'file' && !empty($_GET['name'])) {
        // Die Pruefung des Dateinamens steckt im LogReader und ist dort getestet
        $path = $reader->resolvePath((string) $_GET['name']);

        if ($path === null) {
            http_response_code(400);
            echo json_encode(['error' => 'invalid']);
            exit;
        }

        echo json_encode([
            'lines' => $reader->tail($path, $tailLines),
            'label' => $reader->formatDate($path),
        ]);
        exit;
    }

    if ($_GET['api'] === 'combined') {
        $limit  = isset($_GET['limit']) ? max(1, min(10, (int) $_GET['limit'])) : 3;
        $result = [];

        foreach (array_slice($reader->listFiles(), 0, $limit) as $path) {
            $name = basename($path);
            $result[] = [
                'label' => $reader->formatDate($path),
                'name'  => $name,
                'lines' => $reader->tail($path, $tailLines),
                'today' => $name === $todayFile,
            ];
        }

        echo json_encode($result);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'unknown api']);
    exit;
}
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Log Viewer · <?php echo htmlspecialchars($logPrefix); ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,300;0,400;0,700;1,400&family=Syne:wght@700;800&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* ---- Dark theme (default) ---- */
:root {
  --bg:          #080b0f;
  --surface:     #0e1318;
  --border:      #1e2730;
  --border2:     #2a3440;
  --text:        #b0bec5;
  --text-dim:    #4a5568;
  --text-bright: #e0e8f0;
  --accent:      #00e5a0;
  --accent2:     #0096ff;
  --debug:       #9c6fff;
  --info:        #29b6f6;
  --warn:        #ffb300;
  --err:         #ff5252;
  --today:       rgba(0,229,160,0.08);
  --radius:      5px;
  --mono:        'JetBrains Mono', monospace;
  --display:     'Syne', sans-serif;
  --scanline:    rgba(0,0,0,0.03);
  --shadow:      0 16px 48px rgba(0,0,0,0.6);
}

/* ---- Light theme ---- */
:root.light {
  --bg:          #f0f4f8;
  --surface:     #ffffff;
  --border:      #d0d7de;
  --border2:     #b0bac4;
  --text:        #3d4a56;
  --text-dim:    #8896a4;
  --text-bright: #1a242e;
  --accent:      #00a372;
  --accent2:     #0064cc;
  --debug:       #7c4dff;
  --info:        #0186c4;
  --warn:        #c47d00;
  --err:         #d93025;
  --today:       rgba(0,163,114,0.08);
  --scanline:    rgba(0,0,0,0.012);
  --shadow:      0 8px 32px rgba(0,0,0,0.12);
}

html { scroll-behavior: smooth; }

body {
  background: var(--bg);
  color: var(--text);
  font-family: var(--mono);
  font-size: 13px;
  line-height: 1.7;
  min-height: 100vh;
}

/* ---- Scanline overlay ---- */
body::before {
  content: '';
  position: fixed; inset: 0;
  background: repeating-linear-gradient(
    0deg, transparent, transparent 2px,
    rgba(0,0,0,0.03) 2px, rgba(0,0,0,0.03) 4px
  );
  pointer-events: none; z-index: 9999; transition: opacity .3s;
}

/* ---- Layout ---- */
.layout { display: flex; height: 100vh; overflow: hidden; }

/* ---- Sidebar ---- */
.sidebar {
  width: 220px; flex-shrink: 0;
  background: var(--surface);
  border-right: 1px solid var(--border);
  display: flex; flex-direction: column;
  overflow: hidden;
}
.sidebar-header {
  padding: 1.2rem 1rem 0.8rem;
  border-bottom: 1px solid var(--border);
}
.sidebar-header .logo {
  font-family: var(--display); font-size: 1.1rem;
  color: var(--accent); letter-spacing: -0.02em;
  line-height: 1;
}
.sidebar-header .sub {
  font-size: 0.65rem; color: var(--text-dim);
  margin-top: 0.2rem; letter-spacing: 0.08em;
}
.sidebar-nav {
  padding: 0.8rem 0.5rem;
  border-bottom: 1px solid var(--border);
  display: flex; gap: 0.4rem;
}
.mode-btn {
  flex: 1; padding: 0.4rem 0.3rem;
  background: transparent; border: 1px solid var(--border2);
  border-radius: var(--radius); color: var(--text-dim);
  font-family: var(--mono); font-size: 0.65rem;
  font-weight: 700; letter-spacing: 0.06em;
  cursor: pointer; transition: all .2s; text-align: center;
}
.mode-btn.active, .mode-btn:hover {
  border-color: var(--accent); color: var(--accent);
  background: rgba(0,229,160,0.06);
}
.files-list {
  flex: 1; overflow-y: auto; padding: 0.5rem 0;
}
.files-list::-webkit-scrollbar { width: 4px; }
.files-list::-webkit-scrollbar-track { background: transparent; }
.files-list::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 2px; }

.file-item {
  display: flex; align-items: center; gap: 0.5rem;
  padding: 0.45rem 1rem; cursor: pointer;
  transition: background .15s; border-left: 2px solid transparent;
  color: var(--text-dim); font-size: 0.75rem;
}
.file-item:hover { background: rgba(255,255,255,0.03); color: var(--text); }
.file-item.active {
  border-left-color: var(--accent); background: rgba(0,229,160,0.05);
  color: var(--text-bright);
}
.file-item .dot {
  width: 6px; height: 6px; border-radius: 50%;
  background: var(--text-dim); flex-shrink: 0;
}
.file-item.today .dot { background: var(--accent); box-shadow: 0 0 6px var(--accent); }
.file-item .size { margin-left: auto; font-size: 0.62rem; color: var(--text-dim); }

.combined-opts {
  padding: 0.5rem 0.8rem 0.8rem;
  border-bottom: 1px solid var(--border);
  display: none;
}
.combined-opts label { font-size: 0.68rem; color: var(--text-dim); }
.combined-opts select {
  width: 100%; margin-top: 0.3rem; padding: 0.35rem 0.5rem;
  background: var(--bg); border: 1px solid var(--border2);
  border-radius: var(--radius); color: var(--text); font-family: var(--mono);
  font-size: 0.75rem; cursor: pointer; outline: none;
}

.sidebar-footer {
  padding: 0.8rem 1rem;
  border-top: 1px solid var(--border);
  display: flex; align-items: center; gap: 0.5rem;
}
.logout-btn {
  flex: 1; padding: 0.35rem; background: transparent;
  border: 1px solid var(--border2); border-radius: var(--radius);
  color: var(--text-dim); font-family: var(--mono); font-size: 0.68rem;
  cursor: pointer; transition: all .2s;
}
.logout-btn:hover { border-color: var(--err); color: var(--err); }
.refresh-indicator {
  width: 8px; height: 8px; border-radius: 50%;
  background: var(--text-dim); transition: background .3s;
}
.refresh-indicator.active { background: var(--accent); box-shadow: 0 0 8px var(--accent); }

/* ---- Main ---- */
.main { flex: 1; display: flex; flex-direction: column; overflow: hidden; }

.topbar {
  padding: 0.7rem 1.2rem;
  border-bottom: 1px solid var(--border);
  display: flex; align-items: center; gap: 1rem;
  background: var(--surface);
  flex-shrink: 0;
}
.topbar .title {
  font-family: var(--display); font-size: 0.9rem;
  color: var(--text-bright); flex: 1;
}
.topbar .meta { font-size: 0.68rem; color: var(--text-dim); }
.scroll-lock-btn {
  padding: 0.3rem 0.7rem;
  background: rgba(0,229,160,0.1); border: 1px solid rgba(0,229,160,0.3);
  border-radius: var(--radius); color: var(--accent);
  font-family: var(--mono); font-size: 0.68rem; font-weight: 700;
  cursor: pointer; transition: all .2s; letter-spacing: 0.05em;
}
.scroll-lock-btn.off {
  background: transparent; border-color: var(--border2); color: var(--text-dim);
}
.scroll-lock-btn:hover { opacity: 0.8; }

.search-bar {
  padding: 0.5rem 1.2rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface); flex-shrink: 0;
}
.search-bar input {
  width: 100%; padding: 0.4rem 0.7rem;
  background: var(--bg); border: 1px solid var(--border2);
  border-radius: var(--radius); color: var(--text);
  font-family: var(--mono); font-size: 0.8rem; outline: none;
  transition: border-color .2s;
}
.search-bar input:focus { border-color: var(--accent2); }
.search-bar input::placeholder { color: var(--text-dim); }

.log-container {
  flex: 1; overflow-y: auto; padding: 0.5rem 0;
}
.log-container::-webkit-scrollbar { width: 5px; }
.log-container::-webkit-scrollbar-track { background: transparent; }
.log-container::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }

/* ---- Day block (combined view) ---- */
.day-block { margin-bottom: 0.5rem; }
.day-header {
  position: sticky; top: 0; z-index: 10;
  padding: 0.4rem 1.2rem;
  background: linear-gradient(90deg, rgba(0,229,160,0.12), transparent);
  border-left: 3px solid var(--accent);
  font-family: var(--display); font-size: 0.8rem;
  color: var(--accent); letter-spacing: 0.06em;
}
.day-block.today .day-header {
  background: linear-gradient(90deg, rgba(0,229,160,0.18), transparent);
}

/* ---- Log lines ---- */
.log-line {
  display: flex; padding: 0.1rem 1.2rem;
  font-size: 0.78rem; transition: background .1s;
  border-left: 2px solid transparent;
}
.log-line:hover { background: rgba(255,255,255,0.025); }
.log-line .ln {
  color: var(--text-dim); min-width: 3.5rem; flex-shrink: 0;
  user-select: none; padding-right: 0.8rem; text-align: right;
}
.log-line .txt { word-break: break-all; }

/* Log-Level Highlighting */
.log-line.lvl-debug { border-left-color: var(--debug); }
.log-line.lvl-debug .txt { color: #b39ddb; }
.log-line.lvl-debug .badge { background: rgba(156,111,255,0.15); color: var(--debug); }

.log-line.lvl-info { border-left-color: var(--info); }
.log-line.lvl-info .txt { color: #80d8ff; }
.log-line.lvl-info .badge { background: rgba(41,182,246,0.15); color: var(--info); }

.log-line.lvl-warn { border-left-color: var(--warn); background: rgba(255,179,0,0.03); }
.log-line.lvl-warn .txt { color: #ffd740; }
.log-line.lvl-warn .badge { background: rgba(255,179,0,0.15); color: var(--warn); }

.log-line.lvl-error { border-left-color: var(--err); background: rgba(255,82,82,0.05); }
.log-line.lvl-error .txt { color: #ff8a80; }
.log-line.lvl-error .badge { background: rgba(255,82,82,0.15); color: var(--err); }

/* Badge pill for level label */
.log-line .badge {
  display: inline-block; flex-shrink: 0;
  font-size: 0.6rem; font-weight: 700; letter-spacing: 0.08em;
  padding: 0.1em 0.45em; border-radius: 3px;
  margin-right: 0.5rem; vertical-align: middle;
  font-family: var(--mono);
}

/* ---- Theme toggle ---- */
.theme-btn {
  padding: 0.3rem 0.6rem;
  background: transparent; border: 1px solid var(--border2);
  border-radius: var(--radius); color: var(--text-dim);
  font-family: var(--mono); font-size: 0.75rem;
  cursor: pointer; transition: all .2s;
  line-height: 1; user-select: none;
}
.theme-btn:hover { border-color: var(--accent); color: var(--accent); }

/* Smooth theme transitions */
body, .sidebar, .topbar, .search-bar, .sidebar-header,
.sidebar-nav, .combined-opts, .sidebar-footer,
.file-item, .log-line, .day-header, .login-box {
  transition: background-color .25s, color .25s, border-color .25s;
}
/* ---- Filter bar ---- */
.filter-bar {
  padding: 0.45rem 1.2rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
  display: flex; align-items: center; gap: 0.5rem;
  flex-shrink: 0; flex-wrap: wrap;
}
.filter-bar .filter-label {
  font-size: 0.65rem; color: var(--text-dim);
  letter-spacing: 0.07em; font-weight: 700;
  margin-right: 0.2rem; white-space: nowrap;
}
.lvl-btn {
  padding: 0.2rem 0.65rem;
  border-radius: 3px; border: 1px solid transparent;
  font-family: var(--mono); font-size: 0.65rem; font-weight: 700;
  letter-spacing: 0.07em; cursor: pointer;
  transition: opacity .15s, transform .1s, background .2s;
  user-select: none;
}
.lvl-btn:active { transform: scale(0.94); }
.lvl-btn.off { opacity: 0.22; filter: grayscale(0.6); }

.lvl-btn.btn-debug   { background: rgba(156,111,255,0.15); border-color: rgba(156,111,255,0.45); color: #b39ddb; }
.lvl-btn.btn-info    { background: rgba(41,182,246,0.15);  border-color: rgba(41,182,246,0.45);  color: #80d8ff; }
.lvl-btn.btn-warn    { background: rgba(255,179,0,0.15);   border-color: rgba(255,179,0,0.45);   color: #ffd740; }
.lvl-btn.btn-error   { background: rgba(255,82,82,0.15);   border-color: rgba(255,82,82,0.45);   color: #ff8a80; }
.lvl-btn.btn-default { background: rgba(176,190,197,0.1);  border-color: rgba(176,190,197,0.3);  color: var(--text-dim); }

:root.light .lvl-btn.btn-debug   { background: rgba(124,77,255,0.1);  border-color: rgba(124,77,255,0.4);  color: #7c4dff; }
:root.light .lvl-btn.btn-info    { background: rgba(1,134,196,0.1);   border-color: rgba(1,134,196,0.4);   color: #0186c4; }
:root.light .lvl-btn.btn-warn    { background: rgba(196,125,0,0.1);   border-color: rgba(196,125,0,0.4);   color: #c47d00; }
:root.light .lvl-btn.btn-error   { background: rgba(217,48,37,0.1);   border-color: rgba(217,48,37,0.4);   color: #d93025; }
:root.light .lvl-btn.btn-default { background: rgba(100,120,140,0.1); border-color: rgba(100,120,140,0.35); color: var(--text-dim); }

.filter-sep { flex: 1; }
.filter-reset {
  font-family: var(--mono); font-size: 0.62rem; color: var(--text-dim);
  background: transparent; border: none; cursor: pointer;
  letter-spacing: 0.05em; padding: 0.2rem 0.3rem;
  transition: color .2s; white-space: nowrap;
}
.filter-reset:hover { color: var(--accent); }

.hl { background: rgba(255,214,0,0.3); color: #fff; border-radius: 2px; padding: 0 1px; }

/* Empty / Loading */
.state-msg {
  display: flex; align-items: center; justify-content: center;
  height: 200px; color: var(--text-dim); font-size: 0.85rem;
  letter-spacing: 0.05em;
}
.spinner {
  display: inline-block; width: 14px; height: 14px;
  border: 2px solid var(--border2); border-top-color: var(--accent);
  border-radius: 50%; animation: spin 0.6s linear infinite;
  margin-right: 0.7rem;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ---- Responsive ---- */
@media (max-width: 600px) {
  .sidebar { width: 170px; }
  .log-line { font-size: 0.7rem; }
}
</style>
</head>
<body>
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="sidebar-header">
      <div class="logo">// LOGS</div>
      <div class="sub"><?php echo htmlspecialchars($logPrefix); ?></div>
    </div>

    <div class="sidebar-nav">
      <button class="mode-btn active" id="btn-single" onclick="setMode('single')">DATEI</button>
      <button class="mode-btn" id="btn-combined" onclick="setMode('combined')">ALLE</button>
    </div>

    <div class="combined-opts" id="combined-opts">
      <label for="limit-sel">Letzte N Tage anzeigen</label>
      <select id="limit-sel" onchange="loadCombined()">
        <option value="1">1 Tag</option>
        <option value="3" selected>3 Tage</option>
        <option value="5">5 Tage</option>
        <option value="7">7 Tage</option>
        <option value="10">10 Tage</option>
      </select>
    </div>

    <div class="files-list" id="files-list">
      <div class="state-msg"><span class="spinner"></span></div>
    </div>

    <div class="sidebar-footer">
      <!-- Kein Abmelde-Knopf: Die Anmeldung liegt beim Webserver (HTTP-Basic-Auth).
           Zum Abmelden das Browserfenster schliessen. -->
      <div class="refresh-indicator" id="refresh-dot" title="Auto-Refresh"></div>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">
    <div class="topbar">
      <div class="title" id="view-title">Wähle eine Datei …</div>
      <div class="meta" id="view-meta"></div>
      <button class="theme-btn" id="theme-btn" onclick="toggleTheme()" title="Theme wechseln">🌙</button>
      <button class="scroll-lock-btn" id="scroll-lock-btn" onclick="toggleScrollLock()">⇩ LOCK</button>
    </div>
    <div class="search-bar">
      <input type="text" id="search-input" placeholder="Suche / Filter …" oninput="applyFilter()">
    </div>
    <div class="filter-bar">
      <span class="filter-label">LEVEL</span>
      <button class="lvl-btn btn-debug"   id="fb-debug"   onclick="toggleLevelFilter('debug')">DEBUG</button>
      <button class="lvl-btn btn-info"    id="fb-info"    onclick="toggleLevelFilter('info')">INFO</button>
      <button class="lvl-btn btn-warn"    id="fb-warn"    onclick="toggleLevelFilter('warn')">WARN</button>
      <button class="lvl-btn btn-error"   id="fb-error"   onclick="toggleLevelFilter('error')">ERROR</button>
      <button class="lvl-btn btn-default" id="fb-default" onclick="toggleLevelFilter('default')">OTHER</button>
      <span class="filter-sep"></span>
      <button class="filter-reset" onclick="resetLevelFilters()">Alle einblenden</button>
    </div>
    <div class="log-container" id="log-container">
      <div class="state-msg">← Datei auswählen oder Alle-Ansicht wählen</div>
    </div>
  </main>

</div>

<script>
const INTERVAL   = <?php echo (int)$interval; ?>;
const TAIL_LINES = <?php echo (int)$tailLines; ?>;

let mode         = 'single';
let activeFile   = null;
let scrollLock   = true;
let filterText   = '';
let refreshTimer = null;
let rawData      = null; // last fetched data
const ALL_LEVELS = ['debug', 'info', 'warn', 'error', 'default'];
let activeLevels = new Set(ALL_LEVELS);

// ---- Mode ----
function setMode(m) {
  mode = m;
  document.getElementById('btn-single').classList.toggle('active', m === 'single');
  document.getElementById('btn-combined').classList.toggle('active', m === 'combined');
  document.getElementById('combined-opts').style.display = m === 'combined' ? 'block' : 'none';

  clearTimeout(refreshTimer);

  if (m === 'combined') {
    loadCombined();
  } else {
    if (activeFile) loadFile(activeFile);
    else setContent('<div class="state-msg">← Datei auswählen</div>');
  }
}

// ---- File list ----
async function loadFileList() {
  try {
    const res = await fetch('?api=list');
    const files = await res.json();
    const el = document.getElementById('files-list');
    if (!files.length) { el.innerHTML = '<div class="state-msg">Keine Logs</div>'; return; }

    el.innerHTML = files.map(f => `
      <div class="file-item ${f.today ? 'today' : ''} ${activeFile === f.path ? 'active' : ''}"
           id="fi-${CSS.escape(f.path)}"
           onclick="selectFile('${esc(f.path)}')">
        <span class="dot"></span>
        <span>${f.label}</span>
        <span class="size">${fmtSize(f.size)}</span>
      </div>`).join('');
  } catch(e) { console.error(e); }
}

function selectFile(name) {
  if (mode !== 'single') setMode('single');
  activeFile = name;
  document.querySelectorAll('.file-item').forEach(el => el.classList.remove('active'));
  const el = document.getElementById('fi-' + CSS.escape(name));
  if (el) el.classList.add('active');
  clearTimeout(refreshTimer);
  loadFile(name);
}

// ---- Load single file ----
async function loadFile(name) {
  flashDot();
  try {
    const res = await fetch(`?api=file&name=${encodeURIComponent(name)}`);
    const data = await res.json();
    rawData = data;
    document.getElementById('view-title').textContent = data.label;
    document.getElementById('view-meta').textContent =
      `${data.lines.length} Zeilen (max. ${TAIL_LINES})`;
    renderSingleFile(data.lines, filterText);
    scrollToBottom();
  } catch(e) { setContent('<div class="state-msg">Fehler beim Laden</div>'); }
  refreshTimer = setTimeout(() => loadFile(name), INTERVAL);
}

function renderSingleFile(lines, filter) {
  const html = linesToHtml(lines, filter);
  setContent(html || '<div class="state-msg">Keine Einträge</div>');
}

// ---- Load combined ----
async function loadCombined() {
  const limit = document.getElementById('limit-sel').value;
  flashDot();
  try {
    const res = await fetch(`?api=combined&limit=${limit}`);
    const days = await res.json();
    rawData = days;
    document.getElementById('view-title').textContent = `Letzte ${limit} Tage`;
    const total = days.reduce((s, d) => s + d.lines.length, 0);
    document.getElementById('view-meta').textContent = `${total} Zeilen gesamt`;
    renderCombined(days, filterText);
    scrollToBottom();
  } catch(e) { setContent('<div class="state-msg">Fehler beim Laden</div>'); }
  refreshTimer = setTimeout(loadCombined, INTERVAL);
}

function renderCombined(days, filter) {
  if (!days.length) { setContent('<div class="state-msg">Keine Logs</div>'); return; }
  const html = days.map(d => `
    <div class="day-block ${d.today ? 'today' : ''}">
      <div class="day-header">${d.label}${d.today ? ' · HEUTE' : ''}</div>
      ${linesToHtml(d.lines, filter) || '<div class="state-msg" style="height:60px">Keine Einträge</div>'}
    </div>`).join('');
  setContent(html);
}

// ---- Render lines ----
function linesToHtml(lines, filter) {
  let out = '';
  let shown = 0;
  const needle = filter.toLowerCase();
  lines.forEach((line, i) => {
    if (needle && !line.toLowerCase().includes(needle)) return;
    const { level, badge } = lineLevel(line);
    if (!activeLevels.has(level)) return;
    const escapedLine = esc(line);
    const txt = filter ? highlight(escapedLine, filter) : escapedLine;
    const badgeHtml = badge ? `<span class="badge">${badge}</span>` : '';
    out += `<div class="log-line lvl-${level}"><span class="ln">${i + 1}</span><span class="txt">${badgeHtml}${txt}</span></div>`;
    shown++;
  });
  return out;
}

// Returns { level, badge } where level is css class suffix and badge is display label
function lineLevel(line) {
  // Prefer explicit *.LEVEL tokens (e.g. "2025-01-15 10:00:00.ERROR", ".DEBUG", etc.)
  const tokenMatch = line.match(/\.(\bDEBUG\b|\bINFO\b|\bWARN\b|\bERROR\b)/i);
  if (tokenMatch) {
    const t = tokenMatch[1].toUpperCase();
    if (t === 'DEBUG') return { level: 'debug', badge: 'DEBUG' };
    if (t === 'INFO')  return { level: 'info',  badge: 'INFO'  };
    if (t === 'WARN')  return { level: 'warn',  badge: 'WARN'  };
    if (t === 'ERROR') return { level: 'error', badge: 'ERROR' };
  }
  // Fallback: keyword scan
  const l = line.toLowerCase();
  if (/\b(error|critical|fatal|exception)\b/.test(l)) return { level: 'error', badge: 'ERROR' };
  if (/\bwarn(ing)?\b/.test(l))                       return { level: 'warn',  badge: 'WARN'  };
  if (/\binfo\b/.test(l))                             return { level: 'info',  badge: 'INFO'  };
  if (/\bdebug\b/.test(l))                            return { level: 'debug', badge: 'DEBUG' };
  return { level: 'default', badge: null };
}

function highlight(html, needle) {
  const re = new RegExp(escRe(needle), 'gi');
  return html.replace(re, m => `<mark class="hl">${m}</mark>`);
}

// ---- Level filter ----
function toggleLevelFilter(level) {
  if (activeLevels.has(level)) {
    activeLevels.delete(level);
  } else {
    activeLevels.add(level);
  }
  document.getElementById('fb-' + level).classList.toggle('off', !activeLevels.has(level));
  rerender();
}

function resetLevelFilters() {
  ALL_LEVELS.forEach(l => {
    activeLevels.add(l);
    document.getElementById('fb-' + l).classList.remove('off');
  });
  rerender();
}

function rerender() {
  if (!rawData) return;
  if (mode === 'single') renderSingleFile(rawData.lines, filterText);
  else renderCombined(rawData, filterText);
}

// ---- Filter ----
function applyFilter() {
  filterText = document.getElementById('search-input').value.trim();
  rerender();
}

// ---- Scroll lock ----
function toggleScrollLock() {
  scrollLock = !scrollLock;
  const btn = document.getElementById('scroll-lock-btn');
  btn.textContent = scrollLock ? '⇩ LOCK' : '⇩ FREE';
  btn.classList.toggle('off', !scrollLock);
}

function scrollToBottom() {
  if (!scrollLock) return;
  const c = document.getElementById('log-container');
  c.scrollTop = c.scrollHeight;
}

// ---- Helpers ----
function setContent(html) {
  const c = document.getElementById('log-container');
  c.innerHTML = html;
  scrollToBottom();
}

function flashDot() {
  const d = document.getElementById('refresh-dot');
  d.classList.add('active');
  setTimeout(() => d.classList.remove('active'), 300);
}

function fmtSize(b) {
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b / 1024).toFixed(1) + ' KB';
  return (b / 1048576).toFixed(1) + ' MB';
}

function esc(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function escRe(s) {
  return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

// ---- Theme ----
function applyTheme(theme) {
  const isLight = theme === 'light';
  document.documentElement.classList.toggle('light', isLight);
  const btn = document.getElementById('theme-btn');
  if (btn) btn.textContent = isLight ? '☀️' : '🌙';
}

function toggleTheme() {
  const isLight = document.documentElement.classList.contains('light');
  const next = isLight ? 'dark' : 'light';
  localStorage.setItem('logviewer-theme', next);
  applyTheme(next);
}

// Apply on load (default: dark)
applyTheme(localStorage.getItem('logviewer-theme') || 'dark');

// ---- Init ----
loadFileList();
// Auto-select today's file if available
(async () => {
  try {
    const res = await fetch('?api=list');
    const files = await res.json();
    if (files.length) {
      const today = files.find(f => f.today) || files[0];
      selectFile(today.path);
    }
  } catch(e) {}
})();

// Refresh file list every 60 s
setInterval(loadFileList, 60000);
</script>
</body>
</html>
