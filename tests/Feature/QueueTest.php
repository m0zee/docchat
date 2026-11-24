<?php

use Illuminate\Support\Facades\DB;

it('stores dispatched jobs in the database queue', function () {
    expect(config('queue.default'))->toBe('database');

    DB::table('jobs')->delete();

    dispatch(function () {
        // Intentionally empty: this test only proves the job was queued.
    });

    expect(DB::table('jobs')->count())->toBe(1);
});
