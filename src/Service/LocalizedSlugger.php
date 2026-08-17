<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Service;

use CoolMS\Core\Config\PlatformDefaults;
use CoolMS\Core\Service\SlugGeneratorInterface;
use CoolMS\Core\Service\TransliterationRuleSetInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The platform {@see SlugGeneratorInterface}. Two stages:
 *
 *  1. If a NATIONAL rule set is registered for the (base) locale, its map
 *     is applied to the lowercased title first — `Größe` → `groesse`,
 *     `Юлия` → `yuliya` — so national letters reach the form a reader of
 *     that language expects rather than ICU's lossy default (`grosse`,
 *     `iuliia`).
 *  2. Whatever remains is handed to Symfony's {@see AsciiSlugger}: generic
 *     Any-Latin→ASCII fold for anything the rule set didn't cover, then
 *     collapse-to-`-`, edge-trim, lowercase.
 *
 * Doing the national map BEFORE the fold is the whole point — after the
 * fold the source graphemes are gone. The engine lives in Core; the rule
 * sets (ru, de, …) are contributed by I18n via the tagged
 * {@see TransliterationRuleSetInterface} and collected here with
 * `#[AutowireIterator]`, so adding a locale is a one-class change in I18n
 * with no edit here.
 *
 * `#[Autoconfigure(public: true)]` keeps the service (and its rule-set
 * iterator) resolvable before the first consumer wires the port — the
 * outbox-appender idiom; the `SlugGeneratorInterface` alias in
 * `Core\…\Extension` stays private and is pruned-until-consumed.
 */
#[Autoconfigure(public: true)]
final class LocalizedSlugger implements SlugGeneratorInterface
{
    private readonly AsciiSlugger $ascii;

    /**
     * Base-locale → merged char-map, built once from the rule sets on
     * first use (the iterator is walked lazily; an empty locale means "no
     * national rules, generic fold only").
     *
     * @var array<string, array<string, string>>|null
     */
    private ?array $mapsByLocale = null;

    /** @param iterable<TransliterationRuleSetInterface> $ruleSets */
    public function __construct(
        #[AutowireIterator(TransliterationRuleSetInterface::TAG)]
        private readonly iterable $ruleSets,
        private readonly PlatformDefaults $defaults,
    ) {
        $this->ascii = new AsciiSlugger();
    }

    public function slugify(string $text, ?string $locale = null): string
    {
        $map = $this->mapsByLocale()[$this->baseLocale($locale ?? $this->defaults->locale)] ?? [];
        if ([] !== $map) {
            // Lowercase FIRST so a rule set only needs lowercase keys, then
            // apply the national map before the generic fold below.
            $text = strtr(mb_strtolower($text, 'UTF-8'), $map);
        }

        return $this->ascii->slug($text, '-')->lower()->toString();
    }

    /** @return array<string, array<string, string>> */
    private function mapsByLocale(): array
    {
        if (null !== $this->mapsByLocale) {
            return $this->mapsByLocale;
        }

        $maps = [];
        foreach ($this->ruleSets as $ruleSet) {
            $base = $this->baseLocale($ruleSet->locale());
            // `+` keeps the first-registered rule set's mapping per key.
            $maps[$base] = ($maps[$base] ?? []) + $ruleSet->map();
        }

        return $this->mapsByLocale = $maps;
    }

    /** `de_DE` / `de-CH` → `de`; `ru` → `ru`; '' → ''. */
    private function baseLocale(string $locale): string
    {
        $locale = strtolower(trim($locale));

        return substr($locale, 0, strcspn($locale, '-_'));
    }
}
