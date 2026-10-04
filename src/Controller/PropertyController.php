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
use Vivutio\Property\Enum\UnitEnum;
use Vivutio\Property\Exception\InvalidPropertyException;
use Vivutio\Property\Repository\PropertyRepository;
use Vivutio\Property\Service\PropertyService;

/**
 * The properties: a register, a property's page and its configure page, in
 * the core's place idiom (the offices'). Read with properties.read, a module's
 * pair a department must allow; added and changed by the tiers alone.
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

    public function __construct(
        private Environment $twig,
        private PropertyService $service,
        private PropertyRepository $properties,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties', name: self::REGISTER, methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function register(): Response
    {
        return $this->registerPage();
    }

    #[Route('/properties', name: self::ADD, methods: ['POST'])]
    #[IsGranted(self::CHANGE)]
    public function add(Request $request): Response
    {
        $typed = $this->typed($request);

        if (!$this->tokens->isTokenValid(new CsrfToken('property_add', $request->getPayload()->getString('_token')))) {
            return $this->registerPage(typed: $typed, expired: true);
        }

        try {
            $property = $this->service->create($typed['name'], $typed['location'], (int) $typed['units'], $typed['unit']);
        } catch (InvalidPropertyException $refusal) {
            return $this->registerPage(typed: $typed, wrong: [$refusal->field => $refusal->getMessage()]);
        }

        return new RedirectResponse($this->urls->generate(self::SHOW, ['uuid' => $property->getUuid()]));
    }

    #[Route('/properties/{uuid}', name: self::SHOW, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ)]
    public function show(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $session = $request->getSession();
        $saved = $session instanceof FlashBagAwareSessionInterface && [] !== $session->getFlashBag()->get(self::SAVED);

        return new Response($this->twig->render('@VivutioProperty/properties/show.html.twig', [
            'property' => $property,
            'saved' => $saved,
        ]));
    }

    #[Route('/properties/{uuid}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::CHANGE)]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->configurePage($property, ['name' => $property->getName(), 'location' => $property->getLocation(), 'units' => (string) $property->getUnits(), 'unit' => $property->getUnit()->value]);
        }

        $typed = $this->typed($request);
        if (!$this->tokens->isTokenValid(new CsrfToken('property_configure', $request->getPayload()->getString('_token')))) {
            return $this->configurePage($property, $typed, expired: true);
        }

        try {
            $this->service->change($property, $typed['name'], $typed['location'], (int) $typed['units'], $typed['unit']);
        } catch (InvalidPropertyException $refusal) {
            return $this->configurePage($property, $typed, wrong: [$refusal->field => $refusal->getMessage()]);
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAVED, true);
        }

        return new RedirectResponse($this->urls->generate(self::SHOW, ['uuid' => $property->getUuid()]));
    }

    /**
     * @return array{name: string, location: string, units: string, unit: string}
     */
    private function typed(Request $request): array
    {
        $payload = $request->getPayload();

        return ['name' => $payload->getString('name'), 'location' => $payload->getString('location'), 'units' => $payload->getString('units'), 'unit' => $payload->getString('unit')];
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function registerPage(array $typed = [], array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/index.html.twig', [
            'properties' => $this->properties->findBy([], ['name' => 'ASC']),
            'units' => UnitEnum::cases(),
            'typed' => $typed,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function configurePage(Property $property, array $typed, array $wrong = [], bool $expired = false): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/configure.html.twig', [
            'property' => $property,
            'units' => UnitEnum::cases(),
            'typed' => $typed,
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
