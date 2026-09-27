<?php
// Společná pole registrace a založení správce. Vkládá je auth.php.
?>
<div class="field-grid two">
  <label>Zobrazované jméno<input name="display_name" required autocomplete="name" maxlength="60" placeholder="Jan Novák"></label>
  <label>Přihlašovací jméno<input name="login" required autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="64" placeholder="jan.novak"><small class="field-hint" data-login-hint></small></label>
</div>
<label><span>E-mail <small class="optional">nepovinný, jen pro správce</small></span><input name="email" type="email" autocomplete="email" maxlength="120"></label>
<fieldset class="secret-mode">
  <legend>Jak se budeš přihlašovat</legend>
  <label class="mode-card"><input type="radio" name="secret_mode" value="password" checked><span><strong>Heslo</strong><small>Deník leží na serveru nešifrovaný. Zapomenuté heslo správce obnoví.</small></span></label>
  <label class="mode-card mode-card-key<?= $state['encryption_available'] ? '' : ' is-disabled' ?>"><input type="radio" name="secret_mode" value="key"<?= $state['encryption_available'] ? '' : ' disabled' ?>><span><strong><?= icon('lock') ?>Šifrovaný deník</strong><small>Místo hesla dostaneš přístupový klíč. Deník i screenshoty se šifrují a bez klíče je nepřečte nikdo, ani správce.</small></span></label>
</fieldset>
<div class="password-fields" data-password-fields>
  <div class="field-grid two">
    <label>Heslo<input name="password" type="password" autocomplete="new-password" minlength="<?= PASSWORD_MIN_LENGTH ?>" placeholder="aspoň <?= PASSWORD_MIN_LENGTH ?> znaků"><small class="field-hint">Libovolné znaky včetně číslic, teček a symbolů.</small></label>
    <label>Heslo znovu<input name="password_again" type="password" autocomplete="new-password"></label>
  </div>
</div>
