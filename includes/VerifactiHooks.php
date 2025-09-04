<?php

class VerifactiHooks
{
    protected $ci;
    // Tipos soportados mínimos: F1 (factura normal), R1 (rectificativa / abono)
    const FACTURA_TIPO_INVOICE = 'F1';
    const FACTURA_TIPO_CREDIT  = 'R1';
    public function __construct(){
        $this->ci = &get_instance();
    $this->ci->load->model([VERIFACTI_MODULE_NAME . '/verifacti_model']);
    $this->ci->load->helper(VERIFACTI_MODULE_NAME . '/verifacti');
    $this->ci->load->library([VERIFACTI_MODULE_NAME . '/verifacti_lib']);
    }

    /**
     * Deriva la serie (incluyendo barra final si existe) y el número a partir del número formateado.
     * Ejemplos:
     *   INV-2025/123  => serie: "INV-2025/"  numero: "123"
     *   FAC-00045     => serie: "FAC-"       numero: "45"
     *   ABC2025/0007  => serie: "ABC2025/"   numero: "7"
     * Fallback: si no se puede parsear mantiene prefix y number originales.
     */
    protected function parseFormattedSerieNumero($formatted, $fallbackPrefix, $fallbackNumber){
        $serie = $fallbackPrefix; $numero = $fallbackNumber;
        if(is_string($formatted) && $formatted !== ''){
            if(strpos($formatted,'/') !== false){
                $pos = strpos($formatted,'/');
                $serie = substr($formatted,0,$pos+1); // incluye la barra
                $numero = substr($formatted,$pos+1);
                $numero = ltrim($numero,'0');
            }else{
                if(preg_match('/^([^0-9]*)([0-9].*)$/',$formatted,$m)){
                    $serie = $m[1];
                    $numero = ltrim($m[2],'0');
                }
            }
            if($numero === '' || $numero === null){ $numero = $fallbackNumber; }
        }
        return [$serie,$numero];
    }

