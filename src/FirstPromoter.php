<?php

namespace Messerli90\FirstPromoter;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class FirstPromoter
{
    const COMPANY_URI = 'https://api.firstpromoter.com/api/v2/company/';

    const TRACK_URI = 'https://api.firstpromoter.com/api/v2/track/';

    /**
     * @var string $key
     */
    protected static $key;

    /**
     * @var string $accountId
     */
    protected static $accountId;

    /**
     * Callers pass the API key to the constructor. The request methods are static.
     *
     * @param string|null $key
     */
    public function __construct($key = null)
    {
        if (is_string($key) && $key !== '') {
            static::setApiKey($key);
        }
    }

    /**
     * Makes a request to the FirstPromoter v2 API.
     *
     * A uri that starts with track/ uses the tracking host. An absolute uri is
     * used as given. Every other uri is a company admin call.
     *
     * @param string $method
     * @param string $uri
     * @param array $data
     * @param bool $asQuery Send $data as the query string on a POST.
     *
     * @return object|array
     */
    protected static function request($method, $uri, $data = [], $asQuery = false)
    {
        $base = self::COMPANY_URI;
        if (strpos($uri, 'https://') === 0) {
            $base = null;
        } elseif (strpos($uri, 'track/') === 0) {
            $base = self::TRACK_URI;
            $uri = substr($uri, 6);
            unset($data['wid']);
        }

        $clientOptions = [
            'timeout' => 20,
            'connect_timeout' => 5,
            'http_errors' => true,
        ];
        if ($base) {
            $clientOptions['base_uri'] = $base;
        }

        $client = new Client($clientOptions);
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . self::getApiKey(),
                'Account-ID' => self::getAccountId(),
                'Accept' => 'application/json',
            ],
        ];
        if ($data) {
            if ($method === 'GET' || $asQuery) {
                $options['query'] = $data;
            } else {
                $options['json'] = $data;
            }
        }

        try {
            $res = $client->request($method, $uri, $options);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $status = $response ? $response->getStatusCode() : 0;
            $body = $response ? (string) $response->getBody() : '';
            throw new Exception($body !== '' ? $body : $e->getMessage(), $status, $e);
        }

        $raw = (string) $res->getBody();
        if ($raw === '') {
            return new \stdClass();
        }

        $decoded = json_decode($raw);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception($raw, $res->getStatusCode());
        }

        return $decoded;
    }

    /**
     * Set the First Promoter API Key
     *
     * @param $key
     * @return void
     */
    public static function setApiKey($key)
    {
        static::$key = $key;
    }

    /**
     * Get the First Promoter API Key
     *
     * @return string
     */
    public static function getApiKey()
    {
        if (static::$key) {
            return static::$key;
        }

        if ($key = getenv('FP_API_KEY')) {
            return $key;
        }

        if ($key = getenv('FIRST_PROMOTER_KEY')) {
            return $key;
        }

        if (function_exists('config')) {
            foreach ([
                'services.first_promoter.key',
                'services.first_promoter.api_key',
                'settings.first_promoter.key',
            ] as $name) {
                $value = config($name);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * Account id sent as the Account-ID header. This is the public tracking id.
     *
     * @return string
     */
    public static function getAccountId()
    {
        if (static::$accountId) {
            return static::$accountId;
        }

        foreach (['FP_A', '1P_A'] as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        if (function_exists('config')) {
            $fromSettings = config('settings.first_promoter.a');
            if (is_string($fromSettings) && $fromSettings !== '') {
                return $fromSettings;
            }

            $fromServices = config('services.first_promoter.account_id');
            if (is_string($fromServices) && $fromServices !== '') {
                return $fromServices;
            }
        }

        return 'jpdw6wfa';
    }

    public static function setAccountId($accountId)
    {
        static::$accountId = $accountId;
    }
}
