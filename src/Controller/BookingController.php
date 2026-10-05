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

use Psr\Clock\ClockInterface;
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
use Vivutio\Property\Entity\PropertyBooking;
use Vivutio\Property\Enum\BoardBasisEnum;
use Vivutio\Property\Enum\BookingStatusEnum;
use Vivutio\Property\Exception\InvalidBookingException;
use Vivutio\Property\Model\BookingDetails;
use Vivutio\Property\Repository\PropertyBookingRepository;
use Vivutio\Property\Repository\RoomTypeRepository;
use Vivutio\Property\Service\CancellationService;
use Vivutio\Property\Service\PropertyBookingService;
use Vivutio\Property\Service\PropertyDirectoryService;

/**
 * A property's Bookings tab, a new booking, and a booking's own page with
 * confirming and cancelling it. Read with property_bookings.read, recorded
 * with .record, confirmed and cancelled with .manage: a module's pairs a
 * department must allow, asked about the property or the booking, so reach
 * applies.
 */
final readonly class BookingController
{
    public const string BOOKINGS = 'property_bookings';
    public const string NEW = 'property_booking_new';
    public const string BOOKING = 'property_booking';
    public const string CONFIRM = 'property_booking_confirm';
    public const string CANCEL = 'property_booking_cancel';

    public const string READ = 'property_bookings.read';
    public const string RECORD = 'property_bookings.record';
    public const string MANAGE = 'property_bookings.manage';

    private const string SAID = 'property.booking.said';

    public function __construct(
        private Environment $twig,
        private PropertyBookingService $service,
        private PropertyBookingRepository $bookings,
        private RoomTypeRepository $rooms,
        private PropertyDirectoryService $directory,
        private CancellationService $cancellation,
        private ClockInterface $clock,
        private CsrfTokenManagerInterface $tokens,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/bookings', name: self::BOOKINGS, requirements: ['uuid' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ, subject: 'property')]
    public function bookings(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        $all = $this->bookings->findByProperty($property);
        $status = BookingStatusEnum::tryFrom($request->query->getString('status'));
        $counts = array_fill_keys(array_map(static fn (BookingStatusEnum $case): string => $case->value, BookingStatusEnum::cases()), 0);
        foreach ($all as $booking) {
            ++$counts[$booking->getStatus()->value];
        }

        return new Response($this->twig->render('@VivutioProperty/bookings/index.html.twig', [
            'property' => $property,
            'band' => $this->directory->band($property),
            'bookings' => array_values(array_filter($all, static fn (PropertyBooking $booking): bool => null === $status || $booking->getStatus() === $status)),
            'total' => \count($all),
            'counts' => $counts,
            'statuses' => BookingStatusEnum::cases(),
            'status' => $status,
            'now' => $this->clock->now(),
            'said' => $this->said($request),
        ]));
    }

    #[Route('/properties/{uuid}/bookings/new', name: self::NEW, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(self::RECORD, subject: 'property')]
    public function new(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        if (!$request->isMethod('POST')) {
            return $this->newPage($property, []);
        }

        $sent = $request->getPayload()->all();
        if (!$this->tokens->isTokenValid(new CsrfToken('property_booking_new', $request->getPayload()->getString('_token')))) {
            return $this->newPage($property, $sent, expired: true);
        }

        try {
            $booking = $this->service->record($property, BookingDetails::fromForm($sent));
        } catch (InvalidBookingException $refusal) {
            return $this->newPage($property, $sent, [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, \sprintf('%s is %s.', $booking->getReference(), mb_strtolower($booking->getStatus()->label())));

        return $this->to($booking);
    }

    #[Route('/properties/{uuid}/bookings/{booking}', name: self::BOOKING, requirements: ['uuid' => Requirement::UUID, 'booking' => Requirement::UUID], methods: ['GET'])]
    #[IsGranted(self::READ, subject: 'booking')]
    public function booking(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['booking' => 'uuid'])]
        PropertyBooking $booking,
    ): Response {
        $this->belongs($property, $booking);

        return $this->bookingPage($booking, said: $this->said($request));
    }

    #[Route('/properties/{uuid}/bookings/{booking}/confirm', name: self::CONFIRM, requirements: ['uuid' => Requirement::UUID, 'booking' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE, subject: 'booking')]
    public function confirm(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['booking' => 'uuid'])]
        PropertyBooking $booking,
    ): Response {
        $this->belongs($property, $booking);
        if (!$this->tokens->isTokenValid(new CsrfToken('property_booking_confirm', $request->getPayload()->getString('_token')))) {
            return $this->bookingPage($booking, expired: true);
        }

        try {
            $this->service->confirm($booking);
        } catch (InvalidBookingException $refusal) {
            return $this->bookingPage($booking, [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, \sprintf('%s is confirmed.', $booking->getReference()));

        return $this->to($booking);
    }

    #[Route('/properties/{uuid}/bookings/{booking}/cancel', name: self::CANCEL, requirements: ['uuid' => Requirement::UUID, 'booking' => Requirement::UUID], methods: ['POST'])]
    #[IsGranted(self::MANAGE, subject: 'booking')]
    public function cancel(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
        #[MapEntity(mapping: ['booking' => 'uuid'])]
        PropertyBooking $booking,
    ): Response {
        $this->belongs($property, $booking);
        if (!$this->tokens->isTokenValid(new CsrfToken('property_booking_cancel', $request->getPayload()->getString('_token')))) {
            return $this->bookingPage($booking, expired: true);
        }

        try {
            $charge = $this->service->cancel($booking, $request->getPayload()->getString('reason'));
        } catch (InvalidBookingException $refusal) {
            return $this->bookingPage($booking, [$refusal->field => $refusal->getMessage()]);
        }
        $this->say($request, \sprintf('%s is cancelled; cancelling cost %s %s.', $booking->getReference(), $booking->getCurrency(), number_format($charge / 100, 2)));

        return $this->to($booking);
    }

    private function belongs(Property $property, PropertyBooking $booking): void
    {
        if ($booking->getProperty() !== $property) {
            throw new NotFoundHttpException();
        }
    }

    private function to(PropertyBooking $booking): RedirectResponse
    {
        return new RedirectResponse($this->urls->generate(self::BOOKING, ['uuid' => $booking->getProperty()->getUuid(), 'booking' => $booking->getUuid()]));
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

    /**
     * @param array<mixed>          $sent
     * @param array<string, string> $wrong
     */
    private function newPage(Property $property, array $sent, array $wrong = [], bool $expired = false): Response
    {
        $details = BookingDetails::fromForm($sent);
        $lines = array_pad($details->lines, 3, ['room' => '', 'rooms' => '1', 'adults' => '2', 'children' => '0', 'infants' => '0', 'board' => $property->getBoards()[0] ?? '']);

        return new Response($this->twig->render('@VivutioProperty/bookings/new.html.twig', [
            'property' => $property,
            'band' => $this->directory->band($property),
            'typed' => $details,
            'lines' => $lines,
            'rooms' => $this->rooms->findOnSaleByProperty($property),
            'boards' => array_values(array_filter(BoardBasisEnum::cases(), static fn (BoardBasisEnum $basis): bool => \in_array($basis->value, $property->getBoards(), true))),
            'today' => $this->clock->now(),
            'wrong' => $wrong,
            'expired' => $expired,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @param array<string, string> $wrong
     */
    private function bookingPage(PropertyBooking $booking, array $wrong = [], bool $expired = false, ?string $said = null): Response
    {
        $now = $this->clock->now();

        return new Response($this->twig->render('@VivutioProperty/bookings/booking.html.twig', [
            'property' => $booking->getProperty(),
            'booking' => $booking,
            'now' => $now,
            'lapsed' => $booking->isLapsed($now),
            'charge_today' => BookingStatusEnum::Confirmed === $booking->getStatus() ? $this->service->chargeIf($booking, $now) : null,
            'bands' => $this->cancellation->bands($booking->getPricedNights()[0]['tiers'] ?? []),
            'wrong' => $wrong,
            'expired' => $expired,
            'said' => $said,
        ]), [] === $wrong && !$expired ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
