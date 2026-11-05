<?php

declare(strict_types=1);

namespace VenPosTPV\Tests\Unit\Service\Backup;

use PHPUnit\Framework\TestCase;
use VenPosTPV\Service\Backup\SqlDumper;

final class SqlDumperTest extends TestCase
{
    public function testASingleStatementTriggerIsAPlainStatementWithoutItsDefiner(): void
    {
        $create = "CREATE DEFINER=`tpv_user`@`%` TRIGGER fiscal_record_no_update BEFORE UPDATE ON fiscal_record FOR EACH ROW\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fiscal_record is append-only'";

        $this->assertSame(
            "DROP TRIGGER IF EXISTS `fiscal_record_no_update`;\n"
            . "CREATE TRIGGER fiscal_record_no_update BEFORE UPDATE ON fiscal_record FOR EACH ROW\n"
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fiscal_record is append-only';\n",
            SqlDumper::triggerStatement('fiscal_record_no_update', $create)
        );
    }

    public function testATriggerWithSemicolonsInItsBodyIsWrappedInADelimiterBlock(): void
    {
        $create = "CREATE DEFINER='root'@'localhost' TRIGGER t1 BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; SET NEW.y = 2; END";

        $this->assertSame(
            "DROP TRIGGER IF EXISTS `t1`;\n"
            . "DELIMITER ;;\n"
            . "CREATE TRIGGER t1 BEFORE INSERT ON a FOR EACH ROW BEGIN SET NEW.x = 1; SET NEW.y = 2; END;;\n"
            . "DELIMITER ;\n",
            SqlDumper::triggerStatement('t1', $create)
        );
    }
}
