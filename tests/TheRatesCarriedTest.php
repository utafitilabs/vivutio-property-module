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
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\Rate;
use Vivutio\Property\Entity\RateTerms;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A year's rates carried into the next: each rate and each period's terms
 * onto the same season's period a year later, raised by a share if asked,
 * leaving whatever is set there already.
 */
final class TheRatesCarriedTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Property $lodge;
    private RoomType $tented;
    private RoomType $family;
    private SeasonPeriod $high;
    private SeasonPeriod $green;

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

    public function testAYearsRatesAndTermsAreCarriedIntoTheNextRaisedByAShare(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);
        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '290', $this->cell($this->tented, $this->green) => '210', $this->cell($this->family, $this->high) => '450']);
        $this->rateService()->saveTerms($this->property('Vivutio Lakeshore Lodge'), [(string) $this->high->getUuid() => ['single' => '140']]);
        $this->repeatSeasons(2026);

        $page = $this->browser->request('GET', $this->ratesOf(2026));
        $this->browser->submit($page->selectButton('Carry 2026 into 2027')->form(['raise' => '5']));

        self::assertResponseRedirects($this->ratesOf(2027));
        $tab = $this->browser->followRedirect();
        self::assertStringContainsString('3 rates of 2026 were carried into 2027, raised by 5%.', $tab->filter('.notice')->text());
        self::assertSame(['304.50', '220.50'], $this->amounts('Tented Room', 2027));
        self::assertSame(['472.50'], array_values(array_filter($this->amounts('Family Tent', 2027))));
        $nextHigh = $this->period('High Season', '2027-06-01');
        $terms = $this->em()->getRepository(RateTerms::class)->findOneBy(['period' => $nextHigh]);
        self::assertInstanceOf(RateTerms::class, $terms);
        self::assertSame(140, $terms->share('single'));
    }

    /** What is set already stays: carrying twice changes nothing, and a cell set by hand is kept. */
    public function testWhatIsSetAlreadyIsLeft(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);
        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '290', $this->cell($this->tented, $this->green) => '210']);
        $this->repeatSeasons(2026);
        $this->rateService()->saveRates($this->property('Vivutio Lakeshore Lodge'), BoardBasisEnum::FullBoard, [(string) $this->tented->getUuid() => [(string) $this->period('High Season', '2027-06-01')->getUuid() => '350']]);

        $carried = $this->rateService()->carry($this->property('Vivutio Lakeshore Lodge'), 2026, '0');
        self::assertSame(1, $carried->copied);
        self::assertSame(1, $carried->left);
        self::assertSame(['350.00', '210.00'], $this->amounts('Tented Room', 2027));

        self::assertSame(0, $this->rateService()->carry($this->property('Vivutio Lakeshore Lodge'), 2026, '0')->copied);
        self::assertStringContainsString('2027 has them already', $this->rateService()->carry($this->property('Vivutio Lakeshore Lodge'), 2026, '0')->says());
    }

    /** A period with no match a year later is said, so the season can be repeated first. */
    public function testWithoutTheNextYearsPeriodsNothingIsCarried(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);
        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '290']);

        $carried = $this->rateService()->carry($this->property('Vivutio Lakeshore Lodge'), 2026, '0');

        self::assertSame(0, $carried->copied);
        self::assertStringContainsString('repeat 2026 on the Seasons tab first', $carried->says());
    }

    public function testARaiseIsAShareFrom0To100(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);
        $this->repeatSeasons(2026);

        $page = $this->browser->request('GET', $this->ratesOf(2026));
        $form = $page->selectButton('Carry 2026 into 2027')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues(['raise' => 'ten']));

        self::assertResponseStatusCodeSame(422);
        self::assertSame('raise', $page->filter('.field.wrong input')->attr('name'));
    }

    private function rateService(): RateService
    {
        $service = static::getContainer()->get(RateService::class);
        self::assertInstanceOf(RateService::class, $service);

        return $service;
    }

    private function repeatSeasons(int $year): void
    {
        $seasons = static::getContainer()->get(SeasonService::class);
        self::assertInstanceOf(SeasonService::class, $seasons);
        $seasons->repeat($this->lodge, $year);
    }

    private function period(string $season, string $starts): SeasonPeriod
    {
        foreach ($this->em()->getRepository(SeasonPeriod::class)->findAll() as $period) {
            if ($period->getSeason()->getName() === $season && $period->getStarts()->format('Y-m-d') === $starts) {
                return $period;
            }
        }
        self::fail('No '.$season.' from '.$starts);
    }

    /**
     * The room type's full-board amounts in a year, by its periods in order.
     *
     * @return list<string>
     */
    private function amounts(string $room, int $year): array
    {
        $this->em()->clear();
        $found = [];
        foreach ($this->em()->getRepository(Rate::class)->findAll() as $rate) {
            if ($rate->getRoomType()->getName() === $room && (int) $rate->getPeriod()->getStarts()->format('Y') === $year) {
                $found[$rate->getPeriod()->getStarts()->format('Y-m-d')] = $rate->getAmount();
            }
        }
        ksort($found);

        return array_values($found);
    }

    private function ratesOf(int $year): string
    {
        return '/properties/'.$this->lodge->getUuid().'/rates?year='.$year;
    }

    private function lodgeWithRoomsAndSeasons(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->lodge = $this->add('Vivutio Lakeshore Lodge');
        $rooms = static::getContainer()->get(RoomTypeService::class);
        self::assertInstanceOf(RoomTypeService::class, $rooms);
        $this->tented = $rooms->create($this->lodge, new RoomTypeDetails(name: 'Tented Room', sleeps: '2', adults: '2', count: '20'));
        $this->family = $rooms->create($this->lodge, new RoomTypeDetails(name: 'Family Tent', sleeps: '4', adults: '3', count: '4'));
        $seasons = static::getContainer()->get(SeasonService::class);
        self::assertInstanceOf(SeasonService::class, $seasons);
        $this->high = $seasons->addPeriod($seasons->create($this->lodge, 'High Season', 'high'), '2026-06-01', '2026-10-31');
        $this->green = $seasons->addPeriod($seasons->create($this->lodge, 'Green Season', 'low'), '2026-11-01', '2027-05-31');
    }

    private function rates(?int $year = null): string
    {
        return '/properties/'.$this->lodge->getUuid().'/rates'.(null === $year ? '' : '?year='.$year);
    }

    private function cell(RoomType $room, SeasonPeriod $period): string
    {
        return 'amounts['.$room->getUuid().']['.$period->getUuid().']';
    }

    /**
     * @param list<string> $boards
     */
    private function setUpTheSheet(string $currency, string $pricing, array $boards, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', $this->rates());
        $form = $page->selectButton('Save the rate sheet')->form();
        $form->disableValidation();
        $form->setValues(['currency' => $currency, 'pricing' => $pricing]);
        $values = $form->getPhpValues();
        $values['boards'] = $boards;
        $page = $this->browser->request('POST', (string) $form->getUri(), $values);
        self::assertResponseStatusCodeSame($answered);

        return $page;
    }

    /**
     * @param array<string, string> $cells
     */
    private function sheet(string $board, array $cells, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', $this->rates(2026));
        $form = $page->filter('[data-sheet="'.$board.'"]')->selectButton('Save the rates')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues($cells));
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
