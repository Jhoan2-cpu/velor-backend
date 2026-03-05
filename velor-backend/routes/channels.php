<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (string) $user->id === (string) $id;
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('user.{userId}.focus.tasks', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
}, ['guards' => ['web', 'sanctum']]);

Broadcast::channel('user.{userId}.taskcards', function ($user, $userId) {
    return (string) $user->id === (string) $userId;
}, ['guards' => ['web', 'sanctum']]);
