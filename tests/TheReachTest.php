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
use Symfony\Component\DomCrawler\Field\InputFormField;
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Bundle\IdentityBundle\Service\OfficeService;
use Vivutio\Bundle\IdentityBundle\Service\UserService;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Enum\PropertyTypeEnum;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * Where a person's permissions apply (ruled 2 October with the Position card,
 * design C): the whole organization, where they are posted, or chosen
 * properties, chosen on the Position card and saved with it. A property out of
 * reach is refused like any page one may not open, and left out of the
 * register; a reach that follows the posting moves with the person.
 */
final class TheReachTest extends WebTestCase
{
    private KernelBrowser $browser;
    private Property $lakeshore;
    private Property $riverside;
    private User $amani;

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

    public function testThePositionCardOffersThreeReachesAndSavesTheChoice(): void
    {
        $this->camps();
        $page = $this->configurePage($this->amani);

        $switch = $page->filter('[data-reach] .switch[data-name="reach"] button');
        self::assertSame(['Whole organization', 'Where posted', 'Chosen properties'], $switch->each(static fn (Crawler $button): string => trim($button->text())));
        self::assertSame('true', $switch->eq(0)->attr('aria-pressed'), 'nothing chosen is the whole organization');
        self::assertSame(['Vivutio Lakeshore Lodge', 'Vivutio Riverside Camp'], $page->filter('[data-shown-for="reach=chosen"] .tick')->each(static fn (Crawler $tick): string => trim($tick->filter('span')->text())));

        $this->choose($this->amani, 'chosen', [$this->lakeshore]);

        self::assertSame('Chosen properties · Vivutio Lakeshore Lodge', trim($this->browser->request('GET', '/team/'.$this->amani->getUuid())->filter('[data-card-summary="Permissions apply at"] b')->text()));
        $this->signedInAs($this->amani);
        $this->browser->request('GET', '/properties/'.$this->lakeshore->getUuid());
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/properties/'.$this->riverside->getUuid());
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/properties/'.$this->riverside->getUuid().'/rates');
        self::assertResponseStatusCodeSame(403);
        $register = $this->browser->request('GET', '/properties');
        self::assertSame(['Vivutio Lakeshore Lodge'], $register->filter('tr[data-property]')->each(static fn (Crawler $row): string => (string) $row->attr('data-property')));
        self::assertSame('showing 1 of 1', trim((string) preg_replace('/\s+/', ' ', $register->filter('[data-shown]')->text())), 'counts are over what they may open');
    }

    /** A reach that follows the posting moves with the person: a transfer is one edit and leaves no access behind. */
    public function testWherePostedMovesWithThePerson(): void
    {
        $this->camps();
        $this->accounts()->changePosting($this->fresh($this->amani), $this->lakeshore);
        $this->choose($this->amani, 'posted');

        $this->signedInAs($this->amani);
        $this->browser->request('GET', '/properties/'.$this->lakeshore->getUuid());
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/properties/'.$this->riverside->getUuid());
        self::assertResponseStatusCodeSame(403);

        $this->accounts()->changePosting($this->fresh($this->amani), $this->riverside);
        $this->browser->request('GET', '/properties/'.$this->riverside->getUuid());
        self::assertResponseIsSuccessful();
        $this->browser->request('GET', '/properties/'.$this->lakeshore->getUuid());
        self::assertResponseStatusCodeSame(403);
    }

    /** "Where posted" is not offered to somebody posted at an office, nor to somebody posted nowhere. */
    public function testWherePostedIsOnlyForSomebodyPostedAtAProperty(): void
    {
        $this->camps();
        $offices = static::getContainer()->get(OfficeService::class);
        self::assertInstanceOf(OfficeService::class, $offices);
        $this->accounts()->changePosting($this->fresh($this->amani), $offices->create('Head office', 'Arusha', 'Tanzania'));

        $page = $this->configurePage($this->amani);
        self::assertNotNull($page->filter('[data-reach] button[data-value="posted"]')->attr('disabled'));

        $page = $this->choose($this->amani, 'posted', [], 422);
        self::assertStringContainsString('Head office is an office', $page->filter('[data-reach]')->text());
    }

    public function testChosenPropertiesAreAtLeastOne(): void
    {
        $this->camps();

        $page = $this->choose($this->amani, 'chosen', [], 422);

        self::assertStringContainsString('Choose at least one property', $page->filter('[data-reach]')->text());
    }

    /** Somebody whose reach was never chosen reaches the whole organization, as before there was a choice. */
    public function testUnchosenIsTheWholeOrganization(): void
    {
        $this->camps();
        $this->signedInAs($this->amani);

        self::assertCount(2, $this->browser->request('GET', '/properties')->filter('tr[data-property]'));
    }

    private function camps(): void
    {
        foreach ([['Vivutio Lakeshore Lodge', PropertyTypeEnum::TentedLodge, 'Lake Manyara, Tanzania'], ['Vivutio Riverside Camp', PropertyTypeEnum::TentedCamp, 'Central Serengeti, Tanzania']] as [$name, $type, $location]) {
            $this->em()->persist((new Property())->setName($name)->setType($type)->setLocation($location)->setStatus(PropertyStatusEnum::Open));
        }
        $reception = (new Department())->setName('Reception')->setAllows(['properties.read']);
        $this->em()->persist($reception);
        $this->em()->flush();
        $this->amani = $this->person('Amani', TierEnum::Staff, ['properties.read'], $reception);
        $this->lakeshore = $this->named('Vivutio Lakeshore Lodge');
        $this->riverside = $this->named('Vivutio Riverside Camp');
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
    }

    private function configurePage(User $person): Crawler
    {
        $page = $this->browser->request('GET', '/team/'.$person->getUuid().'/configure');
        self::assertResponseIsSuccessful();

        return $page;
    }

    /**
     * @param list<Property> $properties
     */
    private function choose(User $person, string $reach, array $properties = [], int $answered = 302): Crawler
    {
        $form = $this->configurePage($person)->selectButton('Save position')->form();
        $form->disableValidation();
        $field = $form['reach'];
        self::assertInstanceOf(InputFormField::class, $field);
        $field->setValue($reach);
        $boxes = $form['reach_properties'];
        self::assertIsArray($boxes);
        foreach ($boxes as $box) {
            self::assertInstanceOf(ChoiceFormField::class, $box);
            $ids = array_map(static fn (Property $property): string => (string) $property->getUuid(), $properties);
            \in_array($box->availableOptionValues()[0], $ids, true) ? $box->tick() : $box->untick();
        }
        $page = $this->browser->submit($form);
        self::assertResponseStatusCodeSame($answered);

        return $page;
    }

    private function named(string $name): Property
    {
        $property = $this->em()->getRepository(Property::class)->findOneBy(['name' => $name]);
        self::assertInstanceOf(Property::class, $property);

        return $property;
    }

    private function fresh(User $user): User
    {
        $fresh = $this->em()->getRepository(User::class)->find($user->getId());
        self::assertInstanceOf(User::class, $fresh);

        return $fresh;
    }

    private function accounts(): UserService
    {
        $service = static::getContainer()->get(UserService::class);
        self::assertInstanceOf(UserService::class, $service);

        return $service;
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
