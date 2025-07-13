<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__.'/libreria/vendor/autoload.php';

use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;

$ruta_archivos = dirname(__FILE__);
$_auth = new funciones_conexion;

// Consulta para saber cuales son los ruc que tienen factura sin enviar
$consulta_ruc = "SELECT e.ruc
                    FROM apifasdisafer_portal.comprobantes c
                    INNER JOIN apifasdisafer_portal.empresas e on  e.id = c.empresa_id
                    WHERE c.estado_id = 3 AND c.mensaje_error = 'Resumen_Generado' AND e.tipo_empresa_id = 2 AND fecha_emision > '2025-06-01 00:00:00'
                    GROUP BY empresa_id
                    ORDER BY empresa_id DESC";
$consulta_ruc = $_auth->query($consulta_ruc);

foreach($consulta_ruc as $dato){

    $ruc = $dato['ruc'];
    $datos_emisor = $_auth->datos_empresa($ruc);
    $id_empresa = $datos_emisor['id'];

    $comprobantes_del_ruc = "SELECT ruta_xml
                                FROM apifasdisafer_portal.comprobantes 
                                WHERE estado_id = 3 AND mensaje_error = 'Resumen_Generado' AND empresa_id = ".$id_empresa."
                                GROUP BY ruta_xml";
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);

    foreach ($comprobantes_del_ruc as $comprobante) {

        $ruta_xml = $comprobante["ruta_xml"];

        $see = new See();
   
        $usuario_sol = $datos_emisor["usuario_sol"];
        $clave_sol = $datos_emisor["clave_sol"];  
        $see->setCertificate(file_get_contents($ruta_archivos.'/libreria/certificados/'.$ruc.'.pem'));
        $see->setService(SunatEndpoints::FE_PRODUCCION); // Cambiar la url para cuando sea Percepci¨®n/Retenci¨®n
        $see->setClaveSOL($ruc, $usuario_sol, $clave_sol);
        
        $ruta_envio_xml = $ruta_archivos."/".$ruta_xml;   
        $xmlSigned = file_get_contents($ruta_envio_xml);
        $result = $see->sendXmlFile($xmlSigned);

        if (!$result->isSuccess()) {
            // Si hubo error al conectarse al servicio de SUNAT.     
            $mensaje_error = $result->getError()->getCode();
            
            $sql = "UPDATE apifasdisafer_portal.comprobantes 
                    SET estado_id = ".$estado.", mensaje_error = '".$mensaje_error."'
                    WHERE ruta_xml = '".$ruta_xml."' AND empresa_id = ".$id_empresa;
            $consulta = $_auth->query($sql);
            if($consulta==null){
                $respuesta = "No se pudo actualizar el orden de anulacion";
                echo $respuesta;
                exit;
            }

        }else{
            $ticket = $result->getTicket();
            $sql = "UPDATE apifasdisafer_portal.comprobantes 
                    SET n_ticket = '".$ticket."',
                        mensaje_error = 'Resumen_Enviado'
                    WHERE ruta_xml = '".$ruta_xml."'";
            $consulta = $_auth->query($sql);
            if($consulta==null){
                $respuesta = "No se pudo generar el resumen";
                echo $respuesta;
                exit;
            }
        }

    }
}

?>