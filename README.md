# survos/site-discovery-bundle

Discovers tenant hostnames under a shared SaaS domain — e.g. `*.pastperfectonline.com`,
`*.omeka.net` — by querying web archive indexes.

**Current backend: Internet Archive CDX API only.**
Common Crawl support is planned but not yet implemented.

**Requirements:** PHP 8.4+, Symfony 8.0+

---

## Background: how the CDX API works

The Internet Archive crawls the web continuously and stores every URL in a CDX
(Capture inDeX). URLs are sorted in **SURT** (Sort-friendly URI Reordering Transform)
order, which reverses domain label order:

```
https://fauquierhistory.pastperfectonline.com/path
  →  com,pastperfectonline,fauquierhistory)/path
```

For a SaaS platform like PastPerfect Online, every tenant has a subdomain. Their SURT
keys look like:

```
com,pastperfectonline,fauquierhistory)/
com,pastperfectonline,bainbridgehistorymuseum)/advancedsearch
```

The tenant slug (`fauquierhistory`) sits between the shared SURT prefix
(`com,pastperfectonline,`) and the closing `)`. This bundle queries CDX for all URLs
under the registered domain, filters to subdomain-only rows, and extracts unique slugs.

### Computing the SURT prefix

Reverse the domain labels, join with commas, add a trailing comma:

| Domain                    | SURT prefix               |
|---------------------------|---------------------------|
| `pastperfectonline.com`   | `com,pastperfectonline,`  |
| `omeka.net`               | `net,omeka,`              |
| `myheritage.com`          | `com,myheritage,`         |
| `arcgis.com`              | `com,arcgis,`             |

---

## Installation

```bash
composer require survos/site-discovery-bundle
```

Register if not using Symfony Flex:

```php
// config/bundles.php
return [
    Survos\SiteDiscoveryBundle\SurvosSiteDiscoveryBundle::class => ['all' => true],
];
```

---

## Configuration

```yaml
# config/packages/survos_site_discovery.yaml
survos_site_discovery:
    user_agent: "MyApp SiteDiscovery"   # defaults to "SurvosSiteDiscoveryBundle"
```

---

## Console command

```
site:discover <domain> <surtPrefix> [options]
```

### Arguments

| Argument     | Description |
|--------------|-------------|
| `domain`     | Bare registered domain, e.g. `pastperfectonline.com` |
| `surtPrefix` | SURT prefix for subdomain rows, e.g. `com,pastperfectonline,` |

### Options

| Option        | Default | Description |
|---------------|---------|-------------|
| `--output`    | stdout  | Write JSONL to this file path |
| `--limit`     | 0       | Stop after N unique sites (0 = unlimited). **Always use a small number during development.** |
| `--page-size` | 5000    | CDX rows per API request (max ~10 000) |
| `--scheme`    | `https` | URL scheme used in `base_url` |

### Examples

```bash
# Discover PastPerfect Online sites, print to stdout (first 5 for testing)
bin/console site:discover pastperfectonline.com com,pastperfectonline, --limit=5

# Write to a JSONL file
bin/console site:discover pastperfectonline.com com,pastperfectonline, \
    --output=var/discovery/pastperfect-sites.jsonl

# Discover Omeka.net sites
bin/console site:discover omeka.net net,omeka, \
    --output=var/discovery/omeka-sites.jsonl

# Full discovery — slow, expect 10–30 s per CDX page
bin/console site:discover pastperfectonline.com com,pastperfectonline, \
    --output=var/discovery/pastperfect-sites.jsonl
```

### Output JSONL shape

One JSON object per line:

```json
{
  "slug":           "fauquierhistory",
  "host":           "fauquierhistory.pastperfectonline.com",
  "base_url":       "https://fauquierhistory.pastperfectonline.com",
  "discovered_via": "internet_archive_cdx",
  "validated":      false,
  "validated_at":   null
}
```

---

## PHP API

### Inject `CdxDiscoveryService`

```php
use Survos\SiteDiscoveryBundle\Service\CdxDiscoveryService;
use Survos\SiteDiscoveryBundle\Model\DiscoveredSite;

final class MyHarvester
{
    public function __construct(
        private readonly CdxDiscoveryService $cdx,
    ) {}

    public function run(): void
    {
        foreach ($this->cdx->discover('pastperfectonline.com', 'com,pastperfectonline,') as $site) {
            // $site is a DiscoveredSite value object
            echo $site->slug;     // "fauquierhistory"
            echo $site->host;     // "fauquierhistory.pastperfectonline.com"
            echo $site->baseUrl;  // "https://fauquierhistory.pastperfectonline.com"

            $row = $site->toArray(); // JSONL-ready associative array
        }
    }
}
```

### `CdxDiscoveryService::discover()` signature

