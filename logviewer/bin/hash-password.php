<?php

declare(strict_types=1);

// ============================================================================
//  Erzeugt den Kennwort-Hash fuer die config.php des Log-Betrachters.
//
//      php bin/hash-password.php
//
//  Das Kennwort wird abgefragt, nicht als Argument uebergeben - so landet es
//  weder in der Shell-Historie noch in der Prozessliste.
// ============================================================================

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Programm laeuft ausschliesslich auf der Kommandozeile.\n");
}

/**
 * Fragt eine Eingabe ab, ohne sie anzuzeigen.
 *
 * Faellt auf sichtbare Eingabe zurueck, wenn stty nicht verfuegbar ist.
 */
function readSecret(string $prompt): string
{
    fwrite(STDOUT, $prompt);

    $hasStty = stripos(PHP_OS_FAMILY, 'Windows') === false
        && shell_exec('command -v stty 2>/dev/null') !== null;

    if ($hasStty) {
        shell_exec('stty -echo');
    }

    $input = fgets(STDIN);

    if ($hasStty) {
        shell_exec('stty echo');
        fwrite(STDOUT, PHP_EOL);
    }

    return rtrim($input === false ? '' : $input, "\r\n");
}

$password = readSecret('Neues Kennwort: ');
$repeat   = readSecret('Wiederholen:    ');

if ($password === '') {
    exit("Abbruch: leeres Kennwort.\n");
}

if ($password !== $repeat) {
    exit("Abbruch: die Eingaben stimmen nicht ueberein.\n");
}

if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Hinweis: Kennwoerter unter 12 Zeichen sind fuer einen aus dem\n");
    fwrite(STDERR, "Netz erreichbaren Zugang zu kurz.\n\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

echo "\nDiesen Wert in config.php eintragen:\n\n";
echo "    'passwordHash' => '" . $hash . "',\n\n";
