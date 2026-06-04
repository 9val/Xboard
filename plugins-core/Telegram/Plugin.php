<?php

namespace Plugin\Telegram;

use App\Models\Order;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\Plugin\HookManager;
use App\Services\TelegramService;
use App\Services\TicketService;
use App\Utils\Helper;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Console\Scheduling\Schedule;

class Plugin extends AbstractPlugin
{
  protected array $commands = [];
  protected TelegramService $telegramService;

  protected array $commandConfigs = [
    '/start' => ['description' => '开始使用', 'handler' => 'handleStartCommand'],
    '/login' => ['description' => '登录网站', 'handler' => 'handleMenuCommand'], 
    '/bind' => ['description' => '绑定账号', 'handler' => 'handleBindCommand'],
    '/invite' => ['description' => '获取邀请链接', 'handler' => 'handleInviteCommand'],
    '/mystats' => ['description' => '查看邀请统计', 'handler' => 'handleMyStatsCommand'],
    '/checkin' => ['description' => '每日签到领流量', 'handler' => 'handleCheckinCommand'],
    '/checkin_status' => ['description' => '查看签到状态', 'handler' => 'handleCheckinStatusCommand'],
    '/traffic' => ['description' => '查看流量', 'handler' => 'handleTrafficCommand'],
    '/getlatesturl' => ['description' => '获取订阅链接', 'handler' => 'handleGetLatestUrlCommand'],
    '/unbind' => ['description' => '解绑账号', 'handler' => 'handleUnbindCommand'],
  ];

