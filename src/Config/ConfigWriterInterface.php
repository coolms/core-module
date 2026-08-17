<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Config;

/**
 * Saves config DATA back, wherever this deployment can put it.
 *
 * The write counterpart of {@see ConfigLoaderInterface}, keyed the same way, so
 * a feature that can read its config can now save it without knowing whether
 * this host keeps config in files or in the database. That ignorance is the
 * point: the same admin screen has to work in dev, where `config/modules` is a
 * git working copy, and in a deployment that ships a read-only image.
 *
 * ⚠️ Callers must not reach past this to a filesystem path. A `file_put_contents`
 * at a call site works perfectly in dev and fails in production, which is the
 * worst shape a bug can have.
 */
interface ConfigWriterInterface
{
    /**
     * What a `$type` or `$id` may contain.
     *
     * ⚠️ Both become PATH SEGMENTS in the file store
     * (`config/modules/generated/{type}/{id}.yaml`), so an id of `../../..` is
     * a directory traversal and an id with a slash silently writes somewhere
     * the loader will never look. Harmless while every caller passed a
     * constant; a real hole the moment one of them is a route parameter, which
     * is what section dashboards made it.
     *
     * Enforced by the STORE rather than by its callers, because a store that
     * depends on being called carefully is one bad call from a hole.
     */
    public const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /**
     * Can this store hold that config on THIS host, right now?
     *
     * Asked before every write, not once at boot: a config that already has a
     * file is judged by that file's permissions, a new one by the directory it
     * would be created in, and neither is knowable in the abstract.
     */
    public function canWrite(string $type, string $id): bool;

    /**
     * Save one config, replacing whatever was there.
     *
     * @param string               $type `dashboard`, `datagrid`, … — the `type:` key
     * @param string               $id   the `id:` key within that type
     * @param array<string, mixed> $data the whole config array
     *
     * @return string where it landed: an absolute path, or `db://{type}/{id}`.
     *                Worth surfacing to whoever asked for the save — "saved to
     *                the database because config/ is read-only" is the answer
     *                to a question they will otherwise ask twice
     */
    public function write(string $type, string $id, array $data): string;

    /**
     * Drop a saved config so the platform's own default applies again.
     *
     * @return bool true when something was removed
     */
    public function delete(string $type, string $id): bool;
}
