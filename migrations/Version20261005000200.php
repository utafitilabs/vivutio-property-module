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
 * A property in full: its type and status in place of a unit count, which
 * its rooms will give; where it is on a map, its grading, what it says of
 * itself, how it is reached, and its house rules.
 */
final class Version20261005000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'property_property: type, status, coordinates, grading, summary, description, contact, check-in and check-out, ages; units and unit dropped';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_property DROP units, DROP unit');
        $this->addSql("ALTER TABLE property_property ADD type VARCHAR(16) DEFAULT 'lodge' NOT NULL");
        $this->addSql('ALTER TABLE property_property ALTER type DROP DEFAULT');
        $this->addSql("ALTER TABLE property_property ADD status VARCHAR(16) DEFAULT 'draft' NOT NULL");
        $this->addSql('ALTER TABLE property_property ALTER status DROP DEFAULT');
        $this->addSql('ALTER TABLE property_property ADD latitude NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD longitude NUMERIC(9, 6) DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD grading SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD summary VARCHAR(200) DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD description TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD email VARCHAR(180) DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD phone VARCHAR(40) DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD website VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD check_in_from TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD check_out_by TIME(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD infants_up_to SMALLINT DEFAULT NULL');
        $this->addSql('ALTER TABLE property_property ADD children_up_to SMALLINT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_property DROP type, DROP status, DROP latitude, DROP longitude, DROP grading, DROP summary, DROP description, DROP email, DROP phone, DROP website, DROP check_in_from, DROP check_out_by, DROP infants_up_to, DROP children_up_to');
        $this->addSql('ALTER TABLE property_property ADD units INT DEFAULT 1 NOT NULL');
        $this->addSql("ALTER TABLE property_property ADD unit VARCHAR(16) DEFAULT 'rooms' NOT NULL");
    }
}
