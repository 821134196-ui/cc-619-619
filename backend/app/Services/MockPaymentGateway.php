<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * 模拟扫码 / 收款网关。
 * 真实环境替换为支付 SDK；此处本地返回成功，带唯一交易号。
 */
class MockPaymentGateway
{
    public static function charge(int $amountCents, string $bizRef): string
    {
        return 'MOCKPAY-' . date('Ymd') . '-' . strtoupper(Str::random(10));
    }
}
