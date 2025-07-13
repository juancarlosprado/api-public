<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__.'/libreria/vendor/autoload.php';
require_once __DIR__."/envio_sunat/generacion_anulacion_boleta.php";
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;

$ruta_archivos = dirname(__FILE__);
$_auth = new funciones_conexion;

// Consulta para saber cuales son los ruc que tienen factura sin enviar
$consulta_ruc = "SELECT e.ruc
                FROM apifasdisafer_portal.comprobantes c
                INNER JOIN apifasdisafer_portal.empresas e on  e.id = c.empresa_id
                WHERE c.estado_id = 5 AND e.tipo_empresa_id = 2 AND c.mensaje_error = 'Resumen Aceptado'
                GROUP BY e.ruc";
$consulta_ruc = $_auth->query($consulta_ruc);

foreach($consulta_ruc as $dato){

    $ruc = $dato['ruc'];
    $datos_emisor = $_auth->datos_empresa($ruc);
    $id_empresa = $datos_emisor['id'];

    var_dump($datos_emisor);

    $comprobantes_del_ruc = "SELECT id,serie,correlativo,fecha_emision,fecha_anulacion
                            FROM apifasdisafer_portal.comprobantes 
                            WHERE estado_id = 5 AND mensaje_error = 'Resumen Aceptado' AND empresa_id = ".$id_empresa;
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);

    foreach ($comprobantes_del_ruc as $comprobante) {

        var_dump($comprobante);
        $min_anulado = $_auth->orden_anulado($ruc);

        // Actualizamos la cantidad de anulado
        $numero_anulado = $min_anulado+1;
        $_auth->actualizar_orden_anulado($ruc,$numero_anulado);
        $id_comprobante = $comprobante['id'];
        generacion_anulacion($ruc,$id_comprobante,$datos_emisor,$numero_anulado,$comprobante);
    }
}