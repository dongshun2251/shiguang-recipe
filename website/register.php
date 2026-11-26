<?php
require_once __DIR__ . '/inc/bootstrap.php';

if (current_user()) {
    redirect('user.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = trim(post('username'));
    $name     = trim(post('display_name'));
    $pass     = (string) post('password');
    $pass2    = (string) post('password2');

    if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
        $error = '用户名需为 3–20 位字母、数字或下划线';
    } elseif (strlen($pass) < 6) {
        $error = '密码至少 6 位';
    } elseif ($pass !== $pass2) {
        $error = '两次输入的密码不一致';
    } elseif (fetch_one('SELECT id FROM `' . T('users') . '` WHERE username = ?', [$username])) {
        $error = '该用户名已被使用';
    } else {
        $id = db_insert(T('users'), [
            'username'      => $username,
            'display_name'  => $name !== '' ? $name : $username,
            'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
            'role'          => 'user',
            'status'        => 1,
        ]);
        session_regenerate_id(true);
        sg_begin_user_session(['id' => $id]);
        flash('注册成功，欢迎加入');
        redirect('user.php');
    }
}

sg_head('注册', 'login', '注册账号');
?>
      <?php
      $authFace = 'register';
      $authError = $error;
      $authNext = '';
      require __DIR__ . '/inc/auth-card.php';
      ?>
<?php
sg_foot();
