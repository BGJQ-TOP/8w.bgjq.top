<?php

namespace W8\Passport\Support;

/**
 * 字符串 / 随机数 / 哈希工具
 */
final class Str
{
    /**
     * 生成密码学安全的随机十六进制串
     */
    public static function randomHex($bytes = 32)
    {
        return bin2hex(self::randomBytes($bytes));
    }

    /**
     * 生成 URL 安全的随机串（base64url，无填充）
     */
    public static function randomUrlSafe($bytes = 32)
    {
        return rtrim(strtr(base64_encode(self::randomBytes($bytes)), '+/', '-_'), '=');
    }

    /**
     * 生成纯数字验证码（邮箱/简幻通验证码）
     */
    public static function numericCode($length = 6)
    {
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }
        return $code;
    }

    /**
     * 不可逆哈希，用于令牌/验证码落库
     */
    public static function hash($value)
    {
        return hash('sha256', (string) $value);
    }

    /**
     * 恒定时间比较，防时序侧信道
     */
    public static function equals($known, $given)
    {
        if (!is_string($known) || !is_string($given)) {
            return false;
        }
        return hash_equals($known, $given);
    }

    /**
     * 邮箱脱敏，用于对外展示
     */
    public static function maskEmail($email)
    {
        $email = (string) $email;
        $at = strpos($email, '@');
        if ($at === false || $at < 1) {
            return $email;
        }
        $name = substr($email, 0, $at);
        $domain = substr($email, $at);
        $keep = min(2, strlen($name));
        return substr($name, 0, $keep) . str_repeat('*', max(1, strlen($name) - $keep)) . $domain;
    }

    private static function randomBytes($bytes)
    {
        $bytes = max(1, (int) $bytes);
        try {
            return random_bytes($bytes);
        } catch (\Exception $e) {
            // 极端环境下 random_bytes 不可用时的兜底
            return openssl_random_pseudo_bytes($bytes);
        }
    }
}
