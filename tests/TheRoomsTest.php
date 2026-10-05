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
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * A property's room types: what each sleeps, how many there are and what is
 * in them; listed on the property's Rooms tab, added and changed by the tiers,
 * withdrawn from sale rather than deleted.
 */
final class TheRoomsTest extends WebTestCase
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

    public function testATierAddsRoomTypesAndTheRoomsTabListsThemSmallestFirst(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');

        $this->addRoom($lodge, ['name' => 'Family Tent', 'sleeps' => '4', 'adults' => '2', 'count' => '4'], ['en_suite']);
        $this->addRoom($lodge, ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '20', 'description' => 'Twin or double, under the acacias.'], ['en_suite', 'mosquito_net']);

        $tab = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/rooms');
        self::assertResponseIsSuccessful();
        self::assertSame('Rooms', trim($tab->filter('nav.tabs a[aria-current="page"]')->text()));
        self::assertSame(['Tented Room', 'Family Tent'], $tab->filter('tr[data-room]')->each(static fn (Crawler $row): string => (string) $row->attr('data-room')));
        $row = $tab->filter('tr[data-room="Tented Room"]');
        self::assertSame('20', trim($row->filter('[data-count]')->text()));
        self::assertSame('Sleeps 2', trim($row->filter('[data-sleeps]')->text()));
        self::assertSame('En-suite bathroom · Mosquito net', trim($row->filter('[data-features]')->text()));
        self::assertSame('Sleeps 4, 2 adults at most', trim($tab->filter('tr[data-room="Family Tent"] [data-sleeps]')->text()));

        $overview = $this->browser->request('GET', '/properties/'.$lodge->getUuid());
        self::assertSame('Overview', trim($overview->filter('nav.tabs a[aria-current="page"]')->text()));
        self::assertSame('24 units, sleeping 56', trim((string) preg_replace('/\s+/', ' ', $overview->filter('[data-units]')->text())));
        self::assertSame('24 units', trim($this->browser->request('GET', '/properties')->filter('tr[data-property="Vivutio Lakeshore Lodge"] [data-units]')->text()));
    }

    /** Each refusal is said beside the field it is about, and nothing is added. */
    public function testWhatARoomTypeCannotBeIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $this->addRoom($lodge, ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '20']);

        foreach ([
            ['name', ['name' => 'tented room']],
            ['sleeps', ['sleeps' => '0']],
            ['adults', ['sleeps' => '2', 'adults' => '3']],
            ['adults', ['adults' => '0']],
            ['count', ['count' => '0']],
            ['features', ['features' => ['a_moat']]],
        ] as [$field, $changed]) {
            $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/rooms');
            $form = $page->selectButton('Add the room type')->form();
            $form->disableValidation();
            $values = [...['name' => 'Family Tent', 'sleeps' => '4', 'adults' => '2', 'count' => '4'], ...$changed];
            $features = $values['features'] ?? [];
            unset($values['features']);
            $form->setValues($values);
            $page = $this->browser->request('POST', (string) $form->getUri(), [...$form->getPhpValues(), 'features' => $features]);

            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, rtrim((string) $page->filter('.field.wrong')->filter('input, select, textarea')->attr('name'), '[]'), $field);
        }

        self::assertCount(1, $this->em()->getRepository(RoomType::class)->findAll());
    }

    /** A room type's name is once in its property; two properties may each have a Tented Room. */
    public function testTwoPropertiesMayEachHaveTheSameRoomType(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        foreach (['Vivutio Lakeshore Lodge', 'Vivutio Riverside Camp'] as $name) {
            $this->addRoom($this->add($name), ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '10']);
        }

        self::assertCount(2, $this->em()->getRepository(RoomType::class)->findBy(['name' => 'Tented Room']));
    }

    /** A withdrawn room type is no longer sold or counted, and stays listed with its history. */
    public function testAWithdrawnRoomTypeStaysListedAndStopsCounting(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $this->addRoom($lodge, ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '20']);
        $family = $this->addRoom($lodge, ['name' => 'Family Tent', 'sleeps' => '4', 'adults' => '2', 'count' => '4']);

        $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/rooms/'.$family->getUuid().'/configure');
        $this->browser->submit($page->selectButton('Save room type')->form(['name' => 'Family Tent', 'count' => '5', 'on_sale' => 'no']));
        self::assertResponseRedirects('/properties/'.$lodge->getUuid().'/rooms');

        $tab = $this->browser->followRedirect();
        self::assertSame('Withdrawn', trim($tab->filter('tr[data-room="Family Tent"] [data-on-sale]')->text()));
        self::assertSame('5', trim($tab->filter('tr[data-room="Family Tent"] [data-count]')->text()));
        self::assertSame('20 units, sleeping 40', trim((string) preg_replace('/\s+/', ' ', $this->browser->request('GET', '/properties/'.$lodge->getUuid())->filter('[data-units]')->text())));
    }

    /** Nothing can be booked at a property without a room type on sale: it does not open without one, and its last one is not withdrawn while it is open. */
    public function testAPropertyOpensOnlyWithSomethingToBook(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');

        $page = $this->changeStatus($lodge, 'open');
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('no room type on sale', $page->filter('.field.wrong')->text());

        $room = $this->addRoom($lodge, ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '20']);
        $this->changeStatus($lodge, 'open');
        self::assertResponseRedirects('/properties/'.$lodge->getUuid().'/configure');

        $page = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/rooms/'.$room->getUuid().'/configure');
        $page = $this->browser->submit($page->selectButton('Save room type')->form(['on_sale' => 'no']));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('on_sale', $page->filter('.field.wrong select')->attr('name'));
    }

    public function testStaffWhoReadPropertiesSeeTheRoomsAndChangeNone(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');
        $room = $this->addRoom($lodge, ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '20']);

        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $this->signedInAs($this->person('Amani', TierEnum::Staff, ['properties.read'], $reception));

        $tab = $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/rooms');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $tab->filter('tr[data-room]'));
        self::assertCount(0, $tab->selectButton('Add the room type'));
        $this->browser->request('GET', '/properties/'.$lodge->getUuid().'/rooms/'.$room->getUuid().'/configure');
        self::assertResponseStatusCodeSame(403);
    }

    /** A room type is reached through its own property only. */
    public function testARoomTypeIsNotReachedThroughAnotherProperty(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $room = $this->addRoom($this->add('Vivutio Lakeshore Lodge'), ['name' => 'Tented Room', 'sleeps' => '2', 'adults' => '2', 'count' => '20']);
        $other = $this->add('Vivutio Riverside Camp');

        $this->browser->request('GET', '/properties/'.$other->getUuid().'/rooms/'.$room->getUuid().'/configure');
        self::assertResponseStatusCodeSame(404);
    }

    private function changeStatus(Property $property, string $status): Crawler
    {
        $page = $this->browser->request('GET', '/properties/'.$property->getUuid().'/configure');

        return $this->browser->submit($page->selectButton('Save property')->form(['status' => $status]));
    }

    /**
     * @param array<string, string> $values
     * @param list<string>          $features
     */
    private function addRoom(Property $property, array $values, array $features = []): RoomType
    {
        $page = $this->browser->request('GET', '/properties/'.$property->getUuid().'/rooms');
        $form = $page->selectButton('Add the room type')->form($values);
        $boxes = $form['features'];
        self::assertIsArray($boxes);
        foreach ($boxes as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);
            if (\in_array($box->availableOptionValues()[0], $features, true)) {
                $box->tick();
            }
        }
        $this->browser->submit($form);
        self::assertResponseRedirects('/properties/'.$property->getUuid().'/rooms');

        $this->em()->clear();
        $room = $this->em()->getRepository(RoomType::class)->findOneBy(['name' => $values['name'], 'property' => $this->property($property->getName())]);
        self::assertInstanceOf(RoomType::class, $room);

        return $room;
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
