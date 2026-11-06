## HIFINI 音乐磁场 定时自动签到

支持多账号同时签到。支持账号密码登录（自动过 guard 反爬）与 Cookie 两种方式。支持 Telegram 通知。仅支持 `hifihi.com`.

[![HIFINI-Auto-Checkin](https://github.com/ewigl/hifini-auto-checkin/actions/workflows/Checkin.yml/badge.svg)](https://github.com/ewigl/hifini-auto-checkin/actions/workflows/Checkin.yml)

### 使用方法

1. Fork 此仓库。
2. 在 fork 后的仓库中启用 Actions。
3. 配置仓库变量。

详细文档: https://ewigl.github.io/projects/github-actions-tasks/

### 仓库变量

- **ACCOUNTS**：账户信息。推荐使用 JSON 编辑工具编辑以避免格式出错。

  支持两种配置方式（二选一）：

  **方式一：账号密码登录（推荐，Cookie 永久有效无需手动更新）**

  ```json
  [
    {
      "name": "这里填写账户备注",
      "username": "这里填写用户名或邮箱",
      "password": "这里填写密码"
    },
    {
      "name": "第二个账号",
      "username": "这里填写用户名或邮箱",
      "password": "这里填写密码"
    }
  ]
  ```

  **方式二：Cookie 登录（保留兼容）**

  ```json
  [
    {
      "name": "这里填写账户备注",
      "cookie": "这里填写 Cookie"
    }
  ]
  ```

  获取 Cookie: 
  
  登陆 hifihi.com 后，打开浏览器控制台，在网络监控界面获取 Cookie，确保 Cookie 包含 bbs_token 与 bbs_sid 两个字段。使用网站右上角的“退出”按钮退出登录会使 Cookie 失效。

  ![获取 Cookie](https://raw.githubusercontent.com/ewigl/hifini-auto-checkin/main/imgs/001.png)

  说明：程序会优先使用 `cookie` 字段；未配置 `cookie` 时自动使用 `username` + `password` 登录（登录时密码会按站点前端逻辑进行 MD5 加密，无需自行加密）。

- **TELEGRAM_TOKEN**: （可选）Telegram 通知的 Bot Token。
- **TELEGRAM_TO**: （可选）Telegram 通知的 Chat ID。

### 注意事项

根据 [GitHub 的政策](https://docs.github.com/zh/actions/managing-workflow-runs-and-deployments/managing-workflow-runs/disabling-and-enabling-a-workflow?tool=webui)，当 60 天内未发生仓库活动时，GitHub 会自动禁用 Actions 定时任务。需要再次手动启用。

### 相关推荐

- [HIFINI 音乐磁场 增强](https://github.com/ewigl/hifini-enhanced)：一键自动回帖，汇总网盘链接，自动填充网盘提取码。
