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

namespace Vivutio\Property\Controller;

use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\RoomType;
use Vivutio\Property\Enum\RoomFeatureEnum;
use Vivutio\Property\Exception\InvalidRoomTypeException;
use Vivutio\Property\Model\RoomTypeDetails;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Service\PropertyDirectoryService;
use Vivutio\Property\Service\RoomTypeService;

/**
 * A property's Rooms tab, and a room type's configure page. Read with
 * properties.read; added and changed by the tiers alone.
 */
final readonly class RoomController
{
    public const string ROOMS = 'property_rooms';
    public const string ADD = 'property_room_add';
    public const string CONFIGURE = 'property_room_configure';

    public function __construct(
        private Environment $twig,
        private RoomTypeService $service,
        private RoomTypeRepository $rooms,
        private PropertyDirectoryService $directory,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/rooms', name: self::ROOMS, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(PropertyController::READ, subject: 'property')]
    public function rooms(
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        return $this->roomsPage($property);
    }

    #[Route('/properties/{uuid}/rooms', name: self::ADD, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function add(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $details = $this->typed($request);
        if (!$this->tokens->isTokenValid(new CsrfToken('property_room_add', $request->getPayload()->getString('_token')))) {
            return $this->roomsPage($property, $details, expired: true);
        }

        try {
            $this->service->create($property, $details);
        } catch (InvalidRoomTypeException $refusal) {
            return $this->roomsPage($property, $details, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::ROOMS, ['uuid' => $property->getUuid()]));
    }

    #[Route('/properties/{uuid}/rooms/{room}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID, 'room' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['room' => 'uuid'])]
        RoomType $room,
    ): Response {
        if ($room->getProperty() !== $property) {
            throw new NotFoundHttpException();
        }

        if (!$request->isMethod('POST')) {
            return $this->configurePage($room, $this->stored($room));
        }

        $details = $this->typed($request, $this->stored($room));
        if (!$this->tokens->isTokenValid(new CsrfToken('property_room_configure', $request->getPayload()->getString('_token')))) {
            return $this->configurePage($room, $details, expired: true);
        }

        try {
            $this->service->change($room, $details);
        } catch (InvalidRoomTypeException $refusal) {
            return $this->configurePage($room, $details, [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::ROOMS, ['uuid' => $property->getUuid()]));
    }

    /**
     * What a form sent, over what is stored where it sent nothing of a field.
     */
    private function typed(Request $request, ?RoomTypeDetails $stored = null): RoomTypeDetails
    {
        $payload = $request->getPayload();
        $field = static fn (string $name, string $kept): string => $payload->has($name) ? $payload->getString($name) : $kept;

        return new RoomTypeDetails(
            name: $field('name', $stored->name ?? ''),
            sleeps: $field('sleeps', $stored->sleeps ?? ''),
            adults: $field('adults', $stored->adults ?? ''),
            count: $field('count', $stored->count ?? ''),
            description: $field('description', $stored->description ?? ''),
            features: $payload->has('features') || null === $stored ? array_values(array_filter($payload->all('features'), \is_string(...))) : $stored->features,
            onSale: 'no' !== $field('on_sale', null === $stored || $stored->onSale ? 'yes' : 'no'),
        );
    }

    private function stored(RoomType $room): RoomTypeDetails
    {
        return new RoomTypeDetails(
            name: $room->getName(),
            sleeps: (string) $room->getSleeps(),
            adults: (string) $room->getAdults(),
            count: (string) $room->getCount(),
            description: (string) $room->getDescription(),
            features: $room->getFeatures(),
            onSale: !$room->isWithdrawn(),
        );
    }

    /**
     * @param array<string, string> $wrong
     */
    private function roomsPage(Property $property, ?RoomTypeDetails $typed = null, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/rooms.html.twig', [
            'property' => $property,
            'rooms' => $this->rooms->findByProperty($property),
            'band' => $this->directory->band($property),
            'features' => RoomFeatureEnum::cases(),
            'labels' => array_combine(array_map(static fn (RoomFeatureEnum $feature): string => $feature->value, RoomFeatureEnum::cases()), array_map(static fn (RoomFeatureEnum $feature): string => $feature->label(), RoomFeatureEnum::cases())),
            'typed' => ($typed ?? new RoomTypeDetails('', '2', '2', ''))->typed(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function configurePage(RoomType $room, RoomTypeDetails $details, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/room_configure.html.twig', [
            'property' => $room->getProperty(),
            'room' => $room,
            'features' => RoomFeatureEnum::cases(),
            'typed' => $details->typed(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
