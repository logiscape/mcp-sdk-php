<?php

/**
 * Model Context Protocol SDK for PHP
 *
 * (c) 2026 Logiscape LLC <https://logiscape.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @package    logiscape/mcp-sdk-php
 * @author     Josh Abbott <https://joshabbott.com>
 * @copyright  Logiscape LLC
 * @license    MIT License
 * @link       https://github.com/logiscape/mcp-sdk-php
 *
 * Filename: Shared/EmitsDeprecationWarnings.php
 */

declare(strict_types=1);

namespace Mcp\Shared;

use Psr\Log\LoggerInterface;

/**
 * The SEP-2596/SEP-2577 SHOULD-level runtime deprecation warning, shared
 * by the server and client sessions: when a Deprecated feature (per
 * {@see FeatureLifecycle}) is exercised on a session whose negotiated
 * protocol revision deprecates it, emit one PSR-3 warning per feature per
 * session — the SDK's idiomatic "configurable logger" mechanism. Sessions
 * negotiating a revision where the feature is still Active stay silent,
 * and wire behavior is never affected (SEP-2596 defines no wire-level
 * deprecation signal).
 */
trait EmitsDeprecationWarnings
{
    /** @var array<string, true> Features already warned about on this session. */
    private array $deprecationWarningsEmitted = [];

    /** The negotiated protocol revision the deprecation gate compares against. */
    abstract protected function deprecationProtocolVersion(): ?string;

    /** The PSR-3 logger the warning is emitted through. */
    abstract protected function deprecationLogger(): LoggerInterface;

    /**
     * Emit the deprecation warning for a {@see FeatureLifecycle} feature,
     * once per session, if (and only if) the negotiated revision has the
     * feature in the Deprecated state. Safe to call from hot paths.
     */
    public function warnDeprecatedFeature(string $feature): void
    {
        if (isset($this->deprecationWarningsEmitted[$feature])) {
            return;
        }
        if (!FeatureLifecycle::isDeprecatedIn($feature, $this->deprecationProtocolVersion())) {
            return;
        }
        $this->deprecationWarningsEmitted[$feature] = true;
        $this->deprecationLogger()->warning(FeatureLifecycle::warningMessage($feature));
    }
}
