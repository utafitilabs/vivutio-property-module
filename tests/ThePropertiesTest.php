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
use Vivutio\Bundle\IdentityBundle\Entity\Department;
use Vivutio\Bundle\IdentityBundle\Entity\Position;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Bundle\IdentityBundle\Enum\TierEnum;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * The properties: added and configured by the tiers, read by whoever's
 * position grants properties.read where their department allows it, and in
 * the menu for exactly them.
 */
final class ThePropertiesTest extends WebTestCase
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

    public function testATierAddsAPropertyAndItIsListed(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));

        $page = $this->browser->request('GET', '/properties');
        $this->browser->submit($page->selectButton('Add the property')->form(['name' => 'Vivutio Riverside Camp', 'location' => 'Central Serengeti, Tanzania', 'units' => '24', 'unit' => 'tents']));

        $camp = $this->em()->getRepository(Property::class)->findOneBy(['name' => 'Vivutio Riverside Camp']);
        self::assertInstanceOf(Property::class, $camp);
        self::assertResponseRedirects('/properties/'.$camp->getUuid());

        $list = $this->browser->request('GET', '/properties');
        $row = $list->filter('tr[data-property="Vivutio Riverside Camp"]');
        self::assertSame('Central Serengeti, Tanzania', trim($row->filter('[data-where]')->text()));
        self::assertSame('24 tents', trim($row->filter('[data-size]')->text()));
    }

    public function testANameIsOnceAndAPropertyHasUnits(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/properties');
        $this->browser->submit($page->selectButton('Add the property')->form(['name' => 'Vivutio Riverside Camp', 'location' => 'Central Serengeti, Tanzania', 'units' => '24', 'unit' => 'tents']));

        foreach ([['vivutio riverside camp', '12', 'name'], ['Vivutio Plains Camp', '0', 'units']] as [$name, $units, $field]) {
            $page = $this->browser->request('GET', '/properties');
            $page = $this->browser->submit($page->selectButton('Add the property')->form(['name' => $name, 'location' => 'Serengeti', 'units' => $units, 'unit' => 'tents']));

            self::assertResponseStatusCodeSame(422);
            self::assertSame($field, $page->filter('.field.wrong input')->attr('name'));
        }
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

    public function testATierConfiguresAProperty(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        $page = $this->browser->request('GET', '/properties');
        $this->browser->submit($page->selectButton('Add the property')->form(['name' => 'Vivutio Riverside Camp', 'location' => 'Central Serengeti, Tanzania', 'units' => '24', 'unit' => 'tents']));
        $camp = $this->em()->getRepository(Property::class)->findOneBy(['name' => 'Vivutio Riverside Camp']);
        self::assertInstanceOf(Property::class, $camp);

        $page = $this->browser->request('GET', '/properties/'.$camp->getUuid().'/configure');
        $this->browser->submit($page->selectButton('Save property')->form(['units' => '26']));

        self::assertResponseRedirects('/properties/'.$camp->getUuid());
        $this->browser->followRedirect();
        self::assertSame('26 tents', trim($this->browser->getCrawler()->filter('[data-size]')->text()));
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
