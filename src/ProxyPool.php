<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * Proxy pools, sent as `proxy_pool`. Each one sets both the proxy network and the
 * Unblocker, so a request with a pool needs neither `unblocker` nor `asp`.
 *
 * A pool name not listed here, such as a pool of your own, is passed as a string.
 */
enum ProxyPool: string
{
    /** Datacenter proxies, the Unblocker off. */
    case Basic = 'basic';

    /** Residential proxies and the Unblocker. */
    case Unblocker = 'unblocker';

    /**
     * Residential proxies and the Unblocker. The only pool accepted by the targets and
     * anti-bot protections that have a price of their own; it costs the same as
     * `Unblocker` elsewhere.
     */
    case UnblockerUltra = 'unblocker_ultra';

    /**
     * The API picks the pool before the request, from the target's profile: the
     * Unblocker on datacenter proxies, or the pool the target needs. Priced as the
     * pool it runs on.
     */
    case Auto = 'auto';

    /** The Tor network, the only pool that reaches .onion hidden services. */
    case PublicTorPool = 'public_tor_pool';

    /** @deprecated Written before the proxy pools: see https://scrapfly.io/new-pricing#legacy-spellings */
    case PublicDatacenterPool = 'public_datacenter_pool';

    /** @deprecated Written before the proxy pools: see https://scrapfly.io/new-pricing#legacy-spellings */
    case PublicResidentialPool = 'public_residential_pool';

    /** Whether this name predates the proxy pools. The API still accepts it. */
    public function isLegacy(): bool
    {
        return match ($this) {
            self::PublicDatacenterPool, self::PublicResidentialPool => true,
            default => false,
        };
    }
}