    public function init(){
        
    hooks()->add_action('admin_init',[$this,'add_admin_menu']);
    // Fallback: revisar facturas marcadas como enviadas que no hayan sido sincronizadas
    hooks()->add_action('admin_init',[$this,'processMarkedAsSentFallback']);
    // Fallback adicional: revisar notas de crédito pendientes en cada carga admin
    hooks()->add_action('admin_init',[$this,'processPendingCreditNotes']);
    
    // Deshabilitar merge de facturas (cumplimiento España) – UI + servidor
    hooks()->add_action('app_admin_head',[$this,'injectDisableMergeInvoicesCss']);
    hooks()->add_action('pre_controller',[$this,'blockMergeInvoicesRequest']);
    
    // Validación: no permitir factura > 3000 sin NIF destinatario (obligatorio F1)
    hooks()->add_action('pre_controller',[$this,'enforceSimplifiedInvoiceLimit']);
    if(isVerifactiEnable()){
            // Eliminado envío automático al crear/actualizar: ahora solo al enviar o marcar como enviada.
            // hooks()->add_action('after_invoice_added',[$this,'afterInvoiceCreated']);
            hooks()->add_action('invoice_updated',[$this,'afterInvoiceUpdated']);
            hooks()->add_action('after_total_summary_invoicehtml',[$this,'afterInvoiceLeftHtml']);
            // Activamos también el hook base invoice_pdf_info (algunas plantillas solo usan este)
            hooks()->add_filter('invoice_pdf_info',[$this,'afterInvoicePdfInfo'],10,2);
            // 

            hooks()->add_action('invoice_object_before_send_to_client',[$this,'beforeInvoiceSentClient']);
            hooks()->add_action('created_credit_note_from_invoice',[$this,'afterCreditNoteInvoiceCreated']);
            // Marcar factura como enviada manualmente
            hooks()->add_action('invoice_marked_as_sent',[$this,'onInvoiceMarkedAsSent']);

            // Hooks alternativos que dispara Perfex al enviar factura (incluye cron / send later)
            $possibleSendHooks = [
                'after_invoice_sent',          // nombre común en versiones recientes
                'invoice_sent_to_client',      // posible variante
                'invoice_email_sent',          // fallback
            ];
            foreach($possibleSendHooks as $hk){
                // Registrar sin comprobar existencia; Perfex ignora si no se usa
                hooks()->add_action($hk,[$this,'onInvoiceEmailSent']);
            }

            // Hooks potenciales de cancelación de factura (dependen versión Perfex)
            $possibleCancelHooks = [
                'invoice_marked_as_cancelled',
                'invoice_cancelled',
                'after_invoice_cancelled',
                'after_invoice_status_changed', // recibirá data con invoice_id y status tal vez
            ];
            foreach($possibleCancelHooks as $hk){
                hooks()->add_action($hk,[$this,'onInvoicePossiblyCancelled']);
            }

            // Fallback: tras ejecución del cron procesar facturas pendientes (send later)
            hooks()->add_action('after_cron_run',[$this,'processPendingScheduledInvoices']);
            hooks()->add_action('after_cron_run',[$this,'processPendingCreditNotes']);

            if(!defined('VERIFACTI_CREDIT_HOOKS_REGISTERED')){
                define('VERIFACTI_CREDIT_HOOKS_REGISTERED', true);
                // Hooks reales comprobados (variaciones según versión Perfex)
                $creditHooks = [
                    'after_create_credit_note' => 'onCreditNoteCreated',
                    'after_credit_note_added' => 'onCreditNoteCreated', // variante común
                    'after_update_credit_note' => 'onCreditNoteUpdated',
                    'after_credit_note_updated' => 'onCreditNoteUpdated', // variante
                    'created_credit_note_from_invoice' => 'afterCreditNoteInvoiceCreated',
                    'credit_note_sent' => 'onCreditNoteSent',
                    'credit_note_marked_as_sent' => 'onCreditNoteSent', // posible variante
                    'credit_note_status_changed' => 'onCreditNoteStatusChanged'
                ];
                foreach($creditHooks as $hk=>$cb){ hooks()->add_action($hk,[$this,$cb]); }
            }
            
            // LLAMADA A CUSTOM HOOKS

            // Renderizar campos personalizados de Verifacti en el email
            hooks()->add_action('after_email_fields_invoice_send_to_client',[$this,'renderVerifactiFields']);

            // Posición del QR en la factura en PDF
            hooks()->add_filter('invoice_pdf_header_after_custom_fields',[$this,'afterInvoicePdfInfo'],10,2);

            // Posición del QR en la nota de crédito en PDF
            hooks()->add_filter('invoice_pdf_footer_after_custom_fields',[$this,'afterCreditNotePdfInfo'],10,2);

        }

        // hooks()->add_filter('pdf_logo_url',[$this,'addQrCodeImage']);
    }
    /**
     * Bloquea guardado de factura sin NIF destinatario cuando total > 3000 (no puede ser simplificada F2 por normativa umbral).
     * Se ejecuta en pre_controller antes de que el controlador procese la creación/edición.
     */
    public function enforceSimplifiedInvoiceLimit(){
        // Sólo en área admin y rutas de invoices
        $segments = $this->ci->uri->segment_array();
        if(!in_array('invoices',$segments)) return;
        if(strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
        // Detectar acción de guardar (Perfex suele tener 'save_invoice' o presence de newitems/clientid)
        $post = $this->ci->input->post();
        if(!$post) return;
        $clientId = isset($post['clientid']) ? (int)$post['clientid'] : 0;
        if(!$clientId) return; // sin cliente no validamos todavía
        // Obtener total posteado (Perfex suele mandar hidden 'total')
        $postedTotal = null;
        if(isset($post['total'])){ $postedTotal = (float)$post['total']; }
        elseif(isset($post['subtotal'])){ $postedTotal = (float)$post['subtotal']; }
        // Si no hay total, intentar estimar por items
        if($postedTotal === null && isset($post['newitems']) && is_array($post['newitems'])){
            $sum = 0.0;
            foreach($post['newitems'] as $it){
                if(!is_array($it)) continue; $qty = (float)($it['qty'] ?? 1); $rate = (float)($it['rate'] ?? 0); $sum += $qty*$rate;
            }
            $postedTotal = $sum; // sin impuestos (aprox). Conservador: podría subestimar.
        }
        if($postedTotal === null) return; // no podemos validar
        $threshold = defined('VERIFACTI_F2_MAX_TOTAL') ? VERIFACTI_F2_MAX_TOTAL : 3000;
        if($postedTotal <= $threshold) return; // no supera umbral
        // Cargar cliente y validar VAT
        try{ if(!isset($this->ci->clients_model)){ $this->ci->load->model('clients_model'); } }catch(\Exception $e){ return; }
        $clientRow = $this->ci->clients_model->get($clientId);
        if(!$clientRow) return;
        $vat = trim((string)($clientRow->vat ?? ''));
        $vatValid = ($vat !== '' && preg_match('/^[A-Z0-9]{5,}$/i',$vat));
        if($vatValid) return; // todo correcto
        // Bloquear
        if(function_exists('log_message')){ log_message('error','[Verifacti] Bloqueada factura > '.$threshold.' sin NIF destinatario clientid='.$clientId.' total='.$postedTotal); }
        if(!function_exists('set_alert')){ show_error('No se puede guardar la factura: importe > '.$threshold.'€ y el destinatario no tiene NIF.',400,'Validación Verifacti'); }
        set_alert('warning','No se puede guardar la factura: importe > '.$threshold.'€ y el destinatario no tiene NIF. Añade el NIF antes de guardar.');
        // Redirigir atrás (a formulario). Intentar referer, si no lista de facturas.
        $ref = $_SERVER['HTTP_REFERER'] ?? admin_url('invoices/invoice');
        redirect($ref); exit;
    }

    /**
     * Cancelar factura registrada.
     */
    public function cancelInvoice($invoice_id, $reason='Anulación administrativa'){
        $this->ensureInvoiceSchema();
        $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ]);
        if(!$row || empty($row->verifacti_id)){
            return ['success'=>false,'message'=>'Factura no registrada aún'];
        }
        if(!empty($row->canceled_at)){
            return ['success'=>false,'message'=>'Factura ya cancelada'];
        }
        $invoice = $this->ci->invoices_model->get($invoice_id);
        if(!$invoice){ return ['success'=>false,'message'=>'Factura no encontrada']; }
        $formatted_cancel = function_exists('format_invoice_number') ? format_invoice_number($invoice->id) : ($invoice->prefix.$invoice->number);
        list($serie_canc,$numero_canc) = $this->parseFormattedSerieNumero($formatted_cancel,$invoice->prefix,$invoice->number);
        $payload = [
            'serie' => $serie_canc,
            'numero' => $numero_canc,
            'fecha_expedicion' => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated)),
            // Campos de control según nueva especificación
            'rechazo_previo' => 'N',
            'sin_registro_previo' => 'N',
            'incidencia' => 'N',
            // Motivo interno que guardamos (no especificado obligatorio en API, pero útil para auditoría local)
            'motivo' => $reason,
        ];
        $response = $this->ci->verifacti_lib->cancel_invoice($payload);
        $success = isset($response['success']) ? (bool)$response['success'] : (!isset($response['error']));
        $update = [
            'updated_at' => date('Y-m-d H:i:s'),
            'cancel_reason' => $reason,
            'cancel_response' => json_encode($response)
        ];
        if($success){
            $update['canceled_at'] = date('Y-m-d H:i:s');
            $update['status'] = 'Cancelado';
        }
        $this->ci->verifacti_model->save('invoices',$update,['id'=>$row->id]);
        return ['success'=>$success,'response'=>$response];
    }

    public function forceCancelInvoice($invoice_id, $reason='Anulación administrativa'){
        return $this->cancelInvoice($invoice_id,$reason);
    }

    public function injectDisableMergeInvoicesCss(){
        echo '<style>'
            . '.mergeable-invoices,'
            . 'li.merge-invoice-action,'
            . '.btn-merge-invoice,'
            . '.dropdown-menu a[href*="merge" i]{display:none !important;}'
            . '</style>';
    }
    /**
     * Bloquea cualquier intento de acceder al endpoint de merge de facturas.
     * Si el usuario intenta forzar la URL, devolvemos 403.
     */
    public function blockMergeInvoicesRequest(){
        $segments = $this->ci->uri->segment_array();
        // Buscamos patrón /invoices/merge o /invoices/{id}/merge
        $foundInvoices = in_array('invoices',$segments);
        $foundMerge = in_array('merge',$segments) || in_array('mergeinvoices',$segments);
        if($foundInvoices && $foundMerge){
            // Registrar intento (simple log en error_log; ideal: tabla dedicada)
            if(function_exists('log_message')){ log_message('error','Intento bloqueado de fusionar facturas. URI: '.current_url()); }
            show_error('La función de fusionar facturas está deshabilitada por normativa española (integración Verifacti).',403,'Acción no permitida');
        }
    }
    public function renderVerifactiFields($invoice){
        if(defined('VERIFACTI_MANUAL_SEND_ENABLED') && VERIFACTI_MANUAL_SEND_ENABLED){
            $trans = _l('verifacti_invoice_send_to_api');
            $html = <<<HTML
            <div class="checkbox checkbox-primary">
                <input type="checkbox" name="send_to_verifacti" id="send_to_verifacti" checked>
                <label for="send_to_verifacti">$trans</label>
            </div>
            <hr/>
            HTML;
            echo $html;
        }
    }
    public function afterInvoicePdfInfo($invoice_info, $invoice){
        // No mostrar QR si la fecha de la factura es anterior a la fecha de inicio configurada
        $fecha_inicio = get_option('verifacti_start_date');
        $fecha_factura = !empty($invoice->date) ? date('Y-m-d', strtotime($invoice->date)) : date('Y-m-d', strtotime($invoice->datecreated));
        if($fecha_inicio && $fecha_factura < $fecha_inicio){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] QR oculto: fecha factura '.$fecha_factura.' < inicio '.$fecha_inicio.' (PDF)'); }
            return $invoice_info;
        }
        $invoice_id = $invoice->id;
        // Retardo explícito de la generación del PDF si se solicita (caso Save & Send)
        if(defined('VERIFACTI_FORCE_PDF_DELAY_SECONDS') && VERIFACTI_FORCE_PDF_DELAY_SECONDS > 0){
            static $verifacti_pdf_global_delay_done = [];
            if(empty($verifacti_pdf_global_delay_done[$invoice_id])){
                $verifacti_pdf_global_delay_done[$invoice_id] = true;
                $delayPdf = (int)VERIFACTI_FORCE_PDF_DELAY_SECONDS;
                if(function_exists('log_message')){ log_message('debug','[Verifacti] PDF delay inicial de '.$delayPdf.'s invoice_id='.$invoice_id); }
                sleep($delayPdf);
            }
        }
        $verifacti_invoice = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ],['column'=>'id','order'=>'DESC']);
        // Fallback crítico: si aún no existe registro (ej. Save & Send genera PDF antes del hook before_send) lo forzamos ahora
        if(!$verifacti_invoice){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoicePdfInfo: sin fila previa, se fuerza sendInvoiceVerifacti ID='.$invoice_id); }
            $this->sendInvoiceVerifacti($invoice_id,'pdf_hook_auto',false);
            // Breve pausa opcional tras creación para permitir respuesta de API (configurable)
            $microDelay = defined('VERIFACTI_PDF_FORCE_SEND_MICRO_DELAY') ? (float)VERIFACTI_PDF_FORCE_SEND_MICRO_DELAY : 0.8; // segundos
            if($microDelay > 0){ usleep((int)($microDelay*1000000)); }
            $verifacti_invoice = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ],['column'=>'id','order'=>'DESC']);
        }
        if($verifacti_invoice){
            // Espera específica para escenarios donde el pre-hook no ejecutó (save & send / cron auto)
            if(empty($verifacti_invoice->qr_image_base64) && !empty($verifacti_invoice->verifacti_id)){
                static $verifacti_pdf_wait_done = false;
                if(!$verifacti_pdf_wait_done){
                    $verifacti_pdf_wait_done = true;
                    if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoicePdfInfo: inicio espera QR inline ID='.$invoice_id); }
                    // Reutiliza waitForQr pero con ventana configurable (aumentamos defecto para escenarios lentos)
                    $maxPdfWait = defined('VERIFACTI_PDF_QR_WAIT_SECONDS') ? VERIFACTI_PDF_QR_WAIT_SECONDS : 12; // segundos
                    // Intervalo de polling corto (por defecto 0.6s). Antes estaba a 6s lo que ocasionaba un único intento.
                    $intervalPdf = defined('VERIFACTI_PDF_QR_WAIT_INTERVAL') ? VERIFACTI_PDF_QR_WAIT_INTERVAL : 0.6; // segundos
                    $this->waitForQr($invoice, $maxPdfWait, $intervalPdf);
                    // Refrescar fila tras espera
                    $verifacti_invoice = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ],['column'=>'id','order'=>'DESC']);
                }
            }
            if(empty($verifacti_invoice->qr_image_base64) && !empty($verifacti_invoice->verifacti_id)){
                try{
                    $formatted_pdf = function_exists('format_invoice_number') ? format_invoice_number($invoice->id) : ($invoice->prefix.$invoice->number);
                    list($serie_pdf,$numero_pdf) = $this->parseFormattedSerieNumero($formatted_pdf,$invoice->prefix,$invoice->number);
                    $payloadStatus = [
                        'serie' => $serie_pdf,
                        'numero' => $numero_pdf,
                        'fecha_expedicion' => !empty($invoice->date)?date('d-m-Y',strtotime($invoice->date)):date('d-m-Y',strtotime($invoice->datecreated)),
                        'fecha_operacion' => !empty($invoice->date)?date('d-m-Y',strtotime($invoice->date)):date('d-m-Y',strtotime($invoice->datecreated))
                    ];
                    $resp = $this->ci->verifacti_lib->invoice_status($payloadStatus);
                    if(isset($resp['success']) && $resp['success'] && isset($resp['result']['qr']) && isset($resp['result']['url'])){
                        $this->ci->verifacti_model->save('invoices',[
                            'qr_url' => $resp['result']['url'],
                            'qr_image_base64' => $resp['result']['qr'],
                            'status' => $resp['result']['estado'] ?? ($verifacti_invoice->status?:'desconocido'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ],['id'=>$verifacti_invoice->id]);
                        $verifacti_invoice = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ]);
                    }
                }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoicePdfInfo status error: '.$e->getMessage()); } }
            }
            static $verifacti_qr_injected = false;
            if(!$verifacti_qr_injected && !empty($verifacti_invoice->qr_image_base64)){
                $qrBlock = generateVerifactiQr($verifacti_invoice);
                $inserted = false;
                if(!$inserted){ $invoice_info .= $qrBlock; }
                $verifacti_qr_injected = true;
            }
        }
        return $invoice_info;
    }
    public function afterCreditNotePdfInfo($credit_note_info, $credit_note){
        $id = isset($credit_note->id)?$credit_note->id:null;
        if(!$id){ return $credit_note_info; }
        // No mostrar QR si la fecha de la nota es anterior a la fecha de inicio configurada
        $fecha_inicio = get_option('verifacti_start_date');
        $fecha_nota = !empty($credit_note->date) ? date('Y-m-d', strtotime($credit_note->date)) : date('Y-m-d');
        if($fecha_inicio && $fecha_nota < $fecha_inicio){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] QR oculto: fecha nota '.$fecha_nota.' < inicio '.$fecha_inicio.' (PDF CN)'); }
            return $credit_note_info;
        }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] afterCreditNotePdfInfo hook ejecutado para CN ID='.$id); }
        $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$id ],['column'=>'id','order'=>'DESC']);
        if(!$row){ if(function_exists('log_message')){ log_message('debug','[Verifacti] afterCreditNotePdfInfo sin fila verifacti credit_note_id='.$id); } return $credit_note_info; }
        if(empty($row->qr_image_base64)){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] afterCreditNotePdfInfo sin QR aún credit_note_id='.$id); }
            return $credit_note_info;
        }
        $credit_note_info .= generateVerifactiQr($row);
        return $credit_note_info;
    }

    public function afterCreditNoteInvoiceCreated($params){
        // Hook nativo Perfex normalmente pasa credit_note_id y invoice_id originales.
        $credit_note_id = $params['credit_note_id'] ?? ($params['id'] ?? null);
        if($credit_note_id){
            $this->sendCreditNoteVerifacti($credit_note_id, $params['invoice_id'] ?? null);
        }
    }
    public function beforeInvoiceSentClient($invoice){
        // Si se está programando para enviar más tarde, NO enviamos ahora.
        $sendLaterFlags = ['send_later','schedule_later','scheduled_send','scheduled_email'];
        foreach($sendLaterFlags as $flag){
            $val = $this->ci->input->post($flag);
            if(!empty($val)){
                return $invoice; // se enviará cuando Perfex realice el envío real (hook de marcado como enviada)
            }
        }
            // Eliminado forzado de envío para drafts: nunca enviar borradores
            if(isset($invoice->status)){
                $st = $invoice->status;
                if($st === 'draft' || $st === 'Draft' || (is_numeric($st) && (int)$st === 6)){
                    if(function_exists('log_message')){ log_message('debug','[Verifacti] beforeInvoiceSentClient: abort envío email (draft) ID='.$invoice->id); }
                    return $invoice; // no continúa
                }
            }
        // Determinar si se debe enviar (modo manual o automático)
        $send_to_verifacti = defined('VERIFACTI_MANUAL_SEND_ENABLED') && VERIFACTI_MANUAL_SEND_ENABLED
            ? ($this->ci->input->post('send_to_verifacti') ?? false)
            : true;
        if($send_to_verifacti){
                $this->sendInvoiceVerifacti($invoice->id,'before_send_email',false);
            // Verificar si ya existe registro con QR antes de esperar
            $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice->id ]);
            if($row && !empty($row->qr_image_base64)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] beforeInvoiceSentClient: QR ya disponible sin espera ID='.$invoice->id); }
            }else{
                $this->waitForQr($invoice, defined('VERIFACTI_QR_WAIT_SECONDS') ? VERIFACTI_QR_WAIT_SECONDS : 18, 0.8);
            }
        }
        // Pausa explícita para asegurar que el QR (si acaba de llegar) pueda ser leído por los filtros PDF antes de generar el documento
        $delay = defined('VERIFACTI_EMAIL_SEND_DELAY_SECONDS') ? (int)VERIFACTI_EMAIL_SEND_DELAY_SECONDS : 15;
        if($delay > 0){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] beforeInvoiceSentClient: sleep de '.$delay.'s antes de generar email ID='.$invoice->id); }
            sleep($delay);
        }
        return $invoice;
    }

    /**
     * Espera sin bloquear excesivamente hasta que exista QR para la factura.
     * Usa polling local y remoto (invoice_status) si ya hay verifacti_id pero falta QR.
     * @param object $invoice
     * @param int $maxSeconds
     * @param float $intervalSeconds
     */
    protected function waitForQr($invoice, $maxSeconds=15, $intervalSeconds=0.7){
        $start = microtime(true);
        $invoice_id = $invoice->id;
        if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQr inicio ID='.$invoice_id.' max='.$maxSeconds.'s'); }
        $attempt = 0;
        while( (microtime(true)-$start) < $maxSeconds ){
            $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ]);
            if($row && !empty($row->qr_image_base64)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQr QR disponible tras '.round(microtime(true)-$start,2).'s intento='.$attempt); }
                return true;
            }
            // Si hay verifacti_id pero no QR, intentar primero record_status por UUID de registro si lo tenemos
            if($row && !empty($row->verifacti_id)){
                try{
                    if(!empty($row->estado_api_uuid)){
                        $rec = $this->ci->verifacti_lib->record_status($row->estado_api_uuid);
                        if(isset($rec['success']) && $rec['success'] && isset($rec['result']['qr']) && isset($rec['result']['url'])){
                            $this->ci->verifacti_model->save('invoices',[
                                'qr_url' => $rec['result']['url'],
                                'qr_image_base64' => $rec['result']['qr'],
                                'status' => $rec['result']['estado'] ?? ($row->status?:'desconocido'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ],['id'=>$row->id]);
                            if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQr QR via record_status intento='.$attempt); }
                            return true;
                        }
                    }
                    $formatted_wait = function_exists('format_invoice_number') ? format_invoice_number($invoice->id) : ($invoice->prefix.$invoice->number);
                    list($serie_w,$numero_w) = $this->parseFormattedSerieNumero($formatted_wait,$invoice->prefix,$invoice->number);
                    $payloadStatus = [
                        'serie' => $serie_w,
                        'numero' => $numero_w,
                        'fecha_expedicion' => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated)),
                        'fecha_operacion' => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated))
                    ];
                    $resp = $this->ci->verifacti_lib->invoice_status($payloadStatus);
                    if(isset($resp['success']) && $resp['success'] && isset($resp['result'])){
                        $res = $resp['result'];
                        if(!empty($res['qr']) && !empty($res['url'])){
                            $this->ci->verifacti_model->save('invoices',[
                                'qr_url' => $res['url'],
                                'qr_image_base64' => $res['qr'],
                                'status' => $res['estado'] ?? ($row->status?:'desconocido'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ],['id'=>$row->id]);
                            if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQr QR obtenido via status intento='.$attempt); }
                            return true;
                        }
                    }
                }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQr status error: '.$e->getMessage()); } }
            }
            $attempt++;
            usleep((int)($intervalSeconds*1000000));
        }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQr agotado sin QR ID='.$invoice_id); }
        return false;
    }
    protected function waitForQrCredit($credit, $maxSeconds=15, $intervalSeconds=0.7){
        $start = microtime(true);
        $credit_id = $credit->id;
        if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQrCredit inicio ID='.$credit_id.' max='.$maxSeconds.'s'); }
        $attempt = 0;
        while( (microtime(true)-$start) < $maxSeconds ){
            $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$credit_id ]);
            if($row && !empty($row->qr_image_base64)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQrCredit QR disponible tras '.round(microtime(true)-$start,2).'s intento='.$attempt); }
                return true;
            }
            if($row && !empty($row->verifacti_id)){
                try{
                    $formatted_cw = function_exists('format_credit_note_number') ? format_credit_note_number($credit->id) : ($credit->prefix.$credit->number);
                    list($serie_cw,$numero_cw) = $this->parseFormattedSerieNumero($formatted_cw,$credit->prefix,$credit->number);
                    $payloadStatus = [
                        'serie' => $serie_cw,
                        'numero' => $numero_cw,
                        'fecha_expedicion' => !empty($credit->date) ? date('d-m-Y', strtotime($credit->date)) : date('d-m-Y')
                    ];
                    $resp = $this->ci->verifacti_lib->invoice_status($payloadStatus);
                    if(isset($resp['success']) && $resp['success'] && isset($resp['result'])){
                        $res = $resp['result'];
                        if(!empty($res['qr']) && !empty($res['url'])){
                            $this->ci->verifacti_model->save('invoices',[
                                'qr_url' => $res['url'],
                                'qr_image_base64' => $res['qr'],
                                'status' => $res['estado'] ?? ($row->status?:'desconocido'),
                                'updated_at' => date('Y-m-d H:i:s')
                            ],['id'=>$row->id]);
                            if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQrCredit QR obtenido via status intento='.$attempt); }
                            return true;
                        }
                    }
                }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQrCredit status error: '.$e->getMessage()); } }
            }
            $attempt++;
            usleep((int)($intervalSeconds*1000000));
        }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] waitForQrCredit agotado sin QR ID='.$credit_id); }
        return false;
    }
    
    public function afterInvoiceLeftHtml($invoice){
        $invoice_id = $invoice->id;
        $fecha_inicio = get_option('verifacti_start_date');
        $fecha_factura = !empty($invoice->date) ? date('Y-m-d', strtotime($invoice->date)) : date('Y-m-d', strtotime($invoice->datecreated));
        if($fecha_inicio && $fecha_factura < $fecha_inicio){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] QR oculto: fecha factura '.$fecha_factura.' < inicio '.$fecha_inicio.' (web)'); }
            return;
        }
        $verifacti_invoice = $this->ci->verifacti_model->get("invoices",['single'=>true,'invoice_id'=>$invoice_id],
            ['column'=>'id','order'=>'DESC']);
        if($verifacti_invoice){
            echo generateVerifactiQr($verifacti_invoice);
        }
    }

    // Crear aquí public function afterCreditNoteLeftHtml($credit_note)

    // Métodos legacy (desactivados) mantenidos por compatibilidad futura
    public function afterInvoiceCreated($invoice_id){ /* envío automático deshabilitado */ }
    public function afterInvoiceUpdated($updateData){
        // Solo actuamos cuando se marca como enviada (sent -> 1) y no es draft
        if(empty($updateData['id'])){ return; }
        if(function_exists('isVerifactiEnable') && !isVerifactiEnable()){ return; }
        if(isset($updateData['sent']) && (int)$updateData['sent'] === 1){
            $invoice = $this->ci->invoices_model->get($updateData['id']);
            if($invoice && isset($invoice->status)){
                $st = $invoice->status;
                if($st === 'draft' || $st === 'Draft' || (is_numeric($st) && (int)$st === 6)){
                    return; // nunca enviar borradores
                }
                // Respeto de start_date: si la fecha es anterior no enviamos
                if(isset($invoice->date) && function_exists('verifacti_is_before_start') && verifacti_is_before_start($invoice->date)){
                    if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoiceUpdated: cancelado por start_date ('.$invoice->date.') ID='.$invoice->id); }
                    return;
                }
            }
            if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoiceUpdated detectó sent=1 para factura ID '.$updateData['id']); }
            $this->sendInvoiceVerifacti($updateData['id'],'after_invoice_updated');
        }
    }
    protected function sendInvoiceVerifacti($invoice_id,$source=null,$force=false){
        if(!$invoice_id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: invoice_id vacío source=' . ($source?:'n/a')); } return; }
        if(!function_exists('isVerifactiEnable') || !isVerifactiEnable()){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: módulo deshabilitado ID='.$invoice_id); } return; }
        // Asegurar que las columnas necesarias existen (en caso de que install.php no se haya re-ejecutado)
        $this->ensureInvoiceSchema();
        $invoice = $this->ci->invoices_model->get($invoice_id);
        if(!$invoice){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: factura no encontrada ID='.$invoice_id.' source='.$source); } return; }
        // Bloqueo por fecha de inicio del módulo: no enviar facturas anteriores
        if(isset($invoice->date) && function_exists('verifacti_is_before_start') && verifacti_is_before_start($invoice->date)){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: cancelado por start_date ('.$invoice->date.') ID='.$invoice_id.' source='.$source); }
            return;
        }
    // (Fix #6) Eliminado filtro que bloqueaba facturas con fecha anterior. Permitimos envío atrasado.
        // No enviar nunca facturas en borrador (draft) independientemente del parámetro force.
        if(isset($invoice->status)){
            $st = $invoice->status;
            if($st === 'draft' || $st === 'Draft' || (is_numeric($st) && (int)$st === 6)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: bloqueada (draft) ID='.$invoice_id.' source='.$source); }
                return;
            }
        }
        // Bloqueo adicional: si el número formateado contiene DRAFT / INV-DRAFT, nunca enviar.
        if(function_exists('format_invoice_number')){
            $formatted_number = format_invoice_number($invoice_id);
            if(stripos($formatted_number,'DRAFT') !== false){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: bloqueada por formatted_number con DRAFT ID='.$invoice_id.' number='.$formatted_number); }
                return;
            }
        }
        // Evitar reenvío si ya tenemos verifacti_id o estado Registrado
        $existing = $this->ci->verifacti_model->get('invoices',['single'=>true,'invoice_id'=>$invoice_id]);
        if($existing && ($existing->verifacti_id || $existing->status === 'Registrado')){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: ya enviado/verificado ID='.$invoice_id.' source='.$source); } return; // ya enviado
        }
        // Pre-chequeo remoto opcional: consultar /status para detectar duplicado antes de crear.
        // Desactivado por defecto para evitar 404 de facturas aún no registradas.
        if(defined('VERIFACTI_ENABLE_PRECHECK') && VERIFACTI_ENABLE_PRECHECK){
            try{
                $formatted_initial = function_exists('format_invoice_number') ? format_invoice_number($invoice->id) : ($invoice->prefix.$invoice->number);
                list($serie_check,$numero_check) = $this->parseFormattedSerieNumero($formatted_initial,$invoice->prefix,$invoice->number);
                $statePayload = [
                    'serie' => $serie_check,
                    'numero' => $numero_check,
                    'fecha_expedicion' => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated))
                ];
                $remoteState = $this->ci->verifacti_lib->get_invoice_state($statePayload);
                if(isset($remoteState['success']) && $remoteState['success'] && !empty($remoteState['result'])){
                    if(!$existing){
                        $this->ci->verifacti_model->save('invoices',[
                            'invoice_id' => $invoice_id,
                            'verifacti_id' => $remoteState['result']['uuid'] ?? null,
                            'status' => $remoteState['result']['estado'] ?? 'Registrado',
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: detectada ya registrada (pre-check) ID='.$invoice_id); }
                    return;
                }
            }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] pre-check remoto (opcional) falló ID='.$invoice_id.' msg='.$e->getMessage()); } }
        }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: preparando envío ID='.$invoice_id.' source='.$source); }
        $client = $invoice->client;
        $invoice_items = isset($invoice->items) ? $invoice->items : [];
        // Fallback: si viene vacío intentar cargar vía helper genérico
        if((!is_array($invoice_items) || count($invoice_items)==0) && function_exists('get_items_by_type')){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: items vacíos, usando fallback get_items_by_type invoice ID='.$invoice_id); }
            $invoice_items = get_items_by_type('invoice',$invoice_id);
        }
        // Normalizar a array indexado simple
        if(is_object($invoice_items)) { $invoice_items = (array)$invoice_items; }
        // Si es asociativo (clave => item) extraer valores
        if(!empty($invoice_items) && array_keys($invoice_items)!==range(0,count($invoice_items)-1)){
            $invoice_items = array_values($invoice_items);
        }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: total items='.count($invoice_items)); }

        // === DESTINATARIO (Fix destinatario equivocado) ===
        $recipient_nif = '';
        $recipient_name = '';
        $recipient_id_otro = null; // usaremos array sólo si extranjero
        $is_foreign = false;
        $is_eu = false;
        $countryIso = 'ES';
        // Lista de países UE (códigos ISO2)
        $eu_iso = ['AT','BE','BG','CY','CZ','DE','DK','EE','ES','FI','FR','GR','HR','HU','IE','IT','LT','LU','LV','MT','NL','PL','PT','RO','SE','SI','SK'];
        try{
            if(!isset($this->ci->clients_model)){
                $this->ci->load->model('clients_model');
            }
            if(isset($invoice->clientid) && $invoice->clientid){
                $clientRow = $this->ci->clients_model->get($invoice->clientid);
                if($clientRow){
                    $country_id = isset($clientRow->country) ? (int)$clientRow->country : null;
                    if($country_id){
                        try{
                            $rowC = $this->ci->db->where('country_id',$country_id)->get(db_prefix().'countries')->row();
                            if($rowC && isset($rowC->iso2)){ $countryIso = strtoupper($rowC->iso2); }
                        }catch(\Exception $e){}
                    }
                    $is_foreign = ($countryIso !== 'ES');
                    $is_eu = in_array($countryIso,$eu_iso,true) && $countryIso!=='ES';
                    if(!empty($clientRow->vat)){
                        $rawVat = strtoupper(trim($clientRow->vat));
                        $rawVat = preg_replace('/\s+/','',$rawVat);
                        // Si empieza por ES quitar prefijo país para NIF
                        if(strpos($rawVat,'ES')===0){ $rawVat = substr($rawVat,2); }
                        // Si es doméstico tratamos siempre como NIF
                        if(!$is_foreign){
                            $recipient_nif = verifacti_normalize_nif($rawVat);
                        }else{
                            // extranjero: crear estructura id_otro según API (id_type genérico 03 por defecto)
                            $recipient_id_otro = [ 'codigo_pais'=>$countryIso, 'id_type'=>'03', 'id'=>$rawVat ];
                        }
                    }
                    if(!empty($clientRow->company)){
                        $recipient_name = $clientRow->company;
                    }else{
                        $recipient_name = trim(($clientRow->firstname ?? '').' '.($clientRow->lastname ?? ''));
                    }
                }
            }
        }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] destinatario load error: '.$e->getMessage()); } }
        if((!$recipient_nif && !$recipient_id_otro) || $recipient_name===''){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] WARNING destinatario incompleto factura ID='.$invoice_id.' nif="'.$recipient_nif.'" id_otro='.(is_array($recipient_id_otro)?json_encode($recipient_id_otro):'"'.($recipient_id_otro??'').'"').' nombre="'.$recipient_name.'"'); }
        }
        $nif = $recipient_nif;
        $id_otro = $recipient_id_otro;
        $nombre = $recipient_name;
        $maxF2 = defined('VERIFACTI_F2_MAX_TOTAL') ? VERIFACTI_F2_MAX_TOTAL : 3000; // aún disponible pero sólo si sin identificador
        $recipient_nif_valid = ($nif !== '' && preg_match('/^[A-Z0-9]{5,}$/i', $nif));
        $hasFiscalId = $recipient_nif_valid || (is_array($id_otro) && !empty($id_otro['id']));
        $tipo_factura_calc = 'F1'; // base
        if(!$hasFiscalId && isset($invoice->total) && (float)$invoice->total <= $maxF2){
            // Sólo si realmente NO tenemos ningún identificador usar F2
            $tipo_factura_calc = 'F2';
            if(function_exists('log_message')){ log_message('debug','[Verifacti] Clasificada como F2 (sin identificador fiscal) ID='.$invoice_id); }
        }
        // Log diagnóstico
        if(function_exists('log_message')){ log_message('debug','[Verifacti] Destinatario: iso='.$countryIso.' nif='.$nif.' hasIdOtro='.(is_array($id_otro)?'1':'0').' tipo_preliminar='.$tipo_factura_calc); }
        // fecha_expedicion debe reflejar la fecha fiscal de la factura (Invoice->date) y no la fecha de creación del registro (datecreated)
        // Descripción: usar concepto del primer ítem si existe, en fallback el número formateado
        $first_item_desc = null;
        if(isset($invoice_items[0])){
            $fi = $invoice_items[0];
            if(is_object($fi)) $fi = (array)$fi;
            $first_item_desc = trim((string)($fi['description'] ?? $fi['long_description'] ?? ''));
            if($first_item_desc === '' && isset($fi['long_description'])){ $first_item_desc = trim((string)$fi['long_description']); }
        }
        $descripcion_final = $first_item_desc ?: format_invoice_number($invoice->id);
        // === Derivar serie y número reales según formato seleccionado en Perfex ===
        // Si es cliente UE (excepto España), forzar tipo F1
        if($is_eu && ($tipo_factura_calc === 'F2' || $tipo_factura_calc === 'R5')){
            $tipo_factura_calc = 'F1';
        }
    $raw_formatted = function_exists('format_invoice_number') ? format_invoice_number($invoice->id) : ($invoice->prefix.$invoice->number);
    list($serie_final,$numero_final) = $this->parseFormattedSerieNumero($raw_formatted,$invoice->prefix,$invoice->number);
        $invoice_data = [
            "serie" => $serie_final,
            "numero" => $numero_final,
            "fecha_expedicion" => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated)),
            "tipo_factura" => $tipo_factura_calc,
            "descripcion" => $descripcion_final,
            "fecha_operacion" => date('d-m-Y'),
            "nombre" => $nombre,
            /*"lineas" => [
                [
                  "base_imponible" => "200",
                  "tipo_impositivo" => "21",
                  "cuota_repercutida" => "42"
                ]
            ],*/
            "importe_total" => $invoice->total
        ];
    // Garantizar F1 definitivo si se cumplen condiciones (redundante por seguridad)
    if($nombre !== '' && (isset($invoice_data['nif']) || isset($invoice_data['id_otro']))){ $invoice_data['tipo_factura'] = 'F1'; }
        // Añadir campo fiscal correcto según país
        if($is_foreign && is_array($id_otro)){
            $invoice_data['id_otro'] = $id_otro; // estructura completa
        }elseif(!$is_foreign && $nif){
            $invoice_data['nif'] = $nif;
        }
        // Si finalmente se detectó identificador se refuerza F1
        if(isset($invoice_data['nif']) || isset($invoice_data['id_otro'])){ $invoice_data['tipo_factura']='F1'; }
        // Eliminada limpieza F2/R5.
    // ==== NUEVO CÁLCULO DE LÍNEAS (base_imponible correcta, cantidades, descuento, exentas, exclusión de IRPF) ====
        // Notas:
        // - Perfex aplica descuento a nivel de factura (discount_total). Lo distribuimos proporcionalmente sobre la base de cada item.
        // - Se agrupa por tipo_impositivo (valor numérico del IVA). Las líneas exentas (0%) mantienen su base.
        // - Retenciones (IRPF) NO deben incluirse: si Perfex tuviera un campo 'sale_agent' u otros no lo usamos; asumimos base/tax vienen limpios de IRPF.
        // - El importe_total transmitido será la suma de (base + cuota) de todas las líneas (incluye exentas solo base), pudiendo diferir mínimamente del total original por redondeos.
        // - SOPORTE códigos de exención: permetimos definir el código en el nombre del impuesto en Perfex.
        //   Convención: en el nombre antes del pipe puede incluirse la etiqueta entre corchetes o separada por espacio, p.ej:
        //     "IVA 0% EXENTO E2|0.00"  ó  "IVA 0% [E3]|0.00"  ó  "Servicio Exportación (E5)|0.00"
        //   Detectamos patrón (E\d+|E[A-Z0-9]+) y usamos ese token como operacion_exenta.
        //   Si no se encuentra token para un impuesto 0%, se usa E1 por defecto.

        $subtotal = isset($invoice->subtotal) ? (float)$invoice->subtotal : 0.0; // suma bases sin descuento ni impuestos
        $discount_total = isset($invoice->discount_total) ? (float)$invoice->discount_total : 0.0;
        $aggregated = []; // key = tipo_impositivo[-EXENTA_CODE]

        // Evitar división por cero
        $effective_subtotal = $subtotal > 0 ? $subtotal : 0.0;
        $running_discount_applied = 0.0; // control de ajuste final
        $last_item_key = null;
        $item_index = 0;
        $total_items = count($invoice_items);

    $withholding_total = 0.0; // Acumula importe de retención IRPF (positivo)
    foreach ($invoice_items as $item) {
            if(is_object($item)) { $item = (array)$item; }
            $item_index++;
            $qty  = isset($item['qty']) ? (float)$item['qty'] : 1.0;
            $rate = isset($item['rate']) ? (float)$item['rate'] : 0.0;
            $item_base_raw = round($qty * $rate, 6); // mayor precisión interna

            // Descuento proporcional (si existe)
            $item_discount = 0.0;
            if ($discount_total > 0 && $effective_subtotal > 0) {
                if ($item_index < $total_items) {
                    $item_discount = round(($item_base_raw / $effective_subtotal) * $discount_total, 6);
                    $running_discount_applied += $item_discount;
                } else {
                    // Ajuste final para cuadrar exactamente el total del descuento
                    $item_discount = round($discount_total - $running_discount_applied, 6);
                }
            }
            $item_base_after_discount = max(0, $item_base_raw - $item_discount);

            // Obtener impuestos del item
            // Impuestos correctos de la factura (NO usar credit_note)
            if(function_exists('get_invoice_item_taxes')){
                $item_taxes = get_invoice_item_taxes($item['id']);
            }else{
                // Intento de carga de helper si no existe la función
                if(!function_exists('get_invoice_item_taxes') && method_exists($this->ci->load,'helper')){
                    @ $this->ci->load->helper('invoices');
                }
                $item_taxes = function_exists('get_invoice_item_taxes') ? get_invoice_item_taxes($item['id']) : [];
            }
            if (empty($item_taxes)) {
                // Línea sin impuesto declarado -> exenta genérica E1
                $tax_rate = 0.0;
                $exenta_code = 'E1';
                $tax_key = '0.00-'.$exenta_code;
                if (!isset($aggregated[$tax_key])) {
                    $aggregated[$tax_key] = ['base' => 0.0, 'rate' => 0.00, 'exenta' => $exenta_code];
                }
                $aggregated[$tax_key]['base'] += $item_base_after_discount;
                continue;
            }
            foreach ($item_taxes as $itm_tx) {
                $raw_taxname = $itm_tx['taxname']; // Ej: "IVA 21%|21.00" o "IVA 0% EXENTO E2|0.00"
                $taxname_arr = explode('|', $raw_taxname);
                $name_part = $taxname_arr[0];
                $tax_rate = isset($taxname_arr[1]) ? (float)$taxname_arr[1] : 0.0;
                // IRPF / Retención (impuesto negativo) -> no se envía a Verifacti
                if($tax_rate < 0 || preg_match('/IRPF|RETENC/i',$name_part)){
                    $abs_base = $item_base_after_discount; // base sobre la que se aplica la retención
                    $withholding_total += round($abs_base * abs($tax_rate) / 100, 6);
                    continue; // saltar agregación
                }
                $exenta_code = null;
                if ($tax_rate == 0.0) {
                    // Buscar token E* en el nombre
                    if (preg_match('/\b(E[0-9A-Z]{1,2})\b/', $name_part, $m)) {
                        $exenta_code = $m[1];
                    } elseif (preg_match('/\[(E[0-9A-Z]{1,2})\]/', $name_part, $m2)) {
                        $exenta_code = $m2[1];
                    } elseif (preg_match('/\((E[0-9A-Z]{1,2})\)/', $name_part, $m3)) {
                        $exenta_code = $m3[1];
                    }
                    if (!$exenta_code) { $exenta_code = 'E1'; }
                }
                $base_key = number_format($tax_rate, 2, '.', '');
                $tax_key = $exenta_code ? ($base_key.'-'.$exenta_code) : $base_key;
                if (!isset($aggregated[$tax_key])) {
                    $aggregated[$tax_key] = ['base' => 0.0, 'rate' => $tax_rate];
                    if ($exenta_code) { $aggregated[$tax_key]['exenta'] = $exenta_code; }
                }
                $aggregated[$tax_key]['base'] += $item_base_after_discount;
            }
        }

        // Construir líneas finales
        $invoice_data['lineas'] = [];
        $importe_total_calc = 0.0;
        foreach ($aggregated as $tax_key => $row) {
            $base = round($row['base'], 2);
            $rate = (float)$row['rate'];
            // Regla: Si la operación es exenta NO se deben informar TipoImpositivo ni CuotaRepercutida.
            $line = [
                'base_imponible' => number_format($base, 2, '.', ''),
            ];
            if ($rate > 0) {
                $line['tipo_impositivo'] = number_format($rate, 2, '.', '');
                $tax_amount = round($base * $rate / 100, 2);
                $line['cuota_repercutida'] = number_format($tax_amount, 2, '.', '');
                $line['impuesto'] = '01';
                $line['calificacion_operacion'] = 'S1';
                $importe_total_calc += $base + $tax_amount;
            } else {
                $exenta_code = isset($row['exenta']) ? $row['exenta'] : 'E1';
                // Ajuste normativo: si el backend asume ClaveRegimen=01 y se intenta E2/E3 (Art.21/22) puede dar error.
                // Remapear automáticamente a E6 (Otros) salvo que se defina una constante que permita mantenerlos.
                // (Fix #9) Eliminado remapeo forzado E2/E3 -> E6; se respeta el código original.
                $line['operacion_exenta'] = $exenta_code;
                $importe_total_calc += $base;
            }
            $invoice_data['lineas'][] = $line;
        }

        // Establecer importe_total recalculado (excluye retenciones si Perfex las restó del total original)
        $invoice_data['importe_total'] = number_format($importe_total_calc, 2, '.', '');
        // --- VIES: si operación exenta E5 y cliente UE (no ES) intentar validar para ascender id_type a 02 ---
        $hasE5 = false;
        foreach($invoice_data['lineas'] as $ln){ if(isset($ln['operacion_exenta']) && strtoupper($ln['operacion_exenta'])==='E5'){ $hasE5=true; break; } }
        if($hasE5 && $is_eu && isset($invoice_data['id_otro']) && is_array($invoice_data['id_otro'])){
            try{
                $vatForVies = $invoice_data['id_otro']['id'];
                // Quitar prefijo país duplicado para la consulta si lo repite
                $vatCore = $vatForVies;
                if(stripos($vatCore,$invoice_data['id_otro']['codigo_pais'])===0){ $vatCore = substr($vatCore,strlen($invoice_data['id_otro']['codigo_pais'])); }
                if(method_exists($this->ci->verifacti_lib,'validate_vies')){
                    $viesResp = $this->ci->verifacti_lib->validate_vies($invoice_data['id_otro']['codigo_pais'],$invoice_data['id_otro']['codigo_pais'].$vatCore); // La API ejemplo incluye prefijo
                    if(isset($viesResp['success']) && $viesResp['success'] && isset($viesResp['result']['resultado'])){
                        if(strtoupper($viesResp['result']['resultado'])==='IDENTIFICADO'){
                            $invoice_data['id_otro']['id_type'] = '02';
                            if(function_exists('log_message')){ log_message('debug','[Verifacti] VIES IDENTIFICADO: id_type cambiado a 02 invoice ID='.$invoice_id); }
                        }else{
                            if(function_exists('log_message')){ log_message('debug','[Verifacti] VIES NO IDENTIFICADO: mantiene id_type 03 invoice ID='.$invoice_id); }
                        }
                    }else{
                        if(function_exists('log_message')){ log_message('debug','[Verifacti] VIES respuesta inesperada invoice ID='.$invoice_id.' resp='.(isset($viesResp['result'])?json_encode($viesResp['result']):'null')); }
                    }
                }
            }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] VIES exception invoice ID='.$invoice_id.' msg='.$e->getMessage()); } }
        }
        // Limpieza para facturas simplificadas: si tipo_factura F2 y no hay nif destinatario, eliminar claves nif/nombre (la API acepta ausencia)
        if($invoice_data['tipo_factura'] === 'F2'){
            // Siempre eliminar NIF vacío y también el nombre si no hay NIF (simplificada sin identificación destinatario)
            if(empty($invoice_data['nif'])){ unset($invoice_data['nif']); }
            if(!isset($invoice_data['nif'])){ unset($invoice_data['nombre']); }
        }
        // ==== FIN NUEVO CÁLCULO ====
    if(function_exists('log_message')){ log_message('debug','[Verifacti] Payload final antes de create/update ID='.$invoice_id.' tipo='.$invoice_data['tipo_factura'].' nif='.(isset($invoice_data['nif'])?$invoice_data['nif']:'(none)').' id_otro='.(isset($invoice_data['id_otro'])?json_encode($invoice_data['id_otro']):'(none)').' nombre="'.$nombre.'"'); }
        // _print_r($invoice_data);
        // exit;
        // --- Estrategia create vs update (modify) ---
        // Obtenemos cualquier registro previo de esta factura
    $invoice_prev = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ]);
        // Normalizamos payload para hashing estable (orden de claves)
        // Hash completo (incluye posibles cambios no fiscales)
        $normalized_payload = $invoice_data; ksort($normalized_payload);
        $payload_hash = hash('sha256', json_encode($normalized_payload));
        // Hash fiscal (serie, numero, fecha_expedicion, tipo_factura, lineas, importe_total)
        $fiscal_subset = [
            'serie'=>$invoice_data['serie'],
            'numero'=>$invoice_data['numero'],
            'fecha_expedicion'=>$invoice_data['fecha_expedicion'],
            'tipo_factura'=>$invoice_data['tipo_factura'],
            'lineas'=>$invoice_data['lineas'],
            'importe_total'=>$invoice_data['importe_total']
        ];
        $fiscal_hash = hash('sha256', json_encode($fiscal_subset));
        $is_update = false;
        $fiscal_changed = true; // default hasta comparar
        if($invoice_prev){
            if(isset($invoice_prev->last_payload_hash) && $invoice_prev->last_payload_hash === $payload_hash){
                // Nada cambió -> no reenviamos
                return; 
            }
            // Determinar cambio fiscal (Fix #5): sólo permitimos modify si NO cambia la parte fiscal
            if(isset($invoice_prev->last_fiscal_hash)){
                $fiscal_changed = ($invoice_prev->last_fiscal_hash !== $fiscal_hash);
            }else{
                // Sin hash fiscal previo: tratamos como cambiada para no hacer modify inseguro
                $fiscal_changed = true;
            }
            // Si hay verifacti_id previo, intentamos update (PUT). Caso contrario, recreamos.
            if(!empty($invoice_prev->verifacti_id)){
                // Poll previo si no registrado aún para evitar 3002
                $estadoPrev = isset($invoice_prev->estado_api) ? $invoice_prev->estado_api : null;
                if($estadoPrev !== 'Registrado'){
                    $this->pollStatus($invoice_prev, $invoice_data);
                    // Refrescar objeto tras poll
                    $invoice_prev = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ]);
                    $estadoPrev = isset($invoice_prev->estado_api) ? $invoice_prev->estado_api : null;
                    if($invoice_prev && $estadoPrev !== 'Registrado' && empty($invoice_prev->verifacti_id)){
                        // Si tras polling no existe registro, forzamos create
                        $is_update = false;
                    } else {
                        $is_update = true;
                    }
                }else{
                    $is_update = true;
                }
            }
        }

        // Si pretendemos update pero hubo cambio fiscal, bloqueamos modify (subsanación) y no reenviamos (debe generarse R1/abono según flujo negocio)
        if($is_update && $fiscal_changed){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: cambio fiscal detectado, se omite modify. Crear rectificativa R1 o nota de crédito. ID='.$invoice_id); }
            return; // no hacemos modify para cambios fiscales
        }

        if(!$invoice_prev){
            $v_inv_id = $this->ci->verifacti_model->save('invoices',[
                'invoice_id'=>$invoice_id,
                'status' => 'sent_request',
                'last_payload_hash' => $payload_hash,
                'last_fiscal_hash' => $fiscal_hash,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $response = $this->ci->verifacti_lib->create_invoice($invoice_data);
        }else{
            $v_inv_id = $invoice_prev->id;
            if($is_update){
                // PUT modify
                $response = $this->ci->verifacti_lib->update_invoice($invoice_data);
                $modCount = isset($invoice_prev->mod_count) ? (int)$invoice_prev->mod_count : 0;
                $updateData = [
                    'status' => 'modify_request',
                    'last_payload_hash' => $payload_hash,
                    // last_fiscal_hash NO cambia porque fiscal inmutable en modify
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                // Solo añadir mod_count si la columna existe
                if($this->columnExists('verifacti_invoices','mod_count')){
                    $updateData['mod_count'] = $modCount + 1;
                }
                $this->ci->verifacti_model->save('invoices',$updateData,['id'=>$v_inv_id]);
            }else{
                // No hash previo o sin verifacti_id -> reenviamos como create
                $response = $this->ci->verifacti_lib->create_invoice($invoice_data);
                $this->ci->verifacti_model->save('invoices',[
                    'status' => 'sent_request',
                    'last_payload_hash' => $payload_hash,
                    'last_fiscal_hash' => $fiscal_hash,
                    'updated_at' => date('Y-m-d H:i:s')
                ],['id'=>$v_inv_id]);
            }
        }
        $result = $response['result'];
        if($response['success'] && isset($result['qr']) && isset($result['url'])){
            $this->ci->verifacti_model->save('invoices',[
                'verifacti_id' => $result['uuid'],
                'estado_api_uuid' => $result['uuid'] ?? null,
                'status' => $result['estado'],
                'qr_url' => $result['url'],
                'qr_image_base64' => $result['qr'],
                'last_payload_hash' => $payload_hash,
                'last_fiscal_hash' => $fiscal_hash,
                'updated_at' => date('Y-m-d H:i:s')
            ],['id'=>$v_inv_id]);
        }else{
                // Mostrar error de la API en la interfaz si es 400 y hay mensaje
                if(isset($response['http_code']) && $response['http_code'] == 400 && isset($result['error'])){
                    if(function_exists('set_alert')){
                        set_alert('danger', '[Verifacti] Error al registrar factura: '.htmlspecialchars($result['error']));
                    }
                }
            // En caso de fallo de primera creación eliminamos; en modify conservamos registro
            if(!$invoice_prev){
                $this->ci->verifacti_model->delete('invoices',['id'=>$v_inv_id]);
            }else{
                $this->ci->verifacti_model->save('invoices',[
                    'status' => 'error',
                    'updated_at' => date('Y-m-d H:i:s')
                ],['id'=>$v_inv_id]);
            }
        }
    }
    /**
     * Consulta estado remoto y actualiza la fila local.
     * @param object $invoicePrev fila existente
     * @param array $currentPayload datos actuales (para fechas/serie/numero)
     */
    protected function pollStatus($invoicePrev, $currentPayload){
        // Construir payload status: serie, numero, fecha_expedicion, fecha_operacion (si aplica)
        $payload = [
            'serie' => $currentPayload['serie'],
            'numero' => $currentPayload['numero'],
            'fecha_expedicion' => $currentPayload['fecha_expedicion'],
            'fecha_operacion' => $currentPayload['fecha_operacion'] ?? $currentPayload['fecha_expedicion']
        ];
    $response = $this->ci->verifacti_lib->invoice_status($payload);
        $estado_api = null; $error_code = null; $error_message = null;
        if($response['success']){
            $result = $response['result'];
            // Estructura esperada: estado, codigo?, mensaje?
            if(isset($result['estado'])){ $estado_api = $result['estado']; }
            if(isset($result['error_code'])){ $error_code = $result['error_code']; }
            if(isset($result['error_message'])){ $error_message = $result['error_message']; }
        }else{
            $error_message = isset($response['result']['message']) ? $response['result']['message'] : 'Error desconocido status';
        }
        $update = [
            'estado_api' => $estado_api,
            'error_code' => $error_code,
            'error_message' => $error_message,
            'last_status_checked_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];
    $this->ci->verifacti_model->save('invoices',$update,['id'=>$invoicePrev->id]);
    }
    /**
     * Comprueba y crea columnas nuevas si faltan (fallback si install.php no corrió).
     */
    protected function ensureInvoiceSchema(){
        $table = db_prefix().'verifacti_invoices';
                // Crear tabla completa si no existe (fallback cuando install.php no se ejecutó)
                if(!$this->ci->db->table_exists($table)){
                        $charset = defined('APP_DB_CHARSET') ? APP_DB_CHARSET : 'utf8mb4';
                        $sql = "CREATE TABLE `{$table}` (
                            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                            `invoice_id` INT UNSIGNED NOT NULL,
                            `credit_note_id` INT UNSIGNED DEFAULT NULL,
                            `verifacti_id` VARCHAR(255) DEFAULT NULL,
                            `status` VARCHAR(32) DEFAULT NULL,
                            `qr_url` TEXT DEFAULT NULL,
                            `qr_image_base64` LONGTEXT DEFAULT NULL,
                            `last_payload_hash` VARCHAR(64) DEFAULT NULL,
                            `last_fiscal_hash` VARCHAR(64) DEFAULT NULL,
                            `mod_count` INT UNSIGNED NOT NULL DEFAULT 0,
                            `estado_api` VARCHAR(32) DEFAULT NULL,
                            `estado_api_uuid` VARCHAR(64) DEFAULT NULL,
                            `error_code` VARCHAR(32) DEFAULT NULL,
                            `error_message` TEXT DEFAULT NULL,
                            `last_status_checked_at` DATETIME DEFAULT NULL,
                            `canceled_at` DATETIME DEFAULT NULL,
                            `cancel_reason` VARCHAR(255) DEFAULT NULL,
                            `cancel_response` LONGTEXT DEFAULT NULL,
                            `created_at` DATETIME DEFAULT NULL,
                            `updated_at` DATETIME DEFAULT NULL,
                            PRIMARY KEY (`id`),
                            UNIQUE KEY `uniq_invoice_credit` (`invoice_id`,`credit_note_id`)
                        ) ENGINE=InnoDB DEFAULT CHARSET={$charset};";
                        try { $this->ci->db->query($sql); } catch(\Exception $e){ if(function_exists('log_message')){ log_message('error','[Verifacti] Error creando tabla verifacti_invoices: '.$e->getMessage()); } }
                        // No return: dejamos continuar por si en versiones anteriores faltan columnas futuras
                }
        // Columnas base para control de versiones y estado API
    if(!$this->columnExists('verifacti_invoices','last_payload_hash')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `last_payload_hash` VARCHAR(64) DEFAULT NULL AFTER `qr_image_base64`");
        }
    if(!$this->columnExists('verifacti_invoices','last_fiscal_hash')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `last_fiscal_hash` VARCHAR(64) DEFAULT NULL AFTER `last_payload_hash`");
        }
    if(!$this->columnExists('verifacti_invoices','mod_count')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `mod_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_payload_hash`");
        }
    if(!$this->columnExists('verifacti_invoices','credit_note_id')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `credit_note_id` INT UNSIGNED DEFAULT NULL AFTER `invoice_id`");
            try { $this->ci->db->query("ALTER TABLE `{$table}` DROP INDEX `invoice_id`"); } catch (Exception $e) {}
            try { $this->ci->db->query("CREATE UNIQUE INDEX `uniq_invoice_credit` ON `{$table}` (`invoice_id`,`credit_note_id`)"); } catch (Exception $e) {}
        }
    if(!$this->columnExists('verifacti_invoices','estado_api')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `estado_api` VARCHAR(32) DEFAULT NULL AFTER `mod_count`");
        }
    if(!$this->columnExists('verifacti_invoices','estado_api_uuid')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `estado_api_uuid` VARCHAR(64) DEFAULT NULL AFTER `estado_api`");
        }
    if(!$this->columnExists('verifacti_invoices','error_code')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `error_code` VARCHAR(32) DEFAULT NULL AFTER `estado_api`");
        }
    if(!$this->columnExists('verifacti_invoices','error_message')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `error_message` TEXT DEFAULT NULL AFTER `error_code`");
        }
    if(!$this->columnExists('verifacti_invoices','last_status_checked_at')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `last_status_checked_at` DATETIME DEFAULT NULL AFTER `error_message`");
        }
    // Campos para cancelación
    if(!$this->columnExists('verifacti_invoices','canceled_at')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `canceled_at` DATETIME DEFAULT NULL AFTER `last_status_checked_at`");
        }
    if(!$this->columnExists('verifacti_invoices','cancel_reason')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `cancel_reason` VARCHAR(255) DEFAULT NULL AFTER `canceled_at`");
        }
    if(!$this->columnExists('verifacti_invoices','cancel_response')){
            $this->ci->db->query("ALTER TABLE `{$table}` ADD `cancel_response` LONGTEXT DEFAULT NULL AFTER `cancel_reason`");
        }
    }
    protected function columnExists($tableShort, $column){
        $table = db_prefix().$tableShort;
        return $this->ci->db->field_exists($column,$table);
    }
    /**
     * Enviar una credit note (abono / rectificativa) como tipo R1.
     * Implementación mínima: reutiliza la mayor parte de la lógica de factura normal.
     */
    protected function sendCreditNoteVerifacti($credit_note_id, $original_invoice_id = null){
    if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: entrada credit_note_id='.$credit_note_id.' original_invoice_id='.( $original_invoice_id!==null?$original_invoice_id:'null')); }
        $this->ci->load->model('credit_notes_model');
        if(!function_exists('get_credit_note_item_taxes')){ $this->ci->load->helper('credit_notes'); }
        $credit = $this->ci->credit_notes_model->get($credit_note_id);
    if(!$credit){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: credit note no encontrada ID='.$credit_note_id); } return; }
    // Respetar fecha de inicio del módulo: NO enviar notas anteriores a start_date
    if(isset($credit->date) && function_exists('verifacti_is_before_start') && verifacti_is_before_start($credit->date)){
        if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: cancelado por start_date ('.$credit->date.') ID='.$credit_note_id); }
        return; // no se envía
    }
    if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: preparando envío CN ID='.$credit_note_id); }
    $this->ensureInvoiceSchema();

        // === DESTINATARIO CREDIT NOTE ===
        $recipient_nif = '';$recipient_name='';
        try{
            if(!isset($this->ci->clients_model)){$this->ci->load->model('clients_model');}
            if(isset($credit->clientid) && $credit->clientid){
                $cRow = $this->ci->clients_model->get($credit->clientid);
                if($cRow){
                    $recipient_nif = !empty($cRow->vat) ? verifacti_normalize_nif($cRow->vat) : '';
                    if(!empty($cRow->company)){ $recipient_name = $cRow->company; }
                    else { $recipient_name = trim(($cRow->firstname ?? '').' '.($cRow->lastname ?? '')); }
                }
            }
        }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] destinatario CN load error: '.$e->getMessage()); } }
        if($recipient_nif==='' || $recipient_name===''){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] WARNING destinatario incompleto credit_note ID='.$credit_note_id.' nif="'.$recipient_nif.'" nombre="'.$recipient_name.'"'); }
        }
        $nif = $recipient_nif; $nombre = $recipient_name;
        $tipo = $this->determineTipoFactura('credit_note');
        // Descripción: usar concepto primer ítem si existe; fallback 'Rectificativa' (+ ref)
        $descripcion = null;
        if(isset($credit->items[0])){
            $fi = $credit->items[0];
            if(is_object($fi)) $fi = (array)$fi;
            $descripcion = trim((string)($fi['description'] ?? $fi['long_description'] ?? ''));
            if($descripcion === '' && isset($fi['long_description'])){ $descripcion = trim((string)$fi['long_description']); }
        }
        if($descripcion === null || $descripcion===''){
            $descripcion = 'Rectificativa';
            if(!empty($credit->invoice_id)){
                $descripcion .= ' de '.format_invoice_number($credit->invoice_id);
            }
        }
        // === Derivar serie y número reales de la nota de crédito (mismo criterio que facturas) ===
        $raw_cn_formatted = function_exists('format_credit_note_number') ? format_credit_note_number($credit->id) : ($credit->prefix.$credit->number);
        $serie_cn = $credit->prefix; // fallback
        $numero_cn = $credit->number; // fallback
        if(strpos($raw_cn_formatted,'/') !== false){
            $pos = strpos($raw_cn_formatted,'/');
            $serie_cn = substr($raw_cn_formatted,0,$pos+1); // incluir la barra
            $numero_cn = substr($raw_cn_formatted,$pos+1);
            $numero_cn = ltrim($numero_cn,'0');
        }else{
            // Intentar separar prefijo no numérico de la parte numérica
            if(preg_match('/^([^0-9]*)([0-9].*)$/',$raw_cn_formatted,$m)){ $serie_cn = $m[1]; $numero_cn = $m[2]; }
        }
        if($numero_cn === '' || $numero_cn === null){ $numero_cn = $credit->number; }
        $invoice_data = [
            'serie' => $serie_cn,
            'numero' => $numero_cn,
            'fecha_expedicion' => !empty($credit->date) ? date('d-m-Y', strtotime($credit->date)) : date('d-m-Y'),
            'tipo_factura' => $tipo,
            'descripcion' => $descripcion,
            'fecha_operacion' => date('d-m-Y'),
            'nif' => $nif,
            'nombre' => $nombre,
        ];
        // Campos obligatorios para facturas rectificativas (R1..R5): tipo_rectificativa
        if(in_array($tipo,['R1','R2','R3','R4','R5'])){
            // Estrategia por defecto: diferencia (I). Podría hacerse configurable.
            $invoice_data['tipo_rectificativa'] = 'I';
            // Si la nota de crédito referencia a una factura original, añadimos facturas_rectificadas
            if(!empty($credit->invoice_id)){
                if(!isset($this->ci->invoices_model)){$this->ci->load->model('invoices_model');}
                $orig = $this->ci->invoices_model->get($credit->invoice_id);
                if($orig){
                    $formatted_orig = function_exists('format_invoice_number') ? format_invoice_number($orig->id) : ($orig->prefix.$orig->number);
                    list($serie_o,$numero_o) = $this->parseFormattedSerieNumero($formatted_orig,$orig->prefix,$orig->number);
                    $invoice_data['facturas_rectificadas'] = [[
                        'serie' => $serie_o,
                        'numero' => $numero_o,
                        'fecha_expedicion' => !empty($orig->date) ? date('d-m-Y', strtotime($orig->date)) : date('d-m-Y',strtotime($orig->datecreated))
                    ]];
                }
            }
        }
        // Pre-chequeo remoto (evitar duplicado sólo si estado registrado/aceptado con UUID)
        try{
            $formatted_cn_pre = function_exists('format_credit_note_number') ? format_credit_note_number($credit->id) : ($credit->prefix.$credit->number);
            list($serie_cpre,$numero_cpre) = $this->parseFormattedSerieNumero($formatted_cn_pre,$credit->prefix,$credit->number);
            $statePayload = [
                'serie' => $serie_cpre,
                'numero' => $numero_cpre,
                'fecha_expedicion' => !empty($credit->date) ? date('d-m-Y', strtotime($credit->date)) : date('d-m-Y')
            ];
            $remoteState = $this->ci->verifacti_lib->get_invoice_state($statePayload);
            if(isset($remoteState['success']) && $remoteState['success'] && !empty($remoteState['result'])){
                $r = $remoteState['result'];
                $estadoRem = $r['estado'] ?? null; $uuidRem = $r['uuid'] ?? null;
                if($uuidRem && in_array($estadoRem,['Registrado','Aceptado','Consolidado'])){
                    $existsRemote = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$credit->id ]);
                    if(!$existsRemote){
                        $this->ci->verifacti_model->save('invoices',[
                            'invoice_id' => !empty($credit->invoice_id)?$credit->invoice_id:0,
                            'credit_note_id' => $credit->id,
                            'verifacti_id' => $uuidRem,
                            'status' => $estadoRem,
                            'created_at' => date('Y-m-d H:i:s'),
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    }
                    if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: CN pre-check detecta ya registrada (estado='.$estadoRem.') ID='.$credit_note_id); }
                    return;
                }else{
                    if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: pre-check no concluye registro (estado='.( $estadoRem?:'null' ).', uuid='.( $uuidRem?:'null' ).') ID='.$credit_note_id); }
                }
            }
        }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] pre-check remoto CN falló ID='.$credit_note_id.' msg='.$e->getMessage()); } }
        // Construcción de líneas (similar a facturas)
        $subtotal = (float)($credit->subtotal ?? 0);
        $discount_total = (float)($credit->discount_total ?? 0);
    $items = $credit->items ?? [];
    if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: subtotal='.$subtotal.' items='.count($items).' discount_total='.$discount_total); }
        $aggregated = [];
        $effective_subtotal = $subtotal > 0 ? $subtotal : 0.0;
        $running_discount_applied = 0.0;
        $total_items = count($items); $idx=0; $withholding_total=0.0;
        foreach($items as $item){
            $idx++;
            $qty = isset($item['qty']) ? (float)$item['qty'] : 1.0;
            $rate = isset($item['rate']) ? (float)$item['rate'] : 0.0;
            $item_base_raw = round($qty * $rate,6);
            $item_discount = 0.0;
            if($discount_total>0 && $effective_subtotal>0){
                if($idx < $total_items){
                    $item_discount = round(($item_base_raw / $effective_subtotal) * $discount_total,6);
                    $running_discount_applied += $item_discount;
                }else{
                    $item_discount = round($discount_total - $running_discount_applied,6);
                }
            }
            $item_base_after_discount = max(0,$item_base_raw - $item_discount);
            $item_taxes = function_exists('get_credit_note_item_taxes') ? get_credit_note_item_taxes($item['id']) : (function_exists('get_invoice_item_taxes') ? get_invoice_item_taxes($item['id']) : []);
            if(empty($item_taxes)){
                $tax_key = '0.00-E1';
                if(!isset($aggregated[$tax_key])){ $aggregated[$tax_key] = ['base'=>0.0,'rate'=>0.0,'exenta'=>'E1']; }
                $aggregated[$tax_key]['base'] += $item_base_after_discount; continue;
            }
            foreach($item_taxes as $itm_tx){
                $parts = explode('|',$itm_tx['taxname']);
                $name_part = $parts[0];
                $tax_rate = isset($parts[1]) ? (float)$parts[1] : 0.0;
                if($tax_rate < 0 || preg_match('/IRPF|RETENC/i',$name_part)){
                    $withholding_total += round($item_base_after_discount * abs($tax_rate)/100,6); continue;
                }
                $exenta_code = null;
                if($tax_rate == 0.0){
                    if(preg_match('/\b(E[0-9A-Z]{1,2})\b/',$name_part,$m)){$exenta_code=$m[1];}
                    elseif(preg_match('/\[(E[0-9A-Z]{1,2})\]/',$name_part,$m2)){$exenta_code=$m2[1];}
                    elseif(preg_match('/\((E[0-9A-Z]{1,2})\)/',$name_part,$m3)){$exenta_code=$m3[1];}
                    if(!$exenta_code){ $exenta_code='E1'; }
                }
                $base_key = number_format($tax_rate,2,'.','');
                $tax_key = $exenta_code ? ($base_key.'-'.$exenta_code):$base_key;
                if(!isset($aggregated[$tax_key])){ $aggregated[$tax_key] = ['base'=>0.0,'rate'=>$tax_rate]; if($exenta_code){ $aggregated[$tax_key]['exenta']=$exenta_code; } }
                $aggregated[$tax_key]['base'] += $item_base_after_discount;
            }
        }
        $invoice_data['lineas']=[]; $importe_total_calc=0.0;
        foreach($aggregated as $row){
            $base = round($row['base'],2); $rate=(float)$row['rate'];
            $line=['base_imponible'=>number_format($base,2,'.','')];
            if($rate>0){
                $line['tipo_impositivo']=number_format($rate,2,'.','');
                $tax_amount=round($base*$rate/100,2);
                $line['cuota_repercutida']=number_format($tax_amount,2,'.','');
                $line['impuesto']='01'; $line['calificacion_operacion']='S1';
                $importe_total_calc += $base + $tax_amount;
            }else{
                $line['operacion_exenta']=$row['exenta'] ?? 'E1';
                $importe_total_calc += $base;
            }
            $invoice_data['lineas'][]=$line;
        }
        $invoice_data['importe_total']=number_format($importe_total_calc,2,'.','');

        // Invertir signo para rectificativas (R1-R5) según requerimiento negocio
        if(in_array($tipo,['R1','R2','R3','R4','R5'])){
            foreach($invoice_data['lineas'] as &$ln){
                // base_imponible siempre presente
                $valBase = (float)$ln['base_imponible'];
                $ln['base_imponible'] = number_format($valBase * -1, 2, '.', '');
                if(isset($ln['cuota_repercutida'])){
                    $valCuota = (float)$ln['cuota_repercutida'];
                    $ln['cuota_repercutida'] = number_format($valCuota * -1, 2, '.', '');
                }
            }
            unset($ln);
            $invoice_total_num = (float)$invoice_data['importe_total'];
            $invoice_data['importe_total'] = number_format($invoice_total_num * -1, 2, '.', '');
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: aplicado signo negativo a rectificativa ID='.$credit_note_id); }
        }

        // Hash y decisión create/modify
        $normalized = $invoice_data; ksort($normalized);
        $payload_hash = hash('sha256', json_encode($normalized));
    $existing = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$credit->id ]);
        $is_update = false;
        if($existing){
            if(isset($existing->last_payload_hash) && $existing->last_payload_hash === $payload_hash){ return; }
            if(!empty($existing->verifacti_id)){
                $estadoPrev = isset($existing->estado_api)? $existing->estado_api : null;
                if($estadoPrev !== 'Registrado'){
                    $this->pollStatus($existing,$invoice_data);
                    $existing = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$credit->id ]);
                    $estadoPrev = isset($existing->estado_api)? $existing->estado_api : null;
                    $is_update = ($estadoPrev === 'Registrado');
                }else{ $is_update = true; }
            }
        }
        if(!$existing){
            $baseInvoiceId = !empty($credit->invoice_id)? $credit->invoice_id : 0;
            $rowId = $this->ci->verifacti_model->save('invoices',[
                'invoice_id' => $baseInvoiceId,
                'credit_note_id' => $credit->id,
                'status' => 'sent_request',
                'last_payload_hash' => $payload_hash,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $response = $this->ci->verifacti_lib->create_invoice($invoice_data);
        }else{
            $rowId = $existing->id;
            if($is_update){
                $response = $this->ci->verifacti_lib->update_invoice($invoice_data);
                $upd = [ 'status'=>'modify_request','last_payload_hash'=>$payload_hash,'updated_at'=>date('Y-m-d H:i:s') ];
                if($this->columnExists('verifacti_invoices','mod_count')){ $upd['mod_count'] = (int)$existing->mod_count + 1; }
                $this->ci->verifacti_model->save('invoices',$upd,['id'=>$rowId]);
            }else{
                $response = $this->ci->verifacti_lib->create_invoice($invoice_data);
                $this->ci->verifacti_model->save('invoices',[ 'status'=>'sent_request','last_payload_hash'=>$payload_hash,'updated_at'=>date('Y-m-d H:i:s') ],['id'=>$rowId]);
            }
        }
        $result = $response['result'];
        if($response['success'] && isset($result['qr']) && isset($result['url'])){
            $this->ci->verifacti_model->save('invoices',[
                'verifacti_id' => $result['uuid'] ?? null,
                'status' => $result['estado'] ?? 'desconocido',
                'qr_url' => $result['url'],
                'qr_image_base64' => $result['qr'],
                'last_payload_hash' => $payload_hash,
                'updated_at' => date('Y-m-d H:i:s')
            ],['id'=>$rowId]);
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: éxito CN ID='.$credit_note_id); }
        }else{
            // Conservar siempre registro para depuración (status error)
            $this->ci->verifacti_model->save('invoices',[
                'status'=>'error',
                'error_message'=> isset($response['result']) ? json_encode($response['result']) : 'Error desconocido credit note',
                'updated_at'=>date('Y-m-d H:i:s')
            ],['id'=>$rowId]);
            if(function_exists('log_message')){ log_message('error','[Verifacti] sendCreditNoteVerifacti: fallo CN ID='.$credit_note_id.' payload='.json_encode($invoice_data).' resp='.(isset($response['result'])?json_encode($response['result']):'sin_result')); }
        }
    }

    protected function determineTipoFactura($context){
        if($context === 'credit_note'){
            return self::FACTURA_TIPO_CREDIT; // R1 para rectificativa básica
        }
        return self::FACTURA_TIPO_INVOICE; // F1 por defecto
    }
    public function add_admin_menu(){
        if (has_permission('verifacti', '', 'view')) {
            $this->ci->app_menu->add_sidebar_menu_item('verifacti', [
                'name'     => _l('verifacti'),
                'href'     => base_url('verifacti/setup'),
                'icon'     => 'fa fa-file-invoice',
                'position' => 60,
            ]);
        }
    }
    public function afterCreditNoteAdded($data){ if(isset($data['id'])){ $this->sendCreditNoteVerifacti($data['id']); } }
    public function afterCreditNoteUpdated($data){ if(isset($data['id'])){ $this->sendCreditNoteVerifacti($data['id']); } }
    public function onGenericCreditNoteEvent($payload){
        $id=null;
        if(is_numeric($payload)){ $id=(int)$payload; }
        elseif(is_array($payload)){ $id=$payload['id']??($payload['credit_note_id']??null); }
        elseif(is_object($payload)){ $id=$payload->id??($payload->credit_note_id??null); }
        if($id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] onGenericCreditNoteEvent hook disparado ID='.$id); } $this->sendCreditNoteVerifacti($id); }
    }
    public function onCreditNoteCreated($id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] onCreditNoteCreated ID='.$id); } $this->sendCreditNoteVerifacti($id); }
    public function onCreditNoteUpdated($id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] onCreditNoteUpdated ID='.$id); } $this->sendCreditNoteVerifacti($id); }
    public function onCreditNoteSent($id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] onCreditNoteSent ID='.$id); } $this->sendCreditNoteVerifacti($id); }
    public function onCreditNoteStatusChanged($id,$data=null){ if(function_exists('log_message')){ log_message('debug','[Verifacti] onCreditNoteStatusChanged ID='.$id); } $this->sendCreditNoteVerifacti($id); }
    public function beforeCreditNoteSentClient($credit){
        // Similar a beforeInvoiceSentClient: intentar registro y esperar QR
        if(!isset($credit->id)){ return $credit; }
        $this->sendCreditNoteVerifacti($credit->id,'before_send_email');
        $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$credit->id ]);
        if(!$row || empty($row->qr_image_base64)){
            $this->waitForQrCredit($credit, defined('VERIFACTI_QR_WAIT_SECONDS') ? VERIFACTI_QR_WAIT_SECONDS : 18, 0.8);
        }
        return $credit;
    }
    /**
     * Hook: invoice_marked_as_sent
     * Perfex dispara este hook al pulsar "Mark as Sent". Reenviamos / enviamos factura a Verifactu.
     */
    public function onInvoiceMarkedAsSent($payload){
        $invoice_id = null;
        if(is_numeric($payload)){
            $invoice_id = (int)$payload;
        }elseif(is_array($payload)){
            $invoice_id = isset($payload['invoice_id']) ? (int)$payload['invoice_id'] : (isset($payload['id'])?(int)$payload['id']:null);
        }elseif(is_object($payload)){
            $invoice_id = isset($payload->invoice_id) ? (int)$payload->invoice_id : (isset($payload->id)?(int)$payload->id:null);
        }
        if(!$invoice_id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] onInvoiceMarkedAsSent: no se pudo determinar invoice_id'); } return; }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] onInvoiceMarkedAsSent: recibido ID='.$invoice_id); }
        $this->sendInvoiceVerifacti($invoice_id,'invoice_marked_as_sent');
    }
    /**
     * Hook genérico post-envío de factura por email. Soporta ejecuciones via cron (send later).
     * Perfex puede pasar invoice_id o array con 'invoice_id'. Intentamos detectar.
     */
    public function onInvoiceEmailSent($payload){
        if(is_numeric($payload)){
            $invoice_id = (int)$payload;
        }elseif(is_array($payload) && isset($payload['invoice_id'])){
            $invoice_id = (int)$payload['invoice_id'];
        }elseif(is_object($payload) && isset($payload->invoice_id)){
            $invoice_id = (int)$payload->invoice_id;
        }else{
            if(function_exists('log_message')){ log_message('debug','[Verifacti] onInvoiceEmailSent: formato desconocido'); }
            return; // formato desconocido
        }
        if(!$invoice_id){ return; }
        if(function_exists('log_message')){ log_message('debug','[Verifacti] onInvoiceEmailSent: ID='.$invoice_id); }
        $this->sendInvoiceVerifacti($invoice_id,'email_sent_hook');
    }
    /**
     * Intenta cancelar en Verifacti cuando Perfex marca la factura como cancelada.
     * Acepta distintos formatos de payload según hook.
     */
    public function onInvoicePossiblyCancelled($payload){
        $invoice_id = null; $status = null; $reason = null;
        if(is_numeric($payload)){
            $invoice_id = (int)$payload;
        }elseif(is_array($payload)){
            if(isset($payload['invoice_id'])){ $invoice_id = (int)$payload['invoice_id']; }
            if(isset($payload['id'])){ $invoice_id = (int)$payload['id']; }
            if(isset($payload['status'])){ $status = $payload['status']; }
            if(isset($payload['reason'])){ $reason = $payload['reason']; }
        }elseif(is_object($payload)){
            if(isset($payload->invoice_id)) { $invoice_id = (int)$payload->invoice_id; }
            if(isset($payload->id)) { $invoice_id = (int)$payload->id; }
            if(isset($payload->status)) { $status = $payload->status; }
            if(isset($payload->reason)) { $reason = $payload->reason; }
        }
        if(!$invoice_id){ return; }
        // Comprobar estado real de la factura si no vino en payload
        $invoice = $this->ci->invoices_model->get($invoice_id);
        if(!$invoice){ return; }
        if($status === null && isset($invoice->status)){ $status = $invoice->status; }
        // Perfex usa a menudo 'cancelled' o código (5). Ajustar a ambos.
        $isCancelled = false;
        if($status !== null){
            if(is_numeric($status)){
                $isCancelled = ((int)$status === 5); // suposición común
            }else{
                $isCancelled = (strtolower($status) === 'cancelled' || strtolower($status) === 'canceled');
            }
        }
        if(!$isCancelled){ return; }
        // Evitar doble cancelación
        $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice_id ]);
        if(!$row || empty($row->verifacti_id) || !empty($row->canceled_at)){
            return; // no registrada aún o ya cancelada
        }
        if(!$reason){ $reason = 'Cancelada en Perfex'; }
        $this->cancelInvoice($invoice_id,$reason);
    }
    /**
     * Fallback tras cron: enviar facturas que quedaron programadas (send later) y ya están marcadas como enviadas internamente.
     * Estrategia: buscar en tabla invoices (Perfex) facturas con sent=1 (o status enviado) sin registro en verifacti_invoices.
     */
    public function processPendingScheduledInvoices(){
        if(!function_exists('isVerifactiEnable') || !isVerifactiEnable()){ return; }
        // Intentar detectar columnas estándar
        if(!$this->ci->db->table_exists(db_prefix().'invoices')){ return; }
        $db = $this->ci->db;
        // Seleccionar últimas 50 para limitar carga
        $db->select('id,number,prefix,status,sent,date');
        $db->from(db_prefix().'invoices');
        $db->where('sent',1); // Perfex marcará sent=1 tras envío cron
        // Filtro por start_date (no enviar anteriores)
        if(function_exists('get_option')){
            $start = get_option('verifacti_start_date');
            if($start){ $db->where('date >=',$start); }
        }
        $db->order_by('id','DESC');
        $db->limit(50);
        $query = $db->get();
        $invoices = $query ? $query->result() : [];
        if(!$invoices){ return; }
        foreach($invoices as $inv){
            // Saltar drafts
            if(isset($inv->status) && ( (is_numeric($inv->status) && (int)$inv->status === 6) || strtolower((string)$inv->status)==='draft')){ continue; }
            $already = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$inv->id ]);
            if($already && ($already->verifacti_id || $already->status === 'Registrado' || $already->status === 'sent_request' || $already->status === 'modify_request')){ continue; }
            // Enviar ahora (idempotente con hash interno)
            if(function_exists('log_message')){ log_message('debug','[Verifacti] processPendingScheduledInvoices enviando ID='.$inv->id); }
            $this->sendInvoiceVerifacti($inv->id,'cron_fallback');
        }
    }

    /**
     * Fallback adicional ejecutado en cada admin_init: busca facturas con sent=1 recién marcadas que aún no se han enviado
     * (no existen en verifacti_invoices o sin verifacti_id) y envía.
     */
    public function processMarkedAsSentFallback(){
        if(!function_exists('isVerifactiEnable') || !isVerifactiEnable()){ return; }
        $db = $this->ci->db;
        if(!$db->table_exists(db_prefix().'invoices')){ return; }
                // Asegurar tablas/campos del módulo
                $this->ensureInvoiceSchema();
            $verifactiTable = db_prefix().'verifacti_invoices';
            $hasVerifactiTable = $db->table_exists($verifactiTable);
                // Si todavía no existe la tabla (instalación recién activada) abortar silenciosamente
                if(!$hasVerifactiTable){ return; }
        // Seleccionar últimas 30 facturas sent=1 sin registro verifacti satisfactorio
            $startClause = '';
            if(function_exists('get_option')){ $start = get_option('verifacti_start_date'); if($start){ $startClause = " AND (i.date IS NULL OR i.date >= '".$start."')"; } }
            $sql = "SELECT i.id,i.status,i.sent,i.date FROM ".db_prefix()."invoices i
                LEFT JOIN `$verifactiTable` v ON v.invoice_id = i.id
                                WHERE i.sent=1".$startClause."\n                                    AND (v.id IS NULL OR (v.verifacti_id IS NULL OR v.verifacti_id=''))
                                ORDER BY i.id DESC LIMIT 30";
        $res = $db->query($sql)->result();
        if(!$res){ return; }
        foreach($res as $row){
            // Saltar drafts
            if(isset($row->status) && ( (is_numeric($row->status) && (int)$row->status === 6) || strtolower((string)$row->status)==='draft')){ continue; }
            if(function_exists('verifacti_is_before_start') && isset($row->date) && verifacti_is_before_start($row->date)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] processMarkedAsSentFallback: skip por start_date ID='.$row->id); }
                continue;
            }
            if(function_exists('log_message')){ log_message('debug','[Verifacti] processMarkedAsSentFallback: enviando ID='.$row->id); }
            $this->sendInvoiceVerifacti($row->id,'admin_init_fallback');
        }
    }

    /**
     * Fallback para credit notes: intenta enviar las últimas 30 credit notes sin registro verifacti.
     */
    public function processPendingCreditNotes(){
        if(!function_exists('isVerifactiEnable') || !isVerifactiEnable()){ return; }
        $this->ci->load->model('credit_notes_model');
        $db = $this->ci->db;
        if(!$db->table_exists(db_prefix().'creditnotes')){ return; }
        $this->ensureInvoiceSchema();
    $startClause = '';
    if(function_exists('get_option')){ $start = get_option('verifacti_start_date'); if($start){ $startClause = " AND (cn.date IS NULL OR cn.date >= '".$start."')"; } }
    $sql = "SELECT cn.id, cn.number, cn.prefix, cn.date FROM ".db_prefix()."creditnotes cn
        LEFT JOIN ".db_prefix()."verifacti_invoices v ON v.credit_note_id = cn.id
        WHERE (v.id IS NULL OR (v.verifacti_id IS NULL OR v.verifacti_id=''))".$startClause."\n        ORDER BY cn.id DESC LIMIT 30";
        $rows = $db->query($sql)->result();
        if(!$rows){ return; }
        foreach($rows as $r){
            if(function_exists('verifacti_is_before_start') && isset($r->date) && verifacti_is_before_start($r->date)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] processPendingCreditNotes: skip por start_date CN ID='.$r->id); }
                continue;
            }
            if(function_exists('log_message')){ log_message('debug','[Verifacti] processPendingCreditNotes: enviando CN ID='.$r->id); }
        $this->sendCreditNoteVerifacti($r->id, null);
        }
    }
}