<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__.'/libreria/vendor/autoload.php';

use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;

use Greenter\Ws\Services\SunatEndpoints;
use Greenter\See;

$ruta_archivos = dirname(__FILE__);
$_auth = new funciones_conexion;

date_default_timezone_set('America/Lima');
$fechaActual = date("Y-m-d");

// Consulta para saber cuales son los ruc que tienen factura sin enviar
$consulta_ruc = "SELECT e.ruc
                    FROM apifasdisafer_portal.comprobantes c
                    INNER JOIN apifasdisafer_portal.empresas e on  e.id = c.empresa_id
                    WHERE c.tipo = 3 AND c.estado_id = 1 AND e.tipo_empresa_id = 2 
                    GROUP BY empresa_id 
                    ORDER BY empresa_id DESC";
$consulta_ruc = $_auth->query($consulta_ruc);

foreach($consulta_ruc as $dato){

    $ruc = $dato['ruc'];
    $datos_emisor = $_auth->datos_empresa($ruc);
    $id_empresa = $datos_emisor['id'];

    $fecha_comprobantes = "SELECT DATE(fecha_emision) AS fecha 
                            FROM apifasdisafer_portal.comprobantes 
                            WHERE tipo = 3 AND estado_id = 1 AND empresa_id = ".$id_empresa." 
                            GROUP BY fecha";
    $fecha_comprobantes = $_auth->query($fecha_comprobantes);

    $numero_resumen = 30;
    foreach ($fecha_comprobantes as $fecha) {

            // Por fecha vamos generando los resumenes para que sea facil identificarlos y sobre todo para no tener mezcla de fechas
            $fecha = $fecha["fecha"];
            $fecha = substr($fecha, 0, 10);
            // La fecha de forma maxima y minima, es decir hasta el ultimo momento, en teoria esto se ejecutara a las 11:50 pm puesto que el envio se realizara unos minutos despues
            $fecha_inicial = $fecha." 00:00:00";
            $fecha_final = $fecha." 23:59:59";
            
            // Con la fecha en mano podemos generar el resumen, vamos a realizar pruebas de generacion del resumen.
            $see = new See();
            // Usando los datos del emisor que nos sirve para poder llamar al certificado y usarlo
            $usuario_sol = $datos_emisor["usuario_sol"];
            $clave_sol = $datos_emisor["clave_sol"];
            $see->setCertificate(file_get_contents($ruta_archivos.'/libreria/certificados/'.$ruc.'.pem'));
            $see->setService(SunatEndpoints::FE_PRODUCCION); // Cambiar la url para cuando sea Percepción/Retención
            $see->setClaveSOL($ruc, $usuario_sol, $clave_sol);

            // Agregamos los datos de la empresa
            $address = (new Address())
                ->setUbigueo($datos_emisor["ubigeo"])
                ->setDepartamento($datos_emisor["departamento"])
                ->setProvincia($datos_emisor["provincia"])
                ->setDistrito($datos_emisor["distrito"])
                ->setUrbanizacion('-')
                ->setDireccion($datos_emisor["direccion"])
                ->setCodLocal('0000');
            $company = (new Company())
                ->setRuc($datos_emisor["ruc"])
                ->setRazonSocial($datos_emisor["razon_social"])
                ->setNombreComercial($datos_emisor["nombre_comercial"])
                ->setAddress($address);  
            // Ponemos los detalles del resumen, la fecha es la que se genero el cpe y la fecha actual es la fecha con la cual hacemos el resumen
            $resumen = new Summary();
            $resumen->setFecGeneracion((new \DateTime($fecha))) // Fecha de emisión de las boletas.
                ->setFecResumen((new \DateTime($fechaActual))) // Fecha de envío del resumen diario.
                ->setCorrelativo($numero_resumen) // Correlativo, necesario para diferenciar de otros Resumen diario del mismo día.
                ->setCompany($company);

            $comprobante_fecha = "SELECT c.id,c.serie, c.correlativo,c.tipo_documento_cliente,c.documento_cliente,c.total, d.total_gravada,d.total_exonerada, d.total_inafecta, d.total_igv
                                    FROM apifasdisafer_portal.comprobantes c
                                    INNER JOIN apifasdisafer_portal.detalles d on  d.comprobante_id = c.id
                                    WHERE c.empresa_id =".$id_empresa." AND c.tipo = 3 AND c.estado_id = 1
                                            AND c.fecha_emision >= '$fecha_inicial' 
                                            AND c.fecha_emision <= '$fecha_final' ";                               
            $comprobante_fecha = $_auth->query($comprobante_fecha);
            
            $arrray_detalles =  array();
            foreach($comprobante_fecha as $comprobante){
                
                if($comprobante["total_inafecta"] == NULL){
                    $comprobante["total_inafecta"] = 0;
                }

                $detail = new SummaryDetail();
                $detail->setTipoDoc('03') // Nota de Credito
                    ->setSerieNro($comprobante["serie"]."-".$comprobante["correlativo"])
                    ->setEstado('1') // Emisión
                    ->setClienteTipo($comprobante["tipo_documento_cliente"]) // Tipo documento identidad: DNI
                    ->setClienteNro($comprobante["documento_cliente"]) // Nro de documento identidad
                    ->setTotal($comprobante["total"])
                    ->setMtoOperGravadas($comprobante["total_gravada"])
                    ->setMtoOperExoneradas($comprobante["total_exonerada"])
                    ->setMtoOperInafectas($comprobante["total_inafecta"])
                    ->setMtoIGV($comprobante["total_igv"])
                    ->setMtoISC(0);
                
                array_push($arrray_detalles,$detail);
            
            }

            // Agregamos los comprobantes y armamos un resumen diario, luego guardamos este XML
            $resumen->setDetails($arrray_detalles);   
            $xml = $see->getXmlSigned($resumen);
            $ruta_xml = $ruc. '/'.$resumen->getName().'.xml';
            file_put_contents($ruta_archivos."/".$ruta_xml, $xml);

            foreach($comprobante_fecha as $comprobante){

                $id_comprobante = $comprobante['id'];
                $estado = 3;
                $_auth->actualizar_estado_resumen($id_comprobante,$ruta_xml,$estado);
            
            }


            $numero_resumen = $numero_resumen+1;

    }


}

?>