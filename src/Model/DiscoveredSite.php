<?php

declare(strict_types=1);

namespace Survos\SiteDiscoveryBundle\Model;

/**
 * A single discovered tenant site.
 */
final readonly class DiscoveredSite
{
    public function __construct(
        /** Tenant slug extracted from the SURT key, e.g. "fauquierhistory" */
        public string $slug,

        /** Fully-qualified hostname, e.g. "fauquierhistory.pastperfectonline.com" */
        public string $host,

        /** Absolute base URL, e.g. "https://fauquierhistory.pastperfectonline.com" */
        public string $baseUrl,

        /** Which backend found this site: "internet_archive_cdx" */
        public string $discoveredVia,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'slug'          => $this->slug,
            'host'          => $this->host,
            'base_url'      => $this->baseUrl,
            'discovered_via' => $this->discoveredVia,
            'validated'     => false,
            'validated_at'  => null,
        ];
    }
}
