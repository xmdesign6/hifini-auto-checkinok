## 服务器部署版（PHP）

适用于 GitHub Actions 无法访问站点的情况（站点防火墙拦截海外数据中心 IP），改为在国内服务器上定时运行。单文件、零依赖，仅需 PHP 7.1+ 且启用 curl 扩展。

### 部署步骤

1. 将 `checkin.php`、`config.example.php` 上传到服务器任意目录（建议站点目录外，如 `/www/checkin/`，避免被 Web 访问到）：

   ```bash
   mkdir -p /www/checkin && cd /www/checkin
   # 上传 checkin.php 与 config.example.php 后：
   cp config.example.php config.php
   vi config.php   # 填写账号信息与企业微信 Webhook
   ```

2. 手动测试一次：

   ```bash
   php /www/checkin/checkin.php
   ```

   看到签到成功或「今天已经签过啦！」即部署成功。

3. 添加定时任务（每天北京时间 07:40，按服务器时区调整；`date` 命令确认服务器时区）：

   ```bash
   crontab -e
   # 添加一行：
   40 7 * * * cd /www/checkin && php checkin.php >> checkin.log 2>&1
   ```

### 配置说明（config.php）

| 配置 | 说明 |
|---|---|
| `$ACCOUNTS` | 账号列表，`username`+`password`（账号密码模式）或 `cookie`（Cookie 模式），可混用、支持多账号。可选 `site` 字段选站点（`hifini` 默认 / `hifiti`） |
| `$WECOM_WEBHOOK` | 企业微信群机器人 Webhook 地址或仅 Key，留空则不通知 |
| `$SITE_DOMAINS` | 各站点的域名容灾列表，账号通过 `site` 字段引用 key，一般无需修改 |

### 注意

- `config.php` 含账号密码，已在 `.gitignore` 排除，**请勿提交到 Git 或放在网站可访问目录**
- `cacert.pem` 为可选文件：Windows 等无系统 CA 的环境下启用 HTTPS 证书校验，Linux 服务器一般不需要
