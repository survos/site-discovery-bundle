<?php

declare(strict_types=1);

namespace Survos\SiteDiscoveryBundle\Command;

use Survos\SiteDiscoveryBundle\Service\CdxDiscoveryService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;
use function json_encode;
use function fopen;
use function fwrite;
use function fclose;
use function dirname;
use function is_dir;
use function mkdir;

#[AsCommand('site:discover', 'Discover tenant sites under a SaaS domain via the Internet Archive CDX API')]
final class SiteDiscoverCommand
{
    public function __construct(
        private readonly CdxDiscoveryService $cdx,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Bare registered domain to search, e.g. pastperfectonline.com')]
        string $domain,
        #[Argument('SURT prefix for subdomain rows, e.g. com,pastperfectonline,')]
        string $surtPrefix,
        #[Option('Write results to this JSONL file (omit to print to stdout)')]
        ?string $output = null,
        #[Option('Stop after this many unique sites (0 = unlimited); use a small number for testing')]
        int $limit = 0,
        #[Option('CDX rows fetched per API request')]
        int $pageSize = 5000,
        #[Option('URL scheme for constructed base_url')]
        string $scheme = 'https',
    ): int {
        $io->title(sprintf('Discovering sites under %s via Internet Archive CDX', $domain));
        $io->text(sprintf('SURT prefix: %s | limit: %s | output: %s',
            $surtPrefix,
            $limit > 0 ? $limit : 'unlimited',
            $output ?? 'stdout',
        ));

        $fh = null;
        if ($output !== null) {
            $dir = dirname($output);
            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                $io->error(sprintf('Cannot create output directory: %s', $dir));

                return Command::FAILURE;
            }
            $fh = fopen($output, 'w');
            if ($fh === false) {
                $io->error(sprintf('Cannot open output file: %s', $output));

                return Command::FAILURE;
            }
        }

        $count  = 0;
        $errors = 0;

        try {
            foreach ($this->cdx->discover($domain, $surtPrefix, $scheme, $limit, $pageSize) as $site) {
                $line = json_encode($site->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($line === false) {
                    $errors++;
                    continue;
                }

                if ($fh !== null) {
                    if (fwrite($fh, $line . "\n") === false) {
                        throw new \RuntimeException(sprintf('Write failed on "%s".', $output));
                    }
                } else {
                    $io->writeln($line);
                }

                $count++;
                if ($count % 100 === 0) {
                    $io->text(sprintf('  %d sites discovered…', $count));
                }
            }
        } catch (\Throwable $e) {
            if ($fh !== null) {
                fclose($fh);
            }
            $io->error(sprintf('Discovery failed after %d sites: %s', $count, $e->getMessage()));

            return Command::FAILURE;
        }

        if ($fh !== null) {
            fclose($fh);
            $io->success(sprintf('%d unique sites written to %s', $count, $output));
        } else {
            $io->success(sprintf('%d unique sites discovered', $count));
        }

        return Command::SUCCESS;
    }
}
