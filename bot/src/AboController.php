<?php

namespace PegelBot;

abstract class AboController implements AboInterface
{
    protected \Monolog\Logger $_logger;

    public function __construct(\Monolog\Logger $logger)
    {
        $this->_logger = $logger;
    }

    abstract public function postNotify(array $abo_details, string $message_content): void;
    abstract public function postTrend(array $abo_details, string $message_content, string $image): void;

    // Standard: Verlauf wird unterstützt – Controller die das nicht können, überschreiben dies mit false
    public function supportsTrend(): bool
    {
        return true;
    }
}