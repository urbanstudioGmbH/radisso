<?php

//defines

namespace Radisso{
    use \UA\DateTime as DateTime;
    /**
     * Wrapper class for Radisso Login Management
     * @version 0.2.1
     * @author Marian Feiler <mf@urbanstudio.de>
     */
    class Wrapper {
        protected static string $configFile = "var/certs/radisso.json"; // Path to your config file
        protected static ?object $cfg = NULL; // holds config from json
        protected static array $callbacks = []; // array of callbacks
        protected static string $uuid = ""; // own uuid saved in config

        private static string $radissoReceiveAESKeyEncrypted = ""; // dynamic AES Key will be set when request comes in 
        private static string $radissoSendAESKeyEncrypted = ""; // dynamic AES Key will be set when request or response is sent
        /**
         * Constructor function.
         * Reads config file defined in static::$configFile and write stdCass object in static::$cfg and partner uuid in static::$uuid;
         */
        function __construct($callbacks = null){
            if(!is_null($callbacks) && is_array($callbacks)){
                static::$callbacks = $callbacks;
            }
            if(file_exists(static::$configFile)){
                $cfgfile = file_get_contents(static::$configFile);
                if($cfgfile){
                    static::$cfg = json_decode($cfgfile);
                    static::$uuid = (static::$cfg->uuid ? static::$cfg->uuid : uniqid());
                }
            }else{
                static::$cfg = json_decode("{}");
                static::$uuid = (static::$cfg->uuid ? static::$cfg->uuid : uniqid());
            }
        }
        /**
         * Method buildLoginUrl
         *
         * @param string $mandant
         * @param ?string $origin
         * @param ?string $endpoint
         * @return void
         */
        public static function buildLoginUrl(string $mandant = "NONE", string $origin = null, string $endpoint = null){
            $instance = new static();
            $mandant = strtoupper($mandant);
            //mail("mf@urbanstudio.de","cfg",print_r($instance::$cfg,1));
            $noneep = ""; $url = "";
            foreach ($instance::$cfg->loginEndpoints as $i => $ep) {
                if ($ep->mandant == $mandant) {
                    $url = $ep->url;
                } elseif ($ep->mandant == "NONE") {
                    $noneep = $ep->url;
                }
            }
/*            if(isset($instance::$cfg->loginEndpoints->{$mandant})){
                $url = $instance::$cfg->loginEndpoints->{$mandant}->url;
            }else{
                $url = $instance::$cfg->loginEndpoints->NONE->url;
            }*/
            if(empty($url)) $url = $noneep;
            $noWWW = str_replace("www.","",$_SERVER["HTTP_HOST"]);
            if(substr($noWWW,0,4) == "dev."){
                $url = "https://dev.radisso.de/sso/";
            }
            if(!is_null($origin) && $origin){
                $url .= $instance::uaBase64encode($origin)."/";
            }else{
                $origin = "https://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
                //exit($origin);
                $url .= $instance::uaBase64encode($origin)."/";
            }
            if(!is_null($endpoint) && $endpoint){
                $url .= $instance::uaBase64encode($endpoint)."/";
            }
            return $url;
        }