  public function boot(): void
  {
    $this->telegramService = new TelegramService();
    $this->registerDefaultCommands();

    $this->filter('telegram.message.handle', [$this, 'handleMessage'], 10);
    $this->listen('telegram.message.unhandled', [$this, 'handleUnknownCommand'], 10);
    $this->listen('telegram.message.error', [$this, 'handleError'], 10);
    $this->filter('telegram.bot.commands', [$this, 'addBotCommands'], 10);
    $this->listen('ticket.create.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('ticket.reply.user.after', [$this, 'sendTicketNotify'], 10);
    $this->listen('payment.notify.success', [$this, 'sendPaymentNotify'], 10);
  }

  /**
   * 注册定时任务
   */
  public function schedule(Schedule $schedule): void
  {
    // 每天凌晨重置签到统计（可选）
    $schedule->call(function () {
      $this->resetDailyCheckinStats();
    })->daily();
    
    // 每天凌晨检查并清理过期的临时流量
    $schedule->call(function () {
      $this->cleanupExpiredTraffic();
    })->daily();
  }

  /**
   * 重置每日签到统计
   */
  protected function resetDailyCheckinStats(): void
  {
    try {
      Log::info('Telegram 签到统计已重置');
    } catch (\Exception $e) {
      Log::error('重置签到统计失败', ['error' => $e->getMessage()]);
    }
  }

  /**
   * 清理过期的临时流量
   */
  protected function cleanupExpiredTraffic(): void
  {
    try {
      $now = time();
      
      // 获取所有有临时流量记录的用户
      $trafficRecords = DB::table('telegram_temp_traffic')
        ->where('expires_at', '<', $now)
        ->get();
      
      foreach ($trafficRecords as $record) {
        $user = User::find($record->user_id);
        if ($user && $record->traffic_amount > 0) {
          // 减少用户的流量额度
          $user->transfer_enable = max(0, $user->transfer_enable - $record->traffic_amount);
          $user->save();
          
          Log::info('清理过期临时流量', [
            'user_id' => $user->id,
            'email' => $user->email,
            'traffic_amount' => $record->traffic_amount,
            'traffic_type' => $record->traffic_type
          ]);
          
          // 通知用户（可选）
          if ($user->telegram_id && $this->getConfig('notify_traffic_expiry', false)) {
            $trafficGB = $this->transferToGBString($record->traffic_amount);
            $message = "⚠️ 流量过期提醒\n\n";
            $message .= "您的 {$record->traffic_type} 流量 {$trafficGB}G 已过期\n";
            $message .= "过期时间：" . date('Y-m-d H:i:s', $record->expires_at);
            $this->telegramService->sendMessage($user->telegram_id, $message);
          }
        }
        
        // 删除记录
        DB::table('telegram_temp_traffic')
          ->where('id', $record->id)
          ->delete();
      }
      
      Log::info('临时流量清理完成', ['cleaned_count' => $trafficRecords->count()]);
    } catch (\Exception $e) {
      Log::error('清理过期临时流量失败', ['error' => $e->getMessage()]);
    }
  }

  public function sendPaymentNotify(Order $order): void
  {
    if (!$this->getConfig('enable_payment_notify', true)) {
      return;
    }

    $payment = $order->payment;
    if (!$payment) {
      Log::warning('支付通知失败：订单关联的支付方式不存在', ['order_id' => $order->id]);
      return;
    }

    $message = sprintf(
      "💰成功收款%s元\n" .
      "———————————————\n" .
      "支付接口：%s\n" .
      "支付渠道：%s\n" .
      "本站订单：`%s`",
      $order->total_amount / 100,
      $payment->payment,
      $payment->name,
      $order->trade_no
    );
    $this->telegramService->sendMessageWithAdmin($message, true);
  }

  public function sendTicketNotify(Ticket $ticket): void
  {
    if (!$this->getConfig('enable_ticket_notify', true)) {
      return;
    }

    $message = $ticket->messages()->latest()->first();
    $user = User::find($ticket->user_id);
    if (!$user)
      return;
    $user->load('plan');
    $transfer_enable = $this->transferToGBString($user->transfer_enable);
    $remaining_traffic = $this->transferToGBString($user->transfer_enable - $user->u - $user->d);
    $u = $this->transferToGBString($user->u);
    $d = $this->transferToGBString($user->d);
    $expired_at = $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效';
    $money = $user->balance / 100;
    $affmoney = $user->commission_balance / 100;
    $plan = $user->plan;
    $ip = request()?->ip() ?? '';
    $region = $ip ? (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? (new \Ip2Region())->simple($ip) : 'NULL') : '';
    $TGmessage = "📮 *工单提醒* #{$ticket->id}\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📧 邮箱: `{$user->email}`\n";
    $TGmessage .= "📍 位置: `{$region}`\n";

    if ($plan) {
      $TGmessage .= "📦 套餐: `{$plan->name}`\n";
      $TGmessage .= "📊 流量: `{$remaining_traffic}G / {$transfer_enable}G` (剩余/总计)\n";
      $TGmessage .= "⬆️⬇️ 已用: `{$u}G / {$d}G`\n";
      $TGmessage .= "⏰ 到期: `{$expired_at}`\n";
    } else {
      $TGmessage .= "📦 套餐: `未订购任何套餐`\n";
    }

    $TGmessage .= "💰 余额: `{$money}元`\n";
    $TGmessage .= "💸 佣金: `{$affmoney}元`\n";
    $TGmessage .= "━━━━━━━━━━━━━━━━━━━━\n";
    $TGmessage .= "📝 *主题*: `{$ticket->subject}`\n";
    $TGmessage .= "💬 *内容*: `{$message->message}`";
    $this->telegramService->sendMessageWithAdmin($TGmessage, true);
  }

  protected function registerDefaultCommands(): void
  {
    foreach ($this->commandConfigs as $command => $config) {
      $this->registerTelegramCommand($command, [$this, $config['handler']]);
    }

    $this->registerReplyHandler('/(📮.*?工单提醒.*?#?|工单ID: ?)(\\d+)/', [$this, 'handleTicketReply']);
  }

  public function registerTelegramCommand(string $command, callable $handler): void
  {
    $this->commands['commands'][$command] = $handler;
  }

  public function registerReplyHandler(string $regex, callable $handler): void
  {
    $this->commands['replies'][$regex] = $handler;
  }

  /**
   * 发送消息给用户
   */
  protected function sendMessage(object $msg, string $message, string $parseMode = 'markdown'): void
  {
    $this->telegramService->sendMessage($msg->chat_id, $message, $parseMode);
  }

    /**
     * 直接调用 Telegram API 发送带按钮的消息
     */
    protected function sendMessageWithButtons(object $msg, string $message, array $buttons, string $parseMode = 'markdown'): void
    {
        try {
            $botToken = admin_setting('telegram_bot_token');
            $apiUrl = "https://api.telegram.org/bot{$botToken}/sendMessage";
            
            // 处理 markdown 转义
            $text = $parseMode === 'markdown' ? str_replace('_', '\_', $message) : $message;
            
            $params = [
                'chat_id' => $msg->chat_id,
                'text' => $text,
                'parse_mode' => $parseMode ?: null,
                'reply_markup' => json_encode([
                    'inline_keyboard' => $buttons
                ])
            ];
            
            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->retry(3, 1000)
                ->get($apiUrl, $params);
            
            if (!$response->successful()) {
                Log::error('Telegram 发送按钮消息失败', [
                    'status' => $response->status(),
                    'response' => $response->body()
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Telegram 发送按钮消息异常', [
                'error' => $e->getMessage(),
                'chat_id' => $msg->chat_id ?? 'unknown'
            ]);
            // 如果发送按钮消息失败，降级为普通消息
            $this->sendMessage($msg, $message, $parseMode);
        }
    }

  /**
   * 检查是否为私聊
   */
  protected function checkPrivateChat(object $msg): bool
  {
    if (!$msg->is_private) {
      $this->sendMessage($msg, '请在私聊中使用此命令');
      return false;
    }
    return true;
  }

  /**
   * 获取绑定的用户
   */
  protected function getBoundUser(object $msg): ?User
  {
    $user = User::where('telegram_id', $msg->chat_id)->first();
    if (!$user) {
      $this->sendMessage($msg, '请先使用 /bind 命令绑定账号');
      return null;
    }
    return $user;
  }

  public function handleStartCommand(object $msg): void
  {
    $welcomeTitle = $this->getConfig('start_welcome_title', '🎉 欢迎使用 XBoard Telegram Bot！');
    $botDescription = $this->getConfig('start_bot_description', '🤖 一站式管理助手，可以帮助您：\\n• /login 注册购买套餐\\n• 查看流量使用\\n• 绑定穿云账号\\n• 每日签到领流量\\n• 邀请好友得奖励');
    $footer = $this->getConfig('start_footer', '💡 提示：所有命令都需要在私聊中使用');

    // 检查是否通过邀请链接进入
    if (isset($msg->args[0]) && str_starts_with($msg->args[0], 'inv_')) {
      $inviteCode = substr($msg->args[0], 4); // 移除 'inv_' 前缀
      $this->handleInvitedUser($msg, $inviteCode);
      return;
    }

    $welcomeText = $welcomeTitle . "\n\n" . $botDescription . "\n\n";

    $user = User::where('telegram_id', $msg->chat_id)->first();
    if ($user) {
      // 已绑定用户 - 显示邀请统计
      if ($this->getConfig('enable_invite', true)) {
        $stats = $this->getInviteStats($msg->chat_id);
        $welcomeText .= "✅ 您已绑定账号：{$user->email}\n\n";
        $welcomeText .= "📊 邀请统计：\n";
        $welcomeText .= "├─ 已邀请：{$stats['successful_invites']} 人\n";
        $welcomeText .= "└─ 累计获得：" . $this->transferToGBString($stats['total_reward_traffic']) . "G\n\n";
      } else {
        $welcomeText .= "✅ 您已绑定账号：{$user->email}\n\n";
      }
      $welcomeText .= $this->getConfig('start_unbind_guide', '📋 可用命令：\\n/login - 登录网站后台\\n/invite - 邀请好友得奖励\\n/checkin - 每日签到领流量\\n/mystats - 查看邀请统计\\n/checkin_status - 查看签到状态\\n/traffic - 查看流量使用\\n/getlatesturl - 获取订阅链接\\n/unbind - 解绑账号');
    } else {
      $welcomeText .= $this->getConfig('start_bind_guide', '🔗 请先绑定您的 XBoard 账号：\\n1. 登录您的 XBoard 账户\\n2. 复制您的订阅链接\\n3. 发送 /bind + 订阅链接') . "\n\n";
      $welcomeText .= $this->getConfig('start_bind_commands', '📋 可用命令：\\n/bind [订阅链接] - 绑定账号');
    }

    $welcomeText .= "\n\n" . $footer;
    $welcomeText = str_replace('\\n', "\n", $welcomeText);

    // 构建快捷按钮
    $appUrl = $this->getAppUrl();
    $buttons = [];

    if ($user) {
        // 已绑定用户
        $buttons[] = [
            ['text' => '🔐 登录网站', 'url' => rtrim($appUrl, '/') . '/#/login'],
            ['text' => '💎 购买套餐', 'url' => rtrim($appUrl, '/') . '/#/plan']
        ];
    } else {
        // 未绑定用户
        $buttons[] = [
            ['text' => '🔐 登录账号', 'url' => rtrim($appUrl, '/') . '/#/login'],
            ['text' => '📝 注册账号', 'url' => rtrim($appUrl, '/') . '/#/register']
        ];
        $buttons[] = [
            ['text' => '💎 查看套餐', 'url' => rtrim($appUrl, '/') . '/#/plan']
        ];
    }

    $this->sendMessageWithButtons($msg, $welcomeText, $buttons);
  }

  public function handleMessage(bool $handled, array $data): bool
  {
    list($msg) = $data;
    if ($handled)
      return $handled;

    try {
      return match ($msg->message_type) {
        'message' => $this->handleCommandMessage($msg),
        'reply_message' => $this->handleReplyMessage($msg),
        default => false
      };
    } catch (\Exception $e) {
      Log::error('Telegram 命令处理意外错误', [
        'command' => $msg->command ?? 'unknown',
        'chat_id' => $msg->chat_id ?? 'unknown',
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
      ]);

      if (isset($msg->chat_id)) {
        $this->telegramService->sendMessage($msg->chat_id, '系统繁忙，请稍后重试');
      }

      return true;
    }
  }

  protected function handleCommandMessage(object $msg): bool
  {
    if (!isset($this->commands['commands'][$msg->command])) {
      return false;
    }

    call_user_func($this->commands['commands'][$msg->command], $msg);
    return true;
  }

  protected function handleReplyMessage(object $msg): bool
  {
    if (!isset($this->commands['replies'])) {
      return false;
    }

    foreach ($this->commands['replies'] as $regex => $handler) {
      if (preg_match($regex, $msg->reply_text, $matches)) {
        call_user_func($handler, $msg, $matches);
        return true;
      }
    }

    return false;
  }

  public function handleUnknownCommand(array $data): void
  {
    list($msg) = $data;
    if (!$msg->is_private || $msg->message_type !== 'message')
      return;

    $helpText = $this->getConfig('help_text', '未知命令，请使用 /start 查看可用命令');
    $this->telegramService->sendMessage($msg->chat_id, $helpText);
  }

  public function handleError(array $data): void
  {
    list($msg, $e) = $data;
    Log::error('Telegram 消息处理错误', [
      'chat_id' => $msg->chat_id ?? 'unknown',
      'command' => $msg->command ?? 'unknown',
      'message_type' => $msg->message_type ?? 'unknown',
      'error' => $e->getMessage(),
      'file' => $e->getFile(),
      'line' => $e->getLine()
    ]);
  }

  public function handleBindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $subscribeUrl = $msg->args[0] ?? null;
    if (!$subscribeUrl) {
      $exampleUrl = $this->getSubscribeUrl();
      $this->sendMessage($msg, "参数有误，请携带订阅地址发送\n\n用法：`/bind {$exampleUrl}/s/token`");
      return;
    }

    $token = $this->extractTokenFromUrl($subscribeUrl);
    if (!$token) {
      $this->sendMessage($msg, '订阅地址无效，请检查链接格式');
      return;
    }

    $user = User::where('token', $token)->first();
    if (!$user) {
      $this->sendMessage($msg, '用户不存在，请确认订阅链接是否正确');
      return;
    }

    if ($user->telegram_id && $user->telegram_id != $msg->chat_id) {
      $this->sendMessage($msg, '该账号已经绑定了其他 Telegram 账号');
      return;
    }

    // 检查是否曾经绑定过（通过数据库历史记录判断）
    $hadBoundBefore = DB::table('telegram_bind_history')
      ->where('user_id', $user->id)
      ->exists();
    
    // 如果没有历史记录表，则通过telegram_id判断
    if (!DB::getSchemaBuilder()->hasTable('telegram_bind_history')) {
      $hadBoundBefore = !empty($user->telegram_id);
    }
    
    $user->telegram_id = $msg->chat_id;
    
    // 添加绑定奖励（仅首次绑定）
    $bindReward = 0;
    if (!$hadBoundBefore && $this->getConfig('enable_bind_reward', true)) {
      $bindReward = $this->getConfig('bind_reward', 6442450944); // 默认 6GB
      $user->transfer_enable += $bindReward;
      
      // 记录临时流量（绑定奖励，30天有效）
      $this->addTempTraffic($user->id, $bindReward, 'bind_reward', 30);
      
      // 记录绑定历史
      if (DB::getSchemaBuilder()->hasTable('telegram_bind_history')) {
        DB::table('telegram_bind_history')->insert([
          'user_id' => $user->id,
          'telegram_id' => $msg->chat_id,
          'bound_at' => date('Y-m-d H:i:s')
        ]);
      }
    }
    
    // 处理邀请奖励（仅首次绑定）
    $inviteRewards = ['invitee_reward' => 0, 'inviter_reward' => 0, 'inviter_tg_id' => null, 'inviter_notified' => false];
    if (!$hadBoundBefore) {
      $inviteRewards = $this->processInviteReward($msg->chat_id, $user);
    }
    
    if (!$user->save()) {
      $this->sendMessage($msg, '绑定失败，请稍后重试');
      return;
    }

    HookManager::call('user.telegram.bind.after', [$user]);
    
    // 发送绑定成功消息
    if ($hadBoundBefore) {
      $successMessage = "✅ 重新绑定成功！\n\n您的账号：`{$user->email}`\n\n";
      $successMessage .= "现在可以使用以下功能：\n• /invite - 邀请好友得奖励\n• /checkin - 每日签到领流量\n• /traffic - 查看流量\n• /getlatesturl - 获取订阅链接";
    } else {
      $successMessage = "✅ 绑定成功！\n\n您的账号：`{$user->email}`\n";
      
      if ($bindReward > 0) {
        $rewardGB = $this->transferToGBString($bindReward);
        $successMessage .= "🎁 绑定奖励：`{$rewardGB}G`（60天有效）\n";
      }
      
      if ($inviteRewards['invitee_reward'] > 0) {
        $inviteeRewardGB = $this->transferToGBString($inviteRewards['invitee_reward']);
        $successMessage .= "🎁 邀请奖励：`{$inviteeRewardGB}G`（永久有效）\n";
      }
      
      $successMessage .= "\n现在可以使用以下功能：\n• /invite - 邀请好友得奖励\n• /checkin - 每日签到领流量\n• /traffic - 查看流量\n• /getlatesturl - 获取订阅链接";
    }
    
    $this->sendMessage($msg, $successMessage);
    
    // 如果有邀请人，通知邀请人
    if ($inviteRewards['inviter_notified']) {
      $this->notifyInviter($inviteRewards['inviter_tg_id'], $user->email, $inviteRewards['inviter_reward']);
    }
  }

  protected function extractTokenFromUrl(string $url): ?string
  {
    $parsedUrl = parse_url($url);

    // 支持新格式：https://your-site.com/s/token
    if (isset($parsedUrl['path'])) {
      $pathParts = explode('/', trim($parsedUrl['path'], '/'));
      if (count($pathParts) >= 2 && $pathParts[0] === 's') {
        return $pathParts[1];
      }
      // 兼容旧格式
      $lastPart = end($pathParts);
      if ($lastPart && $lastPart !== 's') {
        return $lastPart;
      }
    }

    // 兼容旧格式：?token=xxx
    if (isset($parsedUrl['query'])) {
      parse_str($parsedUrl['query'], $query);
      if (isset($query['token'])) {
        return $query['token'];
      }
    }

    return null;
  }

  public function handleTrafficCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    // 基础流量统计
    $transferUsed = $user->u + $user->d;
    $transferTotal = $user->transfer_enable;
    $transferRemaining = $transferTotal - $transferUsed;
    $usagePercentage = $transferTotal > 0 ? ($transferUsed / $transferTotal) * 100 : 0;

    // 获取流量来源统计
    $trafficSources = $this->getTrafficSources($user->id, $msg->chat_id);
    
    // 构建消息
    $text = "📊 *流量使用情况*\n\n";
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "📈 *总体统计*\n";
    $text .= "已用流量：`{$this->transferToGBString($transferUsed)}G`\n";
    $text .= "总流量：`{$this->transferToGBString($transferTotal)}G`\n";
    $text .= "剩余流量：`{$this->transferToGBString($transferRemaining)}G`\n";
    $text .= "使用率：`" . sprintf("%.2f", $usagePercentage) . "%`\n\n";
    
    // 流量来源统计
    $text .= "━━━━━━━━━━━━━━━━━━━━\n";
    $text .= "💎 *流量来源统计*\n";
    
    if ($trafficSources['bind_reward'] > 0) {
      $bindRewardGB = $this->transferToGBString($trafficSources['bind_reward']);
      $text .= "🔗 绑定奖励：`{$bindRewardGB}G`\n";
    }
    
    if ($trafficSources['checkin_reward'] > 0) {
      $checkinRewardGB = $this->transferToGBString($trafficSources['checkin_reward']);
      $checkinDays = $user->telegram_checkin_days ?? 0;
      $checkinTotal = $user->telegram_checkin_total ?? 0;
      $text .= "📅 签到奖励：`{$checkinRewardGB}G`\n";
      $text .= "   ├─ 连续签到：{$checkinDays} 天\n";
      $text .= "   └─ 累计签到：{$checkinTotal} 次\n";
    }
    
    if ($trafficSources['invite_reward'] > 0) {
      $inviteRewardGB = $this->transferToGBString($trafficSources['invite_reward']);
      $inviteCount = $trafficSources['invite_count'];
      $text .= "👥 邀请奖励：`{$inviteRewardGB}G`\n";
      $text .= "   └─ 成功邀请：{$inviteCount} 人\n";
    }
    
    if ($trafficSources['other_traffic'] > 0) {
      $otherGB = $this->transferToGBString($trafficSources['other_traffic']);
      $text .= "📦 其他来源：`{$otherGB}G`\n";
    }
    
    // 如果没有任何奖励记录
    if ($trafficSources['total_rewards'] == 0) {
      $text .= "暂无奖励记录\n";
    }
    
    // 临时流量提示
    if ($trafficSources['temp_traffic'] > 0) {
      $tempGB = $this->transferToGBString($trafficSources['temp_traffic']);
      $text .= "\n⏰ 临时流量：`{$tempGB}G`\n";
      $text .= "   └─ 有效期内的流量\n";
    }
    
    $text .= "💡 *获取更多流量*\n";
    $text .= "• /checkin - 每日签到领流量\n";
    $text .= "• /invite - 邀请好友获奖励\n";

    // 如果流量不足（使用率超过 80%），添加购买按钮
    if ($usagePercentage > 80) {
        $appUrl = $this->getAppUrl();
        $buttons = [
            [
                ['text' => '💎 立即购买套餐', 'url' => rtrim($appUrl, '/') . '/#/plan']
            ]
        ];
        $text .= "\n\n⚠️ *流量即将用尽，建议及时续费*";
        $this->sendMessageWithButtons($msg, $text, $buttons);
    } else {
        $this->sendMessage($msg, $text);
    }
  }
  
