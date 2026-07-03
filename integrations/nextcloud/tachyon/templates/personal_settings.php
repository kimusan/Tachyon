<div class="section">
	<form class="tachyon" action="personal.php" method="post">
		<input type="hidden" name="requesttoken" value="<?php echo $_['requesttoken'] ?>" id="requesttoken">
		<fieldset class="personalblock">
			<h2><?php echo $l->t('Tachyon Webmail'); ?></h2>
			<p>
				<?php echo $l->t('Enter an email and password to auto-login to Tachyon.'); ?>
			</p>
			<p>
				<input type="text" id="tachyon-email" name="tachyon-email"
					value="<?php echo $_['tachyon-email']; ?>" placeholder="<?php echo($l->t('Email')); ?>" />

				<input type="password" id="tachyon-password" name="tachyon-password"
					value="<?php echo $_['tachyon-password']; ?>" placeholder="<?php echo($l->t('Password')); ?>" />

				<button id="tachyon-save-button" name="tachyon-save-button"><?php echo($l->t('Save')); ?></button>
				&nbsp;&nbsp;<span class="tachyon-result-desc"></span>
			</p>
		</fieldset>
	</form>
</div>
