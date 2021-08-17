<?php

//defines

namespace Radisso{
    use \UA\DateTime as DateTime;
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
        }

        public static function userDataPush($data){
            if(is_object($data->user) && $data->user->addressid){
                // get user data by addressid
                // change user data and save
                // return bool true|false
            }else{
                return "no valid params";
            }
            return false;
        }

        public static function createUserSession($data){
            // do your magic
            // return result object
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
