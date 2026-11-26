<?php
require_once __DIR__ . '/inc/bootstrap.php';
sg_head('采购清单', 'shopping', '采购清单');
?>
        <div class="page-head">
          <h1>采购清单</h1>
          <span class="sub desk-only">在菜谱详情页点食材右侧的「+」，就会加到这份清单里</span>
          <div class="actions">
            <a class="btn sm" href="recipes.php"><span class="ico" data-ico="book"></span>去挑菜</a>
          </div>
        </div>

        <div class="card">
          <div class="card-head">
            <h3>要买的东西</h3><span class="hint" id="shopMeta">0 项</span>
            <div class="right shop-tools">
              <button class="btn sm" type="button" id="shopZone"><span class="ico" data-ico="grid"></span>按超市分区</button>
              <button class="btn sm" type="button" id="shopCopy"><span class="ico" data-ico="list"></span>复制</button>
              <button class="btn sm" type="button" id="shopImg"><span class="ico" data-ico="image"></span>存图片</button>
              <button class="btn sm" type="button" id="shopClearDone"><span class="ico" data-ico="check"></span>清掉已买</button>
            </div>
          </div>
          <div id="shopList" class="shop-list"></div>
          <p id="shopEmpty" class="shop-empty" hidden>清单还是空的。去菜谱详情页，点食材右边的「+」加入。</p>
          <div class="shop-foot">
            <button class="btn sm" type="button" id="shopClearAll"><span class="ico" data-ico="trash"></span>清空全部</button>
          </div>
        </div>

        <p class="shop-note" style="margin-top:12px">清单存在这台设备的浏览器里，换设备/清缓存会丢，重要的话记得「复制」或「存图片」。</p>
<script src="<?= e($GLOBALS['SG_BASE']) ?>assets/shopping.js"></script>
<?php
sg_foot();
