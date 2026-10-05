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
 * Cancellation: a property's tiers, and a season's own or none.
 */
final class Version20261005000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'property_property.cancellation, property_season.cancellation';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE property_property ADD cancellation JSON DEFAULT '[]' NOT NULL");
        $this->addSql('ALTER TABLE property_property ALTER cancellation DROP DEFAULT');
        $this->addSql('ALTER TABLE property_season ADD cancellation JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_season DROP cancellation');
        $this->addSql('ALTER TABLE property_property DROP cancellation');
    }
}
