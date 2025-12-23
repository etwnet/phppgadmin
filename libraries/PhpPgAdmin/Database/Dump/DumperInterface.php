<?php

namespace PhpPgAdmin\Database\Dump;

/**
 * Interface for all dumper classes.
 */
interface DumperInterface
{
    /**
     * Performs the dump of the specified subject.
     * 
     * @param string $subject The subject to dump (e.g., 'table', 'schema', 'database')
     * @param array $params Parameters for the dump (e.g., ['table' => 'my_table', 'schema' => 'public'])
     * @param array $options Options for the dump (e.g., ['clean' => true, 'if_not_exists' => true, 'data_only' => false])
     * @return void
     */
    public function dump($subject, array $params, array $options = []);
}
