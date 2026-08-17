<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Template;

/**
 * Neutral SPI for assembling a template-rendering context. Modules
 * implement this with their own contributor sets and base context
 * shapes (Web's SSR builder has its own pre-existing concrete
 * implementation living in `Web\Application\Builder\TemplateContextBuilder`
 * — that builder doesn't yet implement this interface for backward
 * compatibility, but its semantics match this contract).
 *
 * Phase 1 of the cross-module context-builder refactor introduces
 * this interface plus a Document-side implementation; Web's
 * builder migrates to this contract in a follow-up if/when its
 * `RenderContext` argument shape needs broadening.
 */
interface ContextBuilderInterface
{
    /**
     * Build the rendering context. Caller-supplied `$base` is the
     * starting point; registered contributors run in iteration
     * order and their returns are merged in. The output is suitable
     * for handing to DTMPL's runtime `Context`.
     *
     * @param array<string, mixed> $base
     *
     * @return array<string, mixed>
     */
    public function build(array $base): array;
}
