<?php
defined('BASEPATH') or exit('No direct script access allowed');

class Verifacti_lib
{
    // La API externa mantiene el segmento 'verifactu' según la documentación oficial
    protected $api_url = 'https://api.verifacti.com/verifactu/';
    protected $token = false;
    protected $ci;

    public function __construct(){
        $this->ci = &get_instance();
    $this->ci->load->model([VERIFACTI_MODULE_NAME . '/verifacti_model']);
    $this->token = getVerifactiConfig('api_key');
    }

    // --- Generic Request Helpers ---
    private function request($endpoint, $method = 'GET', $data = [])
    {
        $url = $this->api_url.$endpoint;
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->token
        ];

        $logData = [
            'request_data' => json_encode($data),
            'endpoint' => $url,
            'created_at' => date('Y-m-d H:i:s')
        ];
    $v_log_id = $this->ci->verifacti_model->save('logs',$logData);

        try{
            $response = atCurlRequest($url,$method,json_encode($data),$headers);
            // Determinar éxito únicamente si HTTP 200
            $http = isset($response['http_code']) ? (int)$response['http_code'] : null;
            $success = ($http === 200);
            // Si la respuesta incluye campo 'error' o 'errors', forzar fallo
            if(isset($response['error']) || (isset($response['result']['errors']) && !empty($response['result']['errors']))){
                $success = false;
            }
            $response['success'] = $success;
            if(!$success && $http !== null && function_exists('log_message')){
                log_message('error','[Verifacti] HTTP '.$http.' fallo endpoint='.$endpoint.' body='.json_encode($data).' resp='.(isset($response['result'])?json_encode($response['result']):'null'));
            }
        }catch(\Exception $e){
            $response = [
                'success'=>FALSE,
                'http_code'=>0,
                'result'=>['message'=>$e->getMessage()]
            ];
            if(function_exists('log_message')){ log_message('error','[Verifacti] Excepción request endpoint='.$endpoint.' msg='.$e->getMessage()); }
        }
        $updateLogData = [
            'response_data'=>json_encode($response),
            'http_status'=> isset($response['http_code']) ? (int)$response['http_code'] : null,
            'updated_at'=>date('Y-m-d H:i:s')
        ];
    $this->ci->verifacti_model->save('logs',$updateLogData,['id'=>$v_log_id]);
        return $response;
    }
    /**
     * Check the Status of API Key
     * */
    public function check_health(){
        return $this->request('health','GET');
    }

    /***
     * Create Invioce
     * 
     * {
          "serie": "A",
          "numero": "234634",
          "fecha_expedicion": "02-12-2024",
          "tipo_factura": "F1",
          "descripcion": "Descripcion de la operacion",
          "nif": "A15022510",
          "nombre": "Empresa de prueba SL",
          "lineas": [
            {
              "base_imponible": "200",
              "tipo_impositivo": "21",
              "cuota_repercutida": "42"
            }
          ],
          "importe_total": "242.00"
        }
     * 
     * 
     * */
    public function create_invoice($invoice_data){
        return $this->request('create','POST',$invoice_data);
    }

    /**
     * Get Invoice Status
     * 
     * {
          "serie": "A",
          "numero": "234634",
          "fecha_expedicion": "02-12-2024",
          "fecha_operacion": "02-12-2024"
        }
     * */
    public function invoice_status($payload){
        return $this->request('status','POST',$payload);
    }
    /**
     * Update Invoice:
     * {
          "serie": "A",
          "numero": "234634",
          "fecha_expedicion": "02-12-2024",
          "tipo_factura": "F1",
          "descripcion": "Descripcion de la operacion",
          "nif": "A15022510",
          "nombre": "Empresa de prueba SL",
          "lineas": [
            {
              "base_imponible": "200",
              "tipo_impositivo": "21",
              "cuota_repercutida": "42"
            }
          ],
          "importe_total": "242.00"
        }
     * 
     * 
     * **/
    public function update_invoice($payload){
        return $this->request('modify','PUT',$payload);
    }

    /**
     * Consultar estado específico de una factura ya registrada en AEAT vía Verifacti.
     * Requiere: serie, numero, fecha_expedicion (dd-mm-YYYY) y opcional fecha_operacion.
     * Endpoint asumido según patrón documentación (ajustar si difiere en docs reales).
     */
    public function get_invoice_state($payload){
        return $this->request('invoice','POST',$payload); // Ajustar endpoint si la doc usa otro slug
    }

    /**
     * Listar facturas (último estado). Payload mínimo: ejercicio (YYYY), periodo (MM o código período).
     * Se pueden añadir filtros serie, numero, rango_fecha_expedicion, paginacion.
     */
    public function list_invoices($payload){
        return $this->request('invoices','POST',$payload); // Ajustar endpoint si la doc usa otro slug
    }

    /////////////////////////////////

    public function submit_tbai_invoice($invoice_data)
    {
        return $this->post('/alta-registro-tbai', $invoice_data);
    }
    public function get_invoice($id)
    {
        return $this->get('/alta-registro-facturacion/' . $id);
    }

    // --- Status & Batch (Envios) ---
    public function list_envios($filters = [])
    {
        return $this->get('/envios-aeat', true, $filters);
    }
    public function get_envio($id)
    {
        return $this->get('/envios-aeat/' . $id);
    }

    // --- Invoice Cancellation ---
    public function cancel_invoice($cancel_data)
    {
        // Endpoint actualizado según docs: POST verifactu/cancel
        return $this->request('cancel','POST',$cancel_data);
    }
    public function cancel_invoice_by_id($id)
    {
        // Si existiera cancelación por ID (no descrita en docs actuales) se podría implementar; placeholder
        return ['success'=>false,'message'=>'cancel_invoice_by_id no soportado'];
    }

    // --- Webhook Management ---
    public function create_webhook($data)
    {
        return $this->post('/webhook', $data);
    }
    public function list_webhooks()
    {
        return $this->get('/webhook');
    }
    public function get_webhook($id)
    {
        return $this->get('/webhook/' . $id);
    }

    // --- Event Log ---
    public function list_event_logs($filters = [])
    {
        return $this->get('/registro-eventos', true, $filters);
    }
    public function get_event_log($id)
    {
        return $this->get('/registro-eventos/' . $id);
    }

    // --- Health Check ---
    public function status()
    {
        return $this->get('/status', false);
    }

    // --- Utility: Clear cached token
    public function clear_token()
    {
        $CI =& get_instance();
    // Internamente usamos prefijo verifacti_ aunque la API siga usando rutas verifactu
    $CI->session->unset_userdata('verifacti_token');
        $this->token = $this->get_token();
        return $this->token;
    }
}
