<?php

declare(strict_types=1);

namespace PegelBot;

/**
 * Ergebnis eines Versandvorgangs ueber alle Kanaele einer Messstelle.
 *
 * Der Zweck ist die Unterscheidung zweier Faelle, die frueher gleich behandelt
 * wurden und zu Befund B2 fuehrten:
 *
 *   - Es gab gar keinen Empfaenger. Das ist der Normalfall fuer Messstellen ohne
 *     aktive Abonnements und keineswegs ein Fehlschlag.
 *   - Es gab Empfaenger, aber kein einziger Versand gelang. Dann darf der
 *     Zeitpunkt nicht fortgeschrieben werden, sonst gilt die Meldung als
 *     erledigt und ist dauerhaft verloren.
 *
 * Die Klasse haelt bewusst nur Zaehler und keine Fachlogik des Versands. Dadurch
 * ist sie ohne Datenbank und ohne Kanaele pruefbar.
 */
final class DeliveryOutcome
{
    private int $succeeded = 0;

    /** @var list<string> Kanalnamen, bei denen mindestens ein Versand scheiterte */
    private array $failedChannels = [];

    private int $failed = 0;

    public function recordSuccess(): void
    {
        $this->succeeded++;
    }

    /**
     * @param string $channel Name des Kanals, etwa "mastodon"
     */
    public function recordFailure(string $channel): void
    {
        $this->failed++;

        // Je Kanal nur einmal vermerken; die Anzahl steht in $failed
        if (!in_array($channel, $this->failedChannels, true)) {
            $this->failedChannels[] = $channel;
        }
    }

    public function attempted(): int
    {
        return $this->succeeded + $this->failed;
    }

    public function succeeded(): int
    {
        return $this->succeeded;
    }

    public function failed(): int
    {
        return $this->failed;
    }

    /**
     * Gab es ueberhaupt einen Empfaenger?
     */
    public function hasRecipients(): bool
    {
        return $this->attempted() > 0;
    }

    /**
     * Darf der Zeitpunkt der letzten Zustellung fortgeschrieben werden?
     *
     * Ja, wenn es keinen Empfaenger gab oder mindestens einer erreicht wurde.
     * Nein nur dann, wenn Empfaenger vorhanden waren und alle scheiterten -
     * dann soll der naechste Lauf es erneut versuchen.
     *
     * Bekannte Einschraenkung: Bei teilweisem Erfolg wird fortgeschrieben, die
     * Meldung an den gescheiterten Kanal ist damit verloren. Sauber waere ein
     * Zeitpunkt je Kanal; die Tabelle fuehrt aber nur einen fuer alle. Siehe
     * SPEC.md, Befund B14.
     */
    public function shouldAdvanceTimestamp(): bool
    {
        return !$this->hasRecipients() || $this->succeeded > 0;
    }

    /**
     * Ist zwar versandt worden, aber nicht an alle Kanaele?
     */
    public function isPartial(): bool
    {
        return $this->succeeded > 0 && $this->failed > 0;
    }

    /**
     * Kanaele, bei denen mindestens ein Versand scheiterte.
     *
     * @return list<string>
     */
    public function failedChannels(): array
    {
        return $this->failedChannels;
    }

    /**
     * Kurzfassung fuer das Protokoll.
     *
     * @return array{versucht: int, erfolgreich: int, gescheitert: int, kanaele: string}
     */
    public function summary(): array
    {
        return [
            'versucht'    => $this->attempted(),
            'erfolgreich' => $this->succeeded,
            'gescheitert' => $this->failed,
            'kanaele'     => implode(', ', $this->failedChannels) ?: '-',
        ];
    }
}
