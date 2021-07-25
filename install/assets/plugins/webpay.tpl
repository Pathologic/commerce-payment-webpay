//<?php
/**
 * Payment Webpay
 *
 * Webpay payments processing
 *
 * @category    plugin
 * @version     1.0.0
 * @author      Pathologic
 * @internal    @events OnRegisterPayments,OnBeforeOrderSending,OnManagerBeforeOrderRender
 * @internal    @properties &title=Название;text; &store_id=Идентификатор магазина;text; &secret=Секретный ключ;text; &debug=Отладка;list;Нет==0||Да==1;0
 * @internal    @modx_category Commerce
 */

return require MODX_BASE_PATH . 'assets/plugins/webpay/plugin.webpay.php';
