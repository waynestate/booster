<?php

class WayneAuth
{
    public $config;
    public $accessid;

    public function __construct($config)
    {
        $this->config = $config;
    }

    public function authenticateSSO()
    {
        $cookie = $this->_parseSSOCookie('v');
        if ($cookie['verified']) {
            $this->accessid = $cookie['accessid'];

            return true;
        }

        return false;
    }

    public function getAccessID()
    {
        return $this->accessid;
    }

    public function _parseSSOCookie($method)
    {
        if (!isset($_COOKIE['validUser'])) {
            return array('verified' => false);
        }
        list($code, $accessid, $secret) = explode(':', $_COOKIE['validUser']);
        $socket = @fsockopen('ssl://' . $this->config['sso_host'], $this->config['sso_port'], $errno, $errstr, 5);
        if (!$socket) {
            return array('verified' => false);
        }
        fwrite($socket, $method . ':' . $code . "\n");
        $answer = trim(fgets($socket));
        fclose($socket);

        return array('verified' => $answer === 'OK', 'accessid' => $accessid);
    }
}
