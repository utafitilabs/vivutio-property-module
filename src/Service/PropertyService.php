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

namespace Vivutio\Property\Service;

use Doctrine\ORM\EntityManagerInterface;
use Vivutio\Bundle\IdentityBundle\Repository\DepartmentRepository;
use Vivutio\Bundle\IdentityBundle\Repository\UserRepository;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Enum\PropertyTypeEnum;
use Vivutio\Property\Exception\InvalidPropertyException;
use Vivutio\Property\Model\PropertyDetails;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Repository\RoomTypeRepository;

/**
 * Properties: named once, each a kind and somewhere, with how it is reached
 * and its house rules, moving through one lifecycle. A property opens only
 * with a room type on sale; one somebody is posted at, or a department sits
 * at, is not archived.
 */
final readonly class PropertyService
{
    public const int OLDEST_CHILD = 17;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private PropertyRepository $properties,
        private UserRepository $users,
        private DepartmentRepository $departments,
        private RoomTypeRepository $rooms,
    ) {
    }

    /**
     * A new property is a draft until it is opened.
     *
     * @throws InvalidPropertyException
     */
    public function create(string $name, string $type, string $location): Property
    {
        $property = (new Property())
            ->setName($this->name($name, null))
            ->setType($this->type($type))
            ->setLocation($this->location($location));

        $this->entityManager->persist($property);
        $this->entityManager->flush();

        return $property;
    }

    /**
     * Everything the configure page holds, in one change: nothing is kept
     * when one field is refused.
     *
     * @throws InvalidPropertyException
     */
    public function change(Property $property, PropertyDetails $details): void
    {
        $name = $this->name($details->name, $property);
        $type = $this->type($details->type);
        $location = $this->location($details->location);
        [$latitude, $longitude] = $this->coordinates($details->latitude, $details->longitude);
        $grading = $this->grading($details->grading);
        $summary = $this->optional($details->summary, 'summary', Property::SUMMARY_MAX_LENGTH);
        $description = $this->optional($details->description, 'description', 20000);
        $email = $this->email($details->email);
        $phone = $this->phone($details->phone);
        $website = $this->website($details->website);
        $checkIn = $this->time($details->checkIn, 'check_in');
        $checkOut = $this->time($details->checkOut, 'check_out');
        [$infants, $children] = $this->ages($details->infantsUpTo, $details->childrenUpTo);
        $status = $this->status($property, $details->status);

        $property
            ->setName($name)->setType($type)->setLocation($location)
            ->setLatitude($latitude)->setLongitude($longitude)->setGrading($grading)
            ->setSummary($summary)->setDescription($description)
            ->setEmail($email)->setPhone($phone)->setWebsite($website)
            ->setCheckInFrom($checkIn)->setCheckOutBy($checkOut)
            ->setInfantsUpTo($infants)->setChildrenUpTo($children)
            ->setStatus($status);
        $this->entityManager->flush();
    }

    /**
     * @throws InvalidPropertyException
     */
    private function name(string $name, ?Property $renamed): string
    {
        $name = trim($name);
        if ('' === $name) {
            throw new InvalidPropertyException('name', 'A property is known by its name: it cannot be empty.');
        }
        if (mb_strlen($name) > Property::NAME_MAX_LENGTH) {
            throw new InvalidPropertyException('name', \sprintf('A name can be at most %d characters.', Property::NAME_MAX_LENGTH));
        }

        foreach ($this->properties->findAll() as $other) {
            if ($other !== $renamed && mb_strtolower($other->getName()) === mb_strtolower($name)) {
                throw new InvalidPropertyException('name', \sprintf('There is already a property called %s.', $other->getName()));
            }
        }

        return $name;
    }

    /**
     * @throws InvalidPropertyException
     */
    private function type(string $type): PropertyTypeEnum
    {
        return PropertyTypeEnum::tryFrom($type) ?? throw new InvalidPropertyException('type', 'Choose what kind of property it is.');
    }

    /**
     * @throws InvalidPropertyException
     */
    private function location(string $location): string
    {
        $location = trim($location);
        if ('' === $location) {
            throw new InvalidPropertyException('location', 'Say where it is.');
        }
        if (mb_strlen($location) > Property::LOCATION_MAX_LENGTH) {
            throw new InvalidPropertyException('location', \sprintf('Where it is can be at most %d characters.', Property::LOCATION_MAX_LENGTH));
        }

        return $location;
    }

    /**
     * @return array{?string, ?string}
     *
     * @throws InvalidPropertyException
     */
    private function coordinates(string $latitude, string $longitude): array
    {
        $latitude = trim($latitude);
        $longitude = trim($longitude);
        if ('' === $latitude && '' === $longitude) {
            return [null, null];
        }

        foreach (['latitude' => [$latitude, 90], 'longitude' => [$longitude, 180]] as $field => [$value, $limit]) {
            if ('' === $value) {
                throw new InvalidPropertyException($field, 'Give both coordinates, or neither.');
            }
            if (!is_numeric($value) || abs((float) $value) > $limit) {
                throw new InvalidPropertyException($field, \sprintf('A %s is a number of degrees from -%d to %d.', $field, $limit, $limit));
            }
        }

        return [number_format((float) $latitude, 6, '.', ''), number_format((float) $longitude, 6, '.', '')];
    }

    /**
     * @throws InvalidPropertyException
     */
    private function grading(string $grading): ?int
    {
        $grading = trim($grading);
        if ('' === $grading) {
            return null;
        }
        if (!ctype_digit($grading) || (int) $grading < 1 || (int) $grading > 5) {
            throw new InvalidPropertyException('grading', 'A grading is one to five stars.');
        }

        return (int) $grading;
    }

    /**
     * @throws InvalidPropertyException
     */
    private function optional(string $value, string $field, int $max): ?string
    {
        $value = trim($value);
        if (mb_strlen($value) > $max) {
            throw new InvalidPropertyException($field, \sprintf('It can be at most %d characters.', $max));
        }

        return '' === $value ? null : $value;
    }

    /**
     * @throws InvalidPropertyException
     */
    private function email(string $email): ?string
    {
        $email = $this->optional($email, 'email', 180);
        if (null !== $email && false === filter_var($email, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidPropertyException('email', 'An email address is written name@example.com.');
        }

        return $email;
    }

    /**
     * @throws InvalidPropertyException
     */
    private function phone(string $phone): ?string
    {
        $phone = $this->optional($phone, 'phone', 40);
        if (null !== $phone && 1 !== preg_match('{^\+?[0-9][0-9 ()-]{4,}$}D', $phone)) {
            throw new InvalidPropertyException('phone', 'A phone number is digits and spaces, with a + before the country code.');
        }

        return $phone;
    }

    /**
     * @throws InvalidPropertyException
     */
    private function website(string $website): ?string
    {
        $website = $this->optional($website, 'website', 255);
        if (null !== $website && (false === filter_var($website, \FILTER_VALIDATE_URL) || !\in_array(parse_url($website, \PHP_URL_SCHEME), ['http', 'https'], true))) {
            throw new InvalidPropertyException('website', 'A website starts with https://.');
        }

        return $website;
    }

    /**
     * @throws InvalidPropertyException
     */
    private function time(string $time, string $field): ?\DateTimeImmutable
    {
        $time = trim($time);
        if ('' === $time) {
            return null;
        }
        $at = 1 === preg_match('{^([01][0-9]|2[0-3]):[0-5][0-9]$}D', $time) ? \DateTimeImmutable::createFromFormat('!H:i', $time) : false;
        if (false === $at) {
            throw new InvalidPropertyException($field, 'A time is written on the 24-hour clock, 14:00.');
        }

        return $at;
    }

    /**
     * @return array{?int, ?int}
     *
     * @throws InvalidPropertyException
     */
    private function ages(string $infants, string $children): array
    {
        $infants = trim($infants);
        $children = trim($children);
        if ('' === $infants && '' === $children) {
            return [null, null];
        }

        foreach (['infants_up_to' => $infants, 'children_up_to' => $children] as $field => $value) {
            if ('' === $value) {
                throw new InvalidPropertyException($field, 'Give both ages, or neither: without them every guest is an adult.');
            }
            if (!ctype_digit($value) || (int) $value > self::OLDEST_CHILD) {
                throw new InvalidPropertyException($field, \sprintf('An age is a whole number of years, up to %d.', self::OLDEST_CHILD));
            }
        }
        if ((int) $infants >= (int) $children) {
            throw new InvalidPropertyException('children_up_to', 'Children are older than infants: their age goes higher.');
        }

        return [(int) $infants, (int) $children];
    }

    /**
     * @throws InvalidPropertyException
     */
    private function status(Property $property, string $status): PropertyStatusEnum
    {
        $current = $property->getStatus();
        $chosen = PropertyStatusEnum::tryFrom($status);
        if (null === $chosen || !\in_array($chosen, $current->choices(), true)) {
            throw new InvalidPropertyException('status', \sprintf('A property that is %s can be %s.', mb_strtolower($current->label()), implode(' or ', array_map(static fn (PropertyStatusEnum $next): string => mb_strtolower($next->label()), \array_slice($current->choices(), 1)))));
        }

        if (PropertyStatusEnum::Open === $chosen && PropertyStatusEnum::Open !== $current && [] === $this->rooms->findOnSaleByProperty($property)) {
            throw new InvalidPropertyException('status', \sprintf('%s has no room type on sale, so there is nothing to book: add one on its Rooms tab first.', $property->getName()));
        }

        if (!$chosen->isAPlace() && $current->isAPlace()) {
            $where = ['postedKind' => Property::PLACE_KIND, 'postedId' => $property->getPlaceId()];
            $posted = $this->users->count($where);
            $sitting = $this->departments->count(['placeKind' => Property::PLACE_KIND, 'placeId' => $property->getPlaceId()]);
            if ($posted + $sitting > 0) {
                $said = array_filter([
                    $posted > 0 ? \sprintf('%d %s posted here', $posted, 1 === $posted ? 'person is' : 'people are') : null,
                    $sitting > 0 ? \sprintf('%d %s here', $sitting, 1 === $sitting ? 'department sits' : 'departments sit') : null,
                ]);
                throw new InvalidPropertyException('status', implode(' and ', $said).': move them elsewhere before it is archived.');
            }
        }

        return $chosen;
    }
}
