<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Channel;

use CoolMS\Core\Channel\OutboundChannelInterface;
use CoolMS\Core\Channel\OutboundChannelRegistryInterface;
use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

use function sprintf;

/**
 * F3 — indexes every tagged {@see OutboundChannelInterface} by its
 * `channelId()` into a single lookup surface.
 *
 * The `#[AutowireIterator]` pin is deliberate (the tagged-iterator glob
 * footgun, same as {@see \CoolMS\CoreModule\Retention\RetentionPruneRunner}):
 * binding the collection through the Core Extension's `setArgument` would be
 * clobbered by the `App\:` services glob re-registering this class, so the tag
 * is consumed on the constructor param directly.
 *
 * Two channels claiming the same id are a fatal misconfiguration — caught here
 * (on first index build) rather than silently letting one shadow the other.
 */
final class OutboundChannelRegistry implements OutboundChannelRegistryInterface
{
    /** @var array<string, OutboundChannelInterface>|null lazily built id→channel map (ENABLED only) */
    private ?array $byId = null;

    /** @var list<string>|null installed-but-switched-off ids, built alongside {@see} */
    private ?array $disabled = null;

    /**
     * @param iterable<OutboundChannelInterface>   $channels      tagged `coolms.outbound_channel`
     * @param array<string, array{enabled?: bool}> $channelConfig `coolms_core.outbound_channels`,
     *                                                            bound by {@see \CoolMS\CoreBundle\DependencyInjection\Compiler\OutboundChannelRegistryConfigPass}
     *                                                            (a compiler pass, not `#[Autowire(param:)]`, so the wiring stays in
     *                                                            configuration where an operator can find it — and so the `App\:`
     *                                                            glob cannot clobber it)
     */
    public function __construct(
        #[AutowireIterator('coolms.outbound_channel')]
        private readonly iterable $channels,
        private readonly array $channelConfig = [],
    ) {
    }

    public function has(string $channelId): bool
    {
        return isset($this->map()[$channelId]);
    }

    public function get(string $channelId): ?OutboundChannelInterface
    {
        return $this->map()[$channelId] ?? null;
    }

    public function channelIds(): array
    {
        return array_keys($this->map());
    }

    public function disabledChannelIds(): array
    {
        $this->map();

        return $this->disabled ?? [];
    }

    /**
     * The ENABLED id→channel map.
     *
     * A channel absent from configuration is ENABLED — the default has to be
     * "on" so installing a channel keeps working without an accompanying config
     * edit, and so this gate cannot silently switch off a channel a site already
     * depends on. Switching one off is therefore always an explicit decision.
     *
     * @return array<string, OutboundChannelInterface>
     */
    private function map(): array
    {
        if (null !== $this->byId) {
            return $this->byId;
        }

        $map = [];
        $off = [];
        $seen = [];
        foreach ($this->channels as $channel) {
            $id = $channel->channelId();
            // Duplicate detection runs over EVERY installed channel, enabled or
            // not: two classes claiming one id is a misconfiguration whether or
            // not the id happens to be switched on today.
            if (isset($seen[$id])) {
                throw new LogicException(sprintf('Duplicate outbound channel id "%s": %s conflicts with %s.', $id, $channel::class, $seen[$id]::class));
            }
            $seen[$id] = $channel;

            if (false === ($this->channelConfig[$id]['enabled'] ?? true)) {
                $off[] = $id;

                continue;
            }
            $map[$id] = $channel;
        }

        $this->disabled = $off;

        return $this->byId = $map;
    }
}
