<div class="section">
	<form class="tachyon" action="admin.php" method="post">
		<input type="hidden" name="requesttoken" value="<?php echo $_['requesttoken'] ?>" id="requesttoken">
		<fieldset class="personalblock">
			<h2><?php echo($l->t('Tachyon Webmail')); ?></h2>
			<br />
			<?php if ($_['tachyon-admin-panel-link']) { ?>
			<p>
				<a href="<?php echo $_['tachyon-admin-panel-link'] ?>" style="text-decoration: underline">
					<?php echo($l->t('Go to Tachyon Webmail admin panel')); ?>
				</a>
			<?php if ($_['tachyon-admin-password']) { ?>
				<br/>
				Username: admin<br/>
				Temporary password: <?php echo $_['tachyon-admin-password']; ?>
			<?php } ?>
			</p>
			<br />
			<?php } ?>
			<p>
				<div style="display: flex;">
					<input type="radio" id="tachyon-noautologin" name="tachyon-autologin" value="0" <?php if (!$_['tachyon-autologin']&&!$_['tachyon-autologin-with-email']) echo 'checked="checked"'; ?> />
					<label style="margin: auto 5px;" for="tachyon-noautologin">
						<?php echo($l->t('Users will login manually, or define credentials in their personal settings for automatic logins.')); ?>
					</label>
				</div>
				<div style="display: flex;">
					<input type="radio" id="tachyon-autologin" name="tachyon-autologin" value="1" <?php if ($_['tachyon-autologin']) echo 'checked="checked"'; ?> />
					<label style="margin: auto 5px;" for="tachyon-autologin">
						<?php echo($l->t('Attempt to automatically login users with their ownCloud username and password, or user-defined credentials, if set.')); ?>
					</label>
				</div>
				<div style="display: flex;">
					<input type="radio" id="tachyon-autologin-with-email" name="tachyon-autologin" value="2" <?php if ($_['tachyon-autologin-with-email']) echo 'checked="checked"'; ?> />
					<label style="margin: auto 5px;" for="tachyon-autologin-with-email">
						<?php echo($l->t('Attempt to automatically login users with their ownCloud email and password, or user-defined credentials, if set.')); ?>
					</label>
				</div>
			</p>
			<br />
			<p>
				<input id="tachyon-no-embed" name="tachyon-no-embed" type="checkbox" class="checkbox" <?php if ($_['tachyon-no-embed']) echo 'checked="checked"'; ?>>
				<label for="tachyon-no-embed">Don't fully integrate in ownCloud, use in iframe</label>
			</p>
			<br />
			<p>
				<input id="tachyon-debug" name="tachyon-debug" type="checkbox" class="checkbox" <?php if ($_['tachyon-debug']) echo 'checked="checked"'; ?>>
				<label for="tachyon-debug">Debug</label>
			</p>
			<br />
			<?php if ($_['can-import-rainloop']) { ?>
			<p>
				<input id="import-rainloop" name="import-rainloop" type="checkbox" class="checkbox">
				<label for="import-rainloop">Import RainLoop data</label>
			</p>
			<br />
			<?php } ?>
			<p>
				<button id="tachyon-save-button" name="tachyon-save-button"><?php echo($l->t('Save')); ?></button>
				<div class="tachyon-result-desc" style="white-space: pre"></div>
			</p>
		</fieldset>
	</form>
</div>
