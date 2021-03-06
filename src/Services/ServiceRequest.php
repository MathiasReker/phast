<?php
namespace Kibo\Phast\Services;

use Kibo\Phast\Common\Base64url;
use Kibo\Phast\Environment\Switches;
use Kibo\Phast\HTTP\Request;
use Kibo\Phast\Security\ServiceSignature;
use Kibo\Phast\ValueObjects\Query;
use Kibo\Phast\ValueObjects\URL;

class ServiceRequest {
    const FORMAT_QUERY = 1;

    const FORMAT_PATH  = 2;

    private static $defaultSerializationMode = self::FORMAT_PATH;

    /**
     * @var string
     */
    private static $propagatedSwitches = '';

    /**
     * @var Switches
     */
    private static $switches;

    /**
     * @var string
     */
    private static $documentRequestId;

    /**
     * @var Request
     */
    private $httpRequest;

    /**
     * @var URL
     */
    private $url;

    /**
     * @var Query
     */
    private $query;

    /**
     * @var string
     */
    private $token;

    public function __construct() {
        if (!isset(self::$switches)) {
            self::$switches = new Switches();
        }
        $this->query = new Query();
    }

    public static function resetRequestState() {
        self::$defaultSerializationMode = self::FORMAT_PATH;
        self::$propagatedSwitches = '';
        self::$switches = null;
        self::$documentRequestId = null;
    }

    public static function setDefaultSerializationMode($mode) {
        self::$defaultSerializationMode = $mode;
    }

    public static function getDefaultSerializationMode() {
        return self::$defaultSerializationMode;
    }

    public static function fromHTTPRequest(Request $request) {
        $query = $request->getQuery();
        if ($query->get('src')) {
            $query->set('src', preg_replace('~^hxxp(?=s?://)~', 'http', $query->get('src')));
        }
        $pathInfo = $request->getPathInfo();
        if ($pathParams = self::parseBase64PathInfo($pathInfo)) {
            $query->update($pathParams);
        } elseif ($pathInfo) {
            $query->update(self::parsePathInfo($pathInfo));
        }
        $instance = new self();
        self::$switches = new Switches();
        $instance->httpRequest = $request;
        if ($request->getCookie('phast')) {
            self::$switches = Switches::fromString($request->getCookie('phast'));
        }
        if ($token = $query->pop('token')) {
            $instance->token = $token;
        }
        $instance->query = $query;
        if ($query->get('phast')) {
            self::$propagatedSwitches = $query->get('phast');
            $paramsSwitches = Switches::fromString($query->get('phast'));
            self::$switches = self::$switches->merge($paramsSwitches);
        }
        if ($query->get('documentRequestId')) {
            self::$documentRequestId = $query->get('documentRequestId');
        } else {
            self::$documentRequestId = (string) mt_rand(0, 999999999);
        }
        return $instance;
    }

    public function hasRequestSwitchesSet() {
        return !empty(self::$propagatedSwitches);
    }

    /**
     * @return Switches
     */
    public function getSwitches() {
        return self::$switches;
    }

    /**
     * @return array
     */
    public function getParams() {
        return $this->query->toAssoc();
    }

    /**
     * @return Query
     */
    public function getQuery() {
        return $this->query;
    }

    /**
     * @return Request
     */
    public function getHTTPRequest() {
        return $this->httpRequest;
    }

    /**
     * @return string
     */
    public function getDocumentRequestId() {
        return self::$documentRequestId;
    }

    /**
     * @param array $params
     * @return ServiceRequest
     */
    public function withParams(array $params) {
        $result = clone $this;
        $result->query = Query::fromAssoc($params);
        return $result;
    }

    /**
     * @param URL $url
     * @return ServiceRequest
     */
    public function withUrl(URL $url) {
        $result = clone $this;
        $result->url = $url;
        return $result;
    }

    /**
     * @param ServiceSignature $signature
     * @return ServiceRequest
     */
    public function sign(ServiceSignature $signature) {
        $token = $signature->sign($this->getVerificationString());
        $result = clone $this;
        $result->token = $token;
        return $result;
    }

    /**
     * @param ServiceSignature $signature
     * @return bool
     */
    public function verify(ServiceSignature $signature) {
        return
            $signature->verify($this->token, $this->getVerificationString())
            || $signature->verify($this->token, $this->getVerificationStringWithoutStemSuffix());
    }

