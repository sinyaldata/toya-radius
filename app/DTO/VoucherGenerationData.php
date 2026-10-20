<?php

namespace App\DTO;

use App\Enums\VoucherCodeCombination;
use App\Enums\VoucherLoginMethod;

readonly class VoucherGenerationData
{
    public function __construct(
        public int $servicePackageId,
        public int $quantity,
        public string $prefix = '',
        public VoucherLoginMethod $loginMethod = VoucherLoginMethod::UserPass,
        public VoucherCodeCombination $codeCombination = VoucherCodeCombination::AlnumUpper,
        public int $usernameLength = 8,
        public int $passwordLength = 6,
        public bool $autoBindMac = false,
        public ?int $outletId = null,
        public ?int $generatedBy = null,
        // Asal pembuatan: 'app' (panel aplikasi) atau 'telegram' (bot).
        // Default 'app' -- pemanggil dari bot Telegram wajib mengisinya
        // sendiri dengan 'telegram' (lihat TelegramWebhookController).
        public string $generatedVia = 'app',
    ) {}

    public static function fromArray(array $data): self
    {
        return new self(
            servicePackageId: (int) $data['service_package_id'],
            quantity: (int) $data['quantity'],
            prefix: (string) ($data['prefix'] ?? ''),
            loginMethod: VoucherLoginMethod::from($data['login_method'] ?? 'user_pass'),
            codeCombination: VoucherCodeCombination::from($data['code_combination'] ?? 'alnum_upper'),
            usernameLength: (int) ($data['username_length'] ?? 8),
            passwordLength: (int) ($data['password_length'] ?? 6),
            autoBindMac: (bool) ($data['auto_bind_mac'] ?? false),
            outletId: isset($data['outlet_id']) ? (int) $data['outlet_id'] : null,
            generatedBy: isset($data['generated_by']) ? (int) $data['generated_by'] : null,
            generatedVia: (string) ($data['generated_via'] ?? 'app'),
        );
    }
}
