<?php

namespace PhpPgAdmin\Database\Dump;

use PhpPgAdmin\Core\AbstractContext;
use PhpPgAdmin\Core\AppContainer;
use PhpPgAdmin\Database\Actions\AclActions;
use PhpPgAdmin\Database\Postgres;

/**
 * Base class for all dumpers providing shared utilities.
 */
abstract class AbstractDumper extends AbstractContext implements DumperInterface
{
    /**
     * @var Postgres
     */
    protected $connection;

    /**
     * @var resource|null
     */
    protected $outputStream = null;

    public function __construct(Postgres $connection = null)
    {
        $this->connection = $connection ?? AppContainer::getPostgres();
    }

    /**
     * Sets the output stream for the dump.
     * 
     * @param resource $stream
     */
    public function setOutputStream($stream)
    {
        $this->outputStream = $stream;
    }

    /**
     * Writes a string to the output stream or echoes it.
     * 
     * @param string $string
     */
    protected function write($string)
    {
        if ($this->outputStream) {
            fwrite($this->outputStream, $string);
        } else {
            echo $string;
            // Flush output buffer if possible to support streaming
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        }
    }

    /**
     * Generates a header for the dump.
     */
    protected function writeHeader($title)
    {
        $this->write("--\n");
        $this->write("-- phpPgAdmin SQL Dump\n");
        $this->write("-- Subject: {$title}\n");
        $this->write("-- Date: " . date('Y-m-d H:i:s') . "\n");
        $this->write("--\n\n");
    }

    /**
     * Generates GRANT/REVOKE SQL for an object.
     * 
     * @param string $objectName
     * @param string $objectType (table, view, sequence, database, function, language, schema, tablespace)
     * @param string|null $schema
     */
    protected function writePrivileges($objectName, $objectType, $schema = null)
    {
        $aclActions = new AclActions($this->connection);
        $privileges = $aclActions->getPrivileges($objectName, $objectType);

        if (empty($privileges)) {
            return;
        }

        $this->write("\n-- Privileges for {$objectType} {$objectName}\n");

        // Reconstruct GRANTS from parsed ACLs
        // This logic is adapted from TableActions::getPrivilegesSql but generalized
        foreach ($privileges as $priv) {
            $grantee = ($priv[1] == '') ? 'PUBLIC' : "\"{$priv[1]}\"";
            $privs = implode(', ', $priv[2]);

            if ($privs == 'ALL PRIVILEGES') {
                $this->write("GRANT ALL ON {$objectType} \"{$objectName}\" TO {$grantee};\n");
            } else {
                $this->write("GRANT {$privs} ON {$objectType} \"{$objectName}\" TO {$grantee}");
                if (!empty($priv[4])) {
                    $this->write(" WITH GRANT OPTION");
                }
                $this->write(";\n");
            }
        }
    }

    /**
     * Helper to generate DROP statement if requested.
     */
    protected function writeDrop($type, $name, $options)
    {
        if (!empty($options['clean'])) {
            $this->write("DROP {$type} IF EXISTS \"{$name}\" CASCADE;\n");
        }
    }

    /**
     * Helper to generate IF NOT EXISTS clause.
     */
    protected function getIfNotExists($options)
    {
        return (!empty($options['if_not_exists'])) ? "IF NOT EXISTS " : "";
    }
}