    private static function parsePathInfo($string) {
        $values = new Query();
        $parts = explode('/', $string);
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $pair = explode('=', $part);
            if (isset($pair[1])) {
                $values->set($pair[0], self::decodeSingleValue($pair[1]));
            } elseif (preg_match('/^__p__(@[1-9][0-9]*x)?\./', $pair[0], $match)) {
                if (!empty($match[1]) && $values->has('src')) {
                    $values->set('src', self::appendStemSuffix($values->get('src'), $match[1]));
                }
                break;
            } else {
                $values->set('src', self::decodeSingleValue($pair[0]));
            }
        }
        return $values;
    }

    private static function decodeSingleValue($value) {
        return urldecode(str_replace('-', '%', $value));
    }

    private static function appendStemSuffix($src, $suffix) {
        $url = URL::fromString($src);
        $path = preg_replace_callback(
            '/\.\w+$/',
            function ($match) use ($suffix) {
                return $suffix . $match[0];
            },
            $url->getPath()
        );
        return $url->withPath($path)->toString();
    }

    private static function parseBase64PathInfo($string) {
        if (!preg_match('~^/([a-z0-9_-]+)\.q\.js$~i', $string, $match)) {
            return null;
        }
        return Query::fromString(Base64url::decode($match[1]));
    }

    /**
     * @param callable $paramsFilter
     * @return string
     */
    private function getVerificationString($paramsFilter = null) {
        $params = $this->getAllParams();
        if ($paramsFilter) {
            $params = $paramsFilter($params);
        }
        ksort($params);
        return http_build_query($params);
    }

    private function getVerificationStringWithoutStemSuffix() {
        return $this->getVerificationString(function ($params) {
            if (isset($params['src'])) {
                $params['src'] = $this->stripStemSuffix($params['src']);
            }
            return $params;
        });
    }

    private function stripStemSuffix($src) {
        $url = URL::fromString($src);
        $path = preg_replace('/@[1-9][0-9]*x(?=\.\w+$)/', '', $url->getPath());
        return $url->withPath($path)->toString();
    }

    public function serialize($format = null) {
        $params = $this->getAllParams();
        if ($this->token) {
            $params['token'] = $this->token;
        }
        if (is_null($format)) {
            $format = self::$defaultSerializationMode;
        }
        if ($format == self::FORMAT_PATH) {
            return $this->serializeToPathFormat($params);
        }
        return $this->serializeToQueryFormat($params);
    }

    private function getAllParams() {
        $urlParams = [];
        if ($this->url) {
            parse_str($this->url->getQuery(), $urlParams);
        }
        $params = array_merge($urlParams, $this->query->toAssoc());
        if (!empty(self::$propagatedSwitches)) {
            $params['phast'] = self::$propagatedSwitches;
        }
        if (self::$switches->isOn(Switches::SWITCH_DIAGNOSTICS)) {
            $params['documentRequestId'] = self::$documentRequestId;
        }
        return $params;
    }

    private function serializeToQueryFormat(array $params) {
        $encoded = http_build_query($params);
        if (!isset($this->url)) {
            return $encoded;
        }
        $serialized = preg_replace('~\?.*~', '', (string) $this->url);
        if (self::$defaultSerializationMode === self::FORMAT_PATH
            && !preg_match('~/$~', $serialized)
        ) {
            $serialized .= '/' . $this->getDummyFilename($params);
        }
        return $serialized . '?' . $encoded;
    }

    /** @return string */
    private function serializeToPathFormat(array $params) {
        $encodedSrc = null;
        $values = [];
        foreach (explode('&', http_build_query($params)) as $element) {
            list($key, $value) = explode('=', $element, 2);
            $encodedValue = str_replace(['-', '%'], ['%2D', '-'], $value);
            if ($key == 'src') {
                $encodedSrc = $encodedValue;
            } else {
                $values[] = $key . '=' . $encodedValue;
            }
        }
        if ($encodedSrc) {
            array_unshift($values, $encodedSrc);
        }
        $params = '/' . join('/', $values) . '/' . $this->getDummyFilename($params);
        if (isset($this->url)) {
            return preg_replace(['~\?.*~', '~/$~'], '', $this->url) . $params;
        }
        return $params;
    }

    private function getDummyFilename(array $params) {
        return '__p__.' . $this->getDummyExtension($params);
    }

    private function getDummyExtension(array $params) {
        $default = 'js';
        if (empty($params['src'])) {
            return $default;
        }
        $url = URL::fromString($params['src']);
        $ext = strtolower($url->getExtension());
        if (preg_match('/^(jpe?g|gif|png|js|css)$/', $ext)) {
            return $ext;
        }
        return $default;
    }
}
