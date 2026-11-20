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
        // CLI на dev (крон, скрипти): CalcApiGlobal обирає dev api2 за HTTP_HOST — підставляємо його лише на час виклику
        $fakeHost = !isset($_SERVER['HTTP_HOST']) && Config::isDev();
        if ($fakeHost) $_SERVER['HTTP_HOST'] = 'dev.design.wolf.ua';
        try {
            $result = self::$transport !== null ? (self::$transport)($params) : \CalcApiGlobal::post('sms/add', $params);
        } catch (\Throwable $e) {
            self::fail($params, $e->getMessage());
            return;
        } finally {
            if ($fakeHost) unset($_SERVER['HTTP_HOST']);
        }
        // CalcApiGlobal не кидає виняток: збій curl — false, сторінка помилки — рядок, відмова api2 — ['error' => true, ...]
        if (!is_array($result) || !empty($result['error'])) {
            self::fail($params, is_string($result) ? $result : (is_array($result) ? json_encode($result, JSON_UNESCAPED_UNICODE) : ''));
        }
    }

    /**
     * Куратору клієнта — customers.curator_id (users.id); немає куратора — лише запис у журнал
     * (пропозиції режиму 2, «без пропозицій», скасування замовлення, скарга)
     */
    public static function curator(int $customerId, string $msg, string $msgSMS): void
    {
        $curatorId = (int)Sql::value('SELECT curator_id FROM customers WHERE id = ?', [$customerId]);
        if ($curatorId <= 0) {
            error_log('Design\\Notify: у клієнта id ' . $customerId . ' немає куратора — сповіщення не надіслано: ' . $msg);
            return;
        }
        self::send(['user_id' => $curatorId, 'msg' => $msg, 'msgSMS' => $msgSMS]);
    }

    private static function fail(array $params, string $reason): void
    {
        $to = isset($params['customer_id']) ? 'customer_id=' . $params['customer_id'] : 'user_id=' . ($params['user_id'] ?? '?');
        error_log('Design\\Notify: api2 sms/add не прийняв повідомлення (' . $to . '): ' . ($reason !== '' ? $reason : 'порожня відповідь або збій звʼязку'));
    }
}
