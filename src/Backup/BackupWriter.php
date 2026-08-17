<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Backup;

use CoolMS\Core\Backup\BackupException;
use CoolMS\Core\Backup\BackupWriterInterface;
use CoolMS\Core\Backup\TableBackupPortInterface;
use Symfony\Component\Filesystem\Filesystem;
use Throwable;

use function bin2hex;
use function dirname;
use function fclose;
use function fopen;
use function fwrite;
use function json_encode;
use function preg_match;
use function random_bytes;
use function sprintf;
use function str_replace;
use function strlen;
use function substr;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * The write handle a {@see \CoolMS\Core\Backup\BackupContributorInterface}
 * receives during `export`. Scoped to ONE contributor: everything it writes
 * lands under `<bundle>/data/<backupKey>/`. The common case is a few
 * `dumpTable('coolms_...')` calls; a contributor with extra state can
 * `putJson(...)` (and, in a later slice, `putBlob(...)` for VFS binaries).
 *
 * Created per contributor by {@see BackupRunner}; not a DI service.
 */
final class BackupWriter implements BackupWriterInterface
{
    private const int JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /** Content hashes are lowercase-hex sha256; validate before using one as a path segment. */
    private const string HASH_PATTERN = '/^[0-9a-f]{4,}$/';

    /** Blob namespaces come from contributor code (e.g. 'blobs', 'public-blobs'); validate anyway. */
    private const string NAMESPACE_PATTERN = '/^[a-z][a-z0-9-]*$/';

    private readonly Filesystem $filesystem;

    /** @var list<string> */
    private array $tablesWritten = [];

    private int $recordsWritten = 0;
    private int $blobsWritten = 0;

