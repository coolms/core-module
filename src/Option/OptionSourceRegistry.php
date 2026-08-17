<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Option;

use CoolMS\Core\Option\Exception\UnknownOptionSourceException;
use CoolMS\Core\Option\Option;
use CoolMS\Core\Option\OptionSourceProviderInterface;
use CoolMS\Core\Option\PublicOptionSourceInterface;
use RuntimeException;

/**
 * Aggregates every {@see OptionSourceProviderInterface} tagged
 * `coolms.option.source` and routes lookups by `key()`.
 *
 * Constructed with a tagged-iterator so any module can drop a new
 * source without touching this class. Duplicate keys throw at the
 * first lookup attempt against the affected key (lazy validation —
 * we don't materialise the index until needed, which keeps the
 * container build fast for the common case where no caller asks for
 * options during boot).
 */
final class OptionSourceRegistry
{
    /** @var array<string, OptionSourceProviderInterface>|null */
    private ?array $byKey = null;

    /**
     * @param iterable<OptionSourceProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param bool $publicOnly when true, a source that does NOT implement
     *                         {@see PublicOptionSourceInterface} is treated
     *                         as if it were not registered at all — the
     *                         public endpoint must never confirm a private
     *                         source exists, so this throws the same
     *                         "unknown source" exception (→ 404, not 403)
     *
     * @throws UnknownOptionSourceException when no provider claims `$key`,
     *                                      or (with `$publicOnly`) the
     *                                      claimant is not public
     *
     * @return list<Option>
     */
    public function provide(string $key, ?string $query = null, ?int $limit = null, bool $publicOnly = false): array
    {
        $provider = $this->find($key);
        if (null === $provider || ($publicOnly && !$provider instanceof PublicOptionSourceInterface)) {
            throw new UnknownOptionSourceException(sprintf('No option source registered for key "%s".', $key));
        }
        $rows = $provider->provide($query, $limit);

        // Defensive clip — providers SHOULD honour the limit themselves
        // for efficiency, but the registry enforces it as a backstop so
        // a misbehaving source can't blow up the response.
        if (null !== $limit && $limit > 0 && count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
        }

        return $rows;
    }

    public function has(string $key): bool
    {
        return null !== $this->find($key);
    }

    /**
     * Whether the source under `$key` opts into anonymous exposure
     * ({@see PublicOptionSourceInterface}) — i.e. it is reachable at the
     * firewall-public `GET /api/v1/public-options/{key}`. False for an
     * unknown key or an admin-only source. Lets callers (e.g. the Form
     * render builder) pick the public route for a public source so a
     * public-site form's select can fetch its options without a token.
     */
    public function isPublic(string $key): bool
    {
        return $this->find($key) instanceof PublicOptionSourceInterface;
    }

    /** @return list<string> Every key registered, sorted alphabetically. */
    public function keys(): array
    {
        $keys = array_keys($this->index());
        sort($keys);

        return $keys;
    }

    private function find(string $key): ?OptionSourceProviderInterface
    {
        return $this->index()[$key] ?? null;
    }

    /** @return array<string, OptionSourceProviderInterface> */
    private function index(): array
    {
        if (null === $this->byKey) {
            $byKey = [];
            foreach ($this->providers as $provider) {
                $key = $provider->key();
                if (isset($byKey[$key])) {
                    throw new RuntimeException(sprintf('Option source key "%s" is registered by both %s and %s. Each key must be unique.', $key, $byKey[$key]::class, $provider::class));
                }
                $byKey[$key] = $provider;
            }
            $this->byKey = $byKey;
        }

        return $this->byKey;
    }
}
