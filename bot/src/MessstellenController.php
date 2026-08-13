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

    // Konstruktor mit Eigenschaften der Messstelle
    public function __construct(\Doctrine\DBAL\Connection $connection, \Monolog\Logger $logger, \WSA\MeasurementApiInterface $api, int $id, string $name, int $nummer, string $uuid, ?array $AboData = null) {
        // Objekte werden in PHP ohnehin als Handle uebergeben, deshalb ohne Referenz
        $this->_connection = $connection;
        $this->_logger     = $logger;
        $this->_api        = $api;

        // die eigentlichen Eigenschaften übernehmen
        $this->id = $id;
        $this->name = $name;
        $this->nummer = $nummer;
        $this->uuid = $uuid;
        $this->abo_data = $AboData;
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
            $date = new \DateTime("now", new \DateTimeZone('UTC'));
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

        //$zeit_lokal = $zeitpunkt_aktuell = $zeitpunkt_aktuell->setTimezone(new DateTimeZone('Europe/Berlin'));
        echo "Lade Messwerte für {$this->name} seit ".$this->getTimestampLetzteMessung()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s')."\n";
        $this->_logger->info("Lade Messwerte" , [
            'name' => $this->name,
            'last_date' => $this->getTimestampLetzteMessung()->setTimezone(new \DateTimeZone('Europe/Berlin'))->format('d.m.Y H:i:s')
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

        $this->_logger->info("Erstelle Notifys für {$this->name} - Letzter Wert: {$this->abo_data['letzter_messwert']} ({$this->abo_data['letzter_zeitpunkt']} UTC) - Aktuellster Wert: {$this->abo_data['messwert_aktuell']} ({$this->abo_data['zeitpunkt_aktuell']} UTC)");
        echo "Erstelle Notifys für {$this->name} - Letzter Wert: {$this->abo_data['letzter_messwert']} ({$this->abo_data['letzter_zeitpunkt']} UTC) - Aktuellster Wert: {$this->abo_data['messwert_aktuell']} ({$this->abo_data['zeitpunkt_aktuell']} UTC)\n";

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

        // alle Sendecontroller prüfen
        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->select('name')
            ->from('abo_types');
        $abo_types = $this->_connection->fetchAllAssociative($queryBuilder);

        // erstellt eigentliche Nachricht
        $message_text = $this->GetNotifyMessage();

        // alle Sendecontroller einzlen bearbeiten
        foreach($abo_types as $abo) {
            $class = __NAMESPACE__ . '\\' ."{$abo['name']}Controller";
            // prüfen ob Controller-Klasse existiert
            if (!class_exists($class, true)) {
                throw new \Exception("Klasse {$class} fehlt", 1);
            }

            $controller = new $class($this->_logger);

            // Abos für Messstelle laden
            $queryBuilder = $this->_connection->createQueryBuilder();
            $queryBuilder
                ->select('*')
                ->from('abonnements_'.$abo['name'])
                ->where('messstellen_id = :messstellen_id')
                ->andWhere('aktiv = 1')
                ->setParameter('messstellen_id', $this->id);

            $abo_details = $queryBuilder->fetchAllAssociative();
            // Abos verarbeiten lassen
            foreach($abo_details as $abo_data) {
                try {
                    $controller->postNotify($abo_data, $message_text);
                } catch (\Throwable $e) {
                    $this->_logger->error("Fehler beim Versenden via {$abo['name']}", [
                        'exception' => $e->getMessage(),
                        'abo_id'      => $abo_data[$abo['name'].'_abo_id'] ?? '?',
                        'messstellen_id' => $this->id,
                        'beschreibung' => $abo_data['beschreibung'] ?? '?',
                    ]);
                }
            }
        }

        // Zeitpunkt aktualisieren
        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->update('messstelllen_abo_zuordnung')
            ->set('letzter_zeitpunkt', ':letzter_zeitpunkt')
            ->where('messstellen_id = :messstellen_id')
            ->setParameter('letzter_zeitpunkt', $this->abo_data['zeitpunkt_aktuell'])
            ->setParameter('messstellen_id', $this->id)
        ;
        $queryBuilder->executeStatement();
    }

    // prüft, ob eine neue Verlaufsgrafik verschickt werden kann
    public function sendAboVerlaufIfNeeded() {
        $this->_logger->debug("sendAboVerlaufIfNeeded({$this->name})");

        if (is_null($this->abo_data)) {
            throw new \Exception("Keine Abo-Daten");
        }

        $letzter_zeitpunkt = new \DateTime($this->abo_data['letzter_verlaufszeitpunkt'], new \DateTimeZone('UTC'));
        $zeitpunkt_aktuell = new \DateTime("now", new \DateTimeZone('UTC'));
        $time_diff = $zeitpunkt_aktuell->diff($letzter_zeitpunkt, true);

        $this->_logger->info("Erstelle Verläufe für {$this->name} - Letzter: {$this->abo_data['letzter_verlaufszeitpunkt']} UTC - Aktuellster Wert: {$this->abo_data['zeitpunkt_aktuell']} UTC - min/max: {$this->abo_data['min_messwert']}/{$this->abo_data['max_messwert']}");
        echo "Erstelle Verläufe für {$this->name} - Letzter: {$this->abo_data['letzter_verlaufszeitpunkt']} UTC - Aktuellster Wert: {$this->abo_data['zeitpunkt_aktuell']} UTC - min/max: {$this->abo_data['min_messwert']}/{$this->abo_data['max_messwert']}\n";

        // keine Verläufe nachts 22-6 Uhr
        if ($zeitpunkt_aktuell->format('H') < 6 || $zeitpunkt_aktuell->format('H') > 22) {
            $this->_logger->info("Nachtsperre");
            echo "  Nachtsperre\n";
            return;
        }

        // Prüfen ob Notify notwendig (Veränderung Wert und letztes Notify mind. 1 Tag oder letztes Notify mind. 24h her)
        if ((abs($this->abo_data['min_messwert'] - $this->abo_data['max_messwert']) >= 50 &&  $time_diff->days >= 1) || $time_diff->days >= 7) {
            // ja, Erstellung an alle konfigurierten Controller leiten
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

        // alle Sendecontroller prüfen
        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->select('name')
            ->from('abo_types');
        $abo_types = $this->_connection->fetchAllAssociative($queryBuilder);

        // erstellt eigentliche Nachricht
        $message_text = $this->GetTrendMessage();

        // lädt die Verlaufsgrafik herunter
        $verlauf_image = $this->_api->fetchTrendImage($this->uuid);
        
        if (is_null($verlauf_image) || strlen($verlauf_image) < 1) {
            // da ist nichts zum verschicken
            return;
        }

        // alle Sendecontroller einzlen bearbeiten
        foreach($abo_types as $abo) {
            $class = __NAMESPACE__ . '\\' ."{$abo['name']}Controller";
            // prüfen ob Controller-Klasse existiert
            if (!class_exists($class, true)) {
                throw new \Exception("Klasse {$class} fehlt", 1);
            }

            $controller = new $class($this->_logger);

            if (!$controller->supportsTrend()) {
                $this->_logger->info("Controller unterstützt keinen Verlauf, wird übersprungen", [
                    'controller' => $abo['name']
                ]);
                continue; // äußerer foreach($abo_types)-Loop
            }

            // Abos für Messstelle laden
            $queryBuilder = $this->_connection->createQueryBuilder();
            $queryBuilder
                ->select('*')
                ->from('abonnements_'.$abo['name'])
                ->where('messstellen_id = :messstellen_id')
                ->andWhere('aktiv = 1')
                ->setParameter('messstellen_id', $this->id);

            $abo_details = $queryBuilder->fetchAllAssociative();
            // Abos verarbeiten lassen
            foreach($abo_details as $abo_data) {
                try {
                    $controller->postTrend($abo_data, $message_text, $verlauf_image);
                } catch (\Throwable $e) {
                    $this->_logger->error("Fehler beim Trend-Versenden via {$abo['name']}", [
                        'exception' => $e->getMessage(),
                        'abo_id'      => $abo_data[$abo['name'].'_abo_id'] ?? '?',
                        'messstellen_id' => $this->id,
                        'beschreibung' => $abo_data['beschreibung'] ?? '?',
                    ]);
                }
            }
        }

        // Zeitpunkt aktualisieren
        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->update('messstelllen_abo_zuordnung')
            ->set('letzter_verlaufszeitpunkt', ':letzter_verlaufszeitpunkt')
            ->where('messstellen_id = :messstellen_id')
            ->setParameter('letzter_verlaufszeitpunkt', $this->abo_data['zeitpunkt_aktuell'])
            ->setParameter('messstellen_id', $this->id)
        ;
        $queryBuilder->executeStatement();
    }
}
