<?php

return array(

    'fondy_id'    => array(
        'value'        => '',
        'title'        => 'ID кошелька',
        'description'  => 'Идентификатор электронного кошелька Вашего интернет магазина в системе Fondy',
        'control_type' => waHtmlControl::INPUT,
    ),
    'secret_key' => array(
        'value'        => '',
        'title'        => 'Секретный ключ',
        'description'  => 'Ваше кодовое слово полученное от системы Fondy.',
        'control_type' => waHtmlControl::INPUT,
    ),
    'group' => array(
        'value'            => array(),
        'title'            => /*_wp*/('Список товаров'),
        'options_callback' => array('fondyPayment', 'settingsTemplates'),
        'control_type'     => waHtmlControl::SELECT,
        'options_wrapper'  => array(
            'control_separator' => '</div><div class="value">',
        ),
    ),
//    'currency'           => array(
//        'value'        => 'RUB',
//        'title'        => 'Валюта платежа',
//        'description'  => 'Валюта платежа для обработки платежной системой.',
//        'control_type' => waHtmlControl::SELECT,
//        'options'      => array(
//            'RUB' => 'Российский рубль',
//            'UAH' => 'Украинские гривны',
//            'USD' => 'Доллары США'
//        ),
//    )
);
