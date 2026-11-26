<?php
require_once __DIR__ . '/inc/bootstrap.php';

$id = get_int('id');
$r  = $id ? fetch_one('SELECT * FROM `' . T('recipes') . '` WHERE id = ? AND status = "published"', [$id]) : null;

if (!$r) {
    http_response_code(404);
    sg_head('菜谱不存在', 'cook', '烹饪模式');
    echo '<div class="empty"><div class="ico" data-ico="search"></div><b>这道菜谱可能已下架</b><p><a href="recipes.php" style="color:var(--brand)">返回菜谱库</a></p></div>';
    sg_foot();
    exit;
}

$steps = recipe_steps($id);
$auto  = get('auto', '') === '1';
$ings  = recipe_ingredients($id);
$total = recipe_total_minutes($r);

sg_head('烹饪模式 · ' . $r['title'], 'cook', '烹饪模式');
?>
<div class="cook-wrap">
  <div class="cook-top">
    <a class="btn sm" href="<?= e(recipe_url($r)) ?>"><span class="ico" data-ico="back"></span>返回菜谱</a>
    <div class="cook-title">
      <h1><?= e($r['title']) ?></h1>
      <span>共 <?= count($steps) ?> 步 · 约 <?= $total ?> 分钟 · <?= e($r['difficulty']) ?></span>
    </div>
  </div>

<?php if (!$steps): ?>
  <div class="card"><p style="margin:0">这个菜谱还没有填写制作步骤。</p></div>
<?php else: ?>

  <div class="cook-progress"><span id="cookBar"></span></div>

  <div class="cook-stage">
    <div class="cook-num"><span id="cookNo">01</span><em id="cookTotal">/ <?= sprintf('%02d', count($steps)) ?></em></div>
    <h2 id="cookStepTitle"><?= e($steps[0]['title'] ?: '第 1 步') ?></h2>
    <div class="cook-body" id="cookStepBody"><?= nl2br(e($steps[0]['content'])) ?></div>
    <div class="cook-photos" id="cookStepPhotos"></div>

    <div class="cook-timer" id="cookTimerBox" hidden>
      <b id="cookClock">00:00</b>
      <button class="btn sm primary" type="button" id="cookToggle">开始计时</button>
      <button class="btn sm" type="button" id="cookReset">重置</button>
    </div>

    <div class="cook-ing" id="cookIng" hidden></div>

    <div class="cook-nav">
      <button class="btn" type="button" id="cookPrev">&lt; 上一步</button>
      <button class="btn primary" type="button" id="cookNext">下一步 &gt;</button>
      <a class="btn primary" href="<?= e(recipe_url($r)) ?>" id="cookDone" hidden><span class="ico" data-ico="check"></span>做好了</a>
    </div>
  </div>

  <div class="cook-dots" id="cookDots">
<?php foreach ($steps as $i => $s): ?>
    <button type="button" data-jump="<?= $i ?>" class="<?= $i === 0 ? 'on' : '' ?>" title="第 <?= $i + 1 ?> 步"><?= $i + 1 ?></button>
<?php endforeach; ?>
  </div>

  <div class="cook-list">
<?php foreach ($steps as $i => $s): ?>
    <div class="cook-li<?= $i === 0 ? ' on' : '' ?>" data-idx="<?= $i ?>">
      <span class="n"><?= sprintf('%02d', $i + 1) ?></span>
      <div><b><?= e($s['title'] ?: '第 ' . ($i + 1) . ' 步') ?></b><p><?= e(mb_strimwidth(trim(strip_tags((string) $s['content'])), 0, 90, '…')) ?></p></div>
<?php if (!empty($s['duration'])): ?>
      <span class="tm"><?= e(step_duration_text((string) $s['duration'])) ?></span>
<?php endif; ?>
    </div>
<?php endforeach; ?>
  </div>

  <script type="application/json" id="sg-cook-data"><?php
  $out = [];
  foreach ($steps as $i => $s) {
      $out[] = [
          't' => $s['title'] ?: '第 ' . ($i + 1) . ' 步',
          'c' => trim((string) $s['content']),
          'd' => step_duration_text((string) $s['duration']),
          'p' => array_map(function ($img) { return image_url($img); }, step_images($s)),
      ];
  }
  echo json_encode(['auto' => $auto, 'steps' => $out], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  ?></script>
<?php endif; ?>
</div>
<script src="<?= e($GLOBALS['SG_BASE']) ?>assets/cook.js"></script>
<?php
sg_foot();