        /**
         * Method doCallback
         * 
         * calls a predefined callback function
         *
         * @param string $callback
         * @param stdClass $data
         * @return mixed value
         */
        public function doCallback(string $callback, object $data){
            if(!isset(static::$callbacks[$callback])) return false;
            return call_user_func(static::$callbacks[$callback], $data);
        }
        /**
         * Undocumented function
         *
         * @return void
         */
        public static function init(){

        }
        /**
         * Yout need some information like API endpoints, the radisso pub key
         * and your private key to communicate with radisso. that radisso is
         * able to send you encrypted requests radisso needs your public key
         * 
         * This method helps you create a config file, saved as JSON
         * 
         * @param string $privkey
         * @param string $pubkey
         * @return void
         */
        public function createBaseConfigFile(string $privkey = "", string $pubkey = ""){
            if(!empty($privkey)){
                static::$cfg->localPrivateKey = base64_encode(file_get_contents($privkey));
            }else{
                static::$cfg->localPrivateKey = "";
            }
            if(!empty($pubkey)){
                static::$cfg->localPublicKeyFile = $pubkey;
                static::$cfg->localPublicKey = base64_encode(file_get_contents($pubkey));
            }else{
                static::$cfg->localPublicKeyFile = "";
                static::$cfg->localPublicKey = "";
            }
            static::$cfg->radissoApiEndpoint = "https://api.radisso.de/";
            static::$cfg->radissoOnboardingApiEndpoint = "https://api.radisso.de/onboarding/request/";
            static::$cfg->radissoPublicKeyFile = "https://api.radisso.de/radisso.pub";
            static::$cfg->radissoPublicKey = base64_encode(file_get_contents(static::$cfg->radissoPublicKeyFile));
            return $this->saveConfig();
        }
        /**
         * This method will save your config file in the path to file you give in this class static::$configFile
         *
         * @return bool
         */
        public function saveConfig() : bool{
            $jsondata = json_encode(static::$cfg, JSON_PRETTY_PRINT);
            return file_put_contents(static::$configFile, $jsondata);
        }
        /**
         * creates a radisso onboarding request
         *  
         * @return bool
         *  
         */
        /* Request Methods for websites and data providers --- begin ---*/
        public function onboardingRequest($data = null) {
            $payload = new \stdClass();
            $payload->jsonrpc = "2.0";
            $payload->id = static::$uuid;
            $payload->method = "onboarding.requestOnboarding";
            $payload->params = new \stdClass();
            $payload->params->appname = $data->name;
            $payload->params->type = $data->type;
            $payload->params->person = new \stdClass();
            $payload->params->person->name = $data->personname;
            $payload->params->person->email = $data->personmail;
            $payload->params->person->phone = $data->personphone;
            $payload->params->api = new \stdClass();
            $payload->params->api->endPoint = $data->ApiUrl;
            $payload->params->api->pubKeyDl = $data->PublicKeyUrl;
            $payload->params->domains = $data->domains;
            $sent = $this->sendRequest(json_encode($payload), static::$cfg->radissoOnboardingApiEndpoint);
            if($sent === false){
                return false;
            }elseif(isset($sent->error)){
                return false;
            }elseif(isset($sent->result) && $sent->result == "OK"){
                // Save uuid for later use!!!
                // uuid will be in $sent->id;
                static::$cfg->uuid = $sent->id;
                $this->saveConfig();
                return true;
            }
        }

        public function addDomains(array $domains = []){
            if(!count($domains)) return false;
            $params = new \stdClass();
            $params->domains = $domains;
            return $this->SendRequestToRadisso("partner.addDomains", $params);
        }
        public function removeDomains(array $domains = []){
            if(!count($domains)) return false;
            $params = new \stdClass();
            $params->domains = $domains;
            return $this->SendRequestToRadisso("partner.removeDomains", $params);
        }
        public function updatePerson(\stdClass $person){
            $params = new \stdClass();
            $params->person = $person;
            return $this->SendRequestToRadisso("partner.updatePerson", $params);
        }
        public function updateEndpoint(string $endPoint = ""){
            if(empty($endPoint)) return false;
            $params = new \stdClass();
            $params->endPoint = $endPoint;
            return $this->SendRequestToRadisso("partner.updateEndpoint", $params);
        }
        public function updatePubKey(string $pubKeyDl = ""){
            if(empty($pubKeyDl)) return false;
            if(!file_get_contents($pubKeyDl)) return false;
            $params = new \stdClass();
            $params->pubKeyDl = $pubKeyDl;
            return $this->SendRequestToRadisso("partner.updatePubKey", $params);
        }
        /* Request Methods for websites and data providers --- end ---*/
        /* Request Methods data providers only --- begin ---*/
        public function listPush(array $users = []){ // array of user stdClass objects
            if(!count($users)) return false;
            $params = new \stdClass();
            $params->users = $users;
            return $this->SendRequestToRadisso("data.listPush", $params);
        }

