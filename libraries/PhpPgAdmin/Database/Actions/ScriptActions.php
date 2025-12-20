<?php

namespace PhpPgAdmin\Database\Actions;

use ADORecordSet;
use PhpPgAdmin\Database\AbstractActions;
use PhpPgAdmin\Database\QueryResult;


class ScriptActions extends AbstractActions
{

    /**
     * A private helper method for executeScript that advances the
     * character by 1.  In psql this is careful to take into account
     * multibyte languages, but we don't at the moment, so this function
     * is someone redundant, since it will always advance by 1
     * @param int &$i The current character position in the line
     * @param int &$prevlen Length of previous character (ie. 1)
     * @param int &$thislen Length of current character (ie. 1)
     */
    private
        function advance_1(
        &$i,
        &$prevlen,
        &$thislen
    ) {
        $prevlen = $thislen;
        $i += $thislen;
        $thislen = 1;
    }

    /**
     * Private helper method to detect a valid $foo$ quote delimiter at
     * the start of the parameter dquote
     * @return bool True if valid, false otherwise
     */
    private function valid_dolquote($dquote)
    {
        // XXX: support multibyte
        return (preg_match('/^[$][$]/', $dquote) || preg_match('/^[$][_[:alpha:]][_[:alnum:]]*[$]/', $dquote));
    }

    /**
     * Handle COPY FROM data transfer for a query result
     * @param \PgSql\Connection $conn The PostgreSQL connection resource
     * @param QueryResult $result The wrapped query result
     * @param resource $fd File descriptor for reading COPY data
     * @param int &$lineno Line number counter (incremented as lines are read)
     * @return bool True if COPY completed successfully
     */
    private function handleCopyData($conn, $result, $fd, &$lineno)
    {
        if (!$result->isCopy) {
            return false;
        }

        while (!feof($fd)) {
            $copy = fgets($fd, 32768);
            $lineno++;
            pg_put_line($conn, $copy);
            if ($copy == "\\.\n" || $copy == "\\.\r\n") {
                pg_end_copy($conn);
                break;
            }
        }
        return true;
    }

