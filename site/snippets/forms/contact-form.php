<?php
/**
 * General contact form. Every label, placeholder, help and message string is
 * editable via the page's form fields — nothing is hard-coded here.
 *
 * @var \Kirby\Cms\Page   $page
 * @var \Breakfast\Platform\Forms\FormResult|null $result
 * @var array             $old
 */
$errors = $result?->errors ?? [];
$old    = $old ?? [];
?>
<?php if ($result && $result->success === false && $result->generalError): ?>
  <div class="form__error-summary" role="alert" tabindex="-1">
    <strong><?= esc($result->generalError) ?></strong>
    <?php if ($errors): ?>
      <ul style="margin-top:var(--s-2)">
        <?php foreach ($errors as $field => $msg): ?><li><a href="#field-<?= esc($field) ?>"><?= esc($msg) ?></a></li><?php endforeach ?>
      </ul>
    <?php endif ?>
  </div>
<?php elseif ($result && $result->success === false && $errors): ?>
  <div class="form__error-summary" role="alert" tabindex="-1">
    <strong><?= esc(t('breakfast.form.fix', 'Please fix the highlighted fields.')) ?></strong>
    <ul style="margin-top:var(--s-2)">
      <?php foreach ($errors as $field => $msg): ?><li><a href="#field-<?= esc($field) ?>"><?= esc($msg) ?></a></li><?php endforeach ?>
    </ul>
  </div>
<?php endif ?>

<form class="form" method="post" action="<?= esc($page->url()) ?>#form" data-guarded data-form="contact" novalidate>
  <input type="hidden" name="csrf" value="<?= esc(csrf()) ?>">
  <input type="hidden" name="rendered_at" value="<?= time() ?>">
  <input type="hidden" name="landing_page" value="">
  <div class="honeypot" aria-hidden="true"><label>Leave this empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>

  <div class="field">
    <label class="field__label" for="field-name"><?= esc($page->form_name_label()->or('Your name')) ?> <span class="field__req" aria-hidden="true">*</span></label>
    <input class="input" id="field-name" name="name" type="text" required maxlength="120"
           placeholder="<?= esc($page->form_name_placeholder()) ?>"
           value="<?= esc($old['name'] ?? '') ?>"
           <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="err-name"' : '' ?>>
    <?php if (isset($errors['name'])): ?><p class="field__error" id="err-name"><?= esc($errors['name']) ?></p><?php endif ?>
  </div>

  <div class="field">
    <label class="field__label" for="field-email"><?= esc($page->form_email_label()->or('Email address')) ?> <span class="field__req" aria-hidden="true">*</span></label>
    <input class="input" id="field-email" name="email" type="email" required maxlength="254" autocomplete="email"
           placeholder="<?= esc($page->form_email_placeholder()) ?>"
           value="<?= esc($old['email'] ?? '') ?>"
           <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="err-email"' : '' ?>>
    <?php if (isset($errors['email'])): ?><p class="field__error" id="err-email"><?= esc($errors['email']) ?></p><?php endif ?>
  </div>

  <div class="field">
    <label class="field__label" for="field-company"><?= esc($page->form_company_label()->or('Company')) ?></label>
    <input class="input" id="field-company" name="company" type="text" maxlength="160"
           placeholder="<?= esc($page->form_company_placeholder()) ?>" value="<?= esc($old['company'] ?? '') ?>">
  </div>

  <div class="field">
    <label class="field__label" for="field-message"><?= esc($page->form_message_label()->or('How can we help?')) ?> <span class="field__req" aria-hidden="true">*</span></label>
    <textarea class="textarea" id="field-message" name="message" required minlength="10" maxlength="5000"
              placeholder="<?= esc($page->form_message_placeholder()) ?>"
              <?= $page->form_message_help()->isNotEmpty() ? 'aria-describedby="help-message"' : '' ?>
              <?= isset($errors['message']) ? 'aria-invalid="true" aria-describedby="err-message"' : '' ?>><?= esc($old['message'] ?? '') ?></textarea>
    <?php if ($page->form_message_help()->isNotEmpty()): ?><p class="field__hint" id="help-message"><?= esc($page->form_message_help()) ?></p><?php endif ?>
    <?php if (isset($errors['message'])): ?><p class="field__error" id="err-message"><?= esc($errors['message']) ?></p><?php endif ?>
  </div>

  <?php if ($page->form_consent_text()->isNotEmpty()): ?>
  <div class="checkbox">
    <input type="checkbox" id="field-consent" name="consent" value="1">
    <label for="field-consent"><?= esc($page->form_consent_text()) ?></label>
  </div>
  <?php endif ?>

  <div><button class="btn btn--secondary btn--lg" type="submit"><?= esc($page->form_submit_label()->or('Send enquiry')) ?></button></div>
</form>
