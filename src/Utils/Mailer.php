<?php

namespace OpenCore\Utils;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class Mailer {
    
    public static $MAX_LOG_SIZE = 16;
    
    public static function sendTextMail($to, string $subject, string $body) {
        self::genericSendMail($to, $subject, $body, false);
    }
    
    public static function sendHtmlMail($to, string $subject, string $body) {
        self::genericSendMail($to, $subject, $body, true);
    }
    
    private static function genericSendMail($to, string $subject, string $body, bool $isHtmlTemplate) {
        switch (getenv('EMAIL_METHOD')) {
            case 'STMP': return self::sendUsingSmtp($to, $subject, $body, $isHtmlTemplate);
            case 'LOG': return self::sendUsingLog($to, $subject, $body, $isHtmlTemplate);
        }
    }

    public static function getMailLog() {
        $ret = null;
        $filename = self::mailLogFileName();
        if (file_exists($filename)) {
            $handle = fopen($filename, 'r');
            flock($handle, LOCK_SH);

            $size = filesize($filename);
        
            $ret = $size ? json_decode(fread($handle, $size), true) : [];

            flock($handle, LOCK_UN);
            fclose($handle);
        }
        return $ret ? $ret : [];
    }

    private static function mailLogFileName() {
        return APP_ROOT . '/logs/mail.log';
    }
    
    private static function makeAltBody(string $htmlBody){
        return strip_tags($htmlBody, '<a>');
    }
    
    private static function sendUsingLog($to, string $subject, string $body, bool $isHtmlTemplate) {
        
        $filename = self::mailLogFileName();
        $handle = fopen($filename, 'c+');
        flock($handle, LOCK_EX);

        $size = filesize($filename);
        $data = $size ? json_decode(fread($handle, $size), true) : [];

        $data[] = [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'alt' => $isHtmlTemplate ? self::makeAltBody($body) : null,
            'time' => time(),
        ];

        if (count($data) > self::$MAX_LOG_SIZE) {
            array_shift($data);
        }

        ftruncate($handle, 0);
        fseek($handle, 0);
        fwrite($handle, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        clearstatcache(true, $filename);
        
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    private static function sendUsingSmtp($to, string $subject, string $body, bool $isHtmlTemplate) {

        $mail = new PHPMailer(true);
        try {

            $mail->SMTPDebug = 0;
//            $mail->SMTPDebug = 4;
            $mail->isSMTP();
            $mail->Host = getenv('SMTP_HOST');
            $mail->CharSet = 'UTF-8';
            $mail->SMTPAuth = true;
            $mail->Username = getenv('SMTP_USER');
            $mail->Password = getenv('SMTP_PASS');
            $mail->SMTPSecure = 'tls';
            $mail->Port = (int) getenv('SMTP_PORT');
            //Recipients
            $mail->setFrom(getenv('EMAIL_FROM_ADDRESS'), getenv('EMAIL_FROM_NAME'));
            $mail->addReplyTo(getenv('EMAIL_REPLY_ADDRESS'), getenv('EMAIL_REPLY_NAME'));

            foreach ((array) $to as $toItem) {
                $mail->addAddress($toItem);
            }

            $mail->isHTML($isHtmlTemplate);
            $mail->Subject = $subject;
            $mail->Body = $body;
            if($isHtmlTemplate){
                $mail->AltBody = self::makeAltBody($body);
            }
            
            $mail->send();
        } catch (Exception $e) {
            throw new MailerException('Mailer Error (' . $e->getMessage() . '): ' . $mail->ErrorInfo);
        }
    }

}
