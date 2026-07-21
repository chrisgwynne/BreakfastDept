<?php
/**
 * Start-a-project enquiry form. All copy editable via page form fields.
 * @var \Kirby\Cms\Page $page
 * @var \Breakfast\Platform\Forms\FormResult|null $result
 * @var array $old
 */
$errors = $result?->errors ?? [];
$old    = $old ?? [];
$field = function (string $name) use ($old) { return esc($old[$name] ?? ''); };
?>
<?php if ($result && $result->success === false): ?>
  <div class="form__error-summary" role="alert" tabindex="-1">
    <strong><?= esc($result->generalError ?: t('breakfast.form.fix', 'Please fix the highlighted fields.')) ?></strong>
    <?php if ($errors): ?><ul style="margin-top:var(--s-2)"><?php foreach ($errors as $f => $m): ?><li><a href="#field-<?= esc($f) ?>"><?= esc($m) ?></a></li><?php endforeach ?></ul><?php endif ?>
  </div>
<?php endif ?>

<form class="form form--wide" method="post" action="<?= esc($page->url()) ?>#form" data-guarded data-form="start-project" novalidate>
  <input type="hidden" name="csrf" value="<?= esc(csrf()) ?>">
  <input type="hidden" name="rendered_at" value="<?= time() ?>">
  <input type="hidden" name="landing_page" value="">
  <div class="honeypot" aria-hidden="true"><label>Leave this empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>

  <div class="grid grid--2">
    <div class="field">
      <label class="field__label" for="field-name"><?= esc($page->form_name_label()->or('Your name')) ?> <span class="field__req">*</span></label>
      <input class="input" id="field-name" name="name" type="text" required maxlength="120" value="<?= $field('name') ?>" <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['name'])): ?><p class="field__error"><?= esc($errors['name']) ?></p><?php endif ?>
    </div>
    <div class="field">
      <label class="field__label" for="field-email"><?= esc($page->form_email_label()->or('Email address')) ?> <span class="field__req">*</span></label>
      <input class="input" id="field-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= $field('email') ?>" <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['email'])): ?><p class="field__error"><?= esc($errors['email']) ?></p><?php endif ?>
    </div>
    <div class="field">
      <label class="field__label" for="field-phone"><?= esc(t('breakfast.form.phone', 'Phone (optional)')) ?></label>
      <input class="input" id="field-phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?= $field('phone') ?>">
    </div>
    <div class="field">
      <label class="field__label" for="field-company"><?= esc($page->form_company_label()->or('Company')) ?></label>
      <input class="input" id="field-company" name="company" type="text" maxlength="160" value="<?= $field('company') ?>">
    </div>
  </div>

  <div class="field">
    <label class="field__label" for="field-website"><?= esc(t('breakfast.form.website', 'Existing website (if any)')) ?></label>
    <input class="input" id="field-website" name="website" type="url" inputmode="url" maxlength="200" placeholder="yourbusiness.co.uk" value="<?= $field('website') ?>" <?= isset($errors['website']) ? 'aria-invalid="true"' : '' ?>>
    <?php if (isset($errors['website'])): ?><p class="field__error"><?= esc($errors['website']) ?></p><?php endif ?>
  </div>

  <div class="field">
    <label class="field__label" for="field-business"><?= esc(t('breakfast.form.business', 'What does your business do?')) ?></label>
    <textarea class="textarea" id="field-business" name="business" maxlength="2000"><?= $field('business') ?></textarea>
  </div>

  <div class="field">
    <label class="field__label" for="field-help"><?= esc(t('breakfast.form.help', 'What do you need help with?')) ?></label>
    <textarea class="textarea" id="field-help" name="help" maxlength="2000"><?= $field('help') ?></textarea>
  </div>

  <?php $services = page('services'); if ($services): ?>
  <fieldset class="field" style="border:0;padding:0;margin:0">
    <legend class="field__label"><?= esc($page->form_services_label()->or('Which services are you interested in?')) ?></legend>
    <?php if ($page->form_services_help()->isNotEmpty()): ?><p class="field__hint"><?= esc($page->form_services_help()) ?></p><?php endif ?>
    <div class="grid grid--2" style="gap:var(--s-2);margin-top:var(--s-2)">
      <?php foreach ($services->children()->listed() as $s): ?>
        <label class="checkbox"><input type="checkbox" name="services[]" value="<?= esc($s->title()) ?>"> <?= esc($s->title()) ?></label>
      <?php endforeach ?>
    </div>
  </fieldset>
  <?php endif ?>

  <div class="grid grid--2">
    <div class="field">
      <label class="field__label" for="field-budget"><?= esc($page->form_budget_label()->or('Approximate budget')) ?></label>
      <input class="input" id="field-budget" name="budget" type="text" maxlength="60" value="<?= $field('budget') ?>">
      <?php if ($page->form_budget_help()->isNotEmpty()): ?><p class="field__hint"><?= esc($page->form_budget_help()) ?></p><?php endif ?>
    </div>
    <div class="field">
      <label class="field__label" for="field-launch"><?= esc($page->form_timeline_label()->or('Ideal launch date')) ?></label>
      <input class="input" id="field-launch" name="launch_date" type="text" maxlength="40" value="<?= $field('launch_date') ?>">
      <?php if ($page->form_timeline_help()->isNotEmpty()): ?><p class="field__hint"><?= esc($page->form_timeline_help()) ?></p><?php endif ?>
    </div>
  </div>

  <div class="field">
    <label class="field__label" for="field-heard"><?= esc(t('breakfast.form.heard', 'How did you hear about us?')) ?></label>
    <input class="input" id="field-heard" name="heard_about" type="text" maxlength="120" value="<?= $field('heard_about') ?>">
  </div>

  <div class="field">
    <label class="field__label" for="field-message"><?= esc($page->form_message_label()->or('Anything else?')) ?></label>
    <textarea class="textarea" id="field-message" name="message" maxlength="5000"><?= $field('message') ?></textarea>
  </div>

  <?php if ($page->form_consent_text()->isNotEmpty()): ?>
  <div class="checkbox"><input type="checkbox" id="field-consent" name="consent" value="1"><label for="field-consent"><?= esc($page->form_consent_text()) ?></label></div>
  <?php endif ?>

  <div><button class="btn btn--secondary btn--lg" type="submit"><?= esc($page->form_submit_label()->or('Send project brief')) ?></button></div>
</form>
