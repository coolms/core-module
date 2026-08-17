<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Outbox;

use CoolMS\Core\Outbox\OutboxMessagePublished;
use CoolMS\Core\Outbox\OutboxPublisherInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\Core\Persistence\ManagerResetterInterface;
use CoolMS\CoreModule\Outbox\OutboxRelay;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

use function array_map;

/**
 * The outbox relay: publishes each claimed message then marks it delivered; a
 * publish failure marks the row failed (attempt bumped, stays undelivered) and
 * the relay carries on with the rest — delivery is at-least-once.
 */
final class OutboxRelayTest extends TestCase
{
    #[Test]
    public function itPublishesThenMarksEachMessageDelivered(): void
    {
        $publisher = $this->publisher();
        $repo = $this->repository([$this->message('id-a'), $this->message('id-b')]);
        $resetter = $this->resetter();

        $published = new OutboxRelay($repo, $publisher, $resetter, new NullLogger())->relay(10);

        self::assertSame(2, $published);
        self::assertSame(['id-a', 'id-b'], array_map(static fn ($m) => $m->outboxId, $publisher->published));
        self::assertSame(['id-a', 'id-b'], $repo->publishedIds);
        self::assertSame([], $repo->failedIds);
        self::assertSame(0, $resetter->resets, 'a clean batch never resets the EM');
    }

    #[Test]
    public function aFailedPublishMarksTheRowFailedAndResetsTheManagerSoTheBatchDoesNotCascade(): void
    {
        $publisher = $this->publisher(throwOn: 'id-a');
        $repo = $this->repository([$this->message('id-a'), $this->message('id-b')]);
        $resetter = $this->resetter();

        $published = new OutboxRelay($repo, $publisher, $resetter, new NullLogger())->relay(10);

        // Only 'id-b' published; 'id-a' is marked failed (left undelivered)...
        self::assertSame(1, $published);
        self::assertSame(['id-b'], $repo->publishedIds);
        self::assertSame(['id-a'], $repo->failedIds);
        // ...and the EM was reset once (after 'id-a' failed) so a consumer that
        // closed it can't poison 'id-b' with EntityManagerClosed.
        self::assertSame(1, $resetter->resets);
    }

    private function message(string $outboxId): OutboxMessagePublished
    {
        return new OutboxMessagePublished($outboxId, 'outbox.test', [], null, new DateTimeImmutable('2026-06-20 09:00:00'));
    }

    /**
     * @return OutboxPublisherInterface&object{published: list<OutboxMessagePublished>}
     */
    private function publisher(?string $throwOn = null): OutboxPublisherInterface
    {
        return new class($throwOn) implements OutboxPublisherInterface {
            /** @var list<OutboxMessagePublished> */
            public array $published = [];

            public function __construct(private ?string $throwOn)
            {
            }

            public function publish(OutboxMessagePublished $message): void
            {
                if ($message->outboxId === $this->throwOn) {
                    throw new RuntimeException('publish failed');
                }
                $this->published[] = $message;
            }
        };
    }

    /**
     * @return ManagerResetterInterface&object{resets: int}
     */
    private function resetter(): ManagerResetterInterface
    {
        return new class implements ManagerResetterInterface {
            public int $resets = 0;

            public function reset(): void
            {
                ++$this->resets;
            }
        };
    }

    /**
     * @param list<OutboxMessagePublished> $messages
     *
     * @return OutboxRelayRepositoryInterface&object{publishedIds: list<string>, failedIds: list<string>}
     */
    private function repository(array $messages): OutboxRelayRepositoryInterface
    {
        return new class($messages) implements OutboxRelayRepositoryInterface {
            /** @var list<string> */
            public array $publishedIds = [];

            /** @var list<string> */
            public array $failedIds = [];

            /** @param list<OutboxMessagePublished> $messages */
            public function __construct(private array $messages)
            {
            }

            public function claimUnpublished(int $limit): array
            {
                return $this->messages;
            }

            public function markPublished(string $outboxId): void
            {
                $this->publishedIds[] = $outboxId;
            }

            public function markFailed(string $outboxId): void
            {
                $this->failedIds[] = $outboxId;
            }

            public function deletePublishedOlderThan(DateTimeImmutable $cutoff): int
            {
                return 0;
            }

            public function countPublishedOlderThan(DateTimeImmutable $cutoff): int
            {
                return 0;
            }
        };
    }
}
