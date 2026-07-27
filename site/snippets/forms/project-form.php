<?php
/**
 * Start-a-project enquiry — a single real form, progressively enhanced into a
 * three-step flow by JS. Without JS every step is visible and the form submits
 * normally; server-side validation (FormProcessor) is always authoritative.
 *
 * @var \Kirby\Cms\Page $page
 * @var \Breakfast\Platform\Forms\FormResult|null $result
 * @var array $old
 */
$errors = $result?->errors ?? [];
$old    = $old ?? [];
$field  = function (string $name) use ($old) { return esc($old[$name] ?? ''); };
// Which step each field lives in — used to jump to the first server error.
$stepOf = [
    'name' => 1, 'email' => 1, 'company' => 1, 'help' => 1,
    'website' => 2, 'services' => 2, 'budget' => 2, 'launch_date' => 2, 'business' => 2, 'message' => 2,
    'phone' => 3, 'heard_about' => 3, 'consent' => 3,
];
$errorStep = 1;
foreach (array_keys($errors) as $ef) {
    if (isset($stepOf[$ef])) { $errorStep = $stepOf[$ef]; break; }
}
?>
<?php if ($result && $result->success === false): ?>
  <div class="form__error-summary" role="alert" tabindex="-1">
    <strong><?= esc($result->generalError ?: t('breakfast.form.fix', 'Please fix the highlighted fields.')) ?></strong>
    <?php if ($errors): ?><ul style="margin-top:var(--s-2)"><?php foreach ($errors as $f => $m): ?><li><a href="#field-<?= esc($f) ?>"><?= esc($m) ?></a></li><?php endforeach ?></ul><?php endif ?>
  </div>
<?php endif ?>

