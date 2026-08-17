<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Outbox;

use CoolMS\Core\Outbox\OutboxPublisherInterface;
use CoolMS\Core\Outbox\OutboxRelayRepositoryInterface;
use CoolMS\Core\Persistence\ManagerResetterInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The transactional-outbox relay: claims a batch of committed
 * `coolms_outbox` rows, publishes each through {@see OutboxPublisherInterface},
 * and stamps it delivered; a publish failure leaves the row undelivered (attempt
 * bumped) for the next run, so delivery is at-least-once.
 *
 * **MUST run inside a transaction** — the claim uses `FOR UPDATE SKIP LOCKED`, so
 * the lock must be held until the marks commit. The {@see \CoolMS\CoreBundle\Console\RelayOutboxCommand}
 * wraps this in a CONNECTION-level transaction (not an EntityManager one): a
 * consumer whose work throws closes the EM (the ORM does this deliberately, as
 * a consistency safeguard), so an EM-tied batch transaction would abort on its
 * final flush — a
 * connection-level transaction is immune (the claim / mark bookkeeping is raw DBAL).
 *
 * **Poison-message isolation:** after a per-row failure the relay resets the EM via
 * {@see ManagerResetterInterface} so the closed manager doesn't cascade
 * `EntityManagerClosed` into every later row of the batch — broker-like per-message
 * isolation, in-process.
 */
final readonly class OutboxRelay
{
    public function __construct(
        private OutboxRelayRepositoryInterface $repository,
        private OutboxPublisherInterface $publisher,
        private ManagerResetterInterface $resetter,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Publish up to `$batchSize` committed messages.
     *
     * @return int the number successfully published
     */
    public function relay(int $batchSize): int
    {
        $messages = $this->repository->claimUnpublished($batchSize);

        $published = 0;
        foreach ($messages as $message) {
            try {
                $this->publisher->publish($message);
                $this->repository->markPublished($message->outboxId);
                ++$published;
            } catch (Throwable $e) {
                $this->repository->markFailed($message->outboxId);
                $this->logger->error('Outbox relay failed to publish a message; it will be retried.', [
                    'outboxId' => $message->outboxId,
                    'type' => $message->type,
                    'exception' => $e,
                ]);
                // A failing consumer may have closed the EntityManager; reset it so
                // the next row in the batch starts with a clean, open EM rather than
                // cascading EntityManagerClosed across the rest of the batch.
                $this->resetter->reset();
            }
        }

        return $published;
    }
}
