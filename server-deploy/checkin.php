<?php
/**
 * HIFINI（音乐磁场）自动签到 - 服务器部署版（PHP 单文件，零依赖，需 PHP 7.1+ 且启用 curl 扩展）
 *
 * 功能：
 *  - 账号密码登录（自动过 guard 反爬）或 Cookie 两种模式
 *  - 多域名容灾：hifini.com.cn（主）→ hifihi.com → hifini.net
 *  - 企业微信群机器人通知（可选）
 *
 * 使用：
 *  1. 复制 config.example.php 为 config.php，填写账号信息
 *  2. 手动测试：php checkin.php
 *  3. 定时任务（crontab -e）：
 *     40 7 * * * cd /www/wwwroot/checkin && php checkin.php >> checkin.log 2>&1
 */

date_default_timezone_set('Asia/Shanghai');
error_reporting(E_ALL & ~E_DEPRECATED);

require __DIR__ . '/config.php';

// ==================== 常量 ====================

const SUCCESS_CODE = '0';
const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/138.0.0.0 Safari/537.36';

// ==================== 网络请求 ====================

/**
 * 发起 HTTP 请求并手动维护 Cookie Jar（保持与浏览器一致的行为）
 * @return array{status:int, body:string}
 */
function httpRequest(string $url, string $method = 'GET', $body = null, array &$jar = [], array $extraHeaders = []): array
{
    $headers = array_merge([
        'User-Agent: ' . UA,
        'Accept: */*',
        'Accept-Language: zh-CN,zh;q=0.9',
    ], $extraHeaders);

    if (!empty($jar)) {
        $pairs = [];
        foreach ($jar as $k => $v) {
            $pairs[] = "$k=$v";
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : (string)$body);
    }

    // 部署目录若附带 cacert.pem 则使用（Windows/无系统 CA 环境兜底）
    $cacert = __DIR__ . '/cacert.pem';
    if (is_file($cacert)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cacert);
    }

    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        throw new RuntimeException("网络请求出错($errno): $err");
    }

    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $headerText = substr($raw, 0, $headerSize);
    $bodyText = substr($raw, $headerSize);

    // 解析 Set-Cookie 合并进 Jar（新值覆盖旧值）
    foreach (preg_split('/\r?\n/', $headerText) as $line) {
        if (stripos($line, 'set-cookie:') === 0) {
            $pair = trim(substr($line, 11));
            $pair = explode(';', $pair)[0];
            $pos = strpos($pair, '=');
            if ($pos > 0) {
                $jar[trim(substr($pair, 0, $pos))] = substr($pair, $pos + 1);
            }
        }
    }

    return ['status' => $status, 'body' => $bodyText];
}

// ==================== guard 反爬（复现站点 /_guard/auto.js 逻辑） ====================