        public function changePush(array $users = []){ // array of user stdClass objects
            if(!count($users)) return false;
            $params = new \stdClass();
            $params->users = $users;
            return $this->SendRequestToRadisso("data.changePush", $params);
        }

        public function pwPush(\stdClass $userdata){ // stdClass object
            $params = new \stdClass();
            $params->addressid = $userdata->addressid;
            $params->pass = $userdata->pass;
            return $this->SendRequestToRadisso("data.pwPush", $params);
        }

        public function deactivateUser(int $addressid){ // 
            $params = new \stdClass();
            $params->addressid = $addressid;
            return $this->SendRequestToRadisso("data.deactivateUser", $params);
        }
        /* Request Methods data providers only --- end ---*/
        /* Request Methods Website only --- begin ---*/
        public function killUserSession(int $addressid){ // stdClass object
            $params = new \stdClass();
            $params->addressid = $addressid;
            return $this->SendRequestToRadisso("website.killUserSession", $params);
        }
        public function findUser(\stdClass $userdata){ // stdClass object
            $params = new \stdClass();
            $params = $userdata;
            return $this->SendRequestToRadisso("website.findUser", $params);
        }
        /* Request Methods Website only --- end ---*/
        public static function userHash(\stdClass $userdata){
            $params = new \stdClass();
            $params = $userdata;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.userHash", $params);
            //mail("mf@urbanstudio.de", "Wrappertest", print_r($params,1)." - ".print_r($result,1));
            if (isset($result->hash) && $result->hash) {
                return $result->hash;
            }
            return NULL;
        }
        public static function searchUser(\stdClass $userdata){
            $params = new \stdClass();
            $params = $userdata;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.findUser", $params);
            if (isset($result->users) && is_array($result->users)) {
                return $result->users;
            }
            return NULL;
        }

        public static function checkUser(\stdClass $userdata){
            $params = new \stdClass();
            $params = $userdata;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.checkUser", $params);
            if (isset($result->isUser)) {
                //mail("mf@urbanstudio.de", "User Check", print_r($result,1));
                return $result;
            }
            return NULL;
        }

        public static function userResetHash(\stdClass $userdata){
            $params = new \stdClass();
            $params = $userdata;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.userResetHash", $params);
            //mail("mf@urbanstudio.de", "User Check", print_r($result,1));
            if (isset($result->isUser)) {
                //
                return $result;
            }
            return NULL;
        }

        public static function getPersonCertifications(bool $force = true){ // array of user stdClass objects
            $params = new \stdClass();
            $params->force = $force;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.getPersonCertifications", $params);
            //return $result;
            if (isset($result->persons) && is_array($result->persons)) {
                return $result->persons;
            }
            return NULL;
        }

        public static function getPersonCertificationTypes(bool $force = true){ // array of user stdClass objects
            $params = new \stdClass();
            $params->force = $force;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.getPersonCertificationTypes", $params);
            if (isset($result->types) && is_array($result->types)) {
                return $result->types;
            }
            return $result;
        }

        public static function getClientPanel(bool $force = true){ // array of user stdClass objects
            $params = new \stdClass();
            $params->force = $force;
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.getClientsPanel", $params);
            //return $result;
            if (isset($result->data) && is_array($result->data)) {
                return $result->data;
            }
            return $result;
        }

