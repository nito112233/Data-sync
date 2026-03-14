<?php

use App\Http\Controllers\CrmOrderImportController;
use Illuminate\Support\Facades\Route;

Route::post('/crm/orders', [CrmOrderImportController::class, 'store']);