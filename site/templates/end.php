<?php snippet('layouts/header') ?>
<section class="section" style="border-bottom:0;min-height:60vh;display:flex;align-items:center">
  <div class="container tt-egg" style="text-align:center">
    <p class="kicker" style="justify-content:center">P999</p>
    <h1 class="hero__title tt-egg__title">CONGRATULATIONS.</h1>
    <p class="section__lead" style="margin-inline:auto">You have reached the end of the internet.</p>
    <p class="hero__sub" style="margin-inline:auto">There is nothing after this.</p>
    <div style="margin-top:var(--s-12);max-width:22rem;margin-inline:auto;text-align:left">
      <?php snippet('teletext/page-index', ['items' => [
          ['number' => '100', 'label' => 'Start Again',            'href' => url('/')],
          ['number' => '101', 'label' => 'Build Something Better', 'href' => url('start-a-project')],
      ]]) ?>
    </div>
  </div>
</section>
<?php snippet('layouts/footer') ?>
