# radisso
radisso is an SSO (Single-Sign-On) eco system for the radiology society of the DRG and associated societies in Germany

## Prerequisites
There are few things you need to take care of before onboarding:
1. make sure your API-Endpoint is secured by an SSL-Certificate
2. create a PrivKey/PubKey Pair (RSA, 4096bit, see below)
3. to receive API-Requests send your onboarding request to service@urbanstudio.de
  - an APP Name (max 20 characters)
  - including your public key (named as {APPNAME}.pub) - do not send your private key!
  - API endpoint of your staging environment (incl. port no)
  - API endpoint of your production environment (incl. port no.)

Add a content type header for each request. The content type is always "application/json".

### workflow model

![Modell](https://user-images.githubusercontent.com/12560807/126470615-df5bfdb9-8b5c-4c40-adf5-f9d5c40a065a.jpg)


### Login workflow

#### User is NOT loggedin in radisso

1. User clicks sso login button on website
2. User gets redirected to radisso, the url includes the originUrl for later redirection
3. User gives credentials for login
4. radisso sends API request to website
5. website answers with login true|false and the redirection URL
6. if login is true, user gets redirected
7. if login is false, user gets message and then redirected

#### User is loggedin in radisso

1. User clicks sso login button on website
2. User gets redirected to radisso, the url includes the originUrl for later redirection
3. radisso sends API request to website
4. website answers with login true|false and the redirection URL
5. if login is true, user gets redirected
6. if login is false, user gets message and then redirected

#### User is loggedin in radisso, website forces SSO

1. User enters a protected page in a website with parameter forceSSO=1
2. User gets directly redirected to radisso, the url includes the originUrl for later redirection
3. radisso sends API request to website
4. website answers with login true|false and the redirection URL
5. if login is true, user gets redirected
6. if login is false, user gets message and then redirected

On Login request redirect user to radisso:

https://[login-enpoint]/sso/[special-encoded-origin]/[optional-special-encoded-apienpoint]

Login-Endpoints will be sensitive by MANDANT (But there is one without MANDANT).

You'll receive all available MANDANT login endpoints uppon verification in onboarding process.


## 1 Key pair generation

Generate your RSA key pair using ```openssl```

```
APPNAME=[yourappname]
# We require 4096 bit keys for production environment
LEN="4096"

openssl genrsa -out "${APPNAME}.key" ${LEN};
openssl rsa -in "${APPNAME}.key" -pubout -out "${APPNAME}.pub";
```

## 2 Onboarding API

All data send through the API will be strongly encrypted, except the first onboarding.
This is asyncronous, so you will have to implement an API method that we can call for you to be able to receive your answer.

### Onboarding

The API endpoint for the ```onboarding``` is ```https://api.radisso.de/onboarding/request/```

1. Request onboarding

The "```appname```" must be unique, you will need this later.
```type``` may have value "```website```" or "```data```", where "```website```" stands for website that provides login and "```data```" stands for data provider

#### Send following request for onboarding
```
{
    "method": "onboarding.requestOnboarding", 
    "id": "1", 
    "params": {
        "appname" : "YOUR-APP-NAME",
        "type"    : "website",
        "person"  : {
            "name"     : "Max Mustermann",
            "email"    : "max@mustermann.de",
            "phone"    : "0123456789012"
        },
        "api" : {
            "endPoint"  : "https://[your-api-domain]:[port]/[path]",
            "pubKeyDl"  : "https://[your-domain]/[appname].pub"
        },
        "domains" : [
            "domain1.de",
            "domain2.de",
            "domain3.de"
        ]
    }, 
    "jsonrpc": "2.0"
}
```
You will receive a response like
```
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
#### After a review you will receive a request

The request will be created on the API endpoint you provided in your onboarding request.

The complete request body will be encrypted. For processing you need to decrypt it using your private key.

##### If your request has been verified

"```loginEnpoints```" will only be deliverd if "```type```" is "```website```" .

You need to save the id locally, as all other API requests need this your personal uuid as id
```
{
    "method": "radisso.onboardingVerification", 
    "id": "[partner-uuid]", 
    "params": {
        "appname" : "YOUR-APP-NAME",
        "type"    : "website",
        "state"    : "verified",
        "api" : {
            "endPoint"  : "https://[our-api-domain]:[port]/[path]",
            "pubKeyDl"  : "https://[our-api-domain]/radisso.pub"
        },
        "loginEnpoints" : [
            {
                "mandant"   : "DRG",
                "url"       : "https://radisso.de/drg/"
            },
            {
                "mandant"   : "NONE",
                "url"       : "https://radisso.de/login"
            }
        ]
    }, 
    "jsonrpc": "2.0"
}
```
##### If your request has been declined
```
{
    "method": "radisso.onboardingVerification", 
    "id": "[partner-uuid]", 
    "params": {
        "appname" : "YOUR-APP-NAME",
        "type"    : "website",
        "state"   : "declined"
    }, 
    "jsonrpc": "2.0"
}
```
### Other methods

The API endpoint for the following methods is ```https://api.radisso.de/```

_Since you are verified, you must encrypt the request body with **our** PubKey that has been provided to you in the verification request._

#### onboarding.removeDomains

(Don't forget to send your ```uuid```as id
```
{
    "method": "partner.removeDomains", 
    "id": "[partner-uuid]", 
    "params": {
        "domains": [
            "domain1.de"
        ]
    },
    "jsonrpc": "2.0"
}
```

#### onboarding.addDomains

(Don't forget to send your ```uuid```as id
```
{
    "method": "partner.addDomains", 
    "id": "[partner-uuid]", 
    "params": {
        "domains": [
            "domain1.de"
        ]
    },
    "jsonrpc": "2.0"
}
```
#### onboarding.updatePerson

(Don't forget to send your ```uuid```as id
```
{
    "method": "partner.updatePerson", 
    "id": "[partner-uuid]", 
    "params": {
        "person"  : {
            "name"     : "Max Mustermann",
            "email"    : "max@mustermann.de",
            "phone"    : "0123456789012"
        }
    },
    "jsonrpc": "2.0"
}
```
#### onboarding.updateEndpoint

(Don't forget to send your ```uuid```as id
```
{
    "method": "partner.updateEndpoint", 
    "id": "[partner-uuid]", 
    "params": {
        "endPoint"     : "https://[your-api-domain]:[port]/[path]",
    },
    "jsonrpc": "2.0"
}
```
#### onboarding.updatePubKey

(Don't forget to send your ```uuid```as id
```
{
    "method": "partner.updatePubKey", 
    "id": "[partner-uuid]", 
    "params": {
        "pubKeyDl"     : "https://[your-api-domain]/[appname].pub",
    },
    "jsonrpc": "2.0"
}
```
## 3 Data provider APIs

This section is only for verified data providers!

### User list push

```
### Request:
{
    "method": "data.listPush", 
    "id": "[partner-uuid]",
    "params": {
        "users": [
            {
                "addressid"   : 10000,
                "mail"        : "max@mustermann.de",
                "pass"        : "passwort",
                "sex"         : "F",
                "salutation"  : "Frau",
                "title"       : "Dr.",
                "firstname"   : "Max",
                "lastname"    : "Mustermann",
                "birthdate"   : "1977-12-03",
                "company"     : "urbanstudio GmbH",
                "department"  : "Programmierung",
                "streetnr"    : "Musterstraße 10",
                "zip"         : "12345",
                "city"        : "Harzgerode",
                "country"     : "DE",
                "phone"       : "012345678910",
                "memberof"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 836,
                        "in"      : "2015-01-01",
                        "out"     : "2018-12-31",
                        "active"  : 0
                    }
                ],
                "agmemberof"    : [
                    {
                        "name"    : "AG Physik und Technik in der bildgebende",
                        "id"      : 4
                    },
                    {
                        "name"    : "FFZ",
                        "id"      : 17
                    }
                ],
                "memberships"    : [
                    {
                        "mandant"    : "DRG",
                        "mandantId"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "panel"     : [
                              {
                                  "name"    : "Forum Junge Radiologie",
                                  "area"    : "Forum",
                                  "gremiumId"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "function" : "Mitglied",
                                  "function_in" :  "2012-01-01",
                                  "function_out" : "",
                                  "active"  : 1
                              },
                              {
                                  "name"    : "Forum Junge Radiologie",
                                  "area"    : "Forum",
                                  "gremiumId"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-12-31",
                                  "function" : "Mitglied",
                                  "function_in" :  "2012-01-01",
                                  "function_out" : ""
                                  "active"  : 0
                            
                              }
                        ]
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 948,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "DGMP AG1",
                                  "number"  : 1,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "DGMP AG2",
                                  "number"  : 2,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-05-31",
                                  "active"  : 0
                            
                              }
                        ]
                    }
                ],
                "participatingevents"    : [
                    "2021RD",
                    "2022RD"
                ],
                "participatingconrad"    : [
                    "BASIC",
                    "CONRAD-RD2"
                ]
            }
        ]
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### User change push

Send only users with changes!

```
### Request:
{
    "method": "data.changePush", 
    "id": "[partner-uuid]",
    "params": {
        "users": [
            {
                "addressid"   : 10000,
                "mail"        : "max@mustermann.de",
                "pass"        : "passwort",
                "sex"         : "F",
                "salutation"  : "Frau",
                "title"       : "Dr.",
                "firstname"   : "Max",
                "lastname"    : "Mustermann",
                "birthdate"   : "1977-12-03",
                "company"     : "urbanstudio GmbH",
                "department"  : "Programmierung",
                "streetnr"    : "Musterstraße 10",
                "zip"         : "12345",
                "city"        : "Harzgerode",
                "country"     : "DE",
                "phone"       : "012345678910",
                "memberof"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 836,
                        "in"      : "2015-01-01",
                        "out"     : "2018-12-31",
                        "active"  : 0
                    }
                ],
                "agmemberof"    : [
                    {
                        "name"    : "AG Physik und Technik in der bildgebende",
                        "id"      : 4
                    },
                    {
                        "name"    : "FFZ",
                        "id"      : 17
                    }
                ],
                "memberships"    : [
                    {
                        "mandant"    : "DRG",
                        "mandantId"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "panel"     : [
                              {
                                  "name"    : "Forum Junge Radiologie",
                                  "area"    : "Forum",
                                  "gremiumId"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "function" : "Mitglied",
                                  "function_in" :  "2012-01-01",
                                  "function_out" : "",
                                  "active"  : 1
                              },
                              {
                                  "name"    : "Forum Junge Radiologie",
                                  "area"    : "Forum",
                                  "gremiumId"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-12-31",
                                  "function" : "Mitglied",
                                  "function_in" :  "2012-01-01",
                                  "function_out" : ""
                                  "active"  : 0
                            
                              }
                        ]
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 948,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "DGMP AG1",
                                  "number"  : 1,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "DGMP AG2",
                                  "number"  : 2,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-05-31",
                                  "active"  : 0
                            
                              }
                        ]
                    }
                ],
                "participatingevents"    : [
                    "2021RD",
                    "2022RD"
                ],
                "participatingconrad"    : [
                    "BASIC",
                    "CONRAD-RD2"
                ]
            }
        ]
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### Person certificates list push

```
### Request:
{
    "method": "data.listPushPersonCertificates", 
    "id": "[partner-uuid]",
    "params": {
        "types": [
            {
                "vewaid"  : 1,
                "name"  : "AG Herz Personen",
                "center" : false
            },
            {
                "vewaid"  : 2,
                "name"  : "AG BVB Personen",
                "center" : false
            },
            {
                "vewaid"  : 3,
                "name"  : "AG Uro",
                "center" : false
            },
            {
                "vewaid"  : 3,
                "name"  : "DeGIR Zentrum",
                "center" : true
            }

        ],
        "persons": [
            {
                "addressid"   : 10000,
                "sex"         : "F",
                "salutation"  : "Frau",
                "title"       : "Dr.",
                "firstname"   : "Gabriele",
                "lastname"    : "Mustermann",
                "company"     : "urbanstudio GmbH",
                "department"  : "Programmierung",
                "streetnr"    : "Musterstraße 10",
                "zip"         : "12345",
                "city"        : "Harzgerode",
                "country"     : "DE",
                "phone"       : "012345678910",
                "mail"        : "gabriele@mustermann.de",
                "certs"       : [
                    {
                        "type"         : 1,
                        "modules"      : ["CT","MRT"],
                        "level"        : 1
                        "status"       : "ausgestellt",
                        "date"         : "2023-02-28",
                        "public"       : 0
                    },
                    {
                        "type"         : 2,
                        "modules"      : ["A","B","C","D","E"],
                        "level"        : 2
                        "status"       : "ausgestellt",
                        "date"         : "2023-02-28",
                        "public"       : 1
                    }
                ]
            }
        ]
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### User password change push

Send only one users password change!

```
### Request:
{
    "method": "data.pwPush", 
    "id": "[partner-uuid]",
    "params": {
        "addressid"   : 10000,
        "pass"        : "passwort"
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### deactivate user push

Users that are not active in the system of data provider will not send through user push, radisso deactivate users that are not sent but sent before!
If a user must not login urgently, use this method.
```
### Request:
{
    "method": "data.deactivateUser", 
    "id": "[partner-uuid]",
    "params": {
        "addressid"   : 10000
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### Client and Panel liszPush

Sends all Mandants including panels for each Mandant
```
### Request:
{
    "method": "data.clientPanel", 
    "id": "[partner-uuid]",
    "params": {
        "panel" : [
            {
                "id"       : 1,
                "name"     : "Deutsche Röntgengesellschaft e.V.",
                "short"    : "DRG",
                "active"   : true,
                "panel"    : [
                    {
                        "id"      : 101,
                        "name"    : "Arbeitsgemeinschaft URO in der DRG",
                        "short"   : "AG Uro",
                        "public"  : true,
                        "active"  : true
                    },
                    {
                        "id"      : 102,
                        "name"    : "AG Herzu und Gefäße",
                        "short"   : "AG Herz",
                        "public"  : true,
                        "active"  : true
                    }
                ]
            },
            {
                "id"       : 2,
                "name"     : "Deutsche Gesellschaft für Medizinische Physik e.V.",
                "short"    : "DGMP",
                "active"   : true,
                "panel"    : [
                    {
                        "id"      : 201,
                        "name"    : "AG Informationstechnologie",
                        "short"   : "AGiT",
                        "public"  : true,
                        "active"  : true
                    }
                ]
            },
            {
                "id"       : 3,
                "name"     : "Deutsche Gesellschaft für minimalinvasive Therapie in der DRG",
                "short"    : "DEGIR",
                "active"   : true,
                "panel"    : [] // [] or null
            }
        ]
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
## 3 Endpoints in API of data provider

This section is only for verified data providers!
A data provider must implement the following methods on its endpoint, which we might request at any given time.
In these cases the data provider receives the request.

### User password change push

Send only one users password change!

```
### Request:
{
    "method": "data.pwPush", 
    "id": "[partner-uuid]",
    "params": {
        "addressid"   : 10000,
        "pass"        : "passwort"
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```

### Request user list push

To request a user list push. *** Do not answer with a users list, instead send separate data.listPush ***

```
### Request:
{
    "method": "data.requestUserListPush", 
    "id": "[partner-uuid]",
    "params": {
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### Request person certificates push

To request a user list push. *** Do not answer with a users list, instead send separate data.listPush ***

```
### Request:
{
    "method": "data.requestPersonCertificatesPush", 
    "id": "[partner-uuid]",
    "params": {
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### Request clients and panel push

To request a Clients and Panel list ***

```
### Request:
{
    "method": "data.requestClientsPanel", 
    "id": "[partner-uuid]",
    "params": {
    },
    "jsonrpc": "2.0"
}
```
```
### Response
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
### Request one users whole data

Has to be implemented later. This will request for a complete data set of one customer (including addresses etc.)
```
TODO 
```
## 3 Website APIs

This section is for websites that implements radisso for user auth!

If you are missing a method, that could be used by a website communication with radisso, please open a feature request ticket here.

### kill user session

Website sends hook that user will logout.
```
### Request
{
    "method": "website.killUserSession", 
    "id": "[partner-uuid]",
    "params": {
        "addressid" : 10000
    },
    "jsonrpc": "2.0"
}
```
```
### Response you will receive!
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "1"
}
```
### get user hash
Some website have special rights, they are able to request for a user OTL hash.
```
### Request
{
    "method": "website.userHash", 
    "id": "[partner-uuid]",
    "params": {
        "addressid" : 10000
    },
    "jsonrpc": "2.0"
}
```
```
### Response you will receive!
{
    "jsonrpc" : "2.0",
    "result" : {
        "hash" : "md5String"
    },
    "id" : "1"
}
```
### Find user

Some website have special rights, they are able to search in users.
At least one param musst be filled!
"```company```" searches in "```company```" and "```department```"
```
### Request:
{
    "method": "website.findUser", 
    "id": "[partner-uuid]",
    "params": {
        "addressid" : NULL,
        "firstname" : "",
        "lastname" : "",
        "mail" : "",
        "company": ""
    },
    "jsonrpc": "2.0"
}
```
```
### Response you should receive, also if the user was not logged in!
{
    "jsonrpc" : "2.0",
    "result" : {
        "users" : [
            {
                "uuid"        : "[uuid]",
                "addressid"   : 10000,
                "mail"        : "max@mustermann.de",
                "sex"         : "F",
                "salutation"  : "Frau",
                "title"       : "Dr.",
                "firstname"   : "Max",
                "lastname"    : "Mustermann",
                "birthdate"   : "1977-12-03",
                "company"     : "urbanstudio GmbH",
                "department"  : "Programmierung",
                "streetnr"    : "Musterstraße 10",
                "zip"         : "12345",
                "city"        : "Harzgerode",
                "country"     : "DE",
                "phone"       : "012345678910",
                "memberof"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 836,
                        "in"      : "2015-01-01",
                        "out"     : "2018-12-31",
                        "active"  : 0
                    }
                ],
                "agmemberof"    : [
                    {
                        "name"    : ""AG Physik und Technik in der bildgebende"",
                        "id"      : 4
                    },
                    {
                        "name"    : "FFZ",
                        "id"      : 17
                    }
                ],
                "memberships"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "AG1",
                                  "number"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "AG2",
                                  "number"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              }
                        ]
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 948,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "DGMP AG1",
                                  "number"  : 1,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "DGMP AG2",
                                  "number"  : 2,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-05-31",
                                  "active"  : 0
                            
                              }
                        ]
                    }
                ],
                "participatingevents"    : [
                    "2021RD",
                    "2022RD"
                ],
                "participatingconrad"    : [
                    "BASIC",
                    "CONRAD-RD2"
                ]
            }
        ]
    },
    "id" : "[partner-uuid]"
}
```
### get person certification types
parameter force true|false reloads data from data.provider
```
### Request
{
    "method": "website.getPersonCertificationTypes", 
    "id": "[partner-uuid]",
    "params": {
        "force" : false
    },
    "jsonrpc": "2.0"
}
```
```
### Response you will receive!
{
    "jsonrpc" : "2.0",
    "result" : {
        "types": [
            {
                "vewaid"  : 1,
                "name"  : "AG Herz Personen",
                "center" : false
            },
            {
                "vewaid"  : 2,
                "name"  : "AG BVB Personen",
                "center" : false
            },
            {
                "vewaid"  : 3,
                "name"  : "AG Uro",
                "center" : false
            }
        ]
    },
    "id" : "[partner-uuid]"
}
```
### get person certifications
parameter force true|false reloads data from data provider
```
### Request
{
    "method": "website.getPersonCertifications", 
    "id": "[partner-uuid]",
    "params": {
        "force" : false
    },
    "jsonrpc": "2.0"
}
```
```
### Response you will receive!
{
    "jsonrpc" : "2.0",
    "result" : {
        "persons": [
            {
                "addressid"   : 10000,
                "sex"         : "F",
                "salutation"  : "Frau",
                "title"       : "Dr.",
                "firstname"   : "Gabriele",
                "lastname"    : "Mustermann",
                "company"     : "urbanstudio GmbH",
                "department"  : "Programmierung",
                "streetnr"    : "Musterstraße 10",
                "zip"         : "12345",
                "city"        : "Harzgerode",
                "country"     : "DE",
                "phone"       : "012345678910",
                "mail"        : "gabriele@mustermann.de",
                "certs"       : [
                    {
                        "type"         : 1,
                        "modules"      : ["CT","MRT"],
                        "level"        : 1
                        "status"       : "ausgestellt",
                        "date"         : "2023-02-28",
                        "public"       : 0
                    },
                    {
                        "type"         : 2,
                        "modules"      : ["A","B","C","D","E"],
                        "level"        : 2
                        "status"       : "ausgestellt",
                        "date"         : "2023-02-28",
                        "public"       : 1
                    }
                ]
            }
        ]
    },
    "id" : "[partner-uuid]"
}
```

## 3 Endpoints in API of website

This section is only for verified websites
!
A data provider must implement the following methods on its endpoint, which we might request at any given time.
In these cases the website receives the request.

### create user Session

Send one users data for login check on website! User is already authenticated by radisso at this moment.
If user has enabled 2FA, the request is sent after the 2FA check.

```
### Request:
{
    "method": "website.createUserSession",
    "id": "[partner-uuid]",
    "params": {
        "originUrl" : "https://beispieldomain.de/test/?abc=def",
        "wsvars"    : "[base64encodedstring]",
        "user"      : {
            "uuid"        : "[uuid]",
            "addressid"   : 10000,
            "mail"        : "max@mustermann.de",
            "sex"         : "F",
            "salutation"  : "Frau",
            "title"       : "Dr.",
            "firstname"   : "Max",
            "lastname"    : "Mustermann",
            "birthdate"   : "1977-12-03",
            "company"     : "urbanstudio GmbH",
            "department"  : "Programmierung",
            "streetnr"    : "Musterstraße 10",
            "zip"         : "12345",
            "city"        : "Harzgerode",
            "country"     : "DE",
            "phone"       : "012345678910",
            "memberof"    : [
                {
                    "name"    : "DRG",
                    "number"  : 500,
                    "in"      : "2012-01-01",
                    "out"     : "",
                    "active"  : 1
                },
                {
                    "name"    : "DGMP",
                    "number"  : 836,
                    "in"      : "2015-01-01",
                    "out"     : "2018-12-31",
                    "active"  : 0
                }
            ],
            "agmemberof"    : [
                {
                    "name"    : ""AG Physik und Technik in der bildgebende"",
                    "id"      : 4
                },
                {
                    "name"    : "FFZ",
                    "id"      : 17
                }
            ],
                "memberships"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "AG1",
                                  "number"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "AG2",
                                  "number"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              }
                        ]
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 948,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "DGMP AG1",
                                  "number"  : 1,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "DGMP AG2",
                                  "number"  : 2,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-05-31",
                                  "active"  : 0
                            
                              }
                        ]
                    }
                ],
            "participatingevents"    : [
                "2021RD",
                "2022RD"
            ],
            "participatingconrad"    : [
                "BASIC",
                "CONRAD-RD2"
            ]
        }
    },
    "jsonrpc": "2.0"
}
```
```
### Response on success
{
    "jsonrpc" : "2.0",
    "result" : {
        "redirectUrl"   : "https://beispieldomain.de/test/?abc=def&token=[token]",
        "token"         : "[token]",
        "login"         : true
    },
    "id" : "[partner-uuid]"
}
```
```
### Response on error
{
    "jsonrpc" : "2.0",
    "result" : {
        "redirectUrl"   : "what-ever-url-you will redirect the user to",
        "token"         : "[users-uuid]",
        "message"       : "You have not enough rights to show the content",
        "login"         : false
    },
    "id" : "partner-uuid"
}
```

### kill user session

On user logout all websites will receive a request to kill the actual session for the user.

```
### Request:
{
    "method": "website.killUserSession", 
    "id": "[partner-uuid]",
    "params": {
        "addressid" : 10000
    },
    "jsonrpc": "2.0"
}
```
```
### Response you should receive, also if the user was not logged in!
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "1"
}
```


### user data push

In the case, that a user has been logged in to a website before and there are changes in the user data we sent, a websites receives user data updates.
So the website may use correct email address for notifications, if needed.

!!! This method is not yet implemented in radisso but will in near future !!!

```
### Request:
{
    "method": "website.userDataPush",
    "id": "[partner-uuid]",
    "params": {
        "users" : [
            {
                "uuid"        : "[uuid]",
                "addressid"   : 10000,
                "mail"        : "max@mustermann.de",
                "pass"        : "passwort",
                "sex"         : "F",
                "salutation"  : "Frau",
                "title"       : "Dr.",
                "firstname"   : "Max",
                "lastname"    : "Mustermann",
                "birthdate"   : "1977-12-03",
                "company"     : "urbanstudio GmbH",
                "department"  : "Programmierung",
                "streetnr"    : "Musterstraße 10",
                "zip"         : "12345",
                "city"        : "Harzgerode",
                "country"     : "DE",
                "phone"       : "012345678910",
                "memberof"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 836,
                        "in"      : "2015-01-01",
                        "out"     : "2018-12-31",
                        "active"  : 0
                    }
                ],
                "agmemberof"    : [
                    {
                        "name"    : ""AG Physik und Technik in der bildgebende"",
                        "id"      : 4
                    },
                    {
                        "name"    : "FFZ",
                        "id"      : 17
                    }
                ],
                "memberships"    : [
                    {
                        "name"    : "DRG",
                        "number"  : 500,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "AG1",
                                  "number"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "AG2",
                                  "number"  : 500,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              }
                        ]
                    },
                    {
                        "name"    : "DGMP",
                        "number"  : 948,
                        "in"      : "2012-01-01",
                        "out"     : "",
                        "active"  : 1,
                        "ags"     : [
                              {
                                  "name"    : "DGMP AG1",
                                  "number"  : 1,
                                  "in"      : "2012-01-01",
                                  "out"     : "",
                                  "active"  : 1,
                            
                              },
                              {
                                  "name"    : "DGMP AG2",
                                  "number"  : 2,
                                  "in"      : "2012-01-01",
                                  "out"     : "2023-05-31",
                                  "active"  : 0
                            
                              }
                        ]
                    }
                ],
                "participatingevents"    : [
                    "2021RD",
                    "2022RD"
                ],
                "participatingconrad"    : [
                    "BASIC",
                    "CONRAD-RD2"
                ]
            }
        ]
    },
    "jsonrpc": "2.0"
}
```
```
### Response you should give in any case, also if the user is absent in your system!
{
    "jsonrpc" : "2.0",
    "result" : "OK",
    "id" : "partner-uuid"
}
```
## 4 Test your implementation

To test your implementation we have a staging system

You will add "dev." in the URL of the API and loginEndpoints. 

Staging-API-Url:          https://dev.api.radisso.de/
Staging-Main-Login-Url:   https://dev.radisso.de/


## Changelog

### [0.0.7] - 2022-03-25

#### Changed
- changed user data params street to streetnr in different Requests/Responses (street, streetno, zip, country, phone)
- removed user data param streetno in different Requests/Responses
- Changed Wrapper to latest Version
- Changed some DEMO Files.

### [0.0.6] - 2022-03-07

#### Changed
- Added user data params in different Requests/Responses (street, streetno, zip, country, phone)

### [0.0.5] - 2021-08-02

#### Changed
- Added Demo Wrapper Class 
- Added some Demo files to show Wrapper usage
- Added Information of optional param on Login request

### [0.0.4] - 2021-07-29

#### Changed
- changed method prefix for partner methods
- removed participatingids from user objects
- added participatingevents as array to user objects
- added participatingconrad as array to user objects

### [0.0.3] - 2021-07-21

#### Changed
- added data provider and website methods

### [0.0.2] - 2021-07-20

#### Changed
- added onboarding process

### [0.0.1] - 2021-07-19

#### Created
