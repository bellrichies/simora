<?php
$pick = $details['pick'];
$pickCount = count($details['picks']);
$pickIndex = $details['pick_index'];
$previousPick = ($pickIndex - 1 + $pickCount) % $pickCount + 1;
$nextPick = ($pickIndex + 1) % $pickCount + 1;
$ringLength = 2 * M_PI * 42;
?>
<article class="match-pick" aria-label="<?= e($pick['home'] . ' versus ' . $pick['away'] . ' prediction preview') ?>">
    <div class="match-pick-main">
        <figure class="pick-team home-team">
            <img class="pick-shirt" src="<?= e($pick['home_kit']) ?>" alt="" width="180" height="190">
            <figcaption><strong><?= e($pick['home']) ?></strong><span><?= e($pick['home_code']) ?> &middot; Home</span></figcaption>
        </figure>
        <div class="pick-selection">
            <div class="pick-market"><?= e(strtoupper($pick['market'])) ?></div>
            <strong class="pick-odds"><?= number_format($pick['odds_hundredths'] / 100, 2) ?></strong>
            <div class="pick-win-rate"><svg viewBox="0 0 100 100" aria-hidden="true"><circle class="win-rate-track" cx="50" cy="50" r="42"/><circle class="win-rate-progress" cx="50" cy="50" r="42" stroke-dasharray="<?= number_format($ringLength, 3, '.', '') ?>" stroke-dashoffset="<?= number_format($ringLength * (1 - $pick['win_rate'] / 100), 3, '.', '') ?>"/></svg><strong><?= (int) $pick['win_rate'] ?>%</strong></div>
            <span class="pick-rate-label">Historical win rate</span>
            <nav class="pick-navigation" aria-label="Prediction picks">
                <a class="pick-arrow" href="?<?= e(http_build_query(array_merge($params, ['pick' => $previousPick]))) ?>" aria-label="Previous pick"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                <div class="pick-position"><span>Pick <?= $pickIndex + 1 ?> of <?= $pickCount ?></span><div class="pick-dots"><?php for ($index = 0; $index < $pickCount; $index++): ?><a href="?<?= e(http_build_query(array_merge($params, ['pick' => $index + 1]))) ?>" aria-label="Show pick <?= $index + 1 ?> of <?= $pickCount ?>" <?= $index === $pickIndex ? 'aria-current="true"' : '' ?>></a><?php endfor ?></div></div>
                <a class="pick-arrow" href="?<?= e(http_build_query(array_merge($params, ['pick' => $nextPick]))) ?>" aria-label="Next pick"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
            </nav>
        </div>
        <figure class="pick-team away-team">
            <img class="pick-shirt" src="<?= e($pick['away_kit']) ?>" alt="" width="180" height="190">
            <figcaption><strong><?= e($pick['away']) ?></strong><span><?= e($pick['away_code']) ?> &middot; Away</span></figcaption>
        </figure>
    </div>
    <details class="pick-reason"><summary><span class="reason-bulb"><i class="bi bi-lightbulb" aria-hidden="true"></i></span><strong>Why this pick?</strong><span class="pick-reason-copy"><?= e($pick['reason']) ?></span><i class="bi bi-chevron-right reason-chevron" aria-hidden="true"></i></summary><div class="pick-reason-detail"><?= e($pick['model_name']) ?> &middot; Rate calculated from <?= (int) $pick['historical_sample_size'] ?> earlier settled demo selections for <?= e($pick['market']) ?>, including calibration history. This describes synthetic model history, not the chance of this match winning.</div></details>
</article>
