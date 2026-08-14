<?php
// src/Messstelle.php

namespace PegelBot;

class MessstellenController
{
    protected \Doctrine\DBAL\Connection $_connection;
    protected \Monolog\Logger $_logger;

    public int $id;
    public string $name;
    public int $nummer;
    public string $uuid;
    public ?array $abo_data;
    
    private ?\DateTime $letzteMessung = null;

  
    protected \WSA\MeasurementApiInterface $_api;
    protected \Psr\Clock\ClockInterface $_clock;
    protected TrendPolicy $_trendPolicy;
    protected ChannelRegistry $_channels;

    // Zeitzone, in der Zeitpunkte in Meldungen ausgegeben werden. Intern wird
    // durchgaengig in UTC gerechnet, umgerechnet wird erst bei der Ausgabe.
    private const DISPLAY_TIMEZONE = 'Europe/Berlin';

    // Konstruktor mit Eigenschaften der Messstelle
    public function __construct(\Doctrine\DBAL\Connection $connection, \Monolog\Logger $logger, \WSA\MeasurementApiInterface $api, \Psr\Clock\ClockInterface $clock, TrendPolicy $trendPolicy, ChannelRegistry $channels, int $id, string $name, int $nummer, string $uuid, ?array $AboData = null) {
        // Objekte werden in PHP ohnehin als Handle uebergeben, deshalb ohne Referenz
        $this->_connection  = $connection;
        $this->_logger      = $logger;
        $this->_api         = $api;
        $this->_clock       = $clock;
        $this->_trendPolicy = $trendPolicy;
        $this->_channels    = $channels;

        // die eigentlichen Eigenschaften übernehmen
        $this->id = $id;
        $this->name = $name;
        $this->nummer = $nummer;
        $this->uuid = $uuid;
        $this->abo_data = $AboData;
    }

    /**
     * Formatiert einen Zeitpunkt fuer Meldungen an Benutzer und Protokoll.
     *
     * Die Ausgabe erfolgt in Ortszeit und wird ausdruecklich als solche
     * gekennzeichnet. Ohne Kennzeichnung war nicht erkennbar, ob eine Zeitangabe
     * in derselben Meldungsfolge UTC oder Ortszeit meint - die uebrigen
     * Meldungen geben ihre Zeitpunkte als UTC aus.
     */
    public function formatLocalTime(\DateTimeInterface $moment): string {
        $local = \DateTimeImmutable::createFromInterface($moment)
            ->setTimezone(new \DateTimeZone(self::DISPLAY_TIMEZONE));

        return $local->format('d.m.Y H:i:s') . ' Ortszeit';
    }

    // Ermittelt das letzte gespeicherte Messdatum zur Messstelle
    private function getTimestampLetzteMessung(): \DateTime {
        if (is_null($this->letzteMessung)) {
            $this->letzteMessung = $this->getTimestampLetzteMessungDB();
        }
        return $this->letzteMessung;
    }

    // Ermittelt das letzte gespeicherte Messdatum zur Messstelle
    private function getTimestampLetzteMessungDB(): \DateTime {
        $this->_logger->debug("getTimestampLetzteMessungDB()", ['name' => $this->name]);

        $sql = "SELECT max(zeitpunkt) as letzter_zeitpunkt FROM messwerte WHERE messstellen_id = ?";
        $stmt = $this->_connection->prepare($sql);
        $stmt->bindValue(1, $this->id);
        $resultSet = $stmt->executeQuery();
        $result = $resultSet->fetchAllAssociative();

        if (count($result) > 0 && !is_null($result[0]['letzter_zeitpunkt'])) {
            $date = new \DateTime($result[0]['letzter_zeitpunkt'], new \DateTimeZone('UTC'));
        } else {
            // wenn es noch keine Messung gibt, einfach die letzten 24h greifen
            $date = \DateTime::createFromImmutable($this->_clock->now());
            $date = $date->sub(new \DateInterval('P1D'));
        }

        return $date;
    }

    // speichert die übergebenen Messungen in der Datenbank
    private function saveMessungeninDB(array $messungen) {
        $this->_logger->debug("saveMessungeninDB()", ['name' => $this->name]);

        foreach($messungen as $messung) {
            $this->_connection->insert('messwerte', [
                'messstellen_id' => $this->id,
                'zeitpunkt' => $messung->getTimestamp()->format("Y-m-d H:i:s"),
                'messwert' => $messung->getValue()
            ]);
        }
        $this->_logger->info("Messwerte eingefügt", ['count' => count($messungen)]);
    }

