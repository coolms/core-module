<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Outbox;

use CoolMS\Core\Inbox\ProcessedMessageStoreInterface;
use CoolMS\Core\Transaction\TransactionRunnerInterface;

/**
 * Runs a consumer's work EXACTLY ONCE per `(consumer, messageId)` — the F7
 * consumer-idempotency helper over {@see ProcessedMessageStoreInterface},
 * the reusable seam an at-least-once consumer drops onto.
 *
 * Wraps the dedupe-record + the work in ONE transaction (a SAVEPOINT when nested
 * inside the relay's batch tx): `firstSeen` reserves the id, the work runs, and
 * they commit together — so a work failure rolls BOTH back (the message is
 * retried, never marked processed-but-skipped). A replay skips the work.
 */
final readonly class IdempotentRunner
{
    public function __construct(
        private ProcessedMessageStoreInterface $store,
        private TransactionRunnerInterface $transactionRunner,
    ) {
    }

    /**
     * @param callable(): void $work
     *
     * @return bool true if the work ran (first delivery), false if it was skipped (replay)
     */
    public function runOnce(string $consumer, string $messageId, callable $work): bool
    {
        return $this->transactionRunner->run(function () use ($consumer, $messageId, $work): bool {
            if (!$this->store->firstSeen($consumer, $messageId)) {
                return false;
            }

            $work();

            return true;
        });
    }
}
