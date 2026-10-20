<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpPool extends Model
{
    protected $fillable = [
        'name',
        'service_type',
        'router_id',
        'start_ip',
        'end_ip',
        'gateway',
        'prefix_length',
        'max_download',
        'max_upload',
        'parent_queue_name',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'prefix_length' => 'integer',
        ];
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(Router::class);
    }

    public function servicePackages(): HasMany
    {
        return $this->hasMany(ServicePackage::class);
    }

    /**
     * IP berikutnya yang masih kosong di rentang pool ini. Dicek terhadap
     * kolom Customer.static_ip yang SUDAH TERPAKAI (soft-delete otomatis
     * membebaskan IP lagi, karena query default Eloquent tidak ikut baris
     * yang sudah dihapus).
     *
     * Return null kalau pool sudah penuh (tidak ada IP tersisa).
     */
    public function nextAvailableIp(): ?string
    {
        $startLong = ip2long($this->start_ip);
        $endLong = ip2long($this->end_ip);

        if ($startLong === false || $endLong === false || $startLong > $endLong) {
            return null;
        }

        $used = Customer::query()
            ->whereNotNull('static_ip')
            ->whereBetween('static_ip', [$this->start_ip, $this->end_ip])
            ->pluck('static_ip')
            ->flip(); // key = ip, biar isset() O(1) bukan in_array() O(n)

        for ($ipLong = $startLong; $ipLong <= $endLong; $ipLong++) {
            $ip = long2ip($ipLong);

            if (! isset($used[$ip])) {
                return $ip;
            }
        }

        return null; // pool penuh
    }

    public function getTotalAddressesAttribute(): int
    {
        $startLong = ip2long($this->start_ip);
        $endLong = ip2long($this->end_ip);

        if ($startLong === false || $endLong === false) {
            return 0;
        }

        return max(0, $endLong - $startLong + 1);
    }

    public function getUsedAddressesAttribute(): int
    {
        return Customer::query()
            ->whereNotNull('static_ip')
            ->whereBetween('static_ip', [$this->start_ip, $this->end_ip])
            ->count();
    }
}
