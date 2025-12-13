<?php

namespace PhpPgAdmin\Database\Action;

use PhpPgAdmin\Database\Action;

class Language extends Action
{
    // Base constructor inherited from Action

    /**
     * Gets all languages.
     */
    public function getLanguages($all = false)
    {
        $conf = $this->conf();

        if ($conf['show_system'] || $all) {
            $where = '';
        } else {
            $where = 'WHERE lanispl';
        }

        $sql = "
            SELECT
                lanname, lanpltrusted,
                lanplcallfoid::pg_catalog.regproc AS lanplcallf
            FROM
                pg_catalog.pg_language
            {$where}
            ORDER BY lanname
        ";

        return $this->connection->selectSet($sql);
    }
}
