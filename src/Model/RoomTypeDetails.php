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
 * What the room type forms send, as it was typed; the service reads and
 * refuses it field by field.
 */
final readonly class RoomTypeDetails
{
    /**
     * @param list<string> $features
     */
    public function __construct(
        public string $name,
        public string $sleeps,
        public string $adults,
        public string $count,
        public string $description = '',
        public array $features = [],
        public bool $onSale = true,
    ) {
    }

    /**
     * The form's fields by the names the page gives them.
     *
     * @return array{name: string, sleeps: string, adults: string, count: string, description: string, features: list<string>, on_sale: string}
     */
    public function typed(): array
    {
        return [
            'name' => $this->name,
            'sleeps' => $this->sleeps,
            'adults' => $this->adults,
            'count' => $this->count,
            'description' => $this->description,
            'features' => $this->features,
            'on_sale' => $this->onSale ? 'yes' : 'no',
        ];
    }
}
