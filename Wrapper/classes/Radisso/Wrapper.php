<?php

//defines

namespace Radisso{
    /**
     * Radisso Wrapper class
     */
    class Wrapper {
        
        protected static string $configFile = "radisso.json";
        protected static ?object $cfg = NULL;

        protected static string $uuid = "";
        /**
         * Constructor function.
         * Reads config file defined in static::$configFile and write stdCass object in static::$cfg and partner uuid in static::$uuid;
         */
        function __construct(){
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
         * Demo function check token and login user when user is redirected to website
         *
         * @param string $token
         * @param string $redirectUrl
         * @return void
         */
        public static function checkToken(string $token = NULL, string $redirectUrl = NULL){
            $time = new DateTime();
            /**
             * Check the token you've been given to radisso
            * Magic needed for individual rights of the user.
            * May the user is registered for an event or is member of mandant?
            * Do this before set the user as logged in in you system.
            */
            $_SESSION["UAuser"] = $user->id;
            $redirectUrl = base64_decode($redirectUrl)."/";
            uaRedirect($redirectUrl);
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
            $payload = new stdClass();
            $payload->jsonrpc = "2.0";
            $payload->id = static::$uuid;
            $payload->method = "onboarding.requestOnboarding";
            $payload->params = new stdClass();
            $payload->params->appname = $data->name;
            $payload->params->type = $data->type;
            $payload->params->person = new stdClass();
            $payload->params->person->name = $data->personname;
            $payload->params->person->email = $data->personmail;
            $payload->params->person->phone = $data->personphone;
            $payload->params->api = new stdClass();
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
                $sendid = explode(".", $sentid);
                static::$cfg->uuid = $sentid[0];
                $this->saveConfig();
                return true;
            }
        }

        public function addDomains(array $domains = []){
            if(!count($domains)) return false;
            $params = new stdClass();
            $params->domains = $domains;
            return $this->SendRequestToRadisso("partner.addDomains", $params);
        }
        public function removeDomains(array $domains = []){
            if(!count($domains)) return false;
            $params = new stdClass();
            $params->domains = $domains;
            return $this->SendRequestToRadisso("partner.removeDomains", $params);
        }
        public function updatePerson(stdClass $person){
            $params = new stdClass();
            $params->person = $person;
            return $this->SendRequestToRadisso("partner.updatePerson", $params);
        }
        public function updateEndpoint(string $endPoint = ""){
            if(empty($endPoint)) return false;
            $params = new stdClass();
            $params->endPoint = $endPoint;
            return $this->SendRequestToRadisso("partner.updateEndpoint", $params);
        }
        public function updatePubKey(string $pubKeyDl = ""){
            if(empty($pubKeyDl)) return false;
            if(!file_get_contents($pubKeyDl)) return false;
            $params = new stdClass();
            $params->pubKeyDl = $pubKeyDl;
            return $this->SendRequestToRadisso("partner.updatePubKey", $params);
        }
        /* Request Methods for websites and data providers --- end ---*/
        /* Request Methods data providers only --- begin ---*/
        public function listPush(array $users = []){ // array of user stdClass objects
            if(!count($users)) return false;
            $params = new stdClass();
            $params->users = $users;
            return $this->SendRequestToRadisso("data.listPush", $params);
        }

        public function changePush(array $users = []){ // array of user stdClass objects
            if(!count($users)) return false;
            $params = new stdClass();
            $params->users = $users;
            return $this->SendRequestToRadisso("data.changePush", $params);
        }

        public function pwPush(stdClass $userdata){ // stdClass object
            $params = new stdClass();
            $params->addressid = $userdata->addressid;
            $params->pass = $userdata->pass;
            return $this->SendRequestToRadisso("data.pwPush", $params);
        }

        public function deactivateUser(int $addressid){ // 
            $params = new stdClass();
            $params->addressid = $addressid;
            return $this->SendRequestToRadisso("data.deactivateUser", $params);
        }
        /* Request Methods data providers only --- end ---*/
        /* Request Methods Website only --- begin ---*/
        public function killUserSession(int $addressid){ // stdClass object
            $params = new stdClass();
            $params->addressid = $addressid;
            return $this->SendRequestToRadisso("website.killUserSession", $params);
        }
        public function findUser(stdClass $userdata){ // stdClass object
            $params = new stdClass();
            $params = $userdata;
            return $this->SendRequestToRadisso("website.findUser", $params);
        }
        /* Request Methods Website only --- end ---*/
        
