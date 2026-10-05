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
use Vivutio\Property\Entity\Season;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A property's seasons: each named, of a kind, with the dated periods it
 * runs; no night in two seasons; drawn as a year of months on the Seasons
 * tab, and repeated a year later in one step.
 */
final class TheSeasonsTest extends WebTestCase
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

    public function testATierAddsSeasonsAndTheirPeriodsAndTheTabDrawsTheYear(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $green = $this->addSeason($lodge, 'Green Season', 'low');
        $high = $this->addSeason($lodge, 'High Season', 'high');
        $this->addPeriod($high, '2026-06-01', '2026-10-31');
        $this->addPeriod($green, '2026-11-01', '2027-05-31');

        $tab = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/seasons?year=2026');

        self::assertResponseIsSuccessful();
        self::assertSame('Seasons', trim($tab->filter('nav.tabs a[aria-current="page"]')->text()));
        self::assertSame(['High Season', 'Green Season'], $tab->filter('tr[data-season]')->each(static fn (Crawler $row): string => (string) $row->attr('data-season')), 'the busiest first');
        self::assertSame('1 Jun – 31 Oct 2026', trim($tab->filter('tr[data-season="High Season"] [data-periods]')->text()));
        self::assertSame('1 Nov 2026 – 31 May 2027', trim($tab->filter('tr[data-season="Green Season"] [data-periods]')->text()));
        self::assertSame('153', trim($tab->filter('tr[data-season="High Season"] [data-nights]')->text()));
        self::assertSame('61', trim($tab->filter('tr[data-season="Green Season"] [data-nights]')->text()));

        self::assertSame('High Season', $tab->filter('[data-day="2026-06-01"]')->attr('data-season'));
        self::assertSame('Green Season', $tab->filter('[data-day="2026-12-31"]')->attr('data-season'));
        self::assertNull($tab->filter('[data-day="2026-03-01"]')->attr('data-season'));
        self::assertSame(365, $tab->filter('[data-day^="2026-"]')->count());
        self::assertStringContainsString('151 nights of 2026 are in no season', $tab->filter('.notice.warn')->text());
        self::assertSame('/properties/'.$lodge->getUuid().'/seasons?year=2027', $tab->filter('.intro')->selectLink('2027')->attr('href'));
    }

    /** A night is in one season at most, so a rate is never in doubt. */
    public function testPeriodsNeverOverlapAndEndAfterTheyStart(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $high = $this->addSeason($lodge, 'High Season', 'high');
        $green = $this->addSeason($lodge, 'Green Season', 'low');
        $this->addPeriod($high, '2026-06-01', '2026-10-31');

        foreach ([
            [$green, '2026-10-31', '2027-05-31', 'starts', 'High Season'],
            [$high, '2026-09-01', '2026-11-30', 'starts', 'High Season'],
            [$green, '2027-05-31', '2027-03-01', 'ends', 'after it starts'],
            [$green, '2026-11-01', '2028-05-31', 'ends', 'a year'],
            [$green, 'the first of November', '2027-05-31', 'starts', 'date'],
        ] as [$season, $starts, $ends, $field, $said]) {
            $page = $this->configurePage($season);
            $page = $this->browser->submit($page->selectButton('Add the period')->form(['starts' => $starts, 'ends' => $ends]));

            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, $page->filter('.field.wrong input')->attr('name'), $starts.' to '.$ends);
            self::assertStringContainsString($said, $page->filter('.field.wrong')->text());
        }

        self::assertCount(1, $this->em()->getRepository(SeasonPeriod::class)->findAll());
    }

    public function testASeasonIsNamedOnceInItsPropertyAndIsOfAKind(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $this->addSeason($lodge, 'High Season', 'high');

        foreach ([['high season', 'high', 'name'], ['Peak Season', 'scorching', 'kind']] as [$name, $kind, $field]) {
            $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/seasons');
            $form = $page->selectButton('Add the season')->form();
            $form->disableValidation();
            $page = $this->browser->submit($form->setValues(['name' => $name, 'kind' => $kind]));

            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'));
        }

        $this->addSeason($this->add('Vivutio Riverside Camp'), 'High Season', 'high');
        self::assertCount(2, $this->em()->getRepository(Season::class)->findBy(['name' => 'High Season']));
    }

    /** The yearly chore in one step: a year's periods again a year later, leaving out any that would overlap. */
    public function testRepeatingAYearCopiesItsPeriodsAYearLater(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $high = $this->addSeason($lodge, 'High Season', 'high');
        $green = $this->addSeason($lodge, 'Green Season', 'low');
        $this->addPeriod($high, '2026-06-01', '2026-10-31');
        $this->addPeriod($green, '2026-11-01', '2027-05-31');

        $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/seasons?year=2026');
        $this->browser->submit($page->selectButton('Repeat 2026 in 2027')->form());
        self::assertResponseRedirects('/properties/'.$lodge->getUuid().'/seasons?year=2027');
        $tab = $this->browser->followRedirect();
        self::assertStringContainsString('2 periods of 2026 were repeated in 2027.', $tab->filter('.notice')->text());
        self::assertSame('1 Jun – 31 Oct 2026, 1 Jun – 31 Oct 2027', trim($tab->filter('tr[data-season="High Season"] [data-periods]')->text()));
        self::assertSame('1 Nov 2026 – 31 May 2027, 1 Nov 2027 – 31 May 2028', trim($tab->filter('tr[data-season="Green Season"] [data-periods]')->text()));

        $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/seasons?year=2026');
        $this->browser->submit($page->selectButton('Repeat 2026 in 2027')->form());
        self::assertStringContainsString('2027 has them already', $this->browser->followRedirect()->filter('.notice')->text());
        self::assertCount(4, $this->em()->getRepository(SeasonPeriod::class)->findAll());
    }

    /** A period is removed; a season is removed once it has none. */
    public function testPeriodsAndSeasonsAreRemoved(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $high = $this->addSeason($lodge, 'High Season', 'high');
        $this->addPeriod($high, '2026-06-01', '2026-10-31');

        $page = $this->configurePage($high);
        self::assertCount(0, $page->selectButton('Remove the season'), 'not while it has periods');
        $this->browser->submit($page->filter('[data-period="2026-06-01"]')->selectButton('Remove')->form());
        self::assertResponseRedirects('/properties/'.$lodge->getUuid().'/seasons/'.$high->getUuid().'/configure');

        $page = $this->browser->followRedirect();
        $this->browser->submit($page->selectButton('Remove the season')->form());
        self::assertResponseRedirects('/properties/'.$lodge->getUuid().'/seasons');
        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(Season::class)->findAll());
    }

    public function testASeasonIsRenamedAndChangesKind(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $season = $this->addSeason($lodge, 'High Season', 'high');

        $page = $this->configurePage($season);
        $this->browser->submit($page->selectButton('Save season')->form(['name' => 'Migration Season', 'kind' => 'peak']));

        self::assertResponseRedirects('/properties/'.$lodge->getUuid().'/seasons/'.$season->getUuid().'/configure');
        $this->em()->clear();
        $season = $this->em()->getRepository(Season::class)->findOneBy(['name' => 'Migration Season']);
        self::assertInstanceOf(Season::class, $season);
        self::assertSame('peak', $season->getKind()->value);
    }

    public function testStaffWhoReadPropertiesSeeTheSeasonsAndChangeNone(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $high = $this->addSeason($lodge, 'High Season', 'high');

        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $this->signedInAs($this->person('Amani', TierEnum::Staff, ['properties.read'], $reception));

        $tab = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/seasons');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tab->filter('tr[data-season]'));
        self::assertCount(0, $tab->selectButton('Add the season'));
        self::assertCount(0, $tab->filter('form'));
        $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/seasons/'.$high->getUuid().'/configure');
        self::assertResponseStatusCodeSame(403);
    }

    public function testASeasonIsNotReachedThroughAnotherProperty(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $season = $this->addSeason($this->add('Vivutio Lakeshore Lodge'), 'High Season', 'high');
        $other = $this->add('Vivutio Riverside Camp');

        $this->browser->request('GET', '/properties/'.$other->getUuid().'/seasons/'.$season->getUuid().'/configure');
        self::assertResponseStatusCodeSame(404);
    }

    private function addSeason(Property $property, string $name, string $kind): Season
    {
        $page = $this->browser->request('GET', '/properties/'.$property->getUuid().'/seasons');
        $this->browser->submit($page->selectButton('Add the season')->form(['name' => $name, 'kind' => $kind]));

        $this->em()->clear();
        $season = $this->em()->getRepository(Season::class)->findOneBy(['name' => $name, 'property' => $this->property($property->getName())]);
        self::assertInstanceOf(Season::class, $season);
        self::assertResponseRedirects('/properties/'.$property->getUuid().'/seasons/'.$season->getUuid().'/configure');

        return $season;
    }

    private function addPeriod(Season $season, string $starts, string $ends): void
    {
        $page = $this->configurePage($season);
        $this->browser->submit($page->selectButton('Add the period')->form(['starts' => $starts, 'ends' => $ends]));
        self::assertResponseRedirects('/properties/'.$season->getProperty()->getUuid().'/seasons/'.$season->getUuid().'/configure');
    }

    private function configurePage(Season $season): Crawler
    {
        $page = $this->browser->request('GET', '/properties/'.$season->getProperty()->getUuid().'/seasons/'.$season->getUuid().'/configure');
        self::assertResponseIsSuccessful();

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
