<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Template;

/**
 * Neutral SPI for one piece of context enrichment, plugged into a
 * {@see ContextBuilderInterface}. Consumers (Web SSR, Document
 * generation, future Email/Notification templates) own their own
 * tag namespace so contributor sets don't cross-pollute — each
 * builder collects only the tag it's interested in.
 *
 * Implementations:
 *  - MUST be idempotent and stateless across invocations.
 *  - MUST return JSON-serializable arrays (no entities/closures/
 *    resources). The builder's output is persisted on the
 *    DocumentInstance row in the Document use case, and travels
 *    through Messenger payloads in others.
 *  - SHOULD return `[]` when there is nothing to contribute for the
 *    current base context.
 *
 * The interface itself has no DI tag — registration is the
 * concrete builder's responsibility. Web tags this as
 * `coolms.template_context_contributor`, Document as
 * `coolms.document.context_contributor`, etc.
 */
interface ContextContributorInterface
{
    /**
     * Contribute fields to the rendering context. The builder
     * deep-merges the return value into the accumulated context;
     * later contributors see earlier contributors' contributions
     * via the `$context` argument.
     *
     * @param array<string, mixed> $context context assembled so far
     *
     * @return array<string, mixed> key/value pairs to merge in
     */
    public function contribute(array $context): array;
}
