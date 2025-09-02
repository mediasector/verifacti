<?php
defined('BASEPATH') or exit('No direct script access allowed');
class Verifacti_admin extends AdminController
{
	protected $post;
	protected $option_keys = ['enable','api_key','start_date'];
	protected $email_stats_response = [];
	protected $setting_key = '';
	public function __construct(){
        parent::__construct();
	$this->setting_key = VERIFACTI_MODULE_NAME.'_setting';
        $this->post = $this->input->post();
    }

    public function setup(){
		if(!empty($this->post)){
			$option_data = array_intersect_key($this->post['verifacti'],array_flip($this->option_keys));

			$form = getVerifactiConfig();
    		if(!empty($option_data)){
    			foreach ($option_data as $key => $value) { $form[$key] = $value; }
    			update_option($this->setting_key,json_encode($form));
    		}

			set_alert('success', 'Configuración actualizada');
			redirect(VERIFACTI_MODULE_NAME."/setup");die;
    	}else{
    		
			$form = getVerifactiConfig();

	    	$data = [
		    	'page_title' 	=> _l('verifacti_setup'),
	    		'form'			=> $form
	    	];

	    	// $api_health = $this->verifacti_lib->check_health();
	    	// _print_r($api_health);exit;
	    	// $data = [];
			$this->load->view(VERIFACTI_MODULE_NAME.'/admin/setup',$data);
    	}
    }
}
