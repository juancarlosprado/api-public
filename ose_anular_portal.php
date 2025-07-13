<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__."/funciones/funciones_generales.php";

require_once __DIR__."/funciones/generar_json.php";
require_once __DIR__."/funciones/generar_txt.php";


$_auth = new funciones_conexion;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // recibimos los datos para la generacion del xml
    // se recibe el texto, que contiene la info de los archivos; se recibe el ruc de la empres y el nombre de la empreas
    $ruc = $_POST["Ruc"];
    $nombre = $_POST["Nombre"];
    $motivo_anulacion = $_POST["motivo_anulacion"];
    $contenido = $_POST["Texto"];
    $contenido = $contenido.'motivo_de_baja|'.$motivo_anulacion.'|';

    // Validamos la existencia de la empresa en la BD
    $_auth->existe_empresa($ruc);

    // Del nombre, valida la extension, si es repetido y devuelve el tipo de documento que es
    $tipo_doc = descomponer_cadena_anulado($nombre);
    $array_cadena_ingresada = explode('-', pathinfo($nombre)['filename']);
    $array_cadena_ingresada[1]= intval($_auth->existe_empresa($array_cadena_ingresada[1]));
    $array_cadena_ingresada[4]= intval($array_cadena_ingresada[4]);
    $id_comprobante = $_auth->id_comprobante_anular($array_cadena_ingresada);
    
    // Da los datos de la empresa, tales como direccion e usario y clave sol
    $datos_emisor = $_auth->datos_empresa($ruc);
    $min_anulado = $_auth->orden_anulado($ruc);

    // Actualizamos la cantidad de anulado
    $numero_anulado = $min_anulado+1;
    $_auth->actualizar_orden_anulado($ruc,$numero_anulado);
    
    $nombre_archivo = pathinfo($nombre)['filename'];
    $nombre_archivo_txt = $ruc."/anulaciones/".$nombre_archivo.".txt";
    $ruta_achivo_json = $ruc."/anulaciones/".$nombre_archivo.".json";

    // Guardar la variable en el archivo
    file_put_contents($nombre_archivo_txt, $contenido);
    re_escribe($nombre_archivo_txt);  
    // creamos el contenedor de la respuesta, se tiene como cabeceta el ruc de la empresa y el nombre del archivo
    $respuesta_final = array();
    convertir_json($nombre_archivo_txt,$ruta_achivo_json);
    unlink($nombre_archivo_txt);

    // Ahora hay que generar el xml de la anulacion
    switch ($tipo_doc) {
    case '1':
        require_once "ose/anular_factura.php";
        anular_factura($ruta_achivo_json,$ruc,$id_comprobante,$datos_emisor,$numero_anulado);
        break;
    case '3':
        require_once "ose/anular_boleta.php";
        anular_boleta($ruta_achivo_json,$ruc,$id_comprobante,$datos_emisor,$numero_anulado);
        break;
    }
    unlink($ruta_achivo_json);
    $respuesta = 'Anulado OSE';
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