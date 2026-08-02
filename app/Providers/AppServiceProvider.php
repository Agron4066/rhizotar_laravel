<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // TagDispatcher は内部バッファという状態を持つため、
        // bind() でリクエストごとに新しいインスタンスを生成する。
        // singleton() にすると、同時並行リクエストで状態が混ざる事故が起きる。
        $this->app->bind(\App\Services\TagDispatcher::class, function ($app) {
            return new \App\Services\TagDispatcher(
                handlers: [
                    // ここに「タグ名 => Handlerインスタンス」のマッピングを追加していく。
                    // 例:
                    //   'SEARCH' => $app->make(\App\Services\Handlers\SearchHandler::class),
                    //
                    // 現時点では SearchHandler は保守的移行で ChatController から直接呼ばれるため未登録。
                    // AppointmentHandler を登録（Phase 14 準備）
                    'APPOINTMENT' => $app->make(\App\Services\Handlers\AppointmentHandler::class),
                ],
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