  /**
   * 获取用户流量来源统计
   */
  protected function getTrafficSources(int $userId, int $telegramId): array
  {
    $sources = [
      'bind_reward' => 0,
      'checkin_reward' => 0,
      'invite_reward' => 0,
      'temp_traffic' => 0,
      'other_traffic' => 0,
      'total_rewards' => 0,
      'invite_count' => 0
    ];
    
    try {
      // 1. 统计临时流量（绑定和签到）
      if (DB::getSchemaBuilder()->hasTable('telegram_temp_traffic')) {
        $tempTraffic = DB::table('telegram_temp_traffic')
          ->where('user_id', $userId)
          ->where('expires_at', '>', time())
          ->get();
        
        foreach ($tempTraffic as $traffic) {
          $sources['temp_traffic'] += $traffic->traffic_amount;
          
          if ($traffic->traffic_type === 'bind_reward') {
            $sources['bind_reward'] += $traffic->traffic_amount;
          } elseif ($traffic->traffic_type === 'checkin_reward') {
            $sources['checkin_reward'] += $traffic->traffic_amount;
          }
        }
      }
      
      // 2. 统计邀请奖励（永久有效，从统计表获取）
      if (DB::getSchemaBuilder()->hasTable('telegram_invite_stats')) {
        $inviteStats = DB::table('telegram_invite_stats')
          ->where('telegram_id', $telegramId)
          ->first();
        
        if ($inviteStats) {
          $sources['invite_reward'] = $inviteStats->total_reward_traffic ?? 0;
          $sources['invite_count'] = $inviteStats->successful_invites ?? 0;
        }
      }
      
      // 3. 如果没有临时流量表，尝试从配置估算历史奖励
      if (!DB::getSchemaBuilder()->hasTable('telegram_temp_traffic')) {
        $user = User::find($userId);
        
        // 估算绑定奖励
        if ($user && $user->telegram_bind_rewarded) {
          $sources['bind_reward'] = $this->getConfig('bind_reward', 10737418240);
        }
        
        // 估算签到奖励
        if ($user && ($user->telegram_checkin_total ?? 0) > 0) {
          $baseReward = $this->getConfig('checkin_base_reward', 209715200);
          $sources['checkin_reward'] = $baseReward * ($user->telegram_checkin_total ?? 0);
        }
      }
      
      // 计算总奖励
      $sources['total_rewards'] = $sources['bind_reward'] + 
                                  $sources['checkin_reward'] + 
                                  $sources['invite_reward'];
      
      // 计算其他来源流量（可能是购买套餐或管理员手动添加的）
      $user = User::find($userId);
      if ($user) {
        $knownTraffic = $sources['bind_reward'] + 
                       $sources['checkin_reward'] + 
                       $sources['invite_reward'];
        
        $otherTraffic = $user->transfer_enable - $knownTraffic;
        $sources['other_traffic'] = max(0, $otherTraffic);
      }
      
    } catch (\Exception $e) {
      Log::error('获取流量来源统计失败', [
        'user_id' => $userId,
        'telegram_id' => $telegramId,
        'error' => $e->getMessage()
      ]);
    }
    
    return $sources;
  }

