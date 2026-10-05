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
use Symfony\Component\DomCrawler\Form;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\IdentityBundle\Service\UserService;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * The properties: what each is and where, its house rules and how to reach
 * it, and whether it takes bookings; added and configured by the tiers, read
 * by whoever's position grants properties.read where their department allows
 * it, and a place to post people.
 */
final class ThePropertiesTest extends WebTestCase
{
    /** A whole configure form, as an Admin fills it in. */
    private const array DETAILS = [
        'name' => 'Vivutio Lakeshore Lodge',
        'type' => 'tented_lodge',
        'location' => 'Lake Manyara, Tanzania',
        'latitude' => '-3.51234',
        'longitude' => '35.821',
        'grading' => '4',
        'summary' => 'Tents on the Rift escarpment above the lake.',
        'description' => 'Twenty tents under acacias, a short drive from the park gate.',
        'email' => 'reservations@vivutio-camps.example',
        'phone' => '+255 27 250 0000',
        'website' => 'https://vivutio-camps.example/lakeshore',
        'check_in' => '14:00',
        'check_out' => '10:00',
        'infants_up_to' => '3',
        'children_up_to' => '15',
    ];

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

    public function testATierAddsAPropertyAsADraftAndGoesOnToConfigureIt(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        $page = $this->browser->request('GET', '/properties');
        $this->browser->submit($page->selectButton('Add the property')->form(['name' => 'Vivutio Riverside Camp', 'type' => 'tented_camp', 'location' => 'Central Serengeti, Tanzania']));

        $camp = $this->property('Vivutio Riverside Camp');
        self::assertResponseRedirects('/properties/'.$camp->getUuid().'/configure');
        self::assertSame(PropertyStatusEnum::Draft, $camp->getStatus());

        $row = $this->browser->request('GET', '/properties')->filter('tr[data-property="Vivutio Riverside Camp"]');
        self::assertSame('Tented camp', trim($row->filter('[data-type]')->text()));
        self::assertSame('Central Serengeti, Tanzania', trim($row->filter('[data-where]')->text()));
        self::assertSame('Draft', trim($row->filter('[data-status]')->text()));
        self::assertSame('Nobody posted', trim($row->filter('[data-posted]')->text()));
    }

    public function testANameIsOnceAndAPropertySaysWhatItIsAndWhere(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $this->add('Vivutio Riverside Camp');

        foreach ([['vivutio riverside camp', 'lodge', 'Serengeti', 'name'], ['Vivutio Plains Camp', 'castle', 'Serengeti', 'type'], ['Vivutio Plains Camp', 'lodge', ' ', 'location']] as [$name, $type, $location, $field]) {
            $page = $this->browser->request('GET', '/properties');
            $page = $this->browser->submit($this->loose($page->selectButton('Add the property')->form(), ['name' => $name, 'type' => $type, 'location' => $location]));

            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select')->attr('name'), $field);
        }
    }

    public function testConfiguringSavesEverythingAPropertyHolds(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');

        $this->configure($lodge, self::DETAILS);

        $record = $this->browser->request('GET', '/properties/'.$lodge->getUuid());
        self::assertSame('Vivutio Lakeshore Lodge', trim($record->filter('h1')->text()));
        self::assertSame('Tented lodge', trim($record->filter('[data-type]')->text()));
        self::assertSame('Lake Manyara, Tanzania', trim($record->filter('[data-where]')->text()));
        self::assertSame('-3.512340, 35.821000', trim($record->filter('[data-coordinates]')->text()));
        self::assertSame('4 stars', trim($record->filter('[data-grading]')->text()));
        self::assertSame('Tents on the Rift escarpment above the lake.', trim($record->filter('[data-summary]')->text()));
        self::assertSame('From 14:00', trim($record->filter('[data-check-in]')->text()));
        self::assertSame('By 10:00', trim($record->filter('[data-check-out]')->text()));
        self::assertSame('Infants up to 3, children 4 to 15', trim($record->filter('[data-ages]')->text()));
        self::assertSame(['reservations@vivutio-camps.example', '+255 27 250 0000', 'https://vivutio-camps.example/lakeshore'], $record->filter('[data-contact] b')->each(static fn (Crawler $line): string => trim($line->text())));
    }

