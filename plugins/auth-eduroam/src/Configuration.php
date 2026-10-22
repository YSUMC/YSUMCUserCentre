<?php

namespace AuthEduroam;

use App\Models\User;
use Illuminate\Support\Str;

class Configuration
{
    public function render()
    {
        $info = null;
        $hosts = [
            'host' => config('auth-eduroam.host'),
            'store_host' => config('auth-eduroam.store_host'),
            'username' => trans('AuthEduroam::auth.eduroam.username'),
        ];
        if (config('auth-eduroam.host')) {
            if (config('auth-eduroam.store_host')) {
                $info = trans('AuthEduroam::config.host.set_with_store_host', $hosts);
            } else {
                $info = trans('AuthEduroam::config.host.set', $hosts);
            }
        } else {
            $info = trans('AuthEduroam::config.host.unset');
            if(config('auth-eduroam.store_host')){
                $info = $info . trans('AuthEduroam::config.only_store_host');
            }
        }
        return view('AuthEduroam::config', [
            'info' => $info,
            'host' => config('auth-eduroam.host') ?: trans('AuthEduroam::config.unset'),
            'store_host' => config('auth-eduroam.store_host') ?: trans('AuthEduroam::config.unset'),
        ]);
    }
}
