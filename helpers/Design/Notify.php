<?php

namespace Design;

/** Сповіщення через api2 (sms/add): повідомлення на сайті wolf.ua і SMS */
final class Notify
{
    /** @var list<array> що «відправлено» при DESIGN_NOTIFY=off (тести); у бойовому режимі не наповнюється */
    public static array $sent = [];

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
            \CalcApiGlobal::post('sms/add', $params);
        } catch (\Throwable $e) {
            error_log('Design\\Notify: ' . $e->getMessage());
        }
    }
}
