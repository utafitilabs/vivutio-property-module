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

namespace Vivutio\Property\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\Season;

/**
 * @extends ServiceEntityRepository<Season>
 */
class SeasonRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Season::class);
    }

    /**
     * A property's seasons, the busiest first.
     *
     * @return list<Season>
     */
    public function findByProperty(Property $property): array
    {
        $seasons = $this->findBy(['property' => $property], ['name' => 'ASC']);
        usort($seasons, static fn (Season $a, Season $b): int => $b->getKind()->tone() <=> $a->getKind()->tone());

        return $seasons;
    }
}
