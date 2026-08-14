<?php

declare(strict_types=1);

namespace Tests\bot;

use PegelBot\DeliveryOutcome;
use PHPUnit\Framework\TestCase;

final class DeliveryOutcomeTest extends TestCase
{
    // ------------------------------------------------------------------
    //  Kein Empfaenger
    // ------------------------------------------------------------------

    /**
     * Der Normalfall fuer Messstellen ohne aktive Abonnements. Er darf nicht als
     * Fehlschlag gelten, sonst wuerde der Zeitpunkt nie fortgeschrieben und der
     * Bot bearbeitete die Messstelle bei jedem Lauf erneut.
     */
    public function testNoRecipientsAdvancesTimestamp(): void
    {
        $outcome = new DeliveryOutcome();

        self::assertFalse($outcome->hasRecipients());
        self::assertTrue($outcome->shouldAdvanceTimestamp());
    }

    public function testNoRecipientsCountsZero(): void
    {
        $outcome = new DeliveryOutcome();

        self::assertSame(0, $outcome->attempted());
        self::assertSame(0, $outcome->succeeded());
        self::assertSame(0, $outcome->failed());
        self::assertFalse($outcome->isPartial());
        self::assertSame([], $outcome->failedChannels());
    }

    // ------------------------------------------------------------------
    //  Alles erfolgreich
    // ------------------------------------------------------------------

    public function testAllSuccessfulAdvancesTimestamp(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordSuccess();
        $outcome->recordSuccess();

        self::assertTrue($outcome->hasRecipients());
        self::assertTrue($outcome->shouldAdvanceTimestamp());
        self::assertFalse($outcome->isPartial());
        self::assertSame(2, $outcome->attempted());
    }

    // ------------------------------------------------------------------
    //  Alles gescheitert - der eigentliche Befund B2
    // ------------------------------------------------------------------

    public function testAllFailedDoesNotAdvanceTimestamp(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordFailure('mastodon');
        $outcome->recordFailure('bluesky');

        self::assertTrue($outcome->hasRecipients());
        self::assertFalse($outcome->shouldAdvanceTimestamp());
    }

    public function testSingleFailureDoesNotAdvanceTimestamp(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordFailure('mail');

        self::assertFalse($outcome->shouldAdvanceTimestamp());
    }

    // ------------------------------------------------------------------
    //  Teilweiser Erfolg
    // ------------------------------------------------------------------

    /**
     * Bewusste Entscheidung: Bei teilweisem Erfolg wird fortgeschrieben. Ein
     * Zeitpunkt je Kanal waere sauberer, die Tabelle fuehrt aber nur einen fuer
     * alle. Siehe SPEC.md, Befund B14.
     */
    public function testPartialSuccessStillAdvancesTimestamp(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordSuccess();
        $outcome->recordFailure('bluesky');

        self::assertTrue($outcome->shouldAdvanceTimestamp());
        self::assertTrue($outcome->isPartial());
    }

    public function testPartialSuccessNamesFailedChannels(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordSuccess();
        $outcome->recordFailure('bluesky');

        self::assertSame(['bluesky'], $outcome->failedChannels());
    }

    // ------------------------------------------------------------------
    //  Zaehlung
    // ------------------------------------------------------------------

    public function testChannelIsListedOnlyOnceDespiteSeveralFailures(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordFailure('mail');
        $outcome->recordFailure('mail');
        $outcome->recordFailure('mail');

        self::assertSame(['mail'], $outcome->failedChannels());
        self::assertSame(3, $outcome->failed());
    }

    public function testCountsAddUp(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordSuccess();
        $outcome->recordSuccess();
        $outcome->recordFailure('mastodon');

        self::assertSame(3, $outcome->attempted());
        self::assertSame(2, $outcome->succeeded());
        self::assertSame(1, $outcome->failed());
    }

    public function testSummaryForProtocol(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordSuccess();
        $outcome->recordFailure('bluesky');
        $outcome->recordFailure('mastodon');

        self::assertSame(
            ['versucht' => 3, 'erfolgreich' => 1, 'gescheitert' => 2, 'kanaele' => 'bluesky, mastodon'],
            $outcome->summary(),
        );
    }

    public function testSummaryWithoutFailuresUsesPlaceholder(): void
    {
        $outcome = new DeliveryOutcome();
        $outcome->recordSuccess();

        self::assertSame('-', $outcome->summary()['kanaele']);
    }
}
