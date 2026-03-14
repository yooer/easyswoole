<?php
namespace App\Utility;

use EasySwooleLib\Logger\Log;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use EasySwoole\EasySwoole\Config;

/**
 * 邮件发送工具类
 */
class SendMail
{
    /**
     * @var PHPMailer PHPMailer实例
     */
    protected $mailer;
    
    /**
     * @var string 发件人邮箱
     */
    protected $from;
    
    /**
     * @var string|false 收件人邮箱
     */
    protected $to;
    
    /**
     * @var string|false 邮件主题
     */
    protected $subject;
    
    /**
     * @var string|false 邮件内容
     */
    protected $body;

    /**
     * 构造函数
     * 
     * @param array $data 包含邮件信息的数组
     *               ['to' => 收件人, 'subject' => 主题, 'body' => 内容]
     */
    public function __construct(array $data)
    {
        try {
            $config = Config::getInstance()->getConf('smtp');
            
            $this->mailer = new PHPMailer(true);
            $this->mailer->isSMTP();
            $this->mailer->Host       = $config['host'];
            $this->mailer->SMTPAuth   = true;
            $this->mailer->Username   = $config['user'];
            $this->mailer->Password   = $config['password'];
            $this->mailer->SMTPSecure = $config['security'] ?? 'ssl';
            $this->mailer->Port       = $config['port'] ?? 465;
            $this->mailer->CharSet    = $config['charset'] ?? 'UTF-8';

            $this->from = $config['user'];

            // 提取邮件信息
            $this->to = $data["to"] ?? false;
            $this->subject = $data["subject"] ?? false;
            $this->body = $data["body"] ?? false;

        } catch (Exception $e) {
            Log::error("邮件服务初始化失败: " . $e->getMessage());
            $this->mailer = false;
        }
    }
    
    /**
     * 验证必要参数
     * 
     * @return bool 是否所有必要参数都有效
     */
    protected function validateParams(): bool
    {
        return $this->mailer && $this->to && $this->subject && $this->body;
    }

    /**
     * 发送邮件
     * 
     * @return bool 是否发送成功
     */
    public function send(): bool
    {
        if (!$this->validateParams()) {
            Log::error("邮件服务未初始化或参数无效");
            return false;
        }
        
        try {
            $this->mailer->setFrom($this->from, 'noreply');
            $this->mailer->addAddress($this->to);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $this->subject;
            $this->mailer->Body = $this->body;
            $this->mailer->AltBody = strip_tags($this->body);
            
            $this->mailer->send();
            
            Log::info("邮件发送成功: To: {$this->to}");
            return true;
        } catch (Exception $e) {
            Log::error("邮件发送失败: From: {$this->from} To: {$this->to} Error: {$this->mailer->ErrorInfo}");
            return false;
        }
    }
}