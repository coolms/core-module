<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Option;

use CoolMS\Core\Option\Exception\UnknownOptionSourceException;
use CoolMS\Core\Option\Option;
use CoolMS\Core\Option\OptionSourceProviderInterface;
use CoolMS\Core\Option\PublicOptionSourceInterface;
use CoolMS\CoreModule\Option\OptionSourceRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * Unit coverage for the {@see OptionSourceRegistry} routing + the
 * `publicOnly` access gate that backs the firewall-public
 * `GET /api/v1/public-options/{source}` endpoint.
 */
final class OptionSourceRegistryTest extends TestCase
{
    #[Test]
    public function itRoutesToTheSourceClaimingTheKey(): void
    {
        $registry = new OptionSourceRegistry([
            self::source('admin.only', false),
            self::source('public.list', true),
        ]);

        self::assertSame(['admin.only-v'], array_column($registry->provide('admin.only'), 'value'));
        self::assertSame(['public.list-v'], array_column($registry->provide('public.list'), 'value'));
        self::assertTrue($registry->has('admin.only'));
        self::assertFalse($registry->has('missing.key'));
    }

    #[Test]
    public function publicOnlyServesAPublicSource(): void
    {
        $registry = new OptionSourceRegistry([self::source('public.list', true)]);

        $rows = $registry->provide('public.list', publicOnly: true);

        self::assertSame(['public.list-v'], array_column($rows, 'value'));
    }

    #[Test]
    public function publicOnlyHidesANonPublicSourceAsIfUnknown(): void
    {
        // A registered-but-private source must 404 (not 403) on the public
        // surface, so the endpoint never confirms it exists. The registry
        // signals that by throwing the same exception as a typo'd key.
        $registry = new OptionSourceRegistry([self::source('admin.only', false)]);

        // Still reachable without the gate (the authenticated endpoint).
        self::assertSame(['admin.only-v'], array_column($registry->provide('admin.only'), 'value'));

        $this->expectException(UnknownOptionSourceException::class);
        $registry->provide('admin.only', publicOnly: true);
    }

    #[Test]
    public function publicOnlyThrowsForAnUnknownKey(): void
    {
        $registry = new OptionSourceRegistry([self::source('public.list', true)]);

        $this->expectException(UnknownOptionSourceException::class);
        $registry->provide('does.not.exist', publicOnly: true);
    }

    #[Test]
    public function isPublicReflectsTheMarkerInterface(): void
    {
        $registry = new OptionSourceRegistry([
            self::source('public.list', true),
            self::source('admin.only', false),
        ]);

        self::assertTrue($registry->isPublic('public.list'));
        self::assertFalse($registry->isPublic('admin.only'), 'a plain source is admin-only');
        self::assertFalse($registry->isPublic('does.not.exist'), 'an unknown key is not public');
    }

    /**
     * A one-row fake source under `$key`. When `$public` it implements the
     * {@see PublicOptionSourceInterface} marker; otherwise the plain
     * admin-only {@see OptionSourceProviderInterface}.
     */
    private static function source(string $key, bool $public): OptionSourceProviderInterface
    {
        $row = new Option(value: $key . '-v', label: $key);

        if ($public) {
            return new class($key, $row) implements PublicOptionSourceInterface {
                public function __construct(private readonly string $key, private readonly Option $row)
                {
                }

                public function key(): string
                {
                    return $this->key;
                }

                public function provide(?string $query = null, ?int $limit = null): array
                {
                    return [$this->row];
                }
            };
        }

        return new class($key, $row) implements OptionSourceProviderInterface {
            public function __construct(private readonly string $key, private readonly Option $row)
            {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function provide(?string $query = null, ?int $limit = null): array
            {
                return [$this->row];
            }
        };
    }
}
