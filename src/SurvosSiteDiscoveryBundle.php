<?php

declare(strict_types=1);

namespace Survos\SiteDiscoveryBundle;

use Survos\SiteDiscoveryBundle\Command\SiteDiscoverCommand;
use Survos\SiteDiscoveryBundle\Service\CdxDiscoveryService;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SurvosSiteDiscoveryBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('user_agent')
                    ->defaultValue('SurvosSiteDiscoveryBundle')
                    ->info('User-Agent header sent to discovery APIs')
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set(CdxDiscoveryService::class)
            ->autowire()
            ->arg('$userAgent', $config['user_agent']);

        $services->set(SiteDiscoverCommand::class)
            ->autowire()
            ->tag('console.command');
    }
}
