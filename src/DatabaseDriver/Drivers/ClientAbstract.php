<?php

namespace Vinelab\NeoEloquent\DatabaseDriver\Drivers;

use Illuminate\Support\Arr;

abstract class ClientAbstract
{
    protected $config;

    /**
     * @return string
     */
    public function buildUriFromConfig(array $config): string
    {
        $directUrl = Arr::get($config, 'url');
        if (!empty($directUrl)) {
            return $directUrl;
        }

        $scheme = Arr::get($config, 'scheme', 'bolt');
        $host = Arr::get($config, 'host', 'localhost');
        $port = Arr::get($config, 'port', 7687);
        $database = Arr::get($config, 'database');

        $uri = $scheme.'://'.$host;

        if ($port) {
            $uri .= ':'.$port;
        }

        if (!empty($database)) {
            $uri .= '?database='.$database;
        }

        return $uri;
    }

    /**
     * Get the connection host.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->getConfig('host');
    }

    public function getTimeout()
    {
        return $this->getConfig('default_timeout');
    }

    public function getFetchSize()
    {
        return $this->getConfig('fetch_size');
    }

    /**
     * Get the connection port.
     *
     * @return int|string
     */
    public function getPort()
    {
        return $this->getConfig('port');
    }

    /**
     * Get the connection username.
     *
     * @return int|string
     */
    public function getUsername()
    {
        return $this->getConfig('username');
    }

    /**
     * Get the connection password.
     *
     * @return int|string
     */
    public function getPassword()
    {
        return $this->getConfig('password');
    }

    /**
     * Get an option from the configuration options.
     *
     * @param string|null $option
     *
     * @return mixed
     */
    public function getConfig($option = null)
    {
        return Arr::get($this->config, $option);
    }

    public function getScheme()
    {
        return Arr::get($this->config, 'scheme');
    }
}
