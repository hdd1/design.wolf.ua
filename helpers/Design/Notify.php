<?php

namespace Design;

/** Сповіщення через api2 (sms/add): повідомлення на сайті wolf.ua і SMS */
final class Notify
{
    private const CONNECT_TIMEOUT = 3;
    private const TIMEOUT = 5;

    /** @var list<array> що «відправлено» при DESIGN_NOTIFY=off (тести); у бойовому режимі не наповнюється */
    public static array $sent = [];

    /** @var (callable(array): mixed)|null замість справжнього запиту до api2 (тести); отримує params, повертає розкодовану відповідь */
    public static $transport = null;

    /**
     * Збій api2 не ламає дію користувача: пишемо в error_log і йдемо далі.
     * @param array $params customer_id або user_id, msg, msgSMS
     */
    public static function send(array $params): void
    {
        if (getenv('DESIGN_NOTIFY') === 'off') {
            self::$sent[] = $params;
            return;
        }
        try {
            $result = self::$transport !== null ? (self::$transport)($params) : self::post($params);
        } catch (\Throwable $e) {
            self::fail($params, $e->getMessage());
            return;
        }
        // CalcApiGlobal не кидає виняток: збій curl — false, сторінка помилки — рядок, відмова api2 — ['error' => true, ...]
        if (!is_array($result) || !empty($result['error'])) {
            self::fail($params, is_string($result) ? $result : (is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : ''));
        }
    }

    /** Той самий запит, що CalcApiGlobal::post, але з таймаутами: api2 завис би — не повісимо php-fpm */
    private static function post(array $params)
    {
        $params['request_ip'] = \CalcApiGlobal::getIP();
        $url = 'https://' . (Config::isDev() ? 'dev.' : '') . 'api2.wolf.ua/api/sms/add';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'authorization: ' . \CalcApiGlobal::globalToken()]);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params, JSON_NUMERIC_CHECK));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        if (Config::isDev()) {
            // як у CalcApiGlobal: на dev сертифікат api2 не перевіряється
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $raw = curl_exec($ch);
        curl_close($ch);

        if ($raw === false) return false;
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : (string)$raw;
    }

    private static function fail(array $params, string $reason): void
    {
        $to = isset($params['customer_id']) ? 'customer_id=' . $params['customer_id'] : 'user_id=' . ($params['user_id'] ?? '?');
        error_log('Design\\Notify: api2 sms/add не прийняв повідомлення (' . $to . '): ' . ($reason !== '' ? $reason : 'порожня відповідь або збій звʼязку'));
    }
}
