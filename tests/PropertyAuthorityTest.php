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
use Vivutio\Property\Controller\RoomController;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Enum\PropertyTypeEnum;
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
    private const string ROOM_UUID = '0199a6f0-9e01-7e10-8000-000000009e02';
    private const string ROOM = self::PROPERTY.'/rooms/'.self::ROOM_UUID;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected static function probes(): array
    {
        return [
            new Probe(PropertyController::REGISTER, 'GET', '/properties'),
            new Probe(PropertyController::ADD, 'POST', '/properties', ['name' => 'Added by a probe', 'type' => 'tented_camp', 'location' => 'Tarangire, Tanzania'], formAt: '/properties'),
            new Probe(PropertyController::SHOW, 'GET', self::PROPERTY),
            new Probe(PropertyController::CONFIGURE, 'GET', self::PROPERTY.'/configure'),
            new Probe(PropertyController::CONFIGURE, 'POST', self::PROPERTY.'/configure', ['name' => 'Probed camp', 'type' => 'tented_camp', 'location' => 'Central Serengeti, Tanzania', 'status' => 'draft'], formAt: self::PROPERTY.'/configure'),
            new Probe(RoomController::ROOMS, 'GET', self::PROPERTY.'/rooms'),
            new Probe(RoomController::ADD, 'POST', self::PROPERTY.'/rooms', ['name' => 'Added by a probe', 'sleeps' => '2', 'adults' => '2', 'count' => '4'], formAt: self::PROPERTY.'/rooms'),
            new Probe(RoomController::CONFIGURE, 'GET', self::ROOM.'/configure'),
            new Probe(RoomController::CONFIGURE, 'POST', self::ROOM.'/configure', ['name' => 'Probed tent', 'count' => '12'], formAt: self::ROOM.'/configure'),
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
            ->setType(PropertyTypeEnum::TentedCamp)
            ->setLocation('Central Serengeti, Tanzania')
            ->setUuid(Uuid::fromString(self::PROPERTY_UUID)));
        $entityManager->flush();

        $camp = $entityManager->getRepository(Property::class)->findOneBy(['name' => 'Probed camp']);
        \assert($camp instanceof Property);
        $entityManager->persist((new RoomType($camp))
            ->setName('Probed tent')
            ->setSleeps(2)
            ->setAdults(2)
            ->setCount(10)
            ->setUuid(Uuid::fromString(self::ROOM_UUID)));
    }
}
