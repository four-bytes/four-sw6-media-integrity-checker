<?php declare(strict_types=1);

namespace Four\MediaIntegrityChecker;

use Shopware\Core\Framework\Plugin;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class FourMediaIntegrityChecker extends Plugin
{
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $confDir = \rtrim($this->getPath(), '/') . '/Resources/config';

        $locator = new FileLocator($confDir);

        $resolver = new LoaderResolver([
            new YamlFileLoader($container, $locator),
            new XmlFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
        ]);

        $configLoader = new DelegatingLoader($resolver);

        $configLoader->load($confDir . '/{packages}/*.yaml', 'glob');
    }
}
