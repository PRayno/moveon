# MoveOn Api wrapper

MoveOn (https://www.qs-unisolution.com/moveon/) is an application used to manage International Mobility between universities and schools (Erasmus program for example). 
This package is a php wrapper for the MoveOn API.

## Installation
Install the library via Composer by running the following command:

`composer require prayno/moveon`

## Usage

### Prerequisites
Prior to usage, you must contact your MoveON technical rep to activate the API in your MoveON instance.

Then, you have to generate a self-signed X509 certificate for your API client (.pem) and bind the serial number to a MoveON user (see technical doc for that)

### Instantiation of the class
The MoveOn class now requires the use of the symfony/http-client component.
```php
private $client;

public function __construct(HttpClientInterface $client)
{
    $this->client = $client->withOptions([
        'local_cert' => '/location/to/my/certificate/mycertificate.crt',
        'local_pk' => '/location/to/my/certificate/mycertificate.key',
        'passphrase' => 'myOptionalPassphraseToReadTheCertificate',
        'base_uri' => 'https://myUniversityInstance-api.moveonfr.com/restService/index.php?version=3.0'
    ]);
}

public function myFunction()
{
    $moveon = new MoveOn($this->client);
    ...
}
```

### Retrieve information
To gather information, you need the entity to look for and the criteria you want to search on.
```php
$data = $moveon->findBy("person",["surname"=>"Foo","first_name"=>"Bar"]);
```
You can use arrays as criteria to search for multiple values. Eg :
```php
$data = $moveon->findBy("person",["surname"=>"Doe","first_name"=>["John","Jane"]]);
```

This will return a SimpleXMLElement object (page,records,total and rows).

You can also add more options to this method :
- sort : array of fields / order
- rows : number of rows per page
- page : page of results
- columns : filter fields being returned
- locale : eng/fra/deu/ita/spa

Eg :
```php
$data = $moveon->findBy("person",["surname"=>"Foo","first_name"=>"Bar"],["surname"=>"asc","first_name"=>"asc"],20,1,["email","surname","last_name"],"fra");
```

Due to a limit set by QS, you cannot request for more than 250 rows. However, this library allows you to request for more lines, it will send multiple requests and merge the responses into a single one.
This feature is only available if you don't request for a specific page.

### Save data
Create and update use the same method ; if you want to update, you just need to provide the id of the entry.

```php
$data = $moveon->save("person",["id"=>"1","surname"=>"Foo","first_name"=>"Bar"]);
```

The additional parameter `$retrieveData` set to false allows the method to only return the queueId provided by the API for a later use (useful when you have many queries)

#### Note :

The following fields were excluded from their entities as they make the requests fail

person : 
address.type_eng,address.type_fra,address.type_deu,address.type_ita,address.type_spa

relation :
parent,created_on,created_by,last_modified_by,last_modified_on

academic-year :
is_active

subject-area :
isced

institution :
sector_id,size_id,organization_type_id

### Custom query
You can also create your own custom query and send it to the API using the sendQuery method.
```php
$data = $moveon->sendQuery("person","list",YOUR_QUERY_STRING);
```