    public function __construct(
        private readonly string $bundleDir,
        private readonly string $backupKey,
        private readonly TableBackupPortInterface $tables,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Dump a whole table into `data/<key>/<table>.json`; returns rows written.
     *
     * Streams: rows are pulled one at a time off {@see TableBackupPortInterface::streamTable()}
     * and appended to the payload as they arrive, so peak memory is one row — not the
     * whole table, and not the whole table's JSON on top of it. A full-install backup
     * of `coolms_vfs_nodes` used to need both at once.
     */
    public function dumpTable(string $table): int
    {
        return $this->dumpRows($table, $this->tables->streamTable($table));
    }

    /**
     * Read a live table's rows WITHOUT writing them — the export-side mirror of
     * {@see BackupReader::readRows()}. For a contributor that must filter or
     * transform rows before writing them (e.g. dropping module-owned definition
     * versions the installer rebuilds), then hand the survivors to
     * {@see dumpRows()}. Generated columns are already stripped by the engine.
     *
     * Materialises the whole table. Use {@see streamTable()} unless the contributor
     * genuinely needs random access or more than one pass over the rows.
     *
     * @return list<array<string, mixed>>
     */
    public function readTable(string $table): array
    {
        return $this->tables->dumpTable($table);
    }

    /**
     * A live table's rows one at a time, without writing them — the bounded-memory
     * {@see readTable()}. A contributor that filters can wrap this in a generator and
     * pass it straight to {@see dumpRows()}, so neither the source rows nor the kept
     * ones are ever all in memory. Re-reads the table on each call (it is a cursor,
     * not a buffer), so a two-pass filter costs two queries and constant memory —
     * deliberately the cheap side of that trade.
     *
     * @return iterable<array<string, mixed>>
     */
    public function streamTable(string $table): iterable
    {
        return $this->tables->streamTable($table);
    }

    /**
     * Write a PRE-BUILT/filtered row list as `$table`'s canonical payload (the
     * same `data/<key>/<table>.json` a plain {@see dumpTable()} would produce, and
     * that {@see BackupReader::readRows()}/`loadTable()` read back). The symmetric
     * partner of {@see readTable()}; tracks the table + count so the manifest
     * stays accurate.
     *
     * Takes an `iterable`, not an `array`, so a contributor that filters can hand
     * over a GENERATOR and never hold the kept rows in memory either (see
     * the definition-ladder backup contributor's `export()`).
     * Rows are consumed exactly once — the count comes from the walk, not `count()`.
     *
     * @param iterable<array<int|string, mixed>> $rows
     */
    public function dumpRows(string $table, iterable $rows): int
    {
        $written = $this->streamJsonList($this->payloadPath($table), $rows);
        $this->tablesWritten[] = $table;
        $this->recordsWritten += $written;

        return $written;
    }

    /**
     * Write an arbitrary JSON payload under this contributor's dir (name without
     * extension, e.g. `putJson('meta', [...])` → `data/<key>/meta.json`).
     *
     * For SMALL, non-row payloads — a contributor's own counters/metadata, whose size
     * does not scale with the install (e.g. VFS's `_blobs_meta`). Table payloads go
     * through {@see dumpRows()}, which streams; this deliberately still encodes in one
     * shot because a fixed-size map does not need the machinery.
     *
     * @param array<mixed> $data
     */
    public function putJson(string $name, array $data): void
    {
        $this->filesystem->dumpFile(
            $this->payloadPath($name),
            json_encode($data, self::JSON_FLAGS),
        );
    }

    /**
     * Store a binary blob (raw bytes, NOT JSON) under this contributor's
     * `<namespace>/` dir, sha256-sharded (`<namespace>/aa/bb/<hash>`). For
     * contributors (e.g. VFS content) whose rows reference file bytes that live
     * outside the DB. `$namespace` separates blob classes that restore
     * differently — e.g. VFS uses `blobs` for the content-addressed secure store
     * and `public-blobs` for bytes materialised in `public/`.
     */
    public function putBlob(string $hash, string $bytes, string $namespace = 'blobs'): void
    {
        $this->filesystem->dumpFile($this->blobPath($hash, $namespace), $bytes);
        ++$this->blobsWritten;
    }

    /** @return list<string> */
    public function tablesWritten(): array
    {
        return $this->tablesWritten;
    }

    public function recordsWritten(): int
    {
        return $this->recordsWritten;
    }

    public function blobsWritten(): int
    {
        return $this->blobsWritten;
    }

    /**
     * Write `$rows` to `$path` as a pretty-printed JSON array, encoding and flushing
     * ONE ROW AT A TIME. Returns rows written.
     *
     * Byte-identical to the `json_encode($rows, JSON_PRETTY_PRINT)` it replaces — an
     * existing bundle re-exported through here does not change — but it never holds
     * the encoded document in memory. That whole-document string was an unbounded
     * allocation: it grows with the table, and PHP doubles the string buffer as it
     * grows, so the transient peak is ~2x the payload ON TOP OF the rows themselves.
     * (A full-suite `bin/phpunit` died exactly here, on the buffer doubling.)
     *
     * **Still atomic.** Symfony's `dumpFile()` (used everywhere else here) writes via a
     * temp file + rename so a reader never sees a half-written payload; streaming
     * straight at the destination would have given that up and left a truncated,
     * silently-corrupt `.json` in the bundle if the export died mid-table. So this
     * does the same dance by hand: stream into a sibling `.part`, rename on success,
     * and remove it on any failure.
     *
     * @param iterable<array<int|string, mixed>> $rows
     */
    private function streamJsonList(string $path, iterable $rows): int
    {
        $this->filesystem->mkdir(dirname($path));

        $partial = sprintf('%s.%s.part', $path, bin2hex(random_bytes(6)));
        $handle = fopen($partial, 'wb');
        if (false === $handle) {
            throw new BackupException(sprintf('Cannot open "%s" to write a backup payload.', $partial));
        }

        $written = 0;
        try {
            $this->write($handle, $partial, '[');
            foreach ($rows as $row) {
                // JSON_PRETTY_PRINT indents a list's elements by 4 spaces; reproduce
                // that on every line of the row so the output matches a whole-array
                // encode exactly.
                $encoded = (string) json_encode($row, self::JSON_FLAGS);
                $this->write($handle, $partial, (0 === $written ? "\n" : ",\n") . '    ' . str_replace("\n", "\n    ", $encoded));
                ++$written;
            }
            // An empty list is `[]` on one line, again matching json_encode.
            $this->write($handle, $partial, 0 === $written ? ']' : "\n]");
        } catch (Throwable $e) {
            fclose($handle);
            $this->filesystem->remove($partial);

            throw $e;
        }

        fclose($handle);
        $this->filesystem->rename($partial, $path, true);

        return $written;
    }

    /**
     * `fwrite` reports a short write (disk full, quota) by returning fewer bytes than
     * given — silently, so an unchecked write yields a truncated payload that still
     * looks like a successful backup. Fail loudly instead.
     *
     * @param resource $handle
     */
    private function write($handle, string $path, string $chunk): void
    {
        if (fwrite($handle, $chunk) !== strlen($chunk)) {
            throw new BackupException(sprintf('Short write while streaming a backup payload to "%s" (out of disk space?).', $path));
        }
    }

    private function payloadPath(string $name): string
    {
        return sprintf('%s/data/%s/%s.json', $this->bundleDir, $this->backupKey, $name);
    }

    private function blobPath(string $hash, string $namespace): string
    {
        if (1 !== preg_match(self::HASH_PATTERN, $hash)) {
            throw new BackupException(sprintf('Refusing to write blob with unsafe hash "%s".', $hash));
        }
        if (1 !== preg_match(self::NAMESPACE_PATTERN, $namespace)) {
            throw new BackupException(sprintf('Refusing to write blob under unsafe namespace "%s".', $namespace));
        }

        return sprintf('%s/data/%s/%s/%s/%s/%s', $this->bundleDir, $this->backupKey, $namespace, substr($hash, 0, 2), substr($hash, 2, 2), $hash);
    }
}
