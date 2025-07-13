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
                    WHERE c.tipo = 1 AND c.estado_id = 1 AND e.tipo_empresa_id = 2
                    GROUP BY empresa_id ORDER BY empresa_id ASC";
$consulta_ruc = $_auth->query($consulta_ruc);

foreach($consulta_ruc as $dato){

    $ruc = $dato['ruc'];
    $datos_emisor = $_auth->datos_empresa($ruc);
    $id_empresa = $datos_emisor['id'];

    $comprobantes_del_ruc = "SELECT id,ruta_xml
                                FROM apifasdisafer_portal.comprobantes 
                                WHERE tipo = 1 AND estado_id = 1 AND empresa_id = ".$id_empresa.  " ORDER BY empresa_id desc";
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);



    foreach ($comprobantes_del_ruc as $comprobante) {

        $ruta_xml = $comprobante["ruta_xml"];
        $id_comprobantes = $comprobante["id"];

        $see = new See();
        $id_comprobantes = strval($id_comprobantes);
    
        $usuario_sol = $datos_emisor["usuario_sol"];
        $clave_sol = $datos_emisor["clave_sol"];  
        $see->setCertificate(file_get_contents($ruta_archivos.'/libreria/certificados/'.$ruc.'.pem'));
        $see->setService(SunatEndpoints::FE_PRODUCCION); // Cambiar la url para cuando sea Percepción/Retención
        $see->setClaveSOL($ruc, $usuario_sol, $clave_sol);
        
        $ruta_envio_xml = $ruta_archivos."/".$ruta_xml;   
        $xmlSigned = file_get_contents($ruta_envio_xml);
        $result = $see->sendXmlFile($xmlSigned);


        if (!$result->isSuccess()) {
            // Mostrar error al conectarse a SUNAT.
            $mensaje_error= $result->getError()->getCode();
            $estado = 4;
            $ruta_cdr = "";
            $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
        }else{
            // Guardamos el CDR
            $ruta_xml = substr($ruta_xml, 12);
            $ruta_xml = pathinfo($ruta_xml)['filename'];
            $ruta_cdr = $ruc.'/R-'.$ruta_xml.'.zip';

            $ruta_guardado_cdr = $ruta_archivos."/".$ruta_cdr;
            file_put_contents($ruta_guardado_cdr, $result->getCdrZip());

            $cdr = $result->getCdrResponse();
            $code = (int)$cdr->getCode();

            if ($code === 0) {
                $mensaje_error = "Aceptado";
                $estado = 3;
                if (count($cdr->getNotes()) > 0) {
                    $mensaje_error = $cdr->getNotes();
                }
                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
            
            } else if ($code >= 2000 && $code <= 3999) {
                $estado = 2;
                $mensaje_error= $result->getError()->getCode();
                if (count($cdr->getNotes()) > 0) {
                    $mensaje_error = $cdr->getNotes();
                }    
                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
            }else {
                $estado = 4;
                $mensaje_error= "Excepcion";
                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
       
            }

        }

    }


}

?>