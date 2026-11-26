<?php
require_once __DIR__ . '/../inc/bootstrap.php';

unset($_SESSION['aid'], $_SESSION['sg_atok']);
session_regenerate_id(true);
flash('已退出后台');
redirect('login.php');
