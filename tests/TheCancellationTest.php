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
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Service\RateService;
use Vivutio\Property\Service\RoomTypeService;
use Vivutio\Property\Service\SeasonService;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * Cancelling: the property's tiers and each season's (its property's, its
 * own, or no charge) set on the cancellation page, said as bands on the Rates
 * tab, and what cancelling a stay costs beside what it costs.
 */
final class TheCancellationTest extends WebTestCase
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

    public function testThePropertysTiersAndEachSeasonsAreSetAndSaidAsBands(): void
    {
        $this->pricedLodge();

        $this->card('property', ['tiers[0][days]' => '60', 'tiers[0][percent]' => '20', 'tiers[1][days]' => '44', 'tiers[1][percent]' => '50', 'tiers[2][days]' => '29', 'tiers[2][percent]' => '100']);
        $this->card('Green Season', ['mode' => 'no_charge']);
        $this->card('High Season', ['mode' => 'own', 'tiers[0][days]' => '30', 'tiers[0][percent]' => '100']);

        $tab = $this->browser->request('GET', $this->rates());
        self::assertSame(['Free more than 60 days before arrival', '20% from 60 to 45 days before', '50% from 44 to 30 days before', '100% from 29 days before, and after arrival'], $tab->filter('[data-policy="property"] li')->each(static fn (Crawler $band): string => trim($band->text())));
        self::assertSame(['No charge to cancel'], $tab->filter('[data-policy="Green Season"] li')->each(static fn (Crawler $band): string => trim($band->text())));
        self::assertSame(['Free more than 30 days before arrival', '100% from 30 days before, and after arrival'], $tab->filter('[data-policy="High Season"] li')->each(static fn (Crawler $band): string => trim($band->text())));

        $this->card('High Season', ['mode' => 'follow']);
        self::assertStringContainsString("The property's", $this->browser->request('GET', $this->rates())->filter('[data-policy="High Season"]')->text());
    }

    public function testWhatCancellingCostsIsSaidBesideWhatTheStayCosts(): void
    {
        $this->pricedLodge();
        $this->card('property', ['tiers[0][days]' => '60', 'tiers[0][percent]' => '20', 'tiers[1][days]' => '29', 'tiers[1][percent]' => '100']);
        $this->card('Green Season', ['mode' => 'no_charge']);

        $tab = $this->browser->request('GET', $this->rates().'&room='.$this->tented->getUuid().'&board=full_board&arrival=2026-10-30&nights=3&adults=2&children=0&infants=0&cancelled=2026-09-15');

        self::assertSame('USD 1,580.00', trim($tab->filter('[data-total]')->text()));
        self::assertSame('USD 232.00', trim($tab->filter('[data-cancel-total]')->text()));
        self::assertStringContainsString('45 days before arrival', $tab->filter('[data-cancel]')->text());
    }

    public function testTiersThatContradictThemselvesAreRefusedInTheirCard(): void
    {
        $this->pricedLodge();

        $page = $this->card('property', ['tiers[0][days]' => '60', 'tiers[0][percent]' => '50', 'tiers[1][days]' => '30', 'tiers[1][percent]' => '20'], 422);

        self::assertStringContainsString('charges less', $page->filter('[data-card="property"] .notice.danger')->text());
    }

    public function testStaffWhoReadPropertiesSeeTheBandsAndChangeNone(): void
    {
        $this->pricedLodge();
        $this->card('property', ['tiers[0][days]' => '30', 'tiers[0][percent]' => '100']);
        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $this->signedInAs($this->person('Amani', TierEnum::Staff, ['properties.read'], $reception));

        $tab = $this->browser->request('GET', $this->rates());
        self::assertCount(2, $tab->filter('[data-policy="property"] li'));
        self::assertCount(0, $tab->selectLink('Configure cancellation'));
        $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/cancellation');
        self::assertResponseStatusCodeSame(403);
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

    private function rates(): string
    {
        return '/properties/'.$this->lodge->getUuid().'/rates?year=2026';
    }

    /**
     * Saves one card of the cancellation page: the property's, or a season's by its name.
     *
     * @param array<string, string> $values
     */
    private function card(string $card, array $values, int $answered = 302): Crawler
    {
        $page = $this->browser->request('GET', '/properties/'.$this->lodge->getUuid().'/cancellation');
        self::assertResponseIsSuccessful();
        $form = $page->filter('form[data-card="'.$card.'"]')->filter('button[type="submit"]')->form();
        $form->disableValidation();
        $page = $this->browser->submit($form->setValues($values));
        self::assertResponseStatusCodeSame($answered);
        if (302 === $answered) {
            self::assertResponseRedirects('/properties/'.$this->lodge->getUuid().'/cancellation');
        }

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
