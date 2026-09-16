<?php

namespace Tests\Support;

use Craft;

/** Exercise transaction rollback through a real database write failure on either driver. */
final class FailingRelationWrites
{
    public static function install(string $name, ?int $ownerId = null): void
    {
        $db = Craft::$app->db;
        $trigger = $db->quoteTableName($name);
        $function = $db->quoteTableName($name . '_fn');
        $condition = $ownerId === null ? 'TRUE' : 'NEW.' . $db->quoteColumnName('ownerId') . ' = ' . $ownerId;

        if ($db->getDriverName() === 'pgsql') {
            $db->createCommand("CREATE FUNCTION {$function}() RETURNS trigger AS \$\$ BEGIN IF {$condition} THEN RAISE EXCEPTION 'Injected relation write failure'; END IF; RETURN NEW; END; \$\$ LANGUAGE plpgsql")->execute();
            $db->createCommand("CREATE TRIGGER {$trigger} BEFORE INSERT ON {{%hyper_links}} FOR EACH ROW EXECUTE FUNCTION {$function}()")->execute();
        } else {
            $db->createCommand("CREATE TRIGGER {$trigger} BEFORE INSERT ON {{%hyper_links}} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Injected relation write failure'; END IF; END")->execute();
        }
    }

    public static function remove(string $name): void
    {
        $db = Craft::$app->db;
        $trigger = $db->quoteTableName($name);
        if ($db->getDriverName() === 'pgsql') {
            $db->createCommand("DROP TRIGGER {$trigger} ON {{%hyper_links}}")->execute();
            $db->createCommand('DROP FUNCTION ' . $db->quoteTableName($name . '_fn') . '()')->execute();
        } else {
            $db->createCommand("DROP TRIGGER {$trigger}")->execute();
        }
    }
}
