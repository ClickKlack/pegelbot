<?php

namespace PegelBot;

/**
 * @package Controller
 */
class Controller {
    protected \Doctrine\DBAL\Connection $_connection;
    protected \Monolog\Logger $_logger;
    protected \WSA\MeasurementApiInterface $_api;
    protected \Psr\Clock\ClockInterface $_clock;
    protected TrendPolicy $_trendPolicy;
    protected ChannelRegistry $_channels;

    /**
     * constructor
     */
    public function __construct(
        \Doctrine\DBAL\Connection $connection,
        \Monolog\Logger $logger,
        \WSA\MeasurementApiInterface $api,
        \Psr\Clock\ClockInterface $clock,
        TrendPolicy $trendPolicy,
        ChannelRegistry $channels
    ) {
        $this->_connection  = $connection;
        $this->_logger      = $logger;
        $this->_api         = $api;
        $this->_clock       = $clock;
        $this->_trendPolicy = $trendPolicy;
        $this->_channels    = $channels;
    }

    /**
     * Programmsteuerung
     */
    public function run() {

        $this->_logger->info('Start');

        // Einmal je Lauf aufloesen, welche Kanaele freigeschaltet sind. Vorher
        // fragte jede Messstelle in jeder Phase erneut ab - bei drei Messstellen
        // vier Abfragen statt einer. Und eine Meldung ueber einen unbekannten
        // Kanal waere entsprechend oft im Protokoll gelandet.
        $this->_channels = $this->resolveActiveChannels();

        $this->verarbeiteMessstellen();
        $this->verarbeiteAbos();
        $this->verarbeiteAbosVerlauf();

        $this->_logger->info('Ende');
    }

    /**
     * Ermittelt die freigeschalteten Kanaele aus abo_types.
     *
     * Ein Name ohne eingetragenen Kanal wird protokolliert und uebersprungen.
     * Frueher brach der Lauf an dieser Stelle ab - eine verwaiste Zeile
     * verhinderte damit saemtliche Benachrichtigungen, auch die der uebrigen
     * Kanaele.
     */
    private function resolveActiveChannels(): ChannelRegistry {
        $queryBuilder = $this->_connection->createQueryBuilder();
        $queryBuilder
            ->select('name')
            ->from('abo_types');

        $enabled = array_map(
            static fn (array $row): string => (string) $row['name'],
            $this->_connection->fetchAllAssociative($queryBuilder)
        );

        foreach ($this->_channels->unknown($enabled) as $name) {
            $this->_logger->error('Unbekannter Kanal in abo_types, wird uebersprungen', [
                'kanal'     => $name,
                'vorhanden' => implode(', ', $this->_channels->names()),
            ]);
            echo "  Unbekannter Kanal '{$name}' in abo_types, wird uebersprungen\n";
        }

        $active = new ChannelRegistry($this->_channels->selectAvailable($enabled));

        $this->_logger->debug('Aktive Kanaele', ['kanaele' => implode(', ', $active->names())]);

        return $active;
    }

    private function verarbeiteMessstellen() {
        $this->_logger->debug('verarbeiteMessstellen()');

        // zuerst einmal die zu verabeitenden Messstellen laden
        $messstellen = $this->getAktualisierendeMessstellen();
      
        // Daten aktualisieren
        foreach($messstellen as &$messstelle) {
            $messstelle->ladeUndSpeichereMessungen();
        }
    }
    
    private function getAktualisierendeMessstellen() {
      
        $messtellen = array();
        $sql = "SELECT id, name, nummer, uuid FROM messstellen WHERE update_active = 1";
        foreach ($this->_connection->iterateAssociativeIndexed($sql) as $id => $data) {
            $messtellen[] = new MessstellenController($this->_connection, $this->_logger, $this->_api, $this->_clock, $this->_trendPolicy, $this->_channels, $id, $data['name'], $data['nummer'], $data['uuid']);
        }
      
        return $messtellen;
    }

    private function verarbeiteAbos() {
        $this->_logger->debug('verarbeiteAbos()');

        $messstellen = $this->getAboMessstellen();
      
        // Abo Notifys verschicken
        foreach($messstellen as &$messstelle) {
            $messstelle->sendAboNotifysIfNeeded();
        }
    }
    