<form class="form form--wide form--steps" method="post" action="<?= esc($page->url()) ?>#form" data-guarded data-form="start-project" data-steps data-error-step="<?= (int) $errorStep ?>" novalidate>
  <input type="hidden" name="csrf" value="<?= esc(csrf()) ?>">
  <input type="hidden" name="rendered_at" value="<?= time() ?>">
  <input type="hidden" name="landing_page" value="">
  <div class="honeypot" aria-hidden="true"><label>Leave this empty<input type="text" name="website_url" tabindex="-1" autocomplete="off"></label></div>

  <?php /* Progress indicator (shown only when JS enhances the form). */ ?>
  <ol class="fprogress" data-steps-progress aria-hidden="true">
    <li class="fprogress__item" data-progress="1"><span class="fprogress__num">1</span> <span class="fprogress__label">About you</span></li>
    <li class="fprogress__item" data-progress="2"><span class="fprogress__num">2</span> <span class="fprogress__label">The project</span></li>
    <li class="fprogress__item" data-progress="3"><span class="fprogress__num">3</span> <span class="fprogress__label">Finish</span></li>
  </ol>

  <?php /* ---- Step 1: about you ---- */ ?>
  <section class="fstep" data-step="1" aria-label="Step 1 of 3: about you">
    <h2 class="fstep__title" tabindex="-1"><?= esc(t('breakfast.form.step1', 'A little about you')) ?></h2>
    <div class="grid grid--2">
      <div class="field">
        <label class="field__label" for="field-name"><?= esc($page->form_name_label()->or('Your name')) ?> <span class="field__req">*</span></label>
        <input class="input" id="field-name" name="name" type="text" required maxlength="120" autocomplete="name" value="<?= $field('name') ?>" <?= isset($errors['name']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['name'])): ?><p class="field__error" id="err-name"><?= esc($errors['name']) ?></p><?php endif ?>
      </div>
      <div class="field">
        <label class="field__label" for="field-email"><?= esc($page->form_email_label()->or('Email address')) ?> <span class="field__req">*</span></label>
        <input class="input" id="field-email" name="email" type="email" required maxlength="254" autocomplete="email" value="<?= $field('email') ?>" <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>>
        <?php if (isset($errors['email'])): ?><p class="field__error" id="err-email"><?= esc($errors['email']) ?></p><?php endif ?>
      </div>
    </div>
    <div class="field">
      <label class="field__label" for="field-company"><?= esc($page->form_company_label()->or('Business name')) ?></label>
      <input class="input" id="field-company" name="company" type="text" maxlength="160" autocomplete="organization" value="<?= $field('company') ?>">
    </div>
    <div class="field">
      <label class="field__label" for="field-help"><?= esc(t('breakfast.form.help', 'What do you need help with?')) ?></label>
      <p class="field__hint"><?= esc(t('breakfast.form.help_hint', 'A new website, a rescue, an online shop — or you’re not sure yet. All fine.')) ?></p>
      <textarea class="textarea" id="field-help" name="help" maxlength="2000"><?= $field('help') ?></textarea>
    </div>
    <div class="fstep__nav" data-steps-nav>
      <span></span>
      <button class="btn btn--secondary" type="button" data-step-next>Next <span aria-hidden="true">→</span></button>
    </div>
  </section>

  <?php /* ---- Step 2: the project ---- */ ?>
  <section class="fstep" data-step="2" aria-label="Step 2 of 3: the project">
    <h2 class="fstep__title" tabindex="-1"><?= esc(t('breakfast.form.step2', 'About the project')) ?></h2>
    <div class="field">
      <label class="field__label" for="field-business"><?= esc(t('breakfast.form.business', 'What does your business do?')) ?></label>
      <textarea class="textarea" id="field-business" name="business" maxlength="2000"><?= $field('business') ?></textarea>
    </div>
    <div class="field">
      <label class="field__label" for="field-website"><?= esc(t('breakfast.form.website', 'Existing website (if any)')) ?></label>
      <input class="input" id="field-website" name="website" type="url" inputmode="url" maxlength="200" autocomplete="url" placeholder="yourbusiness.co.uk" value="<?= $field('website') ?>" <?= isset($errors['website']) ? 'aria-invalid="true"' : '' ?>>
      <?php if (isset($errors['website'])): ?><p class="field__error" id="err-website"><?= esc($errors['website']) ?></p><?php endif ?>
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
        <label class="field__label" for="field-launch"><?= esc($page->form_timeline_label()->or('Ideal timing')) ?></label>
        <input class="input" id="field-launch" name="launch_date" type="text" maxlength="40" value="<?= $field('launch_date') ?>">
        <?php if ($page->form_timeline_help()->isNotEmpty()): ?><p class="field__hint"><?= esc($page->form_timeline_help()) ?></p><?php endif ?>
      </div>
    </div>
    <div class="field">
      <label class="field__label" for="field-message"><?= esc($page->form_message_label()->or('Project details')) ?></label>
      <textarea class="textarea" id="field-message" name="message" maxlength="5000"><?= $field('message') ?></textarea>
    </div>
    <div class="fstep__nav" data-steps-nav>
      <button class="btn btn--ghost" type="button" data-step-back><span aria-hidden="true">←</span> Back</button>
      <button class="btn btn--secondary" type="button" data-step-next>Next <span aria-hidden="true">→</span></button>
    </div>
  </section>

  <?php /* ---- Step 3: finish ---- */ ?>
  <section class="fstep" data-step="3" aria-label="Step 3 of 3: finish">
    <h2 class="fstep__title" tabindex="-1"><?= esc(t('breakfast.form.step3', 'Almost done')) ?></h2>
    <div class="grid grid--2">
      <div class="field">
        <label class="field__label" for="field-phone"><?= esc(t('breakfast.form.phone', 'Phone (optional)')) ?></label>
        <input class="input" id="field-phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?= $field('phone') ?>">
      </div>
      <div class="field">
        <label class="field__label" for="field-heard"><?= esc(t('breakfast.form.heard', 'How did you hear about Breakfast? (optional)')) ?></label>
        <input class="input" id="field-heard" name="heard_about" type="text" maxlength="120" value="<?= $field('heard_about') ?>">
      </div>
    </div>
    <?php if ($page->form_consent_text()->isNotEmpty()): ?>
    <?php /* Optional marketing/follow-up opt-in — replying relies on legitimate interest, so never required. */ ?>
    <div class="checkbox"><input type="checkbox" id="field-consent" name="consent" value="1" <?= isset($errors['consent']) ? 'aria-invalid="true"' : '' ?>><label for="field-consent"><?= esc($page->form_consent_text()) ?> <span class="field__optional">(optional)</span></label></div>
    <?php if (isset($errors['consent'])): ?><p class="field__error" id="err-consent"><?= esc($errors['consent']) ?></p><?php endif ?>
    <?php endif ?>
    <p class="form__note"><?= esc(t('breakfast.form.privacy', 'Your details are only used to reply to your enquiry. Nothing is shared or added to a marketing list.')) ?></p>
    <div class="fstep__nav" data-steps-nav>
      <button class="btn btn--ghost" type="button" data-step-back><span aria-hidden="true">←</span> Back</button>
      <button class="btn btn--secondary btn--lg" type="submit" data-submit><?= esc($page->form_submit_label()->or('Send project brief')) ?></button>
    </div>
  </section>
</form>
