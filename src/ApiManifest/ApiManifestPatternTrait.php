<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\ApiManifest;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Generates a URL pattern string with {param} tokens in place of actual values.
 *
 * Symfony's UrlGenerator percent-encodes placeholder values, so we encode the
 * placeholder first, generate the URL, then replace the encoded placeholder
 * with the raw {param} token.
 *
 * Usage: $this->pattern($urlGenerator, 'route_name', ['id' => '__id__'], '__id__', '{id}')
 */
trait ApiManifestPatternTrait
{
    /**
     * @param array<string, mixed> $params
     */
    private function pattern(
        UrlGeneratorInterface $gen,
        string $route,
        array $params,
        string $placeholder,
        string $token,
    ): string {
        return str_replace(
            urlencode($placeholder),
            $token,
            $gen->generate($route, $params),
        );
    }
}