    /**
     * Executes an SQL script as a series of SQL statements.  Returns
     * the result of the final step.  This is a very complicated lexer
     * based on the REL7_4_STABLE src/bin/psql/mainloop.c lexer in
     * the PostgreSQL source code.
     * XXX: It does not handle multibyte languages properly.
     * @param string $name Entry in $_FILES to use
     * @param callable|null $callback (optional) Callback function to call with each query,
     * its result and line number.
     * @return bool True for general success, false on any failure.
     */
    function executeScript($name, $callback = null)
    {
        $pg = $this->postgres();

        // This whole function isn't very encapsulated, but hey...
        /**
         * @var \PgSql\Connection $conn
         */
        $conn = $pg->conn->_connectionID;
        if (!is_uploaded_file($_FILES[$name]['tmp_name']))
            return false;

        $fd = fopen($_FILES[$name]['tmp_name'], 'r');
        if (!$fd)
            return false;

        try {
            // Build up each SQL statement, they can be multiline
            $query_buf = null;
            $query_start = 0;
            $in_quote = 0;
            $in_xcomment = 0;
            $bslash_count = 0;
            $dol_quote = null;
            $paren_level = 0;
            $len = 0;
            $i = 0;
            $prevlen = 0;
            $thislen = 0;
            $lineno = 0;

            // Loop over each line in the file
            while (!feof($fd)) {
                $line = fgets($fd);
                $lineno++;

                // Nothing left on line? Then ignore...
                if (trim($line) == '')
                    continue;

                $len = strlen($line);
                $query_start = 0;

                /*
                 * Parse line, looking for command separators.
                 *
                 * The current character is at line[i], the prior character at line[i
                 * - prevlen], the next character at line[i + thislen].
                 */
                $prevlen = 0;
                $thislen = ($len > 0) ? 1 : 0;

                for ($i = 0; $i < $len; $this->advance_1($i, $prevlen, $thislen)) {

                    /* was the previous character a backslash? */
                    if ($i > 0 && substr($line, $i - $prevlen, 1) == '\\')
                        $bslash_count++;
                    else
                        $bslash_count = 0;

                    /*
                     * It is important to place the in_* test routines before the
                     * in_* detection routines. i.e. we have to test if we are in
                     * a quote before testing for comments.
                     */

                    /* in quote? */
                    if ($in_quote !== 0) {
                        /*
                         * end of quote if matching non-backslashed character.
                         * backslashes don't count for double quotes, though.
                         */
                        if (
                            substr($line, $i, 1) == $in_quote &&
                            ($bslash_count % 2 == 0 || $in_quote == '"')
                        )
                            $in_quote = 0;
                    } /* in or end of $foo$ type quote? */ else if ($dol_quote) {
                        if (strncmp(substr($line, $i), $dol_quote, strlen($dol_quote)) == 0) {
                            $this->advance_1($i, $prevlen, $thislen);
                            while (substr($line, $i, 1) != '$')
                                $this->advance_1($i, $prevlen, $thislen);
                            $dol_quote = null;
                        }
                    } /* start of extended comment? */ else if (substr($line, $i, 2) == '/*') {
                        $in_xcomment++;
                        if ($in_xcomment == 1)
                            $this->advance_1($i, $prevlen, $thislen);
                    } /* in or end of extended comment? */ else if ($in_xcomment) {
                        if (substr($line, $i, 2) == '*/' && !--$in_xcomment)
                            $this->advance_1($i, $prevlen, $thislen);
                    } /* start of quote? */ else if (substr($line, $i, 1) == '\'' || substr($line, $i, 1) == '"') {
                        $in_quote = substr($line, $i, 1);
                    } /*
                      * start of $foo$ type quote?
                      */ else if (!$dol_quote && $this->valid_dolquote(substr($line, $i))) {
                        $dol_end = strpos(substr($line, $i + 1), '$');
                        $dol_quote = substr($line, $i, $dol_end + 1);
                        $this->advance_1($i, $prevlen, $thislen);
                        while (substr($line, $i, 1) != '$') {
                            $this->advance_1($i, $prevlen, $thislen);
                        }

                    } /* single-line comment? truncate line */ else if (substr($line, $i, 2) == '--') {
                        $line = substr($line, 0, $i); /* remove comment */
                        break;
                    } /* count nested parentheses */ else if (substr($line, $i, 1) == '(') {
                        $paren_level++;
                    } else if (substr($line, $i, 1) == ')' && $paren_level > 0) {
                        $paren_level--;
                    } /* semicolon? then send query */ else if (substr($line, $i, 1) == ';' && !$bslash_count && !$paren_level) {
                        $subline = substr(substr($line, 0, $i), $query_start);
                        /* is there anything else on the line? */
                        if (strspn($subline, " \t\n\r") != strlen($subline)) {
                            /*
                             * insert a cosmetic newline, if this is not the first
                             * line in the buffer
                             */
                            if (strlen($query_buf) > 0)
                                $query_buf .= "\n";
                            $query_buf .= $subline;
                        }
                        $query_buf .= ';';

                        /* is there anything in the query_buf? */
                        if (trim($query_buf)) {
                            // Execute the query using raw pg_*
                            $rs = @pg_query($conn, $query_buf);
                            $errorMsg = '';

                            if ($rs === false) {
                                $errorMsg = pg_last_error($conn);
                            }

                            // Wrap result and call callback
                            $wrappedResult = QueryResult::fromPgResult($rs, $errorMsg);
                            if ($callback !== null)
                                $callback($query_buf, $wrappedResult, $lineno);

                            // Check for COPY request
                            if ($wrappedResult->isCopy) {
                                $this->handleCopyData($conn, $wrappedResult, $fd, $lineno);
                            }
                        }

                        $query_buf = null;
                        $query_start = $i + $thislen;
                    }

                    /*
                     * keyword or identifier?
                     * We grab the whole string so that we don't
                     * mistakenly see $foo$ inside an identifier as the start
                     * of a dollar quote.
                     */
                    // XXX: multibyte here
                    else if (preg_match('/^[_[:alpha:]]$/', substr($line, $i, 1))) {
                        $sub = substr($line, $i, $thislen);
                        while (preg_match('/^[\$_A-Za-z0-9]$/', $sub)) {
                            /* keep going while we still have identifier chars */
                            $this->advance_1($i, $prevlen, $thislen);
                            $sub = substr($line, $i, $thislen);
                        }
                        // Since we're now over the next character to be examined, it is necessary
                        // to move back one space.
                        $i -= $prevlen;
                    }
                } // end for

                /* Put the rest of the line in the query buffer. */
                $subline = substr($line, $query_start);
                if ($in_quote || $dol_quote || strspn($subline, " \t\n\r") != strlen($subline)) {
                    if (strlen($query_buf) > 0)
                        $query_buf .= "\n";
                    $query_buf .= $subline;
                }

                $line = null;

            } // end while

            /*
             * Process query at the end of file without a semicolon, so long as
             * it's non-empty.
             */
            if (strlen($query_buf) > 0 && strspn($query_buf, " \t\n\r") != strlen($query_buf)) {
                // Execute the query using raw pg_*
                $rs = @pg_query($conn, $query_buf);
                $errorMsg = '';

                if ($rs === false) {
                    $errorMsg = pg_last_error($conn);
                }

                // Wrap result and call callback
                $wrappedResult = QueryResult::fromPgResult($rs, $errorMsg);
                if ($callback !== null)
                    $callback($query_buf, $wrappedResult, $lineno);

                // Check for COPY request
                if ($wrappedResult->isCopy) {
                    $this->handleCopyData($conn, $wrappedResult, $fd, $lineno);
                }
            }

            return true;
        } finally {
            fclose($fd);
        }
    }

}