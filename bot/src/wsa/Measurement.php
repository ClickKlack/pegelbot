<?php

namespace WSA;

class Measurement {
    private \DateTime $timestamp;
    private int $value;

    public function __construct(string $timestamp, int $value) {
        // in DateTime umwandeln
        $this->timestamp = new \DateTime($timestamp);
        // den Timestamp auf jeden Fall in UTC halten
        $this->timestamp->setTimezone(new \DateTimeZone('UTC'));

        $this->value = $value;
    }

    public function getTimestamp():\DateTime {
        return $this->timestamp;
    }

    public function getValue():int {
        return $this->value;
    }
}