    // ermittelt auf Pegelonline neue Messwerte und speichert diese in der Datenbank ab
    public function ladeUndSpeichereMessungen() {
        $this->_logger->debug("ladeUndSpeichereMessungen()", ['name' => $this->name]);

        $seit = $this->formatLocalTime($this->getTimestampLetzteMessung());

        echo "Lade Messwerte für {$this->name} seit {$seit}\n";
        $this->_logger->info("Lade Messwerte" , [
            'name' => $this->name,
            'last_date' => $seit
        ]);
        $this->saveMessungeninDB($this->_api->fetchMeasurements($this->uuid, $this->getTimestampLetzteMessung()->add(new \DateInterval('PT1S'))));
    }

    // prüft, welche Abo-Templates vorhanden sind
    public function sendAboNotifysIfNeeded() {
        $this->_logger->debug("sendAboNotifysIfNeeded({$this->name})");

        if (is_null($this->abo_data)) {
            throw new \Exception("Keine Abo-Daten");
        }

        $letzter_zeitpunkt = new \DateTime($this->abo_data['letzter_zeitpunkt'], new \DateTimeZone('UTC'));
        $zeitpunkt_aktuell = new \DateTime($this->abo_data['zeitpunkt_aktuell'], new \DateTimeZone('UTC'));
        $time_diff = $zeitpunkt_aktuell->diff($letzter_zeitpunkt, true);

        // Ein fehlender Vorwert wird ausdruecklich benannt. Eine leere Stelle
        // hinter "Letzter Wert:" sah nach Fehler aus, ist aber der Normalfall,
        // solange letzter_zeitpunkt auf keinen gespeicherten Messzeitpunkt trifft.
        $letzter_wert = is_null($this->abo_data['letzter_messwert'])
            ? '(keiner)'
            : $this->abo_data['letzter_messwert'];

        $meldung = "Erstelle Notifys für {$this->name}"
            . " - Letzter Wert: {$letzter_wert} ({$this->abo_data['letzter_zeitpunkt']} UTC)"
            . " - Aktuellster Wert: {$this->abo_data['messwert_aktuell']} ({$this->abo_data['zeitpunkt_aktuell']} UTC)";

        $this->_logger->info($meldung);
        echo $meldung . "\n";

        // Prüfen ob Notify notwendig (Veränderung Wert oder letztes Notify mind. 24h her)
        if ((!is_null($this->abo_data['letzter_messwert']) && $this->abo_data['letzter_messwert'] <> $this->abo_data['messwert_aktuell']) || $time_diff->days >= 1) {
            // ja, Erstellung an alle konfigurierten Controller leiten
            $this->sendNotifys();
        } else {
            echo "  Keine Aktualisierung\n";
            $this->_logger->info("Keine Aktualisierung");
        }
    }
    
    // Hilfsfunktion für GetNotifyMessage
    private function addVorzeichenGetNotifyMessage($differenz) {
        if (!is_numeric($differenz)) {
        return $differenz;
        }
        
        if ($differenz < 0) {
        return $differenz;
        }
        
        if ($differenz == 0) {
        return "+/-0";
        }
        
        return "+".$differenz;
    }

