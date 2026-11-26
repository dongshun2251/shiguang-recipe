<?php
require_once __DIR__ . '/inc/bootstrap.php';
require_login();

$u   = current_user();
$uid = (int) $u['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    if (post('do') === 'profile') {
        $name  = trim(post('display_name'));
        if ($name === '') {
            flash('昵称不能为空', 'err');
        } else {
            $fields = ['display_name' => $name];
            $oldAvatar = trim((string) ($u['avatar'] ?? ''));
            if (post('remove_avatar') === '1' && $oldAvatar !== '') {
                if (strpos($oldAvatar, 'uploads/avatars/') === 0) {
                    @unlink(SG_ROOT . '/' . $oldAvatar);
                }
                $fields['avatar'] = '';
            }
            if (!empty($_FILES['avatar']['tmp_name'])) {
                try {
                    $newAvatar = upload_image($_FILES['avatar'], 'avatars');
                    if ($oldAvatar !== '' && strpos($oldAvatar, 'uploads/avatars/') === 0) {
                        @unlink(SG_ROOT . '/' . $oldAvatar);
                    }
                    $fields['avatar'] = $newAvatar;
                } catch (RuntimeException $e) {
                    flash($e->getMessage(), 'err');
                    redirect('user-profile.php');
                }
            }
            db_update(T('users'), $fields, 'id = ?', [$uid]);
            flash('资料已更新');
        }
    }
    redirect('user-profile.php');
}

sg_head('个人资料', 'user-profile', '个人资料');
?>
        <div class="me-subhead">
          <a class="me-back" href="user-settings.php" aria-label="返回账号设置"><span class="ico" data-ico="back"></span></a>
          <div><h1>个人资料</h1><span>头像和昵称会显示在评论、投稿中</span></div>
        </div>

        <div class="card">
          <div class="card-head"><h3>头像与昵称</h3></div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="do" value="profile">
            <?= csrf_field() ?>
            <div class="field" style="margin-bottom:14px"><label>头像</label>
              <div class="avatar-edit">
                <button type="button" class="avatar-open" data-avatar-open aria-label="设置头像">
                  <?= sg_avatar((string) ($u['avatar'] ?? ''), (string) ($u['display_name'] ?: $u['username']), 'avatar-lg') ?>
                  <span class="avatar-open-mask">设置</span>
                </button>
                <div class="avatar-edit-main">
                  <b class="avatar-edit-title">点击头像设置</b>
                  <span class="avatar-edit-sub">可拖动裁剪、缩放，保存后按 1:1 居中；JPG / PNG / WebP，不超过 8MB</span>
                  <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" hidden data-avatar-input>
                  <?php if (!empty($u['avatar'])): ?>
                  <label class="avatar-remove"><input type="checkbox" name="remove_avatar" value="1"> 删除当前头像</label>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <div class="field" style="margin-bottom:12px"><label>昵称</label><input class="input" name="display_name" value="<?= e($u['display_name']) ?>" maxlength="32"></div>
            <div class="field" style="margin-bottom:14px"><label>用户名</label><input class="input" value="<?= e($u['username']) ?>" disabled><span class="tip">用户名注册后不可修改</span></div>
            <button class="btn primary block" type="submit"><span class="ico" data-ico="check"></span>保存资料</button>
          </form>
        </div>

        <div class="av-modal" id="avatarModal" hidden>
          <div class="av-mask" data-avatar-close></div>
          <div class="av-panel" role="dialog" aria-modal="true" aria-labelledby="avTitle">
            <div class="av-head">
              <b id="avTitle">设置头像</b>
              <button type="button" class="av-x" data-avatar-close aria-label="关闭">×</button>
            </div>
            <p class="av-sub">拖动图片摆正位置，用滑块缩放；保存后头像按 1:1 居中显示。</p>
            <div class="av-stage" id="avStage" data-avatar-stage>
              <img id="avImg" alt="" hidden>
              <div class="av-drop" id="avDrop">
                <span class="ico" data-ico="image"></span>
                <b>选一张图片来裁剪</b>
                <label class="btn primary sm av-file-btn">选择图片
                  <input type="file" id="avFile" accept="image/jpeg,image/png,image/webp" hidden>
                </label>
              </div>
              <span class="av-frame" aria-hidden="true"></span>
            </div>
            <div class="av-tools" id="avTools" hidden>
              <label class="av-zoom"><span>缩放</span><input type="range" id="avZoom" min="1" max="3" step="0.01" value="1"></label>
              <button type="button" class="btn sm" id="avReset">重置</button>
              <label class="btn sm" for="avFile">换一张</label>
            </div>
            <div class="av-foot">
              <button type="button" class="btn sm" data-avatar-close>取消</button>
              <button type="button" class="btn primary sm" id="avSave" disabled>使用这张</button>
            </div>
          </div>
        </div>
<?php
sg_foot();
