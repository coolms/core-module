<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Tests\Config;

use CoolMS\CoreModule\Config\ChainedConfigWriter;
use CoolMS\CoreModule\Config\ConfigWriterInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Where a config write lands, and what happens to the copy it did not.
 *
 * The interesting tests here are not "it picks the file" — they are the two
 * about leftovers. Two stores mean two chances to leave one behind, the reader
 * lets the database win, and neither half of that failure says anything: the
 * file on disk is right, the screen is wrong, and a `git diff` shows no reason.
 */
#[CoversClass(ChainedConfigWriter::class)]
final class ChainedConfigWriterTest extends TestCase
{
    #[Test]
    public function theFirstStoreThatAcceptsItGetsTheWrite(): void
    {
        $file = new RecordingStore(canWrite: true);
        $db = new RecordingStore(canWrite: true);

        $where = new ChainedConfigWriter([$file, $db])->write('dashboard', 'main', ['widgets' => []]);

        self::assertSame(['dashboard/main'], $file->written);
        self::assertSame([], $db->written);
        self::assertSame('stored:dashboard/main', $where);
    }

    /**
     * The read-only deployment. Nothing about the caller changes — which is the
     * entire point of the seam.
     */
    #[Test]
    public function aStoreThatCannotWriteIsSkipped(): void
    {
        $file = new RecordingStore(canWrite: false);
        $db = new RecordingStore(canWrite: true);

        new ChainedConfigWriter([$file, $db])->write('dashboard', 'main', []);

        self::assertSame([], $file->written);
        self::assertSame(['dashboard/main'], $db->written);
    }

    /**
     * ⚠️ THE test. A row written while `config/` was read-only outranks the file
     * written after it, so a host that becomes writable again would keep serving
     * the stale row — a dashboard no file on disk explains.
     */
    #[Test]
    public function writingToOneStoreClearsTheConfigFromEveryOther(): void
    {
        $file = new RecordingStore(canWrite: true);
        $db = new RecordingStore(canWrite: true);

        new ChainedConfigWriter([$file, $db])->write('dashboard', 'main', []);

        self::assertSame(['dashboard/main'], $db->deleted);
        // Not from the one that took the write, obviously — that would delete
        // what was just saved.
        self::assertSame([], $file->deleted);
    }

    /** Reset-to-default. Anything left behind silently wins the next read. */
    #[Test]
    public function deleteClearsEveryStore(): void
    {
        $file = new RecordingStore(canWrite: true, deletes: true);
        $db = new RecordingStore(canWrite: true, deletes: true);

        self::assertTrue(new ChainedConfigWriter([$file, $db])->delete('dashboard', 'main'));

        self::assertSame(['dashboard/main'], $file->deleted);
        self::assertSame(['dashboard/main'], $db->deleted);
    }

    /**
     * The short-circuit guard. `$store->delete(...) || $removed` calls every
     * store; `$removed || $store->delete(...)` stops at the first success and
     * leaves the rest — a one-character bug that passes any test asserting only
     * on the return value.
     */
    #[Test]
    public function deleteVisitsEveryStoreEvenAfterOneSucceeds(): void
    {
        $first = new RecordingStore(canWrite: true, deletes: true);
        $second = new RecordingStore(canWrite: true, deletes: false);

        self::assertTrue(new ChainedConfigWriter([$first, $second])->delete('dashboard', 'main'));

        self::assertSame(['dashboard/main'], $second->deleted);
    }

    #[Test]
    public function deletingSomethingNobodyStoredSaysSo(): void
    {
        $chain = new ChainedConfigWriter([
            new RecordingStore(canWrite: true, deletes: false),
            new RecordingStore(canWrite: true, deletes: false),
        ]);

        self::assertFalse($chain->delete('dashboard', 'main'));
    }

    /** "No store took it" must never be mistaken for a successful save. */
    #[Test]
    public function aWriteNoStoreCanTakeThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/dashboard\/main/');

        new ChainedConfigWriter([new RecordingStore(canWrite: false)])->write('dashboard', 'main', []);
    }

    /**
     * ⚠️ An unusable KEY is the CALLER's mistake and must not answer like a
     * host problem: a section named `../../etc/passwd` is a 422, while "every
     * store refused a perfectly good key" is a 500. Both reach the same line as
     * "nobody took it", so the difference has to be drawn deliberately.
     */
    #[Test]
    public function aWriteWithAnUnusableKeyIsAnArgumentErrorRatherThanAHostError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a valid config key/');

        new ChainedConfigWriter([new RecordingStore(canWrite: false)])
            ->write('dashboard', '../../../../tmp/pwned', []);
    }
}

/** A store that remembers what it was asked to do. */
final class RecordingStore implements ConfigWriterInterface
{
    /** @var list<string> */
    public array $written = [];

    /** @var list<string> */
    public array $deleted = [];

    public function __construct(
        private readonly bool $canWrite,
        private readonly bool $deletes = false,
    ) {
    }

    public function canWrite(string $type, string $id): bool
    {
        return $this->canWrite;
    }

    public function write(string $type, string $id, array $data): string
    {
        $this->written[] = $type . '/' . $id;

        return 'stored:' . $type . '/' . $id;
    }

    public function delete(string $type, string $id): bool
    {
        $this->deleted[] = $type . '/' . $id;

        return $this->deletes;
    }
}
