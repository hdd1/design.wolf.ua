<?php

namespace Design;

/** Сповіщення через спільну систему api2 (sms/add, CalcApiGlobal): повідомлення на сайті wolf.ua і SMS */
final class Notify
{
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
            $result = self::$transport !== null ? (self::$transport)($params) : \CalcApiGlobal::post('sms/add', $params);
        } catch (\Throwable $e) {
            self::fail($params, $e->getMessage());
            return;
        }
        // CalcApiGlobal не кидає виняток: збій curl — false, сторінка помилки — рядок, відмова api2 — ['error' => true, ...]
        if (!is_array($result) || !empty($result['error'])) {
            self::fail($params, is_string($result) ? $result : (is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : ''));
        }
    }

    private static function fail(array $params, string $reason): void
    {
        $to = isset($params['customer_id']) ? 'customer_id=' . $params['customer_id'] : 'user_id=' . ($params['user_id'] ?? '?');
        error_log('Design\\Notify: api2 sms/add не прийняв повідомлення (' . $to . '): ' . ($reason !== '' ? $reason : 'порожня відповідь або збій звʼязку'));
    }
}