```php
public function discover(
    string $domain,       // e.g. "pastperfectonline.com"
    string $surtPrefix,   // e.g. "com,pastperfectonline,"
    string $scheme   = 'https',
    int    $limit    = 0,     // 0 = unlimited; set small for dev/testing
    int    $pageSize = 5000,
): \Generator  // yields DiscoveredSite
```

### `DiscoveredSite` value object

```php
final readonly class DiscoveredSite
{
    public string $slug;           // "fauquierhistory"
    public string $host;           // "fauquierhistory.pastperfectonline.com"
    public string $baseUrl;        // "https://fauquierhistory.pastperfectonline.com"
    public string $discoveredVia;  // "internet_archive_cdx"

    public function toArray(): array;  // JSONL-ready
}
```

---

## CDX API technical notes

These notes are provided for agents and developers integrating with or extending this bundle.

**Endpoint:** `https://web.archive.org/cdx/search/cdx`

**Parameters used by this bundle:**

| Parameter       | Value                            | Purpose |
|-----------------|----------------------------------|---------|
| `url`           | e.g. `pastperfectonline.com`     | Registered domain (no wildcard) |
| `matchType`     | `domain`                         | Returns all URLs in the entire domain tree |
| `output`        | `json`                           | Structured response; row 0 is a header array |
| `fl`            | `urlkey`                         | Only fetch the SURT key column — cheapest option |
| `collapse`      | `urlkey`                         | CDX-level deduplication |
| `filter`        | `urlkey:{surtPrefix}[a-z0-9]`    | Restrict to subdomain rows; skips bare domain entries |
| `limit`         | 5000                             | Rows per page |
| `showResumeKey` | `true`                           | Enables pagination |
| `resumeKey`     | `{key from previous page}`       | Continue from prior page |

**Pagination:** when `showResumeKey=true`, the last row of each page is a resume-key
string (not a urlkey). It does NOT start with the SURT prefix — that is how we
distinguish it from real data rows. Pass it as `resumeKey` on the next request.

**Why `fl=urlkey` instead of `fl=original`:** the `original` field contains the raw
crawled URL, which requires URL parsing to extract the hostname. The `urlkey` encodes
the slug directly and unambiguously. One regex match is all that is needed.

**Why `matchType=domain` instead of `matchType=host`:** `matchType=host` with a
wildcard (`*.example.com`) returns empty results. `matchType=domain` with the bare
registered domain returns the full tree.

**Latency:** CDX API requests with `matchType=domain` can take 10–30 seconds per page.
The response is streamed; the bundle waits for the full response. Plan accordingly.

**Coverage gaps:** sites blocked by `robots.txt` during crawl, or newer than the most
recent IA crawl, will not appear. Use the output as a candidate list to be validated,
not as a definitive registry.

---

## Rate limiting

The CDX API is free and unauthenticated. The Internet Archive does not publish a formal
rate limit, but hammering the API with parallel requests is antisocial. This bundle
makes one sequential request per page. Do not add concurrency.

## Fetch strategy (cache/retry)

`CdxDiscoveryService` fetches through `survos/fetch-bundle`'s `PersistentFetcherInterface`
rather than a raw `HttpClientInterface`.

- **Cache:** each page request (including its `resumeKey`) is keyed by its full query URL and
  cached **forever** in a SQLite pool at `var/data/fetch_cache.db` — until `forget()`/
  `force_fetch`. Given the CDX API's 10–30s/page latency (see above), this is what makes
  re-running discovery during development cheap instead of re-paying that cost every time.
  If new tenant sites should be picked up that a cached run would miss, `force_fetch` (or clear
  the relevant cache entries) rather than assuming the API is being re-queried live.
- **Retry:** up to 5 attempts, full-jitter exponential backoff (200ms base, 10s cap), on
  transport errors, HTTP 429, and 5xx — in addition to this bundle's own sequential-only,
  no-concurrency policy above.

Before 2026-08-08 this used a raw `HttpClientInterface` with no caching — every discovery run
re-paid the full CDX latency for every page, even re-running the exact same query.

---

## Downstream validation

This bundle only discovers candidate hostnames. It does not validate that a host is
currently live or that it is still running the expected platform. Add a probe step in
your consumer bundle, e.g.:

```php
// Pseudo-code — implement in your bundle
$response = $httpClient->request('GET', $site->baseUrl . '/AdvancedSearch');
$isLive = $response->getStatusCode() === 200
    && str_contains($response->getContent(), 'pastperfectonline');
```

---

## Planned backends

- [ ] Common Crawl Host Index (higher coverage; requires DuckDB or Athena)
- [ ] Static seed file (CSV/JSONL of known hosts, for offline or pre-seeded use)

Pull requests for additional backends are welcome. Implement
`CdxDiscoveryService` as a reference — yield `DiscoveredSite` objects, accept a
`$limit` parameter, stream results lazily.

---

## License

MIT
