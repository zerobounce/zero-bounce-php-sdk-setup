<?php

namespace ZeroBounce\Tests;

use ZeroBounce\SDK\ZeroBounce;
use ZeroBounce\SDK\ZeroBounceWarmup;

/*
 * The one way tests reach the private transport seams of the final clients:
 * a closure bound into the instance fills its private $seam array. The clients
 * are final and the property is private, so no caller or subclass can do this
 * without code like this file. PHP 7.0 syntax only: offline_smoke.php uses it.
 *
 * Hooks, by name (see the $seam property of ZeroBounce / ZeroBounceWarmup):
 * executeHttp, streamHttpToHandle, openDownloadFile, applyCurlOptions,
 * checkedUrl, json, formPost, request, curl, getBulkFileHttp (client);
 * executeHttp, applyCurlOptions, rewriteUrl (Warmup).
 * They replace the network call, not the https check, except checkedUrl.
 */

/**
 * @param ZeroBounce|ZeroBounceWarmup $client a real client
 * @param array<string, callable> $hooks
 * @return void
 */
function install_seams($client, array $hooks)
{
    $bound = \Closure::bind(function (array $hooks) {
        foreach ($hooks as $name => $hook) {
            $this->seam[$name] = $hook;
        }
    }, $client, get_class($client));
    $bound($hooks);
}

/**
 * @param object $client
 * @param string $property
 * @param mixed $value
 * @return void
 */
function set_private($client, $property, $value)
{
    $bound = \Closure::bind(function ($property, $value) {
        $this->$property = $value;
    }, $client, get_class($client));
    $bound($property, $value);
}

/**
 * @param object $client
 * @param string $method
 * @param array<int, mixed> $args
 * @return mixed
 */
function call_private($client, $method, array $args = array())
{
    $bound = \Closure::bind(function ($method, array $args) {
        return call_user_func_array(array($this, $method), $args);
    }, $client, get_class($client));
    return $bound($method, $args);
}

/**
 * The real client behind a test double (or the client itself).
 *
 * @param object $client
 * @return object
 */
function real_client($client)
{
    return $client instanceof ClientDouble ? $client->client() : $client;
}

/**
 * A test double by composition: holds a real client, forwards every call to
 * it, and keeps what its hooks record. Not a ZeroBounce: the client is final.
 */
abstract class ClientDouble
{
    /** @var ZeroBounce|ZeroBounceWarmup */
    protected $client;

    /**
     * Without what the double records or answers with (request URLs, an
     * answer quoting the key): print_r() of an error shows the closures in
     * its trace, and their bound doubles, which are not the SDK's to mask.
     */
    public function __debugInfo()
    {
        return array();
    }

    public function __call($method, array $args)
    {
        return call_user_func_array(array($this->client, $method), $args);
    }

    /** @return ZeroBounce|ZeroBounceWarmup the real client */
    public function client()
    {
        return $this->client;
    }

    /**
     * @param array<string, callable> $hooks
     * @return void
     */
    protected function hook(array $hooks)
    {
        install_seams($this->client, $hooks);
    }

    /**
     * Every base URL of the client at one root (plain http, for a loopback server).
     *
     * @param string $root
     * @return void
     */
    public function pointAt($root)
    {
        foreach (array(
            'apiBaseUrl' => '/v2', 'bulkApiBaseUrl' => '/v2', 'bulkApiRootUrl' => '',
            'emailFinderBulkBaseUrl' => '/email-finder', 'domainSearchBulkBaseUrl' => '/domain-search',
            'listEvaluatorBaseUrl' => '/v2/listevaluator', 'inboxPlacementBaseUrl' => '/api/ipt',
        ) as $name => $path) {
            set_private($this->client, $name, $root . $path);
        }
    }
}

/** A real ZeroBounce client with no hooks: everything goes through the real transport code. */
class PlainClient extends ClientDouble
{
    public function __construct()
    {
        $this->client = ZeroBounce::create();
    }
}
