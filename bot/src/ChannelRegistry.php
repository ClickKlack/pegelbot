<?php

declare(strict_types=1);

namespace PegelBot;

/**
 * Verzeichnis der verfuegbaren Versandkanaele.
 *
 * Bis hierher wurden die Kanaele zur Laufzeit aus einem Datenbankwert erzeugt:
 * Aus dem Eintrag in abo_types wurde ein Klassenname zusammengesetzt, per
 * class_exists() geprueft und mit new $class instanziiert; die Abonnementtabelle
 * entstand ebenso durch Zusammensetzen. Das war weder ersetzbar noch pruefbar
 * (Befund T2) und baute Bezeichner aus Datenbankinhalten (Befund S7).
 *
 * Jetzt werden die Kanaele einmal in bootstrap.php aufgebaut und hier abgelegt.
 * Die Tabelle abo_types entscheidet weiterhin, welche davon zum Einsatz kommen -
 * sie waehlt aber nur noch aus, statt Namen zu erzeugen.
 */
final class ChannelRegistry
{
    /** @var array<string, AboInterface> */
    private array $channels = [];

    /**
     * @param iterable<AboInterface> $channels
     */
    public function __construct(iterable $channels = [])
    {
        foreach ($channels as $channel) {
            $this->add($channel);
        }
    }

    public function add(AboInterface $channel): void
    {
        $name = $channel->name();

        if (isset($this->channels[$name])) {
            throw new \InvalidArgumentException("Kanal '{$name}' ist bereits eingetragen.");
        }

        $this->channels[$name] = $channel;
    }

    public function has(string $name): bool
    {
        return isset($this->channels[$name]);
    }

    public function get(string $name): AboInterface
    {
        if (!$this->has($name)) {
            throw new \RuntimeException(
                "Kanal '{$name}' ist nicht eingetragen. Vorhanden: " . implode(', ', $this->names())
            );
        }

        return $this->channels[$name];
    }

    /**
     * Namen aller eingetragenen Kanaele.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->channels);
    }

    /**
     * Alle eingetragenen Kanaele.
     *
     * @return list<AboInterface>
     */
    public function all(): array
    {
        return array_values($this->channels);
    }

    /**
     * Nur die Kanaele, die eine Ganglinie verschicken koennen.
     *
     * @return list<AboInterface>
     */
    public function supportingTrend(): array
    {
        return array_values(array_filter(
            $this->channels,
            static fn (AboInterface $channel): bool => $channel->supportsTrend(),
        ));
    }

    /**
     * Namen, zu denen kein Kanal eingetragen ist.
     *
     * Dient dazu, verwaiste Zeilen in abo_types zu erkennen. Sie werden
     * uebersprungen statt den Lauf abzubrechen - ein Konfigurationsfehler bei
     * einem Kanal soll den Versand ueber die uebrigen nicht verhindern. Damit es
     * nicht unbemerkt bleibt, meldet der Aufrufer sie ins Protokoll.
     *
     * @param iterable<string> $names
     *
     * @return list<string>
     */
    public function unknown(iterable $names): array
    {
        $unknown = [];

        foreach ($names as $name) {
            if (!$this->has($name) && !in_array($name, $unknown, true)) {
                $unknown[] = $name;
            }
        }

        return $unknown;
    }

    /**
     * Die genannten Kanaele, soweit eingetragen, in der Reihenfolge der Uebergabe.
     *
     * Unbekannte Namen entfallen stillschweigend; sie sind ueber unknown() zu
     * ermitteln und zu melden.
     *
     * @param iterable<string> $names
     *
     * @return list<AboInterface>
     */
    public function selectAvailable(iterable $names): array
    {
        $selected = [];

        foreach ($names as $name) {
            if ($this->has($name)) {
                $selected[] = $this->get($name);
            }
        }

        return $selected;
    }
}
