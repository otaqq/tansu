<?php

use Illuminate\Support\Facades\Route;
use Otaqq\Tansu\Laravel\ExplorerController;
use Otaqq\Tansu\Laravel\TansuServiceProvider;

/*
 * The HTTP endpoints of protocol/README.md, registered once per configured area. The default area is the top-level
 * config; extra areas come from tansu.areas (merged over the default). Each area keeps its own route name prefix, which
 * the controller reads back to work on the right area. The routes are built from config here, without resolving the
 * manager, so an app may still change limits, types or the owner resolver after the service provider has booted.
 */

$endpoints = function (): void {
    Route::get('info', 'info')->name('info');
    Route::get('tree', 'tree')->name('tree');
    Route::get('folder', 'folder')->name('folder');
    Route::post('folders', 'createFolder')->name('folders.create');
    Route::patch('folders', 'renameFolder')->name('folders.rename');
    Route::delete('folders', 'deleteFolder')->name('folders.delete');
    Route::post('files', 'upload')->name('files.upload');
    Route::put('files', 'updateFile')->name('files.update');
    Route::patch('files', 'renameFile')->name('files.rename');
    Route::delete('files', 'deleteFile')->name('files.delete');
    Route::get('raw', 'raw')->name('raw');
};

$base = config('tansu');
$areaRoutes = ['default' => $base['routes']];
foreach ($base['areas'] ?? [] as $name => $override) {
    $areaRoutes[$name] = TansuServiceProvider::mergeConfig($base, is_array($override) ? $override : [])['routes'];
}

foreach ($areaRoutes as $routes) {
    if (! ($routes['enabled'] ?? true)) {
        continue;
    }

    Route::prefix($routes['prefix'])
        ->middleware($routes['middleware'])
        ->name($routes['name'])
        ->controller(ExplorerController::class)
        ->group($endpoints);
}
