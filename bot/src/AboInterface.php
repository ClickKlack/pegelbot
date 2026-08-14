<?php

declare(strict_types=1);

namespace PegelBot;

/**
 * Vertrag eines Versandkanals.
 *
 * name() und subscriptionTable() kamen mit der Kanal-Registrierung dazu: Vorher
 * wurden Klassen- und Tabellenname zur Laufzeit aus einem Datenbankwert
 * zusammengesetzt. Jetzt benennt sich jeder Kanal selbst.
 */
interface AboInterface {
    /**
     * Kurzname des Kanals, etwa "mastodon".
     *
     * Muss mit dem Eintrag in der Tabelle abo_types uebereinstimmen.
     */
    public function name(): string;

    /**
     * Tabelle, in der die Abonnements dieses Kanals stehen.
     */
    public function subscriptionTable(): string;

    /**
     * Spalte mit dem Schluessel eines Abonnements, fuer Protokollausgaben.
     */
    public function subscriptionIdColumn(): string;

    public function postNotify(array $abo_details, string $message_content);
    public function postTrend(array $abo_details, string $message_content, string $image);
    public function supportsTrend(): bool;
}