        public static function getUserList(){ // array of user stdClass objects
            $params = new \stdClass();
            $instance = new static();
            $result = $instance->SendRequestToRadisso("website.userList", $params);
            //return $result;
            if (isset($result->users) && is_array($result->users)) {
                return $result->users;
            }
            return $result;
        }
        /**
         * Method processIncomingRequest
         * 
         * handles all incoming requests
         *
         * @param mixed $input
         * @return void
         */
        public function processIncomingRequest($input = null){
            if(isset($_SERVER["HTTP_X_UA_BEARER"]) && !empty($_SERVER["HTTP_X_UA_BEARER"])){
                static::$radissoReceiveAESKeyEncrypted = $_SERVER["HTTP_X_UA_BEARER"];
            }
            $input = $this->aesDecryption($input);
            //mail("mf@urbanstudio.de", "incomin request PANDA", print_r($input,1));
            if (!$input || !$input->method || is_null($input)) {
                $this->sendErrorResponse(false, uniqid(), ["code" => 404, "message" => "method not found", "input" => $input]);
            }
            if($input->id != static::$uuid){
                $this->sendErrorResponse(false, uniqid(), ["code" => 403, "message" => "not allowed"]);
            }
            switch($input->method){
                default:
                    $this->sendErrorResponse(false, uniqid(), ["code" => 404, "message" => "method not allowed"]);
                break;

                case (substr($input->method, 0, 7) == "radisso"):
                    if($input->method == "radisso.onboardingVerification"){
                        $params = $input->params;
                        /**
                         * Save data from Params for later use
                         * - you will need $input->id for every request
                         * - other data direct from $input->params
                         * - wenn dies ein gültiger Request ist mit erfolgs-response anworten
                         */
                        // MAYBE YOU CHECK THE DATA
                        if($params->state == "verified"){
                            static::$cfg->radissoPublicKeyFile = $params->api->pubKeyDl;
                            static::$cfg->radissoPublicKey = base64_encode(file_get_contents($params->api->pubKeyDl));
                            static::$cfg->radissoApiEndpoint = $params->api->endPoint;
                            static::$cfg->loginEndpoints = $params->loginEndpoints;
                            $this->saveConfig();
                            $this->sendResponse(true, static::$uuid);
                        }elseif($params->state == "declined"){
                            $this->sendResponse(true, static::$uuid);
                        }else{
                            $this->sendErrorResponse(true, static::$uuid, ["code" => 500, "message" => "not enough data for this method"]);
                        }
                    }
                break;

                case (substr($input->method, 0, 7) == "website"):
                    $method = substr($input->method,8);
                    if($input->id != static::$uuid){
                        $this->sendErrorResponse(true, $input->id, ["code" => 403, "message" => "partner not allowed"]);
                    }else{
                        $process = $this->processWebsiteRequest($method, $input->params);
                        if ($method != "createUserSession") {
                            if ($process == true) {
                                $this->sendResponse(true, static::$uuid);
                            } else {
                                if (is_string($process)) {
                                    if ($process == "not allowed") {
                                        $this->sendErrorResponse(true, static::$uuid, ["code" => 401, "message" => "method not allowed"]);
                                    } else {
                                        $this->sendErrorResponse(true, static::$uuid, ["code" => 500, "message" => "$process"]);
                                    }
                                } else {
                                    $this->sendErrorResponse(true, static::$uuid, ["code" => 500, "message" => "data could not been saved"]);
                                }
                            }
                        }
                    }
                break;

                case (substr($input->method, 0, 4) == "data"):
                    $method = substr($input->method,5);
                    if($input->id != static::$uuid){
                        $this->sendErrorResponse(false, $input->id, ["code" => 403, "message" => "partner not allowed"]);
                    }else{
                        $process = $this->processDataRequest($method, $input->params);
                        if($process == true){
                            $this->sendResponse(true, $input->id);
                        }else{
                            if(is_string($process)){
                                if($process == "not allowed"){
                                    $this->sendErrorResponse(true, $input->id, ["code" => 401, "message" => "method not allowed"]);
                                }else{
                                    $this->sendErrorResponse(true, $input->id, ["code" => 500, "message" => "$process"]);
                                }
                            }else{
                                $this->sendErrorResponse(true, $input->id, ["code" => 500, "message" => "data could not been saved"]);
                            }
                        }
                    }
                break;
            }
        }
        /**
         * Method to process requests on data providers from radisso API
         * 
         * @method mixed processDataRequest()
         * 
         * @param string $method
         * @param stdClass $data
         * @return void
         */
        public function processDataRequest(string $method, $data){
            if(!ctype_alnum($method)) return false;
            switch($method){
                case "requestUserListPush":
                case "pwPush":    
                    // $data is empty.
                    // your code here that you may send data.userListPush request to radisso
                    return $this->doCallback($method, $data);
                break;

                default:
                    return "not allowed";
                break;
            }
        }
        /**
         * Method to process requests on partner websites from radisso API
         * 
         * @method mixed processWebsiteRequest()
         * 
         * @param string $method
         * @param stdClass $data
         * @return void
         */
        public function processWebsiteRequest(string $method, $data){
            if(!ctype_alnum($method)) return false;
            switch($method){
                case "userDataPush":
                    $result = $this->doCallback("createUserSession", $data);
                    if(is_object($result)){
                        $this->sendResponse(true, static::$uuid, $result);
                    }else{
                        return $result;
                    }
                break;

                case "killUserSession":
                    return $this->doCallback($method, $data);
                break;

                case "createUserSession":
                    //mail("mf@urbanstudio.de", "wrapper createUserSession", (print_r($data,1)));
                    //if((!isset($data->originUrl) || empty($data->originUrl)) || (!isset($data->user) || !$data->user->addressid)){
                    if((!isset($data->originUrl) || empty($data->originUrl)) || (!isset($data->user))){
                        return $this->sendErrorResponse(false, static::$uuid, ["code" => 500, "message" => "data could not been saved"]);
                    }
                    $result = $this->doCallback("createUserSession", $data);
                    if(is_object($result)){
                        $this->sendResponse(true, static::$uuid, $result);
                    }else{
                        return $result;
                    }
                break;
              
                default:
                    return "not allowed";
                break;
            }
        }
        // helper functions
        