    // erstellt formatierte Notify-Message
    private function GetNotifyMessage(): string {

        $message_text = $this->abo_data['message_template'];
        $zeitpunkt_aktuell = new \DateTime($this->abo_data['zeitpunkt_aktuell'], new \DateTimeZone('UTC'));
        
        $message_text = str_replace("{MESSPUNKT}", $this->abo_data['name'], $message_text);
        $message_text = str_replace("{MESSWERT}", $this->abo_data['messwert_aktuell'], $message_text);
        
        $zeitpunkt_aktuell = $zeitpunkt_aktuell->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $message_text = str_replace("{DATE}", $zeitpunkt_aktuell->format("d.m.Y"), $message_text);
        $message_text = str_replace("{TIME}", $zeitpunkt_aktuell->format("H:i"), $message_text);

        // Tendenz berechnen
        $tendenz = "";
        if (!is_null($this->abo_data['letzter_messwert'])) {
          $tendenz = "Tendenz ";
          
          if ($this->abo_data['letzter_messwert'] > $this->abo_data['messwert_aktuell']) {
            $tendenz .= "fallend";
          } elseif ($this->abo_data['letzter_messwert'] < $this->abo_data['messwert_aktuell']) {
            $tendenz .= "steigend";
          } else {
            $tendenz .= "gleich";
          }
        }
        $message_text = str_replace("{TENDENZ}", $tendenz, $message_text);
        
        $message_text = str_replace("{ENTWICKLUNG_6h}", $this->addVorzeichenGetNotifyMessage($this->abo_data['diff_messwert_6h']), $message_text);
        $message_text = str_replace("{ENTWICKLUNG_12h}", $this->addVorzeichenGetNotifyMessage($this->abo_data['diff_messwert_12h']), $message_text);
        $message_text = str_replace("{ENTWICKLUNG_24h}", $this->addVorzeichenGetNotifyMessage($this->abo_data['diff_messwert_24h']), $message_text);
        $message_text = str_replace("{ENTWICKLUNG_2d}", $this->addVorzeichenGetNotifyMessage($this->abo_data['diff_messwert_2d']), $message_text);
        $message_text = str_replace("{ENTWICKLUNG_4d}", $this->addVorzeichenGetNotifyMessage($this->abo_data['diff_messwert_4d']), $message_text);
        $message_text = str_replace("{ENTWICKLUNG_7d}", $this->addVorzeichenGetNotifyMessage($this->abo_data['diff_messwert_7d']), $message_text);

        return $message_text;
    }

    // erstellt formatierte Trend-Message
    private function GetTrendMessage(): string {
        $message_text = $this->abo_data['trend_template'];
        $message_text = str_replace("{MESSPUNKT}", $this->abo_data['name'], $message_text);

        return $message_text;
    }

    // erstellt eine postNotify für diese Messstelle an alle konfigurierten Controller
    private function sendNotifys() {
        $this->_logger->debug("sendNotifys({$this->name})");

        $message_text = $this->GetNotifyMessage();

        // zaehlt mit, damit der Zeitpunkt nur bei tatsaechlicher Zustellung wandert
        $outcome = new DeliveryOutcome();

        foreach($this->_channels->all() as $channel) {
            foreach($this->getSubscriptions($channel) as $abo_data) {
                try {
                    $channel->postNotify($abo_data, $message_text);
                    $outcome->recordSuccess();
                } catch (\Throwable $e) {
                    $outcome->recordFailure($channel->name());
                    $this->_logger->error("Fehler beim Versenden via {$channel->name()}", [
                        'exception' => $e->getMessage(),
                        'abo_id'      => $abo_data[$channel->subscriptionIdColumn()] ?? '?',
                        'messstellen_id' => $this->id,
                        'beschreibung' => $abo_data['beschreibung'] ?? '?',
                    ]);
                }
            }
        }

        $this->advanceTimestampIfPossible($outcome, 'letzter_zeitpunkt', 'Benachrichtigung');
    }

    /**
     * Aktive Abonnements dieser Messstelle fuer einen Kanal.
     *
     * Der Tabellenname kommt vom Kanal selbst und nicht mehr aus einem
     * Datenbankwert.
     *
     * @return list<array<string, mixed>>
     */
    private function getSubscriptions(AboInterface $channel): array {
        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->select('*')
            ->from($channel->subscriptionTable())
            ->where('messstellen_id = :messstellen_id')
            ->andWhere('aktiv = 1')
            ->setParameter('messstellen_id', $this->id);

        return $queryBuilder->fetchAllAssociative();
    }

    // Schreibt den Zeitpunkt der letzten Zustellung fort, sofern das Ergebnis es zulaesst.
    //
    // Fruehere Fassungen schrieben bedingungslos fort, auch wenn jeder einzelne
    // Versand gescheitert war. Die Meldung galt damit als erledigt und war
    // dauerhaft verloren - Befund B2.
    //
    // @return bool true, wenn fortgeschrieben wurde
    private function advanceTimestampIfPossible(DeliveryOutcome $outcome, string $column, string $art): bool {
        if (!$outcome->shouldAdvanceTimestamp()) {
            $this->_logger->warning(
                "{$art} an keinen Empfaenger zustellbar, Zeitpunkt bleibt stehen",
                ['name' => $this->name] + $outcome->summary()
            );
            echo "  Kein Versand gelungen, Zeitpunkt bleibt stehen - naechster Lauf versucht es erneut\n";

            return false;
        }

        if ($outcome->isPartial()) {
            // Der Zeitpunkt wandert trotzdem vor, die Meldung an die gescheiterten
            // Kanaele ist damit verloren. Siehe SPEC.md, Befund B14.
            $this->_logger->warning(
                "{$art} nur teilweise zugestellt",
                ['name' => $this->name] + $outcome->summary()
            );
        }

        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->update('messstelllen_abo_zuordnung')
            ->set($column, ':zeitpunkt')
            ->where('messstellen_id = :messstellen_id')
            ->setParameter('zeitpunkt', $this->abo_data['zeitpunkt_aktuell'])
            ->setParameter('messstellen_id', $this->id)
        ;
        $queryBuilder->executeStatement();

        return true;
    }