        /**
         * Undocumented function
         *
         * @param mixed $input
         * @return void
         */
        public function processIncomingRequest($input = null){
            $input = $this->privateKeyDecrypt($input);

            if (!$input || !$input->method || is_null($input)) {
                $this->sendErrorResponse(true, uniqid(), ["code" => 404, "message" => "method not found", "input" => $input]);
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
                    if(!$partner->id || !$partner->active || !$partner->verified){
                        $this->sendErrorResponse(false, $input->id, ["code" => 403, "message" => "partner not allowed"]);
                    }else{
                        $process = $this->processDataRequest($method, $input->params);
                        if($process == true){
                            $this->sendResponse(false, $partner->uuid);
                        }else{
                            if(is_string($process)){
                                if($process == "not allowed"){
                                    $this->sendErrorResponse(false, $input->id, ["code" => 401, "message" => "method not allowed"]);
                                }else{
                                    $this->sendErrorResponse(false, $input->id, ["code" => 500, "message" => "$process"]);
                                }
                            }else{
                                $this->sendErrorResponse(false, $input->id, ["code" => 500, "message" => "data could not been saved"]);
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
                    // $data is empty.
                    // your code here that you may send data.userListPush request to radisso
                break;

                case "pwPush":
                    /**
                     * $data holds addressid and pass
                     * do your magic to update Password in your database
                     */
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
                    if(is_object($data->users)){
                        /**
                         * Do your magic to update your user objects
                         * $data->users is an array.
                         * identify user by addressid of user object
                         */
                    }else{
                        return "no valid params";
                    }
                break;

                case "createUserSession":
                    //if((!isset($data->originUrl) || empty($data->originUrl)) || (!isset($data->user) || !$data->user->addressid)){
                    if((!isset($data->originUrl) || empty($data->originUrl)) || (!isset($data->user))){
                        return $this->sendErrorResponse(false, $input->id, ["code" => 500, "message" => "data could not been saved"]);
                    }
                    /**
                     * - find or add user
                     * - create a backgroud session
                     * - create token for identify user when user is redirected
                     * - create redirect url (must include the token)
                     * - send response
                     */
                    //$user = User::getByMail($data->user->mail);
                    $logeedin = true; // bool true|false
                    $result = new stdClass();
                    $pu = parse_url(base64_decode($data->originUrl)); // always base64 encoded
                    $result->redirectUrl = "https://your-redirect-url.de/?token=your-token&redirectUrl=the-orrigin-url";
                    $result->login = true;
                    if(!$loggedin){
                        $result->message = "could not write Session";
                    }
                    $result->token = $token;
                    $this->sendResponse(true, static::$uuid, $result);
                break;

                case "killUserSession":
                    /**
                     * Do your magic to kill the background session
                     */
                    $return = true; // return bool true|false
                    return $return;
                break;
                
                default:
                    return "not allowed";
                break;
            }
        }
        // helper functions
        /**
         * Encrypt function for global use, all requests and responses will be encrypted
         *
         * @param object $data
         * @return base64 encoded data string
         */
        public function publicKeyEncrypt(object $data){
            $json = json_encode($data);
            $key = openssl_get_publickey(base64_decode(static::$cfg->radissoPublicKey));
            $maxlength=117; $result = ""; $chunks = [];
            while($json){
                $input = substr($json, 0, $maxlength);
                $json = substr($json, $maxlength);
                $state = openssl_public_encrypt($input, $encData, $key, OPENSSL_PKCS1_OAEP_PADDING);
                array_push($chunks, base64_encode($encData));
            }
            $result = implode("|||", $chunks);
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
            $result = ''; $chunks = explode("|||", $source);
            foreach($chunks AS $chunk){
                $state = openssl_private_decrypt(base64_decode($chunk), $data, $key, OPENSSL_PKCS1_OAEP_PADDING);
                $result .= $data;
            }
            return json_decode($result);
        }

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
            if(is_null($params)) $params = new stdClass();
            $pl = new stdClass();
            $pl->jsonrpc = "2.0";
            $pl->method = "$method";
            $pl->params = $params;
            $pl->id = static::$uuid;
            //exit(print_r($pl,1));
            $encData = $this->publicKeyEncrypt($pl, static::$cfg->radissoPublicKey);
            if($encData !== false){
                $sent = $this->sendRequest($encData);
                if(isset($sent->error)){
                    return false;
                }elseif(isset($sent->result) && $sent->result == "OK" && $method != "radisso.onboardingVerification"){
                    return true;
                }elseif(isset($sent->result) && isset($sent->result->state) && $method == "radisso.onboardingVerification"){
                    return $sent->result;
                }elseif(isset($sent->result) && isset($sent->result->users) && $method == "website.findUser"){
                    return $sent->result;
                }
            }
            return false;
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
            if (is_null($url)) {
                $ch = \curl_init(static::$cfg->radissoApiEndpoint);
            }else{
                $ch = \curl_init($url);
            }
            \curl_setopt( $ch, CURLOPT_POST, true);
            \curl_setopt( $ch, CURLOPT_POSTFIELDS, $payload );
            \curl_setopt( $ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json; charset=utf-8'));
            \curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false);
            \curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false);
            # Return response instead of printing.
            \curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
            # Send request.
            $result = curl_exec($ch);
            //$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if($errno = \curl_errno($ch)) {
                $error_message = \curl_strerror($errno);
                $return = new stdClass();
                $return->error = "cURL error ({$errno}):\n {$error_message}";
                return $return;
            }
            \curl_close($ch);
            if(json_decode($result) != NULL){
                return json_decode($result);
            }
            return $this->privateKeyDecrypt($result);
        }
        /**
         * Undocumented function
         *
         * @param boolean $encrypt
         * @param [type] $uuid
         * @param [type] $result
         * @return void
         */
        public function sendResponse(bool $encrypt = false, $uuid = null, $result = null){
            if(is_null($result)) $result = "OK";
            $pl = new stdClass();
            $pl->jsonrpc = "2.0";
            $pl->result = $result;
            $pl->id = static::$uuid;
            if(!$encrypt){
                header('Content-type: application/json');
                echo json_encode($pl);
            }else{
                header('Content-type: text/plain');
                echo $this->publicKeyEncrypt($pl);
            }
            die();
        }
        /**
         * Undocumented function
         *
         * @param boolean $encrypt
         * @param [type] $uuid
         * @param [type] $error
         * @return void
         */
        public function sendErrorResponse(bool $encrypt = false, $uuid = null, $error = null){
            if(is_null($error)) $error = ["code" => 500, "message" => "error not specified"];
            $pl = new stdClass();
            $pl->jsonrpc = "2.0";
            $pl->error = $error;
            $pl->id = static::$uuid;
            if(!$encrypt){
                header('Content-type: application/json');
                echo json_encode($pl);
            }else{
                header('Content-type: text/plain');
                echo $this->publicKeyEncrypt($pl);
            }
            die();
        }
        
    }
}