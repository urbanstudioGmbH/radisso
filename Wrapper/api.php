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

$radisso = new \Radisso\Wrapper;
// echo the result is sending the encoded response
echo $radisso->processIncomingRequest($inputData);

