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
use Vivutio\Bundle\PartnerBundle\Entity\Partner;
use Vivutio\Bundle\PartnerBundle\Enum\PartnerKindEnum;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Entity\PropertyReach;
use Vivutio\Property\Enum\BookingStatusEnum;
use Vivutio\Property\Enum\ReachEnum;
use Vivutio\Property\Service\PropertyService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * What the module puts on the organization's dashboard, as designed in
 * vivutio-designs/shell/dashboard-supplier.html: the held bookings still to
 * confirm, a figure in the band and a card of the soonest to let go, counted
 * over the properties the person may read.
 */
final class TheDashboardTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Property $lodge;
    private Property $camp;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        $this->browser = static::createClient();
        $this->browser->disableReboot();
        static::getContainer()->set('clock', new MockClock('2026-10-12 09:00'));
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

        $this->bookings();
    }

    public function testTheBandCountsWhatIsHeldAndWhatLetsGoSoonest(): void
    {
        $page = $this->home($this->person('Baraka', TierEnum::Admin));

        self::assertSame('Held, to confirm 8 2 let go today', $this->figure($page));
    }

    /** The soonest to let go first, at most five, each saying when its hold runs out. */
    public function testTheCardListsTheSoonestToLetGo(): void
    {
        $card = $this->card($this->home($this->person('Baraka', TierEnum::Admin)));

        self::assertSame('Bookings · the soonest let go first', self::words($card->filter('.panel-head p')));
        self::assertSame([
            'VLL-0004 Mollel party · Vivutio Lakeshore Lodge held to Mon 12 Oct today',
            'VRC-0001 Hansen family · Vivutio Riverside Camp held to Mon 12 Oct today',
            'VLL-0001 Savanna Trails Safaris · Vivutio Lakeshore Lodge held to Tue 13 Oct tomorrow',
            'VLL-0010 Lyimo party · Vivutio Lakeshore Lodge held to Wed 14 Oct in 2 days',
            'VLL-0002 Kweka party · Vivutio Lakeshore Lodge held to Thu 15 Oct in 3 days',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip danger', 'chip danger', 'chip danger', 'chip warn', 'chip warn'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));
        self::assertSame('5 of 8', self::words($card->filter('.panel-foot .state')));
        self::assertSame('/properties', $card->filter('.panel-foot a.more')->attr('href'));
        self::assertSame('All bookings', self::words($card->filter('.panel-foot a.more')));
    }

    /** Beyond three days a chip is plain: "in a week" at seven. */
    public function testALaterHoldWearsAPlainChip(): void
    {
        $person = $this->person('Neema', TierEnum::Staff, ['dashboard.read', 'properties.read', 'property_bookings.read'], ['properties.read', 'property_bookings.read'], [$this->lodge]);
        $this->clock()->modify('2026-10-13 09:00');

        $card = $this->card($this->home($person));

        self::assertSame([
            'VLL-0001 Savanna Trails Safaris · Vivutio Lakeshore Lodge held to Tue 13 Oct today',
            'VLL-0010 Lyimo party · Vivutio Lakeshore Lodge held to Wed 14 Oct tomorrow',
            'VLL-0002 Kweka party · Vivutio Lakeshore Lodge held to Thu 15 Oct in 2 days',
            'VLL-0005 Mushi family · Vivutio Lakeshore Lodge held to Sat 17 Oct in 4 days',
            'VLL-0003 Vivutio Tours · Vivutio Lakeshore Lodge held to Mon 19 Oct in 6 days',
        ], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame(['chip danger', 'chip danger', 'chip warn', 'chip', 'chip'], $card->filter('.chip')->each(static fn (Crawler $chip): string => (string) $chip->attr('class')));

        $this->clock()->modify('2026-10-15 09:00');
        $rows = $this->card($this->home($person))->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row));
        self::assertSame('VLL-0003 Vivutio Tours · Vivutio Lakeshore Lodge held to Mon 19 Oct in 4 days', $rows[2]);
        self::assertSame('VLL-0006 Kimaro party · Vivutio Lakeshore Lodge held to Thu 22 Oct in a week', $rows[3]);
    }

    /** A reach limited to one property counts and lists that property's holds alone. */
    public function testAReachLimitedToAPropertySeesItsHoldsAlone(): void
    {
        $page = $this->home($this->person('Neema', TierEnum::Staff, ['dashboard.read', 'properties.read', 'property_bookings.read'], ['properties.read', 'property_bookings.read'], [$this->camp]));

        self::assertSame('Held, to confirm 1 1 lets go today', $this->figure($page));
        $card = $this->card($page);
        self::assertSame(['VRC-0001 Hansen family · Vivutio Riverside Camp held to Mon 12 Oct today'], $card->filter('tbody tr')->each(static fn (Crawler $row): string => self::words($row)));
        self::assertSame('1 of 1', self::words($card->filter('.panel-foot .state')));
    }

    public function testWhatLetsGoTomorrowIsSaidWhenNothingLetsGoToday(): void
    {
        $person = $this->person('Neema', TierEnum::Staff, ['dashboard.read', 'properties.read', 'property_bookings.read'], ['properties.read', 'property_bookings.read'], [$this->camp]);
        $this->clock()->modify('2026-10-11 09:00');

        $page = $this->home($person);

        self::assertSame('Held, to confirm 1 1 lets go tomorrow', $this->figure($page));
        self::assertSame('chip danger', $this->card($page)->filter('.chip')->attr('class'));
        self::assertSame('tomorrow', self::words($this->card($page)->filter('.chip')));
    }

    public function testWithNothingHeldTheCardSaysSo(): void
    {
        $person = $this->person('Neema', TierEnum::Staff, ['dashboard.read', 'properties.read', 'property_bookings.read'], ['properties.read', 'property_bookings.read'], [$this->camp]);
        $this->clock()->modify('2026-10-13 09:00');

        $page = $this->home($person);

        self::assertSame('Held, to confirm 0', $this->figure($page));
        $card = $this->card($page);
        self::assertCount(0, $card->filter('tbody tr'));
        self::assertSame('Nothing is held', self::words($card->filter('[data-card-empty]')));
        self::assertSame('', self::words($card->filter('.panel-foot .state')));
    }

    /** Without the pair, or with a department that does not allow it, there is nothing of it on the dashboard. */
    public function testWithoutThePairThereIsNothingOfIt(): void
    {
        foreach ([
            'not granted' => $this->person('Elia', TierEnum::Staff, ['dashboard.read', 'properties.read'], ['properties.read']),
            'not allowed' => $this->person('Upendo', TierEnum::Staff, ['dashboard.read', 'properties.read', 'property_bookings.read'], ['properties.read']),
        ] as $why => $person) {
            $page = $this->home($person);

            self::assertCount(0, $page->filter('.band .f'), $why);
            self::assertCount(0, $page->filter('[data-cards] .panel'), $why);
        }
    }

    private function bookings(): void
    {
        $properties = static::getContainer()->get(PropertyService::class);
        self::assertInstanceOf(PropertyService::class, $properties);
        $this->lodge = $properties->create('Vivutio Lakeshore Lodge', 'tented_lodge', 'Lake Manyara, Tanzania');
        $this->camp = $properties->create('Vivutio Riverside Camp', 'tented_camp', 'Central Serengeti, Tanzania');
        $savanna = new Partner('Savanna Trails Safaris', PartnerKindEnum::TourOperator, 'KE', 'reservations@savanna-trails.example');
        $this->em()->persist($savanna);
        $this->em()->flush();

        foreach ([
            [$this->lodge, 'VLL-0001', 'Neema Kweka', 'provisional', '2026-10-13', $savanna->getPartnerId()],
            [$this->lodge, 'VLL-0002', 'Kweka party', 'provisional', '2026-10-15', null],
            [$this->lodge, 'VLL-0003', 'Vivutio Tours', 'provisional', '2026-10-19', null],
            [$this->lodge, 'VLL-0004', 'Mollel party', 'provisional', '2026-10-12', null],
            [$this->lodge, 'VLL-0005', 'Mushi family', 'provisional', '2026-10-17', null],
            [$this->lodge, 'VLL-0006', 'Kimaro party', 'provisional', '2026-10-22', null],
            [$this->lodge, 'VLL-0007', 'Lapsed party', 'provisional', '2026-10-11', null],
            [$this->lodge, 'VLL-0008', 'Confirmed party', 'confirmed', null, null],
            [$this->lodge, 'VLL-0009', 'Cancelled party', 'cancelled', null, null],
            [$this->lodge, 'VLL-0010', 'Lyimo party', 'provisional', '2026-10-14', null],
            [$this->camp, 'VRC-0001', 'Hansen family', 'provisional', '2026-10-12', null],
        ] as [$property, $reference, $guest, $status, $heldUntil, $partner]) {
            $booking = (new PropertyBooking($property, $reference, $guest, new \DateTimeImmutable('2026-11-20'), 3, 'USD'))
                ->setStatus(BookingStatusEnum::from($status))
                ->setHeldUntil(null === $heldUntil ? null : new \DateTimeImmutable($heldUntil))
                ->setPartnerTerms($partner, '0.00', 0);
            $this->em()->persist($booking);
        }
        $this->em()->flush();
    }

    /**
     * @param list<string>|null $grants a position's pairs, or null for no position
     * @param list<string>      $allows what the person's department allows of the module
     * @param list<Property>    $reach  the properties chosen as the person's reach, or none for the whole organization
     */
    private function person(string $name, TierEnum $tier, ?array $grants = null, array $allows = [], array $reach = []): User
    {
        $department = null;
        if ([] !== $allows) {
            $department = (new Department())->setName($name.'\'s department')->setAllows($allows);
            $this->em()->persist($department);
        }
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
        if ([] !== $reach) {
            $this->em()->persist((new PropertyReach($user))->setReach(ReachEnum::Chosen)->setProperties(array_map(static fn (Property $property): string => (string) $property->getUuid(), $reach)));
        }
        $this->em()->flush();

        return $user;
    }

    private function home(User $user): Crawler
    {
        $this->browser->restart();
        $this->browser->loginUser($user);
        $page = $this->browser->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSame('organization', $page->filter('[data-dashboard]')->attr('data-dashboard'));

        return $page;
    }

    private function figure(Crawler $page): string
    {
        $figure = $page->filter('.band .f')->reduce(static fn (Crawler $f): bool => 'Held, to confirm' === self::words($f->filter('.k')));
        self::assertCount(1, $figure);

        return self::words($figure);
    }

    private function card(Crawler $page): Crawler
    {
        $card = $page->filter('[data-cards] .panel')->reduce(static fn (Crawler $panel): bool => 'Held, to confirm' === self::words($panel->filter('.panel-head h2')));
        self::assertCount(1, $card);

        return $card;
    }

    /** The words of a node, each element's own apart, as a reader sees them. */
    private static function words(Crawler $node): string
    {
        $words = [];
        foreach ($node as $element) {
            foreach ((new \DOMXPath($element->ownerDocument ?? new \DOMDocument()))->query('.//text()', $element) ?: [] as $text) {
                if ($text instanceof \DOMText) {
                    $words[] = trim((string) preg_replace('/\s+/', ' ', $text->textContent));
                }
            }
        }

        return implode(' ', array_filter($words, static fn (string $word): bool => '' !== $word));
    }

    private function clock(): MockClock
    {
        $clock = static::getContainer()->get('clock');
        self::assertInstanceOf(MockClock::class, $clock);

        return $clock;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }
}
