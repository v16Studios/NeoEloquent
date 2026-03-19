<?php

namespace Vinelab\NeoEloquent\DatabaseDriver;

use Vinelab\NeoEloquent\DatabaseDriver\Interfaces\QueryExecutorInterface;

/**
 * Represents a Cypher query string and variables
 * Query the database using Cypher. For query syntax, please refer
 * to the Cypher documentation for your server version.
 *
 * Latest documentation:
 * http://docs.neo4j.org/chunked/snapshot/cypher-query-lang.html
 */
class CypherQuery
{
    protected $executor = null;
    protected $template = null;
    protected $vars = [];

    protected $result = null;

    public function __construct(QueryExecutorInterface $executor, $template, $vars = [])
    {
        $this->executor = $executor;
        $this->template = $this->normalizeLegacyParameters($template);
        $this->vars = $vars;
    }

    /**
     * Get the query script.
     *
     * @return string
     */
    public function getQuery()
    {
        return $this->template;
    }

    /**
     * Get the template parameters.
     *
     * @return array
     */
    public function getParameters()
    {
        return $this->vars;
    }

    /**
     * Retrieve the query results.
     */
    public function getResultSet()
    {
        if ($this->result === null) {
            $this->result = $this->executor->executeCypherQuery($this);
        }

        return $this->result;
    }

    /**
     * Translate the legacy `{parameter}` syntax to the `$parameter` form expected by modern Neo4j servers.
     */
    protected function normalizeLegacyParameters($template)
    {
        return preg_replace('/\{([A-Za-z_][A-Za-z0-9_]*)\}/', '\$$1', $template);
    }
}
