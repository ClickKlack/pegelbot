<?php

declare(strict_types=1);

namespace LogViewer;

/**
 * Prueft das Zugangskennwort des Log-Betrachters und begrenzt Fehlversuche.
 *
 * Die Klasse haelt bewusst keinen Sitzungszustand: Sie bekommt die aktuelle Zeit
 * und eine Kennung des Aufrufers uebergeben und ist dadurch vollstaendig testbar.
 * Sitzung und Formular liegen in index.php.
 *
 * Fehlversuche werden je Aufrufer in einer Datei gezaehlt, nicht in der Sitzung -
 * sonst genuegte es, das Sitzungsplaetzchen zu verwerfen, um die Sperre zu umgehen.
 */
final class Authenticator
{
    private readonly string $stateFolder;

    /**
     * @param string $passwordHash   Kennwort-Hash aus password_hash()
     * @param string $stateFolder    Verzeichnis fuer die Fehlversuchszaehler,
     *                               muss ausserhalb des Dokumentenstamms liegen
     * @param int    $maxAttempts    Fehlversuche bis zur Sperre
     * @param int    $lockoutSeconds Dauer der Sperre in Sekunden
     */
    public function __construct(
        private readonly string $passwordHash,
        string $stateFolder,
        private readonly int $maxAttempts = 5,
        private readonly int $lockoutSeconds = 900,
    ) {
        $this->stateFolder = rtrim($stateFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    }

    /**
     * Ist ueberhaupt ein Kennwort hinterlegt?
     *
     * Ein leerer oder offensichtlich unfertiger Hash bedeutet, dass die
     * Einrichtung nicht abgeschlossen ist. Der Betrachter verweigert dann den
     * Dienst, statt ungeschuetzt Logdateien auszuliefern.
     */
    public function isConfigured(): bool
    {
        if ($this->passwordHash === '') {
            return false;
        }

        return password_get_info($this->passwordHash)['algo'] !== null;
    }

    /**
     * Prueft das eingegebene Kennwort.
     *
     * password_verify() vergleicht laufzeitkonstant; ein eigener Vergleich
     * waere hier ein Rueckschritt.
     */
    public function verify(string $password): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        return password_verify($password, $this->passwordHash);
    }

    /**
     * Bildet aus IP-Adresse und Browserkennung eine Kennung des Aufrufers.
     *
     * Wird gehasht, damit im Zustandsverzeichnis keine IP-Adressen im Klartext
     * liegen.
     */
    public function clientKey(string $ipAddress, string $userAgent = ''): string
    {
        return hash('sha256', $ipAddress . "\0" . $userAgent);
    }

    /**
     * Verbleibende Sperrzeit in Sekunden; 0, wenn keine Sperre besteht.
     */
    public function remainingLockout(string $clientKey, int $now): int
    {
        $state = $this->readState($clientKey);

        if ($state['count'] < $this->maxAttempts) {
            return 0;
        }

        $remaining = ($state['last'] + $this->lockoutSeconds) - $now;

        return max(0, $remaining);
    }

    public function isLockedOut(string $clientKey, int $now): bool
    {
        return $this->remainingLockout($clientKey, $now) > 0;
    }

    /**
     * Vermerkt einen Fehlversuch.
     *
     * Ist eine abgelaufene Sperre vorhanden, beginnt die Zaehlung von vorn.
     */
    public function registerFailure(string $clientKey, int $now): void
    {
        $state = $this->readState($clientKey);

        if ($state['count'] >= $this->maxAttempts && $this->remainingLockout($clientKey, $now) === 0) {
            $state['count'] = 0;
        }

        $this->writeState($clientKey, [
            'count' => $state['count'] + 1,
            'last'  => $now,
        ]);
    }

    /**
     * Loescht die Fehlversuche nach erfolgreicher Anmeldung.
     */
    public function clearFailures(string $clientKey): void
    {
        $file = $this->stateFile($clientKey);

        if (is_file($file)) {
            unlink($file);
        }
    }

    /**
     * Entfernt Zaehlerdateien, deren Sperre laengst abgelaufen ist.
     *
     * Wird bei jeder Anmeldung nebenbei aufgerufen, damit das Verzeichnis nicht
     * unbegrenzt waechst.
     */
    public function purgeExpired(int $now): void
    {
        foreach (glob($this->stateFolder . '*.json') ?: [] as $file) {
            $raw = @file_get_contents($file);

            if ($raw === false) {
                continue;
            }

            $data = json_decode($raw, true);
            $last = is_array($data) ? (int) ($data['last'] ?? 0) : 0;

            if ($last + $this->lockoutSeconds < $now) {
                @unlink($file);
            }
        }
    }

    /**
     * @return array{count: int, last: int}
     */
    private function readState(string $clientKey): array
    {
        $file = $this->stateFile($clientKey);

        if (!is_file($file)) {
            return ['count' => 0, 'last' => 0];
        }

        $raw = file_get_contents($file);
        $data = $raw === false ? null : json_decode($raw, true);

        if (!is_array($data)) {
            return ['count' => 0, 'last' => 0];
        }

        return [
            'count' => (int) ($data['count'] ?? 0),
            'last'  => (int) ($data['last'] ?? 0),
        ];
    }

    /**
     * @param array{count: int, last: int} $state
     */
    private function writeState(string $clientKey, array $state): void
    {
        if (!is_dir($this->stateFolder)) {
            mkdir($this->stateFolder, 0o700, true);
        }

        file_put_contents(
            $this->stateFile($clientKey),
            json_encode($state, JSON_THROW_ON_ERROR),
            LOCK_EX,
        );
    }

    private function stateFile(string $clientKey): string
    {
        // Die Kennung ist bereits ein Hash und damit als Dateiname unbedenklich
        return $this->stateFolder . $clientKey . '.json';
    }
}
