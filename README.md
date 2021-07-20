# radisso
radisso is an SSO (Single-Sign-On) eco system for the radiology society of the DRG and associated societies in Germany

### Prerequisites
There are few things you need to take care of before onboarding:
1. make sure your API-Endpoint is secured by an SSL-Certificate
2. create a PrivKey/PubKey Pair (RSA, 4096bit, see below)
3. to receive API-Requests send your onboarding request to service@urbanstudio.de
  - an APP Name (max 20 characters)
  - including your public key (named as {APPNAME}.pub) - do not send your private key!
  - API endpoint of your staging environment (incl. port no)
  - API endpoint of your production environment (incl. port no.)

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

The appname must be unique, you will need this later.
```type``` may have value "```website```" or "```data```, where "```website```" stands for website that provides login and "```data```" stands for data provider

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
            "endpoint"  : "https://[your-api-domain]:[port]/[path]",
            "otat"      : "[one-time-auth-token]",
            "pubkey"    : "[base64-of-pub-your-key]",
            "pubkeydl"  : "https://[your-domain]/[appname].pub"
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
    "id" : "1"
}
```
#### After a review you will receive a request

The request will be created on the API endpoint you provided in your onboarding request.

The complete request body will be encrypted. For processing you need to decrypt it using your private key.

##### If your request has been verified

```loginEnpoints``` will only be deliverd if ```type``` == ```website``` .

You need to save the id locally, as all other API requests need uuid as id
```
{
    "method": "radisso.onboardingVerification", 
    "id": "[uuid]", 
    "params": {
        "appname" : "YOUR-APP-NAME",
        "type"    : "website",
        "state"    : "verified",
        "api" : {
            "endpoint"  : "https://[our-api-domain]:[port]/[path]",
            "pubkeydl"  : "https://[our-api-domain]/[appname].pub"
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
    "id": "[uuid]", 
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
    "method": "onboarding.removeDomains", 
    "id": "[uuid]", 
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
    "method": "onboarding.addDomains", 
    "id": "[uuid]", 
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
    "method": "onboarding.addDomains", 
    "id": "[uuid]", 
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
## 3 Data provider APIs

This section is only for verified data providers!

### User list push

```
### Request:
{
    "method": "data.listPush", 
    "id": "[uuid]",
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
                "participatingids"    : [
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
    "id" : "1"
}
```
### User change push

Send only users with changes!

```
### Request:
{
    "method": "data.changePush", 
    "id": "[uuid]",
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
                "participatingids"    : [
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
    "id" : "1"
}
```

### User password change push

Send only one users password change!

```
### Request:
{
    "method": "data.pwPush", 
    "id": "[uuid]",
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
    "id" : "1"
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
    "id": "[uuid]",
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
    "id" : "1"
}
```

### Request user list push

To request a user list push. *** Do not answer with a users list, instead send separate data.listPush ***

```
### Request:
{
    "method": "data.requestUserListPush", 
    "id": "[uuid]",
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
    "id" : "1"
}
```
### Request one users whole data

Has to be implemented later. This will request for a complete data set of one customer (including addresses etc.)
```
TODO 
```
