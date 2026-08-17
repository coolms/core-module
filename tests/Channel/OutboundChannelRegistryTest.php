<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Channel;

use CoolMS\Core\Channel\DeliveryResult;
use CoolMS\Core\Channel\OutboundChannelInterface;
use CoolMS\Core\Channel\OutboundMessage;
use CoolMS\CoreModule\Channel\OutboundChannelRegistry;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ucfirst;

/**
 * F3 — pins the outbound-channel registry: id-keyed resolution, unknown-id
 * null, the full id list, and the duplicate-id fatal guard.
 */
#[CoversClass(OutboundChannelRegistry::class)]
final class OutboundChannelRegistryTest extends TestCase
{
    #[Test]
    public function resolvesChannelsByTheirId(): void
    {
        $registry = new OutboundChannelRegistry([$this->channel('rss'), $this->channel('telegram')]);

        self::assertTrue($registry->has('rss'));
        self::assertTrue($registry->has('telegram'));
        self::assertSame('rss', $registry->get('rss')?->channelId());
        self::assertSame(['rss', 'telegram'], $registry->channelIds());
    }

    #[Test]
    public function unknownIdIsNullAndAbsent(): void
    {
        $registry = new OutboundChannelRegistry([$this->channel('rss')]);

        self::assertFalse($registry->has('sms'));
        self::assertNull($registry->get('sms'));
    }

    #[Test]
    public function emptyRegistryHasNoChannels(): void
    {
        $registry = new OutboundChannelRegistry([]);

        self::assertSame([], $registry->channelIds());
        self::assertNull($registry->get('rss'));
    }

    #[Test]
    public function twoChannelsWithTheSameIdAreFatal(): void
    {
        $registry = new OutboundChannelRegistry([$this->channel('rss'), $this->channel('rss')]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate outbound channel id "rss"/');
        $registry->channelIds();
    }

    /**
     * The per-channel enable gate.
     *
     * Worth pinning because `channelIds()` is not a display preference: it is
     * what the admin picker lists AND what `SetCollectionDistributionProcessor`
     * validates a write against, so a disabled channel leaking back into it
     * silently re-opens a config surface an operator switched off.
     */
    #[Test]
    public function channelAbsentFromConfigIsEnabled(): void
    {
        // The default MUST be "on": installing a channel has to work without an
        // accompanying config edit, and this gate must never switch off a
        // channel a site already depends on.
        $registry = new OutboundChannelRegistry([$this->channel('rss'), $this->channel('webhook')], []);

        self::assertSame(['rss', 'webhook'], $registry->channelIds());
        self::assertSame([], $registry->disabledChannelIds());
    }

    #[Test]
    public function disabledChannelIsHiddenFromEveryLookup(): void
    {
        $registry = new OutboundChannelRegistry(
            [$this->channel('rss'), $this->channel('telegram')],
            ['telegram' => ['enabled' => false]],
        );

        self::assertSame(['rss'], $registry->channelIds(), 'the picker must not offer it');
        self::assertFalse($registry->has('telegram'), 'the distribution write must reject it');
        self::assertNull($registry->get('telegram'), 'and nothing can resolve it to deliver');
    }

    #[Test]
    public function disabledIdsAreReportedApartFromUnknownOnes(): void
    {
        // Why the method exists: "installed but switched off" and "no such
        // channel" need different fixes, so a rejection must distinguish them.
        $registry = new OutboundChannelRegistry(
            [$this->channel('rss'), $this->channel('telegram')],
            ['telegram' => ['enabled' => false]],
        );

        self::assertSame(['telegram'], $registry->disabledChannelIds());
        self::assertNotContains('sms', $registry->disabledChannelIds());
    }

    #[Test]
    public function explicitlyEnabledIsEquivalentToAbsent(): void
    {
        $registry = new OutboundChannelRegistry([$this->channel('rss')], ['rss' => ['enabled' => true]]);

        self::assertSame(['rss'], $registry->channelIds());
        self::assertSame([], $registry->disabledChannelIds());
    }

    #[Test]
    public function duplicateIdIsFatalEvenWhenThatIdIsDisabled(): void
    {
        // Otherwise disabling a channel would MASK the conflict until somebody
        // enabled it again — the worst moment to discover it.
        $registry = new OutboundChannelRegistry(
            [$this->channel('telegram'), $this->channel('telegram')],
            ['telegram' => ['enabled' => false]],
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/Duplicate outbound channel id "telegram"/');
        $registry->channelIds();
    }

    private function channel(string $id): OutboundChannelInterface
    {
        return new class($id) implements OutboundChannelInterface {
            public function __construct(private readonly string $id)
            {
            }

            public function channelId(): string
            {
                return $this->id;
            }

            public function label(): string
            {
                return ucfirst($this->id);
            }

            public function deliver(OutboundMessage $message, array $config): DeliveryResult
            {
                return DeliveryResult::delivered($this->id);
            }
        };
    }
}
