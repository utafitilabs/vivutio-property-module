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
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;
use Vivutio\Property\Entity\Property;
use Vivutio\Property\Entity\Season;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\SeasonKindEnum;
use Vivutio\Property\Exception\InvalidSeasonException;
use Vivutio\Property\Repository\SeasonRepository;
use Vivutio\Property\Service\PropertyDirectoryService;
use Vivutio\Property\Service\SeasonCalendarService;
use Vivutio\Property\Service\SeasonService;

/**
 * A property's Seasons tab, a year of its calendar at a time, and a season's
 * configure page with the periods it runs. Read with properties.read; changed
 * by the tiers alone.
 */
final readonly class SeasonController
{
    public const string SEASONS = 'property_seasons';
    public const string ADD = 'property_season_add';
    public const string REPEAT = 'property_seasons_repeat';
    public const string CONFIGURE = 'property_season_configure';
    public const string ADD_PERIOD = 'property_season_period_add';
    public const string REMOVE_PERIOD = 'property_season_period_remove';
    public const string REMOVE = 'property_season_remove';

    private const string SAID = 'property.season.said';
    private const int FIRST_YEAR = 2000;
    private const int LAST_YEAR = 2100;

    public function __construct(
        private Environment $twig,
        private SeasonService $service,
        private SeasonRepository $seasons,
        private SeasonCalendarService $calendar,
        private PropertyDirectoryService $directory,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/seasons', name: self::SEASONS, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(PropertyController::READ, subject: 'property')]
    public function seasons(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        return $this->seasonsPage($property, $this->year($request->query->getString('year')), said: $this->said($request));
    }

    #[Route('/properties/{uuid}/seasons', name: self::ADD, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function add(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $payload = $request->getPayload();
        $typed = ['name' => $payload->getString('name'), 'kind' => $payload->getString('kind')];
        if (!$this->valid('property_season_add', $request)) {
            return $this->seasonsPage($property, $this->year(''), $typed, expired: true);
        }

        try {
            $season = $this->service->create($property, $typed['name'], $typed['kind']);
        } catch (InvalidSeasonException $refusal) {
            return $this->seasonsPage($property, $this->year(''), $typed, [$refusal->field => $refusal->getMessage()]);
        }

        return $this->toSeason($season);
    }

    #[Route('/properties/{uuid}/seasons/repeat', name: self::REPEAT, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function repeat(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $year = $this->year($request->getPayload()->getString('year'));
        if (!$this->valid('property_seasons_repeat', $request)) {
            return $this->seasonsPage($property, $year, expired: true);
        }

        $this->say($request, $this->service->repeat($property, $year)->says());

        return new RedirectResponse($this->urls->generate(self::SEASONS, ['uuid' => $property->getUuid(), 'year' => min($year + 1, self::LAST_YEAR)]));
    }

    #[Route('/properties/{uuid}/seasons/{season}/configure', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID, 'season' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['season' => 'uuid'])]
        Season $season,
    ): Response {
        $this->belongs($property, $season);
        if (!$request->isMethod('POST')) {
            return $this->configurePage($season, said: $this->said($request));
        }

        $payload = $request->getPayload();
        $typed = ['name' => $payload->getString('name'), 'kind' => $payload->getString('kind')];
        if (!$this->valid('property_season_configure', $request)) {
            return $this->configurePage($season, $typed, expired: true);
        }

        try {
            $this->service->change($season, $typed['name'], $typed['kind']);
        } catch (InvalidSeasonException $refusal) {
            return $this->configurePage($season, $typed, [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, 'The season is saved.');

        return $this->toSeason($season);
    }

    #[Route('/properties/{uuid}/seasons/{season}/periods', name: self::ADD_PERIOD, requirements: ['uuid' => Requirement::UUID, 'season' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function addPeriod(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['season' => 'uuid'])]
        Season $season,
    ): Response {
        $this->belongs($property, $season);
        $payload = $request->getPayload();
        $typed = ['starts' => $payload->getString('starts'), 'ends' => $payload->getString('ends')];
        if (!$this->valid('property_season_period_add', $request)) {
            return $this->configurePage($season, $typed, expired: true);
        }

        try {
            $this->service->addPeriod($season, $typed['starts'], $typed['ends']);
        } catch (InvalidSeasonException $refusal) {
            return $this->configurePage($season, $typed, [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, 'The period is added.');

        return $this->toSeason($season);
    }

    #[Route('/properties/{uuid}/seasons/{season}/periods/{period}/remove', name: self::REMOVE_PERIOD, requirements: ['uuid' => Requirement::UUID, 'season' => Requirement::UUID, 'period' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function removePeriod(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['season' => 'uuid'])]
        Season $season,
        #[MapEntity(mapping: ['period' => 'uuid'])]
        SeasonPeriod $period,
    ): Response {
        $this->belongs($property, $season);
        if ($period->getSeason() !== $season) {
            throw new NotFoundHttpException();
        }
        if (!$this->valid('property_season_period_remove', $request)) {
            return $this->configurePage($season, expired: true);
        }

        try {
            $this->service->removePeriod($period);
        } catch (InvalidSeasonException $refusal) {
            return $this->configurePage($season, wrong: [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, 'The period is removed.');

        return $this->toSeason($season);
    }

    #[Route('/properties/{uuid}/seasons/{season}/remove', name: self::REMOVE, requirements: ['uuid' => Requirement::UUID, 'season' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function remove(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['season' => 'uuid'])]
        Season $season,
    ): Response {
        $this->belongs($property, $season);
        if (!$this->valid('property_season_remove', $request)) {
            return $this->configurePage($season, expired: true);
        }

        try {
            $this->service->remove($season);
        } catch (InvalidSeasonException $refusal) {
            return $this->configurePage($season, wrong: [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, \sprintf('%s is removed.', $season->getName()));

        return new RedirectResponse($this->urls->generate(self::SEASONS, ['uuid' => $property->getUuid()]));
    }

    private function belongs(Property $property, Season $season): void
    {
        if ($season->getProperty() !== $property) {
            throw new NotFoundHttpException();
        }
    }

    private function valid(string $id, Request $request): bool
    {
        return $this->tokens->isTokenValid(new CsrfToken($id, $request->getPayload()->getString('_token')));
    }

    private function year(string $typed): int
    {
        $year = ctype_digit($typed) ? (int) $typed : (int) (new \DateTimeImmutable())->format('Y');

        return max(self::FIRST_YEAR, min(self::LAST_YEAR, $year));
    }

    private function say(Request $request, string $said): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAID, $said);
        }
    }

    private function said(Request $request): ?string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $said = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::SAID) : [];

        return \is_string($said[0] ?? null) ? $said[0] : null;
    }

    private function toSeason(Season $season): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::CONFIGURE, ['uuid' => $season->getProperty()->getUuid(), 'season' => $season->getUuid()]));
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function seasonsPage(Property $property, int $year, array $typed = [], array $wrong = [], bool $expired = false, ?string $said = null): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/seasons.html.twig', [
            'property' => $property,
            'band' => $this->directory->band($property),
            'seasons' => $this->seasons->findByProperty($property),
            'calendar' => $this->calendar->year($property, $year),
            'kinds' => SeasonKindEnum::cases(),
            'first_year' => self::FIRST_YEAR,
            'last_year' => self::LAST_YEAR,
            'typed' => [...['name' => '', 'kind' => SeasonKindEnum::High->value], ...$typed],
            'wrong' => $wrong,
            'expired' => $expired,
            'said' => $said,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $typed
     * @param array<string, string> $wrong
     */
    private function configurePage(Season $season, array $typed = [], array $wrong = [], bool $expired = false, ?string $said = null): Response
    {
        return new Response($this->twig->render('@VivutioProperty/properties/season_configure.html.twig', [
            'property' => $season->getProperty(),
            'season' => $season,
            'kinds' => SeasonKindEnum::cases(),
            'typed' => [...['name' => $season->getName(), 'kind' => $season->getKind()->value, 'starts' => '', 'ends' => ''], ...$typed],
            'wrong' => $wrong,
            'expired' => $expired,
            'said' => $said,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
