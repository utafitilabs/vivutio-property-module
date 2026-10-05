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
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Property\Entity\Closure;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A property's Calendar tab: how many of each room type are free each night,
 * which is its count less what is closed; closures of the whole property, or
 * of some units of a room type, each with its reason; and a night's own page.
 */
final class TheCalendarTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Property $lodge;
    private RoomType $tented;

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

    public function testTheCalendarShowsWhatIsFreeEachNight(): void
    {
        $this->lodgeWithRooms();

        $tab = $this->browser->request('GET', $this->calendar('2026-07'));

        self::assertResponseIsSuccessful();
        self::assertSame('Calendar', trim($tab->filter('nav.tabs a[aria-current="page"]')->text()));
        self::assertSame(['Tented Room', 'Family Tent'], $tab->filter('tr[data-room]')->each(static fn (Crawler $row): string => (string) $row->attr('data-room')));
        self::assertCount(31, $tab->filter('tr[data-room="Tented Room"] td[data-night]'));
        self::assertSame('20', trim($tab->filter('tr[data-room="Tented Room"] td[data-night="2026-07-01"]')->text()));
        self::assertSame('4', trim($tab->filter('tr[data-room="Family Tent"] td[data-night="2026-07-31"]')->text()));
        self::assertSame($this->calendar('2026-08'), $tab->filter('.intro')->selectLink('August 2026')->attr('href'));
        self::assertSame('/properties/'.$this->lodge->getUuid().'/calendar/2026-07-04', $tab->filter('tr[data-room="Tented Room"] td[data-night="2026-07-04"] a')->attr('href'));
    }

    /** A season the property shuts for, the long rains, is a closure of the whole property: every room type is closed. */
    public function testTheWholePropertyIsClosedForAWhile(): void
    {
        $this->lodgeWithRooms();
        $this->close(['starts' => '2027-04-01', 'ends' => '2027-05-31', 'reason' => 'Long rains']);

        $tab = $this->browser->request('GET', $this->calendar('2027-04'));
        foreach (['Tented Room', 'Family Tent'] as $room) {
            $night = $tab->filter('tr[data-room="'.$room.'"] td[data-night="2027-04-10"]');
            self::assertSame('0', trim($night->text()));
            self::assertNotNull($night->attr('data-closed'));
        }
        $row = $tab->filter('tr[data-closure="Long rains"]');
        self::assertSame('1 Apr – 31 May 2027', trim($row->filter('[data-span]')->text()));
        self::assertSame('The whole property', trim($row->filter('[data-what]')->text()));
        self::assertSame('20', trim($this->browser->request('GET', $this->calendar('2027-06'))->filter('tr[data-room="Tented Room"] td[data-night="2027-06-01"]')->text()));
    }

    /** Some units of a room type out of service leave the rest free. */
    public function testSomeUnitsOfARoomTypeAreTakenOutOfService(): void
    {
        $this->lodgeWithRooms();
        $this->close(['room' => (string) $this->tented->getUuid(), 'units' => '2', 'starts' => '2026-07-03', 'ends' => '2026-07-05', 'reason' => 'Canvas repairs']);
        $this->close(['room' => (string) $this->tented->getUuid(), 'units' => '1', 'starts' => '2026-07-05', 'ends' => '2026-07-05', 'reason' => 'Plumbing']);

        $tab = $this->browser->request('GET', $this->calendar('2026-07'));
        $tented = $tab->filter('tr[data-room="Tented Room"]');
        self::assertSame(['20', '18', '18', '17', '20'], array_map(static fn (string $night): string => trim($tented->filter('td[data-night="2026-07-0'.$night.'"]')->text()), ['2', '3', '4', '5', '6']));
        self::assertNull($tented->filter('td[data-night="2026-07-04"]')->attr('data-closed'), 'partly out is not closed');
        self::assertSame('4', trim($tab->filter('tr[data-room="Family Tent"] td[data-night="2026-07-04"]')->text()));
        self::assertSame('Tented Room, 2 units', trim($tab->filter('tr[data-closure="Canvas repairs"] [data-what]')->text()));
    }

    public function testANightsPageSaysWhatIsFreeAndWhatTakesTheRest(): void
    {
        $this->lodgeWithRooms();
        $this->close(['room' => (string) $this->tented->getUuid(), 'units' => '2', 'starts' => '2026-07-03', 'ends' => '2026-07-05', 'reason' => 'Canvas repairs']);

        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/calendar/2026-07-04');

        self::assertResponseIsSuccessful();
        self::assertSame('Saturday 4 July 2026', trim($page->filter('h1')->text()));
        self::assertSame('18 of 20 free', trim($page->filter('[data-room="Tented Room"] [data-free]')->text()));
        self::assertSame(['Canvas repairs: 2 out'], $page->filter('[data-room="Tented Room"] [data-out]')->each(static fn (Crawler $line): string => trim($line->text())));
        self::assertSame('4 of 4 free', trim($page->filter('[data-room="Family Tent"] [data-free]')->text()));
        $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/calendar/2026-02-30');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAClosureIsRefusedBesideItsField(): void
    {
        $this->lodgeWithRooms();

        foreach ([
            ['ends', ['starts' => '2026-07-05', 'ends' => '2026-07-01']],
            ['ends', ['starts' => '2026-07-01', 'ends' => '2027-08-01']],
            ['starts', ['starts' => 'next Tuesday']],
            ['units', ['room' => (string) $this->tented->getUuid(), 'units' => '21']],
            ['units', ['room' => '', 'units' => '2']],
            ['reason', ['reason' => ' ']],
        ] as [$field, $changed]) {
            $page = $this->close([...['starts' => '2026-07-01', 'ends' => '2026-07-05', 'reason' => 'Maintenance'], ...$changed], 422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
        }

        self::assertCount(0, $this->em()->getRepository(Closure::class)->findAll());
    }

    public function testAClosureIsRemoved(): void
    {
        $this->lodgeWithRooms();
        $this->close(['starts' => '2027-04-01', 'ends' => '2027-05-31', 'reason' => 'Long rains']);

        $tab = $this->browser->request('GET', $this->calendar('2027-04'));
        $this->browser->submit($tab->filter('tr[data-closure="Long rains"]')->selectButton('Remove')->form());

        self::assertResponseRedirects($this->calendar('2027-04'));
        self::assertCount(0, $this->em()->getRepository(Closure::class)->findAll());
    }

    public function testStaffWhoReadPropertiesSeeTheCalendarAndChangeNothing(): void
    {
        $this->lodgeWithRooms();
        $this->close(['starts' => '2027-04-01', 'ends' => '2027-05-31', 'reason' => 'Long rains']);
        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $this->signedInAs($this->person('Amani', TierEnum::Staff, ['properties.read'], $reception));

        $tab = $this->browser->request('GET', $this->calendar('2027-04'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tab->filter('tr[data-closure]'));
        self::assertCount(0, $tab->filter('form[method="post"]'));
        $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/calendar/2027-04-10');
        self::assertResponseIsSuccessful();
    }

    private function lodgeWithRooms(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->lodge = $this->add('Vivutio Lakeshore Lodge');
        $rooms = static::getContainer()->get(RoomTypeService::class);
        self::assertInstanceOf(RoomTypeService::class, $rooms);
        $this->tented = $rooms->create($this->lodge, new RoomTypeDetails(name: 'Tented Room', sleeps: '2', adults: '2', count: '20'));
        $rooms->create($this->lodge, new RoomTypeDetails(name: 'Family Tent', sleeps: '4', adults: '3', count: '4'));
    }

    private function calendar(string $month): string
    {
        return '/properties/'.$this->lodge->getUuid().'/calendar?month='.$month;
    }

    /**
     * @param array<string, string> $values
     */
    private function close(array $values, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', $this->calendar(substr($values['starts'], 0, 7)));
        $form = $page->selectButton('Add the closure')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues($values));
        self::assertResponseStatusCodeSame($answered);

        return $page;
    }

    private function add(string $name): Property
    {
        $page = $this->browser->request('GET', '/properties');
        $this->browser->submit($page->selectButton('Add the property')->form(['name' => $name, 'type' => 'tented_lodge', 'location' => 'Lake Manyara, Tanzania']));

        return $this->property($name);
    }

    private function property(string $name): Property
    {
        $this->em()->clear();
        $property = $this->em()->getRepository(Property::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Property::class, $property);

        return $property;
    }

    /**
     * @param list<string>|null $grants
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, ?Department $department = null): User
    {
        $position = null;
        if (null !== $grants) {
            $position = (new Position())->setName($name.'\'s seat')->setGrants($grants);
            $this->em()->persist($position);
        }

        $user = (new User())
            ->setEmail(strtolower($name).'@vivutio-camps.example')
            ->setFirstName($name)
            ->setLastName('Kimaro')
            ->setTier($tier)
            ->setPosition($position)
            ->setDepartment($department)
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
