<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Json;

use CoolMS\Core\Exception\JsoncDecodeException;

/**
 * Platform-wide JSONC (JSON-with-comments) decoder seam.
 *
 * JSONC tolerates `//` line comments and `/* ... *\/` block comments
 * in JSON source. Standard `json_decode` rejects both -- so any
 * human-edited config file (BPMN-Lite bodies, fixtures, future
 * Workflow / DTMPL / FormConfig variants that want inline
 * annotation) needs a pre-pass to strip them. This interface
 * is that pre-pass, decoupled from any specific consumer.
 *
 * **Strings are preserved verbatim.** A `//` or `/*` inside a string
 * literal stays as data -- the decoder tracks string/escape state
 * during the strip pass. Line numbers are preserved (comments are
 * replaced with whitespace of the same width) so downstream error
 * reporting stays accurate.
 *
 * **Root must be an array.** JSONC sources in this codebase are
 * always config files keyed at the top level; decoding to a scalar
 * is treated as a malformed root and raises {@see JsoncDecodeException}.
 *
 * **Comments do not round-trip.** This interface only exposes a
 * decoder direction -- there is no `encode()` method because comments
 * are application metadata that don't survive the AST. If you need
 * to write JSON, use plain `json_encode` directly.
 *
 * **Not a Symfony Serializer encoder.** The Symfony `EncoderInterface`
 * / `DecoderInterface` indirection pays off when you have
 * format-dispatch at runtime (one call surface, multiple formats
 * decided by a string). Every consumer of this interface knows it's
 * JSONC at compile time, so we expose a typed seam directly.
 * Promotion to a Serializer encoder is local if/when that changes.
 */
interface JsoncDecoderInterface
{
    /**
     * Strip JSONC comments and decode to a PHP value.
     *
     * @param string  $source     the raw on-disk bytes
     * @param ?string $sourcePath optional file path, threaded into the
     *                            exception on failure so error messages
     *                            can name the offending file
     *
     * @throws JsoncDecodeException on malformed JSON after stripping or
     *                              non-array root
     *
     * @return array<mixed> always an array; root scalars raise
     */
    public function decode(string $source, ?string $sourcePath = null): array;
}
