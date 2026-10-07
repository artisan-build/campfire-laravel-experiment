<?php

namespace App\Http\Controllers;

use App\Jobs\DeliverPush;
use App\Support\PushEndpoints;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PushController extends Controller
{
    public function index(Request $r)
    {
        return view('users.push');
    }

    public function create(Request $r)
    {
        $a = $r->validate(['push_subscription.endpoint' => 'required|string', 'push_subscription.p256dh_key' => 'required|string', 'push_subscription.auth_key' => 'required|string']);
        $v = $a['push_subscription'];
        if (! app(PushEndpoints::class)->resolve($v['endpoint'])) {
            return response('', 422);
        }DB::table('push_subscriptions')->updateOrInsert($v + ['user_id' => $r->user()->id], ['user_agent' => $r->userAgent(), 'created_at' => now(), 'updated_at' => now()]);

        return response('', 200);
    }

    public function destroy(Request $r, string $user, int $id)
    {
        DB::table('push_subscriptions')->where('id', $id)->where('user_id', $r->user()->id)->delete();

        return redirect()->route('push.index', ['user' => 'me']);
    }

    public function test(Request $r, string $user, int $id)
    {
        $s = DB::table('push_subscriptions')->where('id', $id)->where('user_id', $r->user()->id)->first();
        abort_unless($s, 404);
        DeliverPush::dispatch((array) $s, DeliverPush::payload('Campfire', 'Notifications are working', '/'));

        return response('', 200);
    }
}
