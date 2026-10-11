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

namespace Vivutio\Property\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * When a booking was made, what it is owed is raised from. A booking made
 * before it was kept takes the moment the column arrives.
 */
final class Version20261011003000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'property_booking.made_at';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_booking ADD made_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('UPDATE property_booking SET made_at = NOW() WHERE made_at IS NULL');
        $this->addSql('ALTER TABLE property_booking ALTER made_at SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_booking DROP made_at');
    }
}
