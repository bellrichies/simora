<?php
// Build the hierarchy from the validated route/read models, never raw query text.
$breadcrumbs = [['label' => 'Overview', 'url' => '/dashboard']];
if ($key !== 'overview') {
    if ($key === 'predictions' || $platform !== null) {
        $selected = $key === 'predictions' ? ($predictionsHub['preview'] ?? null) : null;
        $slug = $selected['slug'] ?? $platform['slug'] ?? null;
        $name = $selected['name'] ?? $platform['name'] ?? null;
        $leagueId = $selected['league_id'] ?? $details['league']['id'] ?? null;
        $leagueName = $selected['league'] ?? $details['league']['name'] ?? null;
        $platformParams = $slug && $leagueId ? ['league_' . $slug => $leagueId] : [];
        $breadcrumbs[] = ['label' => 'Platforms', 'url' => '/platforms' . ($platformParams ? '?' . http_build_query($platformParams) : '')];
        if ($slug && $name) {
            $breadcrumbs[] = ['label' => $name, 'url' => '/predictions?' . http_build_query(['focus' => $slug])];
        }
        if ($leagueName) {
            $breadcrumbs[] = ['label' => $leagueName, 'url' => '/predictions?' . http_build_query(['focus' => $slug] + $platformParams)];
        }
        if ($platform !== null) {
            $breadcrumbs[] = ['label' => 'Prediction board', 'url' => null];
        } elseif (!$selected) {
            $breadcrumbs[] = ['label' => 'Predictions', 'url' => null];
        }
    } else {
        $breadcrumbs[] = ['label' => $sections[$key] ?? 'Page not found', 'url' => null];
    }
}
?>
<nav class="page-container workspace-breadcrumb" aria-label="Breadcrumb">
    <ol>
        <?php foreach ($breadcrumbs as $index => $crumb): ?>
            <li>
                <?php if ($index === array_key_last($breadcrumbs)): ?>
                    <span aria-current="page"><?= e($crumb['label']) ?></span>
                <?php else: ?>
                    <a href="<?= e($crumb['url']) ?>"><?= e($crumb['label']) ?></a>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ol>
</nav>
