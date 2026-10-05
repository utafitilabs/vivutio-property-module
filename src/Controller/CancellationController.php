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
use Vivutio\Property\Exception\InvalidCancellationException;
use Vivutio\Property\Repository\SeasonRepository;
use Vivutio\Property\Service\CancellationService;

/**
 * A property's cancellation page, card by card: its own tiers, and for each
 * season whether it follows them, charges nothing or has its own. Changed by
 * the tiers alone; read as bands on the Rates tab.
 */
final readonly class CancellationController
{
    public const string CONFIGURE = 'property_cancellation';

    private const string SAID = 'property.cancellation.said';

    public function __construct(
        private Environment $twig,
        private CancellationService $service,
        private SeasonRepository $seasons,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/cancellation', name: self::CONFIGURE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(PropertyController::CHANGE, subject: 'property')]
    public function configure(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        if (!$request->isMethod('POST')) {
            $session = $request->getSession();
            $said = $session instanceof FlashBagAwareSessionInterface ? $session->getFlashBag()->get(self::SAID) : [];

            return $this->page($property, said: \is_string($said[0] ?? null) ? $said[0] : null);
        }

        $payload = $request->getPayload();
        $card = $payload->getString('card');
        $rows = [];
        foreach ($payload->all('tiers') as $row) {
            if (\is_array($row)) {
                $rows[] = [\is_string($row['days'] ?? null) ? $row['days'] : '', \is_string($row['percent'] ?? null) ? $row['percent'] : ''];
            }
        }
        if (!$this->tokens->isTokenValid(new CsrfToken('property_cancellation', $payload->getString('_token')))) {
            return $this->page($property, expired: true);
        }

        try {
            if ('property' === $card) {
                $this->service->changeProperty($property, $rows);
                $said = "The property's tiers are saved.";
            } else {
                $season = null;
                foreach ($this->seasons->findByProperty($property) as $candidate) {
                    if ((string) $candidate->getUuid() === $card) {
                        $season = $candidate;
                    }
                }
                if (null === $season) {
                    throw new InvalidCancellationException('card', 'Choose the property or one of its seasons.');
                }
                $this->service->changeSeason($season, $payload->getString('mode'), $rows);
                $said = \sprintf('%s is saved.', $season->getName());
            }
        } catch (InvalidCancellationException $refusal) {
            return $this->page($property, [$card => $refusal->getMessage()], typed: [$card => ['mode' => $payload->getString('mode'), 'tiers' => $rows]]);
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(self::SAID, $said);
        }

        return new RedirectResponse($this->urls->generate(self::CONFIGURE, ['uuid' => $property->getUuid()]));
    }

    /**
     * @param array<string, string>                                                  $wrong by card
     * @param array<string, array{mode: string, tiers: list<array{string, string}>}> $typed by card
     */
    private function page(Property $property, array $wrong = [], array $typed = [], bool $expired = false, ?string $said = null): Response
    {
        $cards = [['key' => 'property', 'name' => 'property', 'title' => "The property's tiers", 'mode' => 'own', 'tiers' => self::rows($property->getCancellation())]];
        foreach ($this->seasons->findByProperty($property) as $season) {
            $own = $season->getCancellation();
            $cards[] = ['key' => (string) $season->getUuid(), 'name' => $season->getName(), 'title' => $season->getName(), 'mode' => null === $own ? 'follow' : ([] === $own ? 'no_charge' : 'own'), 'tiers' => self::rows($own ?? [])];
        }
        foreach ($cards as $i => $card) {
            if (isset($typed[$card['key']])) {
                $cards[$i]['mode'] = '' === $typed[$card['key']]['mode'] ? $card['mode'] : $typed[$card['key']]['mode'];
                $cards[$i]['tiers'] = array_pad($typed[$card['key']]['tiers'], CancellationService::MOST_TIERS, ['', '']);
            }
        }

        return new Response($this->twig->render('@VivutioProperty/properties/cancellation.html.twig', [
            'property' => $property,
            'cards' => $cards,
            'bands' => $this->service->bands($property->getCancellation()),
            'wrong' => $wrong,
            'expired' => $expired,
            'said' => $said,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * Kept tiers as the form's rows, padded to the most a card holds.
     *
     * @param list<array{days: int, percent: int}> $tiers
     *
     * @return list<array{string, string}>
     */
    private static function rows(array $tiers): array
    {
        return array_pad(array_map(static fn (array $tier): array => [(string) $tier['days'], (string) $tier['percent']], $tiers), CancellationService::MOST_TIERS, ['', '']);
    }
}