function rc4(string $text, string $key): string
{
    $s = range(0, 255);
    $j = 0;
    $keyLen = strlen($key);
    for ($i = 0; $i < 256; $i++) {
        $j = ($j + $s[$i] + ord($key[$i % $keyLen])) % 256;
        [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
    }
    $i = 0;
    $j = 0;
    $out = '';
    $len = strlen($text);
    for ($k = 0; $k < $len; $k++) {
        $i = ($i + 1) % 256;
        $j = ($j + $s[$i]) % 256;
        [$s[$i], $s[$j]] = [$s[$j], $s[$i]];
        $out .= chr(ord($text[$k]) ^ $s[($s[$i] + $s[$j]) % 256]);
    }
    return $out;
}

/** 根据 guard Cookie 计算站点要求的 guardret Cookie */
function solveGuard(string $guard): string
{
    $key = substr($guard, 0, 8);
    $arg = (string)(intval(substr($guard, 12)) * 2 + 18 - 2);
    return base64_encode(rc4($arg, $key));
}

/** 访问站点获取 guard Cookie 并算出 guardret，使后续请求可通过反爬 */
function fetchGuardCookies(string $baseUrl, array &$jar): void
{
    $loginPageUrl = $baseUrl . '/user-login.htm';

    // 第一次访问：拿到 guard Cookie（响应是被反爬拦截的跳转页）
    $first = httpRequest($loginPageUrl, 'GET', null, $jar);

    if (!isset($jar['guard'])) {
        return; // 站点未启用 guard
    }

    // 计算 guardret 后重新访问，获取正常会话 Cookie
    $jar['guardret'] = solveGuard($jar['guard']);
    httpRequest($loginPageUrl, 'GET', null, $jar);
}

// ==================== 登录 ====================

/** 在指定域名上使用账号密码登录，成功返回 true（Cookie 写入 $jar） */
function loginOnDomain(array $account, string $baseUrl, array &$jar): bool
{
    $jar = [];

    // 先过 guard 反爬，拿到基础 Cookie
    fetchGuardCookies($baseUrl, $jar);

    $response = httpRequest($baseUrl . '/user-login.htm', 'POST', [
        'email' => $account['username'],
        // 站点前端逻辑：提交前将密码 MD5 加密（见站点 view/js/md5.js 与登录页 JS）
        'password' => md5($account['password']),
    ], $jar, [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . $baseUrl . '/user-login.htm',
    ]);

    $json = json_decode($response['body'], true);
    if (!is_array($json)) {
        throw new RuntimeException("登录响应格式异常（可能被拦截）");
    }
    if (($json['code'] ?? null) !== SUCCESS_CODE) {
        throw new RuntimeException('登录失败: ' . ($json['message'] ?? '未知错误'));
    }
    if (!isset($jar['bbs_sid']) || !isset($jar['bbs_token'])) {
        throw new RuntimeException('登录成功但未获取到有效 Cookie');
    }
    return true;
}

// ==================== 签到 ====================

/**
 * 在指定域名上签到
 * @throws DomainFailException 该域名不可用，可尝试下一个域名
 * @throws RuntimeException 真实签到失败
 */
function checkInOnDomain(array $account, array &$jar, string $baseUrl): string
{
    $response = httpRequest($baseUrl . '/sg_sign.htm', 'POST', null, $jar, [
        'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
        'X-Requested-With: XMLHttpRequest',
        'Referer: ' . $baseUrl . '/',
    ]);

    $json = json_decode($response['body'], true);
    if (!is_array($json)) {
        throw new DomainFailException('响应非 JSON（可能被拦截）');
    }

    $code = $json['code'] ?? null;
    $message = $json['message'] ?? '';

    if ($code === SUCCESS_CODE) {
        return $message;
    }
    if ($message === '今天已经签过啦！') {
        return $message;
    }
    // 未登录类提示说明 Cookie 在此域名上无效，可尝试其他域名
    if (is_string($message) && (mb_strpos($message, '登录') !== false || mb_strpos($message, '用户') !== false)) {
        throw new DomainFailException($message);
    }
    throw new RuntimeException('签到失败: ' . $message);
}

class DomainFailException extends RuntimeException
{
}

// ==================== 企业微信通知（群机器人 Webhook） ====================

function notifyWeCom(string $resultMsg): void
{
    global $WECOM_WEBHOOK;
    if (empty($WECOM_WEBHOOK)) {
        return;
    }

    $webhook = $WECOM_WEBHOOK;
    if (strpos($webhook, 'http') !== 0) {
        $webhook = 'https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=' . $webhook;
    }

    // 企业微信 markdown 内容上限 4096 字节，超长截断
    $content = "**HIFINI 自动签到**\n$resultMsg";
    if (strlen($content) > 4000) {
        $content = mb_strcut($content, 0, 4000, 'UTF-8') . "\n...（内容过长已截断）";
    }

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json; charset=UTF-8'],
        CURLOPT_POSTFIELDS => json_encode(['msgtype' => 'markdown', 'markdown' => ['content' => $content]], JSON_UNESCAPED_UNICODE),
    ]);
    $cacert = __DIR__ . '/cacert.pem';
    if (is_file($cacert)) {
        curl_setopt($ch, CURLOPT_CAINFO, $cacert);
    }
    $raw = curl_exec($ch);
    if ($raw === false) {
        logLine('企业微信通知发送出错: ' . curl_error($ch));
        curl_close($ch);
        return;
    }
    curl_close($ch);

    $json = json_decode($raw, true);
    if (($json['errcode'] ?? -1) === 0) {
        logLine('企业微信通知发送成功。');
    } else {
        logLine('企业微信通知发送失败: ' . $raw);
    }
}

