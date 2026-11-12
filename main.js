import { appendFileSync } from "fs";
import { createHash } from "crypto";

// 密码 MD5 加密（与站点前端 $.md5 行为一致）
function md5(input) {
  return createHash("md5").update(input, "utf8").digest("hex");
}

// 站点域名列表：主域名在前，备用域名依次容灾
const baseUrls = [
  "https://www.hifini.com.cn", // 本站主域名
  "https://www.hifihi.com", // 备用域名
  "https://www.hifini.net", // 备用域名
];
const responseSuccessCode = "0";

// ==================== 工具 ====================

const defaultHeaders = {
  "User-Agent":
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36",
};

// ==================== guard 反爬（复现站点 /_guard/auto.js 逻辑） ====================

// RC4 加密（与站点 auto.js 的 rc4 函数一致）
function rc4(text, key) {
  const s = [];
  for (let i = 0; i < 256; i++) s[i] = i;
  let j = 0;
  for (let i = 0; i < 256; i++) {
    j = (j + s[i] + key.charCodeAt(i % key.length)) % 256;
    const temp = s[i];
    s[i] = s[j];
    s[j] = temp;
  }
  let i = 0;
  j = 0;
  let result = "";
  for (let k = 0; k < text.length; k++) {
    i = (i + 1) % 256;
    j = (j + s[i]) % 256;
    const temp = s[i];
    s[i] = s[j];
    s[j] = temp;
    result += String.fromCharCode(text.charCodeAt(k) ^ s[(s[i] + s[j]) % 256]);
  }
  return result;
}

// 根据 guard Cookie 计算站点要求的 guardret Cookie
function solveGuard(guard) {
  const key = guard.substr(0, 8);
  const arg = (parseInt(guard.substr(12), 10) * 2 + 18 - 2).toString();
  return Buffer.from(rc4(arg, key), "latin1").toString("base64");
}

// 访问站点获取 guard Cookie 并算出 guardret，返回可通过反爬的 Cookie 串
async function fetchGuardCookies(baseUrl) {
  let jar = {};

  const parseCookie = (cookieStr) => {
    for (const part of cookieStr.split(";")) {
      const idx = part.indexOf("=");
      if (idx > 0) jar[part.slice(0, idx).trim()] = part.slice(idx + 1).trim();
    }
  };

  const loginPageUrl = `${baseUrl}/user-login.htm`;

  // 第一次访问：拿到 guard Cookie（响应是被反爬拦截的跳转页）
  const firstResponse = await fetch(loginPageUrl, { headers: defaultHeaders });
  for (const item of extractCookies(firstResponse)) {
    parseCookie(item.split(";")[0]);
  }
  await firstResponse.text();

  if (!jar.guard) {
    // 站点未启用 guard 时直接返回已有 Cookie
    return Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; ");
  }

  // 计算 guardret 后重新访问，获取正常会话 Cookie
  jar.guardret = solveGuard(jar.guard);
  const guardCookie = Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; ");

  const secondResponse = await fetch(loginPageUrl, {
    headers: { ...defaultHeaders, Cookie: guardCookie },
  });
  for (const item of extractCookies(secondResponse)) {
    parseCookie(item.split(";")[0]);
  }
  await secondResponse.text();

  return Object.entries(jar).map(([k, v]) => `${k}=${v}`).join("; ");
}

// 从响应中提取 Set-Cookie（兼容不同 Node 版本）
function extractCookies(response) {
  if (typeof response.headers.getSetCookie === "function") {
    return response.headers.getSetCookie();
  }
  const raw = response.headers.get("set-cookie");
  if (!raw) return [];
  // 简易拆分：按 ", " 分割并过滤 Expires 中被误切的情况
  return raw.split(/,(?=[^;]+?=)/g);
}

// 将 Set-Cookie 数组合并为 name=value 的 Cookie 字符串
function mergeCookies(originalCookie, setCookieList) {
  const jar = {};

  // 解析已有 Cookie
  if (originalCookie) {
    for (const part of originalCookie.split(";")) {
      const idx = part.indexOf("=");
      if (idx > 0) {
        jar[part.slice(0, idx).trim()] = part.slice(idx + 1).trim();
      }
    }
  }

  // 合并新 Cookie（新值覆盖旧值）
  for (const item of setCookieList) {
    const pair = item.split(";")[0];
    const idx = pair.indexOf("=");
    if (idx > 0) {
      jar[pair.slice(0, idx).trim()] = pair.slice(idx + 1).trim();
    }
  }

  return Object.entries(jar)
    .map(([k, v]) => `${k}=${v}`)
    .join("; ");
}

