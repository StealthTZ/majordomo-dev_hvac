<?php
/**
 * HVAC transport layer for MajorDoMo module dev_hvac
 *
 * Supported devices:
 *   - Cooper&Hunter with "Smart Wi-Fi" controller  (class CH,   devtype 0x202,  TCP 12416 / UDP 12414)
 *   - Gree / Cooper&Hunter with Gree Wi-Fi module  (class Gree, devtype 0x2711, UDP 7000)
 *
 * Gree protocol reference implementations:
 *   https://github.com/tomikaa87/gree-remote
 *   https://github.com/cmroche/greeclimate
 *
 * @version 0.2 (PHP 7.0 - 8.4 compatible)
 */

if (!class_exists('hvac', false)) {

    class hvac
    {
        /** @var string */
        protected $name = '';
        /** @var string */
        protected $host = '';
        /** @var array list of 6 integers, natural (wire) order */
        protected $mac = array();
        /** @var string device AES key */
        protected $keys = '';
        /** @var int socket timeout, seconds */
        protected $timeout = 5;
        /** @var int */
        protected $count = 0;
        /** @var array */
        protected $id = array(0, 0, 0, 0);
        /** @var int */
        protected $devtype = 0;
        /** @var string ECB (protocol v1) or GCM (protocol v2) */
        protected $encryption = 'ECB';
        /** @var string last transport error, for diagnostics */
        protected $last_error = '';

        /** Generic AES-128-ECB key, protocol v1 */
        const DEFAULT_KEY = 'a3K8Bx%2r8Y7#xDh';
        /** Generic AES-128-GCM key, protocol v2 */
        const DEFAULT_GCM_KEY = '{yxAHAY_Lm6pbC/<';
        /** Fixed GCM nonce used by Gree */
        const GCM_IV = "\x54\x40\x78\x44\x49\x67\x5a\x51\x6c\x5e\x63\x13";
        /** Fixed GCM additional authenticated data used by Gree */
        const GCM_AAD = 'qualcomm-test';

        const GREE_PORT = 7000;
        const CH_DISCOVERY_PORT = 12414;
        const CH_PORT = 12416;

        const BROADCAST_ADDR = '255.255.255.255';

        /**
         * @param string       $h host (IP address)
         * @param string|array $m MAC: "aa:bb:cc:dd:ee:ff", "aabbccddeeff" or array of 6 bytes
         * @param string|int   $d device type ("0x2711" or 10001)
         * @param string       $k device AES key (Gree only)
         * @param string       $enc "ECB" (protocol v1) or "GCM" (protocol v2), Gree only
         */
        public function __construct($h = '', $m = '', $d = 0, $k = '', $enc = 'ECB')
        {
            $this->host = trim((string)$h);
            $this->devtype = self::normalizeDevType($d);
            $this->keys = (string)$k;
            $this->encryption = self::normalizeEncryption($enc);
            $this->mac = self::normalizeMac($m);
            $this->count = mt_rand(0, 0xffff);
        }

        public function __destruct()
        {
        }

        // ----------------------------------------------------------------
        // factory / meta
        // ----------------------------------------------------------------

        /**
         * @return hvac|null
         */
        public static function CreateDevice($h = '', $m = '', $d = 0, $k = '', $enc = 'ECB')
        {
            switch (self::model($d)) {
                case 0:
                    return new CH($h, $m, $d);
                case 1:
                    return new Gree($h, $m, $d, $k, $enc);
                default:
                    return null;
            }
        }

        /**
         * Device family lookup.
         * NOTE: must stay static - it is called from static context (CreateDevice()).
         * Calling a non-static method statically is a fatal error since PHP 8.0.
         *
         * @param string|int $devtype
         * @param string     $needle 'type' or 'model'
         * @return int|string
         */
        public static function model($devtype, $needle = 'type')
        {
            $type = 'Unknown';
            $model = 'Unknown';

            switch (self::normalizeDevType($devtype)) {
                case 0x202:
                    $model = 'CH';
                    $type = 0;
                    break;
                case 0x2711:
                    $model = 'Gree';
                    $type = 1;
                    break;
                default:
                    break;
            }

            return ($needle == 'model') ? $model : $type;
        }

        public function mac()
        {
            $parts = array();
            foreach ($this->mac as $b) {
                $parts[] = sprintf('%02x', (int)$b);
            }
            return implode(':', $parts);
        }

        /**
         * Kept for backward compatibility with the scan template.
         * @return string
         */
        public function macgree()
        {
            return $this->mac();
        }

        /**
         * MAC in the form Gree expects inside JSON packets: 12 hex digits, no separators.
         * @return string
         */
        public function get_mac()
        {
            $out = '';
            foreach ($this->mac as $b) {
                $out .= sprintf('%02x', (int)$b);
            }
            return $out;
        }

        public function host()
        {
            return $this->host;
        }

        public function name()
        {
            return $this->name;
        }

        public function keys()
        {
            return $this->keys;
        }

        public function encryption()
        {
            return $this->encryption;
        }

        public function lastError()
        {
            return $this->last_error;
        }

        public function devtype()
        {
            return sprintf('0x%x', $this->devtype);
        }

        public function devmodel()
        {
            return self::model($this->devtype, 'model');
        }

        /**
         * Default implementation so that anything CreateDevice() hands back can be
         * polled without a class check. Overridden by CH and Gree.
         * @return array
         */
        public function get_status()
        {
            return array();
        }

        public function setTimeout($seconds)
        {
            $seconds = (int)$seconds;
            if ($seconds > 0) {
                $this->timeout = $seconds;
            }
        }

        // ----------------------------------------------------------------
        // helpers
        // ----------------------------------------------------------------

        protected static function normalizeDevType($d)
        {
            if (is_string($d)) {
                $d = trim($d);
                if ($d === '') {
                    return 0;
                }
                return (int)hexdec(preg_replace('/^0x/i', '', $d));
            }
            return (int)$d;
        }

        protected static function normalizeEncryption($enc)
        {
            return (strtoupper(trim((string)$enc)) === 'GCM') ? 'GCM' : 'ECB';
        }

        /**
         * Accepts "aa:bb:cc:dd:ee:ff", "aabbccddeeff" or an array of 6 bytes
         * (integers or two-digit hex strings) in natural order.
         * Always returns 6 integers in natural order.
         *
         * @param string|array $m
         * @return array
         */
        protected static function normalizeMac($m)
        {
            $bytes = array();

            if (is_array($m)) {
                foreach ($m as $v) {
                    if (is_string($v)) {
                        $v = preg_match('/^[0-9a-f]{1,2}$/i', trim($v)) ? hexdec(trim($v)) : (int)$v;
                    }
                    $bytes[] = ((int)$v) & 0xFF;
                }
            } else {
                $hex = strtolower(preg_replace('/[^0-9a-f]/i', '', (string)$m));
                if (strlen($hex) % 2 !== 0) {
                    $hex = '0' . $hex;
                }
                if ($hex !== '') {
                    foreach (str_split($hex, 2) as $pair) {
                        $bytes[] = (int)hexdec($pair);
                    }
                }
            }

            while (count($bytes) < 6) {
                array_unshift($bytes, 0);
            }
            if (count($bytes) > 6) {
                $bytes = array_slice($bytes, -6);
            }

            return $bytes;
        }

        protected static function bytearray($size)
        {
            return array_fill(0, max(0, (int)$size), 0);
        }

        /**
         * Binary string -> zero based array of byte values.
         * Tolerant to non-string input: since PHP 8.0 unpack() throws a TypeError
         * for anything but a string, so every failed transfer used to be fatal.
         *
         * @param mixed $data
         * @return array
         */
        protected static function byte2array($data)
        {
            if (is_array($data)) {
                return array_values($data);
            }
            if (!is_string($data) || $data === '') {
                return array();
            }
            $unpacked = @unpack('C*', $data);
            if (!is_array($unpacked)) {
                return array();
            }
            return array_values($unpacked);
        }

        /**
         * Array of byte values -> binary string
         * @param array $array
         * @return string
         */
        protected static function byte($array)
        {
            $out = '';
            if (!is_array($array)) {
                return $out;
            }
            foreach ($array as $v) {
                $out .= chr(((int)$v) & 0xFF);
            }
            return $out;
        }

        /**
         * Trailing checksum byte used by the CH protocol: sum of all bytes modulo 256.
         *
         * The original code used intval(substr(dechex(array_sum($p)), 1), 16), which is
         * only equal to sum % 256 while 0x100 <= sum <= 0xFFF and silently produces a
         * wrong checksum outside of that range.
         *
         * @param array $bytes
         * @return int
         */
        protected static function checksum($bytes)
        {
            $sum = 0;
            foreach ($bytes as $b) {
                $sum += ((int)$b) & 0xFF;
            }
            return $sum & 0xFF;
        }

        protected static function trimJson($s)
        {
            if (!is_string($s)) {
                return '';
            }
            $pos = strrpos($s, '}');
            return ($pos === false) ? '' : substr($s, 0, $pos + 1);
        }

        // ----------------------------------------------------------------
        // discovery
        // ----------------------------------------------------------------

        /**
         * Discovery of Cooper&Hunter "Smart Wi-Fi" controllers (UDP 12414).
         * @param int $timeout seconds to wait for answers
         * @return array of hvac
         */
        public static function Discover($timeout = 2)
        {
            $devices = array();

            if (!function_exists('socket_create')) {
                return $devices;
            }

            $cs = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($cs === false) {
                return $devices;
            }

            @socket_set_option($cs, SOL_SOCKET, SO_REUSEADDR, 1);
            @socket_set_option($cs, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_set_option($cs, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
            @socket_set_option($cs, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0));

            if (!@socket_bind($cs, '0.0.0.0', 0)) {
                @socket_close($cs);
                return $devices;
            }

            $packet = array(0xAA, 0xAA, 0x06, 0x02, 0xFF, 0xFF, 0xFF, 0x00, 0x59);
            $raw = self::byte($packet);

            if (@socket_sendto($cs, $raw, strlen($raw), 0, self::BROADCAST_ADDR, self::CH_DISCOVERY_PORT) === false) {
                @socket_close($cs);
                return $devices;
            }

            $deadline = microtime(true) + max(1, (int)$timeout);
            $seen = array();

            while (microtime(true) < $deadline) {
                $response = '';
                $from = '';
                $port = 0;
                $bytes = @socket_recvfrom($cs, $response, 2048, 0, $from, $port);

                if ($bytes === false || $bytes <= 0 || !is_string($response)) {
                    break;
                }
                if (strlen($response) < 0x0D) {
                    continue;
                }

                $responsepacket = self::byte2array($response);
                $devtype = $responsepacket[0x5] | ($responsepacket[0x6] << 8);
                $mac = array_slice($responsepacket, 0x7, 6);

                $device = self::CreateDevice($from, $mac, $devtype);
                if ($device === null) {
                    continue;
                }
                if (isset($seen[$device->mac()])) {
                    continue;
                }
                $seen[$device->mac()] = 1;

                $device->name = trim(str_replace(array("\0", "\2"), '', self::byte(array_slice($responsepacket, 0x40))));
                $devices[] = $device;
            }

            @socket_shutdown($cs, 2);
            @socket_close($cs);

            return $devices;
        }

        /**
         * Discovery of Gree Wi-Fi modules (UDP 7000).
         * Handles both protocol v1 (AES-128-ECB) and v2 (AES-128-GCM, "tag" in the reply).
         *
         * @param int $timeout seconds to wait for answers
         * @return array of hvac
         */
        public static function DiscoverGREE($timeout = 2)
        {
            $devices = array();

            if (!function_exists('socket_create')) {
                return $devices;
            }

            $cs = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($cs === false) {
                return $devices;
            }

            @socket_set_option($cs, SOL_SOCKET, SO_REUSEADDR, 1);
            @socket_set_option($cs, SOL_SOCKET, SO_BROADCAST, 1);
            @socket_set_option($cs, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 1, 'usec' => 0));
            @socket_set_option($cs, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 1, 'usec' => 0));

            if (!@socket_bind($cs, '0.0.0.0', 0)) {
                @socket_close($cs);
                return $devices;
            }

            $packet = json_encode(array('t' => 'scan'));

            if (@socket_sendto($cs, $packet, strlen($packet), 0, self::BROADCAST_ADDR, self::GREE_PORT) === false) {
                @socket_close($cs);
                return $devices;
            }

            $deadline = microtime(true) + max(1, (int)$timeout);
            $found = array();

            while (microtime(true) < $deadline) {
                $json_response = '';
                $from = '';
                $port = 0;
                $bytes = @socket_recvfrom($cs, $json_response, 4096, 0, $from, $port);

                if ($bytes === false || $bytes <= 0 || !is_string($json_response)) {
                    break;
                }

                $response = json_decode($json_response, true);
                if (!is_array($response) || !isset($response['pack'])) {
                    continue;
                }

                // Protocol v2 devices add a "tag" field (AES-GCM authentication tag).
                $encryption = (isset($response['tag']) && $response['tag'] !== '') ? 'GCM' : 'ECB';

                if ($encryption === 'GCM') {
                    $pack_json = self::fnDecryptGCM($response['pack'], $response['tag'], self::DEFAULT_GCM_KEY);
                } else {
                    $pack_json = self::fnDecrypt($response['pack'], self::DEFAULT_KEY);
                }

                $pack = json_decode($pack_json, true);
                if (!is_array($pack)) {
                    $pack = array();
                }

                $mac = '';
                foreach (array('cid', 'mac') as $field) {
                    if (isset($response[$field]) && $response[$field] !== '') {
                        $mac = $response[$field];
                        break;
                    }
                    if (isset($pack[$field]) && $pack[$field] !== '') {
                        $mac = $pack[$field];
                        break;
                    }
                }
                if ($mac === '') {
                    continue;
                }
                if (isset($found[$mac])) {
                    continue;
                }
                $found[$mac] = 1;

                $keys = self::pair($mac, $from, $encryption);

                $device = self::CreateDevice($from, $mac, 0x2711, (string)$keys, $encryption);
                if ($device === null) {
                    continue;
                }
                $device->name = isset($pack['name']) ? (string)$pack['name'] : '';
                $devices[] = $device;
            }

            @socket_shutdown($cs, 2);
            @socket_close($cs);

            return $devices;
        }

        // ----------------------------------------------------------------
        // Cooper&Hunter "Smart Wi-Fi" transport (plain TCP, port 12416)
        // ----------------------------------------------------------------

        /**
         * @param array $payload
         * @return string|array raw answer on success, empty array on failure
         */
        public function send_packet($payload)
        {
            $this->last_error = '';

            if (!is_array($payload) || $this->host === '') {
                $this->last_error = 'invalid request';
                return array();
            }
            if (!function_exists('socket_create')) {
                $this->last_error = 'php sockets extension is not available';
                return array();
            }

            $cs = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
            if ($cs === false) {
                $this->last_error = 'socket_create failed';
                return array();
            }

            @socket_set_option($cs, SOL_SOCKET, SO_REUSEADDR, 1);
            @socket_set_option($cs, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
            @socket_set_option($cs, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));

            if (!@socket_connect($cs, $this->host, self::CH_PORT)) {
                $this->last_error = 'connect to ' . $this->host . ':' . self::CH_PORT . ' failed';
                @socket_close($cs);
                return array();
            }

            $packet = array_values($payload);
            $packet[] = self::checksum($packet);
            $raw = self::byte($packet);

            if (@socket_send($cs, $raw, strlen($raw), 0) === false) {
                $this->last_error = 'send failed';
                @socket_close($cs);
                return array();
            }

            $response = '';
            $received = @socket_recv($cs, $response, 2048, 0);

            @socket_shutdown($cs, 2);
            @socket_close($cs);

            if ($received === false || $received <= 0 || !is_string($response) || $response === '') {
                $this->last_error = 'no answer from ' . $this->host;
                return array();
            }

            $resp = self::byte2array($response);
            if (count($resp) < 2) {
                $this->last_error = 'answer too short';
                return array();
            }

            $crcresp = array_pop($resp);
            if ((int)$crcresp !== self::checksum($resp)) {
                $this->last_error = 'checksum mismatch';
                return array();
            }

            return $response;
        }

        // ----------------------------------------------------------------
        // Gree transport (UDP, port 7000)
        // ----------------------------------------------------------------

        /**
         * AES-128-ECB (protocol v1) encryption of a pack.
         * @return string base64 payload, empty string on failure
         */
        public static function fnEncrypt($sValue, $sSecretKey, $sMethod = 'aes-128-ecb')
        {
            $encrypted = @openssl_encrypt((string)$sValue, $sMethod, (string)$sSecretKey, OPENSSL_RAW_DATA);
            if ($encrypted === false) {
                return '';
            }
            return base64_encode($encrypted);
        }

        /**
         * AES-128-ECB (protocol v1) decryption of a pack.
         * Devices are not consistent about padding, so a zero-padding retry plus a
         * cut at the last "}" is used - exactly what the reference clients do.
         *
         * @return string decrypted JSON, empty string on failure
         */
        public static function fnDecrypt($sValue, $sSecretKey, $sMethod = 'aes-128-ecb')
        {
            if (!is_string($sValue) || $sValue === '') {
                return '';
            }
            $raw = base64_decode($sValue, true);
            if ($raw === false || $raw === '') {
                return '';
            }

            $decrypted = @openssl_decrypt($raw, $sMethod, (string)$sSecretKey, OPENSSL_RAW_DATA);
            if ($decrypted === false) {
                $decrypted = @openssl_decrypt($raw, $sMethod, (string)$sSecretKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
            }
            if (!is_string($decrypted)) {
                return '';
            }

            return self::trimJson($decrypted);
        }

        public static function gcmAvailable()
        {
            static $available = null;
            if ($available === null) {
                $available = function_exists('openssl_encrypt')
                    && in_array('aes-128-gcm', array_map('strtolower', openssl_get_cipher_methods()));
            }
            return $available;
        }

        /**
         * AES-128-GCM (protocol v2) encryption of a pack.
         * @return array|false array('pack'=>..., 'tag'=>...)
         */
        public static function fnEncryptGCM($sValue, $sSecretKey)
        {
            if (!self::gcmAvailable()) {
                return false;
            }
            $tag = '';
            $encrypted = @openssl_encrypt(
                (string)$sValue,
                'aes-128-gcm',
                (string)$sSecretKey,
                OPENSSL_RAW_DATA,
                self::GCM_IV,
                $tag,
                self::GCM_AAD,
                16
            );
            if ($encrypted === false) {
                return false;
            }
            return array('pack' => base64_encode($encrypted), 'tag' => base64_encode($tag));
        }

        /**
         * AES-128-GCM (protocol v2) decryption of a pack.
         * @return string decrypted JSON, empty string on failure
         */
        public static function fnDecryptGCM($sValue, $sTag, $sSecretKey)
        {
            if (!self::gcmAvailable()) {
                return '';
            }
            if (!is_string($sValue) || $sValue === '' || !is_string($sTag) || $sTag === '') {
                return '';
            }
            $raw = base64_decode($sValue, true);
            $tag = base64_decode($sTag, true);
            if ($raw === false || $tag === false || $raw === '' || $tag === '') {
                return '';
            }

            $decrypted = @openssl_decrypt(
                $raw,
                'aes-128-gcm',
                (string)$sSecretKey,
                OPENSSL_RAW_DATA,
                self::GCM_IV,
                $tag,
                self::GCM_AAD
            );
            if (!is_string($decrypted)) {
                return '';
            }

            return self::trimJson(str_replace("\xff", '', $decrypted));
        }

        /**
         * Build the outer "pack" envelope.
         * Field layout follows the official application:
         *   cid  = "app"                (sender)
         *   tcid = device MAC           (recipient)
         * The original code had cid/tcid swapped in the binding request.
         *
         * @return string JSON, empty string on failure
         */
        protected static function buildEnvelope($pack_json, $devmac, $key, $encryption, $i = 0)
        {
            $envelope = array(
                'cid' => 'app',
                'i' => (int)$i,
                't' => 'pack',
                'uid' => 0,
                'tcid' => (string)$devmac,
            );

            if (self::normalizeEncryption($encryption) === 'GCM') {
                $encrypted = self::fnEncryptGCM($pack_json, $key);
                if ($encrypted === false) {
                    return '';
                }
                $envelope['tag'] = $encrypted['tag'];
                $envelope['pack'] = $encrypted['pack'];
            } else {
                $pack = self::fnEncrypt($pack_json, $key);
                if ($pack === '') {
                    return '';
                }
                $envelope['pack'] = $pack;
            }

            return json_encode($envelope);
        }

        /**
         * Decode an answer envelope. The cipher is detected from the answer itself,
         * so a device that silently switched to protocol v2 still works.
         *
         * @return array|null decoded pack
         */
        protected static function parseEnvelope($raw, $key)
        {
            if (!is_string($raw) || $raw === '') {
                return null;
            }
            $data = json_decode($raw, true);
            if (!is_array($data) || !isset($data['pack'])) {
                return null;
            }

            if (isset($data['tag']) && $data['tag'] !== '') {
                $json = self::fnDecryptGCM($data['pack'], $data['tag'], $key);
            } else {
                $json = self::fnDecrypt($data['pack'], $key);
            }
            if ($json === '') {
                return null;
            }

            $pack = json_decode($json, true);
            return is_array($pack) ? $pack : null;
        }

        /**
         * One UDP request/answer exchange.
         * @return string raw answer, empty string on failure
         */
        protected static function udpRequest($host, $request, $timeout = 3, $broadcast = false)
        {
            if (!function_exists('socket_create') || $host === '' || $request === '') {
                return '';
            }

            $cs = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            if ($cs === false) {
                return '';
            }

            $timeout = max(1, (int)$timeout);

            @socket_set_option($cs, SOL_SOCKET, SO_REUSEADDR, 1);
            if ($broadcast) {
                @socket_set_option($cs, SOL_SOCKET, SO_BROADCAST, 1);
            }
            @socket_set_option($cs, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
            @socket_set_option($cs, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $timeout, 'usec' => 0));

            if (!@socket_bind($cs, '0.0.0.0', 0)) {
                @socket_close($cs);
                return '';
            }

            if (@socket_sendto($cs, $request, strlen($request), 0, $host, self::GREE_PORT) === false) {
                @socket_close($cs);
                return '';
            }

            $result = '';
            $deadline = microtime(true) + $timeout;

            while (microtime(true) < $deadline) {
                $response = '';
                $from = '';
                $port = 0;
                $bytes = @socket_recvfrom($cs, $response, 4096, 0, $from, $port);

                if ($bytes === false || $bytes <= 0 || !is_string($response)) {
                    break;
                }
                // when talking to one device, ignore answers coming from anybody else
                if (!$broadcast && $from !== '' && $from !== $host) {
                    continue;
                }
                $result = $response;
                break;
            }

            @socket_close($cs);
            return $result;
        }

        /**
         * Bind to a Gree device and obtain its personal AES key.
         *
         * @param string $m   device MAC (12 hex digits)
         * @param string $h   device IP
         * @param string $encryption in/out: "ECB" or "GCM", updated if the fallback succeeds
         * @param int    $timeout
         * @return string device key, empty string on failure
         */
        public static function pair($m, $h, &$encryption = 'ECB', $timeout = 3)
        {
            $order = array(self::normalizeEncryption($encryption));
            $order[] = ($order[0] === 'GCM') ? 'ECB' : 'GCM';

            $pack_json = json_encode(array('mac' => (string)$m, 't' => 'bind', 'uid' => 0));

            foreach ($order as $mode) {
                if ($mode === 'GCM' && !self::gcmAvailable()) {
                    continue;
                }
                $generic = ($mode === 'GCM') ? self::DEFAULT_GCM_KEY : self::DEFAULT_KEY;

                $request = self::buildEnvelope($pack_json, $m, $generic, $mode, 1);
                if ($request === '') {
                    continue;
                }

                $raw = self::udpRequest($h, $request, $timeout);
                $pack = self::parseEnvelope($raw, $generic);

                if (is_array($pack) && isset($pack['key']) && $pack['key'] !== '') {
                    $encryption = $mode;
                    return (string)$pack['key'];
                }
            }

            return '';
        }

        /**
         * Send an inner pack to this device and return the decoded answer.
         * Unicast first, broadcast as a fallback (device may have changed its IP).
         *
         * @param array $pack
         * @return array|null
         */
        protected function greeExchange($pack)
        {
            $this->last_error = '';

            if ($this->keys === '') {
                $this->last_error = 'device is not bound (empty key)';
                return null;
            }

            $devmac = $this->get_mac();
            $pack['mac'] = $devmac;

            $pack_json = json_encode($pack);
            $request = self::buildEnvelope($pack_json, $devmac, $this->keys, $this->encryption, 0);
            if ($request === '') {
                $this->last_error = 'unable to encrypt request';
                return null;
            }

            $raw = '';
            if ($this->host !== '') {
                $raw = self::udpRequest($this->host, $request, $this->timeout);
            }
            if ($raw === '') {
                $raw = self::udpRequest(self::BROADCAST_ADDR, $request, $this->timeout, true);
            }
            if ($raw === '') {
                $this->last_error = 'no answer from ' . $this->host;
                return null;
            }

            $answer = self::parseEnvelope($raw, $this->keys);
            if ($answer === null) {
                $this->last_error = 'unable to decrypt answer';
            }
            return $answer;
        }

        /**
         * Kept for backward compatibility with third party code.
         * @return string
         */
        public function send_gree_packet($request)
        {
            if ($this->host === '') {
                return '';
            }
            return self::udpRequest($this->host, $request, $this->timeout);
        }

        /**
         * Kept for backward compatibility with third party code.
         * @return string
         */
        public function send_gree_packet_broadcast($request)
        {
            return self::udpRequest(self::BROADCAST_ADDR, $request, $this->timeout, true);
        }

        /**
         * Kept for backward compatibility with third party code.
         * @return string
         */
        public function get_request($sEncPack, $devmac)
        {
            return json_encode(array(
                'cid' => 'app',
                'i' => 0,
                't' => 'pack',
                'tcid' => (string)$devmac,
                'uid' => 0,
                'pack' => (string)$sEncPack,
            ));
        }
    }

    // --------------------------------------------------------------------
    // Cooper&Hunter with "Smart Wi-Fi" controller
    // --------------------------------------------------------------------

    class CH extends hvac
    {
        /** request used both to read the state and as a template for writing it back */
        protected static $STATUS_PAYLOAD = array(
            0xAA, 0xAA, 0x12, 0xA0, 0x0A, 0x0A, 0x00, 0x00, 0x00, 0x00,
            0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00,
        );

        public function __construct($h = '', $m = '', $d = 0x202)
        {
            parent::__construct($h, $m, $d);
        }

        /**
         * @return array
         */
        public function get_status()
        {
            $response = self::byte2array($this->send_packet(self::$STATUS_PAYLOAD));

            $data = array();
            if (count($response) < 17) {
                return $data;
            }

            $data['ac_mode'] = $response[7] & 7;
            $data['power'] = ($response[7] >> 3) & 1;
            $data['fan_speed'] = ($response[7] >> 4) & 15;
            $data['temperature'] = ($response[8] & 15) + 16;
            $data['temptype'] = ($response[8] >> 5) & 1;
            $data['quiet'] = ($response[8] >> 6) & 1;
            $data['fan_direction'] = $response[9] & 15;
            $data['eco'] = $response[10] & 1;
            $data['light'] = ($response[10] >> 7) & 1;
            $data['health'] = ($response[10] >> 6) & 1;
            $data['timing'] = ($response[10] >> 5) & 1;
            $data['dry'] = ($response[10] >> 4) & 1;
            $data['wdnumber_mode'] = ($response[10] >> 2) & 3;
            $data['sleep'] = ($response[10] >> 1) & 1;
            $data['energy_save'] = ($response[10] >> 4) & 1;
            $data['stepless_max'] = $response[14];
            $data['indoorTemperature'] = $response[15] . '.' . $response[16];

            return $data;
        }

        /**
         * Read the current state, apply $mutator to it and write it back.
         *
         * @param callable $mutator function (array &$state)
         * @return bool
         */
        protected function updateState($mutator)
        {
            $state = self::byte2array($this->send_packet(self::$STATUS_PAYLOAD));
            if (count($state) < 17) {
                return false;
            }

            array_pop($state);            // drop the checksum, send_packet() appends a fresh one
            $mutator($state);
            $state[3] = $state[3] | 1;    // mark the packet as a "write" request

            $result = $this->send_packet($state);
            return is_string($result) && $result !== '';
        }

        public function set_temp($temperature)
        {
            $temperature = (int)$temperature;
            if ($temperature < 16) {
                $temperature = 16;
            }
            if ($temperature > 31) {
                $temperature = 31;
            }
            return $this->updateState(function (&$s) use ($temperature) {
                $s[8] = ($s[8] & 240) | (($temperature - 16) & 15);
            });
        }

        public function set_power($power)
        {
            $power = (int)$power;
            return $this->updateState(function (&$s) use ($power) {
                $s[7] = $s[7] & 247;
                if ($power) {
                    $s[7] = $s[7] | 8;
                }
            });
        }

        public function set_ac_mode($mode)
        {
            $mode = (int)$mode & 7;
            return $this->updateState(function (&$s) use ($mode) {
                $s[7] = ($s[7] & 248) | $mode;
            });
        }

        public function set_fan_speed($mode)
        {
            // 0..6 and 8 are valid; anything else is treated as "auto"
            $mode = (int)$mode;
            if (($mode < 0 || $mode > 6) && $mode != 8) {
                $mode = 0;
            }
            $result_mode = ($mode << 4) & 240;

            return $this->updateState(function (&$s) use ($result_mode) {
                $s[7] = ($s[7] & 15) | $result_mode;
            });
        }

        public function set_quiet($mode)
        {
            return $this->setBit(8, 6, $mode);
        }

        public function set_fan_direction($mode)
        {
            $mode = (int)$mode & 15;
            return $this->updateState(function (&$s) use ($mode) {
                $s[9] = ($s[9] & 240) | $mode;
            });
        }

        public function set_stepless_max($stepless_max)
        {
            $stepless_max = (int)$stepless_max & 0xFF;
            return $this->updateState(function (&$s) use ($stepless_max) {
                $s[14] = $stepless_max;
            });
        }

        public function set_light($mode)
        {
            return $this->setBit(10, 7, $mode);
        }

        public function set_health($mode)
        {
            return $this->setBit(10, 6, $mode);
        }

        public function set_sleep($mode)
        {
            return $this->setBit(10, 1, $mode);
        }

        public function set_energy_save($mode)
        {
            return $this->setBit(10, 4, $mode);
        }

        public function set_eco($mode)
        {
            return $this->setBit(10, 0, $mode);
        }

        /**
         * @param int $offset byte offset inside the packet
         * @param int $bit    bit number
         * @param mixed $mode on/off
         * @return bool
         */
        protected function setBit($offset, $bit, $mode)
        {
            $offset = (int)$offset;
            $mask = 1 << (int)$bit;
            $on = (bool)$mode;

            return $this->updateState(function (&$s) use ($offset, $mask, $on) {
                if (!isset($s[$offset])) {
                    return;
                }
                $s[$offset] = $on ? ($s[$offset] | $mask) : ($s[$offset] & ~$mask & 0xFF);
            });
        }
    }

    // --------------------------------------------------------------------
    // Gree Wi-Fi module (also used by many Cooper&Hunter units)
    // --------------------------------------------------------------------

    class Gree extends hvac
    {
        /** parameter name -> property name reported to MajorDoMo */
        protected static $STATUS_MAP = array(
            'Pow' => 'power',
            'Mod' => 'ac_mode',
            'SetTem' => 'temperature',
            'WdSpd' => 'fan_speed',
            'Air' => 'air',
            'Blo' => 'blow',
            'Health' => 'health',
            'SwhSlp' => 'sleep',
            'Lig' => 'light',
            'SwingLfRig' => 'fan_directionh',
            'SwUpDn' => 'fan_direction',
            'Quiet' => 'quiet',
            'Tur' => 'turbo',
            'StHt' => 'stht',
            'TemUn' => 'temptype',
            'HeatCoolType' => 'heatcooltype',
            'TemRec' => 'temrec',
            'SvSt' => 'energy_save',
            // not requested by default: not every unit supports it and an unsupported
            // column shifts the whole "dat" array. Mapped here in case a firmware
            // echoes it back on its own.
            'TemSen' => 'indoorTemperature',
        );

        /** columns requested from the device - kept identical to the previous version */
        protected static $STATUS_COLS = array(
            'Pow', 'Mod', 'SetTem', 'WdSpd', 'Air', 'Blo', 'Health', 'SwhSlp', 'Lig',
            'SwingLfRig', 'SwUpDn', 'Quiet', 'Tur', 'StHt', 'TemUn', 'HeatCoolType',
            'TemRec', 'SvSt',
        );

        public function __construct($h = '', $m = '', $d = 0x2711, $k = '', $enc = 'ECB')
        {
            parent::__construct($h, $m, $d, $k, $enc);
        }

        /**
         * @return array
         */
        public function get_status()
        {
            $cols = self::$STATUS_COLS;

            $answer = $this->greeExchange(array(
                'cols' => $cols,
                't' => 'status',
            ));

            $data = array();
            if (!is_array($answer) || !isset($answer['dat']) || !is_array($answer['dat'])) {
                return $data;
            }

            // some firmwares echo the requested columns back, use them when present
            $names = (isset($answer['cols']) && is_array($answer['cols'])) ? $answer['cols'] : $cols;
            $values = array_values($answer['dat']);

            foreach ($names as $i => $col) {
                if (!isset(self::$STATUS_MAP[$col]) || !array_key_exists($i, $values)) {
                    continue;
                }
                $value = $values[$i];
                if ($col === 'TemSen') {
                    // some units report the room temperature with a +40 offset
                    $value = ((int)$value > 40) ? ((int)$value - 40) : (int)$value;
                }
                $data[self::$STATUS_MAP[$col]] = $value;
            }

            return $data;
        }

        /**
         * @param array $options parameter => value
         * @return bool
         */
        public function set_options($options)
        {
            if (!is_array($options) || !count($options)) {
                return false;
            }

            $answer = $this->greeExchange(array(
                'opt' => array_keys($options),
                'p' => array_values($options),
                't' => 'cmd',
            ));

            return is_array($answer);
        }

        public function set_temp($temperature)
        {
            $temperature = (int)$temperature;
            if ($temperature < 16) {
                $temperature = 16;
            }
            if ($temperature > 30) {
                $temperature = 30;
            }
            return $this->set_options(array('TemUn' => 0, 'SetTem' => $temperature));
        }

        public function set_power($power)
        {
            return $this->set_options(array('Pow' => (int)((bool)$power)));
        }

        public function set_ac_mode($mode)
        {
            return $this->set_options(array('Mod' => (int)$mode));
        }

        public function set_fan_speed($mode)
        {
            return $this->set_options(array('WdSpd' => (int)$mode));
        }

        public function set_quiet($mode)
        {
            return $this->set_options(array('Quiet' => (int)$mode));
        }

        public function set_fan_direction($mode)
        {
            return $this->set_options(array('SwUpDn' => (int)$mode));
        }

        public function set_fan_directionh($mode)
        {
            return $this->set_options(array('SwingLfRig' => (int)$mode));
        }

        public function set_light($mode)
        {
            return $this->set_options(array('Lig' => (int)((bool)$mode)));
        }

        public function set_health($mode)
        {
            return $this->set_options(array('Health' => (int)((bool)$mode)));
        }

        public function set_sleep($mode)
        {
            return $this->set_options(array('SwhSlp' => (int)((bool)$mode)));
        }

        public function set_energy_save($mode)
        {
            return $this->set_options(array('SvSt' => (int)((bool)$mode)));
        }

        public function set_turbo($mode)
        {
            return $this->set_options(array('Tur' => (int)((bool)$mode)));
        }

        public function set_air($mode)
        {
            return $this->set_options(array('Air' => (int)((bool)$mode)));
        }

        public function set_blow($mode)
        {
            return $this->set_options(array('Blo' => (int)((bool)$mode)));
        }

        public function set_stht($mode)
        {
            return $this->set_options(array('StHt' => (int)((bool)$mode)));
        }
    }
}
