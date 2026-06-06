<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\MailLog;
use App\Models\MailTemplate;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class MailService
{
    // Render {{key}} / {{key|default}} placeholders.
    private static function renderPlaceholders(string $template, array $vars): string
    {
        if ($template === '' || empty($vars)) {
            return $template;
        }

        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.-]+)(?:\|([^}]*))?\s*\}\}/', function ($m) use ($vars) {
            $key = $m[1] ?? '';
            $default = array_key_exists(2, $m) ? trim((string) $m[2]) : null;

            if (!array_key_exists($key, $vars) || $vars[$key] === null || $vars[$key] === '') {
                return $default !== null ? $default : $m[0];
            }

            $value = $vars[$key];
            if (is_bool($value)) {
                return $value ? '1' : '0';
            }
            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }, $template);
    }

    /**
     * 获取需要发送提醒的用户总数
     */
    public function getTotalUsersNeedRemind(): int
    {
        return User::where(function ($query) {
            $query->where('remind_expire', true)
                ->orWhere('remind_traffic', true);
        })
            ->where('banned', false)
            ->whereNotNull('email')
            ->count();
    }

    /**
     * 分块处理用户提醒邮件
     */
    public function processUsersInChunks(int $chunkSize, ?callable $progressCallback = null): array
    {
        $statistics = [
            'processed_users' => 0,
            'expire_emails' => 0,
            'traffic_emails' => 0,
            'errors' => 0,
            'skipped' => 0,
        ];

        User::select('id', 'email', 'expired_at', 'transfer_enable', 'u', 'd', 'remind_expire', 'remind_traffic')
            ->where(function ($query) {
                $query->where('remind_expire', true)
                    ->orWhere('remind_traffic', true);
            })
            ->where('banned', false)
            ->whereNotNull('email')
            ->chunk($chunkSize, function ($users) use (&$statistics, $progressCallback) {
                $this->processUserChunk($users, $statistics);

                if ($progressCallback) {
                    $progressCallback();
                }

                // 定期清理内存
                if ($statistics['processed_users'] % 2500 === 0) {
                    gc_collect_cycles();
                }
            });

        return $statistics;
    }

    /**
     * 处理用户块
     */
    private function processUserChunk($users, array &$statistics): void
    {
        foreach ($users as $user) {
            try {
                $statistics['processed_users']++;
                $emailsSent = 0;

                // 检查并发送过期提醒
                if ($user->remind_expire && $this->shouldSendExpireRemind($user)) {
                    $this->remindExpire($user);
                    $statistics['expire_emails']++;
                    $emailsSent++;
                }

                // 检查并发送流量提醒
                if ($user->remind_traffic && $this->shouldSendTrafficRemind($user)) {
                    $this->remindTraffic($user);
                    $statistics['traffic_emails']++;
                    $emailsSent++;
                }

                if ($emailsSent === 0) {
                    $statistics['skipped']++;
                }

            } catch (\Exception $e) {
                $statistics['errors']++;

                Log::error('发送提醒邮件失败', [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * 检查是否应该发送过期提醒
     */
    private function shouldSendExpireRemind(User $user): bool
    {
        if ($user->expired_at === NULL) {
            return false;
        }
        $expiredAt = $user->expired_at;
        $now = time();
        if (($expiredAt - 86400) < $now && $expiredAt > $now) {
            return true;
        }
        return false;
    }

    /**
     * 检查是否应该发送流量提醒
     */
    private function shouldSendTrafficRemind(User $user): bool
    {
        if ($user->transfer_enable <= 0) {
            return false;
        }

        $usedBytes = $user->u + $user->d;
        $usageRatio = $usedBytes / $user->transfer_enable;

        // 流量使用超过80%时发送提醒
        return $usageRatio >= 0.8;
    }

    public function remindTraffic(User $user)
    {
        if (!$user->remind_traffic)
            return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable))
            return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag))
            return;
        if (!Cache::put($flag, 1, 24 * 3600))
            return;

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The traffic usage in :app_name has reached 80%', [
                'app_name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!$this->shouldSendExpireRemind($user)) {
            return;
        }

        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => __('The service in :app_name is about to expire', [
                'app_name' => admin_setting('app_name', 'XBoard')
            ]),
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => admin_setting('app_name', 'XBoard'),
                'url' => admin_setting('app_url')
            ]
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud)
            return false;
        if (!$transfer_enable)
            return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 80)
            return false;
        if ($percentage >= 100)
            return false;
        return true;
    }

    /**
     * 发送邮件
     *
     * @param array $params 包含邮件参数的数组，必须包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称，例如 "welcome" 或 "password_reset"
     *   - template_value: 邮件模板变量，一个关联数组，包含模板中需要替换的变量和对应的值
     * @return array 包含邮件发送结果的数组，包含以下字段：
     *   - email: 收件人邮箱地址
     *   - subject: 邮件主题
     *   - template_name: 邮件模板名称
     *   - error: 如果邮件发送失败，包含错误信息；否则为 null
     */
    public static function sendEmail(array $params)
    {
        // 优先检查是否配置了 Cloudflare Email Service
        $useCfEmailService = admin_setting('email_use_cf_email_service', false);
        $cfAccountId = admin_setting('email_cf_account_id');
        $cfApiToken = admin_setting('email_cf_api_token');

        if ($useCfEmailService && $cfAccountId && $cfApiToken) {
            return self::sendEmailViaCfEmailService($params, $cfAccountId, $cfApiToken);
        }

        // 以下为原有 SMTP + DB模板 逻辑
        if (admin_setting('email_host')) {
            Config::set('mail.host', admin_setting('email_host', config('mail.host')));
            Config::set('mail.port', admin_setting('email_port', config('mail.port')));
            Config::set('mail.encryption', admin_setting('email_encryption', config('mail.encryption')));
            Config::set('mail.username', admin_setting('email_username', config('mail.username')));
            Config::set('mail.password', admin_setting('email_password', config('mail.password')));
            Config::set('mail.from.address', admin_setting('email_from_address', config('mail.from.address')));
            Config::set('mail.from.name', admin_setting('app_name', 'XBoard'));
        }

        $email = $params['email'];
        $subject = $params['subject'];
        $templateName = $params['template_name'];

        $templateValue = $params['template_value'] ?? [];
        $vars = is_array($templateValue) ? ($templateValue['vars'] ?? []) : [];
        $contentMode = is_array($templateValue) ? ($templateValue['content_mode'] ?? null) : null;

        if (is_array($vars) && !empty($vars)) {
            $subject = self::renderPlaceholders((string) $subject, $vars);

            if (is_array($templateValue) && isset($templateValue['content']) && is_string($templateValue['content'])) {
                $templateValue['content'] = self::renderPlaceholders($templateValue['content'], $vars);
            }
        }

        if ($contentMode === 'text' && is_array($templateValue) && isset($templateValue['content']) && is_string($templateValue['content'])) {
            $templateValue['content'] = e($templateValue['content']);
        }

        $params['template_value'] = $templateValue;

        // Check for DB template override (cached to avoid per-email queries in bulk sends).
        $cacheKey = "mail_template:{$templateName}";
        $cached = Cache::get($cacheKey);
        if ($cached === null) {
            $dbTemplate = MailTemplate::where('name', $templateName)->first();
            Cache::put($cacheKey, $dbTemplate ?: 'none', 3600);
        } else {
            $dbTemplate = ($cached === 'none') ? null : $cached;
        }

        try {
            if ($dbTemplate) {
                $renderVars = self::buildSafeVars($templateValue);
                $renderedSubject = self::renderPlaceholders($dbTemplate->subject, $renderVars);
                $renderedContent = self::renderPlaceholders($dbTemplate->content, $renderVars);
                $subject = $renderedSubject ?: $subject;

                Mail::html($renderedContent, function ($message) use ($email, $subject) {
                    $message->to($email)->subject($subject);
                });
                $params['template_name'] = 'db:' . $templateName;
            } else {
                $params['template_name'] = 'mail.default.' . $templateName;
                Mail::send(
                    $params['template_name'],
                    $params['template_value'],
                    function ($message) use ($email, $subject) {
                        $message->to($email)->subject($subject);
                    }
                );
            }
            $error = null;
        } catch (\Exception $e) {
            Log::error($e);
            $error = $e->getMessage();
        }

        $log = [
            'email' => $params['email'],
            'subject' => $params['subject'],
            'template_name' => $params['template_name'],
            'error' => $error,
            'config' => config('mail')
        ];

        MailLog::create($log);
        return $log;
    }

    /**
     * 通过 Cloudflare Email Service REST API 发送邮件
     *
     * 前置条件：
     *   1. 域名 DNS 托管在 Cloudflare，并已启用 Email Routing
     *   2. 已验证发件人域名（SPF / DKIM / DMARC）
     *   3. API Token 需具备 "Email Sending: Edit" 权限
     *   4. 需要付费 Workers 订阅（Beta 期间免费额度可能有限）
     *
     * 后台所需配置项（admin_setting）：
     *   - email_use_cf_email_service : true
     *   - email_cf_account_id        : Cloudflare Account ID
     *   - email_cf_api_token         : 具有 Email Sending 权限的 API Token
     *   - email_from_address         : 已验证的发件人邮箱（必须属于 Cloudflare 托管域名）
     *
     * @param array  $params      邮件参数（email / subject / template_name / template_value）
     * @param string $accountId   Cloudflare Account ID
     * @param string $apiToken    Cloudflare API Token
     * @return array              邮件发送日志数组
     */
    private static function sendEmailViaCfEmailService(array $params, string $accountId, string $apiToken): array
    {
        $email      = $params['email'];
        $subject    = $params['subject'];
        $templateName = 'mail.' . admin_setting('email_template', 'default') . '.' . $params['template_name'];

        $fromEmail = admin_setting('email_from_address')
            ?? admin_setting('email_username')
            ?? config('mail.from.address')
            ?? 'noreply@example.com';

        $fromName = admin_setting('app_name', 'XBoard');

        $error = null;

        try {
            // 渲染 Blade 模板为 HTML
            $htmlContent = view($templateName, $params['template_value'])->render();

            // 生成纯文本备用内容（去除 HTML 标签）
            $textContent = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlContent));

            // 调用 Cloudflare Email Service REST API
            // 文档：https://developers.cloudflare.com/email-service/api/send-emails/rest-api/
            $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$accountId}/email/sending/send";

            $response = Http::timeout(30)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiToken,
                    'Content-Type'  => 'application/json',
                ])
                ->post($endpoint, [
                    'from' => [
                        'address' => $fromEmail,
                        'name'    => $fromName,
                    ],
                    'to'      => $email,
                    'subject' => $subject,
                    'html'    => $htmlContent,
                    'text'    => $textContent,
                ]);

            $responseData = $response->json();

            if ($response->successful() && ($responseData['success'] ?? false)) {
                $result = $responseData['result'] ?? [];

                Log::info('邮件发送成功 (Cloudflare Email Service)', [
                    'email'              => $email,
                    'subject'            => $subject,
                    'from'               => $fromEmail,
                    'delivered'          => $result['delivered'] ?? [],
                    'queued'             => $result['queued'] ?? [],
                    'permanent_bounces'  => $result['permanent_bounces'] ?? [],
                ]);

                // 如果邮件被永久退信，记录为错误但不抛出异常
                if (!empty($result['permanent_bounces'])) {
                    $error = 'Permanent bounce: ' . implode(', ', $result['permanent_bounces']);
                }
            } else {
                $cfErrors = $responseData['errors'] ?? [];
                $errorMsg = !empty($cfErrors)
                    ? implode('; ', array_map(fn($e) => "[{$e['code']}] {$e['message']}", $cfErrors))
                    : $response->body();

                $error = 'CF Email Service Error: ' . $errorMsg;

                Log::error('邮件发送失败 (Cloudflare Email Service)', [
                    'email'   => $email,
                    'subject' => $subject,
                    'from'    => $fromEmail,
                    'status'  => $response->status(),
                    'errors'  => $cfErrors,
                ]);
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();

            Log::error('邮件发送异常 (Cloudflare Email Service)', [
                'email'     => $email,
                'subject'   => $subject,
                'exception' => $e->getMessage(),
            ]);
        }

        $log = [
            'email'         => $email,
            'subject'       => $subject,
            'template_name' => $templateName,
            'error'         => $error,
            'config'        => [
                'driver'     => 'cloudflare_email_service',
                'account_id' => $accountId,
                'from'       => $fromEmail,
            ],
        ];

        MailLog::create($log);
        return $log;
    }

    /**
     * Build HTML-escaped vars for DB template rendering.
     */
    private static function buildSafeVars(array $templateValue): array
    {
        $safe = [];
        foreach ($templateValue as $key => $value) {
            if (is_scalar($value)) {
                $safe[$key] = e((string) $value);
            }
        }
        // 'content' may be pre-escaped text or admin-authored HTML.
        if (isset($templateValue['content'])) {
            $content = (string) $templateValue['content'];
            $contentMode = $templateValue['content_mode'] ?? null;
            $safe['content'] = ($contentMode === 'text') ? nl2br($content) : $content;
        }
        return $safe;
    }
}