// ==================== 登录 ====================

// 使用账号密码在指定域名上登录，返回签到所需的 Cookie（bbs_sid + bbs_token）
async function loginOnDomain(account, baseUrl) {
  // 站点前端逻辑：提交前将密码 MD5 加密（见 view/js/md5.js 与登录页 JS）
  const password = md5(account.password);

  // 先过 guard 反爬，拿到基础 Cookie
  const guardCookie = await fetchGuardCookies(baseUrl);

  const response = await fetch(`${baseUrl}/user-login.htm`, {
    method: "POST",
    headers: {
      ...defaultHeaders,
      "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      "X-Requested-With": "XMLHttpRequest",
      Referer: `${baseUrl}/user-login.htm`,
      Cookie: guardCookie,
    },
    body: new URLSearchParams({
      email: account.username,
      password,
    }).toString(),
  });

  if (!response.ok) {
    throw new Error(`登录请求出错 - ${response.status}`);
  }

  const responseJson = await response.json();

  if (responseJson.code !== responseSuccessCode) {
    throw new Error(`登录失败: ${responseJson.message}`);
  }

  // 登录成功后在 guard Cookie 基础上合并登录下发的 bbs_sid / bbs_token
  const cookie = mergeCookies(guardCookie, extractCookies(response));

  if (!cookie.includes("bbs_sid") || !cookie.includes("bbs_token")) {
    throw new Error(`登录成功但未获取到有效 Cookie。`);
  }

  return cookie;
}

// 多域名容灾登录：依次尝试所有域名，返回 { cookie, baseUrl }
async function login(account) {
  console.log(`【${account.name}】: 正在使用账号密码登录...`);

  const errors = [];

  for (const baseUrl of baseUrls) {
    try {
      const cookie = await loginOnDomain(account, baseUrl);
      console.log(`【${account.name}】: 登录成功（${baseUrl}）。`);
      return { cookie, baseUrl };
    } catch (error) {
      errors.push(`${baseUrl}: ${error.message}`);
    }
  }

  throw new Error(`所有域名登录失败（${errors.join("；")}）`);
}

// ==================== 签到 ====================

// 在指定域名上签到；响应不是有效 JSON（被 guard 拦截）时抛出可容灾的错误
async function checkInOnDomain(account, cookie, baseUrl) {
  const response = await fetch(`${baseUrl}/sg_sign.htm`, {
    method: "POST",
    headers: {
      ...defaultHeaders,
      "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
      "X-Requested-With": "XMLHttpRequest",
      Referer: `${baseUrl}/`,
      Cookie: cookie,
    },
  });

  if (!response.ok) {
    throw new Error(`网络请求出错 - ${response.status}`);
  }

  const text = await response.text();

  let responseJson;
  try {
    responseJson = JSON.parse(text);
  } catch (error) {
    throw new Error(`DOMAIN_FAIL`);
  }

  if (responseJson.code === responseSuccessCode) {
    return responseJson.message;
  } else {
    if (responseJson.message === "今天已经签过啦！") {
      return responseJson.message;
    }
    // 未登录类提示说明 Cookie 在此域名上无效，可尝试其他域名
    if (typeof responseJson.message === "string" && (responseJson.message.includes("登录") || responseJson.message.includes("用户"))) {
      throw new Error(`DOMAIN_FAIL`);
    }
    throw new Error(`签到失败: ${responseJson.message}`);
  }
}

// 多域名容灾签到，返回 { message, baseUrl }
async function checkIn(account, cookie, fixedBaseUrl) {
  console.log(`【${account.name}】: 开始签到...`);

  // 账号密码登录成功时已确定可用域名，优先使用
  const domains = fixedBaseUrl ? [fixedBaseUrl, ...baseUrls.filter((u) => u !== fixedBaseUrl)] : baseUrls;

  const errors = [];

  for (const baseUrl of domains) {
    try {
      const message = await checkInOnDomain(account, cookie, baseUrl);
      console.log(`【${account.name}】: ${message}（${baseUrl}）`);
      return { message, baseUrl };
    } catch (error) {
      if (error.message !== "DOMAIN_FAIL") {
        throw error;
      }
      errors.push(baseUrl);
    }
  }

  throw new Error(`签到失败: 所有域名均无法完成签到（${errors.join("、")}）`);
}

// 查询账户现有金币数量（my.htm 页面金币字段），失败返回 null 不影响签到
async function fetchCoins(cookie, baseUrl) {
  try {
    const response = await fetch(`${baseUrl}/my.htm`, {
      headers: { ...defaultHeaders, Cookie: cookie },
    });
    if (!response.ok) return null;
    const html = await response.text();
    const match = html.match(/金币[^<]*<\/span>\s*<em[^>]*>(\d+)</);
    return match ? Number(match[1]) : null;
  } catch (error) {
    return null;
  }
}

