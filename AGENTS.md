# AGENTS.md — Guidelines for Agentic Coding Assistants

This document defines **mandatory rules** for any automated or semi-automated coding
agent (including LLM-based tools such as OpenCode, Cursor, Copilot Workspace, etc.)
that modifies this repository.

The overriding goals are:

- **Deterministic, reviewable diffs**
- **Explicit, boring PHP code**
- **No suppressed errors or magic**
- **Zero protocol surprises**

If an agent cannot follow these rules, it must refuse to proceed and explain why.

---

## Project overview

**survos/site-discovery-bundle** is a PHP 8.4 / Symfony 8.0 bundle that discovers
tenant hostnames under a shared SaaS domain (e.g. `*.pastperfectonline.com`) by
querying web archive indexes.

**Current backend:** Internet Archive CDX API only.
**Planned backend:** Common Crawl Host Index.

### Scope of this bundle

This bundle is intentionally small. It does **one thing**: yield `DiscoveredSite`
value objects for a given domain. It does NOT:

- Validate that discovered sites are live
- Fetch or parse site content
- Write JSONL files (that is the consumer's responsibility)
- Know anything about PastPerfect, Omeka, or any other platform

All platform-specific logic belongs in consumer bundles
(`survos/past-perfect-bundle`, `survos/omeka-bundle`, etc.).

---

## Supported environment

- **PHP**: 8.4+
- **Symfony**: 8.0+
- **Composer**: 2.x
- **Testing**: PHPUnit via `symfony/phpunit-bridge`

---

## Source layout

```
src/
├── Command/
│   └── SiteDiscoverCommand.php   # site:discover console command
├── Model/
│   └── DiscoveredSite.php        # readonly value object
├── Service/
│   └── CdxDiscoveryService.php   # Internet Archive CDX backend
└── SurvosSiteDiscoveryBundle.php # bundle class + DI wiring
```

Tests mirror this under `tests/`.

---

## CRITICAL: agent output requirements

### 1. Output format

Agents **MUST emit plain text output only**.

- ❌ Do NOT emit structured response items
- ❌ Do NOT emit `reasoning`, `analysis`, or multi-item protocols
- ❌ Do NOT rely on streaming item sequences
- ✅ Emit deterministic, reviewable text suitable for patch/diff workflows

### 2. Change scope

- Prefer **small, surgical diffs**
- Do not reformat unrelated code
- Do not introduce opportunistic refactors
- If a full-file rewrite is required, state it explicitly before doing it

---

## PHP coding standards

### Every PHP file must begin with

```php
<?php

declare(strict_types=1);
```

### Classes and types

- Use `final class` unless extension is explicitly required
- Use `final readonly class` for value objects (`DiscoveredSite` is an example)
- Type all parameters, return types, and properties
- Avoid `mixed` unless absolutely unavoidable

### Forbidden patterns

- ❌ Leading backslashes on global functions: `\json_encode()`, `\preg_match()`, etc.
  — use `use function` imports instead
- ❌ The `@` error suppression operator — handle failures explicitly
- ❌ `exit()` / `die()` — throw an exception instead

### Error handling

- Never suppress warnings or notices
- If a failure is recoverable, throw a typed exception
- Fail fast on programmer errors (wrong types, bad arguments)
- Prefer `\RuntimeException` or `\InvalidArgumentException`

```php
// Correct
$response = $this->httpClient->request('GET', $url);
if ($response->getStatusCode() !== 200) {
    throw new \RuntimeException(sprintf('CDX API returned HTTP %d.', $response->getStatusCode()));
}

// Forbidden
$response = @file_get_contents($url);
```

---

## Service design rules

### `CdxDiscoveryService`

- The `discover()` method is a **generator** — it yields lazily. Do not buffer all
  results into an array.
- The `$limit` parameter must be respected: stop yielding after that many **unique**
  sites, not that many CDX rows.
- Slug deduplication is done **client-side** (the `$seen` array). CDX `collapse=urlkey`
  reduces but does not eliminate duplicates across pages.
- The CDX API can be slow (10–30 s per page). Do not add concurrency or parallelism.
  One sequential HTTP request per page is correct.
- Pagination uses `showResumeKey=true` + `resumeKey` param. A resume key is the last
  element of a CDX JSON response page. It does **not** start with the SURT prefix —
  that is the detection heuristic used in `fetchUrlkeys()`.

### Adding a new backend

To add a new discovery backend (e.g. Common Crawl):

1. Create `src/Service/CommonCrawlDiscoveryService.php`
2. It must yield `DiscoveredSite` objects (same contract as `CdxDiscoveryService`)
3. It must accept a `$limit` parameter (0 = unlimited)
4. Register it in `SurvosSiteDiscoveryBundle::loadExtension()`
5. Update `README.md` to move the backend from "Planned" to documented
6. Do not change `CdxDiscoveryService` or `SiteDiscoverCommand` when adding a backend

---

## `DiscoveredSite` value object rules

`DiscoveredSite` is `final readonly`. Its constructor signature is the public API.

- Do not add mutable state
- Do not add methods that perform I/O
- `toArray()` must return a JSONL-serialisable associative array with stable keys:
  `slug`, `host`, `base_url`, `discovered_via`, `validated`, `validated_at`
- Do not rename or remove existing keys — consumer bundles depend on them

---

## Console command rules

All commands in this repository follow the Symfony 8.x invokable command pattern:

```php
#[AsCommand('site:discover', 'Short description')]
final class SiteDiscoverCommand
{
    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Description')]
        string $myArg,
        #[Option('Description')]
        bool $myOption = false,
    ): int {
        return Command::SUCCESS;
    }
}
```

### Mandatory rules

- Use `__invoke()` — never `execute()`, never `configure()`
- Do **not** extend `Command` (import `Command::SUCCESS` / `Command::FAILURE` via `use`)
- Use `#[Argument]` and `#[Option]` attributes on `__invoke()` parameters only
- `SymfonyStyle $io` is always the **first** parameter of `__invoke()`
- `#[AsCommand]` takes the command name as the **first positional argument** and the
  description as the **second positional argument** — never use named `description:` arg
- All options must have a default value
- Never define parameters named `$verbose`, `$version`, or `$help` (Symfony reserved)

---

## Bundle class rules

`SurvosSiteDiscoveryBundle` uses `AbstractBundle` from Symfony 8.x.

- Configuration is defined in `configure(DefinitionConfigurator $definition)`
- Services are wired in `loadExtension(array $config, ContainerConfigurator, ContainerBuilder)`
- Do not use `Extension` classes or `Configuration` classes — `AbstractBundle` handles both
- Service arguments use `service()` helper for injected services
- Scalar config values are passed directly as constructor arguments

---

## What agents should NOT do

- Do not add platform-specific knowledge (PastPerfect field names, Omeka API paths, etc.)
  — this bundle is platform-agnostic
- Do not add JSONL writing logic — consumers own output
- Do not add HTTP caching — that is a consumer or framework concern
- Do not change the `DiscoveredSite::toArray()` key names without a migration note
- Do not add `symfony/messenger` as a dependency — this bundle has no async concerns
- Do not add Doctrine or ORM dependencies

---

## Testing expectations

- Tests live under `tests/` mirroring `src/`
- Use `#[Test]` and `#[CoversClass]` attributes
- CDX integration tests must be skipped in CI unless explicitly opted in (they are slow
  and hit the live CDX API)
- Use a recorded fixture or a mock HTTP client for unit tests of `CdxDiscoveryService`
- Test that `$limit` is respected exactly
- Test that duplicate slugs across CDX pages are deduplicated

---

## Agent summary (non-negotiable checklist)

Before submitting any change, verify:

- [ ] All PHP files have `declare(strict_types=1)`
- [ ] No leading backslashes on global functions
- [ ] No `@` error suppression
- [ ] No new platform-specific logic added to this bundle
- [ ] `DiscoveredSite::toArray()` key names unchanged (or migration documented)
- [ ] `CdxDiscoveryService::discover()` is still a lazy generator
- [ ] `$limit` is respected
- [ ] Command uses `__invoke()` pattern
- [ ] README updated if public API changed