        /**
         * Helper function for creating and sending request.
         * 
         * Method received params as object (stdClass) and will encode and send to radisso
         *
         * @param String $method
         * @param object $params
         * @return void
         */
        public function SendRequestToRadisso(String $method = null, object $params = null) {
            if(is_null($method)) return false;
            if(is_null($params)) $params = new \stdClass();
            $pl = new \stdClass();
            $pl->jsonrpc = "2.0";
            $pl->method = "$method";
            $pl->params = $params;
            $pl->id = static::$uuid;
            //exit(print_r($pl,1));
            $encData = $this->aesEncryption($pl);
            //mail("mf@urbanstudio.de", "radisso encryption failure", (print_r(static::$radissoSendAESKeyEncrypted,1)." - ".print_r($pl,1)." - ".$encData));
            //$encData = json_encode($pl);
            if($encData !== false){
                $sent = $this->sendRequest($encData);
                //$sent = $this->sendRequest($pl);
                //mail("mf@urbanstudio.de", "Check rec", "Method: ".print_r($sent,1));
                if(is_object($sent)){
                    if(isset($sent->error)){
                        return false;
                    }elseif(isset($sent->result) && $sent->result == "OK" && $method != "radisso.onboardingVerification"){
                        return true;
                    }elseif(isset($sent->result) && isset($sent->result->state) && $method == "radisso.onboardingVerification"){
                        return $sent->result;
                    }elseif(isset($sent->result) && isset($sent->result->users) && $method == "website.findUser"){
                        return $sent->result;
                    }elseif(isset($sent->result) && isset($sent->result->users) && $method == "website.searchUser"){
                        return $sent->result;
                    }elseif(isset($sent->result) && isset($sent->result->hash) && $method == "website.userHash"){
                        return $sent->result;
                    }elseif(isset($sent->result) && isset($sent->result->types) && $method == "website.getPersonCertificationTypes"){
                        return $sent->result;
                    }elseif(isset($sent->result) && isset($sent->result->persons) && $method == "website.getPersonCertifications"){
                        return $sent->result;
                    }elseif(isset($sent->result) && $method == "website.checkUser"){
                        return $sent->result;
                    }elseif(isset($sent->result) && $method == "partner.addDomains"){
                        return $sent->result;
                    }elseif(isset($sent->result)){
                        return $sent->result;
                    }
                }
            }
            return false;
        }

