<?php

/**
 * A row of "LABEL ... ● VALUE" status lines (e.g. the homepage's current
 * availability, or an easter-egg system-status page). Never used for real
 * infrastructure/version data — see docs/security.md section on P900.
 *
 * @var array<int,array{label:string,value:string,dot?:string}> $rows
 */
$rows = $rows ?? [];
$dotClass = static fn (string $dot) => match ($dot) {
    'green' => 'tt-dot tt-dot--green',
    'red'   => 'tt-dot tt-dot--red',
    default => 'tt-dot tt-dot--yellow',
};
?>
<div class="tt-status-list">
  <?php foreach ($rows as $row): ?>
    <div class="tt-status-row">
      <span class="tt-status-row__label"><?= esc($row['label']) ?></span>
      <span class="tt-status-row__value"><span class="<?= $dotClass($row['dot'] ?? 'yellow') ?>" aria-hidden="true"></span><?= esc($row['value']) ?></span>
    </div>
  <?php endforeach ?>
</div>
