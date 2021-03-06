<?php

namespace Icinga\Module\Vspheredb;

use DateTime;
use Exception;
use Icinga\Application\Logger;
use Icinga\Exception\AuthenticationException;
use Icinga\Module\Vspheredb\DbObject\VCenterServer;
use Icinga\Module\Vspheredb\MappedClass\ApiClassMap;
use Icinga\Module\Vspheredb\PropertySet\PropertySet;
use Icinga\Module\Vspheredb\SelectSet\SelectSet;
use SoapVar;

/**
 * Class Api
 *
 * This is your main entry point when working with this library
 */
class Api
{
    use LazyApiHelpers;

    /** @var CurlLoader */
    private $curl;

    /** @var string */
    private $host;

    /** @var int */
    private $port;

    /** @var string */
    private $user;

    /** @var string */
    private $pass;

    /** @var string */
    private $wsdlDir;

    /** @var string */
    private $cacheDir;

    /** @var SoapClient */
    private $soapClient;

    /** @var VCenterServer */
    private $vCenterServer;

    /**
     * Involved WSDL files
     *
     * We'll always fetch and store them in case they are not available. Pay
     * attention when modifying this list, we'll use the first one to start
     * with when connecting to the SOAP API
     *
     * @var array
     */
    private $wsdlFiles = [
        'vimService.wsdl',
        'vim.wsdl',
        'core-types.xsd',
        'query-types.xsd',
        'query-messagetypes.xsd',
        'reflect-types.xsd',
        'reflect-messagetypes.xsd',
        'vim-types.xsd',
        'vim-messagetypes.xsd',
    ];

    /**
     * Api constructor.
     *
     * @param string $host
     * @param string $user
     * @param string $pass
     * @param int|null $port
     */
    public function __construct($host, $user, $pass, $port = null)
    {
        $this->host = $host;
        $this->user = $user;
        $this->pass = $pass;
        $this->port = $port;
    }

    /**
     * @param VCenterServer $server
     * @return static
     */
    public static function forServer(VCenterServer $server)
    {
        $host = $server->get('host');
        if (preg_match('/^(.+?):(\d{1,5})$/', $host, $match)) {
            $host = $match[1];
            $port = (int) $match[2];
        } else {
            $port = null;
        }

        $api = new static(
            $host,
            $server->get('username'),
            $server->get('password'),
            $port
        );

        $api->vCenterServer = $server;

        $curl = $api->curl();

        if ($port !== null) {
            $curl->setPort($port);
        }

        if ($type = $server->get('proxy_type')) {
            $curl->setProxy($server->get('proxy_address'), $type);

            if ($user = $server->get('proxy_user')) {
                $curl->setProxyAuth($user, $server->get('proxy_pass'));
            }
        }

        if ($server->get('scheme') === 'https') {
            if ($server->get('ssl_verify_peer') === 'n') {
                $curl->disableSslPeerVerification();
            }
            if ($server->get('ssl_verify_host') === 'n') {
                $curl->disableSslHostVerification();
            }
        }

        return $api;
    }


    /**
     * @return DateTime
     * @throws AuthenticationException
     */
    public function getCurrentTime()
    {
        $result = $this->soapCall(
            'CurrentTime',
            $this->makeBaseServiceInstanceParam()
        );

        return new DateTime($result->returnval);
    }

    /**
     * @return CurlLoader
     */
    public function curl()
    {
        if ($this->curl === null) {
            $this->curl = new CurlLoader($this->host, null, null, $this->cacheDir());
            $this->curl->on('cookie', [$this, 'gotCookie']);
        }

        return $this->curl;
    }

    public function gotCookie($cookie)
    {
        // vmware_soap_session="d4a9b281fb7e587ef73a5097fe591bfbaa420ccd"; Path=/; HttpOnly; Secure;
        Logger::info('Got new session cookie from VCenter');
        return;
        $parts = explode(';', $cookie);
        list($name, $sid) = preg_split('/=/', $parts[0], 2);
        $sid = trim($sid, '"');

        $db->insert('vcenter_session', [
            'vcenter_uuid' => $this->getBinaryUuid(),
            'session_id' => hex2bin(sha1($cookie)),
            'session_cookie_string' => $cookie,
            'session_cookie_name' => $name,
            'scope' => 'Main Sync',
            'ts_created' => Util::currentTimestamp(),
            'ts_last_check' => Util::currentTimestamp(),
        ]);
    }

    /**
     * SOAP call wrapper
     *
     * Accepts the desired method name and optional arguments
     *
     * @param $method
     * @return mixed
     * @throws AuthenticationException
     */
    public function soapCall($method)
    {
        $arguments = func_get_args();
        array_shift($arguments);

        try {
            return $this->soapClient()->__soapCall($method, $arguments);
        } catch (AuthenticationException $e) {
            if ($method === 'Login' || $method === 'Logout') {
                throw $e;
            } else {
                try {
                    $this->logout();
                } catch (AuthenticationException $ae) {
                    // That's fine.
                }
                $this->login();

                return $this->soapClient()->__soapCall($method, $arguments);
            }
        }
    }

