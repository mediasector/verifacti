<?php

// Developed by Asif Thebepotra
// Modified by Íñigo Sastre:
// - fecha_expedicion Changed from $invoice->datecreated to real fiscal date --> ($invoice->date in Perfex).


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
            hooks()->add_action('after_right_panel_invoicehtml',[$this,'afterInvoiceLeftHtml']);
            // hooks()->add_action('invoice_pdf_info',[$this,'afterInvoicePdfInfo'],10,2);
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
            /**MY CUSTOM HOOKS*/
            hooks()->add_action('after_email_fields_invoice_send_to_client',[$this,'renderVerifactiFields']);
            hooks()->add_filter('invoice_pdf_after_invoice_header_number',[$this,'afterInvoicePdfInfo'],10,2);
            // Filtros PDF adicionales (compatibilidad distintas versiones Perfex)
            $invoicePdfFilters = [
                'invoice_pdf_after_invoice_number',
                'invoice_pdf_after_invoice_heading',
                'invoice_pdf_after_invoice_content',
                'invoice_pdf_after_invoice_info',
                'invoice_pdf_after_invoice_date',
                'invoice_pdf_after_invoice_client_details'
            ];
            foreach($invoicePdfFilters as $ipf){ hooks()->add_filter($ipf,[$this,'afterInvoicePdfInfo'],10,2); }
            // Hooks PDF para notas de crédito (intentamos varias variantes posibles)
            $creditPdfFilters = [
                // Filtros conocidos / supuestos
                'credit_note_pdf_after_credit_note_number',
                'credit_note_pdf_after_credit_note_heading',
                'credit_note_pdf_after_credit_note_content',
                // Filtros adicionales posibles según versiones
                'credit_note_pdf_after_credit_note_info',
                'credit_note_pdf_after_credit_note_date',
                'credit_note_pdf_after_credit_note_client_details'
            ];
            foreach($creditPdfFilters as $cpf){ hooks()->add_filter($cpf,[$this,'afterCreditNotePdfInfo'],10,2); }
            // Hook antes de enviar nota de crédito por email (esperar QR)
            hooks()->add_action('credit_note_object_before_send_to_client',[$this,'beforeCreditNoteSentClient']);
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
        $payload = [
            'serie' => $invoice->prefix,
            'numero' => $invoice->number,
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
        $invoice_id = $invoice->id;
    $verifacti_invoice = $this->ci->verifacti_model->get("invoices",['single'=>true,'invoice_id'=>$invoice_id],
            ['column'=>'id','order'=>'DESC']);
        if($verifacti_invoice){
            if(empty($verifacti_invoice->qr_image_base64) && !empty($verifacti_invoice->verifacti_id)){
                // Intento rápido único: pedir status una vez para poblar QR justo antes de render PDF
                try{
                    $payloadStatus = [
                        'serie' => $invoice->prefix,
                        'numero' => $invoice->number,
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
                        // refrescar objeto
                        $verifacti_invoice = $this->ci->verifacti_model->get("invoices",['single'=>true,'invoice_id'=>$invoice_id]);
                    }
                }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoicePdfInfo status error: '.$e->getMessage()); } }
            }
            if(!empty($verifacti_invoice->qr_image_base64)){
                $html = generateVerifactiQr($verifacti_invoice);
                $invoice_info .= $html;
            }
        }
        return $invoice_info;
     }
    public function afterCreditNotePdfInfo($credit_note_info, $credit_note){
        $id = isset($credit_note->id)?$credit_note->id:null;
        if(!$id){ return $credit_note_info; }
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
        // Manejo especial de borrador: si el usuario está enviando (Save & Send) queremos registrar y esperar QR
        $force_send_even_if_draft = false;
        if(isset($invoice->status)){
            $st = $invoice->status;
            $isDraft = ($st === 'draft' || $st === 'Draft' || (is_numeric($st) && (int)$st === 6));
            if($isDraft){
                // Si NO es un envío programado (send_later flags ya filtrados arriba) asumimos acción explícita del usuario de enviar ahora.
                // Permitimos forzar registro para obtener QR antes de generar el PDF adjunto.
                $force_send_even_if_draft = true;
            }
        }
        // Determinar si se debe enviar (modo manual o automático)
        $send_to_verifacti = defined('VERIFACTI_MANUAL_SEND_ENABLED') && VERIFACTI_MANUAL_SEND_ENABLED
            ? ($this->ci->input->post('send_to_verifacti') ?? false)
            : true;
        if($send_to_verifacti){
            $this->sendInvoiceVerifacti($invoice->id,'before_send_email',$force_send_even_if_draft);
            // Verificar si ya existe registro con QR antes de esperar
            $row = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'invoice_id'=>$invoice->id ]);
            if($row && !empty($row->qr_image_base64)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] beforeInvoiceSentClient: QR ya disponible sin espera ID='.$invoice->id); }
            }else{
                $this->waitForQr($invoice, defined('VERIFACTI_QR_WAIT_SECONDS') ? VERIFACTI_QR_WAIT_SECONDS : 18, 0.8);
            }
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
            // Si hay verifacti_id pero no QR, intentar status remoto
            if($row && !empty($row->verifacti_id)){
                try{
                    $payloadStatus = [
                        'serie' => $invoice->prefix,
                        'numero' => $invoice->number,
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
                    $payloadStatus = [
                        'serie' => $credit->prefix,
                        'numero' => $credit->number,
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
    public function addQrCodeImage($pdf_url){
        $segments = $this->ci->uri->segment_array();
        $qr_image = 'QR CODE HERE';
        $qr_image .= json_encode($segments);

        return $pdf_url."<br/>".$qr_image;
    }
    public function afterInvoiceLeftHtml($invoice){
        $invoice_id = $invoice->id;
    $verifacti_invoice = $this->ci->verifacti_model->get("invoices",['single'=>true,'invoice_id'=>$invoice_id],
            ['column'=>'id','order'=>'DESC']);
        if($verifacti_invoice){
            echo generateVerifactiQr($verifacti_invoice);
        }

    }

    // Métodos legacy (desactivados) mantenidos por compatibilidad futura
    public function afterInvoiceCreated($invoice_id){ /* envío automático deshabilitado */ }
    public function afterInvoiceUpdated($updateData){
        // Solo actuamos cuando se marca como enviada (sent -> 1) y no es draft
        if(empty($updateData['id'])){ return; }
        if(function_exists('isVerifactiEnable') && !isVerifactiEnable()){ return; }
        if(isset($updateData['sent']) && (int)$updateData['sent'] === 1){
            // Confirmar que no es draft
            $invoice = $this->ci->invoices_model->get($updateData['id']);
            if($invoice && isset($invoice->status)){
                $st = $invoice->status;
                if($st === 'draft' || $st === 'Draft' || (is_numeric($st) && (int)$st === 6)){
                    return;
                }
            }
            if(function_exists('log_message')){ log_message('debug','[Verifacti] afterInvoiceUpdated detectó sent=1 para factura ID '.$updateData['id']); }
            $this->sendInvoiceVerifacti($updateData['id'],'afterInvoiceUpdated');
        }
    }
    protected function sendInvoiceVerifacti($invoice_id,$source=null,$force=false){
        if(!$invoice_id){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: invoice_id vacío source=' . ($source?:'n/a')); } return; }
        if(!function_exists('isVerifactiEnable') || !isVerifactiEnable()){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: módulo deshabilitado ID='.$invoice_id); } return; }
        // Asegurar que las columnas necesarias existen (en caso de que install.php no se haya re-ejecutado)
        $this->ensureInvoiceSchema();
        $invoice = $this->ci->invoices_model->get($invoice_id);
        if(!$invoice){ if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: factura no encontrada ID='.$invoice_id.' source='.$source); } return; }
        // Filtro: no enviar si fecha expedición anterior a fecha de entrada en funcionamiento
        if(isset($invoice->date) && verifacti_is_before_start($invoice->date)){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: saltando por start_date ('.$invoice->date.') ID='.$invoice_id); }
            return;
        }
    // (Fix #6) Eliminado filtro que bloqueaba facturas con fecha anterior. Permitimos envío atrasado.
        // No enviar nunca facturas en borrador (draft). En Perfex normalmente el estado draft es el código 6 o la cadena 'draft'.
        if(!$force && isset($invoice->status)){
            $st = $invoice->status;
            if($st === 'draft' || $st === 'Draft' || (is_numeric($st) && (int)$st === 6)){
                if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: saltando draft ID='.$invoice_id.' source='.$source); } return; // Saltar envío para borradores si no se fuerza
            }
        }elseif($force && function_exists('log_message')){
            log_message('debug','[Verifacti] sendInvoiceVerifacti: FORZADO envío aunque draft ID='.$invoice_id.' source='.$source);
        }
        // Evitar reenvío si ya tenemos verifacti_id o estado Registrado
        $existing = $this->ci->verifacti_model->get('invoices',['single'=>true,'invoice_id'=>$invoice_id]);
        if($existing && ($existing->verifacti_id || $existing->status === 'Registrado')){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: ya enviado/verificado ID='.$invoice_id.' source='.$source); } return; // ya enviado
        }
        // Pre-chequeo remoto: consultar si ya existe en AEAT a través de Verifacti (estado consolidado) antes de intentar crear
        try{
            $statePayload = [
                'serie' => $invoice->prefix,
                'numero' => $invoice->number,
                'fecha_expedicion' => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated))
            ];
            $remoteState = $this->ci->verifacti_lib->get_invoice_state($statePayload);
            if(isset($remoteState['success']) && $remoteState['success'] && !empty($remoteState['result'])){
                // Registrar localmente si no existía
                if(!$existing){
                    $this->ci->verifacti_model->save('invoices',[
                        'invoice_id' => $invoice_id,
                        'verifacti_id' => $remoteState['result']['uuid'] ?? null,
                        'status' => $remoteState['result']['estado'] ?? 'Registrado',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                if(function_exists('log_message')){ log_message('debug','[Verifacti] sendInvoiceVerifacti: detectada ya registrada en remoto ID='.$invoice_id); }
                return;
            }
        }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] pre-check remoto falló ID='.$invoice_id.' msg='.$e->getMessage()); } }
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
        try{
            if(!isset($this->ci->clients_model)){
                $this->ci->load->model('clients_model');
            }
            if(isset($invoice->clientid) && $invoice->clientid){
                $clientRow = $this->ci->clients_model->get($invoice->clientid);
                if($clientRow){
                    $recipient_nif = !empty($clientRow->vat) ? verifacti_normalize_nif($clientRow->vat) : '';
                    if(!empty($clientRow->company)){
                        $recipient_name = $clientRow->company;
                    }else{
                        $recipient_name = trim(($clientRow->firstname ?? '').' '.($clientRow->lastname ?? ''));
                    }
                }
            }
        }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] destinatario load error: '.$e->getMessage()); } }
        if($recipient_nif === '' || $recipient_name===''){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] WARNING destinatario incompleto factura ID='.$invoice_id.' nif="'.$recipient_nif.'" nombre="'.$recipient_name.'"'); }
        }
        $nif = $recipient_nif; // Campos API representan destinatario
        $nombre = $recipient_name;
        // Clasificación F2 (simplificada) si no hay NIF destinatario válido y total dentro de umbral
        $maxF2 = defined('VERIFACTI_F2_MAX_TOTAL') ? VERIFACTI_F2_MAX_TOTAL : 3000; // importe total con impuestos
        $recipient_nif_valid = ($nif !== '' && preg_match('/^[A-Z0-9]{5,}$/i', $nif));
        $tipo_factura_calc = $this->determineTipoFactura('invoice'); // por defecto F1
        if(!$recipient_nif_valid && isset($invoice->total) && (float)$invoice->total <= $maxF2 && (float)$invoice->total >= 0){
            $tipo_factura_calc = 'F2';
            if(function_exists('log_message')){ log_message('debug','[Verifacti] Clasificada como F2 (sin NIF destinatario, total='.$invoice->total.') ID='.$invoice_id); }
        }
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
        // Determinar serie (incluir año si el formato de Perfex lo muestra como PREF-YYYY/NUMERO)
        $serie = $invoice->prefix;
        if(function_exists('format_invoice_number')){
            try{
                $formatted_tmp = format_invoice_number($invoice->id);
                // Ej esperado: MS-2025/700  -> serie debe ser MS-2025 y numero 700
                if(is_string($formatted_tmp) && preg_match('/^([A-Z0-9_-]+)-([0-9]{4})\/(\d+)/i',$formatted_tmp,$m)){
                    $prefMatch = $m[1]; $yearMatch = $m[2]; $numMatch = $m[3];
                    // Validar que concuerda con prefix y numero actuales (ignorar ceros a la izquierda en numero)
                    if(strtoupper($prefMatch) === strtoupper($invoice->prefix) && (int)$numMatch == (int)$invoice->number){
                        $serie = $prefMatch.'-'.$yearMatch; // incluir año
                    }
                }
            }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] serie year parse invoice error: '.$e->getMessage()); } }
        }
        $invoice_data = [
            "serie" => $serie,
            "numero" => $invoice->number,
            "fecha_expedicion" => !empty($invoice->date) ? date('d-m-Y', strtotime($invoice->date)) : date('d-m-Y',strtotime($invoice->datecreated)),
            "tipo_factura" => $tipo_factura_calc,
            "descripcion" => $descripcion_final,
            "fecha_operacion" => date('d-m-Y'),
            "nif" => $nif,
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
        // Limpieza para facturas simplificadas: si tipo_factura F2 y no hay nif destinatario, eliminar claves nif/nombre (la API acepta ausencia)
        if($invoice_data['tipo_factura'] === 'F2'){
            // Siempre eliminar NIF vacío y también el nombre si no hay NIF (simplificada sin identificación destinatario)
            if(empty($invoice_data['nif'])){ unset($invoice_data['nif']); }
            if(!isset($invoice_data['nif'])){ unset($invoice_data['nombre']); }
        }
        // ==== FIN NUEVO CÁLCULO ====
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
                'status' => $result['estado'],
                'qr_url' => $result['url'],
                'qr_image_base64' => $result['qr'],
                'last_payload_hash' => $payload_hash,
                'last_fiscal_hash' => $fiscal_hash,
                'updated_at' => date('Y-m-d H:i:s')
            ],['id'=>$v_inv_id]);
        }else{
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
    if(isset($credit->date) && verifacti_is_before_start($credit->date)){
        if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: saltando por start_date ('.$credit->date.') ID='.$credit_note_id); }
        return;
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
        // Determinar serie para la nota de crédito (incluir año si el formato lo muestra)
        $serie_cn = $credit->prefix;
        if(function_exists('format_credit_note_number')){
            try{
                $formatted_cn = format_credit_note_number($credit->id);
                // Ej: NC-2025/15 -> serie NC-2025 numero 15
                if(is_string($formatted_cn) && preg_match('/^([A-Z0-9_-]+)-([0-9]{4})\/(\d+)/i',$formatted_cn,$m)){
                    $prefMatch = $m[1]; $yearMatch = $m[2]; $numMatch = $m[3];
                    if(strtoupper($prefMatch) === strtoupper($credit->prefix) && (int)$numMatch == (int)$credit->number){
                        $serie_cn = $prefMatch.'-'.$yearMatch;
                    }
                }
            }catch(\Exception $e){ if(function_exists('log_message')){ log_message('debug','[Verifacti] serie year parse credit error: '.$e->getMessage()); } }
        }
        $invoice_data = [
            'serie' => $serie_cn,
            'numero' => $credit->number,
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
                    $invoice_data['facturas_rectificadas'] = [[
                        'serie' => $orig->prefix,
                        'numero' => $orig->number,
                        'fecha_expedicion' => !empty($orig->date) ? date('d-m-Y', strtotime($orig->date)) : date('d-m-Y',strtotime($orig->datecreated))
                    ]];
                }
            }
        }
        // Pre-chequeo remoto para evitar duplicados si ya fue registrada manualmente
        try{
            $statePayload = [
                'serie' => $credit->prefix,
                'numero' => $credit->number,
                'fecha_expedicion' => !empty($credit->date) ? date('d-m-Y', strtotime($credit->date)) : date('d-m-Y')
            ];
            $remoteState = $this->ci->verifacti_lib->get_invoice_state($statePayload);
            if(isset($remoteState['success']) && $remoteState['success'] && !empty($remoteState['result'])){
                $existsRemote = $this->ci->verifacti_model->get('invoices',[ 'single'=>true,'credit_note_id'=>$credit->id ]);
                if(!$existsRemote){
                    $this->ci->verifacti_model->save('invoices',[
                        'invoice_id' => !empty($credit->invoice_id)?$credit->invoice_id:0,
                        'credit_note_id' => $credit->id,
                        'verifacti_id' => $remoteState['result']['uuid'] ?? null,
                        'status' => $remoteState['result']['estado'] ?? 'Registrado',
                        'created_at' => date('Y-m-d H:i:s'),
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                }
                if(function_exists('log_message')){ log_message('debug','[Verifacti] sendCreditNoteVerifacti: CN ya registrada remoto ID='.$credit_note_id); }
                return;
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
            $item_taxes = function_exists('get_credit_note_item_taxes') ? get_credit_note_item_taxes($item['id']) : [];
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
        $db->select('id,number,prefix,status,sent');
        $db->from(db_prefix().'invoices');
        $db->where('sent',1); // Perfex marcará sent=1 tras envío cron
        // Filtro: sólo facturas con fecha de hoy o futura
    // (Fix #6) Eliminado filtro de fecha >= hoy: permitimos envíos atrasados.
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
            $sql = "SELECT i.id,i.status,i.sent FROM ".db_prefix()."invoices i
                LEFT JOIN `$verifactiTable` v ON v.invoice_id = i.id
                                WHERE i.sent=1
                                    AND (v.id IS NULL OR (v.verifacti_id IS NULL OR v.verifacti_id=''))
                                ORDER BY i.id DESC LIMIT 30";
        $res = $db->query($sql)->result();
        if(!$res){ return; }
        foreach($res as $row){
            // Saltar drafts
            if(isset($row->status) && ( (is_numeric($row->status) && (int)$row->status === 6) || strtolower((string)$row->status)==='draft')){ continue; }
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
    $sql = "SELECT cn.id, cn.number, cn.prefix FROM ".db_prefix()."creditnotes cn
        LEFT JOIN ".db_prefix()."verifacti_invoices v ON v.credit_note_id = cn.id
        WHERE (v.id IS NULL OR (v.verifacti_id IS NULL OR v.verifacti_id=''))
        ORDER BY cn.id DESC LIMIT 30";
        $rows = $db->query($sql)->result();
        if(!$rows){ return; }
        foreach($rows as $r){
            if(function_exists('log_message')){ log_message('debug','[Verifacti] processPendingCreditNotes: enviando CN ID='.$r->id); }
        $this->sendCreditNoteVerifacti($r->id, null);
        }
    }
}