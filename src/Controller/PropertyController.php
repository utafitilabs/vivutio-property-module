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
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Enum\PropertyStatusEnum;
use Vivutio\Property\Enum\PropertyTypeEnum;
use Vivutio\Property\Exception\InvalidPropertyException;
use Vivutio\Property\Model\PropertyDetails;
use Vivutio\Property\Service\PropertyDirectoryService;
use Vivutio\Property\Service\PropertyGlanceService;
use Vivutio\Property\Service\PropertyService;

/**
 * The properties: a register, a property's page and its configure page, in
 * the core's record idiom. Read with properties.read, a module's pair a
 * department must allow; added and changed by the tiers alone. A property is
 * added as a draft and configured next.
 */
final readonly class PropertyController
{
    public const string REGISTER = 'property_list';
    public const string ADD = 'property_add';
    public const string SHOW = 'property_show';
    public const string CONFIGURE = 'property_configure';

    public const string READ = 'properties.read';
    public const string CHANGE = 'properties.configure';

    private const string SAVED = 'property.saved';

    /** The configure page's cards, each saved on its own, and what its notice calls it. */
    private const array CARDS = [
        'property' => 'The property',
        'where' => 'Where it is',
        'about' => 'About',
        'house_rules' => 'The house rules',
        'contact' => 'The contact',
    ];

    public function __construct(
        private Environment $twig,
        private PropertyService $service,
        private PropertyDirectoryService $directory,
        private PropertyGlanceService $glance,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(Request $request): Response
    {
        return $this->registerPage(q: $request->query->getString('q'), status: $request->query->getString('status'), type: $request->query->getString('type'));
    }

    #[Route('/properties', name: self::ADD, methods: ['POST'])]
    #[IsGranted(self::CHANGE)]
    public function add(Request $request): Response
    {
        $payload = $request->getPayload();
        $typed = ['name' => $payload->getString('name'), 'type' => $payload->getString('type'), 'location' => $payload->getString('location')];

        if (!$this->tokens->isTokenValid(new CsrfToken('property_add', $payload->getString('_token')))) {
            return $this->registerPage(typed: $typed, expired: true);
        }

        try {
            $property = $this->service->create($typed['name'], $typed['type'], $typed['location']);
        } catch (InvalidPropertyException $refusal) {
            return $this->registerPage(typed: $typed, wrong: [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::CONFIGURE, ['uuid' => $property->getUuid()]));
    }

    #[Route('/properties/{uuid}', name: self::SHOW, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ, subject: 'property')]
    public function show(
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        return new Response($this->twig->render('@VivutioProperty/properties/show.html.twig', [
            'property' => $property,
            'posted' => $this->directory->postedAt($property),
            'departments' => $this->directory->departmentsAt($property),
            'band' => $this->directory->band($property),
            'glance' => $this->glance->of($property),
        ]));
    }

    #[Route('/properties/{uuid}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::CHANGE, subject: 'property')]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        if (!$request->isMethod('POST')) {
            $session = $request->getSession();
            $saved = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::SAVED) : [];

            return $this->configurePage($property, $this->stored($property), saved: \is_string($saved[0] ?? null) ? $saved[0] : null);
        }

        $details = $this->typed($request, $this->stored($property));
        if (!$this->tokens->isTokenValid(new CsrfToken('property_configure', $request->getPayload()->getString('_token')))) {
            return $this->configurePage($property, $details, expired: true);
        }

        try {
            $this->service->change($property, $details);
        } catch (InvalidPropertyException $refusal) {
            return $this->configurePage($property, $details, wrong: [$refusal->field => $refusal->getMessage()]);
        }

        $card = $request->getPayload()->getString('card');
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAVED, \array_key_exists($card, self::CARDS) ? self::CARDS[$card] : 'The property');
        }

        return new RedirectResponse($this->urls->generate(self::CONFIGURE, ['uuid' => $property->getUuid()]));
    }

    /**
     * What a card sent, over what is stored: a card sends its own fields, and
     * the rest of the property stands as it is.
     */
    private function typed(Request $request, PropertyDetails $stored): PropertyDetails
    {
        $payload = $request->getPayload();
        $field = static fn (string $name, string $kept): string => $payload->has($name) ? $payload->getString($name) : $kept;

        return new PropertyDetails(
            name: $field('name', $stored->name),
            type: $field('type', $stored->type),
            location: $field('location', $stored->location),
            status: $field('status', $stored->status),
            latitude: $field('latitude', $stored->latitude),
            longitude: $field('longitude', $stored->longitude),
            grading: $field('grading', $stored->grading),
            summary: $field('summary', $stored->summary),
            description: $field('description', $stored->description),
            email: $field('email', $stored->email),
            phone: $field('phone', $stored->phone),
            website: $field('website', $stored->website),
            checkIn: $field('check_in', $stored->checkIn),
            checkOut: $field('check_out', $stored->checkOut),
            infantsUpTo: $field('infants_up_to', $stored->infantsUpTo),
            childrenUpTo: $field('children_up_to', $stored->childrenUpTo),
        );
    }

    private function stored(Property $property): PropertyDetails
    {
        return new PropertyDetails(
            name: $property->getName(),
            type: $property->getType()->value,
            location: $property->getLocation(),
            status: $property->getStatus()->value,
            latitude: (string) $property->getLatitude(),
            longitude: (string) $property->getLongitude(),
            grading: (string) $property->getGrading(),
            summary: (string) $property->getSummary(),
            description: (string) $property->getDescription(),
            email: (string) $property->getEmail(),
            phone: (string) $property->getPhone(),
            website: (string) $property->getWebsite(),
            checkIn: (string) $property->getCheckInFrom()?->format('H:i'),
            checkOut: (string) $property->getCheckOutBy()?->format('H:i'),
            infantsUpTo: (string) $property->getInfantsUpTo(),
            childrenUpTo: (string) $property->getChildrenUpTo(),
        );
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function registerPage(array $typed = [], array $wrong = [], bool $expired = false, string $q = '', string $status = '', string $type = ''): Response
    {
        $register = $this->directory->register($q, $status, $type);

        return new Response($this->twig->render('@VivutioProperty/properties/index.html.twig', [
            'register' => $register,
            'properties' => $register->properties,
            'statuses' => PropertyStatusEnum::cases(),
            'posted' => $this->directory->postedCounts(),
            'units' => $this->directory->unitCounts(),
            'types' => PropertyTypeEnum::cases(),
            'typed' => $typed,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function configurePage(Property $property, PropertyDetails $details, array $wrong = [], bool $expired = false, ?string $saved = null): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/configure.html.twig', [
            'property' => $property,
            'types' => PropertyTypeEnum::cases(),
            'statuses' => $property->getStatus()->choices(),
            'typed' => $details->typed(),
            'saved' => $saved,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
