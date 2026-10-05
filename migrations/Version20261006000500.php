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
 * A booking made by a partner: the partner, the discount and days to pay it
 * was made at, and what it cost before the discount. A booking already made
 * was direct and cost what it costs.
 */
final class Version20261006000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'property_booking.partner_id, discount, credit_days and gross';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_booking ADD partner_id VARCHAR(36) DEFAULT NULL');
        $this->addSql("ALTER TABLE property_booking ADD discount NUMERIC(5, 2) DEFAULT '0.00' NOT NULL");
        $this->addSql('ALTER TABLE property_booking ADD credit_days INT DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE property_booking ADD gross INT DEFAULT NULL');
        $this->addSql('UPDATE property_booking SET gross = total');
        $this->addSql('ALTER TABLE property_booking ALTER gross SET NOT NULL');
        $this->addSql('ALTER TABLE property_booking ALTER discount DROP DEFAULT');
        $this->addSql('ALTER TABLE property_booking ALTER credit_days DROP DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE property_booking DROP partner_id, DROP discount, DROP credit_days, DROP gross');
    }
}
