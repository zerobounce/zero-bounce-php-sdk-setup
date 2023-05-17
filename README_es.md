{\rtf1\ansi\ansicpg1252\cocoartf2709
\cocoatextscaling0\cocoaplatform0{\fonttbl\f0\fswiss\fcharset0 Helvetica;}
{\colortbl;\red255\green255\blue255;}
{\*\expandedcolortbl;;}
\paperw11900\paperh16840\margl1440\margr1440\vieww11520\viewh8400\viewkind0
\pard\tx566\tx1133\tx1700\tx2267\tx2834\tx3401\tx3968\tx4535\tx5102\tx5669\tx6236\tx6803\pardirnatural\partightenfactor0

\f0\fs24 \cf0 # ZeroBounce PHP SDK\
\
Este SDK contiene m\'e9todos para interactuar f\'e1cilmente con la API de ZeroBounce.\
Puede encontrar m\'e1s informaci\'f3n sobre ZeroBounce en la [documentaci\'f3n oficial](https://www.zerobounce.net/docs/).\
\
## Instalaci\'f3n\
Para instalar el SDK, necesitar\'e1 utilizar [Composer](https://getcomposer.org/) en su proyecto.\
Si no est\'e1 utilizando Composer, puede instalarlo de la siguiente manera:\
```bash\
curl -sS https://getcomposer.org/installer | php\
# o\
sudo apt install -y composer\
```\
\
Para instalar el SDK con Composer, ejecute:\
```bash\
composer install zero-bounce/sdk\
```\
\
## Uso\
- _incluya el SDK en su archivo (siempre debe usar el autoloader de Composer en su aplicaci\'f3n para cargar autom\'e1ticamente las dependencias)_\
```php\
require 'vendor/autoload.php';\
use ZeroBounce\\SDK\\ZeroBounce;\
```\
\
- _inicialice el SDK con su clave de API_\
```php\
ZeroBounce::Instance()->initialize("<SU_CLAVE_DE_API>");\
```\
\
### _Documentaci\'f3n de m\'e9todos_\
\
- Verificar una direcci\'f3n de correo electr\'f3nico:\
```php\
/** @var $response ZeroBounce\\SDK\\ZBValidateResponse */\
$response = ZeroBounce::Instance()->validate(\
                "<DIRECCI\'d3N_DE_CORREO_ELECTR\'d3NICO>",  // La direcci\'f3n de correo electr\'f3nico que desea validar\
                "<DIRECCI\'d3N_IP>"                       // La direcci\'f3n IP desde la cual se registr\'f3 el correo electr\'f3nico (puede estar en blanco)\
            );\
\
// puede ser: v\'e1lido, inv\'e1lido, catch-all, desconocido, spamtrap, abuso, no_enviar_correo\
$status = $response->status;\
```\
\
- Verificar cu\'e1ntos cr\'e9ditos le quedan en su cuenta\
```php\
/** @var $response ZeroBounce\\SDK\\ZBGetCreditsResponse */\
$response = ZeroBounce::Instance()->getCredits();\
$credits = $response->credits;\
```\
\
- Verificar el uso de su API durante un per\'edodo de tiempo espec\'edfico\
```php\
$startDate = new DateTime("-1 mes");  // La fecha de inicio de cuando desea ver el uso de la API\
$endDate = new DateTime();            // La fecha de finalizaci\'f3n de cuando desea ver el uso de la API\
\
/** @var $response ZeroBounce\\SDK\\ZBApiUsageResponse */\
$response = ZeroBounce::Instance()->getApiUsage($startDate, $endDate);\
$usage = $response->total;\
```\
\
- Verificar la actividad de un suscriptor dado su correo electr\'f3nico\
```php\
/** @var $response ZeroBounce\\SDK\\ZBActivityResponse */\
$response = ZeroBounce::Instance()->getActivity("<DIRECCI\'d3N_DE_CORREO_ELECTR\'d3NICO>");\
$active_in_days = $response->activeInDays;\
```\
\
- Enviar un archivo para validaci\'f3n masiva de correo electr\'f3nico\
```php\
/** @var $response ZeroBounce\\SDK\\ZBSendFileResponse */\
$response = ZeroBounce::Instance()->sendFile(\
    "<RUTA_DEL_ARCHIVO>",               // El archivo CSV o TXT\
    "<COLUMNA_DIRECCI\'d3N_DE_CORREO>",     // El \'edndice de columna de la direcci\'f3n de correo electr\'f3nico en el archivo. El \'edndice comienza en 1\
    "<URL_DE_RETORNO>",                  // La URL que se utilizar\'e1 como devoluci\'f3n de llamada despu\'e9s de que se env\'ede el archivo\
    "<COLUMNA_NOMBRE_PRIMERO>",          // El \'ed\
\
ndice de columna del primer nombre del usuario en el archivo\
    "<COLUMNA_APELLIDO>",                // El \'edndice de columna del apellido del usuario en el archivo\
    "<COLUMNA_G\'c9NERO>",                  // El \'edndice de columna del g\'e9nero del usuario en el archivo\
    "<COLUMNA_DIRECCI\'d3N_IP>",            // El \'edndice de columna de la direcci\'f3n IP en el archivo\
    "<TIENE_FILA_DE_ENCABEZADO>"         // Si la primera fila del archivo enviado es una fila de encabezado. Verdadero o Falso\
);\
$fileId = $response->fileId;            // por ejemplo, "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"\
```\
\
- Verificar el estado de un archivo cargado mediante el m\'e9todo "sendFile"\
```php\
$fileId = "<ID_DE_ARCHIVO>";   // El ID de archivo recibido de la respuesta "sendFile"\
 \
/** @var $response ZeroBounce\\SDK\\ZBFileStatusResponse */\
$response = ZeroBounce::Instance()->fileStatus($fileId);\
$status = $response->fileStatus;    // por ejemplo, "Completado"\
```\
\
- Obtener el archivo de resultados de validaci\'f3n para el archivo que se envi\'f3 utilizando el m\'e9todo sendFile\
```php\
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"\
$downloadPath = "<RUTA_DE_DESCARGA>";     // La ruta donde se descargar\'e1 el archivo\
 \
/** @var $response ZeroBounce\\SDK\\ZBGetFileResponse */\
$response = ZeroBounce::Instance()->getFile($fileId, $downloadPath);\
$localPath = $response->localFilePath;\
```\
\
- Elimina el archivo que se envi\'f3 utilizando el m\'e9todo sendFile. El archivo solo se puede eliminar cuando su estado es _`Completado`_\
```php\
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"\
 \
/** @var $response ZeroBounce\\SDK\\ZBDeleteFileResponse */\
$response = ZeroBounce::Instance()->deleteFile($fileId);\
$success = $response->success;      // Verdadero / Falso\
```\
\
#### API de puntuaci\'f3n de inteligencia artificial\
- El API de puntuaci\'f3n de env\'edo de archivo permite al usuario enviar un archivo para puntuaci\'f3n masiva de correo electr\'f3nico\
```php\
/** @var $response ZeroBounce\\SDK\\ZBSendFileResponse */\
$response = ZeroBounce::Instance()->scoringSendFile(\
    "<RUTA_DEL_ARCHIVO>",               // El archivo CSV o TXT\
    "<COLUMNA_DIRECCI\'d3N_DE_CORREO>",     // El \'edndice de columna de la direcci\'f3n de correo electr\'f3nico en el archivo. El \'edndice comienza en 1\
    "<URL_DE_RETORNO>",                  // La URL que se utilizar\'e1 como devoluci\'f3n de llamada despu\'e9s de que se env\'ede el archivo\
    "<TIENE_FILA_DE_ENCABEZADO>"         // Si la primera fila del archivo enviado es una fila de encabezado. Verdadero o Falso\
);\
$fileId = $response->fileId;            // por ejemplo, "aaaaaaaa-zzzz-xxxx-yyyy-5003727fffff"\
```\
\
- Verificar el estado de un archivo cargado mediante el m\'e9todo "scoringSendFile"\
```php\
$fileId = "<ID_DE_ARCHIVO>";   // El ID de archivo recibido de la respuesta "sendFile"\
 \
/** @var $response Zero\
\
Bounce\\SDK\\ZBFileStatusResponse */\
$response = ZeroBounce::Instance()->scoringFileStatus($fileId);\
$status = $response->fileStatus;    // por ejemplo, "Completado"\
```\
\
- Obtener el archivo de resultados de validaci\'f3n para el archivo que se envi\'f3 utilizando el m\'e9todo scoringSendfile\
```php\
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"\
$downloadPath = "<RUTA_DE_DESCARGA>";     // La ruta donde se descargar\'e1 el archivo\
 \
/** @var $response ZeroBounce\\SDK\\ZBGetFileResponse */\
$response = ZeroBounce::Instance()->scoringGetFile($fileId, $downloadPath);\
$localPath = $response->localFilePath;\
```\
\
- Elimina el archivo que se envi\'f3 utilizando el m\'e9todo scoringSendfile. El archivo solo se puede eliminar cuando su estado es _`Completado`_\
```php\
$fileId = "<ID_DE_ARCHIVO>";              // El ID de archivo recibido de la respuesta "sendFile"\
 \
/** @var $response ZeroBounce\\SDK\\ZBDeleteFileResponse */\
$response = ZeroBounce::Instance()->scoringDeleteFile($fileId);\
$success = $response->success;      // Verdadero / Falso\
```\
\
## Desarrollo\
\
Instale los m\'f3dulos de PHP requeridos\
```bash\
sudo apt install -y php-curl php-dom php-xml php-xmlwriter\
```\
\
Instale las dependencias de desarrollo\
```bash\
composer install --dev\
```\
\
Ejecute las pruebas\
```bash\
./vendor/bin/phpunit test\
```}