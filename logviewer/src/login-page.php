<?php

declare(strict_types=1);

/**
 * Anmeldeseite des Log-Betrachters.
 *
 * Wird aus public/index.php eingebunden und erwartet dort gesetzte Variablen:
 *
 * @var string      $logPrefix  Dateinamenpraefix, nur zur Anzeige
 * @var string|null $loginError Fehlermeldung oder null
 */

$csrfToken = (string) ($_SESSION['csrf'] ?? '');
?><!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Log Viewer · Anmeldung</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;700&family=Syne:wght@700;800&display=swap');

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

/* Dieselbe Farbwelt wie der Betrachter selbst */
:root {
  --bg:          #080b0f;
  --surface:     #0e1318;
  --border:      #1e2730;
  --border2:     #2a3440;
  --text:        #b0bec5;
  --text-dim:    #4a5568;
  --text-bright: #e0e8f0;
  --accent:      #00e5a0;
  --err:         #ff5252;
  --radius:      5px;
  --mono:        'JetBrains Mono', monospace;
  --display:     'Syne', sans-serif;
}

@media (prefers-color-scheme: light) {
  :root {
    --bg:          #f0f4f8;
    --surface:     #ffffff;
    --border:      #d0d7de;
    --border2:     #b0bac4;
    --text:        #3d4a56;
    --text-dim:    #8896a4;
    --text-bright: #1a242e;
    --accent:      #00a372;
    --err:         #d93025;
  }
}

body {
  min-height: 100vh;
  display: flex; align-items: center; justify-content: center;
  padding: 1.5rem;
  background: var(--bg);
  background-image:
    radial-gradient(ellipse at 15% 45%, rgba(0,229,160,0.10) 0%, transparent 55%),
    radial-gradient(ellipse at 85% 15%, rgba(0,150,255,0.07) 0%, transparent 50%);
  font-family: var(--mono);
  font-size: 13px;
  color: var(--text);
}

/* Feine Rasterlinien wie im Betrachter */
body::before {
  content: '';
  position: fixed; inset: 0; pointer-events: none;
  background: repeating-linear-gradient(
    0deg, transparent, transparent 2px,
    rgba(0,0,0,0.03) 2px, rgba(0,0,0,0.03) 4px
  );
}

.card {
  position: relative;
  width: 100%; max-width: 360px;
  padding: 2.5rem 2rem 2rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  box-shadow: 0 24px 64px rgba(0,0,0,0.45);
}

.card::before {
  content: '';
  position: absolute; inset: -1px -1px auto -1px; height: 2px;
  border-radius: var(--radius) var(--radius) 0 0;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
}

.logo {
  font-family: var(--display);
  font-size: 1.5rem; line-height: 1;
  color: var(--text-bright);
  letter-spacing: -0.03em;
}
.logo span { color: var(--accent); }

.sub {
  margin-top: 0.45rem; margin-bottom: 2rem;
  font-size: 0.68rem; color: var(--text-dim);
  letter-spacing: 0.09em; text-transform: uppercase;
}

label {
  display: block; margin-bottom: 0.5rem;
  font-size: 0.68rem; color: var(--text-dim);
  letter-spacing: 0.09em; text-transform: uppercase;
}

input[type=password] {
  width: 100%; padding: 0.7rem 0.85rem;
  background: var(--bg);
  border: 1px solid var(--border2);
  border-radius: var(--radius);
  color: var(--text-bright);
  font-family: inherit; font-size: 0.9rem;
  outline: none;
  transition: border-color .18s, box-shadow .18s;
}
input[type=password]:focus {
  border-color: var(--accent);
  box-shadow: 0 0 0 3px rgba(0,229,160,0.12);
}

button {
  margin-top: 1.25rem; width: 100%;
  padding: 0.7rem;
  background: var(--accent);
  border: none; border-radius: var(--radius);
  color: #04120c;
  font-family: inherit; font-size: 0.78rem; font-weight: 700;
  letter-spacing: 0.09em; text-transform: uppercase;
  cursor: pointer;
  transition: filter .18s, transform .06s;
}
button:hover  { filter: brightness(1.1); }
button:active { transform: translateY(1px); }

.error {
  display: flex; align-items: flex-start; gap: 0.5rem;
  margin-top: 1.1rem; padding: 0.6rem 0.75rem;
  background: rgba(255,82,82,0.12);
  border: 1px solid rgba(255,82,82,0.35);
  border-left-width: 2px;
  border-radius: var(--radius);
  font-size: 0.75rem; line-height: 1.5; color: var(--err);
}
.error::before { content: '!'; font-weight: 700; }

.foot {
  margin-top: 1.75rem; padding-top: 1rem;
  border-top: 1px solid var(--border);
  font-size: 0.64rem; color: var(--text-dim);
  letter-spacing: 0.05em; text-align: center;
}
</style>
</head>
<body>

<main class="card">
  <div class="logo">LOG<span>VIEWER</span></div>
  <div class="sub"><?= htmlspecialchars($logPrefix, ENT_QUOTES, 'UTF-8') ?>-yyyy-mm-dd.log</div>

  <form method="post" autocomplete="on">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <label for="password">Kennwort</label>
    <input type="password" id="password" name="password" autofocus autocomplete="current-password" required>

    <button type="submit">Anmelden</button>

    <?php if ($loginError !== null): ?>
      <p class="error"><?= htmlspecialchars($loginError, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
  </form>

  <div class="foot">Pegelbot</div>
</main>

</body>
</html>
