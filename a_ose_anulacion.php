<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__.'/libreria/vendor/autoload.php';


$ruta_archivos = dirname(__FILE__);
$_auth = new funciones_conexion;

// Consulta para saber cuales son los ruc que tienen factura sin enviar
$consulta_ruc = "SELECT e.ruc
                    FROM apifasdisafer_portal.comprobantes c
                    INNER JOIN apifasdisafer_portal.empresas e on  e.id = c.empresa_id
                    WHERE c.estado_id = 5 AND e.tipo_empresa_id = 1
                    GROUP BY empresa_id";
$consulta_ruc = $_auth->query($consulta_ruc);

$wsdl = 'https://ose.nubefact.com/ol-ti-itcpe/billService?wsdl'; // Produccion
// $wsdl = 'https://demo-ose.nubefact.com/ol-ti-itcpe/billService?wsdl'; // Beta o DEMO

foreach($consulta_ruc as $dato){

    $ruc = $dato['ruc'];
    $datos_emisor = $_auth->datos_empresa_ose($ruc);
    $id_empresa = $datos_emisor['id'];
    
    $username = $datos_emisor["usuario_ose"]; // Usuario de autenticación
    $password = $datos_emisor["clave_ose"]; // Contraseña de autenticación


    $comprobantes_del_ruc = "SELECT id,ruta_xml
                                FROM apifasdisafer_portal.comprobantes 
                                WHERE estado_id = 5 AND empresa_id = ".$id_empresa;
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);

    foreach ($comprobantes_del_ruc as $comprobante) {

        $ruta_xml = $comprobante['ruta_xml'] ;
        $id_comprobantes = $comprobante["id"];
        $nombre_archivo = substr($ruta_xml, 24);

        // Contenido del archivo XML codificado en base64
        $fileContent = base64_encode(file_get_contents($ruta_archivos.'/'.$ruta_xml));
        // Crear el cliente SOAP
        $options = array(
            'trace' => 1,
            'exceptions' => true,
        );
        $client = new SoapClient($wsdl, $options);
        // Crear el encabezado de seguridad WSSE
        $securityHeader = '
        <wsse:Security xmlns:wsse="http://schemas.xmlsoap.org/ws/2002/12/secext">
            <wsse:UsernameToken>
                <wsse:Username>' . $ruc.$username . '</wsse:Username>
                <wsse:Password>' . $password . '</wsse:Password>
            </wsse:UsernameToken>
        </wsse:Security>';
        // Componer el cuerpo de la solicitud SOAP
        $soapBody = '
        <soapenv:Envelope
            xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
            xmlns:ser="http://service.sunat.gob.pe"
            xmlns:wsse="http://docs.oasisopen.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd"> 
        <soapenv:Header>' . $securityHeader . '</soapenv:Header>
            <soapenv:Body>
                <ser:sendSummary>
                    <fileName>'.$nombre_archivo .'</fileName>
                    <contentFile>' . $fileContent . '</contentFile>
                </ser:sendSummary>
            </soapenv:Body>
        </soapenv:Envelope>';
        // Realizar la solicitud SOAP
        $response = $client->__doRequest($soapBody, $wsdl, '', SOAP_1_1);
       
        // validamos que exista el ticket
        $cadena = $response;
        $palabra = "ticket";
        
        $posiciones = array();
        $pos = 0;       
        while (($pos = strpos($cadena, $palabra, $pos)) !== false) {
            $posiciones[] = $pos;
            $pos = $pos + strlen($palabra);
        }

        $datos = array();
        if (count($posiciones)< 1) {
            
            $palabra = "message";
            $posiciones = array();
            $pos = 0;
            while (($pos = strpos($cadena, $palabra, $pos)) !== false) {
                $posiciones[] = $pos;
                $pos = $pos + strlen($palabra);
            }
            $longitud_cadena_detalle = $posiciones[1]-$posiciones[0]-10;
            $mensaje_error = substr($response, $posiciones[0]+8, $longitud_cadena_detalle);

            $estado = 4;

            $sql = "UPDATE apifasdisafer_portal.comprobantes 
                    SET estado_id = ".$estado.", mensaje_error = '".$mensaje_error."'
                    WHERE ruta_xml = '".$ruta_xml."' AND empresa_id = ".$id_empresa;
            $consulta = $_auth->query($sql);
            if($consulta==null){
                $respuesta = "No se pudo actualizar el orden de anulacion";
                echo $respuesta;
                exit;
            }
        } else {

            $longitud_cadena_ticket = $posiciones[1]-$posiciones[0]-9;
            $ticket = substr($response, $posiciones[0]+7, $longitud_cadena_ticket);

            $estado = 6;

            $sql = "UPDATE apifasdisafer_portal.comprobantes 
                    SET estado_id = ".$estado.", n_ticket = '".$ticket."', mensaje_error = 'Anulando'
                    WHERE id = ".$id_comprobantes." AND empresa_id = ".$id_empresa;
            $consulta = $_auth->query($sql);
            if($consulta==null){
                $respuesta = "No se pudo actualizar el orden de anulacion";
                echo $respuesta;
                exit;
            }  

        }
    }


}

?>