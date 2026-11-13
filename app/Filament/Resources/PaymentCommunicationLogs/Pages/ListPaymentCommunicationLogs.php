<?php

namespace App\Filament\Resources\PaymentCommunicationLogs\Pages;

use App\Filament\Resources\PaymentCommunicationLogs\PaymentCommunicationLogResource;
use Filament\Resources\Pages\ListRecords;

class ListPaymentCommunicationLogs extends ListRecords
{
    protected static string $resource = PaymentCommunicationLogResource::class;
}
