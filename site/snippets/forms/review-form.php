<?php
/**
 * Website-review form. Same secure pipeline as the contact form; labels fall
 * back to sensible copy but remain overridable via the page's form fields.
 *
 * @var \Kirby\Cms\Page   $page
 * @var \Breakfast\Platform\Forms\FormResult|null $result
 * @var array             $old
 */
$errors = $result?->errors ?? [];
$old    = $old ?? [];
?>
<?php if ($result && $result->success === false && ($result->generalError || $errors)): ?>
  <div class="form__error-summary" role="alert" tabindex="-1">
    <strong><?= esc($result->generalError ?: t('breakfast.form.fix', 'Please fix the highlighted fields.')) ?></strong>
    <?php if ($errors): ?>
      <ul style="margin-top:var(--s-2)">
        <?php foreach ($errors as $field => $msg): ?><li><a href="#field-<?= esc($field) ?>"><?= esc($msg) ?></a></li><?php endforeach ?>
      </ul>
    <?php endif ?>
  </div>
<?php endif ?>

<form class="form" method="post" action="<?= esc($page->url()) ?>#form" data-guarded data-form="website-review" novalidate>
  <input type="hidden" name="csrf" value="<?= esc(csrf()) ?>">
  <input type="hidden" name="rendered_at" value="<?= time() ?>">
  <input type="hidden" name="landing_page" value="">
  <div class="honeypot" aria-hidden="true"><label>Leave this empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>

  <div class="form__row">
    <div class="field">
      <label class="field__label" for="field-name"><?= esc($page->form_name_label()->or('Your name')) ?> <span class="field__req" aria-hidden="true">*</span></label>
      <input class="input" id="field-name" name="name" type="text" required maxlength="120"
             value="<?= esc($old['name'] ?? '') ?>"
             <?= isset($errors['name']) ? 'aria-invalid="true" aria-describedby="err-name"' : '' ?>>
      <?php if (isset($errors['name'])): ?><p class="field__error" id="err-name"><?= esc($errors['name']) ?></p><?php endif ?>
    </div>
    <div class="field">
      <label class="field__label" for="field-email"><?= esc($page->form_email_label()->or('Email address')) ?> <span class="field__req" aria-hidden="true">*</span></label>
      <input class="input" id="field-email" name="email" type="email" required maxlength="254" autocomplete="email"
             value="<?= esc($old['email'] ?? '') ?>"
             <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="err-email"' : '' ?>>
      <?php if (isset($errors['email'])): ?><p class="field__error" id="err-email"><?= esc($errors['email']) ?></p><?php endif ?>
    </div>
  </div>

  <div class="form__row">
    <div class="field">
      <label class="field__label" for="field-company"><?= esc($page->form_business_label()->or('Business name')) ?></label>
      <input class="input" id="field-company" name="company" type="text" maxlength="160" value="<?= esc($old['company'] ?? '') ?>">
    </div>
    <div class="field">
      <label class="field__label" for="field-location"><?= esc($page->form_location_label()->or('Where are you based?')) ?></label>
      <input class="input" id="field-location" name="location" type="text" maxlength="120" value="<?= esc($old['location'] ?? '') ?>">
    </div>
  </div>

  <div class="field">
    <label class="field__label" for="field-website"><?= esc($page->form_website_label()->or('Your current website')) ?></label>
    <input class="input" id="field-website" name="website" type="url" maxlength="200" inputmode="url"
           placeholder="https://" value="<?= esc($old['website'] ?? '') ?>"
           <?= isset($errors['website']) ? 'aria-invalid="true" aria-describedby="err-website"' : '' ?>>
    <?php if (isset($errors['website'])): ?><p class="field__error" id="err-website"><?= esc($errors['website']) ?></p><?php endif ?>
  </div>

  <div class="field">
    <label class="field__label" for="field-issues"><?= esc($page->form_issues_label()->or('What feels wrong with it?')) ?> <span class="field__req" aria-hidden="true">*</span></label>
    <textarea class="textarea" id="field-issues" name="issues" required minlength="10" maxlength="5000"
              placeholder="<?= esc($page->form_issues_placeholder()->or('Dated, awkward on phones, hard to update, no enquiries…')) ?>"
              <?= isset($errors['issues']) ? 'aria-invalid="true" aria-describedby="err-issues"' : '' ?>><?= esc($old['issues'] ?? '') ?></textarea>
    <?php if (isset($errors['issues'])): ?><p class="field__error" id="err-issues"><?= esc($errors['issues']) ?></p><?php endif ?>
  </div>

  <div class="field">
    <label class="field__label" for="field-phone"><?= esc($page->form_phone_label()->or('Phone (optional)')) ?></label>
    <input class="input" id="field-phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?= esc($old['phone'] ?? '') ?>">
  </div>

  <?php if ($page->form_consent_text()->isNotEmpty()): ?>
  <div class="checkbox">
    <input type="checkbox" id="field-consent" name="consent" value="1">
    <label for="field-consent"><?= esc($page->form_consent_text()) ?></label>
  </div>
  <?php endif ?>

  <div><button class="btn btn--secondary btn--lg" type="submit"><?= esc($page->form_submit_label()->or('Ask for a review')) ?></button></div>
</form>
