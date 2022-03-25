<?php

//defines

namespace Radisso{
    /**
     * Radisso client class
     */
    class Client {
        /**
         * Demo function check token and login user when user is redirected to website
         *
         * @param string $token
         * @param string $redirectUrl
         * @return void
         */
        public static function checkToken(string $token = NULL, string $redirectUrl = NULL){
            /**
            * Magic needed for individual rights of the user.
            * May the user is registered for an event or is member of mandant?
            * Do this before set the user as logged in in you system.
            */
            
            // redirectUrl is uaBase64encoded (urlencoded base64 string)
            // $redirectUrl = \Radisso\Wrapper::uaBase64decode($redirectUrl);
            
            // check the token and login the user to your system.
            // at this point you know where the user is and may check if user has permission
            // if not redirect him to an error page and do not log them in
            // but if user has permission for the site log them in and redirect (307) them to $redirectUrl
        }

        public static function userDataPush($data){
            if(is_array($data->users)){
                // get user data by addressid
                // change user data and save
                // return bool true|false
                foreach($data->users AS $user){
                    // check if users exists
                    // if exists user save new user data
                }
                return true;
            }else{
                return "no valid params";
            }
            return false;
        }

        public static function createUserSession($data){
            // do your magic
            // return result object
            if(is_object($data->user) && $data->user->addressid){
                // check wether user exists by addressid or create
                // save user data of new or exoisting user (with new data)
                // create a token to check (we use a uuid saved in a table just for login checking.
                $result = new \stdClass();
                // You are able to set the url where we redirect the user different with each request
                // In our websites we always use the desired domain the user comes from. 
                // the pathpart "radisso" point to radisso.php which takes the variables from url and calls Client::checkToken($token, $url);
                $result->redirectUrl = "https://your-endpoint-domain.com/radisso/$token/$data->originUrl/";
                // OR
                $result->redirectUrl = "https://your-endpoint-domain.com/radisso.php?token=$token&url=$data->originUrl/";
                $result->login = true; // bool true|false
                if(!$loggedin){
                    $result->message = "could not write Session";
                }
                $result->token = $token; // the token you creates and saved
                return $result;
            }else{
                return "no valid params";
            }
            return false;
        }

        public static function killUserSession($data){
            // return true or false
        }

        // data requests
        public static function requestUserListPush($data){
            /**
             * $data is empty.
             * your code here that you may send data.userListPush request to radisso
             * 
             */
            return true;
        }

        public static function pwPush($data){
            /**
             * $data holds addressid and pass
             * do your magic to update Password in your database
             */
            return true;
        }
    }
}
