<?php

declare(strict_types=1);

namespace PegelBot;

abstract class AboController implements AboInterface
{
    protected \Monolog\Logger $_logger;

    public function __construct(\Monolog\Logger $logger)
    {
        $this->_logger = $logger;
    }

    abstract public function name(): string;

    abstract public function postNotify(array $abo_details, string $message_content): void;
    abstract public function postTrend(array $abo_details, string $message_content, string $image): void;

    /**
     * Abonnementtabelle nach der ueblichen Benennung.
     *
     * Der Name stammt jetzt vom Kanal selbst und nicht mehr aus einem
     * Datenbankwert. Kanaele mit abweichender Benennung ueberschreiben dies.
     */
    public function subscriptionTable(): string
    {
        return 'abonnements_' . $this->name();
    }

    public function subscriptionIdColumn(): string
    {
        return $this->name() . '_abo_id';
    }

    // Standard: Verlauf wird unterstützt – Controller die das nicht können, überschreiben dies mit false
    public function supportsTrend(): bool
    {
        return true;
    }
}
