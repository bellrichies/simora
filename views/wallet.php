<?php
$money = static fn (int $minor): string => ($minor < 0 ? '-' : '') . '&#8358;' . number_format(abs($minor) / 100, 2);
?>
<link rel="stylesheet" href="/assets/platforms.css">
<link rel="stylesheet" href="/assets/wallet.css">
<script src="/assets/wallet.js" defer></script>
<div class="page-container pl-page wallet-page" data-wallet-page>
    <header class="pl-header"><div><p class="pl-eyebrow">YOUR SUBSCRIPTION CREDITS</p><h2>Your wallet. <span>Every credit in view.</span></h2><p>Track your rewards, manage your credit, and review your activity.</p></div><a class="wl-button" href="/plans">View plans &amp; billing <i class="bi bi-arrow-up-right" aria-hidden="true"></i></a></header>
    <div class="pl-notice"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Demo wallet: these balances and transactions are examples. Credits cannot be used in this preview.</span><small>SAMPLE DATA</small></div>
    <section aria-labelledby="wallet-summary-heading"><div class="pl-heading"><h3 id="wallet-summary-heading">Your credit at a glance</h3><span>NGN &middot; Subscription credits</span></div><div class="pl-grid wl-summary">
        <section class="pl-card wl-balance" aria-labelledby="wallet-overview-heading">
            <div class="wl-card-heading"><h3 id="wallet-overview-heading"><i class="bi bi-wallet2" aria-hidden="true"></i> Credit overview</h3><span class="wl-tag">NGN &middot; Demo</span></div>
            <div class="wallet-primary-balance"><span>AVAILABLE SUBSCRIPTION CREDIT</span><strong><?= $money($wallet['available']) ?></strong><p>Example credit available towards eligible subscriptions.</p></div>
            <a class="wl-text-link" href="/plans">Explore eligible plans <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        </section>
        <section class="pl-card wl-pending" aria-labelledby="wallet-pending-heading"><div class="wl-card-heading"><h3 id="wallet-pending-heading"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Pending &amp; reserved</h3></div><div class="wl-hold"><span>Pending rewards</span><strong><?= $money($wallet['pending']) ?></strong><small>Awaiting confirmation &middot; not yet available</small></div><div class="wl-hold"><span>Reserved credit</span><strong><?= $money($wallet['reserved']) ?></strong><small>Held for a subscription payment</small></div></section>
        <section class="pl-card wl-insights" aria-labelledby="wallet-insights-heading">
            <div class="wl-card-heading"><h3 id="wallet-insights-heading"><i class="bi bi-bar-chart-fill" aria-hidden="true"></i> Credit activity</h3><span class="wl-tag">All time</span></div>
            <p class="wallet-panel-copy">A snapshot of completed activity in this example wallet.</p>
            <div class="wallet-stat-row"><span><i class="bi bi-arrow-down-left" aria-hidden="true"></i> Total earned</span><strong><?= $money($wallet['earned']) ?></strong></div>
            <div class="wallet-stat-row"><span><i class="bi bi-arrow-up-right" aria-hidden="true"></i> Applied to plans</span><strong><?= $money($wallet['used']) ?></strong></div>
            <p class="wallet-insight-note"><i class="bi bi-info-circle" aria-hidden="true"></i> Pending rewards are not included in available credit.</p>
        </section>
    </div></section>
    <section class="pl-history wl-history" aria-labelledby="wallet-history-heading">
        <div class="pl-section-head"><div><h3 id="wallet-history-heading"><i class="bi bi-list-ul" aria-hidden="true"></i> Wallet activity</h3><p class="wallet-panel-copy">Review example rewards and subscription credits.</p></div>
            <form method="get" action="/wallet" class="pl-filters wl-filters" data-wallet-filters aria-label="Filter wallet activity">
                <label>Type <select name="type"><option value="all">All types</option><?php foreach (['Reward', 'Subscription'] as $option): ?><option value="<?= e($option) ?>" <?= $wallet['type'] === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach ?></select></label>
                <label>Status <select name="status"><option value="all">All statuses</option><?php foreach (['Completed', 'Pending', 'Reserved'] as $option): ?><option value="<?= e($option) ?>" <?= $wallet['status'] === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach ?></select></label>
                <button class="wl-apply" type="submit">Apply</button>
            </form>
        </div>
        <div class="pl-table-wrap" tabindex="0" role="region" aria-label="Wallet activity"><table class="pl-table wl-table"><thead><tr><th scope="col">Date</th><th scope="col">Activity</th><th scope="col">Reference</th><th scope="col">Type</th><th scope="col">Status</th><th scope="col">Amount</th></tr></thead><tbody>
        <?php foreach ($wallet['rows'] as $row): ?><tr><td data-label="Date"><?= e((new DateTimeImmutable($row['date']))->format('d M Y')) ?></td><td data-label="Activity"><strong><?= e($row['description']) ?></strong></td><td data-label="Reference"><?= e($row['reference']) ?></td><td data-label="Type"><?= e($row['type']) ?></td><td data-label="Status"><span class="wallet-status <?= $row['status'] !== 'Completed' ? 'is-pending' : '' ?>"><?= e($row['status']) ?></span></td><td data-label="Amount" class="<?= $row['amount_minor'] > 0 ? 'wallet-credit' : '' ?>"><?= $row['amount_minor'] > 0 ? '+' : '' ?><?= $money($row['amount_minor']) ?></td></tr><?php endforeach ?>
        <?php if (!$wallet['rows']): ?><tr><td colspan="6" class="history-empty">No example activity matches these filters.</td></tr><?php endif ?>
        </tbody></table></div><div class="pl-history-footer"><p>Showing <?= count($wallet['rows']) ?> example transactions &middot; Newest first</p><a href="/wallet">Reset filters</a></div>
    </section>
    <div class="wallet-context-grid"><section class="pl-card wallet-context"><i class="bi bi-gift" aria-hidden="true"></i><div><h3>Credit sources</h3><p>Explore how referral rewards may appear in your wallet.</p><a href="/referrals">Explore referrals &rarr;</a></div></section><section class="pl-card wallet-context"><i class="bi bi-credit-card" aria-hidden="true"></i><div><h3>Using credits</h3><p>Subscription credit is illustrated here for eligible plans.</p><a href="/plans">View plans &amp; billing &rarr;</a></div></section></div>
    <p class="wl-demo-note">Illustrative data only &middot; Wallet and rewards are not connected.</p>
</div>
