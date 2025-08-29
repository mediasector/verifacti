<?php
$key = VERIFACTI_MODULE_NAME.'_setting';
if(!get_option($key)){
	$data = [
		'enable' => 'no',
		'api_key' => '',
	];
	update_option($key,json_encode($data));
}
$charset = APP_DB_CHARSET;
$tableLegacy = db_prefix() . 'verifacti_invoices';
$table = db_prefix() . 'verifacti_invoices';
// Renombrar tabla antigua si existe
if($CI->db->table_exists($tableLegacy) && !$CI->db->table_exists($table)){
	$CI->db->query('RENAME TABLE `'.$tableLegacy.'` TO `'.$table.'`');
}
if (!$CI->db->table_exists($table)) {
	$sql = "CREATE TABLE `{$table}` (
	  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	  `invoice_id` INT UNSIGNED NOT NULL, -- Perfex invoice reference
	  `credit_note_id` INT UNSIGNED DEFAULT NULL, -- Nota de crédito asociada (si aplica)
	  `verifacti_id` VARCHAR(255) DEFAULT NULL, -- ID desde API Verifacti
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
	$CI->db->query($sql);
}
// Añadir columnas nuevas si la tabla ya existía
if($CI->db->table_exists($table)){
	if(!$CI->db->field_exists('last_payload_hash',$table)){
		$CI->db->query("ALTER TABLE `{$table}` ADD `last_payload_hash` VARCHAR(64) DEFAULT NULL AFTER `qr_image_base64`");
	}
	if(!$CI->db->field_exists('last_fiscal_hash',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `last_fiscal_hash` VARCHAR(64) DEFAULT NULL AFTER `last_payload_hash`");
		}
	if(!$CI->db->field_exists('mod_count',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `mod_count` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `last_fiscal_hash`");
		}
	if(!$CI->db->field_exists('credit_note_id',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `credit_note_id` INT UNSIGNED DEFAULT NULL AFTER `invoice_id`");
			// Ajustar índice único compuesto
			try { $CI->db->query("ALTER TABLE `{$table}` DROP INDEX `invoice_id`"); } catch (Exception $e) {}
			try { $CI->db->query("CREATE UNIQUE INDEX `uniq_invoice_credit` ON `{$table}` (`invoice_id`,`credit_note_id`)"); } catch (Exception $e) {}
		}
	if(!$CI->db->field_exists('estado_api',$table)){
		$CI->db->query("ALTER TABLE `{$table}` ADD `estado_api` VARCHAR(32) DEFAULT NULL AFTER `mod_count`");
	}
	if(!$CI->db->field_exists('error_code',$table)){
		$CI->db->query("ALTER TABLE `{$table}` ADD `error_code` VARCHAR(32) DEFAULT NULL AFTER `estado_api`");
	}
	if(!$CI->db->field_exists('error_message',$table)){
		$CI->db->query("ALTER TABLE `{$table}` ADD `error_message` TEXT DEFAULT NULL AFTER `error_code`");
	}
	if(!$CI->db->field_exists('last_status_checked_at',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `last_status_checked_at` DATETIME DEFAULT NULL AFTER `error_message`");
		}
	if(!$CI->db->field_exists('canceled_at',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `canceled_at` DATETIME DEFAULT NULL AFTER `last_status_checked_at`");
		}
	if(!$CI->db->field_exists('cancel_reason',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `cancel_reason` VARCHAR(255) DEFAULT NULL AFTER `canceled_at`");
		}
	if(!$CI->db->field_exists('cancel_response',$table)){
			$CI->db->query("ALTER TABLE `{$table}` ADD `cancel_response` LONGTEXT DEFAULT NULL AFTER `cancel_reason`");
		}
}


$legacyLogs = db_prefix().'verifacti_api_logs';
$table = db_prefix() . 'verifacti_api_logs';
if($CI->db->table_exists($legacyLogs) && !$CI->db->table_exists($table)){
	$CI->db->query('RENAME TABLE `'.$legacyLogs.'` TO `'.$table.'`');
}
if (!$CI->db->table_exists($table)) {
	$sql = "CREATE TABLE `{$table}` (
	  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
	  `endpoint` VARCHAR(255) NOT NULL,
	  `request_data` LONGTEXT DEFAULT NULL,
	  `response_data` LONGTEXT DEFAULT NULL,
	  `http_status` SMALLINT DEFAULT NULL,
	  `created_at` DATETIME DEFAULT NULL,
	  `updated_at` DATETIME DEFAULT NULL,
	  PRIMARY KEY (`id`)
	) ENGINE=InnoDB DEFAULT CHARSET={$charset};
	";
	$CI->db->query($sql);
}