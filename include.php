<?php
/**
 * Модуль печати кассовых чеков для Битрикс Управление Сайтом
 * сервис Все в кассу
 * https://vsevkassu.ru
 */


require __DIR__ . '/vendor/php-sdk/autoloader.php';

CModule::AddAutoloadClasses(
    'codeclass.vsevkassu',
    array(
        'Codeclass\\Vsevkassu\\CVsevkassu'=> 'lib/CVsevkassu.php',
        'Codeclass\\Vsevkassu\\Vsevkassu' => 'lib/Vsevkassu.php'
    )
);

Codeclass\Vsevkassu\Vsevkassu::Log(['message' => 'Init classes ok']);

