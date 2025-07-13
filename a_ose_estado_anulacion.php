<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__.'/libreria/vendor/autoload.php';


$ruta_archivos = dirname(__FILE__);
$_auth = new funciones_conexion;

// Consulta para saber cuales son los ruc que tienen factura sin enviar
$consulta_ruc = "SELECT e.ruc
                    FROM apifasdisafer_portal.comprobantes c
                    INNER JOIN apifasdisafer_portal.empresas e on  e.id = c.empresa_id
                    WHERE c.estado_id = 6 AND e.tipo_empresa_id = 1 AND c.mensaje_error = 'Anulando'
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


    $comprobantes_del_ruc = "SELECT id,ruta_xml,n_ticket
                                FROM apifasdisafer_portal.comprobantes 
                                WHERE estado_id = 6 AND mensaje_error = 'Anulando' AND empresa_id = ".$id_empresa;
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);

    foreach ($comprobantes_del_ruc as $comprobante) {

            // Sacamos la ruta del zip donde tenemos guardado el archivo para poder enviarselo a la ose
            $ticket = $comprobante['n_ticket'] ;

            $ruta_xml = $comprobante['ruta_xml'] ;
            $id_comprobantes = $comprobante["id"];
            $nombre_archivo = substr($ruta_xml, 24);
    
            // Crear el cliente SOAP
            $options = array(
                'trace' => 1,
                'exceptions' => true,
            );
            $client = new SoapClient($wsdl, $options);
            // Crear el encabezado de seguridad WSSE

            // Componer el cuerpo de la solicitud SOAP
            $soapBody = '
            <soapenv:Envelope
                xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/"
                xmlns:ser="http://service.sunat.gob.pe"
                xmlns:wsse="http://docs.oasisopen.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd">
                <soapenv:Header>
                    <wsse:Security>
                        <wsse:UsernameToken>
                            <wsse:Username>'. $ruc.$username .'</wsse:Username>
                            <wsse:Password>'. $password .'</wsse:Password>
                        </wsse:UsernameToken>
                    </wsse:Security>
                </soapenv:Header>
                <soapenv:Body>
                    <ser:getStatus>
                        <ticket>'.$ticket.'</ticket>
                    </ser:getStatus>
                </soapenv:Body>
            </soapenv:Envelope>';
            // Realizar la solicitud SOAP
            $response = $client->__doRequest($soapBody, $wsdl, '', SOAP_1_1);

            $cadena = $response;
            $palabra = "content";
            
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

                $mensaje_error = substr($response, $posiciones[0]+8, $posiciones[1]-$posiciones[0]-10);
                $ruta_cdr = "";
                $estado = 4;

                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);


            } else {

                $content = substr($response, $posiciones[0]+8, $posiciones[1]-$posiciones[0]-10);

                $decodedData = base64_decode($content);
                $ruta_cdr = $ruc.'/anulaciones/R-'.$nombre_archivo;
                // Crear y escribir el archivo ZIP
                file_put_contents($ruta_archivos.'/'.$ruta_cdr, $decodedData);
                $estado = 6;
                $mensaje_error = 'Anulacion Aceptada';


                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);

            }
    }


}

?>