// 处理
async function processSingleAccount(account) {
  let cookie;
  let result;

  if (account.cookie) {
    // Cookie 模式：Cookie 与域名绑定，自动容灾尝试所有域名
    cookie = account.cookie;
    result = await checkIn(account, cookie, null);
  } else {
    // 账号密码模式：登录成功的域名即为可用域名
    const loginResult = await login(account);
    cookie = loginResult.cookie;
    result = await checkIn(account, cookie, loginResult.baseUrl);
  }

  // 查询现有金币数量并附加到结果
  const coins = await fetchCoins(cookie, result.baseUrl);

  return coins !== null ? `${result.message}（现有金币：${coins}）` : result.message;
}

// ==================== 企业微信通知（群机器人 Webhook） ====================

// 发送企业微信通知，WECOM_WEBHOOK 支持完整 Webhook 地址或仅机器人 Key
async function notifyWeCom(resultMsg) {
  const webhook = process.env.WECOM_WEBHOOK;
  if (!webhook) return;

  const url = webhook.startsWith("http")
    ? webhook
    : `https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=${webhook}`;

  // GitHub Actions 运行详情链接（本地运行时为空则不拼接）
  const runLink =
    process.env.GITHUB_SERVER_URL && process.env.GITHUB_REPOSITORY && process.env.GITHUB_RUN_ID
      ? `\n[任务详情](${process.env.GITHUB_SERVER_URL}/${process.env.GITHUB_REPOSITORY}/actions/runs/${process.env.GITHUB_RUN_ID})`
      : "";

  // 企业微信 markdown 内容上限 4096 字节，超长截断
  let content = `**HIFINI 自动签到**\n${resultMsg}${runLink}`;
  if (Buffer.byteLength(content, "utf8") > 4000) {
    content = content.slice(0, 4000) + "\n...（内容过长已截断）";
  }

  try {
    const response = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json; charset=UTF-8" },
      body: JSON.stringify({ msgtype: "markdown", markdown: { content } }),
    });
    const responseJson = await response.json();
    if (responseJson.errcode === 0) {
      console.log("企业微信通知发送成功。");
    } else {
      console.error(`企业微信通知发送失败: ${JSON.stringify(responseJson)}`);
    }
  } catch (error) {
    console.error(`企业微信通知发送出错: ${error.message}`);
  }
}

function setGitHubOutput(name, value) {
  if (!process.env.GITHUB_OUTPUT) return;
  appendFileSync(process.env.GITHUB_OUTPUT, `${name}<<EOF\n${value}\nEOF\n`);
}

async function main() {
  let accounts;

  try {
    if (!process.env.ACCOUNTS) {
      throw new Error("❌ 未配置账户信息。");
    }

    accounts = JSON.parse(process.env.ACCOUNTS);
  } catch (error) {
    const message = `❌ ${error.message.includes("JSON") ? "账户信息配置格式错误。" : error.message
      }`;
    console.error(message);
    setGitHubOutput("result", message);
    process.exit(1);
  }

  // 校验账户配置：Cookie 或 账号密码 至少配置一项
  for (const account of accounts) {
    if (!account.cookie && (!account.username || !account.password)) {
      const message = `❌ 账户 [${account.name || "未命名"}] 未配置 Cookie 或 账号密码（username + password）。`;
      console.error(message);
      setGitHubOutput("result", message);
      process.exit(1);
    }
  }

  const allPromises = accounts.map((account) => processSingleAccount(account));
  const results = await Promise.allSettled(allPromises);

  const msgHeader = "\n======== 签到结果 ========\n\n";
  console.log(msgHeader);

  let hasError = false;

  const resultLines = results.map((result, index) => {
    const accountName = accounts[index].name;

    const isSuccess = result.status === "fulfilled";

    if (!isSuccess) {
      hasError = true;
    }

    const icon = isSuccess ? "✅" : "❌";
    const message = isSuccess ? result.value : result.reason.message;

    const line = `${accountName}: ${icon} ${message}`;

    isSuccess ? console.log(line) : console.error(line);

    return line;
  });

  const resultMsg = resultLines.join("\n");

  setGitHubOutput("result", resultMsg);

  // 发送企业微信通知（未配置 WECOM_WEBHOOK 时自动跳过）
  await notifyWeCom(resultMsg);

  if (hasError) {
    process.exit(1);
  }
}

main();
