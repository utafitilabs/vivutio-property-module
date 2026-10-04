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

namespace Vivutio\Property\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;
use Vivutio\Bundle\IdentityBundle\Test\AuthorityTestCase;
use Vivutio\Bundle\IdentityBundle\Test\Probe;
use Vivutio\Property\Controller\PropertyController;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\UnitEnum;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * The module held to the core's five proofs, through the base every module's
 * suite extends. Record a reviewed change to the table with:
 *
 *     VIVUTIO_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit --filter PropertyAuthorityTest
 */
final class PropertyAuthorityTest extends AuthorityTestCase
{
    private const string PROPERTY_UUID = '0199a6f0-9e01-7e10-8000-000000009e01';

    private const string PROPERTY = '/properties/'.self::PROPERTY_UUID;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        return [
            new Probe(PropertyController::REGISTER, 'GET', '/properties'),
            new Probe(PropertyController::ADD, 'POST', '/properties', ['name' => 'Added by a probe', 'location' => 'Tarangire, Tanzania', 'units' => '12', 'unit' => 'tents'], formAt: '/properties'),
            new Probe(PropertyController::SHOW, 'GET', self::PROPERTY),
            new Probe(PropertyController::CONFIGURE, 'GET', self::PROPERTY.'/configure'),
            new Probe(PropertyController::CONFIGURE, 'POST', self::PROPERTY.'/configure', ['name' => 'Probed camp', 'location' => 'Central Serengeti, Tanzania', 'units' => '24', 'unit' => 'tents'], formAt: self::PROPERTY.'/configure'),
        ];
    }

    protected static function packageDirectory(): string
    {
        return \dirname(__DIR__);
    }

    protected static function authorityTable(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected function seedSubjects(EntityManagerInterface $entityManager): void
    {
        $entityManager->persist((new Property())
            ->setName('Probed camp')
            ->setLocation('Central Serengeti, Tanzania')
            ->setUnits(24)
            ->setUnit(UnitEnum::Tents)
            ->setUuid(Uuid::fromString(self::PROPERTY_UUID)));
    }
}
