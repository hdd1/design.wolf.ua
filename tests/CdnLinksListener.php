<?php

// Окремий файл (не в tests/bootstrap.php): bootstrap підключають і дочірні процеси тестів без PHPUnit (PurchasesTest)
/**
 * Підключений у phpunit.xml: кожен тест, що лишив у cloud-links (ci, cf) новий симлінк чи каталог від користувача PHPUnit
 * (root на dev), — провалений, а лишене прибирається (CdnLinksGuard::cleanup). Причина: каталоги шардів root:root 755 —
 * php-fpm (www-data) у них уже не створить посилання «Завантажити» / прев'ю, а симлінки на тимчасові папки тестів висять.
 * Записи php-fpm (HTTP-тести) сюди не потрапляють — інший власник; їх прибирають самі тести
 */
final class CdnLinksListener implements \PHPUnit\Framework\TestListener
{
    use \PHPUnit\Framework\TestListenerDefaultImplementation;

    /** @var array<string, true>|null */
    private ?array $owned = null;

    public function startTest(\PHPUnit\Framework\Test $test): void
    {
        $this->owned = array_fill_keys(CdnLinksGuard::owned(), true);
    }

    public function endTest(\PHPUnit\Framework\Test $test, float $time): void
    {
        if ($this->owned === null) return;
        $left = array_values(array_filter(CdnLinksGuard::owned(), fn(string $p): bool => !isset($this->owned[$p])));
        $this->owned = null;
        if (!$left) return;
        sort($left);
        // Прибрати: лише нові симлінки й порожні нові каталоги цього користувача (як CdnLinksGuard::cleanup)
        $before = CdnLinksGuard::snapshot();
        foreach ($left as $p) {
            unset($before['dirs'][$p], $before['links'][$p]);
        }
        CdnLinksGuard::cleanup($before);
        if ($test instanceof \PHPUnit\Framework\TestCase && $test->getTestResultObject() !== null) {
            $test->getTestResultObject()->addFailure($test, new \PHPUnit\Framework\AssertionFailedError(
                'Тест лишив у cloud-links ' . count($left) . ' записів (прибрано): ' . implode(', ', array_slice($left, 0, 5))), $time);
        }
    }
}
