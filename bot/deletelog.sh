#!/bin/bash

# Erwartet den Aufruf aus dem Log-Verzeichnis heraus.
# ACHTUNG: Das Skript ist vermutlich ueberfluessig, weil Monolog die Logs bereits
# ueber den RotatingFileHandler 14 Tage vorhaelt. Ausserdem setzt es GNU-Werkzeuge
# voraus ("date -d", dreiargumentiges match() in awk) und laeuft auf BSD/macOS nicht.
# Siehe SPEC.md, offener Punkt O10.
LOGFILE="pegelbot.log"
TMPFILE="${LOGFILE}.tmp"

# Zeitpunkt: vor 1 Monat
CUTOFF=$(date -d "1 month ago" +%s)

awk -v cutoff="$CUTOFF" '
{
    if (match($0, /^\[([0-9T:\.\+\-]+)\]/, ts)) {
        cmd = "date -d \"" ts[1] "\" +%s"
        cmd | getline t
        close(cmd)

        if (t >= cutoff) {
            print
        }
    }
}
' "$LOGFILE" > "$TMPFILE" && mv "$TMPFILE" "$LOGFILE"
