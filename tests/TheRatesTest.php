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
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A property's Rates tab: its currency, whether it prices per person sharing
 * or per room and the board bases it sells; a year's rate sheet, room types
 * down and season periods across; each period's terms; and what a stay costs.
 */
final class TheRatesTest extends WebTestCase
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

    public function testRatesAreSetUpBeforeTheyAreSet(): void
    {
        $this->lodgeWithRoomsAndSeasons();

        $tab = $this->browser->request('GET', $this->rates());
        self::assertSame('Rates', trim($tab->filter('nav.tabs a[aria-current="page"]')->text()));
        self::assertStringContainsString('Choose the currency', $tab->filter('.notice.warn')->text());
        self::assertCount(0, $tab->filter('[data-sheet]'));

        $this->setUpTheSheet('USD', 'per_person', ['full_board', 'half_board']);

        $tab = $this->browser->request('GET', $this->rates(2026));
        self::assertSame(['half_board', 'full_board'], $tab->filter('[data-sheet]')->each(static fn (Crawler $sheet): string => (string) $sheet->attr('data-sheet')));
        self::assertSame(['Tented Room', 'Family Tent'], $tab->filter('[data-sheet="full_board"] tbody tr')->each(static fn (Crawler $row): string => (string) $row->attr('data-room')));
        self::assertSame(['High Season 1 Jun – 31 Oct 2026', 'Green Season 1 Nov 2026 – 31 May 2027'], $tab->filter('[data-sheet="full_board"] thead th[data-period]')->each(static fn (Crawler $cell): string => trim((string) preg_replace('/\s+/', ' ', $cell->text()))));
    }

    public function testATierSetsAYearsRatesAndEveryoneWhoReadsSeesThem(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);

        $this->sheet('full_board', [
            $this->cell($this->tented, $this->high) => '290',
            $this->cell($this->tented, $this->green) => '210',
            $this->cell($this->family, $this->high) => '450',
        ]);

        self::assertCount(3, $this->em()->getRepository(Rate::class)->findAll());
        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $this->signedInAs($this->person('Amani', TierEnum::Staff, ['properties.read'], $reception));

        $tab = $this->browser->request('GET', $this->rates(2026));
        self::assertCount(0, $tab->filter('form[method="post"]'));
        $row = $tab->filter('[data-sheet="full_board"] tr[data-room="Tented Room"]');
        self::assertSame(['USD 290.00', 'USD 210.00'], $row->filter('td[data-rate]')->each(static fn (Crawler $cell): string => trim($cell->text())));
        self::assertSame(['USD 450.00', 'No rate'], $tab->filter('[data-sheet="full_board"] tr[data-room="Family Tent"] td[data-rate]')->each(static fn (Crawler $cell): string => trim($cell->text())));
    }

    /** A rate is an amount of money, at most to the cent; an empty cell has no rate. */
    public function testARateIsAnAmountOfMoney(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);
        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '290']);

        foreach (['two hundred', '-5', '290.555'] as $typed) {
            $page = $this->sheet('full_board', [$this->cell($this->tented, $this->high) => $typed], 422);
            self::assertSame($this->cell($this->tented, $this->high), $page->filter('.field.wrong input, td.wrong input')->attr('name'), $typed);
        }

        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '']);
        self::assertCount(0, $this->em()->getRepository(Rate::class)->findAll());
    }

    public function testEachPeriodHasItsTermsAndAStayIsPricedOnTheTab(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);
        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '290', $this->cell($this->tented, $this->green) => '210']);

        $page = $this->browser->request('GET', $this->rates(2026));
        $this->browser->submit($page->selectButton('Save the terms')->form([
            'terms['.$this->high->getUuid().'][single]' => '140',
            'terms['.$this->high->getUuid().'][third_adult]' => '70',
            'terms['.$this->high->getUuid().'][child_sharing]' => '50',
        ]));
        self::assertResponseRedirects($this->rates(2026));

        $tab = $this->browser->request('GET', $this->rates(2026).'&room='.$this->tented->getUuid().'&board=full_board&arrival=2026-10-30&nights=3&adults=2&children=0&infants=0');
        $quote = $tab->filter('[data-quote]');
        self::assertSame('USD 1,580.00', trim($quote->filter('[data-total]')->text()));
        self::assertSame(['30 Oct 2026', '31 Oct 2026', '1 Nov 2026'], $quote->filter('[data-night]')->each(static fn (Crawler $night): string => (string) $night->attr('data-night')));

        $tab = $this->browser->request('GET', $this->rates(2026).'&room='.$this->tented->getUuid().'&board=full_board&arrival=2026-03-01&nights=1&adults=2&children=0&infants=0');
        self::assertStringContainsString('1 Mar 2026 is in no season', $tab->filter('[data-quote]')->text());
    }

    /** What its rates mean is not changed under them, and a period that is priced stays. */
    public function testPricedRatesKeepTheirMeaningAndTheirPeriods(): void
    {
        $this->lodgeWithRoomsAndSeasons();
        $this->setUpTheSheet('USD', 'per_person', ['full_board', 'half_board']);
        $this->sheet('full_board', [$this->cell($this->tented, $this->high) => '290']);

        foreach ([['EUR', 'per_person', ['full_board', 'half_board'], 'currency'], ['USD', 'per_room', ['full_board', 'half_board'], 'pricing'], ['USD', 'per_person', ['half_board'], 'boards']] as [$currency, $pricing, $boards, $field]) {
            $page = $this->setUpTheSheet($currency, $pricing, $boards, 422);
            self::assertSame($field, rtrim((string) $page->filter('.field.wrong')->filter('input, select')->attr('name'), '[]'), $field);
        }
        $this->setUpTheSheet('USD', 'per_person', ['full_board']);

        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/seasons/'.$this->high->getSeason()->getUuid().'/configure');
        $page = $this->browser->submit($page->filter('[data-period="2026-06-01"]')->selectButton('Remove')->form());
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('1 rate', $page->filter('.notice.danger')->text());
    }

    public function testACurrencyIsThreeLetters(): void
    {
        $this->lodgeWithRoomsAndSeasons();

        $page = $this->setUpTheSheet('dollars', 'per_person', ['full_board'], 422);
        self::assertSame('currency', $page->filter('.field.wrong input')->attr('name'));
        $page = $this->setUpTheSheet('USD', 'per_person', [], 422);
        self::assertSame('boards', rtrim((string) $page->filter('.field.wrong input')->attr('name'), '[]'));
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
