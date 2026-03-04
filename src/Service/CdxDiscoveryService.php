<?php

declare(strict_types=1);

namespace Survos\SiteDiscoveryBundle\Service;

use Survos\SiteDiscoveryBundle\Model\DiscoveredSite;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function sprintf;
use function preg_match;
use function str_starts_with;
use function array_merge;
use function is_array;
use function count;
use function array_slice;
use function json_decode;

/**
 * Discovers tenant hostnames under a shared SaaS domain via the Internet Archive CDX API.
 *
 * ## How it works
 *
 * The Internet Archive maintains a CDX (Capture inDeX) of every URL it has ever crawled.
 * URLs are stored in SURT (Sort-friendly URI Reordering Transform) order, which reverses
 * the domain segments: `https://foo.example.com/path` becomes `com,example,foo)/path`.
 *
 * For a SaaS platform like PastPerfect Online (`*.pastperfectonline.com`), every tenant
 * has a subdomain. Their SURT keys look like:
 *
 *   com,pastperfectonline,fauquierhistory)/
 *   com,pastperfectonline,bainbridgehistorymuseum)/advancedsearch
 *
 * We query the CDX API with:
 *   - `url=pastperfectonline.com` + `matchType=domain`  → returns the whole domain tree
 *   - `fl=urlkey`                                        → only the SURT key (cheap)
 *   - `filter=urlkey:{surtPrefix}[a-z0-9]`              → only subdomain rows
 *   - `collapse=urlkey`                                  → deduplicate at CDX level
 *   - `output=json`                                      → structured response
 *
 * The tenant slug is then extracted from the urlkey with a regex.
 * We dedup slugs client-side and yield one `DiscoveredSite` per unique slug.
 *
 * ## Limitations
 *
 * - Only the Internet Archive CDX API is supported. Common Crawl support is planned.
 * - Coverage depends on IA crawl history. Brand-new or robots-blocked sites may be missing.
 * - The CDX API can be slow (10–30 s per page). Use `$limit` during development.
 *
 * ## CDX API reference
 *
 * https://github.com/internetarchive/wayback/tree/master/wayback-cdx-server#readme
 */
final class CdxDiscoveryService
{
    private const CDX_API    = 'https://web.archive.org/cdx/search/cdx';
    private const SOURCE_KEY = 'internet_archive_cdx';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $userAgent = 'SurvosSiteDiscoveryBundle',
    ) {}

    /**
     * Yield one DiscoveredSite per unique tenant slug found in the CDX index.
     *
     * @param string $domain      Bare registered domain, e.g. "pastperfectonline.com"
     * @param string $surtPrefix  SURT prefix for subdomain rows, e.g. "com,pastperfectonline,"
     * @param string $scheme      URL scheme for the constructed base_url (default "https")
     * @param int    $limit       Stop after this many unique sites (0 = unlimited).
     *                            Set a small number (e.g. 5) during development/testing.
     * @param int    $pageSize    CDX rows per API request (default 5000; max ~10000).
     *
     * @return \Generator<DiscoveredSite>
     */
    public function discover(
        string $domain,
        string $surtPrefix,
        string $scheme = 'https',
        int $limit = 0,
        int $pageSize = 5000,
    ): \Generator {
        // Build a regex that matches urlkeys starting with the surt prefix + an alphanumeric char.
        // Tenant slugs use [a-z0-9] on PPO; Omeka slugs can include hyphens → use [a-z0-9-].
        $filterValue = $surtPrefix . '[a-z0-9]';
        // Slug capture: everything between the prefix and the closing ")"
        $slugRe = '/^' . preg_quote($surtPrefix, '/') . '([a-z0-9][a-z0-9-]*)\)/';

        $seen      = [];
        $count     = 0;
        $resumeKey = null;

        do {
            $params = [
                'url'           => $domain,
                'matchType'     => 'domain',
                'output'        => 'json',
                'fl'            => 'urlkey',
                'collapse'      => 'urlkey',
                'filter'        => 'urlkey:' . $filterValue,
                'limit'         => $pageSize,
                'showResumeKey' => 'true',
            ];

            if ($resumeKey !== null) {
                $params['resumeKey'] = $resumeKey;
            }

            $response = $this->httpClient->request('GET', self::CDX_API, [
                'query'   => $params,
                'headers' => ['User-Agent' => $this->userAgent],
                'timeout' => 120,
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException(sprintf('CDX API returned HTTP %d for domain "%s".', $response->getStatusCode(), $domain));
            }

            $decoded = json_decode($response->getContent(), true);
            if (!is_array($decoded) || count($decoded) < 2) {
                // Empty result or header-only — no more data.
                return;
            }

            // Row 0 is the header ["urlkey"] — skip it.
            $resumeKey = null;

            foreach (array_slice($decoded, 1) as $row) {
                if (!is_array($row) || !isset($row[0])) {
                    continue;
                }

                $value = (string) $row[0];

                // Resume keys do not start with the SURT prefix.
                if (!str_starts_with($value, $surtPrefix)) {
                    $resumeKey = $value;
                    continue;
                }

                if (!preg_match($slugRe, $value, $m)) {
                    continue;
                }

                $slug = $m[1];
                if (isset($seen[$slug])) {
                    continue;
                }
                $seen[$slug] = true;

                $host = $slug . '.' . $domain;

                yield new DiscoveredSite(
                    slug:         $slug,
                    host:         $host,
                    baseUrl:      $scheme . '://' . $host,
                    discoveredVia: self::SOURCE_KEY,
                );

                $count++;
                if ($limit > 0 && $count >= $limit) {
                    return;
                }
            }
        } while ($resumeKey !== null);
    }
}
