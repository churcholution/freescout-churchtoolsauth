<?php

namespace Modules\ChurchToolsAuth\Libraries\ChurchTools;

use \GuzzleHttp\Client;
use \GuzzleHttp\HandlerStack;
use \GuzzleHttp\TransferStats;
use \GuzzleHttp\RequestOptions;
use \GuzzleHttp\Cookie\CookieJar;
use \GuzzleHttp\Exception\BadResponseException;

use \Psr\Http\Message\ResponseInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class ChurchToolsClient
{
 
    protected   Client      $client;
    protected   CookieJar   $cookieJar;

    protected   string      $url;
    protected   string      $apiUrl;
    protected   int         $paginationPageSize = 200;

    protected   int         $userId;

    protected   int         $cacheTtl = 600; //10 minutes
    protected   bool        $useCache = true;

    protected   array       $lastErrorResponse;
    protected   int         $lastStatusCode;

    public function __construct() {

        $this->clearCookies();
        $this->client = new Client();

    }

    protected function getRequestOptions() : array {

        //Set default value for guzzle request
        return  [
                    RequestOptions::HTTP_ERRORS     =>  false,          //disable Exceptions on 4xx & 5xx http-response
                    RequestOptions::COOKIES         =>  $this->cookieJar, //enable cookie storage
                    RequestOptions::HEADERS         =>  [
                                                            'Content-Type' => 'application/json',
                                                            'User-Agent' => Config::get('app.name'),
                                                        ]
                ];

    }

    public function clearCookies() : void {
        $this->cookieJar = new CookieJar();
    }

    public function setUrl(string $url) : void {
        $url = rtrim($url, '/');
        $this->url = $url;
        $this->setApiUrl($url);
    }

    public function getUrl() : string {
        return $this->url;
    }

    public function setApiUrl(string $basUrl) : void {
        $url = rtrim($basUrl, '/');
        $this->apiUrl = $url . '/api';
    }

    public function getApiUrl() : string {
        return $this->apiUrl;
    }

    public function setPaginationPageSize(int $pageSize) : void {
        $this->paginationPageSize = $pageSize;
    }

    public function getPaginationPageSize() : int {
        return $this->paginationPageSize;
    }

    protected function setUserId(int $userId) : void {
        $this->userId = $userId;
    }

    public function getUserId() : int {
        return $this->userId;
    }

    /* ******** */
    /* REQUESTS */
    /* ******** */

    protected function request(string $method, string $endpoint, ?array $queryParams = [], ?array $requestData = [], ?array $options = []) : ResponseInterface {
        
        if ( strpos($endpoint, "https://") !== 0 ) {
            $url = rtrim($this->getApiUrl(), '/') . '/' . ltrim($endpoint, '/') ;
        } else {
            $url = $endpoint;
        }

        $opts = [];
        if ( ! empty($queryParams) ) {
            $opts[RequestOptions::QUERY] = $queryParams;
        }
        if ( ! empty($requestData) ) {
            $opts[RequestOptions::JSON] = $requestData;
        }

        $options = array_merge_recursive($options, $opts, $this->getRequestOptions());

        $maxAttempts = 3;
        $attempt = 0;
        $success = false;

        while ( $attempt < $maxAttempts and ! $success ) {
            $response = $this->client->request($method, $url, $options);
            if ($response->getStatusCode() == 429) { //To many requests; we have to wait 1 minute
                $attempt++;
                sleep(62); //Wait 62 secs
            } else {
                $success = true;
            }
        }

        return $response;

    }

    protected function get(string $endpoint, ?array $queryParams = [], ?array $requestData = [], ?array $options = []) : ResponseInterface {
        return $this->request('GET', $endpoint, $queryParams, null, $options);
    }

    protected function post(string $endpoint, ?array $queryParams = [], ?array $requestData = [], ?array $options = []) : ResponseInterface {
        return $this->request('POST', $endpoint, $queryParams, $requestData, $options);
    }

    protected function patch(string $endpoint, ?array $queryParams = [], ?array $requestData = [], ?array $options = []) : ResponseInterface {
        return $this->request('PATCH', $endpoint, $queryParams, $requestData, $options);
    }

    protected function put(string $endpoint, ?array $queryParams = [], ?array $requestData = [], ?array $options = []) : ResponseInterface {
        return $this->request('PUT', $endpoint, $queryParams, $requestData, $options);
    }

    protected function delete(string $endpoint, ?array $queryParams = [], ?array $requestData = [], ?array $options = []) : ResponseInterface {
        return $this->request('DELETE', $endpoint, $queryParams, $requestData, $options);
    }

    /* ******************* */
    /* GENERAL API METHODS */
    /* ******************* */

    public function getData(string $endpoint, ?array $queryParams = [], ?array $requestData = []) : array {

        $this->clearLastErrorResponse();

        $data = [];
        $cacheKey = '';

        if ( empty($queryParams) ) {
            $queryParams = [];
        }
        if ( ! isset($queryParams['limit']) ) {
            $queryParams['limit'] = $this->getPaginationPageSize();
        }

        if ( $this->isCacheEnabled() ) {

            $cacheKey = $this->buildCacheKey($endpoint, $queryParams, $requestData);
            $data = $this->getCache($cacheKey);

            if ( ! empty($data) and is_array($data) ) {
                return $data;
            }

        }

        $manualPagination = ( isset($queryParams['page']) ? true : false );

        $response = $this->get($endpoint, $queryParams, $requestData);
        $this->setLastStatusCode($response->getStatusCode());

        if ( $response->getStatusCode() != 200 ) {
            $this->setLastErrorResponse($this->responseAsArray($response));
            return [];
        }

        $meta = self::metaAsArray($response);
        $data = array_merge($data, self::dataAsArray($response));

        if ( ! $manualPagination and array_key_exists("pagination", $meta) ) {

            $lastPage = $meta["pagination"]["lastPage"];

            // Collect Date from Second till Last page
            for ($i = 2; $i <= $lastPage; $i++) {

                $queryParams['page'] = $i;

                $response = $this->get($endpoint, $queryParams, $requestData);

                if ( $response->getStatusCode() == 200 ) {
                    $data = array_merge($data, self::dataAsArray($response));
                }

            }

        }

        if ( $this->isCacheEnabled() ) {

            if ( ! $manualPagination ) {
                unset($queryParams['page']);
            }

            $cacheKey = $this->buildCacheKey($endpoint, $queryParams, $requestData);
            $this->setCache($cacheKey, $data);

        }

        return $data;

    }

    public function postData(string $endpoint, ?array $queryParams = [], ?array $requestData = []) : array {

        $this->clearLastErrorResponse();

        $data = [];

        if ( empty($queryParams) ) {
            $queryParams = [];
        }

        if ( empty($requestData) ) {
            $requestData = [];
        }
       
        $response = $this->post($endpoint, $queryParams, $requestData);
        $this->setLastStatusCode($response->getStatusCode());

        if ( $response->getStatusCode() != 200 ) {
            $this->setLastErrorResponse($this->responseAsArray($response));
            return [];
        }

        $data = self::dataAsArray($response);

        return $data;

    }

    public function patchData(string $endpoint, ?array $queryParams = [], ?array $requestData = []) : array {

        $this->clearLastErrorResponse();

        $data = [];

        if ( empty($queryParams) ) {
            $queryParams = [];
        }

        if ( empty($requestData) ) {
            $requestData = [];
        }
       
        $response = $this->patch($endpoint, $queryParams, $requestData);
        $this->setLastStatusCode($response->getStatusCode());

        if ( $response->getStatusCode() != 200 ) {
            $this->setLastErrorResponse($this->responseAsArray($response));
            return [];
        }

        $data = self::dataAsArray($response);

        return $data;

    }

    public function putData(string $endpoint, ?array $queryParams = [], ?array $requestData = []) : array {

        $this->clearLastErrorResponse();

        $data = [];

        if ( empty($queryParams) ) {
            $queryParams = [];
        }

        if ( empty($requestData) ) {
            $requestData = [];
        }
       
        $response = $this->put($endpoint, $queryParams, $requestData);
        $this->setLastStatusCode($response->getStatusCode());
        
        if ( $response->getStatusCode() != 200 ) {
            $this->setLastErrorResponse($this->responseAsArray($response));
            return [];
        }

        $data = self::dataAsArray($response);

        return $data;

    }

    public function deleteData(string $endpoint, ?array $queryParams = [], ?array $requestData = []) : bool {

        $this->clearLastErrorResponse();

        $data = [];

        if ( empty($queryParams) ) {
            $queryParams = [];
        }

        if ( empty($requestData) ) {
            $requestData = [];
        }
       
        $response = $this->delete($endpoint, $queryParams, $requestData);
        $this->setLastStatusCode($response->getStatusCode());
        
        if ( $response->getStatusCode() != 204 ) {
            $this->setLastErrorResponse($this->responseAsArray($response));
            return false;
        }

        $data = self::dataAsArray($response);

        return true;

    }

    /* **************** */
    /* AJAX API METHODS */
    /* **************** */
    public function ajaxApiRequest(string $method = 'GET', string $path = '', ?array $requestData = []) : array {

        $this->clearLastErrorResponse();

        $data = [];

        $queryParams = [];
        $queryParams['q'] = $path;

        if ( empty($requestData) ) {
            $requestData = [];
        }

        $url = rtrim($this->getUrl(), '/') . '/index.php';
        $response = $this->request($method, $url, $queryParams, $requestData);

        $this->setLastStatusCode($response->getStatusCode());

        $data = self::responseAsArray($response);

        return $data;

    }

    /* ******* */
    /* Caching */
    /* ******* */

    protected function getCacheSelector() : string {
        return 'ct_' . parse_url($this->apiUrl)['host'] . '_' . $this->userId;
    }

    protected function buildCacheKey(string $endpoint, ?array $queryParams, ?array $requestData) {

        $chain = [];

        if ( empty($queryParams) ) {
            $queryParams = [];
        }
        ksort($queryParams);

        if ( empty($requestData) ) {
            $requestData = [];
        }
        ksort($requestData);

        $chain =    [
                        'endpoint'      => $endpoint,
                        'queryParams'   => $queryParams,
                        'requestData'   => $requestData,
                    ];

        $hash = md5(json_encode($chain));

        return $this->getCacheSelector() . '_' . $hash;

    }

    protected function getCache(string $key) : array {

        if ( ! Cache::has($key) ) {
            return [];
        }

        $result = Cache::get($key);
        if ( ! is_array($result) ) {
            return [];
        }

        return $result;

    }

    protected function setCache(string $key, array $value) : bool {
        Cache::put($key, $value, $this->getCacheTtl()/60);
        return true;
    }

    public function clearCache() : void {
        //Cache::forget($key);
        Cache::flush();
    }

    public function enableCache() : void {
        $this->useCache = true;
    }

    public function disableCache() : void {
        $this->useCache = false;
    }

    public function isCacheEnabled() : bool {
        return $this->useCache;
    }

    public function setCacheTtl(int $seconds) : void {
        $this->cacheTtl = $seconds;
    }

    public function getCacheTtl() : int {
        return $this->cacheTtl;
    }

    /* ****** */
    /* Errors */
    /* ****** */

    public function clearLastErrorResponse() : void {
        $this->lastErrorResponse = [];
    }

    public function setLastErrorResponse(array $response) : void {
        $this->lastErrorResponse = $response;
    }

    public function getLastErrorResponse() : array {
        return $this->lastErrorResponse;
    }

    /* ************ */
    /* Status Codes */
    /* ************ */

    public function clearLastStatusCode() : void {
        $this->lastStatusCode = null;
    }

    public function setLastStatusCode(int $statusCode) : void {
        $this->lastStatusCode = $statusCode;
    }

    public function getLastStatusCode() : int {
        return $this->lastStatusCode;
    }

    /* ***** */
    /* UTILS */
    /* ***** */

    public static function jsonToObject(ResponseInterface $response)
    {
        return json_decode($response->getBody()->__toString(), true);
    }

    public static function jsonToArray(ResponseInterface $response): array
    {
        $object = self::jsonToObject($response);

        if ($object == null) {
            return [];
        } else {
            $data = (array)$object;
            return $data;
        }
    }

    public static function responseAsArray(ResponseInterface $response): array
    {
        $responseArray = self::jsonToArray($response);

        if ( ! empty($responseArray) ) {
            return $responseArray;
        } else {
            return [];
        }
    }

    public static function dataAsArray(ResponseInterface $response): array
    {
        $responseArray = self::jsonToArray($response);

        if (array_key_exists('data', $responseArray)) {
            return $responseArray['data'];
        } else {
            return [];
        }
    }

    public static function metaAsArray(ResponseInterface $response): array
    {
        $responseArray = self::jsonToArray($response);

        if (array_key_exists('meta', $responseArray)) {
            return $responseArray['meta'];
        } else {
            return [];
        }
    }

    /* ************** */
    /* Authentication */
    /* ************** */
    
    public function authWithCredentials(string $email, string $password, ?string $totp = null) : bool {

        $this->clearCookies();

        $options =  [
                        RequestOptions::HEADERS =>  [
                                                        'Cache-Control' => 'no-cache',
                                                    ]
                    ];

        $endpoint = '/login';

        $requestData =  [   
                            'username' => $email,
                            'password' => $password,
                        ];

        $response = $this->post($endpoint, null, $requestData, $options);

        if ( $response->getStatusCode() != 200 ) {
            return false;
        }

        $data = self::dataAsArray($response);

        $userId = ( isset($data['personId']) ? $data['personId'] : null );
        if ( empty($userId) ) {
            return false;
        }

        $status = ( isset($data['status']) ? $data['status'] : null );
        if ( $status != 'totp' and $status != 'success' ) {
            return false;
        }

        if ( $status == 'totp' ) {

            $endpoint = '/login/totp';
            $requestData =  [
                                'personId' => $userId,
                                'code' => $totp,
                            ];

            $response = $this->post($endpoint, null, $requestData, $options);

            if ( $response->getStatusCode() != 200 ) {
                return false;
            }
    
            $data = self::dataAsArray($response);

            $status = ( isset($data['status']) ? $data['status'] : null );

            if ( $status != 'success' ) {
                return false;
            }

        }

        $this->setUserId($userId);

        return true;

    }

    public function authWithLoginToken(string $loginToken) : bool {

        $this->clearCookies();

        $options =  [
            RequestOptions::HEADERS =>  [
                                            'authorization' => 'Login ' . $loginToken,
                                        ]
        ];

        $endpoint = '/whoami';

        $response = $this->get($endpoint, null, null, $options);

        if ( $response->getStatusCode() != 200 ) {
            return false;
        }

        $data = self::dataAsArray($response);

        if ( ! isset($data['id']) ) {
            return false;
        }

        if ( empty($data['id']) or intval($data['id']) <= 0 ) {
            return false;
        }

        $this->setUserId($data['id']);

        return true;

    }

}