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
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\AvailabilityService;
use Vivutio\Property\Service\CancellationService;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A property at a glance, on its Overview: from what a night costs, the season
 * tonight and the next, the next closure and the cancellation terms, each
 * leading to its tab; and what each says while it is not set.
 */
final class TheOverviewTest extends WebTestCase
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

    public function testTheOverviewSaysWhatTheOtherTabsHold(): void
    {
        $this->pricedLodge();
        $availability = static::getContainer()->get(AvailabilityService::class);
        self::assertInstanceOf(AvailabilityService::class, $availability);
        $availability->close($this->lodge, '', '', '2027-04-01', '2027-05-31', 'Long rains');
        $cancellation = static::getContainer()->get(CancellationService::class);
        self::assertInstanceOf(CancellationService::class, $cancellation);
        $cancellation->changeProperty($this->fresh(), [['60', '20'], ['29', '100']]);

        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid());
        $glance = $page->filter('[data-glance]');

        self::assertSame('From USD 210.00 a night, per person sharing, full board', trim($glance->filter('[data-glance-from]')->text()));
        self::assertSame('High Season, until 31 Oct 2026', trim($glance->filter('[data-glance-tonight]')->text()));
        self::assertSame('Green Season, from 1 Nov 2026', trim($glance->filter('[data-glance-next]')->text()));
        self::assertSame('Long rains, 1 Apr – 31 May 2027', trim($glance->filter('[data-glance-closure]')->text()));
        self::assertSame('Free more than 60 days before arrival', trim($glance->filter('[data-glance-cancellation]')->text()));
        self::assertSame('/properties/'.$this->lodge->getUuid().'/rates', $glance->filter('[data-glance-from]')->closest('.fact')?->filter('a')->attr('href'));
    }

    public function testWhatIsNotSetIsSaidSo(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->lodge = $this->add('Vivutio Lakeshore Lodge');

        $glance = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid())->filter('[data-glance]');

        self::assertSame('No rates yet', trim($glance->filter('[data-glance-from]')->text()));
        self::assertSame('In no season', trim($glance->filter('[data-glance-tonight]')->text()));
        self::assertSame('No season ahead', trim($glance->filter('[data-glance-next]')->text()));
        self::assertSame('Nothing closed ahead', trim($glance->filter('[data-glance-closure]')->text()));
        self::assertSame('No charge to cancel', trim($glance->filter('[data-glance-cancellation]')->text()));
    }

    /** A closure under way tonight is the one said. */
    public function testAClosureUnderWayIsSaid(): void
    {
        $this->pricedLodge();
        $availability = static::getContainer()->get(AvailabilityService::class);
        self::assertInstanceOf(AvailabilityService::class, $availability);
        $availability->close($this->lodge, (string) $this->tented->getUuid(), '2', '2026-06-28', '2026-07-03', 'Canvas repairs');
        $availability->close($this->lodge, '', '', '2027-04-01', '2027-05-31', 'Long rains');

        $glance = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid())->filter('[data-glance]');

        self::assertSame('Canvas repairs, until 3 Jul 2026', trim($glance->filter('[data-glance-closure]')->text()));
    }

    private function fresh(): Property
    {
        return $this->property('Vivutio Lakeshore Lodge');
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
