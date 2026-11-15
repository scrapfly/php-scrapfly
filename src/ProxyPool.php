<?php

declare(strict_types=1);

namespace Scrapfly;

/**
 * Proxy pools, sent as `proxy_pool`: `PublicDatacenterPool` (the default),
 * `PublicResidentialPool` or `PublicTorPool`. The anti-bot bypass is the
 * `unblocker` argument of the config.
 *
 * `Basic`, `Unblocker`, `UnblockerUltra` and `Auto` name pools the API does not
 * accept yet: a request naming one fails with `ERR::PROXY::POOL_NOT_FOUND`.
 *
 * A pool name not listed here, such as a pool of your own, is passed as a string.
 */
enum ProxyPool: string
{
    /** Not accepted by the API yet (`ERR::PROXY::POOL_NOT_FOUND`). */
    case Basic = 'basic';

    /** Not accepted by the API yet (`ERR::PROXY::POOL_NOT_FOUND`). */
    case Unblocker = 'unblocker';

    /** Not accepted by the API yet (`ERR::PROXY::POOL_NOT_FOUND`). */
    case UnblockerUltra = 'unblocker_ultra';

    /** Not accepted by the API yet (`ERR::PROXY::POOL_NOT_FOUND`). */
    case Auto = 'auto';

    /** The Tor network, the only pool that reaches .onion hidden services. */
    case PublicTorPool = 'public_tor_pool';

    /** Datacenter proxies, the default pool. */
    case PublicDatacenterPool = 'public_datacenter_pool';

    /** Residential proxies. */
    case PublicResidentialPool = 'public_residential_pool';

    /**
     * Whether this is `PublicDatacenterPool` or `PublicResidentialPool`: pool names older than the four the API does
     * not accept yet, and the ones it accepts today. `PublicTorPool` is not one.
     */
    public function isLegacy(): bool
    {
        return match ($this) {
            self::PublicDatacenterPool, self::PublicResidentialPool => true,
            default => false,
        };
    }
}
