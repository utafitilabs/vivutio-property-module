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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Vivutio\Bundle\IdentityBundle\Model\DeletionPageContext;
use Vivutio\Bundle\IdentityBundle\Security\DeletionVoter;
use Vivutio\Bundle\IdentityBundle\Service\DeletionPageService;
use Vivutio\Property\Entity\Property;

/**
 * A property deleted by a Super Admin, on the core's delete page, reached
 * from the Danger card of its Configure page.
 */
final readonly class PropertyDeletionController
{
    public const string DELETE = 'property_delete';

    public function __construct(
        private DeletionPageService $pages,
        private UrlGeneratorInterface $urls,
    ) {
    }

    #[Route('/properties/{uuid}/delete', name: self::DELETE, requirements: ['uuid' => Requirement::UUID], methods: ['GET', 'POST'])]
    #[IsGranted(DeletionVoter::DELETE, subject: 'property')]
    public function delete(
        Request $request,
        #[MapEntity(mapping: ['uuid' => 'uuid'])]
        Property $property,
    ): Response {
        return $this->pages->respond($request, $property, new DeletionPageContext(
            'properties',
            [
                ['label' => 'Properties', 'url' => $this->urls->generate(PropertyController::REGISTER)],
                ['label' => (string) $property->getName(), 'url' => $this->urls->generate(PropertyController::SHOW, ['uuid' => $property->getUuid()])],
            ],
            $this->urls->generate(PropertyController::CONFIGURE, ['uuid' => $property->getUuid()]),
            $this->urls->generate(PropertyController::REGISTER),
        ));
    }
}
