<?php
$week = $details['week'];
$configuration = $details['settings'];
$limits = $configuration['stake'];
$kickoff = $week ? (new DateTimeImmutable($week['kickoff_at']))->setTimezone(new DateTimeZone($configuration['timezone'])) : null;
$params = ['pick' => (string) ($details['pick_index'] + 1), 'market' => $details['market'], 'week' => $details['week_filter'],
    'status' => $details['status_filter'], 'stake' => number_format($details['stake_minor'] / 100, 2, '.', '')];
if ($details['league'] !== null) {
    $params['league'] = $details['league']['id'];
}
if ($details['upgraded']) {
    $params['access'] = 'pro';
}
$money = static fn (int $minor): string => ($minor < 0 ? '-' : '') . e($configuration['currency_symbol']) . number_format(abs($minor) / 100, 2);
$points = $details['simulation']['points'];
$min = min($points);
$span = max(1, max($points) - $min);
$coordinates = [];
foreach ($points as $index => $point) {
    $coordinates[] = number_format(420 * $index / max(1, count($points) - 1), 2, '.', '') . ','
        . number_format(60 - 52 * ($point - $min) / $span, 2, '.', '');
}
?>
<div class="page-container board prediction-board">
    <div class="board-notice"><i class="bi bi-info-circle" aria-hidden="true"></i>
        <span><?php if ($details['upgraded']): ?>Pro access preview. Additional analyses are enabled for this local demo.<?php else: ?><?= e($dashboard['account']['plan']) ?> plan: <?= $details['quota']['used'] ?>/<?= $details['quota']['limit'] ?> prediction analyses used today. Upgrade for higher daily limits and additional analytics.<?php endif ?></span>
        <button type="button" class="upgrade-link" data-dialog="board-upgrade-dialog"><?= $details['upgraded'] ? 'Manage preview' : 'Upgrade' ?></button>
    </div>

    <div class="board-grid">
        <section class="board-panel prediction" aria-labelledby="prediction-heading">
            <header class="prediction-header">
                <div class="board-metadata" role="group" aria-label="Week details">
                    <div><i class="bi bi-arrow-repeat" aria-hidden="true"></i><span>Week<strong><?= e(preg_replace('/^Week\s+/', '', $week['week'] ?? 'Unavailable')) ?></strong></span></div>
                    <div><i class="bi bi-calendar3" aria-hidden="true"></i><span>Season<strong><?= e($week['season'] ?? 'Unavailable') ?></strong></span></div>
                    <div><i class="bi bi-calendar-event" aria-hidden="true"></i><span>Date<strong><?= $kickoff ? e($kickoff->format('D, j M Y')) : 'Unavailable' ?></strong></span></div>
                    <div><i class="bi bi-clock" aria-hidden="true"></i><span>Kickoff<strong><?= $kickoff ? 'From ' . e($kickoff->format('g:i A')) . ' WAT' : 'Unavailable' ?></strong></span></div>
                    <div><i class="bi bi-layers" aria-hidden="true"></i><span>Matches<strong><?= $week ? (int) $week['match_count'] . ' matches' : 'Unavailable' ?></strong></span></div>
                </div>
                <div class="prediction-toolbar">
                <h3 id="prediction-heading"><i class="bi bi-bullseye" aria-hidden="true"></i> Prediction</h3>
                <form method="get" class="board-filter" data-auto-submit aria-label="Prediction market">
        <?php foreach ($params as $name => $value): ?><?php if ($name !== 'market'): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endif ?><?php endforeach ?>
        <fieldset class="market-choices"><legend class="sr-only">Market</legend><div class="market-options">
            <?php foreach ($details['markets'] as $market): ?><label><input type="radio" name="market" value="<?= e($market) ?>" <?= $details['market'] === $market ? 'checked' : '' ?>><span><?= e($market) ?></span></label><?php endforeach ?>
        </div></fieldset><noscript><button type="submit" class="filter-apply">Select market</button></noscript>
    </form>
                <div class="prediction-kickoff">
                    <i class="bi bi-clock" aria-hidden="true"></i>
                    <div><span>Next kickoff</span><?php if ($kickoff): ?><time datetime="<?= e($week['kickoff_at']) ?>"><?= e($kickoff->format('g:i A')) ?> WAT</time><?php endif ?></div>
                    <strong role="timer" aria-live="off"<?php if ($kickoff): ?> data-countdown="<?= e($week['kickoff_at']) ?>" data-countdown-format="clock"<?php endif ?>><?= $kickoff ? gmdate('i:s', max(0, $kickoff->getTimestamp() - time())) : 'Unavailable' ?></strong>
                </div>
                </div>
            </header>
            <?php if ($details['pick']): ?>
                <?php require __DIR__ . '/components/match-pick.php'; ?>
            <?php else: ?><div class="board-empty"><strong>No prediction available</strong></div><?php endif ?>
            <div class="trade-entry">
                <div class="trade-entry-copy"><span class="trade-entry-icon"><i class="bi bi-lightning-charge-fill" aria-hidden="true"></i></span><div><strong>Auto Trade <span class="trade-entry-badge">PREVIEW</span></strong><p>Plan a strategy for <?= e($platform['name']) ?> using this market.</p></div></div>
                <button class="trade-entry-button" type="button" data-dialog="auto-trade-dialog" aria-haspopup="dialog">Configure <i class="bi bi-arrow-up-right" aria-hidden="true"></i></button>
            </div>
        </section>
        <section class="board-panel simulator" aria-labelledby="simulator-heading"><h3 id="simulator-heading"><svg class="board-line-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6M10 3v7L4 20c-.3.5.1 1 .7 1h14.6c.6 0 1-.5.7-1l-6-10V3M7.5 15h9" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"/></svg> Strategy Simulator</h3>
            <form method="get" id="simulation-form">
                <?php foreach ($params as $name => $value): ?><?php if ($name !== 'stake'): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endif ?><?php endforeach ?>
                <div class="stake-controls"><label for="stake">Base stake</label><div class="stake-input"><span aria-hidden="true"><?= e($configuration['currency_symbol']) ?></span><input id="stake" name="stake" type="number" min="<?= $limits['min_minor'] / 100 ?>" max="<?= $limits['max_minor'] / 100 ?>" step="0.01" value="<?= e((string) ($details['stake_minor'] / 100)) ?>" inputmode="decimal" aria-describedby="simulation-description simulation-result"></div><?php foreach ($limits['increments_minor'] as $increment): ?><button type="button" data-stake-add="<?= $increment / 100 ?>" disabled>+<?= number_format($increment / 100) ?></button><?php endforeach ?></div>
                <noscript><button type="submit" class="filter-apply">Run simulation</button></noscript>
            </form>
            <p class="board-subtitle" id="simulation-description">Simulates how the model would have performed with your stake size on past demo weeks.</p>
            <p id="simulation-result" class="simulation-result" role="status" data-negative="<?= $details['simulation']['net_minor'] < 0 ? 'true' : 'false' ?>"><?php if ($details['stake_error']): ?>Enter a stake from <?= $money($limits['min_minor']) ?> to <?= $money($limits['max_minor']) ?>. Showing the default <?= $money($limits['default_minor']) ?> simulation.<?php else: ?>Net result: <?= $money($details['simulation']['net_minor']) ?> <span>&middot; <?= $details['simulation']['roi'] ?>% ROI</span><?php endif ?></p>
        </section>
        <section class="board-panel performance" aria-labelledby="performance-heading"><div class="board-panel-heading"><h3 id="performance-heading"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i> Model Performance</h3><span class="board-tag">Historical simulation</span></div>
            <div class="performance-box"><div class="performance-values"><span class="wins"><strong><?= $details['won'] ?></strong> won</span><span class="losses"><strong><?= $details['lost'] ?></strong> lost</span><span><strong><?= $details['hit_rate'] ?>%</strong> hit rate</span></div>
                <svg class="performance-chart" viewBox="0 0 420 68" preserveAspectRatio="none" role="img" aria-labelledby="chart-title"><title id="chart-title">Cumulative demo profit for <?= e($details['market']) ?> across <?= $details['won'] + $details['lost'] ?> settled examples</title><path class="chart-area" d="M0,68 L<?= implode(' L', $coordinates) ?> L420,68 Z"/><polyline points="<?= implode(' ', $coordinates) ?>" fill="none" stroke="currentColor" stroke-width="2" vector-effect="non-scaling-stroke"/></svg>
            </div><p class="performance-note"><i class="bi bi-info-circle" aria-hidden="true"></i><span><?= e($details['source']['notice'] ?? 'No performance data available.') ?></span></p>
        </section>
    </div>
    <section class="board-panel history" aria-labelledby="history-heading"><div class="history-heading"><div><h3 id="history-heading"><i class="bi bi-list-ul" aria-hidden="true"></i> Recent predictions</h3></div>
        <form method="get" class="history-filters" data-auto-submit aria-label="Filter recent predictions"><?php if ($details['league'] !== null): ?><input type="hidden" name="league" value="<?= e($details['league']['id']) ?>"><?php endif ?><input type="hidden" name="pick" value="<?= $details['pick_index'] + 1 ?>"><input type="hidden" name="stake" value="<?= e($params['stake']) ?>"><?php if ($details['upgraded']): ?><input type="hidden" name="access" value="pro"><?php endif ?>
            <label>Week <select name="week"><option value="all">All weeks</option><?php foreach ($details['weeks'] as $option): ?><option value="<?= e($option) ?>" <?= $details['week_filter'] === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach ?></select></label>
            <label>Market <select name="market"><?php foreach ($details['markets'] as $option): ?><option value="<?= e($option) ?>" <?= $details['market'] === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach ?></select></label>
            <label>Status <select name="status"><?php foreach (['all' => 'All', 'Won' => 'Won', 'Lost' => 'Lost'] as $value => $label): ?><option value="<?= e($value) ?>" <?= $details['status_filter'] === $value ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach ?></select></label><noscript><button type="submit" class="filter-apply">Apply filters</button></noscript>
        </form></div>
        <div class="table-scroll" tabindex="0" role="region" aria-label="Prediction history"><table role="table"><thead role="rowgroup"><tr role="row"><th scope="col">Week</th><th scope="col">Date</th><th scope="col">Kickoff <span class="history-timezone" title="West Africa Time">WAT</span></th><th scope="col">Match</th><th scope="col">Pick</th><th scope="col">Odds</th><th scope="col">Result</th><th scope="col">Status</th></tr></thead><tbody role="rowgroup" id="prediction-history-rows">
            <?php foreach ($details['history'] as $row): ?><?php require __DIR__ . '/components/prediction-history-row.php'; ?><?php endforeach ?>
            <?php if (!$details['history']): ?><tr role="row"><td role="cell" colspan="8" class="history-empty">No demo predictions match these filters.</td></tr><?php endif ?>
        </tbody></table></div>
        <?php if ($details['total'] > 0): ?>
            <div class="history-load-footer">
                <p id="history-load-status" role="status" aria-live="polite">Showing <?= $details['shown'] ?> of <?= $details['total'] ?> predictions</p>
                <?php if ($details['next_page'] !== null): ?>
                    <form method="get" class="history-load" data-history-load data-endpoint="/api/platforms/<?= e($platform['slug']) ?>/predictions" data-next-page="<?= $details['next_page'] ?>" data-shown="<?= $details['shown'] ?>" action="/<?= e($platform['slug']) ?>#history-heading">
                        <?php foreach ($params as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach ?>
                        <input type="hidden" name="show" value="<?= min($details['shown'] + $details['page_size'], $details['total']) ?>">
                        <button type="submit" aria-controls="prediction-history-rows">Load more</button>
                    </form>
                <?php endif ?>
            </div>
        <?php endif ?>
    </section>
    <p class="board-demo-note">Demo data &middot; <?= e($platform['name']) ?> &middot; <?= $details['won'] + $details['lost'] ?> settled predictions for this market. Week and status filters apply to the table.<?php if ($details['source']): ?> Generated <?= e(substr($details['source']['generated_at'], 0, 10)) ?> &middot; Seed <?= (int) $details['source']['seed'] ?>.<?php endif ?></p>
</div>
<dialog id="auto-trade-dialog" class="trade-dialog" aria-labelledby="trade-dialog-title" aria-describedby="trade-preview-notice">
    <button type="button" class="dialog-close" data-close-dialog aria-label="Close Auto Trade setup">&times;</button>
    <p class="eyebrow">STRATEGY PREVIEW</p><h2 id="trade-dialog-title">Auto Trade <span>&middot; <?= e($platform['name']) ?></span></h2>
    <p id="trade-preview-notice" class="trade-dialog-notice">Preview only. Save a draft in this browser tab; no account is connected and no bets can be placed.</p>
    <div class="trade-detected" aria-label="Automatically detected prediction context"><div class="trade-detected-heading"><i class="bi bi-check-circle" aria-hidden="true"></i><strong>Detected from this prediction</strong></div><p>Change the league or market on the prediction board to update this setup.</p><dl><div><dt>Platform</dt><dd><?= e($platform['name']) ?></dd></div><div><dt>League</dt><dd><?= e($details['league']['name'] ?? 'Unavailable') ?></dd></div><div><dt>Market</dt><dd><?= e($details['market']) ?></dd></div></dl></div>
    <section class="trade-connection" aria-labelledby="trade-connection-title"><div><strong id="trade-connection-title">Betting account</strong><span>Not connected</span><p>Secure <?= e($platform['name']) ?> account linking is not available yet. Your login details are not collected in this preview.</p></div><button type="button" disabled aria-describedby="trade-connection-title">Connect account</button></section>
    <form id="trade-draft-form" data-platform="<?= e($platform['slug']) ?>" novalidate>
        <input type="hidden" name="platform" value="<?= e($platform['slug']) ?>">
        <input type="hidden" name="league" value="<?= e($details['league']['id'] ?? '') ?>">
        <input type="hidden" name="market" value="<?= e($details['market']) ?>">
        <div class="trade-form-grid trade-amount-grid">
            <label>Initial stake (<?= e($configuration['currency']) ?>)<input name="stake" type="number" min="0.01" max="1000000" step="0.01" inputmode="decimal" required placeholder="e.g. 500"></label>
            <label>Session profit target (<?= e($configuration['currency']) ?>)<input name="profitTarget" type="number" min="0.01" max="1000000" step="0.01" inputmode="decimal" required placeholder="Target profit"></label>
            <label>Session stop-loss (<?= e($configuration['currency']) ?>)<input name="stopLoss" type="number" min="0.01" max="1000000" step="0.01" inputmode="decimal" required placeholder="Maximum loss"></label>
        </div>
        <fieldset class="trade-session-picker"><legend>Staking method</legend><div class="trade-session-options trade-strategy-options">
            <?php foreach (['flat' => ['dash-circle', 'Flat stake', 'Same stake'], 'martingale' => ['arrow-up-circle', 'Martingale', 'Progress after loss'], 'recovery' => ['arrow-repeat', 'Recovery', 'Target prior losses']] as $value => [$icon, $label, $description]): ?>
                <label class="trade-session-option"><input type="radio" name="strategy" value="<?= e($value) ?>" required <?= $value === 'flat' ? 'checked' : '' ?>><span class="trade-session-face"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><strong><?= e($label) ?></strong><small><?= e($description) ?></small></span></span></label>
            <?php endforeach ?>
        </div></fieldset>
        <fieldset class="trade-session-picker"><legend>Trading session</legend><div class="trade-session-options">
            <?php foreach (['morning' => ['sunrise', 'Morning', '06:00–12:00'], 'afternoon' => ['sun', 'Afternoon', '12:00–18:00'], 'evening' => ['sunset', 'Evening', '18:00–00:00'], 'night' => ['moon-stars', 'Night', '00:00–06:00']] as $value => [$icon, $label, $hours]): ?>
                <label class="trade-session-option"><input type="radio" name="session" value="<?= e($value) ?>" required <?= $value === 'morning' ? 'checked' : '' ?>><span class="trade-session-face"><i class="bi bi-<?= e($icon) ?>" aria-hidden="true"></i><span><strong><?= e($label) ?></strong><small><?= e($hours) ?></small></span></span></label>
            <?php endforeach ?>
        </div></fieldset>
        <section class="trade-loss-control" aria-labelledby="trade-loss-title"><label class="trade-loss-toggle"><input type="checkbox" name="lossControl"><span><strong id="trade-loss-title">Virtual Loss Control</strong><small>Wait for a loss pattern before allowing new trades.</small></span></label><div id="trade-loss-settings" hidden><div class="trade-loss-sliders"><label>Losses to observe <output id="trade-loss-count-value" for="trade-loss-count">1</output><input id="trade-loss-count" name="lossCount" type="range" min="1" max="100" step="1" value="1" disabled></label><label>Recent predictions <output id="trade-lookback-value" for="trade-lookback">5</output><input id="trade-lookback" name="lookback" type="range" min="1" max="100" step="1" value="5" disabled></label></div><p id="trade-loss-error" role="status" aria-live="polite"></p><p>After a placed bet loses, pause new bets until the loss condition is met and a later observed prediction wins. Then resume only if all other limits allow it.</p><p>Past outcomes do not predict future results. This is a preview, not an active safeguard.</p></div></section>
        <p class="trade-form-help">Times are illustrative; time zone and advanced risk controls will be required before live activation. Recovery strategies can increase losses.</p>
        <p id="trade-draft-status" role="status" aria-live="polite"></p>
        <div class="trade-actions"><button type="submit" class="button secondary" disabled>Save preview draft</button><button type="button" class="button primary" disabled aria-describedby="trade-activation-help">Activate Auto Trade</button></div>
        <p id="trade-activation-help" class="trade-form-help">Activation requires an authorized platform connection, real predictions and account safeguards.</p>
        <noscript><p>Enable JavaScript to save a preview draft in this tab.</p></noscript>
    </form>
</dialog>
<dialog id="board-upgrade-dialog"><button class="dialog-close" data-close-dialog aria-label="Close">&times;</button><div class="dialog-icon"><i class="bi bi-stars" aria-hidden="true"></i></div><p class="eyebrow gold">SIMORA DEMO</p><h2>Explore more analyses</h2><p>Preview the upgraded prediction state using local examples. This preview changes no account or payment details.</p><a class="button primary wide" href="?<?= e(http_build_query(array_merge($params, ['access' => $details['upgraded'] ? 'free' : 'pro']))) ?>"><?= $details['upgraded'] ? 'Return to free preview' : 'Preview upgraded access' ?></a></dialog>
<dialog id="history-prediction-dialog" aria-labelledby="history-info-title">
    <button class="dialog-close" data-close-dialog aria-label="Close prediction details">&times;</button>
    <p class="eyebrow">DEMO PREDICTION</p><h2 id="history-info-title" data-history-title>Prediction details</h2>
    <dl class="history-detail-fields"><div><dt>Week</dt><dd data-history-field="week"></dd></div><div><dt>Kickoff (Lagos)</dt><dd data-history-field="kickoff"></dd></div><div><dt>Pick</dt><dd data-history-field="pick"></dd></div><div><dt>Odds</dt><dd data-history-field="odds"></dd></div><div><dt>Result</dt><dd data-history-field="result"></dd></div><div><dt>Status</dt><dd data-history-field="status"></dd></div><div><dt>Historical win rate</dt><dd data-history-field="rate"></dd></div></dl>
    <p>Historical win rate uses this model's earlier settled demo selections for the same league and market, including calibration history. It excludes this match and later results. The performance summary covers displayed history only.</p>
</dialog>
<script id="simulation-data" type="application/json"><?= json_encode($details['simulation_rows'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>
<script id="platform-settings" type="application/json"><?= json_encode(['currency' => $configuration['currency'], 'stake' => $limits, 'page_size' => $details['page_size']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) ?></script>

<script src="/assets/platform.js" defer></script>

<script src="/assets/history.js" defer></script>
<script src="/assets/auto-trade.js" defer></script>
