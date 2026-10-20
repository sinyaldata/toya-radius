<?php

namespace App\Filament\Resources\RouterResource\Pages;

use App\Enums\RouterConnectionStatus;
use App\Filament\Resources\RouterResource;
use App\Services\MikrotikService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRouter extends EditRecord
{
    protected static string $resource = RouterResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('test_connection')
                ->label('Test Connection')
                ->icon('lucide-radio')
                ->color('info')
                ->action(function (MikrotikService $service) {
                    $result = $service->testConnection($this->record);

                    if ($result['success']) {
                        $this->record->update([
                            'connection_status' => RouterConnectionStatus::Connected,
                            'routeros_version' => $result['version'] ?? null,
                            'identity' => $result['identity'] ?? null,
                            'last_checked_at' => now(),
                        ]);

                        Notification::make()
                            ->title('Koneksi berhasil')
                            ->body("Terhubung ke {$result['identity']} (RouterOS {$result['version']})")
                            ->success()
                            ->send();
                    } else {
                        $this->record->update(['connection_status' => RouterConnectionStatus::Disconnected, 'last_checked_at' => now()]);

                        Notification::make()
                            ->title('Koneksi gagal')
                            ->body($result['error'])
                            ->danger()
                            ->send();
                    }
                }),
            Actions\DeleteAction::make()->iconButton(),
        ];
    }
}
