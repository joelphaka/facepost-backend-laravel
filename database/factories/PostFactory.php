<?php

/** @var \Illuminate\Database\Eloquent\Factory $factory */

use App\Models\Comment;
use App\Models\Post;
use App\Models\User;
use Faker\Generator as Faker;

/*
|--------------------------------------------------------------------------
| Model Factories
|--------------------------------------------------------------------------
|
| This directory should contain each of the model factory definitions for
| your application. Factories provide a convenient way to generate new
| model instances for testing / seeding your application's database.
|
*/

$factory->define(Post::class, function (Faker $faker) {
    $userId = User::all(['id'])->pluck('id')->random();
    $title = $faker->text(60);
    $content= $faker->realText(360);

    return [
        'user_id' => $userId,
        'title' => $title,
        'content' => $content,
    ];
});
