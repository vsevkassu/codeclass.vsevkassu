<?php
/**
 * Модуль печати кассовых чеков для Битрикс Управление Сайтом
 * сервис Все в кассу
 * https://vsevkassu.ru
 */


namespace Codeclass\Vsevkassu;

class CVsevkassu {

    public function registerMainClass()
    {
        return new \Bitrix\Main\EventResult(
            \Bitrix\Main\EventResult::SUCCESS,
            array(
                "Codeclass\\Vsevkassu\\Vsevkassu" => "/bitrix/modules/codeclass.vsevkassu/lib/Vsevkassu.php",
            )
        );
    }


}