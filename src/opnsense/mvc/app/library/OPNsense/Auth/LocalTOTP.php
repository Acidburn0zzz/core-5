<?php

/**
 *    Copyright (C) 2015 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Auth;

require_once 'base32/Base32.php';

/**
 * RFC 6238 TOTP: Time-Based One-Time Password Authenticator
 * @package OPNsense\Auth
 */
class LocalTOTP extends Local
{
    /**
     * @var int time window in seconds (google auth uses 30, some hardware tokens use 60)
     */
    private $timeWindow = 30;

    /**
     * @var int key length (6,8)
     */
    private $otpLength = 6;

    /**
     * @var int number of seconds the clocks (local, remote) may differ
     */
    private $graceperiod = 10;

    /**
     * type name in configuration
     * @return string
     */
    public static function getType()
    {
        return 'totp';
    }

    /**
     * user friendly description of this authenticator
     * @return string
     */
    public function getDescription()
    {
        return gettext("Local + Timebased One Time Password");
    }

    /**
     * set connector properties
     * @param array $config connection properties
     */
    public function setProperties($config)
    {
        parent::setProperties($config);
        if (!empty($config['timeWindow'])) {
            $this->timeWindow = $config['timeWindow'];
        }
        if (!empty($config['otpLength'])) {
            $this->otpLength = $config['otpLength'];
        }
        if (!empty($config['graceperiod'])) {
            $this->graceperiod = $config['graceperiod'];
        }
    }

    /**
     * use graceperiod and timeWindow to calculate which moments in time we should check
     * @return array timestamps
     */
    private function timesToCheck()
    {
        $result = array();
        if ($this->graceperiod > $this->timeWindow) {
            $step = $this->timeWindow;
            $start = -1 * floor($this->graceperiod  / $this->timeWindow) * $this->timeWindow;
        } else {
            $step = $this->graceperiod;
            $start = -1 * $this->graceperiod;
        }
        $now = time();
        for ($count = $start; $count <= $this->graceperiod; $count += $step) {
            $result[] = $now + $count;
            if ($this->graceperiod == 0) {
                // special case, we expect the clocks to match 100%, so step and target are both 0
                break;
            }
        }
        return $result;
    }

    /**
     * @param int $moment timestemp
     * @param string $secret secret to use
     * @return calculated token code
     */
    private function calculateToken($moment, $secret)
    {
        // calculate binary 8 character time for provided window
        $binary_time = pack("N", (int)($moment/$this->timeWindow));
        $binary_time = str_pad($binary_time, 8, chr(0), STR_PAD_LEFT);

        // Generate the hash using the SHA1 algorithm
        $hash = hash_hmac('sha1', $binary_time, $secret, true);
        $offset = ord($hash[19]) & 0xf;
        $otp = (
                ((ord($hash[$offset+0]) & 0x7f) << 24 ) |
                ((ord($hash[$offset+1]) & 0xff) << 16 ) |
                ((ord($hash[$offset+2]) & 0xff) << 8 ) |
                (ord($hash[$offset+3]) & 0xff)
            ) % pow(10, $this->otpLength);


        $otp = str_pad($otp, $this->otpLength, "0", STR_PAD_LEFT);
        return $otp;
    }

    /**
     * return current token code
     * @param $base32seed secret to use
     * @return string token code
     */
    public function testToken($base32seed)
    {
        $otp_seed = \Base32\Base32::decode($base32seed);
        return $this->calculateToken(time(), $otp_seed);
    }

    /**
     * authenticate TOTP RFC 6238
     * @param string $secret secret seed to use
     * @param string $code provided code
     * @return bool is valid
     */
    private function authTOTP($secret, $code)
    {
        foreach ($this->timesToCheck() as $moment) {
            if ($code == $this->calculateToken($moment, $secret)) {
                return true;
            }
        }
        return false;
    }

    /**
     * authenticate user against otp key stored in local database
     * @param string $username username to authenticate
     * @param string $password user password
     * @return bool authentication status
     */
    public function authenticate($username, $password)
    {
        $userObject = $this->getUser($username);
        if ($userObject != null && !empty($userObject->otp_seed)) {
            if (strlen($password) > $this->otpLength) {
                // split otp token code and userpassword
                $code = substr($password, 0, $this->otpLength);
                $userPassword =  substr($password, $this->otpLength);
                $otp_seed = \Base32\Base32::decode($userObject->otp_seed);
                if ($this->authTOTP($otp_seed, $code)) {
                    // token valid, do local auth
                    return parent::authenticate($userObject, $userPassword);
                }
            }
        }
        return false;
    }

    /**
     * retrieve configuration options
     * @return array
     */
    public function getConfigurationOptions()
    {
        $fields = array();
        $fields["otpLength"] = array();
        $fields["otpLength"]["name"] = gettext("Token length");
        $fields["otpLength"]["type"] = "dropdown";
        $fields["otpLength"]["default"] = 6;
        $fields["otpLength"]["options"] = array();
        $fields["otpLength"]["options"]["6"] = "6";
        $fields["otpLength"]["options"]["8"] = "8";
        $fields["otpLength"]["help"] = gettext("Token length to use");
        $fields["otpLength"]["validate"] = function ($value) {
            if (!in_array($value, array(6,8))) {
                return array(gettext("Only token lengths of 6 or 8 characters are supported"));
            } else {
                return array();
            }
        };
        $fields["timeWindow"] = array();
        $fields["timeWindow"]["name"] = gettext("Time window");
        $fields["timeWindow"]["type"] = "text";
        $fields["timeWindow"]["default"] = null;
        $fields["timeWindow"]["help"] = gettext("The time period in which the token will be valid,".
          " default is 30 seconds (google authenticator)") ;
        $fields["timeWindow"]["validate"] = function ($value) {
            if (!empty($value) && filter_var($value, FILTER_SANITIZE_NUMBER_INT) != $value) {
                return array(gettext("Please enter a valid time window in seconds"));
            } else {
                return array();
            }
        };
        $fields["graceperiod"] = array();
        $fields["graceperiod"]["name"] = gettext("Grace period");
        $fields["graceperiod"]["type"] = "text";
        $fields["graceperiod"]["default"] = null;
        $fields["graceperiod"]["help"] = gettext("Time in seconds in which this server and the token may differ,".
          " default is 10 seconds. Set higher for a less secure easier match.");
        $fields["graceperiod"]["validate"] = function ($value) {
            if (!empty($value) && filter_var($value, FILTER_SANITIZE_NUMBER_INT) != $value) {
                return array(gettext("Please enter a valid grace period in seconds"));
            } else {
                return array();
            }
        };

        return $fields;
    }
}
