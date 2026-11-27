<?php

declare(strict_types=1);

namespace App\Modules\Settings\Services;

use App\Common\Support\Money;
use App\Modules\Settings\Models\PlatformSettingModel;
use Config\WapiFood;

/**
 * Lecture des paramètres de la plateforme (wf_platform_settings), avec repli sur Config\WapiFood.
 */
final class PlatformSettings
{
    /** @var array<string, string|null>|null */
    private ?array $cache = null;

    public function __construct(private readonly PlatformSettingModel $model = new PlatformSettingModel())
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->cache ??= array_column($this->model->select('setting_key, setting_value')->findAll(), 'setting_value', 'setting_key');

        return $this->cache[$key] ?? $default;
    }

    /** Taux de commission par défaut, en pourcentage (« 10.00 »). */
    public function defaultCommissionRate(): string
    {
        $rate = $this->get('default_commission_rate', config(WapiFood::class)->defaultCommissionRate);

        return Money::isValid((string) $rate) && Money::toCents((string) $rate) <= 10000
            ? Money::normalize((string) $rate)
            : config(WapiFood::class)->defaultCommissionRate;
    }
}
