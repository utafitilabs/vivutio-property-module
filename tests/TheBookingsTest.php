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
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DomCrawler\Crawler;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Model\PropertyDetails;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * Bookings received, on screen: the Bookings tab for whoever reads them, a
 * new booking for whoever records one, a booking's page, and confirming and
 * cancelling it for whoever manages them; reach applies to every one.
 */
final class TheBookingsTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Property $lodge;
    private RoomType $tented;
    private SeasonPeriod $high;
    private SeasonPeriod $green;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-07-01 09:00'));
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

    public function testABookingIsRecordedAndShownOnTheTabAndItsPage(): void
    {
        $this->openLodge();

        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings');
        self::assertSame('Bookings', trim($page->filter('nav.tabs a[aria-current="page"]')->text()));
        $this->browser->click($page->filter('.intro')->selectLink('New booking')->link());
        $this->browser->submit($this->browser->getCrawler()->selectButton('Record the booking')->form([
            'guest' => 'Mollel party',
            'booked_by' => 'Vivutio Tours',
            'arrival' => '2026-10-30',
            'nights' => '3',
            'lines[0][room]' => (string) $this->tented->getUuid(),
            'lines[0][rooms]' => '2',
            'status' => 'confirmed',
        ]));

        $booking = $this->only();
        self::assertResponseRedirects('/properties/'.$this->lodge->getUuid().'/bookings/'.$booking->getUuid());
        $record = $this->browser->followRedirect();
        self::assertSame('VLL-0001', trim($record->filter('h1')->text()));
        self::assertSame('USD 3,160.00', trim($record->filter('[data-total]')->text()));
        self::assertSame(['2026-10-30', '2026-10-31', '2026-11-01'], $record->filter('[data-night]')->each(static fn (Crawler $night): string => (string) $night->attr('data-night')));

        $row = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings')->filter('tr[data-booking="VLL-0001"]');
        self::assertSame('2 Tented Room', trim($row->filter('[data-rooms]')->text()));
        self::assertSame('Confirmed', trim($row->filter('[data-status]')->text()));

        $night = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/calendar/2026-10-30');
        self::assertSame('18 of 20 free', trim($night->filter('[data-room="Tented Room"] [data-free]')->text()));
        self::assertSame(['VLL-0001 · Mollel party: 2 booked'], $night->filter('[data-room="Tented Room"] [data-out]')->each(static fn (Crawler $line): string => trim((string) preg_replace('/\s+/', ' ', $line->text()))));
    }

    public function testWhatABookingCannotBeIsSaidBesideItsLine(): void
    {
        $this->openLodge();

        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings/new');
        $page = $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => 'Mollel party', 'arrival' => '2026-10-30', 'nights' => '3', 'lines[0][room]' => (string) $this->tented->getUuid(), 'lines[0][rooms]' => '21', 'status' => 'confirmed']));

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Tented Room has 20 free on 30 Oct 2026', $page->filter('.notice.danger')->text());
        self::assertSame('21', $page->filter('input[name="lines[0][rooms]"]')->attr('value'), 'what was typed is shown back');
    }

    public function testAHoldIsConfirmedAndABookingCancelledOnItsPage(): void
    {
        $this->openLodge();
        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings/new');
        $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => 'Mollel party', 'arrival' => '2026-10-30', 'nights' => '3', 'lines[0][room]' => (string) $this->tented->getUuid(), 'status' => 'provisional', 'held_until' => '2026-07-15']));
        $record = $this->browser->followRedirect();
        self::assertSame('Held to 15 Jul', trim($record->filter('[data-status]')->text()));

        $this->browser->submit($record->selectButton('Confirm the booking')->form());
        $record = $this->browser->followRedirect();
        self::assertSame('Confirmed', trim($record->filter('[data-status]')->text()));
        self::assertStringContainsString('Cancelling today would cost USD 0.00', $record->filter('[data-charge-today]')->text(), 'more than 60 days ahead');

        $this->browser->submit($record->selectButton('Cancel the booking')->form(['reason' => 'The guests changed their plans']));
        $record = $this->browser->followRedirect();
        self::assertSame('Cancelled', trim($record->filter('[data-status]')->text()));
        self::assertStringContainsString('cancelling cost USD 0.00', $record->filter('.notice')->text());
        self::assertCount(0, $record->filter('[data-actions]'));
    }

    /** Reading, recording and managing are three pairs, each a department must allow; reach applies to every booking. */
    public function testStaffDoWhatTheirPairsAllowWhereTheirReachCovers(): void
    {
        $this->openLodge();
        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings/new');
        $this->browser->submit($page->selectButton('Record the booking')->form(['guest' => 'Mollel party', 'arrival' => '2026-10-30', 'nights' => '3', 'lines[0][room]' => (string) $this->tented->getUuid(), 'status' => 'confirmed']));
        $booking = $this->only();

        $front = (new Department())->setName('Front office')->setAllows(['properties.read', 'property_bookings.read']);
        $this->em()->persist($front);
        $this->signedInAs($this->person('Neema', TierEnum::Staff, ['properties.read', 'property_bookings.read', 'property_bookings.manage'], $front));

        $tab = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $tab->filter('.intro')->selectLink('New booking'));
        $record = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings/'.$booking->getUuid());
        self::assertCount(0, $record->filter('[data-actions]'), 'managing is not allowed by the department');
        $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/bookings/new');
        self::assertResponseStatusCodeSame(403);

        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['properties.read']));
        self::assertCount(0, $this->browser->request('GET', '/properties/'.$this->lodge->getUuid())->filter('nav.tabs')->selectLink('Bookings'), 'no Bookings tab without the pair');
    }

    private function openLodge(): void
    {
        $this->pricedLodge();
        $properties = static::getContainer()->get(PropertyService::class);
        self::assertInstanceOf(PropertyService::class, $properties);
        $properties->change($this->property('Vivutio Lakeshore Lodge'), new PropertyDetails(name: 'Vivutio Lakeshore Lodge', type: 'tented_lodge', location: 'Lake Manyara, Tanzania', status: 'open'));
    }

    private function only(): PropertyBooking
    {
        $this->em()->clear();
        $bookings = $this->em()->getRepository(PropertyBooking::class)->findAll();
        self::assertCount(1, $bookings);

        return $bookings[0];
    }

    private function pricedLodge(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->lodge = $this->add('Vivutio Lakeshore Lodge');
        $rooms = static::getContainer()->get(RoomTypeService::class);
        self::assertInstanceOf(RoomTypeService::class, $rooms);
        $this->tented = $rooms->create($this->lodge, new RoomTypeDetails(name: 'Tented Room', sleeps: '2', adults: '2', count: '20'));
        $seasons = static::getContainer()->get(SeasonService::class);
        self::assertInstanceOf(SeasonService::class, $seasons);
        $this->high = $seasons->addPeriod($seasons->create($this->lodge, 'High Season', 'high'), '2026-06-01', '2026-10-31');
        $this->green = $seasons->addPeriod($seasons->create($this->lodge, 'Green Season', 'low'), '2026-11-01', '2027-05-31');
        $rates = static::getContainer()->get(RateService::class);
        self::assertInstanceOf(RateService::class, $rates);
        $rates->setup($this->lodge, 'USD', 'per_person', ['full_board']);
        $rates->saveRates($this->lodge, BoardBasisEnum::FullBoard, [(string) $this->tented->getUuid() => [(string) $this->high->getUuid() => '290', (string) $this->green->getUuid() => '210']]);
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
