<?php
$week = $platform['week'] ?? null;
$kickoff = null;
if (is_array($week) && !empty($week['kickoff_at'])) {
    try { $kickoff = new DateTimeImmutable($week['kickoff_at']); } catch (Exception $exception) { $kickoff = null; }
}
$leagueFlags = ['England League' => 'england', 'Italy League' => 'italy', 'Spain League' => 'spain', 'Germany League' => 'germany', 'France League' => 'france', 'Turkey League' => 'turkey'];
$initial = ['sportybet' => 'S', 'betpawa' => 'b', 'betking' => 'B'][$platform['slug']] ?? substr($platform['name'], 0, 1);
?>
<article class="ov-platform-card overview-platform-card">
<div class="ov-platform-content"><div class="ov-platform-top"><div class="ov-platform-brand"><span class="ov-platform-mark ov-<?= e($platform['class']) ?>" aria-hidden="true"><?= e($initial) ?></span><h4><?= e($platform['name']) ?><span>.</span></h4></div><span class="ov-platform-status"><?= $kickoff ? '&bull; Scheduled' : 'Unavailable' ?></span></div>
<div class="ov-league-row"><span><?php if (isset($leagueFlags[$platform['league']])): ?><img class="ov-league-flag" src="/assets/flags/<?= e($leagueFlags[$platform['league']]) ?>.svg" width="14" height="9" alt=""><?php endif ?> <?= e($platform['league']) ?></span><span>Virtual football</span></div>
<?php if (!$kickoff): ?><p class="ov-empty"><?= $platform['week_state'] === 'loading' ? 'Loading week details...' : 'No upcoming week information is available.' ?></p><?php endif ?>
<div class="ov-round-row"><div><span>Next round</span><strong><?= e($week['week'] ?? 'Unavailable') ?></strong></div><div><span>Kickoff &middot; WAT</span><?php if ($kickoff): ?><time data-schedule-kickoff datetime="<?= e($kickoff->format(DATE_ATOM)) ?>"><?= e($kickoff->setTimezone(new DateTimeZone('Africa/Lagos'))->format('d M, H:i')) ?></time><?php else: ?><strong>Unavailable</strong><?php endif ?></div></div>
<div class="ov-countdown"><span><i class="bi bi-clock" aria-hidden="true"></i> Time to kickoff</span><strong<?php if ($kickoff): ?> data-countdown="<?= e($kickoff->format(DATE_ATOM)) ?>" data-countdown-format="clock" data-countdown-closed-label="Round started"<?php if (!empty($week['fixture']) && ($platform['interval_seconds'] ?? 0) > 30): ?> data-countdown-interval="<?= (int) $platform['interval_seconds'] ?>"<?php endif ?><?php endif ?>><?= $kickoff ? 'See kickoff time' : 'Unavailable' ?></strong></div></div>
<a class="ov-platform-link" href="/<?= e($platform['slug']) ?>">Explore <?= e($platform['name']) ?> <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
</article>
