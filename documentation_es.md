#### Instalación
Para instalar el SDK, necesitará utilizar [Composer](https://getcomposer.org/) en su proyecto.
Si no está utilizando Composer, puede instalarlo de la siguiente manera:
```bash
curl -sS https://getcomposer.org/installer | php
### o
sudo apt install -y composer
```

Para instalar el SDK con Composer, ejecute:
```bash
composer install zero-bounce/sdk
```

#### Uso
- _incluya el SDK en su archivo (siempre debe usar el autoloader de Composer en su aplicación para cargar automáticamente las dependencias)_
```php
require 'vendor/autoload.php';
use ZeroBounce\SDK\ZeroBounce;
```

- _inicialice el SDK con su clave de API_
```php
ZeroBounce::Instance()->initialize("<SU_CLAVE_DE_API>");
```

##### _Documentación de métodos_

- Verificar una dirección de correo electrónico:
```php
/** @var $response ZeroBounce\SDK\ZBValidateResponse */
$response = ZeroBounce::Instance()->validate(
                "<DIRECCIÓN_DE_CORREO_ELECTRÓNICO>",  // La dirección de correo electrónico que desea validar
                "<DIRECCIÓN_IP>"                       // La dirección IP desde la cual se registró el correo electrónico (puede estar en blanco)
            );

// puede ser: válido, inválido, catch-all, desconocido, spamtrap, abuso, no_enviar_correo
$status = $response->status;
```

- Verificar cuántos créditos le quedan en su cuenta
```php
/** @var $response ZeroBounce\SDK\ZBGetCreditsResponse */
$response = ZeroBounce::Instance()->getCredits();
$credits = $response->credits;
```

- Verificar el uso de su API durante un período de tiempo específico
```php
$startDate = new DateTime("-1 mes");  // La fecha de inicio de cuando desea ver el uso de la API
$endDate = new DateTime();            // La fecha de finalización de cuando desea ver el uso de la API

/** @var $response ZeroBounce\SDK\ZBApiUsageResponse */
$response = ZeroBounce::Instance()->getApiUsage($startDate, $endDate);
$usage = $response->total;
```

- Verificar la actividad de un suscriptor dado su correo electrónico
```php
/** @var $response ZeroBounce\SDK\ZBActivityResponse */
$response = ZeroBounce::Instance()->getActivity("<DIRECCIÓN_DE_CORREO_ELECTRÓNICO>");
$active_in_days = $response->activeInDays;
```

- Enviar un archivo para validación masiva de correo electrónico
```php
/** @var $response ZeroBounce\SDK\ZBSendFileResponse */
$response = ZeroBounce::Instance()->sendFile(
    "<RUTA_DEL_ARCHIVO>",               // El archivo CSV o TXT
    "<COLUMNA_DIRECCIÓN_DE_CORREO>",     // El índice de columna de la dirección de correo electrónico en el archivo. El índice comienza en 1
    "<URL_DE_RETORNO>",                  // La URL que se utilizará como devolución de llamada después de que se envíe el archivo
    "<COLUMNA_NOMBRE_PRIMERO>",          // El í

ndice de columna del primer nombre del usuario en el archivo
    "<COLUMNA_APELLIDO>",                // El índice de columna del apellido del usuario en el archivo
    "<COLUMNA_GÉNERO>",                  // El índice de columna del género del usuario en el archivo
    "<COLUMNA_DIRECCIÓN_IP>",            // El índice de columna de la dirección IP en el archivo
    "<TIENE_FILA_DE_ENCABEZADO>"         // Si la primera fila del archivo enviado es una fila de encabezado. Verdadero o Falso
);
$fileId = $response->fileId;            // por ejemplo, "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"
```

- Verificar el estado de un archivo cargado mediante el método "sendFile"
```php
$fileId = "<ID_DE_ARCHIVO>";   // El ID de archivo recibido de la respuesta "sendFile"
 
/** @var $response ZeroBounce\SDK\ZBFileStatusResponse */
$response = ZeroBounce::Instance()->fileStatus($fileId);
$status = $response->fileStatus;    // por ejemplo, "Completado"
```

- Obtener el archivo de resultados de validación para el archivo que se envió utilizando el método sendFile
```php
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"
$downloadPath = "<RUTA_DE_DESCARGA>";     // La ruta donde se descargará el archivo
 
/** @var $response ZeroBounce\SDK\ZBGetFileResponse */
$response = ZeroBounce::Instance()->getFile($fileId, $downloadPath);
$localPath = $response->localFilePath;
```

- Elimina el archivo que se envió utilizando el método sendFile. El archivo solo se puede eliminar cuando su estado es _`Completado`_
```php
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"
 
/** @var $response ZeroBounce\SDK\ZBDeleteFileResponse */
$response = ZeroBounce::Instance()->deleteFile($fileId);
$success = $response->success;      // Verdadero / Falso
```

###### API de puntuación de inteligencia artificial
- El API de puntuación de envío de archivo permite al usuario enviar un archivo para puntuación masiva de correo electrónico
```php
/** @var $response ZeroBounce\SDK\ZBSendFileResponse */
$response = ZeroBounce::Instance()->scoringSendFile(
    "<RUTA_DEL_ARCHIVO>",               // El archivo CSV o TXT
    "<COLUMNA_DIRECCIÓN_DE_CORREO>",     // El índice de columna de la dirección de correo electrónico en el archivo. El índice comienza en 1
    "<URL_DE_RETORNO>",                  // La URL que se utilizará como devolución de llamada después de que se envíe el archivo
    "<TIENE_FILA_DE_ENCABEZADO>"         // Si la primera fila del archivo enviado es una fila de encabezado. Verdadero o Falso
);
$fileId = $response->fileId;            // por ejemplo, "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"
```

- Verificar el estado de un archivo cargado mediante el método "scoringSendFile"
```php
$fileId = "<ID_DE_ARCHIVO>";   // El ID de archivo recibido de la respuesta "sendFile"
 
/** @var $response Zero

Bounce\SDK\ZBFileStatusResponse */
$response = ZeroBounce::Instance()->scoringFileStatus($fileId);
$status = $response->fileStatus;    // por ejemplo, "Completado"
```

- Obtener el archivo de resultados de validación para el archivo que se envió utilizando el método scoringSendfile
```php
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"
$downloadPath = "<RUTA_DE_DESCARGA>";     // La ruta donde se descargará el archivo
 
/** @var $response ZeroBounce\SDK\ZBGetFileResponse */
$response = ZeroBounce::Instance()->scoringGetFile($fileId, $downloadPath);
$localPath = $response->localFilePath;
```

- Elimina el archivo que se envió utilizando el método scoringSendfile. El archivo solo se puede eliminar cuando su estado es _`Completado`_
```php
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"
 
/** @var $response ZeroBounce\SDK\ZBDeleteFileResponse */
$response = ZeroBounce::Instance()->scoringDeleteFile($fileId);
$success = $response->success;      // Verdadero / Falso
```

###### Email Finder API
- Guess the format of email addresses for a domain
```php
$response = ZeroBounce::Instance()->guessFormat(
        $domain, $firstname, $middlename, $lastname);
$email = $response->email;
```


#### Desarrollo

Instale los módulos de PHP requeridos
```bash
sudo apt install -y php-curl php-dom php-xml php-xmlwriter
```

Instale las dependencias de desarrollo
```bash
composer install --dev
```

Ejecute las pruebas
```bash
./vendor/bin/phpunit test
```
