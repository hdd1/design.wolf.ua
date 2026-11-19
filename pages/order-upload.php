<?php
/**
 * Прийом файлу частинами: POST /orders/{id}/upload (assets/js/chunk-upload.js).
 * Поля кожної частини: meta[fileId|chunkIndex|totalChunks|totalSize|originalName], file, csrf, purpose = preview | layout | replace;
 * для preview — caption і based_on (беруться на останній частині), для replace — file_id (замінюваний макет).
 * Доступ (обраний дизайнер замовлення в роботі, Previews::canWork) і CSRF — на кожну частину, до ChunkUpload::receive.
 * Ліміти — на першій частині за заявленим розміром (макети — Layouts::precheck; прев'ю — ліміт receive).
 * Відповідь: проміжна частина — {done:false}; остання — {done:true, preview, message} / {done:true, layout};
 * помилка — 4xx/5xx {error}. Остаточна відповідь (і помилка обробки) запамʼятовується: повтор останньої частини отримує її ж
 * @var array $params
 */
use Design\Chat;
use Design\ChunkUpload;
use Design\DesignerException;
use Design\Guard;
use Design\Http;
use Design\Layouts;
use Design\Offers;
use Design\Previews;
use Design\Settings;

$busyText = ChunkUpload::ERR_BUSY;

$me = Guard::customer();
$orderId = (int)$params['id'];

/** Ціле > 0 з поля форми або null (порожньо); інше — DesignerException */
$optionalId = static function (string $field, string $error): ?int {
    $v = trim(Http::str($_POST, $field));
    if ($v === '') return null;
    if (!preg_match('/^[1-9]\d{0,9}$/D', $v)) throw new DesignerException($error);
    return (int)$v;
};

/** Блокування рядків MySQL не дочекалось (1205) або взаємне блокування (40001): спробувати ще раз */
$lockTimeout = static fn(\Throwable $e): bool => $e instanceof \PDOException
    && ((string)$e->getCode() === '40001' || in_array((int)($e->errorInfo[1] ?? 0), [1205, 1213], true));

$key = null;
try {
    if (Previews::canWork($orderId, $me) === null) {
        Http::json(['error' => "Завантажувати прев'ю й макети може лише обраний дизайнер замовлення в роботі"], 403);
    }
    if (!Guard::csrfOk($me)) Http::json(['error' => Guard::STALE_AJAX], 403);
    $purpose = Http::str($_POST, 'purpose');
    if (!in_array($purpose, ['preview', 'layout', 'replace'], true)) throw new DesignerException('Невірні дані завантаження. Оновіть сторінку і спробуйте ще раз');
    $replaceId = $purpose === 'replace' ? $optionalId('file_id', 'Макет не знайдено: його вже замінено або видалено') : null;
    if ($purpose === 'replace' && $replaceId === null) throw new DesignerException('Макет не знайдено: його вже замінено або видалено');

    // Ліміти макетів — на першій частині (нічого не кладемо в tmp_uppy, якщо файл однаково не приймемо)
    $meta = is_array($_POST['meta'] ?? null) ? $_POST['meta'] : [];
    if ($purpose !== 'preview' && ($meta['chunkIndex'] ?? null) === '0' && is_string($meta['totalSize'] ?? null)
        && preg_match('/^\d{1,15}$/D', $meta['totalSize'])) {
        Layouts::precheck($orderId, $me, (int)$meta['totalSize'], $replaceId);
    }

    $max = $purpose === 'preview' ? Settings::previewMaxBytes() : Settings::layoutMaxBytes();
    $part = ChunkUpload::receive($_POST, $_FILES, "$me:$orderId:$purpose", $max);
    if (!$part['done']) Http::json(['done' => false]);
    if (isset($part['recalled'])) Http::json($part['recalled'], isset($part['recalled']['error']) ? 422 : 200);
    $key = $part['key'];
} catch (DesignerException $e) {
    $busy = $e->getCode() === 503;
    Http::json(['error' => $busy ? $busyText : $e->getMessage()], $busy ? 503 : 422);
} catch (\Throwable $e) {
    error_log("design: частина завантаження замовлення $orderId від $me: " . $e);
    Http::json($lockTimeout($e) ? ['error' => $busyText] : ['error' => 'Не вдалося завантажити файл — спробуйте ще раз'],
        $lockTimeout($e) ? 503 : 500);
}

// Остання частина: файл склеєно ($part['path'] у tmp_uppy) — обробка споживає його в будь-якому разі
$code = 200;
try {
    if ($purpose === 'preview') {
        $basedOn = $optionalId('based_on', "Версію, на основі якої зроблено прев'ю, не знайдено");
        $added = Previews::add($orderId, $me, $part['path'], Http::str($_POST, 'caption'), $basedOn);
        $access = Offers::access((int)(Offers::forDesigner($orderId, $me)['id'] ?? 0), $me)
            ?? throw new \RuntimeException('Немає листування замовлення ' . $orderId);
        $response = [
            'done' => true,
            'preview' => Previews::byIds($orderId, [$added['id']])[$added['id']] ?? null,
            'message' => Chat::messages($access, $added['message_id'] - 1, 1)[0] ?? null,
        ];
    } else {
        if ($replaceId !== null && !in_array($replaceId, array_column(Layouts::forOrder($orderId), 'id'), true)) {
            throw new DesignerException('Макет не знайдено: його вже замінено або видалено');
        }
        $saved = $replaceId === null
            ? Layouts::add($orderId, $me, $part['path'], $part['name'])
            : Layouts::replace($replaceId, $me, $part['path'], $part['name']);
        $layout = null;
        foreach (Layouts::forOrder($orderId) as $l) if ($l['id'] === $saved['id']) $layout = $l;
        $response = ['done' => true, 'layout' => $layout];
    }
} catch (DesignerException $e) {
    $code = 422;
    $response = ['error' => $e->getMessage()];
} catch (\Throwable $e) {
    error_log("design: обробка завантаження ($purpose) замовлення $orderId від $me: " . $e);
    $code = $lockTimeout($e) ? 503 : 500;
    $response = ['error' => $lockTimeout($e) ? $busyText : 'Не вдалося обробити файл — завантажте його ще раз'];
} finally {
    if (is_file($part['path'])) @unlink($part['path']);
}

// Повтор останньої частини отримає цю ж відповідь; збій запису — лише в журнал (результат уже є)
try {
    ChunkUpload::remember($key, $response);
} catch (\Throwable $e) {
    error_log("design: не запамʼятати відповідь завантаження $key: " . $e);
}
Http::json($response, $code);
