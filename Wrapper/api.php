<?php
/**
 * This file handles all incoming requests.
 * See processIncomingRequest mthod in Wrapper.
 * Some methods need your attention, Wrapper is
 * only a demonstration, not a working class!
 */
require(dirname(__FILE__)."/classes/Radisso/Wrapper.php");
/**
 * All incoming requests will be encoded.
 * Get the POST Body with:
 */
$inputData = file_get_contents('php://input');
/**
 * Define some Callbacks for Incoming requests.
 * Key is the request type
 * Value = String for functions, array for static methods in a class
 * All your callback methods will receive stdClass $data
 */
$radissoCallbacks = [
    "userDataPush"          => ["\Radisso\Client", "userDataPush"],
    "createUserSession"     => ["\Radisso\Client", "createUserSession"],
    "requestUserListPush"   => ["\Radisso\Client", "requestUserListPush"],
    "pwPush"                => ["\Radisso\Client", "pwPush"]
];

$radisso = new \Radisso\Wrapper;
// echo the result is sending the encoded response
echo $radisso->processIncomingRequest($inputData);

