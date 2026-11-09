<?php
/**
 * 签到配置文件 - 复制本文件为 config.php 后填写
 * config.php 包含账号密码等敏感信息，请勿提交到 Git / 不要放在网站可访问目录
 */

// 账号列表（支持两种模式，可混用）
$ACCOUNTS = [
    // 账号密码模式（推荐）
    [
        'name' => 'xiaobai',
        'username' => '用户名或邮箱',
        'password' => '密码明文（脚本会自动 MD5，无需手动加密）',
    ],
    // Cookie 模式（可选，二选一即可）
    // [
    //     'name' => 'xiaobai',
    //     'cookie' => 'bbs_sid=xxx; bbs_token=xxx',
    // ],
];

// 企业微信群机器人 Webhook（可选）：完整地址 https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxx 或仅填 Key；留空不通知
$WECOM_WEBHOOK = '';

// 站点域名（一般无需修改）
$BASE_URLS = [
    'https://www.hifini.com.cn',
    'https://www.hifihi.com',
    'https://www.hifini.net',
];
