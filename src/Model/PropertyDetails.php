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

namespace Vivutio\Property\Model;

/**
 * Everything the configure page sends, as it was typed; the service reads
 * and refuses it field by field.
 */
final readonly class PropertyDetails
{
    public function __construct(
        public string $name,
        public string $type,
        public string $location,
        public string $status,
        public string $latitude = '',
        public string $longitude = '',
        public string $grading = '',
        public string $summary = '',
        public string $description = '',
        public string $email = '',
        public string $phone = '',
        public string $website = '',
        public string $checkIn = '',
        public string $checkOut = '',
        public string $infantsUpTo = '',
        public string $childrenUpTo = '',
    ) {
    }

    /**
     * The form's fields by the names the page gives them.
     *
     * @return array<string, string>
     */
    public function typed(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'location' => $this->location,
            'status' => $this->status,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'grading' => $this->grading,
            'summary' => $this->summary,
            'description' => $this->description,
            'email' => $this->email,
            'phone' => $this->phone,
            'website' => $this->website,
            'check_in' => $this->checkIn,
            'check_out' => $this->checkOut,
            'infants_up_to' => $this->infantsUpTo,
            'children_up_to' => $this->childrenUpTo,
        ];
    }
}
