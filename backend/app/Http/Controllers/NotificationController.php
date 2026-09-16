<?php

namespace App\Http\Controllers;

use App\Services\NotificationFeed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The bell's dropdown, fetched when it is opened rather than shipped with
 * every page — building the list costs several queries nobody needs until
 * they look.
 */
class NotificationController extends Controller
{
    public function __invoke(Request $request, NotificationFeed $feed): JsonResponse
    {
        return response()
            ->json($feed->for($request->user()))
            ->header('Cache-Control', 'no-store');
    }
}
