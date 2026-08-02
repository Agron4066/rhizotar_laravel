<?php

namespace App\Services\Handlers\Exceptions;

use RuntimeException;

/**
 * SessionNotFoundException
 *
 * SearchHandler::handleSearchResults() でキャッシュから
 * セッション状態が取り出せなかったときに投げられる例外。
 *
 * 通常は ChatController::continue() で捕捉され、
 * 404 レスポンスに変換される。
 *
 * Handlerが HTTP プロトコル(404等)を直接知らずに済むように、
 * 例外クラスで「セッションがない」という意味だけを表現する。
 */
class SessionNotFoundException extends RuntimeException
{
}
