<?php
require_once __DIR__ . '/inc/bootstrap.php';

$current = home_update_current();
$history = sg_update_history_all();

/* 当前版本不重复出现在历史列表里 */
$history = array_values(array_filter($history, static function ($row) use ($current) {
    return strcasecmp($row['version'], $current['version']) !== 0;
}));

/* 按版本系列分组：v1.17.x、v1.16.x…… */
$series = [];
foreach ($history as $row) {
    $key = $row['series'] !== '' ? $row['series'] : '其他版本';
    if (!isset($series[$key])) {
        $series[$key] = [];
    }
    $series[$key][] = $row;
}
$totalHistory = count($history);

sg_head('版本更新记录', 'updates', '版本更新记录');
?>
        <div class="page-head">
          <h1>版本更新记录</h1>
          <span class="sub desk-only">从 v1.4.0 起共 <?= (int) $totalHistory ?> 个历史版本，按系列收起，点开看完整内容</span>
        </div>

        <div class="update-list">
<?php if ($current['text'] !== ''): ?>
          <article class="update-card current">
            <div class="update-meta">
              <span class="update-ver"><?= e($current['version']) ?></span>
              <span class="update-badge">当前版本</span>
            </div>
            <p class="update-text"><?= nl2br(e($current['text'])) ?></p>
          </article>
<?php endif; ?>
        </div>

<?php foreach ($series as $seriesName => $rows): ?>
        <section class="update-series">
          <div class="section-head">
            <h2><?= e($seriesName) ?> 系列</h2>
            <span class="sub"><?= count($rows) ?> 个版本</span>
          </div>
          <div class="update-list">
<?php foreach ($rows as $row): ?>
            <details class="update-card update-fold">
              <summary>
                <span class="update-ver"><?= e($row['version']) ?></span>
                <span class="update-title"><?= e($row['title'] ?: '版本更新') ?></span>
<?php if (!empty($row['date'])): ?><span class="update-date"><?= e($row['date']) ?></span><?php endif; ?>
                <span class="update-num"><?= count($row['notes']) ?> 条</span>
                <span class="ico" data-ico="chev"></span>
              </summary>
              <ul class="update-notes">
<?php foreach ($row['notes'] as $note): ?>
                <li><?= e($note) ?></li>
<?php endforeach; ?>
              </ul>
            </details>
<?php endforeach; ?>
          </div>
        </section>
<?php endforeach; ?>

<?php if ($current['text'] === '' && !$history): ?>
        <div class="alert"><span class="ico" data-ico="info"></span><span>还没有版本更新记录。到后台「系统设置 → 首页公告栏」填写版本号和更新说明后，这里会自动出现。</span></div>
<?php endif; ?>

        <p class="tip" style="margin-top:14px">当前版本置顶；历史版本默认收起，点版本号一行展开。后台每次修改版本号或更新说明并保存，上一条仍会自动归档，并与内置历史合并显示。</p>
        <p style="margin-top:14px"><a class="btn" href="index.php"><span class="ico" data-ico="home"></span>回首页</a></p>
<?php sg_foot(); ?>
