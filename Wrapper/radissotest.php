<?php
/**
 * This file contains test for all request methods
 * for both, websites and data providers.
 */
require(dirname(__FILE__)."/classes/Radisso/Wrapper.php");

$method = $_GET["method"];

$radisso = new \Radisso\Wrapper;

switch($method){
    default:
        echo "nothing todo";
    break;

    case "createBaseConfigFile":
        $config = $radisso->createBaseConfigFile("path/to/your/privkey/yourappname.key", "path/to/your/pubkey/yourappname.pub");
        if ($config) {
            echo "Config written";
        } else {
            echo "Config could not be written";
        }
    break;
    
    case "onboardingRequest":
        $data = new stdClass();
        $data->name = "yourappname";
        $data->type = "website";
        $data->ApiUrl = "https://your-api-domain.de/with/path/";
        $data->PublicKeyUrl = "https://your-domain.de/yourappname.pub";
        $data->domains = ["your-domain1.de","your-domain2.de"];
        $data->personname = "Max Mustermann";
        $data->personmail = "max@mustermann.de";
        $data->personphone = "01234567890";
        $onboarding = $radisso->onboardingRequest($data);
        if ($onboarding) {
            echo "Onboarding requested".PHP_EOL.PHP_EOL;
        } else {
            echo "Config could proceed onboarding";
        }
    break;

    // Partners
    case "addDomains":
        echo $radisso->addDomains(["domain1.de","www.domain1.de"]);
    break;

    case "removeDomains":
        echo $radisso->removeDomains(["domain1.de","www.domain1.de"]);
    break;

    case "updatePerson":
        $person = new stdClass();
        $person->name = "Max Mustermann";
        $person->phone = "030217990473";
        $person->email = "text@urbanstudio.de";
        echo $radisso->updatePerson($person);
    break;

    case "updateEndpoint":
        echo $radisso->updateEndpoint("https://your-new-api-domain.de/your-api-uri/");
    break;

    case "updatePubKey":
        echo $radisso->updatePubKey("https://your-domain-to-cert.de/certs/yourappname.pub");
    break;
    // Only partners that are data providers
    case "listPush":
        // needs array of Users, each user as Object
        $users = json_decode(file_get_contents("demo-user.json"));
        echo $radisso->listPush($users);
    break;

    case "changePush":
        // needs array of Users, each user as Object
        $users = json_decode(file_get_contents("demo-user.json"));
        $users[0]->lastname = "Musterfrau";
        echo $radisso->changePush($users);
    break;

    case "pwPush":
        $userdata = new stdClass();
        $userdata->addressid = 10001;
        $userdata->pass = "new-password-for-user";
        echo $radisso->pwPush($userdata);
    break;

    case "deactivateUser":
        $addressid = 10000;
        echo $radisso->deactivateUser($addressid);
    break;
    // Only partners that are website partners
    case "killUserSession":
        $userdata = new stdClass();
        $userdata->addressid = 109177;
        echo $radisso->pwPush($addressid);
    break;

    case "findUser":
        $userdata = new stdClass();
        $userdata->addressid = NULL;
        $userdata->firstname = "";
        $userdata->lastname = "";
        $userdata->mail = "";
        $userdata->company = "studio";
        echo print_r($radisso->findUser($userdata),1);
    break;
}
