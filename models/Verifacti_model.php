<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Verifacti Model
 */
class Verifacti_model extends App_Model{
    protected $tables;
    public function __construct(){
        parent::__construct();
    $this->tables['invoices'] = db_prefix()."verifacti_invoices";
    $this->tables['logs'] = db_prefix()."verifacti_api_logs";
    }

    public function get($table,$where,$orderby=[]){
        if(!empty($orderby)){
            $this->db->order_by($orderby['column'],$orderby['order']);
        }
        if(isset($where['single'])){
            unset($where['single']);
            $this->db->where($where);
            $result = $this->db->get($this->tables[$table])->row();
        }else{
            $this->db->where($where);
            $result = $this->db->get($this->tables[$table])->result();
        }
        return $result;
    }

    public function delete($table,$where){
        unset($where['single']);
    
        $this->db->where($where);
        $result = $this->db->delete($this->tables[$table]);
        
        return $result;
    }
    public function save($table,$data,$where=[]){
        unset($where['single']);
        if(!empty($where)){
            $this->db->update($this->tables[$table],$data,$where);
            return true;
        }
        $this->db->insert($this->tables[$table],$data);
        return $this->db->insert_id();
    }

    public function getContacts($where=[]){
        $single = isset($where['single']) ? true : false;
        unset($where['single']);

        $tbl_contacts = db_prefix()."contacts";

        $this->db->select("*");
        $this->db->from($this->tables['contacts']);
        $this->db->join($tbl_contacts,"{$tbl_contacts}.id = {$this->tables['contacts']}.contact_id");
        $this->db->where($where);
        
        if($single){
            $result = $this->db->get($this->tables[$table])->row_array();
        }else{
            $result = $this->db->get($this->tables[$table])->result_array();
        }
    }

    public function getSyncData($module='',$where=[]){
        $single = isset($where['single']) ? true : false;
        unset($where['single']);
        $rows = false;
        if($module == 'companies'){
            $tbl_companies = db_prefix()."clients";
            $this->db->select("*");
            $this->db->from($tbl_companies);
            $sql_where = "userid NOT IN (SELECT company_id FROM {$this->tables['companies']})";
            $this->db->where($sql_where);
            $this->db->where($where);
            $rows = $this->db->get()->result_array();
        }
        if($module == 'contacts'){
            $tbl_contacts = db_prefix()."contacts";
            $this->db->select("*");
            $this->db->from($tbl_contacts);
            $sql_where = "id NOT IN (SELECT contact_id from {$this->tables['contacts']})";
            // $sql_where .= " AND useriVd in (SELECT company_id FROM {$this->tables['companies']})";
            $this->db->where($sql_where);
            $this->db->where($where);
            $rows = $this->db->get()->result_array();
        }
        return $rows;
    }

}