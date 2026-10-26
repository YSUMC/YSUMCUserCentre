<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Http\Controllers\ReportController;
use App\Models\Report;
use App\Models\User;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Access\BackendPolicy;

final class DelegatedReportsController extends ReportController
{
    public function review(Report $report, Request $request, Dispatcher $dispatcher)
    {
        $policy = app(BackendPolicy::class);
        $actor = $request->user();
        abort_unless($policy->allows($actor, 'reports', 2), 403);
        $target = User::find($report->uploader);
        if ($request->input('action') !== 'reject' && $target) {
            abort_unless($policy->canManageTarget($actor, $target), 403, '不能处理权限高于自己的账号。');
        }
        if ($request->input('action') !== 'ban') return parent::review($report, $request, $dispatcher);
        abort_unless($policy->allows($actor, 'users', 2) && $target && $target->uid !== $actor->uid, 403, '封禁账号还需要用户管理权限。');
        return DB::transaction(function () use ($report, $actor, $target, $dispatcher, $policy) {
            $users = User::whereIn('uid', [$actor->uid, $target->uid])->orderBy('uid')->lockForUpdate()->get()->keyBy('uid');
            $actor = $users->get($actor->uid); $target = $users->get($target->uid);
            $policy->forgetIdentities();
            abort_unless($actor && $target && $policy->allows($actor, 'users', 2) && $policy->canManageTarget($actor, $target), 403);
            $dispatcher->dispatch('report.reviewing', [$report, 'ban']);
            $target->permission = User::BANNED;
            $target->save();
            $dispatcher->dispatch('user.banned', [$target]);
            self::returnScore($report);
            self::giveAward($report);
            $report->status = Report::RESOLVED;
            $report->save();
            $dispatcher->dispatch('report.resolved', [$report, 'ban']);
            return json(trans('general.op-success'), 0, ['status' => Report::RESOLVED]);
        });
    }
}
