<?php

use Illuminate\Database\Schema\Blueprint;

return [
    App\Events\PluginWasEnabled::class => function () {
        if (!Schema::hasTable('invitation_codes')) {
            Schema::create('invitation_codes', function ($table) {
                $table->increments('id');
                $table->string('code', 255);
                $table->dateTime('generated_at');
                $table->integer('used_by')->default(0);
                $table->dateTime('used_at')->nullable();
            });
        }
        
      	if (!Schema::hasColumn('invitation_codes', 'description')) {
            Schema::table('invitation_codes', function (Blueprint $table) {
                $table->string('description')->nullable();
            });
        }
      
        option(['invitation_codes_for_union_enabled' => true]);
        
    },
  
    App\Events\PluginWasDisabled::class => function () {
        option(['invitation_codes_for_union_enabled' => false]);
    },
];