    private function getAboMessstellen() {
      
        $messtellen = array();
        $sql = "SELECT m.id, m.name, m.nummer, m.uuid, a.letzter_zeitpunkt, w.messwert letzter_messwert, wa.messwert messwert_aktuell, wa.zeitpunkt zeitpunkt_aktuell, a.message_template,
        IFNULL(wa.messwert - (SELECT max(mwi.messwert) FROM messwerte mwi WHERE mwi.messstellen_id = m.id AND mwi.zeitpunkt = DATE_ADD(wa.zeitpunkt, INTERVAL -6 HOUR)), 'N/A') diff_messwert_6h,
        IFNULL(wa.messwert - (SELECT max(mwi.messwert) FROM messwerte mwi WHERE mwi.messstellen_id = m.id AND mwi.zeitpunkt = DATE_ADD(wa.zeitpunkt, INTERVAL -12 HOUR)), 'N/A') diff_messwert_12h,
        IFNULL(wa.messwert - (SELECT max(mwi.messwert) FROM messwerte mwi WHERE mwi.messstellen_id = m.id AND mwi.zeitpunkt = DATE_ADD(wa.zeitpunkt, INTERVAL -1 DAY)), 'N/A') diff_messwert_24h,
        IFNULL(wa.messwert - (SELECT max(mwi.messwert) FROM messwerte mwi WHERE mwi.messstellen_id = m.id AND mwi.zeitpunkt = DATE_ADD(wa.zeitpunkt, INTERVAL -2 DAY)), 'N/A') diff_messwert_2d,
        IFNULL(wa.messwert - (SELECT max(mwi.messwert) FROM messwerte mwi WHERE mwi.messstellen_id = m.id AND mwi.zeitpunkt = DATE_ADD(wa.zeitpunkt, INTERVAL -4 DAY)), 'N/A') diff_messwert_4d,
        IFNULL(wa.messwert - (SELECT max(mwi.messwert) FROM messwerte mwi WHERE mwi.messstellen_id = m.id AND mwi.zeitpunkt = DATE_ADD(wa.zeitpunkt, INTERVAL -7 DAY)), 'N/A') diff_messwert_7d
        FROM messstellen m
        INNER JOIN messstelllen_abo_zuordnung a ON m.id = a.messstellen_id
        LEFT JOIN messwerte w ON w.messstellen_id = m.id AND a.letzter_zeitpunkt = w.zeitpunkt
        INNER JOIN messwerte wa ON wa.messstellen_id = m.id AND wa.zeitpunkt = (SELECT max(mwi.zeitpunkt) FROM messwerte mwi WHERE mwi.messstellen_id = m.id)
        AND m.update_active = 1";
        foreach ($this->_connection->iterateAssociativeIndexed($sql) as $id => $data) {
            $messtellen[] = new MessstellenController($this->_connection, $this->_logger, $this->_api, $this->_clock, $this->_trendPolicy, $this->_channels, $id, $data['name'], $data['nummer'], $data['uuid'], $data);
        }
      
        return $messtellen;
    }

    private function verarbeiteAbosVerlauf() {
        $this->_logger->debug('verarbeiteAbosVerlauf()');

        $messstellen = $this->getAboVerlaufMessstellen();
      
        // Abo Notifys verschicken
        foreach($messstellen as &$messstelle) {
            $messstelle->sendAboVerlaufIfNeeded();
        }
    }
    
    private function getAboVerlaufMessstellen() {
      
        $messtellen = array();
        $sql = "SELECT m.id, m.name, m.nummer, m.uuid, a.letzter_verlaufszeitpunkt, a.trend_template,
        (SELECT max(mwi.zeitpunkt) FROM messwerte mwi WHERE mwi.messstellen_id = m.id) zeitpunkt_aktuell,
        (SELECT min(mim.messwert) FROM messwerte mim WHERE mim.messstellen_id = m.id AND mim.zeitpunkt >= a.letzter_verlaufszeitpunkt) min_messwert,
        (SELECT max(mam.messwert) FROM messwerte mam WHERE mam.messstellen_id = m.id AND mam.zeitpunkt >= a.letzter_verlaufszeitpunkt) max_messwert
        FROM messstellen m
        INNER JOIN messstelllen_abo_zuordnung a ON m.id = a.messstellen_id
        AND m.update_active = 1";
        foreach ($this->_connection->iterateAssociativeIndexed($sql) as $id => $data) {
            $messtellen[] = new MessstellenController($this->_connection, $this->_logger, $this->_api, $this->_clock, $this->_trendPolicy, $this->_channels, $id, $data['name'], $data['nummer'], $data['uuid'], $data);
        }
      
        return $messtellen;
    }
}