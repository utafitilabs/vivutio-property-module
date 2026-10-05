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
use Vivutio\Property\Entity\Rate;
use Vivutio\Property\Entity\RateTerms;
use Vivutio\Property\Entity\SeasonPeriod;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Enum\PricingEnum;
use Vivutio\Property\Exception\InvalidRateException;
use Vivutio\Property\Exception\QuoteRefusedException;
use Vivutio\Property\Model\Quote;
use Vivutio\Property\Repository\RateRepository;
use Vivutio\Property\Repository\RateTermsRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Repository\SeasonPeriodRepository;
use Vivutio\Property\Repository\SeasonRepository;
use Vivutio\Property\Service\CancellationService;
use Vivutio\Property\Service\PropertyDirectoryService;
use Vivutio\Property\Service\RateQuoteService;
use Vivutio\Property\Service\RateService;

/**
 * A property's Rates tab: its rate sheet's setup, a year's sheet for each
 * board basis it sells, room types down and season periods across, each
 * period's terms, and what a stay costs. Read with properties.read; changed
 * by the tiers alone.
 */
final readonly class RateController
{
    public const string RATES = 'property_rates';
    public const string SETUP = 'property_rates_setup';
    public const string SAVE = 'property_rates_save';
    public const string TERMS = 'property_rate_terms_save';
    public const string CARRY = 'property_rates_carry';

    private const string SAID = 'property.rates.said';

    public function __construct(
        private Environment $twig,
        private RateService $service,
        private RateQuoteService $quotes,
        private RateRepository $rates,
        private RateTermsRepository $terms,
        private RoomTypeRepository $rooms,
        private SeasonPeriodRepository $periods,
        private PropertyDirectoryService $directory,
        private CancellationService $cancellation,
        private SeasonRepository $seasons,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/rates', name: self::RATES, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(PropertyController::READ)]
    public function rates(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $year = $this->year($request->query->getString('year'));
        $asked = $request->query->has('room') ? $request->query->all() : null;

        return $this->page($property, $year, quote: $asked, said: $this->said($request));
    }

    #[Route('/properties/{uuid}/rates/setup', name: self::SETUP, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE)]
    public function setup(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $payload = $request->getPayload();
        $typed = ['currency' => $payload->getString('currency'), 'pricing' => $payload->getString('pricing'), 'boards' => array_values(array_filter($payload->all('boards'), \is_string(...)))];
        $year = $this->year($payload->getString('year'));
        if (!$this->valid('property_rates_setup', $request)) {
            return $this->page($property, $year, setup: $typed, expired: true);
        }

        try {
            $this->service->setup($property, $typed['currency'], $typed['pricing'], $typed['boards']);
        } catch (InvalidRateException $refusal) {
            return $this->page($property, $year, setup: $typed, wrong: [$refusal->field => $refusal->getMessage()]);
        }

        return $this->back($request, $property, $year, 'The rate sheet is set up.');
    }

    #[Route('/properties/{uuid}/rates', name: self::SAVE, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE)]
    public function save(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $payload = $request->getPayload();
        $year = $this->year($payload->getString('year'));
        $board = BoardBasisEnum::tryFrom($payload->getString('board'));
        $amounts = self::grid($payload->all('amounts'));
        if (!$this->valid('property_rates_save', $request)) {
            return $this->page($property, $year, typed: $amounts, expired: true);
        }

        try {
            if (null === $board) {
                throw new InvalidRateException('board', 'Choose a board basis it sells.');
            }
            $saved = $this->service->saveRates($property, $board, $amounts);
        } catch (InvalidRateException $refusal) {
            return $this->page($property, $year, typed: $amounts, wrong: [$refusal->field => $refusal->getMessage()], board: $board);
        }

        return $this->back($request, $property, $year, \sprintf('%s rates for %d are saved (%d %s).', $board->label(), $year, $saved, 1 === $saved ? 'cell' : 'cells'));
    }

    #[Route('/properties/{uuid}/rates/terms', name: self::TERMS, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE)]
    public function terms(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $payload = $request->getPayload();
        $year = $this->year($payload->getString('year'));
        $terms = self::grid($payload->all('terms'));
        if (!$this->valid('property_rate_terms_save', $request)) {
            return $this->page($property, $year, typedTerms: $terms, expired: true);
        }

        try {
            $this->service->saveTerms($property, $terms);
        } catch (InvalidRateException $refusal) {
            return $this->page($property, $year, typedTerms: $terms, wrong: [$refusal->field => $refusal->getMessage()]);
        }

        return $this->back($request, $property, $year, 'The terms are saved.');
    }

    #[Route('/properties/{uuid}/rates/carry', name: self::CARRY, requirements: ['uuid' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(PropertyController::CHANGE)]
    public function carry(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $payload = $request->getPayload();
        $year = $this->year($payload->getString('year'));
        if (!$this->valid('property_rates_carry', $request)) {
            return $this->page($property, $year, expired: true);
        }

        try {
            $carried = $this->service->carry($property, $year, $payload->getString('raise'));
        } catch (InvalidRateException $refusal) {
            return $this->page($property, $year, wrong: [$refusal->field => $refusal->getMessage()], raise: $payload->getString('raise'));
        }

        return $this->back($request, $property, min($year + 1, 2100), $carried->says());
    }

    /**
     * A two-level grid of typed cells, as a form sends it.
     *
     * @param array<mixed> $sent
     *
     * @return array<string, array<string, string>>
     */
    private static function grid(array $sent): array
    {
        $grid = [];
        foreach ($sent as $row => $cells) {
            if (!\is_array($cells)) {
                continue;
            }
            foreach ($cells as $column => $value) {
                if (\is_string($value)) {
                    $grid[(string) $row][(string) $column] = $value;
                }
            }
        }

        return $grid;
    }

    private function year(string $typed): int
    {
        $year = ctype_digit($typed) ? (int) $typed : (int) (new \DateTimeImmutable())->format('Y');

        return max(2000, min(2100, $year));
    }

    private function valid(string $id, Request $request): bool
    {
        return $this->tokens->isTokenValid(new CsrfToken($id, $request->getPayload()->getString('_token')));
    }

    private function back(Request $request, Property $property, int $year, string $said): RedirectResponse
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAID, $said);
        }

        return new RedirectResponse($this->urls->generate(self::RATES, ['uuid' => $property->getUuid(), 'year' => $year]));
    }

    private function said(Request $request): ?string
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $said = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::SAID) : [];

        return \is_string($said[0] ?? null) ? $said[0] : null;
    }

    /**
     * @param array{currency: string, pricing: string, boards: list<string>}|null $setup
     * @param array<string, array<string, string>>|null                           $typed
     * @param array<string, array<string, string>>|null                           $typedTerms
     * @param array<mixed>|null                                                   $quote
     * @param array<string, string>                                               $wrong
     */
    private function page(Property $property, int $year, ?array $setup = null, ?array $typed = null, ?array $typedTerms = null, ?array $quote = null, array $wrong = [], bool $expired = false, ?BoardBasisEnum $board = null, ?string $said = null, string $raise = ''): Response
    {
        $first = new \DateTimeImmutable($year.'-01-01');
        $last = new \DateTimeImmutable($year.'-12-31');
        $periods = array_values(array_filter($this->periods->findByProperty($property), static fn (SeasonPeriod $period): bool => $period->getStarts() <= $last && $period->getEnds() >= $first));

        $amounts = [];
        foreach ($this->rates->findByProperty($property) as $rate) {
            $amounts[$rate->getBoard()->value][(string) $rate->getRoomType()->getUuid()][(string) $rate->getPeriod()->getUuid()] = $rate->getAmount();
        }
        $terms = [];
        foreach ($periods as $period) {
            $found = $this->terms->findOneBy(['period' => $period]);
            $terms[(string) $period->getUuid()] = array_map(static fn (int $share): string => (string) $share, $found instanceof RateTerms ? $found->getShares() : []);
        }
        if (null !== $typed && null !== $board) {
            $amounts[$board->value] = $typed;
        }
        if (null !== $typedTerms) {
            $terms = [...$terms, ...$typedTerms];
        }

        [$quoted, $refused] = null === $quote ? [null, null] : $this->quoted($property, $quote);
        $cancelled = null === $quote || !\is_string($quote['cancelled'] ?? null) ? false : \DateTimeImmutable::createFromFormat('!Y-m-d', $quote['cancelled']);
        $charge = $quoted instanceof Quote && false !== $cancelled ? $this->cancellation->charge($quoted, $cancelled) : null;

        $policies = [];
        foreach ($this->seasons->findByProperty($property) as $season) {
            $policies[] = ['season' => $season, 'follows' => null === $season->getCancellation(), 'bands' => $this->cancellation->bands($this->cancellation->tiersOf($season))];
        }

        return new Response($this->twig->render('@VivutioProperty/properties/rates.html.twig', [
            'property' => $property,
            'band' => $this->directory->band($property),
            'year' => $year,
            'periods' => $periods,
            'rooms' => $this->rooms->findOnSaleByProperty($property),
            'sold' => array_values(array_filter(BoardBasisEnum::cases(), static fn (BoardBasisEnum $basis): bool => \in_array($basis->value, $property->getBoards(), true))),
            'boards' => BoardBasisEnum::cases(),
            'pricings' => PricingEnum::cases(),
            'amounts' => $amounts,
            'terms' => $terms,
            'term_names' => RateTerms::TERMS,
            'setup' => $setup ?? ['currency' => (string) $property->getCurrency(), 'pricing' => $property->getPricing()->value, 'boards' => $property->getBoards()],
            'asked' => $quote ?? [],
            'quote' => $quoted,
            'charge' => $charge,
            'property_bands' => $this->cancellation->bands($property->getCancellation()),
            'policies' => $policies,
            'refused' => $refused,
            'raise' => $raise,
            'wrong' => $wrong,
            'expired' => $expired,
            'said' => $said,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<mixed> $asked
     *
     * @return array{?Quote, ?string}
     */
    private function quoted(Property $property, array $asked): array
    {
        $text = static fn (string $key): string => \is_string($asked[$key] ?? null) ? $asked[$key] : '';
        $room = null;
        foreach ($this->rooms->findOnSaleByProperty($property) as $candidate) {
            if ((string) $candidate->getUuid() === $text('room')) {
                $room = $candidate;
            }
        }
        $board = BoardBasisEnum::tryFrom($text('board'));
        $arrival = \DateTimeImmutable::createFromFormat('!Y-m-d', $text('arrival'));
        if (null === $room || null === $board || false === $arrival) {
            return [null, 'Choose a room type, a board basis and the night of arrival.'];
        }

        try {
            return [$this->quotes->quote($room, $board, $arrival, (int) $text('nights'), (int) $text('adults'), (int) $text('children'), (int) $text('infants')), null];
        } catch (QuoteRefusedException $refusal) {
            return [null, $refusal->getMessage()];
        }
    }
}
