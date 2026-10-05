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

namespace Vivutio\Property\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Vivutio\Bundle\IdentityBundle\Entity\Trait\TimestampableTrait;
use Vivutio\Bundle\IdentityBundle\Entity\User;
use Vivutio\Property\Enum\ReachEnum;
use Vivutio\Property\Repository\PropertyReachRepository;

/**
 * Where a person's permissions apply among the properties, as chosen on their
 * Position card: the whole organization, where they are posted, or chosen
 * properties. A person with none chosen reaches the whole organization.
 *
 * It holds state and nothing else.
 */
#[ORM\Entity(repositoryClass: PropertyReachRepository::class)]
#[ORM\Table(name: 'property_reach')]
#[ORM\HasLifecycleCallbacks]
class PropertyReach
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null; // @phpstan-ignore property.unusedType (assigned by Doctrine)

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $person;

    #[ORM\Column(length: 16, enumType: ReachEnum::class)]
    private ReachEnum $reach = ReachEnum::Organization;

    /** @var list<string> the uuids of the properties chosen, for a chosen reach */
    #[ORM\Column(type: Types::JSON)]
    private array $properties = [];

    public function __construct(User $person)
    {
        $this->person = $person;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPerson(): User
    {
        return $this->person;
    }

    public function getReach(): ReachEnum
    {
        return $this->reach;
    }

    public function setReach(ReachEnum $reach): static
    {
        $this->reach = $reach;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * @param list<string> $properties
     */
    public function setProperties(array $properties): static
    {
        $this->properties = $properties;

        return $this;
    }
}
