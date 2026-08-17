<?php

declare(strict_types=1);

namespace CoolMS\CoreModule\Channel;

use CoolMS\Core\Channel\ChannelConfigField;
use CoolMS\Core\Channel\ConfigurableChannelInterface;
use CoolMS\Core\Channel\OutboundChannelInterface;
use CoolMS\Core\Secret\SecretStoreInterface;

use function is_string;
use function trim;

/**
 * Turns a channel's STORED config into its RUNTIME config by resolving every
 * declared {@see ChannelConfigField::TYPE_SECRET_REF} field through the secret
 * store.
 *
 * ## Why this is one shared step and not each channel's own job
 *
 * A channel is a transport. Making each one inject {@see SecretStoreInterface}
 * and remember which of its keys are references would mean the safety of the
 * whole class of vendor channels depends on every author getting it right — and
 * a channel that forgot would work perfectly against a raw token in `extras`,
 * which is exactly the failure mode this exists to prevent. Resolving once, at
 * the single point every channel funnels through, means a channel keeps reading
 * `$config['botToken']` as a plain value and cannot opt out.
 *
 * ## Where the value is allowed to exist
 *
 * Only in the argument list of `deliver()`. It is deliberately NOT resolved in
 * the distribution trigger: workflow variables are persisted to
 * `coolms_workflow_process_instances.variables`, so a token resolved there would
 * be written to the database in clear and shown in the cockpit. The reference
 * travels; the credential does not.
 *
 * A reference naming a secret that does not exist FAILS LOUD
 * ({@see \CoolMS\Core\Secret\SecretNotFoundException}, which carries the
 * key). That is the right posture even though a missing plain field only
 * soft-skips: an unset plain field means "this section does not use that
 * channel", while a dangling secret name means someone configured a credential
 * that is not there — silence would look identical to success.
 */
final readonly class ChannelConfigResolver
{
    public function __construct(
        private SecretStoreInterface $secrets,
    ) {
    }

    /**
     * @param array<string, mixed> $config as stored on the section
     *
     * @return array<string, mixed> with secret references replaced by their values
     */
    public function resolve(OutboundChannelInterface $channel, array $config): array
    {
        if (!$channel instanceof ConfigurableChannelInterface) {
            return $config;
        }

        foreach ($channel->configFields() as $field) {
            if (!$field->isSecretRef()) {
                continue;
            }

            $reference = $config[$field->key] ?? null;
            if (!is_string($reference) || '' === trim($reference)) {
                // Absent is absent — the channel's own soft-skip decides what
                // that means. Only a NAMED-but-missing secret is an error.
                continue;
            }

            $config[$field->key] = $this->secrets->getRequired(trim($reference));
        }

        return $config;
    }
}
