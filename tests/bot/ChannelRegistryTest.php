<?php

declare(strict_types=1);

namespace Tests\bot;

use PegelBot\AboInterface;
use PegelBot\ChannelRegistry;
use PHPUnit\Framework\TestCase;

final class ChannelRegistryTest extends TestCase
{
    /**
     * Ein Kanal ohne Versandwirkung, nur mit Namen und Bildfaehigkeit.
     */
    private function channel(string $name, bool $supportsTrend = true): AboInterface
    {
        return new class ($name, $supportsTrend) implements AboInterface {
            public function __construct(
                private readonly string $name,
                private readonly bool $supportsTrend,
            ) {
            }

            public function name(): string
            {
                return $this->name;
            }

            public function subscriptionTable(): string
            {
                return 'abonnements_' . $this->name;
            }

            public function subscriptionIdColumn(): string
            {
                return $this->name . '_abo_id';
            }

            public function postNotify(array $abo_details, string $message_content): void
            {
            }

            public function postTrend(array $abo_details, string $message_content, string $image): void
            {
            }

            public function supportsTrend(): bool
            {
                return $this->supportsTrend;
            }
        };
    }

    // ------------------------------------------------------------------
    //  Eintragen und Nachschlagen
    // ------------------------------------------------------------------

    public function testEmptyRegistryHasNoChannels(): void
    {
        self::assertSame([], (new ChannelRegistry())->names());
    }

    public function testChannelsAreRegisteredUnderTheirOwnName(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail'), $this->channel('mastodon')]);

        self::assertSame(['mail', 'mastodon'], $registry->names());
        self::assertTrue($registry->has('mail'));
        self::assertFalse($registry->has('bluesky'));
    }

    public function testGetReturnsTheRegisteredInstance(): void
    {
        $mail = $this->channel('mail');
        $registry = new ChannelRegistry([$mail]);

        self::assertSame($mail, $registry->get('mail'));
    }

    public function testAddAfterConstruction(): void
    {
        $registry = new ChannelRegistry();
        $registry->add($this->channel('twitter'));

        self::assertSame(['twitter'], $registry->names());
    }

    public function testDuplicateChannelIsRejected(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail')]);

        $this->expectException(\InvalidArgumentException::class);

        $registry->add($this->channel('mail'));
    }

    // ------------------------------------------------------------------
    //  Unbekannte Kanaele
    // ------------------------------------------------------------------

    public function testGetOnUnknownChannelThrows(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Kanal 'gibtesnicht' ist nicht eingetragen");

        $registry->get('gibtesnicht');
    }

    public function testErrorMessageListsAvailableChannels(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail'), $this->channel('bluesky')]);

        $this->expectExceptionMessage('Vorhanden: mail, bluesky');

        $registry->get('gibtesnicht');
    }

    // ------------------------------------------------------------------
    //  Verwaiste Namen aus abo_types
    // ------------------------------------------------------------------

    /**
     * Eine verwaiste Zeile in abo_types soll auffallen, aber den Lauf nicht
     * abbrechen: Der Aufrufer meldet sie ins Protokoll und ueberspringt sie.
     */
    public function testUnknownNamesAreReported(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail'), $this->channel('bluesky')]);

        self::assertSame(['sms'], $registry->unknown(['mail', 'sms', 'bluesky']));
    }

    public function testNoUnknownNamesWhenAllRegistered(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail')]);

        self::assertSame([], $registry->unknown(['mail']));
    }

    public function testUnknownNameIsListedOnlyOnce(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail')]);

        self::assertSame(['sms'], $registry->unknown(['sms', 'sms']));
    }

    // ------------------------------------------------------------------
    //  Auswahl
    // ------------------------------------------------------------------

    public function testSelectAvailableReturnsChannelsInGivenOrder(): void
    {
        $registry = new ChannelRegistry([
            $this->channel('mail'),
            $this->channel('bluesky'),
            $this->channel('mastodon'),
        ]);

        $selected = $registry->selectAvailable(['mastodon', 'mail']);

        self::assertCount(2, $selected);
        self::assertSame('mastodon', $selected[0]->name());
        self::assertSame('mail', $selected[1]->name());
    }

    public function testSelectingNothingYieldsNothing(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail')]);

        self::assertSame([], $registry->selectAvailable([]));
    }

    /**
     * Der Kern der Entscheidung: Ein unbekannter Name entfaellt, die uebrigen
     * Kanaele verschicken weiterhin.
     */
    public function testSelectAvailableSkipsUnknownNames(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail'), $this->channel('bluesky')]);

        $selected = $registry->selectAvailable(['mail', 'gibtesnicht', 'bluesky']);

        self::assertSame(['mail', 'bluesky'], array_map(
            static fn (AboInterface $c): string => $c->name(),
            $selected,
        ));
    }

    // ------------------------------------------------------------------
    //  Alle Kanaele und Ganglinien-Faehigkeit
    // ------------------------------------------------------------------

    public function testAllReturnsEveryRegisteredChannel(): void
    {
        $registry = new ChannelRegistry([$this->channel('mail'), $this->channel('bluesky')]);

        self::assertSame(['mail', 'bluesky'], array_map(
            static fn (AboInterface $c): string => $c->name(),
            $registry->all(),
        ));
    }

    public function testSupportingTrendFiltersOutChannelsWithoutImages(): void
    {
        $registry = new ChannelRegistry([
            $this->channel('mail'),
            $this->channel('sms', supportsTrend: false),
            $this->channel('mastodon'),
        ]);

        self::assertSame(['mail', 'mastodon'], array_map(
            static fn (AboInterface $c): string => $c->name(),
            $registry->supportingTrend(),
        ));
    }

    public function testSupportingTrendCanEndUpEmpty(): void
    {
        $registry = new ChannelRegistry([$this->channel('sms', supportsTrend: false)]);

        self::assertSame([], $registry->supportingTrend());
    }

    // ------------------------------------------------------------------
    //  Benennung der Tabellen
    // ------------------------------------------------------------------

    /**
     * Der Tabellenname kommt vom Kanal und nicht mehr aus einem Datenbankwert -
     * das war Befund S7.
     */
    public function testChannelNamesItsOwnSubscriptionTable(): void
    {
        $registry = new ChannelRegistry([$this->channel('mastodon')]);

        self::assertSame('abonnements_mastodon', $registry->get('mastodon')->subscriptionTable());
        self::assertSame('mastodon_abo_id', $registry->get('mastodon')->subscriptionIdColumn());
    }
}