        public function aesEncryption($data){
            $json = json_encode($data);
            $key =  openssl_random_pseudo_bytes(32);
            static::$radissoSendAESKeyEncrypted = $this->publicKeyEncrypt($key);
            $cipher = 'aes-256-gcm';
            $iv_len = openssl_cipher_iv_length($cipher);
            $tag_length = 16;
            $iv = openssl_random_pseudo_bytes($iv_len);
            $tag = ""; // will be filled by openssl_encrypt

            $ciphertext = openssl_encrypt($json, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag, "", $tag_length);
            $encrypted = base64_encode($iv.$ciphertext.$tag);
            //mail("mf@urbanstudio.de", "wrapper encryption failure", (print_r(static::$radissoSendAESKeyEncrypted,1)." - ".print_r($data,1)));
            return $encrypted;
        }

        public function aesDecryption($data){
            $key = $this->privateKeyDecrypt(static::$radissoReceiveAESKeyEncrypted);
            $key = base64_decode($key);
            $encrypted = base64_decode($data);
            $cipher = 'aes-256-gcm';
            $iv_len = openssl_cipher_iv_length($cipher);
            $tag_length = 16;
            $iv = substr($encrypted, 0, $iv_len);
            $ciphertext = substr($encrypted, $iv_len, -$tag_length);
            $tag = substr($encrypted, -$tag_length);
            $decrypted = openssl_decrypt($ciphertext, $cipher, $key, OPENSSL_RAW_DATA, $iv, $tag);
            //mail("mf@urbanstudio.de", "wrapper decryption failure", (print_r(static::$radissoReceiveAESKeyEncrypted,1)." - ".print_r($decrypted,1)));
            return json_decode($decrypted);
        }
        /**
         * Encrypt function for global use, all requests and responses will be encrypted
         *
         * @param object $data
         * @return base64 encoded data string
         */
        public function publicKeyEncrypt($aesKey){
            $data = base64_encode($aesKey);
            $key = openssl_get_publickey(base64_decode(static::$cfg->radissoPublicKey));
            $maxlength=117; $result = ""; $chunks = [];
            while($data){
                $input = substr($data, 0, $maxlength);
                $data = substr($data, $maxlength);
                $state = openssl_public_encrypt($input, $encData, $key, OPENSSL_PKCS1_OAEP_PADDING);
                array_push($chunks, base64_encode($encData));
            }
            $result = implode("|||", $chunks);
            //mail("mf@urbanstudio.de", "wrapper encryption failure", (print_r(base64_encode($aesKey),1)." - ".print_r($result,1)));
            return base64_encode($result);
        }
        /**
         * Decrypt function for global use, all requests and responses need decryption
         *
         * @param string $encData
         * @return void
         */
        public function privateKeyDecrypt(string $encData){
            $key = openssl_get_privatekey(base64_decode(static::$cfg->localPrivateKey));
            $source = base64_decode($encData);
            $result = '';
            $chunks = explode("|||", $source);
            foreach($chunks AS $chunk){
                $state = openssl_private_decrypt(base64_decode($chunk), $data, $key, OPENSSL_PKCS1_OAEP_PADDING);
                $result .= $data;
            }
            //mail("mf@urbanstudio.de", "radisso decryption failure 2", (print_r(base64_encode($result),1)));

            return $result;
        }
        /**
         * Sends request to any given URL, standard Url is the radissoApiEndpoint
         * Payload is whether an encoded string or for the onboarding request a JSON
         * 
         * @param string $payload
         * @param string $url
         * @return void
         */
        public function sendRequest(string $payload, string $url = NULL){
            $apiendpoint = static::$cfg->radissoApiEndpoint;
            //mail("mf@urbanstudio.de", "apiendpoint", print_r(static::$cfg->radissoApiEndpoint,1));
            if (is_null($url)) {
                $ch = \curl_init(static::$cfg->radissoApiEndpoint);
            }else{
                $ch = \curl_init($url);
            }
            $headers = [];
            array_push($headers, "Content-Type: application/json; charset=utf-8");
            // When content is aes encrypted, the HEADER X-UA-BEARER must be sent.
            // It holds the RSA encrypted key for the payload
            if (!empty(static::$radissoSendAESKeyEncrypted)) {
                array_push($headers, "X-UA-BEARER: ".static::$radissoSendAESKeyEncrypted);
            }
            \curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // for performance reasons
            \curl_setopt( $ch, CURLOPT_POST, true);
            \curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
            \curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers);
            \curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false);
            \curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);
            # Return response instead of printing.
            \curl_setopt( $ch, CURLOPT_HEADER, 1);
            \curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            # Send request.
            $result = curl_exec($ch);
            $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $header = substr($result, 0, $header_size);
            $body = substr($result, $header_size);
            
            if($errno = \curl_errno($ch)) {
                $error_message = \curl_strerror($errno);
                $return = new \stdClass();
                $return->error = "cURL error ({$errno}):\n {$error_message}";
                return $return;
            }
            \curl_close($ch);
            $headers = $this->get_headers_from_curl_response($header);
            if(isset($headers["X-UA-BEARER"]) && !empty($headers["X-UA-BEARER"])){
                static::$radissoReceiveAESKeyEncrypted = trim($headers["X-UA-BEARER"]);
                $decrypted = $this->aesDecryption($body);
                if (is_string($decrypted) && json_decode($decrypted) != null) {
                    return json_decode($decrypted);
                }
                return $decrypted;
            }else{
                if (json_decode($result) != null) {
                    return json_decode($body);
                }
            }
            return $result;
        }
        /**
         * Method sendResponse
         * 
         * method sends successfull response w/wo result params
         *
         * @param boolean $encrypt
         * @param ?uuid $uuid
         * @param ?stdClass $result
         * @return void
         */
        public function sendResponse(bool $encrypt = false, $uuid = null, $result = null){
            if(is_null($result)) $result = "OK";
            $pl = new \stdClass();
            $pl->jsonrpc = "2.0";
            $pl->result = $result;
            $pl->id = static::$uuid;
            //mail("mf@urbanstudio.de", "success response PANDA", print_r($pl,1));
            if(!$encrypt){
                uaheader('Content-type: application/json');
                echo json_encode($pl);
            }else{
                uaheader('Content-type: text/plain');
                $payload = $this->aesEncryption($pl);
                //mail("mf@urbanstudio.de", "success response PANDA 2", print_r($payload,1));
                if (!empty(static::$radissoSendAESKeyEncrypted)) {
                    uaheader("X-UA-BEARER: ".static::$radissoSendAESKeyEncrypted);
                }
                echo $payload;
            }
            ua()->getDB()->commit();
        }
        /**
         * Method sendErrorResponse
         * 
         * method sends an error response to a recieved request
         *
         * @param boolean $encrypt
         * @param ?uuid $uuid
         * @param ?string $error
         * @return void
         */
        public function sendErrorResponse(bool $encrypt = false, $uuid = null, $error = null){
            if(is_null($error)) $error = ["code" => 500, "message" => "error not specified"];
            $pl = new \stdClass();
            $pl->jsonrpc = "2.0";
            $pl->error = $error;
            $pl->id = static::$uuid;
            //mail("mf@urbanstudio.de", "error response PANDA", print_r($pl,1));
            if(!$encrypt){
                uaHeader('Content-type: application/json');
                echo json_encode($pl);
            }else{
                uaHeader('Content-type: text/plain');
                $payload = $this->aesEncryption($pl);
                if (!empty(static::$radissoSendAESKeyEncrypted)) {
                    uaHeader("X-UA-BEARER: ".static::$radissoSendAESKeyEncrypted);
                }
                echo $payload;
            }
        }

        public function get_headers_from_curl_response($response_headers){
            $headers = [];
            foreach (explode("\r\n", $response_headers) AS $i => $line) {
                if ($i === 0) {
                    $headers['HTTP_CODE'] = $line;
                } else {
                    if(stristr($line, ":") === false) continue;
                    list($key, $value) = explode(': ', $line);
                    $headers[strtoupper($key)] = $value;
                }
            }
            return $headers;
        }

        public static function uaBase64encode(string $string){
            //$str = base64_encode($string);
            //return urlencode($str);
            return str_replace(["+","/","="],["-","_","."],base64_encode($string));
        }

        public static function uaBase64decode(string $string){
            //$str = urldecode($string);
            //return base64_decode($str);
            return base64_decode(str_replace(["-","_","."],["+","/","="],$string));
        }
    }
}