    /**
     * Builds our base url
     *
     * @return string
     */
    protected function makeLocation()
    {
        return 'https://' . $this->host . '/sdk';
    }

    /**
     * Lazy-instantiation of our SoapClient
     *
     * @return SoapClient
     */
    protected function soapClient()
    {
        if ($this->soapClient === null) {
            $this->prepareWsdl();
            $wsdlFile = $this->cacheDir() . '/' . $this->wsdlFiles[0];
            $features = SOAP_SINGLE_ELEMENT_ARRAYS + SOAP_USE_XSI_ARRAY_TYPE;
            $options = [
                'trace'              => true,
                'location'           => $this->makeLocation(),
                'exceptions'         => true,
                'connection_timeout' => 10,
                'classmap'           => ApiClassMap::getMap(),
                'features'           => $features,
                'cache_wsdl'         => WSDL_CACHE_NONE
            ];

            try {
                $soap = new SoapClient($wsdlFile, $options);
            } catch (Exception $e) {
                // e.g.: SOAP-ERROR: Parsing Schema: can't import schema from '/tmp/[..]/vim-types.xsd
                $this->flushWsdlCache();

                throw $e;
            }
            $soap->setCurl($this->curl());
            $this->soapClient = $soap;
        }

        return $this->soapClient;
    }

    /**
     * Make sure all our WSDL files are in place, fetch missing ones
     */
    protected function prepareWsdl()
    {
        $curl = $this->curl();
        $dir = $this->cacheDir();
        foreach ($this->wsdlFiles as $file) {
            if (! file_exists("$dir/$file")) {
                Logger::info("Loading sdk/$file");
                $wsdl = $curl->get($curl->url("sdk/$file"));
                file_put_contents("$dir/$file", $wsdl);
            }
        }
    }

    protected function flushWsdlCache()
    {
        $dir = $this->cacheDir();
        Logger::info("Flushing WSDL Cache in $dir");
        foreach ($this->wsdlFiles as $file) {
            if (file_exists("$dir/$file")) {
                unlink("$dir/$file");
            }
        }
    }

    /**
     * Really fetch the ServiceInstance
     *
     * @see getServiceInstance()
     *
     * @return mixed
     * @throws AuthenticationException
     */
    protected function fetchServiceInstance()
    {
        $result = $this->soapCall(
            'RetrieveServiceContent',
            $this->makeBaseServiceInstanceParam()
        );

        return $result->returnval;
    }

    protected function makeBaseServiceInstanceParam()
    {
        $param = array(
            $this->makeVar('ServiceInstance', 'ServiceInstance')
        );

        return new SoapVar($param, SOAP_ENC_OBJECT);
    }

    /**
     * Log in to to API
     *
     * This will retrieve a session cookie and pass it with subsequent requests
     * @throws AuthenticationException
     */
    public function login()
    {
        if ($this->curl()->hasCookie()) {
            Logger::debug('Using existing Cookie');
            return $this;
        }

        Logger::debug('Sending Login request to %s', $this->makeLocation());
        $request = array(
            '_this'    => $this->getServiceInstance()->sessionManager,
            'userName' => $this->user,
            'password' => $this->pass,
        );

        $this->soapCall('Login', $request);

        return $this;
    }

    /**
     * Logout, destroy our session
     */
    public function logout()
    {
        $request = array(
            '_this' => $this->getServiceInstance()->sessionManager
        );
        try {
            $this->soapCall('Logout', $request);
        } catch (Exception $e) {
            // Do nothing
        }
        $this->curl()->forgetCookie();
    }

    /**
     * @param SelectSet $selectSet
     * @param null $base
     * @return array
     * @throws AuthenticationException
     */
    protected function makeObjectSet(SelectSet $selectSet, $base = null)
    {
        if ($base === null) {
            $base = $this->getServiceInstance()->rootFolder;
        }

        return [
            'obj'   => $base,
            'skip'  => false,
            'selectSet' => $selectSet->toArray(),
        ];
    }

    /**
     * @param PropertySet $propSet
     * @param SelectSet $selectSet
     * @return array
     * @throws AuthenticationException
     */
    public function makePropertyFilterSpec(PropertySet $propSet, SelectSet $selectSet)
    {
        return array(
            'propSet'   => $propSet->toArray(),
            'objectSet' => $this->makeObjectSet($selectSet)
        );
    }

    /**
     * @return string
     */
    protected function cacheDir()
    {
        if ($this->cacheDir === null) {
            $this->cacheDir = SafeCacheDir::getSubDirectory($this->host);
        }

        return $this->cacheDir;
    }

    /**
     * Just a helper method
     *
     * @return SoapVar
     */
    public function makeVar($key, $val)
    {
        return new SoapVar($val, XSD_STRING, $key, null, 'ns1:_this');
    }
}
