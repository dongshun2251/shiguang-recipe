<?php
/**
 * 食光菜谱 · 旧分类浏览页跳转
 * v1.13.1 起前台不再保留独立分类页，统一进入菜谱库。
 */
require_once __DIR__ . '/inc/bootstrap.php';
redirect('recipes.php');
