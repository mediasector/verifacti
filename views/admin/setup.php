<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php init_head(); 

?>
<div id="wrapper">
	<div class="content">
		<div class="row">
			<div class="col-md-12">
				<div class="panel_s">
					<div class="panel-heading"><?= $page_title ?> 
					</div>
					<div class="panel-body">
						<?= form_open($this->uri->uri_string()); ?>
						<div class="form-group">
							<label><?= _l('verifacti_enable') ?></label>
							<div>
								<div class="radio radio-primary radio-inline">
									<input type="radio" name="verifacti[enable]" value="yes" id="verifacti_enable_yes" <?= isVerifactiEnable() ? 'checked' : '' ?>>
									<label for="verifacti_enable_yes"><?= _l('enable') ?></label>
								</div>
								<div class="radio radio-primary radio-inline">
									<input type="radio" name="verifacti[enable]" value="no" id="verifacti_enable_no" <?= isVerifactiEnable() ? '' : 'checked' ?>>
									<label for="verifacti_enable_no"><?= _l('disable') ?></label>
								</div>
							</div>
						</div>
						<div><hr/></div>
							<div class="form-group">
								<label for="verifacti_api_key"><?= _l('verifacti_api_key') ?></label>
								<input type="password" class="form-control" name="verifacti[api_key]" id="verifacti_api_key" placeholder="Enter <?= _l('mautic'),' ',_l('verifacti_api_key') ?>" value="<?= $form['api_key'] ?? '' ?>" required>
							</div>
							<div class="form-group">
								<label for="verifacti_start_date">Entrada en funcionamiento</label>
								<?php
								$hoy = date('Y-m-d');
								$valor_fecha = isset($form['start_date']) && $form['start_date'] ? html_escape($form['start_date']) : $hoy;
								?>
								<input type="date" class="form-control" name="verifacti[start_date]" id="verifacti_start_date" value="<?= $valor_fecha ?>" placeholder="YYYY-MM-DD">
								<p class="help-block small">Facturas y notas de crédito con fecha de expedición anterior NO se informarán a Verifacti.</p>
							</div>
							
							<button type="submit" class="btn btn-primary">Submit</button>
						<?= form_close(); ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<?php init_tail(); ?>