  public function handleGetLatestUrlCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    // 获取网站URL（多种方式尝试）
    $subscribeUrl = $this->getSubscribeUrl();
    $subscribeUrl = rtrim($subscribeUrl, '/') . '/s/' . $user->token;
    $text = sprintf("🔗 *您的订阅链接*\n\n`%s`\n\n⚠️ 请勿泄露此链接给他人", $subscribeUrl);

    $this->sendMessage($msg, $text);
  }
  
  /**
   * 获取网站URL
   */
  protected function getAppUrl(): string
  {
    // 方式1：从配置文件获取（如果配置了 subscribe_url）
    $subscribeUrl = $this->getConfig('subscribe_url');
    if ($subscribeUrl) {
      return $subscribeUrl;
    }
    
    // 方式2：从系统配置获取
    $systemUrl = config('v2board.subscribe_url');
    if ($systemUrl) {
      return $systemUrl;
    }
    
    // 方式3：从 app.url 获取（如果不是 localhost）
    $appUrl = config('app.url');
    if ($appUrl && !str_contains($appUrl, 'localhost') && !str_contains($appUrl, '127.0.0.1')) {
      return $appUrl;
    }
    
    // 方式4：从数据库配置获取
    try {
      $siteUrl = DB::table('v2_settings')->where('key', 'site_url')->value('value');
      if ($siteUrl) {
        return $siteUrl;
      }
    } catch (\Exception $e) {
      // 忽略数据库查询错误
    }
    
    // 方式5：使用默认值（从 app.url）
    return config('app.url', 'https://your-domain.com');
  }

  /**
   * 获取订阅域名（用于生成订阅链接）
   */
  protected function getSubscribeUrl(): string
  {
    // 优先从插件配置获取订阅域名
    $subscribeDomain = $this->getConfig('subscribe_domain');
    if ($subscribeDomain) {
      return $subscribeDomain;
    }
    
    // 如果没配置，降级使用网站域名
    return $this->getAppUrl();
  }

  public function handleUnbindCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $user->telegram_id = null;
    if (!$user->save()) {
      $this->sendMessage($msg, '解绑失败，请稍后重试');
      return;
    }

    $this->sendMessage($msg, '✅ 解绑成功！\n\n如需再次使用 Bot 功能，请使用 /bind 命令重新绑定\n\n⚠️ 注意：重新绑定不会再获得绑定奖励');
  }

  /**
   * 处理签到命令
   */
  public function handleCheckinCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    if (!$this->getConfig('enable_checkin', true)) {
      $this->sendMessage($msg, '签到功能暂未开启');
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    // 检查今日是否已签到
    $today = date('Y-m-d');
    $lastCheckinDate = $user->telegram_checkin_last_time ? date('Y-m-d', strtotime($user->telegram_checkin_last_time)) : null;

    if ($lastCheckinDate === $today) {
      $nextCheckinTime = date('Y-m-d H:i:s', strtotime($today . ' +1 day'));
      $this->sendMessage($msg, "⚠️ 您今天已经签到过了！\n\n下次签到时间：`{$nextCheckinTime}`\n当前连续签到：`{$user->telegram_checkin_days}` 天");
      return;
    }

    // 计算连续签到天数
    $yesterday = date('Y-m-d', strtotime('-1 day'));
    if ($lastCheckinDate === $yesterday) {
      $checkinDays = ($user->telegram_checkin_days ?? 0) + 1;
    } else {
      $checkinDays = 1;
    }

    // 计算奖励流量
    $trafficReward = $this->calculateCheckinReward($checkinDays);
    
    // 更新用户数据
    $user->transfer_enable += $trafficReward;
    $user->telegram_checkin_last_time = date('Y-m-d H:i:s');
    $user->telegram_checkin_days = $checkinDays;
    $user->telegram_checkin_total = ($user->telegram_checkin_total ?? 0) + 1;

    if (!$user->save()) {
      $this->sendMessage($msg, '签到失败，请稍后重试');
      return;
    }
    
    // 记录临时流量（签到流量，当月有效）
    $daysUntilEndOfMonth = date('t') - date('j') + 1;
    $this->addTempTraffic($user->id, $trafficReward, 'checkin_reward', $daysUntilEndOfMonth);

    // 记录签到日志
    Log::info('Telegram 用户签到', [
      'user_id' => $user->id,
      'email' => $user->email,
      'checkin_days' => $checkinDays,
      'reward_traffic' => $trafficReward
    ]);

    // 发送签到成功消息
    $rewardGB = $this->transferToGBString($trafficReward);
    $totalTransfer = $this->transferToGBString($user->transfer_enable);
    
    $message = "✅ *签到成功！*\n\n";
    $message .= "🎁 本次奖励：`{$rewardGB}G`（当月有效）\n";
    $message .= "📅 连续签到：`{$checkinDays}` 天\n";
    $message .= "📊 总流量：`{$totalTransfer}G`\n";
    $message .= "🏆 累计签到：`{$user->telegram_checkin_total}` 次\n\n";
    
    // 添加连续签到激励信息
    $bonusDays = $this->getConfig('checkin_bonus_days', 7);
    $monthlyBonusDays = $this->getConfig('checkin_monthly_bonus_days', 30);
    
    if ($checkinDays % $monthlyBonusDays === 0 && $this->getConfig('enable_checkin_monthly_bonus', true)) {
      $message .= "🎉🎉🎉 恭喜达成 {$checkinDays} 天全勤！获得超级大礼包！\n";
    } elseif ($checkinDays % $bonusDays === 0 && $this->getConfig('enable_checkin_bonus', true)) {
      $message .= "🎉 恭喜！连续签到满 {$checkinDays} 天，获得额外奖励！\n";
    } else {
      // 计算距离下一个奖励还需要多少天
      $daysToNext7 = $bonusDays - ($checkinDays % $bonusDays);
      $daysToNext30 = $monthlyBonusDays - ($checkinDays % $monthlyBonusDays);
      
      if ($daysToNext7 <= $daysToNext30) {
        $message .= "💡 再连续签到 {$daysToNext7} 天可获得 7 天奖励！\n";
      } else {
        $message .= "💡 再连续签到 {$daysToNext30} 天可获得 30 天全勤大奖！\n";
      }
    }

    $this->sendMessage($msg, $message);

    // 通知管理员（可选）
    if ($this->getConfig('enable_checkin_notify_admin', false)) {
      $adminMessage = sprintf(
        "📝 用户签到\n━━━━━━━━━━━━━━━━━━━━\n用户：`%s`\n奖励流量：`%sG`\n连续签到：`%d` 天\n累计签到：`%d` 次",
        $user->email,
        $rewardGB,
        $checkinDays,
        $user->telegram_checkin_total
      );
      $this->telegramService->sendMessageWithAdmin($adminMessage, true);
    }
  }

  /**
   * 查看签到状态
   */
  public function handleCheckinStatusCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    if (!$this->getConfig('enable_checkin', true)) {
      $this->sendMessage($msg, '签到功能暂未开启');
      return;
    }

    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    $today = date('Y-m-d');
    $lastCheckinDate = $user->telegram_checkin_last_time ? date('Y-m-d', strtotime($user->telegram_checkin_last_time)) : '从未签到';
    $canCheckinToday = $lastCheckinDate !== $today;
    $checkinDays = $user->telegram_checkin_days ?? 0;
    $totalCheckin = $user->telegram_checkin_total ?? 0;

    $message = "📊 *签到状态*\n\n";
    $message .= "📅 连续签到：`{$checkinDays}` 天\n";
    $message .= "🏆 累计签到：`{$totalCheckin}` 次\n";
    $message .= "🕐 上次签到：`{$lastCheckinDate}`\n";
    $message .= "✨ 今日状态：" . ($canCheckinToday ? "可以签到" : "已签到") . "\n\n";

    // 显示奖励规则
    $message .= "🎁 *签到奖励规则*\n";
    $baseReward = $this->getConfig('checkin_base_reward', 209715200);
    $baseRewardMB = $this->transferToGBString($baseReward);
    $message .= "• 每日签到：`{$baseRewardMB}G`（当月有效）\n";

    if ($this->getConfig('enable_checkin_bonus', true)) {
      $bonusDays = $this->getConfig('checkin_bonus_days', 7);
      $bonusReward = $this->getConfig('checkin_bonus_reward', 1073741824);
      $bonusRewardGB = $this->transferToGBString($bonusReward);
      $message .= "• 连续 {$bonusDays} 天奖励：`{$bonusRewardGB}G`\n";
    }
    
    if ($this->getConfig('enable_checkin_monthly_bonus', true)) {
      $monthlyBonusDays = $this->getConfig('checkin_monthly_bonus_days', 30);
      $monthlyBonusReward = $this->getConfig('checkin_monthly_bonus_reward', 3221225472);
      $monthlyBonusRewardGB = $this->transferToGBString($monthlyBonusReward);
      $message .= "• 连续 {$monthlyBonusDays} 天全勤奖励：`{$monthlyBonusRewardGB}G`\n";
    }

    if ($canCheckinToday) {
      $message .= "\n💡 使用 /checkin 命令立即签到！";
    } else {
      $nextCheckinTime = date('Y-m-d 00:00:00', strtotime($today . ' +1 day'));
      $message .= "\n⏰ 下次签到时间：`{$nextCheckinTime}`";
    }

    $this->sendMessage($msg, $message);
  }

  /**
   * 计算签到奖励流量（字节）
   */
  protected function calculateCheckinReward(int $checkinDays): int
  {
    // 基础奖励（字节）
    $baseReward = $this->getConfig('checkin_base_reward', 209715200); // 默认 200MB
    $totalReward = $baseReward;

    // 检查7天连续签到奖励
    if ($this->getConfig('enable_checkin_bonus', true)) {
      $bonusDays = $this->getConfig('checkin_bonus_days', 7);
      $bonusReward = $this->getConfig('checkin_bonus_reward', 1073741824); // 默认 1GB

      if ($checkinDays > 0 && $checkinDays % $bonusDays === 0) {
        $totalReward += $bonusReward;
      }
    }

    // 检查30天全勤奖励
    if ($this->getConfig('enable_checkin_monthly_bonus', true)) {
      $monthlyBonusDays = $this->getConfig('checkin_monthly_bonus_days', 30);
      $monthlyBonusReward = $this->getConfig('checkin_monthly_bonus_reward', 3221225472); // 默认 3GB

      if ($checkinDays > 0 && $checkinDays % $monthlyBonusDays === 0) {
        $totalReward += $monthlyBonusReward;
      }
    }

    return $totalReward;
  }

  public function handleTicketReply(object $msg, array $matches): void
  {
    $user = $this->getBoundUser($msg);
    if (!$user) {
      return;
    }

    if (!isset($matches[2]) || !is_numeric($matches[2])) {
      Log::warning('Telegram 工单回复正则未匹配到工单ID', ['matches' => $matches, 'msg' => $msg]);
      $this->sendMessage($msg, '未能识别工单ID，请直接回复工单提醒消息');
      return;
    }

    $ticketId = (int) $matches[2];
    $ticket = Ticket::where('id', $ticketId)->first();
    if (!$ticket) {
      $this->sendMessage($msg, '工单不存在');
      return;
    }

    $ticketService = new TicketService();
    $ticketService->replyByAdmin(
      $ticketId,
      $msg->text,
      $user->id
    );

    $this->sendMessage($msg, "✅ 工单 #{$ticketId} 回复成功");
  }

  /**
   * 添加 Bot 命令到命令列表
   */
  public function addBotCommands(array $commands): array
  {
    foreach ($this->commandConfigs as $command => $config) {
      $commands[] = [
        'command' => $command,
        'description' => $config['description']
      ];
    }

    return $commands;
  }

  private function transferToGBString(float $transfer_enable, int $decimals = 2): string
  {
    return number_format(Helper::transferToGB($transfer_enable), $decimals, '.', '');
  }

  /**
   * 添加临时流量记录
   */
  protected function addTempTraffic(int $userId, int $trafficAmount, string $trafficType, int $validDays): void
  {
    try {
      $expiresAt = strtotime("+{$validDays} days");
      
      DB::table('telegram_temp_traffic')->insert([
        'user_id' => $userId,
        'traffic_amount' => $trafficAmount,
        'traffic_type' => $trafficType,
        'expires_at' => $expiresAt,
        'created_at' => date('Y-m-d H:i:s')
      ]);
    } catch (\Exception $e) {
      Log::error('添加临时流量记录失败', [
        'user_id' => $userId,
        'traffic_amount' => $trafficAmount,
        'traffic_type' => $trafficType,
        'error' => $e->getMessage()
      ]);
    }
  }
  
  /**
   * 隐藏邮箱中间部分
   */
  protected function maskEmail(string $email): string
  {
    $parts = explode('@', $email);
    if (count($parts) !== 2) {
      return $email;
    }
    
    $username = $parts[0];
    $domain = $parts[1];
    
    $usernameLength = strlen($username);
    if ($usernameLength <= 2) {
      return $username[0] . '***@' . $domain;
    }
    
    $visibleChars = min(2, floor($usernameLength / 3));
    $maskedUsername = substr($username, 0, $visibleChars) . '***' . substr($username, -$visibleChars);
    
    return $maskedUsername . '@' . $domain;
  }

  // ==================== 邀请功能方法 ====================

  /**
   * 处理被邀请用户
   */
  protected function handleInvitedUser(object $msg, string $inviteCode): void
  {
    try {
      $inviteeTgId = $msg->chat_id;
      $firstname = $msg->from->first_name ?? '用户';

      // 验证邀请码并获取邀请人
      $inviter = null;
      
      // 检查telegram_invite_code字段是否存在
      if (DB::getSchemaBuilder()->hasColumn('v2_user', 'telegram_invite_code')) {
        $inviter = User::where('telegram_invite_code', $inviteCode)->first();
      }
      
      // 如果没有找到，尝试从邀请码中提取telegram_id（兼容模式）
      if (!$inviter) {
        // 邀请码格式：telegram_id + 8位随机字符
        if (strlen($inviteCode) > 8) {
          $telegramId = substr($inviteCode, 0, -8);
          if (is_numeric($telegramId)) {
            $inviter = User::where('telegram_id', $telegramId)->first();
          }
        }
      }
      
      if (!$inviter || !$inviter->telegram_id) {
        $this->sendMessage($msg, "❌ 邀请码无效\n\n{$firstname}，请让你的好友重新发送邀请链接");
        return;
      }

      $inviterTgId = $inviter->telegram_id;

      // 检查是否自己邀请自己
      if ($inviterTgId == $inviteeTgId) {
        $this->sendMessage($msg, "❌ 不能使用自己的邀请链接");
        return;
      }

      // 检查是否已经绑定
      $user = User::where('telegram_id', $inviteeTgId)->first();
      if ($user) {
        $this->sendMessage($msg, "✅ {$firstname}，你已经绑定过账号了\n\n输入 /mystats 查看你的信息");
        return;
      }
      
      // 检查是否已经使用过这个邀请码
      $existingInvite = DB::table('telegram_invite_logs')
        ->where('inviter_telegram_id', $inviterTgId)
        ->where('invitee_telegram_id', $inviteeTgId)
        ->where('status', '!=', 'pending')
        ->exists();
      
      if ($existingInvite) {
        $this->sendMessage($msg, "⚠️ {$firstname}，你已经使用过这个邀请码了");
        return;
      }

      // 创建邀请记录
      DB::table('telegram_invite_logs')->insert([
        'inviter_telegram_id' => $inviterTgId,
        'invitee_telegram_id' => $inviteeTgId,
        'invite_code' => $inviteCode,
        'status' => 'bound',
        'bound_at' => date('Y-m-d H:i:s'),
        'created_at' => date('Y-m-d H:i:s')
      ]);

      // 保存邀请码到缓存（用于绑定时发放奖励）
      Cache::put("telegram_invite_code_{$inviteeTgId}", $inviteCode, 3600);

      // 获取网站注册链接
      $appUrl = $this->getAppUrl();
      $registerUrl = rtrim($appUrl, '/') . '/#/register';
      
      // 获取奖励配置（邀请人10GB，被邀请人2GB）
      $bindReward = $this->getConfig('bind_reward', 6442450944); // 6GB
      $bindRewardGB = $this->transferToGBString($bindReward);
      
      $inviteeReward = $this->getConfig('invite_invitee_reward', 2147483648); // 被邀请人 2GB ✅
      $inviteeRewardGB = $this->transferToGBString($inviteeReward);
      
      $inviterReward = $this->getConfig('invite_inviter_reward', 10737418240); // 邀请人 10GB ✅
      $inviterRewardGB = $this->transferToGBString($inviterReward);
      
      // 构建欢迎消息（从被邀请人视角）
      $message = "🎉 {$firstname}，欢迎通过好友邀请加入！\n\n";
      $message .= "📋 完成以下步骤可获得流量奖励：\n\n";
      
      $message .= "1️⃣ 点击下方链接注册账号\n";
      $message .= "   → 注册即送流量（网站奖励 2.00G 流量）\n\n";
      
      $message .= "2️⃣ 注册成功后，输入 /bind + 订阅链接\n";
      $message .= "   → 绑定即送 {$bindRewardGB}G 流量（30天有效）\n\n";
      
      $message .= "3️⃣ 绑定成功后双方都得到邀请奖励\n";
      $message .= "   → 你(被邀请人)获得 {$inviteeRewardGB}G 流量（永久有效）\n";  // 被邀请人2GB
      $message .= "   → 好友(邀请人)获得 {$inviterRewardGB}G 流量（永久有效）\n\n";  // 邀请人10GB
      
      $message .= "🌐 注册链接：\n";
      $message .= $registerUrl . "\n\n";
      
      $message .= "━━━━━━━━━━━━━━━━━━━━\n";
      $message .= "🎁 你将获得的奖励（完成所有步骤）：\n";
      $message .= "├─ 注册奖励：网站自动发放\n";
      $message .= "├─ 绑定奖励：{$bindRewardGB}G（30天有效）\n";
      $message .= "└─ 被邀请奖励：{$inviteeRewardGB}G（永久有效）\n\n";
      
      $message .= "⚠️ 注意：一个邮箱只能绑定一个 TG 账号\n\n";
      $message .= "💡 输入 /bind 开始绑定";

      $this->sendMessage($msg, $message, '');
    } catch (\Exception $e) {
      Log::error('处理被邀请用户失败', [
        'chat_id' => $msg->chat_id ?? 'unknown',
        'invite_code' => $inviteCode ?? 'unknown',
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
      ]);
      $this->sendMessage($msg, '处理失败，请稍后重试');
    }
  }

  /**
   * 处理邀请奖励
   */
  protected function processInviteReward(int $inviteeTgId, User $invitee): array
  {
    $result = [
      'invitee_reward' => 0,
      'inviter_reward' => 0,
      'inviter_tg_id' => null,
      'inviter_notified' => false
    ];

    try {
      if (!$this->getConfig('enable_invite', true)) {
        return $result;
      }

      // 检查是否有待处理的邀请
      $inviteCode = Cache::get("telegram_invite_code_{$inviteeTgId}");
      if (!$inviteCode) {
        return $result;
      }

      $invitation = DB::table('telegram_invite_logs')
        ->where('invitee_telegram_id', $inviteeTgId)
        ->where('status', 'bound')
        ->first();

      if (!$invitation) {
        return $result;
      }

      // 获取邀请人
      $inviter = User::where('telegram_id', $invitation->inviter_telegram_id)->first();
      if (!$inviter) {
        return $result;
      }

      // 计算奖励
      $inviteeReward = $this->getConfig('invite_invitee_reward', 2147483648); // 2GB
      $inviterReward = $this->getConfig('invite_inviter_reward', 10737418240); // 10GB

      // 发放被邀请人奖励
      $invitee->transfer_enable += $inviteeReward;
      
      // 发放邀请人奖励（永久有效）
      $inviter->transfer_enable += $inviterReward;
      $inviter->save();

      // 更新邀请记录
      DB::table('telegram_invite_logs')
        ->where('id', $invitation->id)
        ->update([
          'status' => 'rewarded',
          'rewarded_at' => date('Y-m-d H:i:s'),
          'invitee_email' => $invitee->email,
          'inviter_reward' => $inviterReward,
          'invitee_reward' => $inviteeReward
        ]);

      // 更新邀请统计
      $this->updateInviteStats($invitation->inviter_telegram_id, $inviterReward);

      // 检查里程碑
      $this->checkMilestone($invitation->inviter_telegram_id);

      // 清除缓存
      Cache::forget("telegram_invite_code_{$inviteeTgId}");

      // 记录日志
      Log::info('Telegram 邀请奖励发放', [
        'inviter_tg_id' => $invitation->inviter_telegram_id,
        'inviter_email' => $inviter->email,
        'inviter_reward' => $inviterReward,
        'invitee_tg_id' => $inviteeTgId,
        'invitee_email' => $invitee->email,
        'invitee_reward' => $inviteeReward
      ]);

      $result = [
        'invitee_reward' => $inviteeReward,
        'inviter_reward' => $inviterReward,
        'inviter_tg_id' => $invitation->inviter_telegram_id,
        'inviter_notified' => true
      ];
    } catch (\Exception $e) {
      Log::error('处理邀请奖励失败', [
        'invitee_tg_id' => $inviteeTgId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
    }

    return $result;
  }

  /**
   * 通知邀请人
   */
  protected function notifyInviter(int $inviterTgId, string $inviteeEmail, int $reward): void
  {
    try {
      $rewardGB = $this->transferToGBString($reward);
      $maskedEmail = $this->maskEmail($inviteeEmail);
      $message = "🎉 好友绑定成功！\n\n";
      $message .= "好友邮箱：{$maskedEmail}\n";
      $message .= "🎁 你获得奖励：{$rewardGB}G（永久有效）\n\n";
      $message .= "💡 继续邀请好友获得更多流量\n";
      $message .= "输入 /invite 获取邀请链接";

      $this->telegramService->sendMessage($inviterTgId, $message);
    } catch (\Exception $e) {
      Log::error('通知邀请人失败', [
        'inviter_tg_id' => $inviterTgId,
        'error' => $e->getMessage()
      ]);
    }
  }

  /**
   * 更新邀请统计
   */
  protected function updateInviteStats(int $telegramId, int $rewardTraffic): void
  {
    try {
      DB::table('telegram_invite_stats')->updateOrInsert(
        ['telegram_id' => $telegramId],
        [
          'successful_invites' => DB::raw('successful_invites + 1'),
          'total_reward_traffic' => DB::raw("total_reward_traffic + {$rewardTraffic}"),
          'updated_at' => date('Y-m-d H:i:s')
        ]
      );
    } catch (\Exception $e) {
      Log::error('更新邀请统计失败', [
        'telegram_id' => $telegramId,
        'error' => $e->getMessage()
      ]);
    }
  }

  /**
   * 检查里程碑
   */
  protected function checkMilestone(int $telegramId): void
  {
    try {
      if (!$this->getConfig('enable_invite_milestone', true)) {
        return;
      }

      $stats = $this->getInviteStats($telegramId);
      $successfulInvites = $stats['successful_invites'];
      
      // 定义里程碑
      $milestones = [
        3 => ['reward' => 16106127360, 'field' => 'milestone_3_claimed', 'name' => '3人'],  // 15GB
        5 => ['reward' => 26843545600, 'field' => 'milestone_5_claimed', 'name' => '5人'],  // 25GB
        10 => ['reward' => 53687091200, 'field' => 'milestone_10_claimed', 'name' => '10人'], // 50GB
        15 => ['reward' => 75161927680, 'field' => 'milestone_15_claimed', 'name' => '15人'], // 70GB
        20 => ['reward' => 107374182400, 'field' => 'milestone_20_claimed', 'name' => '20人'] // 100GB
      ];
      
      foreach ($milestones as $count => $milestone) {
        // 检查是否达到里程碑且未领取
        if ($successfulInvites >= $count && !$stats[$milestone['field']]) {
          // 发放里程碑奖励
          $user = User::where('telegram_id', $telegramId)->first();
          if ($user) {
            $user->transfer_enable += $milestone['reward'];
            $user->save();
            
            // 标记为已领取
            DB::table('telegram_invite_stats')
              ->where('telegram_id', $telegramId)
              ->update([
                $milestone['field'] => 1,
                'total_reward_traffic' => DB::raw("total_reward_traffic + {$milestone['reward']}")
              ]);
            
            // 通知用户
            $rewardGB = $this->transferToGBString($milestone['reward']);
            $emoji = $count >= 15 ? ($count >= 20 ? '👑' : '💎') : '🎁';
            $message = "🎉🎉🎉 恭喜达成 {$milestone['name']} 邀请里程碑！{$emoji}\n\n";
            $message .= "🎁 里程碑奖励：{$rewardGB}G（永久有效）\n\n";
            $message .= "💡 继续邀请获得更多奖励！";
            
            $this->telegramService->sendMessage($telegramId, $message);
            
            Log::info('Telegram 里程碑奖励发放', [
              'telegram_id' => $telegramId,
              'milestone' => $milestone['name'],
              'reward' => $milestone['reward']
            ]);
          }
          
          // 只发放一个里程碑，避免一次性发放多个
          break;
        }
      }
    } catch (\Exception $e) {
      Log::error('检查里程碑失败', [
        'telegram_id' => $telegramId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
    }
  }

  /**
   * /invite 命令 - 生成永久邀请链接
   */
  public function handleInviteCommand(object $msg): void
  {
    try {
      if (!$this->checkPrivateChat($msg)) {
        return;
      }

      if (!$this->getConfig('enable_invite', true)) {
        $this->sendMessage($msg, '邀请功能暂未开启');
        return;
      }

      $user = $this->getBoundUser($msg);
      if (!$user) {
        return;
      }

      // 获取或生成永久邀请码
      $inviteCode = null;
      
      // 检查telegram_invite_code字段是否存在
      if (DB::getSchemaBuilder()->hasColumn('v2_user', 'telegram_invite_code')) {
        if (empty($user->telegram_invite_code)) {
          $user->telegram_invite_code = $this->generatePermanentInviteCode($msg->chat_id);
          $user->save();
        }
        $inviteCode = $user->telegram_invite_code;
      } else {
        // 如果字段不存在，使用telegram_id作为邀请码
        $inviteCode = $this->generatePermanentInviteCode($msg->chat_id);
      }
      
      $botUsername = $this->getConfig('bot_username', 'YourBotUsername');
      
      // 检查bot_username是否配置
      if ($botUsername === 'YourBotUsername') {
        $this->sendMessage($msg, '❌ Bot用户名未配置，请联系管理员在插件配置中设置 bot_username');
        return;
      }
      
      $inviteLink = "https://t.me/{$botUsername}?start=inv_{$inviteCode}";

      // 获取统计
      $stats = $this->getInviteStats($msg->chat_id);
      
      // 计算下一个里程碑
      $milestones = [3, 5, 10, 15, 20];
      $nextMilestone = null;
      $remaining = 0;
      
      foreach ($milestones as $milestone) {
        $field = "milestone_{$milestone}_claimed";
        if (!$stats[$field]) {
          $nextMilestone = $milestone;
          $remaining = max(0, $milestone - $stats['successful_invites']);
          break;
        }
      }

      $inviterReward = $this->transferToGBString($this->getConfig('invite_inviter_reward', 10737418240));
      $inviteeReward = $this->transferToGBString($this->getConfig('invite_invitee_reward', 2147483648));

      // 使用纯文本，不使用Markdown避免下划线转义
      $message = "🎁 穿云VPN，邀请好友，双方都得流量！\n\n";
      $message .= "📨 你的专属邀请链接：\n";
      $message .= $inviteLink . "\n\n";
      $message .= "（长按复制链接，永久有效）\n\n";
      $message .= "📋 邀请规则：\n";
      $message .= "1️⃣ 好友通过链接进入 Bot\n";
      $message .= "2️⃣ 好友注册并绑定账号\n";
      $message .= "3️⃣ 你获得 {$inviterReward}G，好友获得 {$inviteeReward}G\n\n";
      $message .= "💰 里程碑奖励（永久有效）：\n";
      $message .= "├─ 3人：+15GB 🎁\n";
      $message .= "├─ 5人：+25GB 🎁\n";
      $message .= "├─ 10人：+50GB 🎁\n";
      $message .= "├─ 15人：+70GB 💎\n";
      $message .= "└─ 20人：+100GB 👑\n\n";
      $message .= "📊 当前统计：\n";
      $message .= "├─ 已邀请：{$stats['successful_invites']} 人\n";
      $message .= "├─ 累计获得：" . $this->transferToGBString($stats['total_reward_traffic']) . "G\n";
      
      if ($nextMilestone) {
        $message .= "└─ 距离{$nextMilestone}人里程碑：还差 {$remaining} 人\n\n";
      } else {
        $message .= "└─ 🎉 已完成所有里程碑！\n\n";
      }
      
      $message .= "💡 分享链接给好友即可开始！";

      $this->sendMessage($msg, $message, '');
    } catch (\Exception $e) {
      Log::error('处理邀请命令失败', [
        'chat_id' => $msg->chat_id ?? 'unknown',
        'user_id' => $user->id ?? 'unknown',
        'error' => $e->getMessage(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
      ]);
      $this->sendMessage($msg, "获取邀请链接失败\n\n错误信息：" . $e->getMessage() . "\n请联系管理员");
    }
  }

  /**
   * /mystats 命令
   */
  public function handleMyStatsCommand(object $msg): void
  {
    try {
      if (!$this->checkPrivateChat($msg)) {
        return;
      }

      if (!$this->getConfig('enable_invite', true)) {
        $this->sendMessage($msg, '邀请功能暂未开启');
        return;
      }

      $user = $this->getBoundUser($msg);
      if (!$user) {
        return;
      }

      // 获取邀请统计
      $stats = $this->getInviteStats($msg->chat_id);
      
      // 获取最近的邀请记录
      $recentInvites = DB::table('telegram_invite_logs')
        ->where('inviter_telegram_id', $msg->chat_id)
        ->where('status', 'rewarded')
        ->orderBy('rewarded_at', 'desc')
        ->limit(5)
        ->get();

      // 检查里程碑状态
      $milestones = [
        3 => ['field' => 'milestone_3_claimed', 'reward' => '15GB'],
        5 => ['field' => 'milestone_5_claimed', 'reward' => '25GB'],
        10 => ['field' => 'milestone_10_claimed', 'reward' => '50GB'],
        15 => ['field' => 'milestone_15_claimed', 'reward' => '70GB'],
        20 => ['field' => 'milestone_20_claimed', 'reward' => '100GB']
      ];
      
      $milestoneStatus = [];
      foreach ($milestones as $count => $milestone) {
        if ($stats[$milestone['field']]) {
          $milestoneStatus[] = "{$count}人({$milestone['reward']})✅";
        } else if ($stats['successful_invites'] >= $count) {
          $milestoneStatus[] = "{$count}人({$milestone['reward']})🎁待领取";
        } else {
          $remaining = $count - $stats['successful_invites'];
          $milestoneStatus[] = "{$count}人({$milestone['reward']})还差{$remaining}人";
        }
      }

      $message = "📊 邀请统计\n\n";
      $message .= "👤 账号：{$user->email}\n";
      $message .= "🆔 TG ID：{$msg->chat_id}\n\n";
      $message .= "🎁 邀请数据：\n";
      $message .= "├─ 成功邀请：{$stats['successful_invites']} 人\n";
      $message .= "└─ 累计获得：" . $this->transferToGBString($stats['total_reward_traffic']) . "G\n\n";
      $message .= "🏆 里程碑进度：\n";
      foreach ($milestoneStatus as $status) {
        $message .= "├─ {$status}\n";
      }
      $message .= "\n";

      if ($recentInvites->isNotEmpty()) {
        $message .= "📜 最近邀请记录：\n";
        foreach ($recentInvites as $invite) {
          $date = date('m-d H:i', strtotime($invite->rewarded_at));
          $reward = $this->transferToGBString($invite->inviter_reward);
          $email = $invite->invitee_email ? $this->maskEmail($invite->invitee_email) : '未知';
          $message .= "  • {$date} | +{$reward}G | {$email}\n";
        }
      } else {
        $message .= "📜 暂无邀请记录\n";
      }

      $message .= "\n💡 输入 /invite 获取邀请链接";

      $this->sendMessage($msg, $message, '');
    } catch (\Exception $e) {
      Log::error('处理统计命令失败', [
        'chat_id' => $msg->chat_id ?? 'unknown',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
      ]);
      $this->sendMessage($msg, '获取统计信息失败，请稍后重试');
    }
  }

  /**
   * 生成永久邀请码
   */
  protected function generatePermanentInviteCode(int $telegramId): string
  {
    // 使用 telegram_id + 随机字符串生成唯一邀请码
    $random = bin2hex(random_bytes(4));
    return "{$telegramId}{$random}";
  }

  /**
   * 获取邀请统计
   */
  protected function getInviteStats(int $telegramId): array
  {
    try {
      $stats = DB::table('telegram_invite_stats')
        ->where('telegram_id', $telegramId)
        ->first();

      if (!$stats) {
        return [
          'total_invites' => 0,
          'successful_invites' => 0,
          'total_reward_traffic' => 0,
          'milestone_3_claimed' => false,
          'milestone_5_claimed' => false,
          'milestone_10_claimed' => false,
          'milestone_15_claimed' => false,
          'milestone_20_claimed' => false
        ];
      }

      return [
        'total_invites' => $stats->total_invites ?? 0,
        'successful_invites' => $stats->successful_invites ?? 0,
        'total_reward_traffic' => $stats->total_reward_traffic ?? 0,
        'milestone_3_claimed' => $stats->milestone_3_claimed ?? false,
        'milestone_5_claimed' => $stats->milestone_5_claimed ?? false,
        'milestone_10_claimed' => $stats->milestone_10_claimed ?? false,
        'milestone_15_claimed' => $stats->milestone_15_claimed ?? false,
        'milestone_20_claimed' => $stats->milestone_20_claimed ?? false
      ];
    } catch (\Exception $e) {
      Log::error('获取邀请统计失败', [
        'telegram_id' => $telegramId,
        'error' => $e->getMessage()
      ]);
      
      return [
        'total_invites' => 0,
        'successful_invites' => 0,
        'total_reward_traffic' => 0,
        'milestone_3_claimed' => false,
        'milestone_5_claimed' => false,
        'milestone_10_claimed' => false,
        'milestone_15_claimed' => false,
        'milestone_20_claimed' => false
      ];
    }
  }
  /**
   * 处理菜单命令
   */
  public function handleMenuCommand(object $msg): void
  {
    if (!$this->checkPrivateChat($msg)) {
      return;
    }

    $appUrl = $this->getAppUrl();
    $user = User::where('telegram_id', $msg->chat_id)->first();

    $message = "📱 *快捷菜单*\n\n";
    $message .= "点击下方按钮快速访问：";

    // 构建按钮
    $buttons = [];
    
    if (!$user) {
      // 未绑定用户 - 显示登录和注册
      $buttons[] = [
        ['text' => '🔐 登录账号', 'url' => rtrim($appUrl, '/') . '/#/login'],
        ['text' => '📝 注册账号', 'url' => rtrim($appUrl, '/') . '/#/register']
      ];
      $buttons[] = [
        ['text' => '💎 查看套餐', 'url' => rtrim($appUrl, '/') . '/#/plan']
      ];
    } else {
      // 已绑定用户 - 显示完整菜单
      $buttons[] = [
        ['text' => '🔐 登录网站', 'url' => rtrim($appUrl, '/') . '/#/login'],
        ['text' => '💎 购买套餐', 'url' => rtrim($appUrl, '/') . '/#/plan']
      ];
      $buttons[] = [
        ['text' => '📊 个人中心', 'url' => rtrim($appUrl, '/') . '/#/dashboard']
      ];
    }

    $this->sendMessageWithButtons($msg, $message, $buttons);
  }
}
