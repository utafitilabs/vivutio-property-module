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
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Enum\PropertyTypeEnum;
use Vivutio\Property\Tests\Application\Kernel;

/**
 * The properties register's tool row, the house list idiom: a search over
 * names and places, the statuses as chips and the types in a dropdown, each
 * with its count over every property; a filtered register lives in its
 * address.
 */
final class TheRegisterTest extends WebTestCase
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

    public function testEveryStatusIsAChipWithItsCount(): void
    {
        $this->four();

        $page = $this->browser->request('GET', '/properties');

        self::assertSame(['All 4', 'Draft 1', 'Open 1', 'Closed 1', 'Archived 1'], $page->filter('[data-facet="status"] a')->each(static fn (Crawler $chip): string => trim((string) preg_replace('/\s+/', ' ', $chip->text()))));
        self::assertSame('showing 4 of 4', trim((string) preg_replace('/\s+/', ' ', $page->filter('[data-shown]')->text())));
    }

    public function testTheRegisterIsFilteredByStatusTypeAndSearch(): void
    {
        $this->four();

        foreach ([
            ['status=open', ['Vivutio Lakeshore Lodge']],
            ['type=tented_camp', ['Vivutio Riverside Camp']],
            ['q=lake', ['Vivutio Lakeshore Lodge']],
            ['q=SERENGETI', ['Vivutio Riverside Camp']],
            ['status=archived&type=camp', ['Vivutio Old Camp']],
            ['status=sideways', ['Vivutio Crater Lodge', 'Vivutio Lakeshore Lodge', 'Vivutio Old Camp', 'Vivutio Riverside Camp']],
        ] as [$query, $names]) {
            $page = $this->browser->request('GET', '/properties?'.$query);
            self::assertSame($names, $page->filter('tr[data-property]')->each(static fn (Crawler $row): string => (string) $row->attr('data-property')), $query);
        }

        $page = $this->browser->request('GET', '/properties?type=tented_camp');
        self::assertSame('Tented camp', trim($page->filter('[data-facet="type"] summary')->text()));
        self::assertSame('/properties?type=tented_camp&status=open', $page->filter('[data-facet="status"]')->selectLink('Open')->attr('href'), 'a chip keeps the other filters');
    }

    public function testWhenNothingMatchesItSaysSoAndLeadsBack(): void
    {
        $this->four();

        $page = $this->browser->request('GET', '/properties?q=volcano');

        self::assertStringContainsString('Nothing matches “volcano”', $page->filter('.empty')->text());
        self::assertSame('/properties', $page->filter('.empty')->selectLink('Show every property')->attr('href'));
    }

    private function four(): void
    {
        $this->signedInAs($this->person('Baraka', TierEnum::Admin));
        foreach ([
            ['Vivutio Lakeshore Lodge', PropertyTypeEnum::TentedLodge, 'Lake Manyara, Tanzania', PropertyStatusEnum::Open],
            ['Vivutio Riverside Camp', PropertyTypeEnum::TentedCamp, 'Central Serengeti, Tanzania', PropertyStatusEnum::Draft],
            ['Vivutio Crater Lodge', PropertyTypeEnum::Lodge, 'Ngorongoro Highlands, Tanzania', PropertyStatusEnum::Closed],
            ['Vivutio Old Camp', PropertyTypeEnum::Camp, 'Tarangire, Tanzania', PropertyStatusEnum::Archived],
        ] as [$name, $type, $location, $status]) {
            $this->em()->persist((new Property())->setName($name)->setType($type)->setLocation($location)->setStatus($status));
        }
        $this->em()->flush();
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
