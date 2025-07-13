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
                    WHERE c.estado_id = 6 AND e.tipo_empresa_id = 2 AND c.mensaje_error = 'Anulando'
                    GROUP BY empresa_id";
$consulta_ruc = $_auth->query($consulta_ruc);


foreach($consulta_ruc as $dato){

    $ruc = $dato['ruc'];
    $datos_emisor = $_auth->datos_empresa($ruc);
    $id_empresa = $datos_emisor['id'];

    $comprobantes_del_ruc = "SELECT id,ruta_xml,n_ticket
                                FROM apifasdisafer_portal.comprobantes 
                                WHERE estado_id = 6 AND mensaje_error = 'Anulando' AND empresa_id = ".$id_empresa;
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);

    foreach ($comprobantes_del_ruc as $comprobante) {

            // Sacamos la ruta del zip donde tenemos guardado el archivo para poder enviarselo a la ose
            $ticket = $comprobante['n_ticket'] ;
            $ruta_xml = $comprobante['ruta_xml'] ;
            $nombre_archivo = substr($ruta_xml, 24);
            $nombre_archivo = pathinfo($nombre_archivo)['filename'];

            $id_comprobantes = $comprobante["id"];


            $see = new See();

            // ---------------------------------------------------- ACA ES LA CONSULTA DEL TICKET ---------------------------------------------------
            // Con la ruta del xml podemos enviarlo.
            $usuario_sol = $datos_emisor["usuario_sol"];
            $clave_sol = $datos_emisor["clave_sol"];
            $see->setCertificate(file_get_contents($ruta_archivos.'/libreria/certificados/'.$ruc.'.pem'));
            $see->setService(SunatEndpoints::FE_PRODUCCION); // Cambiar la url para cuando sea Percepción/Retención
            $see->setClaveSOL($ruc, $usuario_sol, $clave_sol);
            // var_dump($ticket['ticket']);

            $statusResult = $see->getStatus($ticket);

            if (!$statusResult->isSuccess()) {
                // Si hubo error al conectarse al servicio de SUNAT.
                $ruta_cdr = "";
                $estado = 4;
                $mensaje_error = "No se pudo consultar el ticket";
                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
    
            }else{
        
                $ruta_cdr = $ruc.'/anulaciones/R-'.$nombre_archivo.'.zip';

                // Guardar CDR
                file_put_contents($ruta_archivos."/".$ruta_cdr, $statusResult->getCdrZip());
                $estado = 6;
                $mensaje_error = 'Anulacion Aceptada';


                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
    

            }

    }


}

?>