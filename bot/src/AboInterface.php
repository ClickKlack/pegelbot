<?php

namespace PegelBot;

interface AboInterface {
    public function postNotify(array $abo_details, string $message_content);
    public function postTrend(array $abo_details, string $message_content, string $image);
    public function supportsTrend(): bool;
}