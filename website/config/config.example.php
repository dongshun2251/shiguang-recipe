<?php
// 食光菜谱示例配置：复制为 config/config.php 后填写自己的数据库
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'shiguang',
        'user'    => 'root',
        'pass'    => '',
        'prefix'  => 'sg_',
        'charset' => 'utf8mb4',
    ],
    'app' => ['timezone' => 'Asia/Shanghai', 'debug' => false],
];