    // prüft, ob eine neue Verlaufsgrafik verschickt werden kann
    public function sendAboVerlaufIfNeeded() {
        $this->_logger->debug("sendAboVerlaufIfNeeded({$this->name})");

        if (is_null($this->abo_data)) {
            throw new \Exception("Keine Abo-Daten");
        }

        $letzter_zeitpunkt = new \DateTimeImmutable($this->abo_data['letzter_verlaufszeitpunkt'], new \DateTimeZone('UTC'));
        $zeitpunkt_aktuell = $this->_clock->now();

        $this->_logger->info("Erstelle Verläufe für {$this->name} - Letzter: {$this->abo_data['letzter_verlaufszeitpunkt']} UTC - Aktuellster Wert: {$this->abo_data['zeitpunkt_aktuell']} UTC - min/max: {$this->abo_data['min_messwert']}/{$this->abo_data['max_messwert']}");
        echo "Erstelle Verläufe für {$this->name} - Letzter: {$this->abo_data['letzter_verlaufszeitpunkt']} UTC - Aktuellster Wert: {$this->abo_data['zeitpunkt_aktuell']} UTC - min/max: {$this->abo_data['min_messwert']}/{$this->abo_data['max_messwert']}\n";

        // Nachtsperre und Ausloeseregel stecken in TrendPolicy und sind dort getestet
        if ($this->_trendPolicy->isQuietTime($zeitpunkt_aktuell)) {
            $this->_logger->info("Nachtsperre", ['zeitzone' => $this->_trendPolicy->timezoneName()]);
            echo "  Nachtsperre\n";
            return;
        }

        $sollSenden = $this->_trendPolicy->shouldSend(
            $zeitpunkt_aktuell,
            $letzter_zeitpunkt,
            is_null($this->abo_data['min_messwert']) ? null : (int) $this->abo_data['min_messwert'],
            is_null($this->abo_data['max_messwert']) ? null : (int) $this->abo_data['max_messwert'],
        );

        if ($sollSenden) {
            $this->sendVerlaufe();
        } else {
            $this->_logger->info("Keine Aktualisierung");
            echo "  Keine Aktualisierung\n";
        }
    }

    // erstellt eine postVerlauf für diese Messstelle an alle konfigurierten Controller
    private function sendVerlaufe() {
        $this->_logger->debug('sendVerlaufe()');

        $this->_logger->info("Erstelle Verläufe für {$this->name}");
        echo "Erstelle Verläufe für {$this->name}\n";

        $message_text = $this->GetTrendMessage();

        // zaehlt mit, damit der Zeitpunkt nur bei tatsaechlicher Zustellung wandert
        $outcome = new DeliveryOutcome();

        // lädt die Verlaufsgrafik herunter
        $verlauf_image = $this->_api->fetchTrendImage($this->uuid);

        if (strlen($verlauf_image) < 1) {
            // da ist nichts zum verschicken
            return;
        }

        // Kanaele ohne Bildunterstuetzung filtert die Registrierung heraus
        foreach($this->_channels->supportingTrend() as $channel) {
            foreach($this->getSubscriptions($channel) as $abo_data) {
                try {
                    $channel->postTrend($abo_data, $message_text, $verlauf_image);
                    $outcome->recordSuccess();
                } catch (\Throwable $e) {
                    $outcome->recordFailure($channel->name());
                    $this->_logger->error("Fehler beim Trend-Versenden via {$channel->name()}", [
                        'exception' => $e->getMessage(),
                        'abo_id'      => $abo_data[$channel->subscriptionIdColumn()] ?? '?',
                        'messstellen_id' => $this->id,
                        'beschreibung' => $abo_data['beschreibung'] ?? '?',
                    ]);
                }
            }
        }

        $this->advanceTimestampIfPossible($outcome, 'letzter_verlaufszeitpunkt', 'Ganglinie');
    }
}
