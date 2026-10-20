<?php

namespace App\Filament\Concerns;

use Filament\Forms\Components\Placeholder;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Illuminate\Support\HtmlString;

/**
 * Kumpulan helper tampilan buat wizard "Create/Edit Profile" (Static IP,
 * Hotspot, PPPoE) supaya SATU pola desain dipakai konsisten di
 * ketiganya, bukan diulang manual di tiap Resource:
 *
 *   1. wizardStepSection()  -> header step ber-ikon (kotak oranye +
 *      judul + deskripsi abu-abu), isi fieldnya di bawahnya. Ini yang
 *      tampil di dalam body tiap step wizard (BUKAN stepper-nya sendiri
 *      di atas, itu sudah otomatis dari Step::make()->icon()).
 *   2. toggleCard()         -> bungkus 1 Toggle jadi "kartu" bordered,
 *      otomatis berubah oranye begitu switch-nya AKTIF (CSS :has()).
 *   3. infoBox()            -> catatan penting sebagai kotak abu2 +
 *      ikon info, dipakai buat helper text yang perlu lebih menonjol
 *      dari helper text biasa (bukan pengganti ->helperText() biasa).
 *
 * Class CSS terkait ("toya-wizard-step-header", "toya-toggle-card",
 * "toya-info-box") didefinisikan di resources/css/filament/admin/theme.css.
 */
trait HasStyledWizardSteps
{
    protected static function wizardStepSection(string $title, string $icon, string $description, array $schema, int $columns = 2): Section
    {
        return Section::make($title)
            ->description($description)
            ->icon($icon)
            ->contained(false)
            ->extraAttributes(['class' => 'toya-wizard-step-header'])
            ->columns($columns)
            ->schema($schema);
    }

    protected static function toggleCard(Component $toggle): Group
    {
        return Group::make([$toggle])
            ->extraAttributes(['class' => 'toya-toggle-card']);
    }

    protected static function infoBox(string $text): HtmlString
    {
        return new HtmlString(
            '<div class="toya-info-box">'
            .'<svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10A8 8 0 1 1 2 10a8 8 0 0 1 16 0ZM9 9a1 1 0 0 1 2 0v4a1 1 0 1 1-2 0V9Zm1-4a1.25 1.25 0 1 0 0 2.5A1.25 1.25 0 0 0 10 5Z" clip-rule="evenodd" /></svg>'
            .'<span>'.e($text).'</span>'
            .'</div>'
        );
    }

    /**
     * Versi siap-pakai buat langsung ditaruh di dalam ->schema([...]),
     * biar Resource tidak perlu import Placeholder + HtmlString manual
     * tiap kali butuh satu kotak info.
     */
    protected static function infoBoxField(string $key, string $text): Placeholder
    {
        return Placeholder::make($key)
            ->hiddenLabel()
            ->content(self::infoBox($text))
            ->columnSpanFull();
    }
}
