# radisso
radisso is a SSO eco system for the radiology society of the DRG and assosiated societies in Germany

### Prerequisites
There are some thigs that need attention when onboarding.
1. make sure your API-Endpoint is secured by an SSL-Certificate
2. create an PrivKey/PubKey Pair
3. to receive API-Requests send your onboarding request to service@urbanstudio.de
  - an APP Name (max 20 characters)
  - including your public key (named as {APPNAME}.pub
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
This ist asyncronous so you must implement a API method that we are able to receive your answer.

### Onboarding

The API endpoint for the onboarding is ```https://api.radisso.de/onboarding/```

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
            "pubkeydl"  : "https://[your-domain]/[appname].pub
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

##### If your request has been verified
```loginEnpoints```wird nur bei ```type``` == ```website``` geliefert.
```
{
    "method": "radisso.onboardingVerification", 
    "id": "1", 
    "result": {
        "appname" : "YOUR-APP-NAME",
        "type"    : "website",
        "state"    : "verified",
        "api" : {
            "endpoint"  : "https://[our-api-domain]:[port]/[path]",
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
    "id": "1", 
    "result": {
        "appname" : "YOUR-APP-NAME",
        "type"    : "website",
        "state"   : "declined"
    }, 
    "jsonrpc": "2.0"
}
```