// ==================== 主流程 ====================

function logLine(string $message): void
{
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

/** 多域名容灾登录，返回可用的域名 */
function loginWithFailover(array $account): string
{
    global $BASE_URLS;
    $errors = [];
    foreach ($BASE_URLS as $baseUrl) {
        try {
            $jar = [];
            loginOnDomain($account, $baseUrl, $jar);
            return $baseUrl;
        } catch (Exception $e) {
            $errors[] = $baseUrl . ': ' . $e->getMessage();
        }
    }
    throw new RuntimeException('所有域名登录失败（' . implode('；', $errors) . '）');
}

// 查询账户现有金币数量（my.htm 页面金币字段），失败返回 null 不影响签到
function fetchCoins(array $jar, string $baseUrl): ?int
{
    try {
        $response = httpRequest($baseUrl . '/my.htm', 'GET', null, $jar);
        if ($response['status'] !== 200) {
            return null;
        }
        if (preg_match('/金币[^<]*<\/span>\s*<em[^>]*>(\d+)</u', $response['body'], $m)) {
            return (int)$m[1];
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

/** 多域名容灾签到（Cookie 模式下 Cookie 与域名绑定，需逐域名尝试），返回 ['message', 'jar', 'baseUrl'] */
function checkInWithFailover(array $account, array $jar, ?string $fixedBaseUrl): array
{
    global $BASE_URLS;

    $domains = $fixedBaseUrl !== null
        ? array_merge([$fixedBaseUrl], array_values(array_diff($BASE_URLS, [$fixedBaseUrl])))
        : $BASE_URLS;

    $failedDomains = [];
    foreach ($domains as $baseUrl) {
        $tryJar = $jar;
        try {
            $message = checkInOnDomain($account, $tryJar, $baseUrl);
            return ['message' => $message, 'jar' => $tryJar, 'baseUrl' => $baseUrl];
        } catch (DomainFailException $e) {
            $failedDomains[] = $baseUrl;
        }
    }
    throw new RuntimeException('签到失败: 所有域名均无法完成签到（' . implode('、', $failedDomains) . '）');
}

// ==================== 入口 ====================

logLine('======== HIFINI 自动签到开始 ========');

$resultLines = [];
$hasError = false;

foreach ($ACCOUNTS as $account) {
    $name = $account['name'] ?? '未命名';
    try {
        if (!empty($account['cookie'])) {
            // Cookie 模式
            logLine("【{$name}】: 使用 Cookie 签到...");
            $jar = [];
            foreach (explode(';', $account['cookie']) as $part) {
                $pos = strpos($part, '=');
                if ($pos > 0) {
                    $jar[trim(substr($part, 0, $pos))] = substr($part, $pos + 1);
                }
            }
            $result = checkInWithFailover($account, $jar, null);
        } elseif (!empty($account['username']) && !empty($account['password'])) {
            // 账号密码模式
            logLine("【{$name}】: 正在使用账号密码登录...");
            $baseUrl = loginWithFailover($account);
            logLine("【{$name}】: 登录成功（{$baseUrl}）。");
            $jar = [];
            loginOnDomain($account, $baseUrl, $jar);
            logLine("【{$name}】: 开始签到...");
            $result = checkInWithFailover($account, $jar, $baseUrl);
        } else {
            throw new RuntimeException('未配置 Cookie 或 账号密码（username + password）');
        }

        // 查询现有金币数量并附加到结果
        $coins = fetchCoins($result['jar'], $result['baseUrl']);
        $message = $result['message'] . ($coins !== null ? "（现有金币：{$coins}）" : '');

        logLine("【{$name}】: {$message}");
        $resultLines[] = "$name: ✅ $message";
    } catch (Exception $e) {
        logLine("【{$name}】: ❌ " . $e->getMessage());
        $resultLines[] = "$name: ❌ " . $e->getMessage();
        $hasError = true;
    }
}

logLine('======== 签到结果 ========');
$resultMsg = implode("\n", $resultLines);
logLine($resultMsg);

notifyWeCom($resultMsg);

exit($hasError ? 1 : 0);
