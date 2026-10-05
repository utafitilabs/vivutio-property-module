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

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyReach;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\Season;
use Vivutio\Property\Enum\PropertyTypeEnum;
use Vivutio\Property\Enum\ReachEnum;
use Vivutio\Property\Enum\SeasonKindEnum;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A property deleted by a Super Admin through the core's delete (as uhifadhi
 * ruled it): everything under it goes, counted first; people posted there are
 * posted nowhere, departments that sat there sit with the organization, and a
 * reach naming it names the rest, so it never widens.
 */
final class ThePropertyDeletionTest extends WebTestCase
{
    private KernelBrowser $browser;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $connection = static::getContainer()->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);
        $connection->executeStatement('DROP SCHEMA IF EXISTS public CASCADE');
        $connection->executeStatement('CREATE SCHEMA public');
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();
        self::assertSame(0, $application->run(new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]), $output), $output->fetch());
    }

    public function testAPropertyGoesWithEverythingUnderItAndWhatSatThereStays(): void
    {
        $lodge = $this->property('Vivutio Lakeshore Lodge');
        $other = $this->property('Vivutio Riverside Camp');
        foreach (['Tented Room', 'Family Tent'] as $name) {
            $this->em()->persist((new RoomType($lodge))->setName($name)->setCount(4));
        }
        $this->em()->persist((new Season($lodge))->setName('High')->setKind(SeasonKindEnum::High));
        $this->em()->persist(new Closure($lodge, new \DateTimeImmutable('2027-04-01'), new \DateTimeImmutable('2027-04-30'), 'Long rains'));
        $amani = $this->person('Amani', TierEnum::Staff);
        $amani->setPosting($lodge, new \DateTimeImmutable('2026-10-01'));
        $this->em()->persist((new Department())->setName('Housekeeping')->setPlace($lodge));
        $elia = $this->person('Elia', TierEnum::Staff);
        $this->em()->persist((new PropertyReach($elia))->setReach(ReachEnum::Chosen)->setProperties([(string) $lodge->getUuid(), (string) $other->getUuid()]));
        $this->em()->flush();
        $this->signedInAs($this->person('Neema', TierEnum::SuperAdmin));

        $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/configure');
        $page = $this->browser->click($page->filter('[data-danger]')->selectLink('Delete…')->link());
        self::assertSame('Delete Vivutio Lakeshore Lodge', trim($page->filter('h1')->text()));
        self::assertSame(['1 property', '2 room types', '1 season', '1 closure'], $this->lines($page, 'goes'));
        self::assertSame([
            '1 person posted there, who is then posted nowhere',
            '1 department sitting there, which then sits with the organization',
            '1 person whose permissions apply there, who then keeps the rest of their properties',
        ], $this->lines($page, 'stays'));

        $this->browser->submit($page->selectButton('Delete the property')->form(['reference' => 'Vivutio Lakeshore Lodge']));
        self::assertResponseRedirects('/properties');
        self::assertSame('Property Vivutio Lakeshore Lodge is deleted.', trim($this->browser->followRedirect()->filter('[data-deleted]')->text()));

        $this->em()->clear();
        self::assertNull($this->em()->getRepository(Property::class)->findOneBy(['name' => 'Vivutio Lakeshore Lodge']));
        self::assertCount(0, $this->em()->getRepository(RoomType::class)->findAll());
        $amani = $this->em()->getRepository(User::class)->findOneBy(['email' => 'amani@vivutio-camps.example']);
        self::assertInstanceOf(User::class, $amani);
        self::assertNull($amani->getPostedKind());
        $housekeeping = $this->em()->getRepository(Department::class)->findOneBy(['name' => 'Housekeeping']);
        self::assertInstanceOf(Department::class, $housekeeping);
        self::assertNull($housekeeping->getPlaceKind());
        $reach = $this->em()->getRepository(PropertyReach::class)->findOneBy([]);
        self::assertInstanceOf(PropertyReach::class, $reach);
        self::assertSame(ReachEnum::Chosen, $reach->getReach());
        self::assertSame([(string) $other->getUuid()], $reach->getProperties());
    }

    public function testAnAdminIsOfferedNoDelete(): void
    {
        $lodge = $this->property('Vivutio Lakeshore Lodge');
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        self::assertCount(0, $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/configure')->filter('[data-danger]'));
        $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/delete');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<string>
     */
    private function lines(Crawler $page, string $which): array
    {
        return $page->filter('[data-'.$which.'] .fact span')->each(static fn (Crawler $line): string => trim($line->text()));
    }

    private function property(string $name): Property
    {
        $property = (new Property())->setName($name)->setType(PropertyTypeEnum::TentedLodge)->setLocation('Lake Manyara, Tanzania');
        $this->em()->persist($property);
        $this->em()->flush();

        return $property;
    }

    private function person(string $name, TierEnum $tier): User
    {
        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-camps.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPassword('a hash, never a password');
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function signedInAs(User $user): void
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
