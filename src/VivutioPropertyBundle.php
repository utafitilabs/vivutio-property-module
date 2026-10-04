<?php

declare(strict_types=1);

/*
 * This file is part of the vivutio property module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Vivutio\Property;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Properties: the camps, lodges and hotels an organization runs.
 *
 * The module knows the core; the core never names the module. Its pages join
 * the menu through the shell's seam, its permissions and the "property" scope
 * through the access contracts, and its routes are mounted by its recipe.
 */
final class VivutioPropertyBundle extends AbstractBundle
{
    /** Configuration lives under "property:", not the class-derived "vivutio_property:". */
    protected string $extensionAlias = 'property';

    /**
     * Where its entities are mapped and where the versions that build its
     * tables are, prepended so the installation keeps the last word; each
     * guarded, as an application may hold the bundle without either.
     *
     * @see https://symfony.com/doc/current/bundles/prepend_extension.html
     */
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        if ($builder->hasExtension('doctrine')) {
            $container->extension('doctrine', [
                'orm' => [
                    'mappings' => [
                        'Property' => [
                            'type' => 'attribute',
                            'dir' => __DIR__.'/Entity',
                            'prefix' => 'Vivutio\\Property\\Entity',
                            'is_bundle' => false,
                        ],
                    ],
                ],
            ], prepend: true);
        }

        if ($builder->hasExtension('doctrine_migrations')) {
            $container->extension('doctrine_migrations', [
                'migrations_paths' => [
                    'Vivutio\\Property\\Migrations' => \dirname(__DIR__).'/migrations',
                ],
            ], prepend: true);
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
