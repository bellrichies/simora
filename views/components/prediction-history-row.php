<?php
$historyInfo = [
    'match' => $row['home'] . ' vs ' . $row['away'],
    'week' => preg_replace('/^W(\d+)$/', 'Week $1', $row['week']),
    'kickoff' => $row['kickoff_date_label'] . ' · ' . $row['kickoff_time_label'] . ' WAT', 'pick' => $row['pick'],
    'odds' => number_format($row['odds_hundredths'] / 100, 2),
    'result' => $row['home_score'] . ' - ' . $row['away_score'],
    'rate' => $row['historical_win_rate'] . '%', 'status' => $row['status'],
];
?>
<tr role="row">
    <td role="cell" data-label="Week"><span class="history-week-short"><?= e($row['week']) ?></span><strong class="history-week-label"><?= e($historyInfo['week']) ?></strong><time class="history-time" datetime="<?= e($row['kickoff_at']) ?>" title="<?= e($historyInfo['kickoff']) ?>"><?= e($row['kickoff_label']) ?></time></td>
    <td role="cell" data-label="Date"><time datetime="<?= e($row['kickoff_date']) ?>"><?= e($row['kickoff_date_label']) ?></time></td>
    <td role="cell" data-label="Kickoff"><time datetime="<?= e($row['kickoff_at']) ?>" title="West Africa Time (Africa/Lagos)"><?= e($row['kickoff_time_label']) ?></time></td>
    <td role="cell" data-label="Match"><span class="history-match-full"><?= e($historyInfo['match']) ?></span>
        <div class="history-match-preview" title="<?= e($historyInfo['match']) ?>">
            <span class="history-mini-kits" aria-hidden="true"><svg class="history-mini-kit" viewBox="0 0 24 26"><use href="/assets/kits/mini-shirts.svg#<?= e($row['home_kit_id']) ?>"/></svg><svg class="history-mini-kit" viewBox="0 0 24 26"><use href="/assets/kits/mini-shirts.svg#<?= e($row['away_kit_id']) ?>"/></svg></span>
            <div class="history-mini-content"><div class="history-mini-line"><span aria-hidden="true"><?= e($row['home_code']) ?> v <?= e($row['away_code']) ?></span><span class="sr-only"><?= e($historyInfo['match']) ?>, <?= e($row['pick']) ?></span><button type="button" class="history-info-trigger" disabled data-dialog="history-prediction-dialog" data-history-info="<?= e(json_encode($historyInfo, JSON_THROW_ON_ERROR)) ?>" aria-label="Prediction details for <?= e($historyInfo['match']) ?>"><i class="bi bi-info-circle" aria-hidden="true"></i></button></div>
                <p class="history-mini-facts"><span class="sr-only">Result </span><?= $row['home_score'] ?> - <?= $row['away_score'] ?> &middot; <span class="sr-only">odds </span>@ <?= e($historyInfo['odds']) ?> &middot; <span title="Demo historical win rate"><span class="sr-only">historical win rate </span><?= (int) $row['historical_win_rate'] ?>%</span></p><noscript><span class="history-nojs-details"><?= e($historyInfo['match'] . ' / ' . $row['pick']) ?></span></noscript>
            </div>
        </div>
    </td>
    <td role="cell" data-label="Pick"><?= e($row['pick']) ?></td>
    <td role="cell" data-label="Odds"><?= e($historyInfo['odds']) ?></td>
    <td role="cell" data-label="Result"><?= $row['home_score'] ?> &ndash; <?= $row['away_score'] ?></td>
    <td role="cell" data-label="Status"><span class="result-badge <?= $row['status'] === 'Won' ? 'won' : 'lost' ?>"><?= e($row['status']) ?></span></td>
</tr>