    /** Each refusal is said beside the field it is about. */
    public function testWhatAPropertyCannotHoldIsRefusedBesideItsField(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $lodge = $this->add('Vivutio Lakeshore Lodge');

        $this->configure($lodge, self::DETAILS);

        foreach ([
            ['grading', ['grading' => '6']],
            ['latitude', ['latitude' => '95']],
            ['longitude', ['longitude' => '']],
            ['email', ['email' => 'reservations at the lodge']],
            ['website', ['website' => 'ftp://vivutio-camps.example']],
            ['check_in', ['check_in' => '25:00']],
            ['children_up_to', ['infants_up_to' => '15', 'children_up_to' => '10']],
            ['infants_up_to', ['infants_up_to' => '', 'children_up_to' => '12']],
        ] as [$field, $changed]) {
            $page = $this->configure($lodge, $changed, strict: false);

            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, $page->filter('.field.wrong')->filter('input, select, textarea')->attr('name'), $field);
            self::assertSame(self::DETAILS['summary'], $this->property('Vivutio Lakeshore Lodge')->getSummary(), 'nothing was saved');
        }
    }

    /**
     * One lifecycle in place of a status and a switch: a draft opens; an open
     * property closes and reopens; either is archived, and an archived one
     * comes back closed. A season's closure is the calendar's, not this.
     */
    public function testAPropertyOpensClosesAndIsArchived(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $camp = $this->add('Vivutio Riverside Camp');

        foreach ([['open', 'Open'], ['closed', 'Closed'], ['open', 'Open'], ['archived', 'Archived'], ['closed', 'Closed']] as [$status, $label]) {
            $this->configure($camp, ['status' => $status]);
            self::assertSame($label, trim($this->browser->request('GET', '/properties/'.$camp->getUuid())->filter('[data-status]')->text()));
        }

        $page = $this->browser->request('GET', '/properties/'.$camp->getUuid().'/configure');
        self::assertSame(['closed', 'open', 'archived'], $page->filter('select[name="status"] option')->each(static fn (Crawler $option): string => (string) $option->attr('value')), 'a property never goes back to being a draft');
        $page = $this->configure($camp, ['status' => 'draft'], strict: false);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('status', $page->filter('.field.wrong select')->attr('name'));
    }

    /** A camp's own staff are posted at the property itself: it is offered on the Position card beside the offices. */
    public function testAPropertyIsAPlaceToPostPeopleUntilItIsArchived(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $camp = $this->add('Vivutio Riverside Camp');
        $old = $this->add('Vivutio Old Camp');
        $this->changeStatus($old, 'archived');
        $amani = $this->person('Amani', TierEnum::Staff);

        $page = $this->browser->request('GET', '/team/'.$amani->getUuid().'/configure');
        $offered = $page->filter('select[name="posted_at"] optgroup[label="Properties"] option');
        self::assertSame(['Vivutio Riverside Camp'], $offered->each(static fn (Crawler $option): string => trim($option->text())));

        $this->browser->submit($page->selectButton('Save position')->form(['posted_at' => 'property:'.$camp->getUuid()]));
        self::assertResponseRedirects('/team/'.$amani->getUuid().'/configure');

        $record = $this->browser->request('GET', '/properties/'.$camp->getUuid());
        self::assertSame(['Amani Kimaro'], $record->filter('[data-posted] [data-person]')->each(static fn (Crawler $row): string => (string) $row->attr('data-person')));
        self::assertSame('1 posted', trim($this->browser->request('GET', '/properties')->filter('tr[data-property="Vivutio Riverside Camp"] [data-posted]')->text()));
    }

    /** Archiving a property somebody is posted at, or a department sits at, would leave them nowhere: it is refused until they are moved. */
    public function testArchivingIsRefusedWhileAnybodyIsPostedThere(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $camp = $this->add('Vivutio Riverside Camp');
        $amani = $this->person('Amani', TierEnum::Staff);
        $accounts = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $accounts);
        $accounts->changePosting($amani, $camp);

        $page = $this->configure($camp, ['status' => 'archived'], strict: false);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('1 person is posted here', $page->filter('.field.wrong')->text());
    }

    /** The module's page joins the menu through the shell, for whoever may open it. */
    public function testThePropertiesAreInTheMenuForWhoeverMayOpenThem(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        self::assertSame('/properties', $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Properties')->attr('href'));

        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $clerk = $this->person('Amani', TierEnum::Staff, ['properties.read'], $reception);
        $this->signedInAs($clerk);
        self::assertCount(1, $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Properties'));
        $this->browser->request('GET', '/properties');
        self::assertResponseIsSuccessful();

        $this->signedInAs($this->person('Elia', TierEnum::Staff, ['properties.read']));
        self::assertCount(0, $this->browser->request('GET', '/')->filter('nav.menu')->selectLink('Properties'), 'without a department that allows it');
        $this->browser->request('GET', '/properties');
        self::assertResponseStatusCodeSame(403);
    }

    /** The module ships the migration its mapping needs, and nothing is left for a diff to write. */
    public function testTheMigrationsBuildWhatTheMappingDescribes(): void
    {
        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $application = new Application($kernel);
        $application->setAutoExit(false);
        $output = new BufferedOutput();

        $status = $application->run(new ArrayInput(['command' => 'doctrine:schema:validate', '--skip-property-types' => true]), $output);

        self::assertSame(0, $status, $output->fetch());
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

    /**
     * A form filled in as a hand-made request would be, past what its
     * choices offer.
     *
     * @param array<string, string> $values
     */
    private function loose(Form $form, array $values): Form
    {
        $form->disableValidation();
        $form->setValues($values);

        return $form;
    }

    private function add(string $name): Property
    {
        $page = $this->browser->request('GET', '/properties');
        $this->browser->submit($page->selectButton('Add the property')->form(['name' => $name, 'type' => 'lodge', 'location' => 'Tarangire, Tanzania']));

        return $this->property($name);
    }

    private function changeStatus(Property $property, string $status): void
    {
        $this->configure($property, ['status' => $status]);
    }

    /**
     * Fills the configure page as an Admin would: each card that holds one
     * of the values is saved with its own button, in the page's order. A
     * strict save must come back to the page; a loose one, past what the
     * choices offer, stops at the first refusal and returns it.
     *
     * @param array<string, string> $values
     */
    private function configure(Property $property, array $values, bool $strict = true): Crawler
    {
        $url = '/properties/'.$property->getUuid().'/configure';
        $page = $this->browser->request('GET', $url);
        $forms = $page->filter('form[data-card]');
        self::assertGreaterThan(0, $forms->count());

        foreach ($forms as $element) {
            $card = new Crawler($element, $page->getUri());
            $form = $card->filter('button[type="submit"]')->form();
            $mine = array_intersect_key($values, $form->getValues());
            if ([] === $mine) {
                continue;
            }

            $page = $this->browser->submit($strict ? $form->setValues($mine) : $this->loose($form, $mine));
            if (!$strict && 422 === $this->browser->getResponse()->getStatusCode()) {
                return $page;
            }
            self::assertResponseRedirects($url);
            $page = $this->browser->followRedirect();
        }

        return $page;
    }

    private function property(string $name): Property
    {
        $this->em()->clear();
        $property = $this->em()->getRepository(Property::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Property::class, $property);

        return $property;
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
