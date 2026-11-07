<?php

use Illuminate\Support\Facades\Route;
use Otaqq\Tansu\Laravel\ExplorerController;

$routes = config('tansu.routes');

Route::prefix($routes['prefix'])
    ->middleware($routes['middleware'])
    ->name($routes['name'])
    ->controller(ExplorerController::class)
    ->group(function () {
        Route::get('info', 'info')->name('info');
        Route::get('tree', 'tree')->name('tree');
        Route::get('folder', 'folder')->name('folder');
        Route::post('folders', 'createFolder')->name('folders.create');
        Route::patch('folders', 'renameFolder')->name('folders.rename');
        Route::delete('folders', 'deleteFolder')->name('folders.delete');
        Route::post('files', 'upload')->name('files.upload');
        Route::patch('files', 'renameFile')->name('files.rename');
        Route::delete('files', 'deleteFile')->name('files.delete');
        Route::get('raw', 'raw')->name('raw');
    });
