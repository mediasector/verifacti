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
							
							<button type="submit" class="btn btn-primary">Submit</button>
						<?= form_close(); ?>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<?php init_tail(); ?>