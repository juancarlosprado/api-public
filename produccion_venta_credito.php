<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__."/funciones/funciones_generales.php";

require_once __DIR__."/funciones/generar_json.php";
require_once __DIR__."/funciones/generar_txt.php";

$_auth = new funciones_conexion;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // recibimos los datos para la generacion del xml
    // se recibe el texto, que contiene la info de los archivos; se recibe el ruc de la empres y el nombre de la empreas
    $header = getallheaders();  
    $contenido = $header["Texto"];
    $contenido_2 = $header["Texto_2"];
    $contenido = $contenido.$contenido_2;

    $ruc = $header["Ruc"];
    $nombre = $header["Nombre"];
    
    $notas_deudor = "Acceso denegado - Comunicarse con el area de facturacion de DISAFER";
    
    // Validamos la existencia de la empresa en la BD
    $_auth->existe_empresa($ruc);

    // Del nombre, valida la extension, si es repetido y devuelve el tipo de documento que es
    $tipo_doc = descomponer_cadena($nombre);

    // Da los datos de la empresa, tales como direccion e usario y clave sol
    $datos_emisor = $_auth->datos_empresa($ruc);
    
    // Generamos la ruta de los archivos donde se guardara el txt y json
    $nombre_archivo = pathinfo($nombre)['filename'];
    $rutaArchivo_txt = $ruc."/".$nombre_archivo.".txt";
    $ruta_achivo_json = $ruc."/".$nombre_archivo.".json";
    
    $contenido = str_replace("'", "", $contenido);
    // $contenido = trim($contenido);

    // Generamos los archivos de json y txt
    file_put_contents($rutaArchivo_txt, $contenido);
    re_escribe($rutaArchivo_txt);
    convertir_json($rutaArchivo_txt,$ruta_achivo_json);
    unlink($rutaArchivo_txt);

    // Ahora hay que generar el xml
    switch ($tipo_doc) {
        case '01':
            require_once "envio_sunat/factura_credito.php";
            $ruta_pdf = factura_boleta($ruta_achivo_json,$ruc,$datos_emisor);
            break;
        case '02':
            require_once "envio_sunat/factura.php";
            require_once "libreria/pdf.php";
            $ruta_pdf =factura_boleta($ruta_achivo_json,$ruc,$datos_emisor);
            pdf($ruta_achivo_json,$ruc,$datos_emisor,$ruta_pdf);
            break;
    }
    unlink($ruta_achivo_json);
    $respuesta = 'Proceso-Aceptado';
    return $respuesta;


}// simplemente mas metodos para que muestre algo
elseif($_SERVER["REQUEST_METHOD"] == "GET"){
    print_r("ERROR METODO DE ENVIO INCORRECTO");
}elseif($_SERVER["REQUEST_METHOD"] == "PUT"){
    print_r("ERROR METODO DE ENVIO INCORRECTO");
}elseif($_SERVER["REQUEST_METHOD"] == "DELETE"){
    print_r("ERROR METODO DE ENVIO INCORRECTO");
}else{
    print_r("ERROR METODO DE ENVIO INCORRECTO");
}

?>
