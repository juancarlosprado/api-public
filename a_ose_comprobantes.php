<?php

require_once __DIR__."/funciones/funciones_conexion.php";
require_once __DIR__.'/libreria/vendor/autoload.php';


$ruta_archivos = dirname(__FILE__);
$_auth = new funciones_conexion;

// Consulta para saber cuales son los ruc que tienen factura sin enviar
$consulta_ruc = "SELECT e.ruc
                    FROM apifasdisafer_portal.comprobantes c
                    INNER JOIN apifasdisafer_portal.empresas e on  e.id = c.empresa_id
                    WHERE c.estado_id = 1 AND e.tipo_empresa_id = 1
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


    $comprobantes_del_ruc = "SELECT id, ruta_xml
                                 FROM apifasdisafer_portal.comprobantes 
                                 WHERE estado_id = 1 
                                 AND empresa_id = ".$id_empresa." 
                                 AND fecha_emision >= '2024-10-02 00:00:00' 
                                 LIMIT 80";
    $comprobantes_del_ruc = $_auth->query($comprobantes_del_ruc);

    foreach ($comprobantes_del_ruc as $comprobante) {

            // Sacamos la ruta del zip donde tenemos guardado el archivo para poder enviarselo a la ose
            $ruta_xml = $comprobante['ruta_xml'] ;
            $id_comprobantes = $comprobante["id"];
            $nombre_archivo = substr($ruta_xml, 12);

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
            <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ser="http://service.sunat.gob.pe">
                <soapenv:Header>' . $securityHeader . '</soapenv:Header>
                <soapenv:Body>
                    <ser:sendBill>
                        <fileName>'.$nombre_archivo .'</fileName>
                        <contentFile>' . $fileContent . '</contentFile>
                    </ser:sendBill>
                </soapenv:Body>
            </soapenv:Envelope>';
            // Realizar la solicitud SOAP
            $response = $client->__doRequest($soapBody, $wsdl, '', SOAP_1_1);
            
            $palabra_a_buscar = "faultstring";

            $posiciones = array();
            $posicion = strpos($response, $palabra_a_buscar);
            
            while ($posicion !== false) {
                $posiciones[] = $posicion;
                $posicion = strpos($response, $palabra_a_buscar, $posicion + 1);
            }
            if (count($posiciones)==0) {
                // Cargar el XML
                $xmlObject = new SimpleXMLElement($response);
                // Extraer el contenido de la etiqueta <applicationResponse>
                $applicationResponse = $xmlObject->xpath('//applicationResponse')[0];
                // Decodificar la cadena Base64
                $decodedData = base64_decode($applicationResponse);
                // Nombre del archivo ZIP a crear
                $ruta_cdr = $ruc.'/R-'.$nombre_archivo;
                $estado = 3;
                $mensaje_error = "";
                // Crear y escribir el archivo ZIP
                file_put_contents($ruta_archivos.'/'.$ruta_cdr, $decodedData);
                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
            }else{
                $mensaje_error = substr($response,$posiciones[0]+12, $posiciones[1]-$posiciones[0]-14);
                $estado = 4;
                $ruta_cdr = "";
                // Crear y escribir el archivo ZIP
                file_put_contents($ruta_archivos.'/'.$ruta_cdr, $decodedData);
                $_auth->actualizar_info_envios($id_empresa,$id_comprobantes,$estado,$mensaje_error,$ruta_cdr);
            }
    }


}

?>