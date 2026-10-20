<x-filament-panels::page>
    <style>
        .tya-tabs { display: flex; gap: 0.25rem; border-bottom: 1px solid #e4e4e7; margin-block-end: 1.5rem; }
        .dark .tya-tabs { border-color: rgba(255,255,255,.08); }
        .tya-tab-btn {
            display: flex; align-items: center; gap: 0.4rem;
            padding: 0.65rem 0.25rem; margin-inline-end: 1.5rem;
            font-size: 0.85rem; font-weight: 600; color: #71717a;
            border-bottom: 2px solid transparent; cursor: pointer; background: none; border-top: none; border-inline: none;
        }
        .tya-tab-btn.tya-tab-active { color: #f97316; border-block-end-color: #f97316; }

        .tya-card { padding: 1.5rem; border: 1px solid #e4e4e7; border-radius: 0.85rem; background: #ffffff; }
        .dark .tya-card { background: #27272a; border-color: rgba(255,255,255,.08); }

        .tya-note {
            display: flex; gap: 0.6rem; padding: 0.85rem 1rem; margin-block-end: 1.5rem;
            background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 0.6rem;
            font-size: 0.8rem; color: #1d4ed8; line-height: 1.5;
        }

        .tya-label { display: block; font-size: 0.8rem; font-weight: 600; margin-block-end: 0.4rem; }
        .tya-hint { font-size: 0.75rem; color: #71717a; margin-block: 0.3rem 1rem; }

        .tya-type-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); gap: 0.75rem; margin-block-end: 1.5rem; }
        .tya-type-card {
            display: flex; align-items: flex-start; gap: 0.6rem;
            padding: 0.85rem; border: 1.5px solid #e4e4e7; border-radius: 0.6rem;
            cursor: pointer; transition: border-color .15s ease, background-color .15s ease;
        }
        .tya-type-card.tya-type-active { border-color: #f97316; background: #fff7ed; }
        .tya-type-card svg { width: 1.1rem; height: 1.1rem; flex-shrink: 0; margin-block-start: 0.1rem; color: #71717a; }
        .tya-type-card.tya-type-active svg { color: #f97316; }
        .tya-type-title { font-size: 0.82rem; font-weight: 600; }
        .tya-type-desc { font-size: 0.72rem; color: #71717a; margin-block-start: 0.15rem; }

        .tya-select-native {
            width: 100%; padding: 0.55rem 0.75rem; border: 1px solid #d4d4d8; border-radius: 0.5rem; font-size: 0.85rem;
        }

        select.tya-select-native { max-width: 28rem; }
    </style>

    <div x-data="{ tab: 'import', importType: 'outlet', exportType: 'outlet' }">

        {{-- Tab switcher --}}
        <div class="tya-tabs">
            <button type="button" class="tya-tab-btn" :class="tab === 'import' && 'tya-tab-active'" @click="tab = 'import'">
                <x-filament::icon icon="lucide-upload" style="width:1rem;height:1rem;" />
                Impor Data
            </button>
            <button type="button" class="tya-tab-btn" :class="tab === 'export' && 'tya-tab-active'" @click="tab = 'export'">
                <x-filament::icon icon="lucide-download" style="width:1rem;height:1rem;" />
                Ekspor Data
            </button>
        </div>

        {{-- ================= IMPORT TAB ================= --}}
        <div x-show="tab === 'import'" class="tya-card">

            <div class="tya-note">
                <x-filament::icon icon="lucide-info" style="width:1.1rem;height:1.1rem;flex-shrink:0;" />
                <span>Kolom di file Excel-mu harus sama persis dengan template — cara paling aman: klik "Unduh Template" dulu untuk tipe data yang mau kamu import, isi berdasarkan itu.</span>
            </div>

            <div class="tya-label">Unduh Template</div>
            <div style="display:flex; flex-wrap:wrap; gap:0.5rem; margin-block-end:1.5rem;">
                <x-filament::button wire:click="exportOutlet" icon="lucide-download" color="primary" size="sm">Outlet</x-filament::button>
                <x-filament::button wire:click="exportReseller" icon="lucide-download" color="primary" size="sm">Reseller</x-filament::button>
                <x-filament::button wire:click="exportBiller" icon="lucide-download" color="primary" size="sm">Biller</x-filament::button>
                <x-filament::button wire:click="exportVoucher" icon="lucide-download" color="primary" size="sm">Voucher</x-filament::button>
                <x-filament::button wire:click="exportPppoe" icon="lucide-download" color="primary" size="sm">User PPPoE</x-filament::button>
            </div>

            <div class="tya-label">Tipe Data</div>
            <div class="tya-type-grid">
                <div class="tya-type-card" :class="importType === 'outlet' && 'tya-type-active'" @click="importType = 'outlet'">
                    <x-filament::icon icon="lucide-store" />
                    <div>
                        <div class="tya-type-title">Outlet</div>
                        <div class="tya-type-desc">Import data mitra Outlet</div>
                    </div>
                </div>
                <div class="tya-type-card" :class="importType === 'reseller' && 'tya-type-active'" @click="importType = 'reseller'">
                    <x-filament::icon icon="lucide-users" />
                    <div>
                        <div class="tya-type-title">Reseller</div>
                        <div class="tya-type-desc">Import data mitra Reseller</div>
                    </div>
                </div>
                <div class="tya-type-card" :class="importType === 'biller' && 'tya-type-active'" @click="importType = 'biller'">
                    <x-filament::icon icon="lucide-receipt" />
                    <div>
                        <div class="tya-type-title">Biller</div>
                        <div class="tya-type-desc">Import data mitra Biller</div>
                    </div>
                </div>
                <div class="tya-type-card" :class="importType === 'voucher' && 'tya-type-active'" @click="importType = 'voucher'">
                    <x-filament::icon icon="lucide-ticket" />
                    <div>
                        <div class="tya-type-title">Voucher</div>
                        <div class="tya-type-desc">Import kode voucher Hotspot</div>
                    </div>
                </div>
                <div class="tya-type-card" :class="importType === 'pppoe' && 'tya-type-active'" @click="importType = 'pppoe'">
                    <x-filament::icon icon="lucide-wifi" />
                    <div>
                        <div class="tya-type-title">User PPPoE</div>
                        <div class="tya-type-desc">Import pelanggan PPPoE</div>
                    </div>
                </div>
            </div>

            <div class="tya-label">File</div>
            {{ $this->importForm }}

            <div style="margin-block-start:1.5rem; display:flex; justify-content:flex-end;">
                <div x-show="importType === 'outlet'"><x-filament::button wire:click="importOutlet" icon="lucide-upload" color="primary">Import Outlet</x-filament::button></div>
                <div x-show="importType === 'reseller'"><x-filament::button wire:click="importReseller" icon="lucide-upload" color="primary">Import Reseller</x-filament::button></div>
                <div x-show="importType === 'biller'"><x-filament::button wire:click="importBiller" icon="lucide-upload" color="primary">Import Biller</x-filament::button></div>
                <div x-show="importType === 'voucher'"><x-filament::button wire:click="importVoucher" icon="lucide-upload" color="primary">Import Voucher</x-filament::button></div>
                <div x-show="importType === 'pppoe'"><x-filament::button wire:click="importPppoe" icon="lucide-upload" color="primary">Import User PPPoE</x-filament::button></div>
            </div>
        </div>

        {{-- ================= EXPORT TAB ================= --}}
        <div x-show="tab === 'export'" class="tya-card">

            <label class="tya-label">Tipe Data</label>
            <select x-model="exportType" class="tya-select-native" style="margin-block-end:1.25rem;">
                <option value="outlet">Outlet</option>
                <option value="reseller">Reseller</option>
                <option value="biller">Biller</option>
                <option value="voucher">Voucher</option>
                <option value="pppoe">User PPPoE</option>
            </select>

            <div x-show="exportType === 'voucher'">
                <label class="tya-label">Filter (opsional)</label>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:0.75rem; max-width:32rem; margin-block-end:0.5rem;">
                    {{ $this->exportForm }}
                </div>
                <p class="tya-hint">Kosongkan buat export SEMUA voucher, atau pilih buat export voucher Outlet/Profile tertentu saja.</p>
            </div>

            <div style="margin-block-start:1.5rem;">
                <div x-show="exportType === 'outlet'"><x-filament::button wire:click="exportOutlet" icon="lucide-download" color="primary">Export Outlet</x-filament::button></div>
                <div x-show="exportType === 'reseller'"><x-filament::button wire:click="exportReseller" icon="lucide-download" color="primary">Export Reseller</x-filament::button></div>
                <div x-show="exportType === 'biller'"><x-filament::button wire:click="exportBiller" icon="lucide-download" color="primary">Export Biller</x-filament::button></div>
                <div x-show="exportType === 'voucher'"><x-filament::button wire:click="exportVoucher" icon="lucide-download" color="primary">Export Voucher</x-filament::button></div>
                <div x-show="exportType === 'pppoe'"><x-filament::button wire:click="exportPppoe" icon="lucide-download" color="primary">Export User PPPoE</x-filament::button></div>
            </div>
        </div>

    </div>
</x-filament-panels::page>
