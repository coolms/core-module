<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupException;

use function array_values;
use function is_array;
use function is_int;
use function is_string;
use function sprintf;

/**
 * The `manifest.json` at a bundle's root — self-describing metadata that also
 * gates restore. `formatVersion` is the compat contract: a bundle written by a
 * NEWER format than the running code understands is refused rather than
 * mis-imported (the seam that later carries the sync snapshot version).
 *
 * Deliberately records what the bundle EXCLUDES so the security posture travels
 * with the data: no master key, no `COOLMS_SECRET_*`/`VAULT_TOKEN`, no `.env*`,
 * no `var/secrets/`. Any DB ciphertext columns inside are opaque and useless
 * without the out-of-band `COOLMS_SECRET_MASTER_KEY`.
 *
 * @phpstan-type ContributorEntry array{key: string, label: string, tier: string, tables: list<string>, records: int}
 */
final readonly class BackupManifest
{
    /** Bump when the on-disk bundle layout changes incompatibly. */
    public const int FORMAT_VERSION = 1;

    /**
     * @var list<string>
     */
    public const array EXCLUDED = [
        'COOLMS_SECRET_MASTER_KEY',
        'COOLMS_SECRET_* / VAULT_TOKEN (env)',
        '.env* files',
        'var/secrets/ (sealed secret store)',
    ];

    /**
     * @param list<string>           $tiers        included data tiers
     * @param list<ContributorEntry> $contributors
     */
    public function __construct(
        public int $formatVersion,
        public string $createdAt,
        public array $tiers,
        public array $contributors,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $formatVersion = $data['formatVersion'] ?? null;
        $createdAt = $data['createdAt'] ?? null;
        $tiers = $data['tiers'] ?? null;
        $contributors = $data['contributors'] ?? null;

        if (!is_int($formatVersion) || !is_string($createdAt) || !is_array($tiers) || !is_array($contributors)) {
            throw new BackupException('Backup manifest is malformed (missing formatVersion / createdAt / tiers / contributors).');
        }

        /* @var list<string> $tiers */
        /* @var list<ContributorEntry> $contributors */
        return new self($formatVersion, $createdAt, array_values($tiers), array_values($contributors));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'formatVersion' => $this->formatVersion,
            'createdAt' => $this->createdAt,
            'tiers' => $this->tiers,
            'contributors' => $this->contributors,
            'excluded' => self::EXCLUDED,
            'note' => 'Contains NO secrets or master key. Restoring on a remote requires COOLMS_SECRET_MASTER_KEY provisioned out-of-band; any ciphertext columns are opaque without it.',
        ];
    }

    /**
     * Refuse a bundle written by a newer format than this code understands.
     */
    public function assertRestorable(): void
    {
        if ($this->formatVersion > self::FORMAT_VERSION) {
            throw new BackupException(sprintf('Backup bundle format v%d is newer than this platform supports (v%d). Upgrade before restoring.', $this->formatVersion, self::FORMAT_VERSION));
        }
    }
}
